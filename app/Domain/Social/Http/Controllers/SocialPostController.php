<?php

namespace App\Domain\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Production;
use App\Models\User;
use App\Services\AppNotificationService;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;
use Tymon\JWTAuth\Facades\JWTAuth;

final class SocialPostController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AppNotificationService $notifications,
    ) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:40',
            'scope_type' => 'nullable|in:global,production',
            'scope_id' => 'nullable|integer|min:1',
        ]);

        $scopeType = $data['scope_type'] ?? 'global';
        $scopeId = $scopeType === 'production' ? (int) ($data['scope_id'] ?? 0) : null;
        if ($scopeType === 'production') {
            abort_unless($scopeId > 0, 422, 'Informe a produção.');
            $this->publicProduction($scopeId);
        }

        $appId = $this->context->id();
        $user = $this->optionalUser($request);
        $perPage = (int) ($data['per_page'] ?? 20);

        $posts = DB::table('social_posts as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.app_id', $appId)
            ->where('p.scope_type', $scopeType)
            ->when($scopeId, fn ($q) => $q->where('p.scope_id', $scopeId), fn ($q) => $q->whereNull('p.scope_id'))
            ->whereNull('p.parent_id')
            ->where('p.status', 'published')
            ->select(['p.id','p.user_id','p.scope_type','p.scope_id','p.body','p.created_at','p.edited_at','u.first_name','u.last_name','u.user_name','u.avatar'])
            ->selectSub(fn ($q) => $q->from('social_post_likes as l')->selectRaw('COUNT(*)')->whereColumn('l.post_id', 'p.id')->where('l.app_id', $appId), 'likes_count')
            ->selectSub(fn ($q) => $q->from('social_posts as r')->selectRaw('COUNT(*)')->whereColumn('r.parent_id', 'p.id')->where('r.status', 'published'), 'comments_count')
            ->selectSub(fn ($q) => $q->from('social_post_views as v')->selectRaw('COUNT(*)')->whereColumn('v.post_id', 'p.id')->where('v.source_type', 'social')->where('v.app_id', $appId), 'views_count')
            ->orderByDesc('p.created_at')
            ->paginate($perPage);

        $ids = collect($posts->items())->pluck('id')->filter()->values();
        $replies = $ids->isEmpty() ? collect() : DB::table('social_posts as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.app_id', $appId)
            ->whereIn('p.parent_id', $ids)
            ->where('p.status', 'published')
            ->select(['p.id','p.parent_id','p.user_id','p.body','p.created_at','p.edited_at','u.first_name','u.last_name','u.user_name','u.avatar'])
            ->selectSub(fn ($q) => $q->from('social_post_likes as l')->selectRaw('COUNT(*)')->whereColumn('l.post_id', 'p.id')->where('l.app_id', $appId), 'likes_count')
            ->selectSub(fn ($q) => $q->from('social_post_views as v')->selectRaw('COUNT(*)')->whereColumn('v.post_id', 'p.id')->where('v.source_type', 'social')->where('v.app_id', $appId), 'views_count')
            ->orderBy('p.created_at')
            ->get()
            ->groupBy('parent_id');

        $liked = collect();
        if ($user) {
            $all = $ids->merge($replies->flatten(1)->pluck('id'))->filter()->values();
            if ($all->isNotEmpty()) {
                $liked = DB::table('social_post_likes')->where('app_id', $appId)->where('user_id', $user->id)->whereIn('post_id', $all)->pluck('post_id');
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

        return response()->json(['posts' => $posts, 'scope' => ['type' => $scopeType, 'id' => $scopeId]]);
    }

    public function create(Request $request)
    {
        $data = $request->validate([
            'body' => 'required|string|min:2|max:3000',
            'parent_id' => 'nullable|integer|min:1',
            'scope_type' => 'nullable|in:global,production',
            'scope_id' => 'nullable|integer|min:1',
        ]);

        $user = $request->user();
        $appId = $this->context->id();
        $scopeType = $data['scope_type'] ?? 'global';
        $scopeId = $scopeType === 'production' ? (int) ($data['scope_id'] ?? 0) : null;
        if ($scopeType === 'production') {
            abort_unless($scopeId > 0, 422, 'Informe a produção.');
            $this->publicProduction($scopeId);
        }

        $parent = null;
        if ($parentId = $data['parent_id'] ?? null) {
            $parent = DB::table('social_posts')
                ->where('app_id', $appId)
                ->where('id', $parentId)
                ->whereNull('parent_id')
                ->where('scope_type', $scopeType)
                ->when($scopeId, fn ($q) => $q->where('scope_id', $scopeId), fn ($q) => $q->whereNull('scope_id'))
                ->where('status', 'published')
                ->first();
            abort_unless($parent, 422, 'A publicação que você tentou responder não está mais disponível.');
        }

        $id = DB::table('social_posts')->insertGetId([
            'app_id' => $appId,
            'user_id' => $user->id,
            'parent_id' => $data['parent_id'] ?? null,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'body' => trim($data['body']),
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($parent && (int) $parent->user_id !== (int) $user->id) {
            $this->safeNotify((int) $parent->user_id, [
                'type' => 'social_reply',
                'title' => 'Responderam sua publicação',
                'message' => (trim((string) $user->first_name) ?: 'Alguém') . ' respondeu sua publicação na Cutinapp.',
                'reference_type' => 'social_post',
                'reference_id' => $id,
                'reference_url' => $scopeType === 'production' ? '/production/' . $this->publicProduction($scopeId)->slug . '/public#comunidade' : '/feed',
                'data' => ['post_id' => $id, 'actor_id' => $user->id],
            ]);
        }

        return response()->json(['message' => $parent ? 'Resposta publicada.' : 'Publicação enviada para o feed.', 'post_id' => $id], 201);
    }

    public function delete(Request $request, int $postId)
    {
        $post = DB::table('social_posts')->where('app_id', $this->context->id())->where('id', $postId)->first();
        abort_unless($post, 404, 'Publicação não encontrada.');
        $user = $request->user();
        abort_unless((int) $post->user_id === (int) $user->id || $user->hasProfile('Administrador'), 403, 'Você não pode remover esta publicação.');
        DB::table('social_posts')->where('app_id', $this->context->id())->where(fn ($q) => $q->where('id', $postId)->orWhere('parent_id', $postId))->update(['status' => 'hidden', 'updated_at' => now()]);
        return response()->json(['message' => 'Publicação removida.']);
    }

    public function like(Request $request, int $postId)
    {
        $post = $this->publishedSocialPost($postId);
        $user = $request->user();
        $appId = $this->context->id();
        $already = DB::table('social_post_likes')->where(['app_id' => $appId, 'post_id' => $post->id, 'user_id' => $user->id])->exists();
        DB::table('social_post_likes')->updateOrInsert(
            ['app_id' => $appId, 'post_id' => $post->id, 'user_id' => $user->id],
            ['created_at' => now(), 'updated_at' => now()]
        );
        if (! $already && (int) $post->user_id !== (int) $user->id) {
            $this->safeNotify((int) $post->user_id, [
                'type' => 'social_like',
                'title' => 'Curtiram sua publicação',
                'message' => (trim((string) $user->first_name) ?: 'Alguém') . ' curtiu sua publicação na Cutinapp.',
                'reference_type' => 'social_post',
                'reference_id' => $post->id,
                'reference_url' => '/feed',
                'data' => ['post_id' => $post->id, 'actor_id' => $user->id],
            ]);
        }
        return response()->json(['liked' => true]);
    }

    public function unlike(Request $request, int $postId)
    {
        DB::table('social_post_likes')->where(['app_id' => $this->context->id(), 'post_id' => $postId, 'user_id' => $request->user()->id])->delete();
        return response()->json(['liked' => false]);
    }

    public function recordView(Request $request, int $postId)
    {
        $post = $this->publishedSocialPost($postId);
        return $this->record($request, 'social', $postId, (int) $post->user_id);
    }

    public function viewers(Request $request, int $postId)
    {
        $this->publishedSocialPost($postId);
        return $this->viewerResponse('social', $postId);
    }

    public function recordEventView(Request $request, int $postId)
    {
        $post = $this->publishedEventPost($postId);
        return $this->record($request, 'event', $postId, (int) $post->user_id);
    }

    public function eventViewers(Request $request, int $postId)
    {
        $this->publishedEventPost($postId);
        return $this->viewerResponse('event', $postId);
    }

    private function record(Request $request, string $sourceType, int $postId, int $authorId)
    {
        $appId = $this->context->id();
        $user = $this->optionalUser($request);
        if (! $user || (int) $user->id !== $authorId) {
            $identity = $user ? 'user:' . $user->id : 'guest:' . (string) $request->ip() . '|' . substr((string) $request->userAgent(), 0, 500);
            $visitorKey = hash('sha256', $identity);
            $exists = DB::table('social_post_views')
                ->where(['app_id' => $appId, 'source_type' => $sourceType, 'post_id' => $postId, 'visitor_key' => $visitorKey])
                ->where('viewed_at', '>=', now()->subMinutes(30))
                ->exists();
            if (! $exists) {
                DB::table('social_post_views')->insert([
                    'app_id' => $appId,
                    'source_type' => $sourceType,
                    'post_id' => $postId,
                    'user_id' => $user?->id,
                    'visitor_key' => $visitorKey,
                    'viewed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
        return response()->json(['views_count' => DB::table('social_post_views')->where(['app_id' => $appId, 'source_type' => $sourceType, 'post_id' => $postId])->count()]);
    }

    private function viewerResponse(string $sourceType, int $postId)
    {
        $appId = $this->context->id();
        $base = DB::table('social_post_views')->where(['app_id' => $appId, 'source_type' => $sourceType, 'post_id' => $postId]);
        $identified = DB::table('social_post_views as v')
            ->join('users as u', 'u.id', '=', 'v.user_id')
            ->where(['v.app_id' => $appId, 'v.source_type' => $sourceType, 'v.post_id' => $postId])
            ->select(['u.id','u.first_name','u.last_name','u.user_name','u.avatar'])
            ->selectRaw('COUNT(*) as views_count, MAX(v.viewed_at) as last_viewed_at')
            ->groupBy('u.id','u.first_name','u.last_name','u.user_name','u.avatar')
            ->orderByDesc('last_viewed_at')
            ->limit(100)
            ->get();

        return response()->json([
            'views_count' => (clone $base)->count(),
            'unique_viewers_count' => (clone $base)->distinct('visitor_key')->count('visitor_key'),
            'anonymous_views_count' => (clone $base)->whereNull('user_id')->count(),
            'viewers' => $identified,
        ]);
    }

    private function publishedSocialPost(int $postId): object
    {
        $post = DB::table('social_posts')->where('app_id', $this->context->id())->where('id', $postId)->where('status', 'published')->first();
        abort_unless($post, 404, 'Publicação não encontrada.');
        if ($post->scope_type === 'production') $this->publicProduction((int) $post->scope_id);
        return $post;
    }

    private function publishedEventPost(int $postId): object
    {
        $post = DB::table('event_posts as p')
            ->join('events as e', 'e.id', '=', 'p.event_id')
            ->where('p.app_id', $this->context->id())
            ->where('p.id', $postId)
            ->where('p.status', 'published')
            ->where('e.is_published', true)
            ->where('e.is_cancelled', false)
            ->where(fn ($q) => $q->where('e.is_private', false)->orWhereNull('e.is_private'))
            ->select(['p.id','p.user_id'])
            ->first();
        abort_unless($post, 404, 'Publicação não encontrada.');
        return $post;
    }

    private function publicProduction(int $id): Production
    {
        return Production::where('app_id', $this->context->id())->whereKey($id)->where('is_published', true)->where('is_cancelled', false)->firstOrFail();
    }

    private function safeNotify(int $userId, array $payload): void
    {
        try { $this->notifications->sendToUser($this->context->id(), $userId, $payload); } catch (Throwable $e) { report($e); }
    }

    private function optionalUser(Request $request): ?User
    {
        $token = $request->bearerToken();
        if (! $token) return null;
        try { $user = JWTAuth::setToken($token)->authenticate(); return $user instanceof User ? $user : null; } catch (Throwable) { return null; }
    }
}
