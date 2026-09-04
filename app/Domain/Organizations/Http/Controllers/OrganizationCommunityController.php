<?php

namespace App\Domain\Organizations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Production;
use App\Models\User;
use App\Services\AppNotificationService;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use Throwable;
use Tymon\JWTAuth\Facades\JWTAuth;

final class OrganizationCommunityController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AppNotificationService $notifications,
    ) {}

    public function publicCommunity(Request $request, string $slug)
    {
        $organization = $this->publicOrganizationBySlug($slug);
        $appId = $this->context->id();
        $user = $this->optionalUser($request);
        $perPage = min(max((int) $request->input('per_page', 10), 1), 30);

        $posts = DB::table('organization_posts as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.app_id', $appId)->where('p.organization_id', $organization->id)
            ->whereNull('p.parent_id')->where('p.status', 'published')
            ->select(['p.id','p.organization_id','p.user_id','p.body','p.is_pinned','p.created_at','p.edited_at','u.first_name','u.last_name','u.avatar'])
            ->selectSub(fn ($q) => $q->from('organization_post_likes as l')->selectRaw('COUNT(*)')->whereColumn('l.post_id', 'p.id')->where('l.app_id', $appId), 'likes_count')
            ->selectSub(fn ($q) => $q->from('organization_posts as r')->selectRaw('COUNT(*)')->whereColumn('r.parent_id', 'p.id')->where('r.status', 'published'), 'comments_count')
            ->orderByDesc('p.is_pinned')->orderByDesc('p.created_at')->paginate($perPage);

        $ids = collect($posts->items())->pluck('id')->filter()->values();
        $replies = $ids->isEmpty() ? collect() : DB::table('organization_posts as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.app_id', $appId)->where('p.organization_id', $organization->id)
            ->whereIn('p.parent_id', $ids)->where('p.status', 'published')
            ->select(['p.id','p.parent_id','p.user_id','p.body','p.created_at','p.edited_at','u.first_name','u.last_name','u.avatar'])
            ->selectSub(fn ($q) => $q->from('organization_post_likes as l')->selectRaw('COUNT(*)')->whereColumn('l.post_id', 'p.id')->where('l.app_id', $appId), 'likes_count')
            ->orderBy('p.created_at')->get()->groupBy('parent_id');

        $liked = collect();
        if ($user) {
            $allIds = $ids->merge($replies->flatten(1)->pluck('id'))->filter()->values();
            if ($allIds->isNotEmpty()) {
                $liked = DB::table('organization_post_likes')->where('app_id', $appId)->where('user_id', $user->id)->whereIn('post_id', $allIds)->pluck('post_id');
            }
        }

        $posts->setCollection(collect($posts->items())->map(function ($post) use ($replies, $liked, $user) {
            $post->is_liked = $user ? $liked->contains($post->id) : false;
            $post->replies = collect($replies->get($post->id, []))->map(function ($reply) use ($liked, $user) {
                $reply->is_liked = $user ? $liked->contains($reply->id) : false;
                return $reply;
            })->values();
            return $post;
        }));

        return response()->json(['posts' => $posts]);
    }

    public function createPost(Request $request, int $organizationId)
    {
        $user = $request->user();
        $organization = $this->publicOrganizationById($organizationId);
        $appId = $this->context->id();
        $data = $request->validate(['body' => 'required|string|min:2|max:3000', 'parent_id' => 'nullable|integer|min:1']);
        $parent = null;

        if ($parentId = $data['parent_id'] ?? null) {
            $parent = DB::table('organization_posts')->where('id', $parentId)->where('app_id', $appId)
                ->where('organization_id', $organization->id)->whereNull('parent_id')->where('status', 'published')->first();
            abort_unless($parent, 422, 'A publicação que você tentou responder não está mais disponível.');
        }

        $id = DB::table('organization_posts')->insertGetId([
            'app_id' => $appId, 'organization_id' => $organization->id, 'user_id' => $user->id,
            'parent_id' => $data['parent_id'] ?? null, 'body' => trim($data['body']),
            'status' => 'published', 'is_pinned' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->notifyActivity($organization, $user, $id, $parent);
        return response()->json(['message' => $parent ? 'Resposta publicada.' : 'Publicação adicionada à conversa.', 'post_id' => $id], 201);
    }

    public function deletePost(Request $request, int $postId)
    {
        $user = $request->user();
        $appId = $this->context->id();
        $post = DB::table('organization_posts')->where('app_id', $appId)->where('id', $postId)->first();
        abort_unless($post, 404, 'Publicação não encontrada.');
        $organization = Production::query()->where('app_id', $appId)->find($post->organization_id);
        $can = (int) $post->user_id === (int) $user->id || $user->hasProfile('Administrador')
            || ($organization && (int) $organization->user_id === (int) $user->id);
        abort_unless($can, 403, 'Você não tem permissão para remover esta publicação.');
        DB::table('organization_posts')->where('app_id', $appId)
            ->where(fn ($q) => $q->where('id', $postId)->orWhere('parent_id', $postId))
            ->update(['status' => 'hidden', 'updated_at' => now()]);
        return response()->json(['message' => 'Publicação removida.']);
    }

    public function like(Request $request, int $postId)
    {
        $user = $request->user();
        $post = $this->publishedPost($postId);
        $appId = $this->context->id();
        $already = DB::table('organization_post_likes')->where(['app_id' => $appId, 'post_id' => $post->id, 'user_id' => $user->id])->exists();
        DB::table('organization_post_likes')->updateOrInsert(
            ['app_id' => $appId, 'post_id' => $post->id, 'user_id' => $user->id],
            ['created_at' => now(), 'updated_at' => now()]
        );

        if (! $already && (int) $post->user_id !== (int) $user->id) {
            $organization = Production::query()->where('app_id', $appId)->find($post->organization_id);
            if ($organization) {
                $this->safeNotify($appId, (int) $post->user_id, [
                    'type' => 'comment_like', 'title' => 'Curtiram sua publicação',
                    'message' => (trim((string) $user->first_name) ?: 'Alguém').' curtiu o que você publicou em '.$organization->name.'.',
                    'reference_type' => 'production', 'reference_id' => $organization->id,
                    'reference_url' => '/production/'.$organization->slug.'/public#comunidade',
                    'data' => ['organization_id' => $organization->id, 'post_id' => $post->id, 'actor_id' => $user->id],
                ]);
            }
        }

        return response()->json(['message' => 'Publicação curtida.', 'liked' => true]);
    }

    public function unlike(Request $request, int $postId)
    {
        DB::table('organization_post_likes')->where([
            'app_id' => $this->context->id(), 'post_id' => $postId, 'user_id' => $request->user()->id,
        ])->delete();
        return response()->json(['message' => 'Curtida removida.', 'liked' => false]);
    }

    public function storeMedia(Request $request, int $organizationId)
    {
        $organization = $this->managedOrganization($request, $organizationId);
        $data = $request->validate(['photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:10240', 'caption' => 'nullable|string|max:180']);
        $appId = $this->context->id();
        $count = DB::table('organization_media')->where('app_id', $appId)->where('organization_id', $organization->id)->count();
        abort_if($count >= 16, 422, 'A galeria pode ter até 16 fotos.');

        $path = 'images/apps/'.$this->context->slug().'/organizations/'.$organization->id.'/gallery/'.Str::uuid().'.webp';
        $absolute = Storage::disk('public')->path($path);
        if (! is_dir(dirname($absolute))) mkdir(dirname($absolute), 0755, true);
        Image::make($data['photo']->getRealPath())->orientate()->resize(1800, 1200, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        })->encode('webp', 86)->save($absolute);

        $id = DB::table('organization_media')->insertGetId([
            'app_id' => $appId, 'organization_id' => $organization->id, 'user_id' => $request->user()->id,
            'path' => $path, 'caption' => trim((string) ($data['caption'] ?? '')) ?: null,
            'position' => $count, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Foto adicionada.', 'media' => $this->mediaPayload(DB::table('organization_media')->where('id', $id)->first())], 201);
    }

    public function deleteMedia(Request $request, int $organizationId, int $mediaId)
    {
        $organization = $this->managedOrganization($request, $organizationId);
        $row = DB::table('organization_media')->where('app_id', $this->context->id())
            ->where('organization_id', $organization->id)->where('id', $mediaId)->first();
        abort_unless($row, 404, 'Foto não encontrada.');
        Storage::disk('public')->delete($row->path);
        DB::table('organization_media')->where('id', $row->id)->delete();
        return response()->json(['message' => 'Foto removida.']);
    }

    private function notifyActivity(Production $organization, User $actor, int $postId, ?object $parent): void
    {
        $appId = $this->context->id();
        $ownerId = (int) $organization->user_id;
        $parentAuthor = $parent ? (int) $parent->user_id : null;
        $name = trim((string) $actor->first_name) ?: 'Alguém';
        $payload = [
            'type' => $parent ? 'production_reply' : 'production_comment',
            'title' => $parent ? 'Nova resposta na produção' : 'Novo comentário na produção',
            'message' => $name.($parent ? ' respondeu uma conversa em ' : ' publicou na conversa de ').$organization->name.'.',
            'reference_type' => 'production', 'reference_id' => $organization->id,
            'reference_url' => '/production/'.$organization->slug.'/public#comunidade',
            'data' => ['organization_id' => $organization->id, 'post_id' => $postId, 'actor_id' => $actor->id],
        ];
        if ($ownerId && $ownerId !== (int) $actor->id) $this->safeNotify($appId, $ownerId, $payload);
        if ($parentAuthor && $parentAuthor !== (int) $actor->id && $parentAuthor !== $ownerId) $this->safeNotify($appId, $parentAuthor, $payload);
    }

    private function safeNotify(int $appId, int $userId, array $payload): void
    {
        try { $this->notifications->sendToUser($appId, $userId, $payload); } catch (Throwable $e) { report($e); }
    }

    private function mediaPayload(object $row): array
    {
        return ['id' => (int) $row->id, 'url' => Storage::disk('public')->url($row->path), 'caption' => $row->caption, 'position' => (int) $row->position];
    }

    private function publishedPost(int $id): object
    {
        $post = DB::table('organization_posts')->where('app_id', $this->context->id())->where('id', $id)->where('status', 'published')->first();
        abort_unless($post, 404, 'Publicação não encontrada.');
        return $post;
    }

    private function publicOrganizationBySlug(string $slug): Production
    {
        return Production::query()->where('app_id', $this->context->id())->where('slug', $slug)
            ->where('is_published', true)->where('is_cancelled', false)->firstOrFail();
    }

    private function publicOrganizationById(int $id): Production
    {
        return Production::query()->where('app_id', $this->context->id())->whereKey($id)
            ->where('is_published', true)->where('is_cancelled', false)->firstOrFail();
    }

    private function managedOrganization(Request $request, int $id): Production
    {
        $organization = Production::query()->where('app_id', $this->context->id())->findOrFail($id);
        $user = $request->user();
        $admin = $user && method_exists($user, 'hasProfile') && $user->hasProfile('Administrador');
        abort_unless($user && ($admin || (int) $organization->user_id === (int) $user->id), 403, 'Você não pode gerenciar esta organização.');
        return $organization;
    }

    private function optionalUser(Request $request): ?User
    {
        $token = $request->bearerToken();
        if (! $token) return null;
        try { $user = JWTAuth::setToken($token)->authenticate(); return $user instanceof User ? $user : null; } catch (Throwable) { return null; }
    }
}
