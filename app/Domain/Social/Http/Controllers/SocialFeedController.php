<?php

namespace App\Domain\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AppNotificationService;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;
use Tymon\JWTAuth\Facades\JWTAuth;

final class SocialFeedController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AppNotificationService $notifications,
    ) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:5|max:40',
        ]);

        $appId = $this->context->id();
        $user = $this->optionalUser($request);
        $perPage = (int) ($data['per_page'] ?? 15);

        $posts = DB::table('social_posts as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.app_id', $appId)
            ->where('p.scope_type', 'global')
            ->whereNull('p.scope_id')
            ->whereNull('p.parent_id')
            ->where('p.status', 'published')
            ->select([
                'p.id', 'p.user_id', 'p.body', 'p.media_path', 'p.media_type',
                'p.created_at', 'p.edited_at', 'u.first_name', 'u.last_name',
                'u.user_name', 'u.avatar',
            ])
            ->selectSub(
                fn ($query) => $query->from('social_post_likes as l')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('l.post_id', 'p.id')
                    ->where('l.app_id', $appId),
                'likes_count'
            )
            ->selectSub(
                fn ($query) => $query->from('social_posts as r')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('r.parent_id', 'p.id')
                    ->where('r.app_id', $appId)
                    ->where('r.status', 'published'),
                'comments_count'
            )
            ->orderByDesc('p.created_at')
            ->orderByDesc('p.id')
            ->paginate($perPage);

        $postIds = collect($posts->items())->pluck('id')->map(fn ($id) => (int) $id)->values();
        $comments = $postIds->isEmpty()
            ? collect()
            : DB::table('social_posts as p')
                ->join('users as u', 'u.id', '=', 'p.user_id')
                ->where('p.app_id', $appId)
                ->whereIn('p.parent_id', $postIds)
                ->where('p.status', 'published')
                ->select([
                    'p.id', 'p.parent_id', 'p.user_id', 'p.body', 'p.created_at',
                    'u.first_name', 'u.last_name', 'u.user_name', 'u.avatar',
                ])
                ->selectSub(
                    fn ($query) => $query->from('social_post_likes as l')
                        ->selectRaw('COUNT(*)')
                        ->whereColumn('l.post_id', 'p.id')
                        ->where('l.app_id', $appId),
                    'likes_count'
                )
                ->orderBy('p.created_at')
                ->get()
                ->groupBy('parent_id');

        $likedPostIds = collect();
        if ($user) {
            $allIds = $postIds
                ->merge($comments->flatten(1)->pluck('id'))
                ->filter()
                ->unique()
                ->values();

            if ($allIds->isNotEmpty()) {
                $likedPostIds = DB::table('social_post_likes')
                    ->where('app_id', $appId)
                    ->where('user_id', $user->id)
                    ->whereIn('post_id', $allIds)
                    ->pluck('post_id');
            }
        }

        $posts->setCollection(collect($posts->items())->map(function ($post) use ($comments, $likedPostIds, $user) {
            $post->is_liked = $user ? $likedPostIds->contains($post->id) : false;
            $post->comments = collect($comments->get($post->id, []))->map(function ($comment) use ($likedPostIds, $user) {
                $comment->is_liked = $user ? $likedPostIds->contains($comment->id) : false;
                return $comment;
            })->values();
            return $post;
        }));

        return response()->json(['posts' => $posts]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'body' => 'nullable|string|max:3000',
            'parent_id' => 'nullable|integer|min:1',
            'media' => 'nullable|file|max:51200|mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,video/quicktime',
        ]);

        $body = trim((string) ($data['body'] ?? ''));
        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        $media = $request->file('media');

        if ($body === '' && ! $media) {
            throw ValidationException::withMessages(['body' => 'Escreva algo ou escolha uma foto/vídeo para publicar.']);
        }
        if ($parentId && $media) {
            throw ValidationException::withMessages(['media' => 'Anexos são permitidos na publicação principal.']);
        }

        $appId = $this->context->id();
        $user = $request->user();
        $parent = null;

        if ($parentId) {
            $parent = DB::table('social_posts')
                ->where('app_id', $appId)
                ->where('id', $parentId)
                ->where('scope_type', 'global')
                ->whereNull('scope_id')
                ->whereNull('parent_id')
                ->where('status', 'published')
                ->first();
            abort_unless($parent, 422, 'A publicação que você tentou comentar não está mais disponível.');
        }

        $mediaPath = null;
        $mediaType = null;
        if ($media) {
            $mime = (string) $media->getMimeType();
            $mediaType = str_starts_with($mime, 'video/') ? 'video' : 'image';
            $mediaPath = $media->store('social/'.$appId.'/'.now()->format('Y/m'), 'public');
        }

        $postId = DB::table('social_posts')->insertGetId([
            'app_id' => $appId,
            'user_id' => $user->id,
            'parent_id' => $parentId,
            'scope_type' => 'global',
            'scope_id' => null,
            'body' => $body,
            'media_path' => $mediaPath,
            'media_type' => $mediaType,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($parent && (int) $parent->user_id !== (int) $user->id) {
            $this->safeNotify((int) $parent->user_id, [
                'type' => 'social_reply',
                'title' => 'Comentaram sua publicação',
                'message' => $this->actorName($user).' comentou sua publicação na Cutinapp.',
                'reference_type' => 'social_post',
                'reference_id' => $postId,
                'reference_url' => '/feed#post-'.$parentId,
                'data' => ['post_id' => $postId, 'parent_id' => $parentId, 'actor_id' => $user->id],
            ]);
        }

        return response()->json([
            'message' => $parent ? 'Comentário publicado.' : 'Publicação enviada para o feed.',
            'post_id' => $postId,
        ], 201);
    }

    public function destroy(Request $request, int $postId)
    {
        $appId = $this->context->id();
        $post = DB::table('social_posts')->where('app_id', $appId)->where('id', $postId)->first();
        abort_unless($post, 404, 'Publicação não encontrada.');

        $user = $request->user();
        $isAdmin = method_exists($user, 'hasProfile') && $user->hasProfile('Administrador');
        abort_unless((int) $post->user_id === (int) $user->id || $isAdmin, 403, 'Você não pode remover esta publicação.');

        if (! empty($post->media_path)) {
            Storage::disk('public')->delete($post->media_path);
        }

        DB::table('social_posts')
            ->where('app_id', $appId)
            ->where(fn ($query) => $query->where('id', $postId)->orWhere('parent_id', $postId))
            ->update(['status' => 'hidden', 'updated_at' => now()]);

        return response()->json(['message' => 'Publicação removida.']);
    }

    public function like(Request $request, int $postId)
    {
        $appId = $this->context->id();
        $post = $this->publishedPost($postId);
        $user = $request->user();

        $alreadyLiked = DB::table('social_post_likes')->where([
            'app_id' => $appId,
            'post_id' => $postId,
            'user_id' => $user->id,
        ])->exists();

        DB::table('social_post_likes')->updateOrInsert(
            ['app_id' => $appId, 'post_id' => $postId, 'user_id' => $user->id],
            ['created_at' => now(), 'updated_at' => now()]
        );

        if (! $alreadyLiked && (int) $post->user_id !== (int) $user->id) {
            $targetId = $post->parent_id ? (int) $post->parent_id : $postId;
            $this->safeNotify((int) $post->user_id, [
                'type' => 'social_like',
                'title' => 'Curtiram sua publicação',
                'message' => $this->actorName($user).' curtiu o que você publicou na Cutinapp.',
                'reference_type' => 'social_post',
                'reference_id' => $postId,
                'reference_url' => '/feed#post-'.$targetId,
                'data' => ['post_id' => $postId, 'actor_id' => $user->id],
            ]);
        }

        return response()->json(['liked' => true]);
    }

    public function unlike(Request $request, int $postId)
    {
        DB::table('social_post_likes')->where([
            'app_id' => $this->context->id(),
            'post_id' => $postId,
            'user_id' => $request->user()->id,
        ])->delete();

        return response()->json(['liked' => false]);
    }

    private function publishedPost(int $postId): object
    {
        $post = DB::table('social_posts')
            ->where('app_id', $this->context->id())
            ->where('id', $postId)
            ->where('scope_type', 'global')
            ->where('status', 'published')
            ->first();
        abort_unless($post, 404, 'Publicação não encontrada.');
        return $post;
    }

    private function optionalUser(Request $request): ?User
    {
        $token = $request->bearerToken();
        if (! $token) return null;
        try {
            $user = JWTAuth::setToken($token)->authenticate();
            return $user instanceof User ? $user : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function safeNotify(int $userId, array $payload): void
    {
        try {
            $this->notifications->sendToUser($this->context->id(), $userId, $payload);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function actorName(User $user): string
    {
        return trim((string) $user->first_name) ?: 'Alguém';
    }
}
