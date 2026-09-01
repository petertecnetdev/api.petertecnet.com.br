<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Event;
use App\Models\EventPass;
use App\Models\User;
use App\Services\AppNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappEventCommunityController extends Controller
{
    private const APP = 'cutinapp';

    public function __construct(private AppNotificationService $notifications) {}

    public function publicCommunity(Request $request, string $slug)
    {
        $event = $this->publicEventBySlug($slug);
        $appId = $this->applicationId();
        $user = $this->optionalRequestUser($request);
        $perPage = min(max((int) $request->input('per_page', 10), 1), 30);

        $posts = DB::table('cutinapp_event_posts as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.app_id', $appId)
            ->where('p.event_id', $event->id)
            ->whereNull('p.parent_id')
            ->where('p.status', 'published')
            ->select(['p.id','p.event_id','p.user_id','p.body','p.is_pinned','p.created_at','p.edited_at','u.first_name','u.last_name','u.avatar'])
            ->selectSub(function ($q) use ($appId) {
                $q->from('cutinapp_event_post_likes as l')->selectRaw('COUNT(*)')->whereColumn('l.post_id', 'p.id')->where('l.app_id', $appId);
            }, 'likes_count')
            ->selectSub(function ($q) {
                $q->from('cutinapp_event_posts as r')->selectRaw('COUNT(*)')->whereColumn('r.parent_id', 'p.id')->where('r.status', 'published');
            }, 'comments_count')
            ->orderByDesc('p.is_pinned')
            ->orderByDesc('p.created_at')
            ->paginate($perPage);

        $ids = collect($posts->items())->pluck('id')->filter()->values();
        $replies = $ids->isEmpty() ? collect() : DB::table('cutinapp_event_posts as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.app_id', $appId)
            ->where('p.event_id', $event->id)
            ->whereIn('p.parent_id', $ids)
            ->where('p.status', 'published')
            ->select(['p.id','p.parent_id','p.user_id','p.body','p.created_at','p.edited_at','u.first_name','u.last_name','u.avatar'])
            ->selectSub(function ($q) use ($appId) {
                $q->from('cutinapp_event_post_likes as l')->selectRaw('COUNT(*)')->whereColumn('l.post_id', 'p.id')->where('l.app_id', $appId);
            }, 'likes_count')
            ->orderBy('p.created_at')
            ->get()
            ->groupBy('parent_id');

        $likedIds = collect();
        if ($user) {
            $allIds = $ids->merge($replies->flatten(1)->pluck('id'))->filter()->values();
            if ($allIds->isNotEmpty()) {
                $likedIds = DB::table('cutinapp_event_post_likes')->where('app_id', $appId)->where('user_id', $user->id)->whereIn('post_id', $allIds)->pluck('post_id');
            }
        }

        $posts->setCollection(collect($posts->items())->map(function ($post) use ($replies, $likedIds, $user) {
            $post->is_liked = $user ? $likedIds->contains($post->id) : false;
            $post->replies = collect($replies->get($post->id, []))->map(function ($reply) use ($likedIds, $user) {
                $reply->is_liked = $user ? $likedIds->contains($reply->id) : false;
                return $reply;
            })->values();
            return $post;
        }));

        $rating = DB::table('cutinapp_event_ratings')->where('app_id', $appId)->where('event_id', $event->id)
            ->selectRaw('ROUND(AVG(rating), 1) as average, COUNT(*) as total')->first();
        $myRating = $user ? DB::table('cutinapp_event_ratings')->where(['app_id'=>$appId,'event_id'=>$event->id,'user_id'=>$user->id])->value('rating') : null;

        return response()->json([
            'posts' => $posts,
            'rating' => ['average' => $rating?->average ? (float) $rating->average : 0, 'total' => (int) ($rating?->total ?? 0), 'mine' => $myRating ? (int) $myRating : null],
        ]);
    }

    public function createPost(Request $request, int $eventId)
    {
        $user = $this->requestUser($request);
        $event = $this->publicEventById($eventId);
        $appId = $this->applicationId();
        $data = $request->validate([
            'body' => 'required|string|min:2|max:3000',
            'parent_id' => 'nullable|integer|min:1',
        ], [
            'body.required' => 'Escreva uma mensagem antes de publicar.',
            'body.min' => 'Sua mensagem precisa ter pelo menos 2 caracteres.',
            'body.max' => 'Sua mensagem pode ter no máximo 3.000 caracteres.',
        ]);

        $parentId = $data['parent_id'] ?? null;
        $parent = null;
        if ($parentId) {
            $parent = DB::table('cutinapp_event_posts')->where('id', $parentId)->where('app_id', $appId)->where('event_id', $event->id)->whereNull('parent_id')->where('status', 'published')->first();
            abort_unless($parent, 422, 'A publicação que você tentou responder não está mais disponível.');
        }

        $id = DB::table('cutinapp_event_posts')->insertGetId([
            'app_id' => $appId, 'event_id' => $event->id, 'user_id' => $user->id,
            'parent_id' => $parentId, 'body' => trim($data['body']), 'status' => 'published',
            'is_pinned' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->notifyCommunityActivity($event, $user, $id, $parent);

        return response()->json(['message' => $parentId ? 'Comentário publicado.' : 'Publicação adicionada ao evento.', 'post_id' => $id], 201);
    }

    public function deletePost(Request $request, int $postId)
    {
        $user = $this->requestUser($request);
        $appId = $this->applicationId();
        $post = DB::table('cutinapp_event_posts')->where('app_id', $appId)->where('id', $postId)->first();
        abort_unless($post, 404, 'Publicação não encontrada.');

        $event = Event::with('production')->where('app_id', $appId)->where('app_slug', self::APP)->find($post->event_id);
        $canModerate = (int) $post->user_id === (int) $user->id
            || $user->hasProfile('Administrador')
            || ($event?->production && (int) $event->production->user_id === (int) $user->id);
        abort_unless($canModerate, 403, 'Você não tem permissão para remover esta publicação.');

        DB::table('cutinapp_event_posts')->where('app_id', $appId)->where(function ($q) use ($postId) { $q->where('id', $postId)->orWhere('parent_id', $postId); })->update(['status'=>'hidden','updated_at'=>now()]);
        return response()->json(['message' => 'Publicação removida.']);
    }

    public function like(Request $request, int $postId)
    {
        $user = $this->requestUser($request);
        $post = $this->publishedPost($postId);
        $appId = $this->applicationId();
        $alreadyLiked = DB::table('cutinapp_event_post_likes')->where(['app_id'=>$appId,'post_id'=>$post->id,'user_id'=>$user->id])->exists();

        DB::table('cutinapp_event_post_likes')->updateOrInsert(
            ['app_id'=>$appId,'post_id'=>$post->id,'user_id'=>$user->id],
            ['created_at'=>now(),'updated_at'=>now()]
        );

        if (! $alreadyLiked && (int) $post->user_id !== (int) $user->id) {
            $event = Event::query()->where('app_id', $appId)->where('app_slug', self::APP)->find($post->event_id);
            if ($event) {
                $actor = trim((string) ($user->first_name ?? '')) ?: 'Alguém';
                $this->safeNotifyUser($appId, (int) $post->user_id, [
                    'type' => 'comment_like',
                    'title' => 'Curtiram sua publicação',
                    'message' => $actor . ' curtiu o que você publicou em ' . $event->title . '.',
                    'reference_type' => 'event',
                    'reference_id' => $event->id,
                    'reference_url' => '/event/' . $event->slug . '#comunidade',
                    'data' => ['event_id' => $event->id, 'post_id' => $post->id, 'actor_id' => $user->id],
                ]);
            }
        }

        return response()->json(['message' => 'Publicação curtida.', 'liked' => true]);
    }

    public function unlike(Request $request, int $postId)
    {
        $user = $this->requestUser($request);
        DB::table('cutinapp_event_post_likes')->where(['app_id'=>$this->applicationId(),'post_id'=>$postId,'user_id'=>$user->id])->delete();
        return response()->json(['message' => 'Curtida removida.', 'liked' => false]);
    }

    public function rate(Request $request, int $eventId)
    {
        $user = $this->requestUser($request);
        $event = $this->publicEventById($eventId);
        $data = $request->validate(['rating' => 'required|integer|min:1|max:5'], ['rating.*' => 'Escolha uma nota entre 1 e 5.']);
        $verified = EventPass::query()->where('event_id', $event->id)->where('user_id', $user->id)->exists();

        DB::table('cutinapp_event_ratings')->updateOrInsert(
            ['app_id'=>$this->applicationId(),'event_id'=>$event->id,'user_id'=>$user->id],
            ['rating'=>(int)$data['rating'],'verified_attendee'=>$verified,'created_at'=>now(),'updated_at'=>now()]
        );
        return response()->json(['message' => 'Sua avaliação foi registrada.', 'rating' => (int) $data['rating'], 'verified_attendee' => $verified]);
    }

    public function report(Request $request, int $eventId)
    {
        $user = $this->requestUser($request);
        $event = $this->publicEventById($eventId);
        $data = $request->validate([
            'reason' => 'required|in:fraud,misleading,inappropriate,safety,cancelled,illegal,hate,harassment,spam,copyright,other',
            'details' => 'nullable|string|max:3000',
        ], [
            'reason.required' => 'Selecione o motivo da denúncia.',
            'reason.in' => 'Selecione um motivo válido para a denúncia.',
            'details.max' => 'Os detalhes podem ter no máximo 3.000 caracteres.',
        ]);

        DB::table('cutinapp_event_reports')->updateOrInsert(
            ['app_id'=>$this->applicationId(),'event_id'=>$event->id,'user_id'=>$user->id],
            ['reason'=>$data['reason'],'details'=>trim((string)($data['details'] ?? '')) ?: null,'status'=>'open','reviewed_by'=>null,'reviewed_at'=>null,'moderation_note'=>null,'created_at'=>now(),'updated_at'=>now()]
        );
        return response()->json(['message' => 'Denúncia enviada. Nossa equipe poderá revisar este evento.']);
    }

    private function notifyCommunityActivity(Event $event, User $actor, int $postId, ?object $parent): void
    {
        $appId = $this->applicationId();
        $parentAuthorId = $parent ? (int) $parent->user_id : null;
        $attendees = EventPass::query()
            ->where('event_id', $event->id)
            ->whereNotNull('user_id')
            ->whereNotIn('status', ['cancelled', 'refunded', 'charged_back'])
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->reject(fn ($id) => $id === (int) $actor->id || ($parentAuthorId && $id === $parentAuthorId))
            ->values();

        $actorName = trim((string) ($actor->first_name ?? '')) ?: 'Alguém';
        $payload = [
            'type' => $parent ? 'event_reply' : 'event_comment',
            'title' => $parent ? 'Nova resposta no evento' : 'Novo comentário no evento',
            'message' => $actorName . ($parent ? ' respondeu uma conversa em ' : ' publicou na conversa de ') . $event->title . '.',
            'reference_type' => 'event',
            'reference_id' => $event->id,
            'reference_url' => '/event/' . $event->slug . '#comunidade',
            'data' => ['event_id' => $event->id, 'post_id' => $postId, 'actor_id' => $actor->id],
        ];

        try {
            $this->notifications->sendToUsers($appId, $attendees, $payload, (int) $actor->id);
        } catch (\Throwable $e) {
            report($e);
        }

        if ($parentAuthorId && $parentAuthorId !== (int) $actor->id) {
            $this->safeNotifyUser($appId, $parentAuthorId, [
                'type' => 'event_reply',
                'title' => 'Responderam sua publicação',
                'message' => $actorName . ' respondeu sua publicação em ' . $event->title . '.',
                'reference_type' => 'event',
                'reference_id' => $event->id,
                'reference_url' => '/event/' . $event->slug . '#comunidade',
                'data' => ['event_id' => $event->id, 'post_id' => $postId, 'parent_post_id' => $parent->id, 'actor_id' => $actor->id],
            ]);
        }
    }

    private function safeNotifyUser(int $appId, int $userId, array $payload): void
    {
        try {
            $this->notifications->sendToUser($appId, $userId, $payload);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function publishedPost(int $postId): object
    {
        $post = DB::table('cutinapp_event_posts')->where('app_id', $this->applicationId())->where('id', $postId)->where('status', 'published')->first();
        abort_unless($post, 404, 'Publicação não encontrada.');
        return $post;
    }

    private function publicEventBySlug(string $slug): Event
    {
        return Event::query()
            ->where('app_id', $this->applicationId())
            ->where('app_slug', self::APP)
            ->where('slug', $slug)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where(fn ($query) => $query->where('is_private', false)->orWhereNull('is_private'))
            ->firstOrFail();
    }

    private function publicEventById(int $id): Event
    {
        return Event::query()
            ->where('app_id', $this->applicationId())
            ->where('app_slug', self::APP)
            ->where('id', $id)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where(fn ($query) => $query->where('is_private', false)->orWhereNull('is_private'))
            ->firstOrFail();
    }

    private function applicationId(): int
    {
        $id = Application::query()->where('slug', self::APP)->where('is_active', true)->value('id');
        abort_unless($id, 503, 'A Cutinapp não está registrada corretamente na API.');
        return (int) $id;
    }

    private function requestUser(Request $request): User
    {
        $user = $this->optionalRequestUser($request);
        abort_unless($user instanceof User, 401, 'Sessão inválida ou expirada. Faça login novamente.');
        return $user;
    }

    private function optionalRequestUser(Request $request): ?User
    {
        $token = trim((string) $request->bearerToken());
        if ($token === '') return null;
        try { return JWTAuth::setToken($token)->authenticate() ?: null; } catch (\Throwable) { return null; }
    }
}
