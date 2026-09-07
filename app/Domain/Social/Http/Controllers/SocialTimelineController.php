<?php

namespace App\Domain\Social\Http\Controllers;

use App\Domain\Social\Events\SocialTimelineChanged;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventPass;
use App\Models\User;
use App\Services\AppNotificationService;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class SocialTimelineController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AppNotificationService $notifications,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $appId = $this->context->id();
        $data = $request->validate([
            'per_page' => 'nullable|integer|min:8|max:30',
            'cursor' => 'nullable|string|max:160',
        ]);
        $perPage = (int) ($data['per_page'] ?? 16);
        $cursorAt = $this->decodeCursor($data['cursor'] ?? null);
        $now = now();

        $followedProductions = DB::table('follows')
            ->where(['app_id' => $appId, 'user_id' => $user->id, 'target_type' => 'production'])
            ->pluck('target_id')->map(fn ($id) => (int) $id)->all();
        $followedArtists = DB::table('follows')
            ->where(['app_id' => $appId, 'user_id' => $user->id, 'target_type' => 'artist'])
            ->pluck('target_id')->map(fn ($id) => (int) $id)->all();
        $engagedEvents = DB::table('event_engagements')
            ->where('app_id', $appId)->where('user_id', $user->id)
            ->where(fn ($query) => $query->where('is_favorite', true)->orWhere('is_interested', true))
            ->pluck('event_id')->map(fn ($id) => (int) $id)->all();
        $ticketEvents = EventPass::query()
            ->where('user_id', $user->id)
            ->whereHas('event', fn ($query) => $query->where('app_id', $appId))
            ->whereNotIn('status', ['cancelled', 'refunded', 'charged_back'])
            ->pluck('event_id')->unique()->map(fn ($id) => (int) $id)->all();
        $preference = DB::table('application_user_preferences')
            ->where(['app_id' => $appId, 'user_id' => $user->id])->first();

        $postRows = DB::table('social_posts as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->leftJoin('profiles as pf', 'pf.id', '=', 'u.profile_id')
            ->leftJoin('events as e', 'e.id', '=', 'p.event_id')
            ->leftJoin('productions as pr', 'pr.id', '=', 'e.production_id')
            ->leftJoin('social_post_metrics as m', function ($join) use ($appId) {
                $join->on('m.post_id', '=', 'p.id')->where('m.app_id', '=', $appId);
            })
            ->where('p.app_id', $appId)
            ->whereNull('p.parent_id')
            ->where('p.status', 'published')
            ->where('p.visibility', 'public')
            ->where(fn ($query) => $query->whereNull('p.expires_at')->orWhere('p.expires_at', '>', $now))
            ->when($cursorAt, fn ($query) => $query->where('p.published_at', '<', $cursorAt))
            ->orderByDesc('p.published_at')
            ->orderByDesc('p.id')
            ->limit($perPage * 3)
            ->get([
                'p.id', 'p.user_id', 'p.event_id', 'p.body', 'p.type', 'p.media_type', 'p.media_path', 'p.thumbnail_path',
                'p.location_name', 'p.location_lat', 'p.location_lng', 'p.source', 'p.campaign', 'p.promoter_id',
                'p.is_pinned', 'p.is_promoted', 'p.promoted_until', 'p.expires_at', 'p.metadata', 'p.published_at', 'p.created_at',
                'u.first_name', 'u.last_name', 'u.user_name', 'u.avatar', 'u.is_producer', 'u.is_promoter', 'pf.name as profile_name',
                'e.title as event_title', 'e.slug as event_slug', 'e.image as event_image', 'e.start_date as event_start_date',
                'e.city as event_city', 'e.production_id', 'pr.name as production_name', 'pr.slug as production_slug',
                DB::raw('COALESCE(m.impressions,0) as impressions'), DB::raw('COALESCE(m.opens,0) as opens'),
                DB::raw('COALESCE(m.event_clicks,0) as event_clicks'), DB::raw('COALESCE(m.ticket_clicks,0) as ticket_clicks'),
                DB::raw('COALESCE(m.likes,0) as likes_count'), DB::raw('COALESCE(m.comments,0) as comments_count'),
                DB::raw('COALESCE(m.shares,0) as shares_count'), DB::raw('COALESCE(m.saves,0) as saves_count'),
                DB::raw('COALESCE(m.conversions,0) as conversions'), DB::raw('COALESCE(m.gmv_cents,0) as gmv_cents'),
                DB::raw('COALESCE(m.platform_revenue_cents,0) as platform_revenue_cents'),
            ]);

        $eventRows = DB::table('events as e')
            ->leftJoin('productions as pr', 'pr.id', '=', 'e.production_id')
            ->where('e.app_id', $appId)
            ->where('e.is_published', true)
            ->where('e.is_cancelled', false)
            ->where(fn ($query) => $query->where('e.is_private', false)->orWhereNull('e.is_private'))
            ->where(fn ($query) => $query->whereNull('e.end_date')->orWhere('e.end_date', '>', $now))
            ->when($cursorAt, fn ($query) => $query->where('e.created_at', '<', $cursorAt))
            ->select([
                'e.id', 'e.title', 'e.slug', 'e.description', 'e.category', 'e.image', 'e.start_date', 'e.end_date', 'e.city', 'e.uf',
                'e.venue', 'e.production_id', 'e.is_featured', 'e.created_at', 'pr.name as production_name', 'pr.slug as production_slug', 'pr.logo as production_logo',
            ])
            ->selectSub(fn ($q) => $q->from('event_engagements as eg')->selectRaw('COUNT(*)')->whereColumn('eg.event_id', 'e.id')->where('eg.app_id', $appId), 'engagement_count')
            ->selectSub(fn ($q) => $q->from('event_passes as ep')->selectRaw('COUNT(*)')->whereColumn('ep.event_id', 'e.id')->whereNotIn('ep.status', ['cancelled', 'refunded', 'charged_back']), 'passes_count')
            ->selectSub(fn ($q) => $q->from('tickets as t')->selectRaw('MIN(t.price)')->whereColumn('t.event_id', 'e.id')->where('t.app_id', $appId)->where('t.price', '>', 0), 'price_from')
            ->orderByDesc('e.created_at')
            ->orderByDesc('e.id')
            ->limit($perPage * 3)
            ->get();

        $postIds = $postRows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->enrichPosts($postRows, $postIds, (int) $user->id, $appId);

        $eventIds = $eventRows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $artistFollowEvents = empty($eventIds) || empty($followedArtists)
            ? []
            : DB::table('event_artist')->whereIn('event_id', $eventIds)->whereIn('artist_id', $followedArtists)->pluck('event_id')->unique()->map(fn ($id) => (int) $id)->all();

        $context = [
            'app_id' => $appId,
            'preferred_city' => $preference?->preferred_city,
            'preferred_uf' => $preference?->preferred_uf,
            'followed_productions' => $followedProductions,
            'followed_artists' => $followedArtists,
            'engaged_events' => $engagedEvents,
            'ticket_events' => $ticketEvents,
            'artist_follow_events' => $artistFollowEvents,
        ];

        $candidates = collect();
        foreach ($postRows as $post) {
            $candidates->push($this->postItem($post, $context));
        }
        foreach ($eventRows as $event) {
            $candidates->push($this->eventItem($event, $context));
        }

        $items = $this->diversify($candidates, $perPage)->values();
        $lastSortAt = $items->pluck('sort_at')->filter()->sort()->first();

        return response()->json([
            'items' => $items,
            'next_cursor' => $items->count() >= $perPage && $lastSortAt ? $this->encodeCursor($lastSortAt) : null,
            'stories' => $this->stories($appId, (int) $user->id),
            'trending' => $this->trending($appId),
            'context' => [
                'app_id' => $appId,
                'preferred_city' => $preference?->preferred_city,
                'preferred_uf' => $preference?->preferred_uf,
                'following_productions' => count($followedProductions),
                'following_artists' => count($followedArtists),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $appId = $this->context->id();
        $data = $request->validate([
            'client_token' => 'nullable|string|min:8|max:64',
            'body' => 'nullable|string|max:3000',
            'type' => 'nullable|in:text,image,video,poll,location,event,story',
            'event_id' => 'nullable|integer|exists:events,id',
            'media' => 'nullable|file|max:51200|mimes:jpg,jpeg,png,webp,gif,mp4,webm,mov',
            'location_name' => 'nullable|string|max:180',
            'location_lat' => 'nullable|numeric|between:-90,90',
            'location_lng' => 'nullable|numeric|between:-180,180',
            'poll_question' => 'nullable|string|max:500',
            'poll_options' => 'nullable|array|min:2|max:6',
            'poll_options.*' => 'required_with:poll_options|string|min:1|max:240',
            'poll_allows_multiple' => 'nullable|boolean',
            'poll_ends_at' => 'nullable|date|after:now',
            'story_hours' => 'nullable|integer|min:1|max:48',
            'campaign' => 'nullable|string|max:120',
        ]);

        $clientToken = trim((string) ($data['client_token'] ?? '')) ?: null;
        if ($clientToken) {
            $existing = DB::table('social_posts')
                ->where(['app_id' => $appId, 'user_id' => $user->id, 'client_token' => $clientToken])
                ->first();
            if ($existing) {
                return response()->json(['post' => $this->singlePost((int) $existing->id, (int) $user->id), 'replayed' => true]);
            }
        }

        $event = null;
        if (! empty($data['event_id'])) {
            $event = Event::query()
                ->where('app_id', $appId)->whereKey((int) $data['event_id'])
                ->where('is_published', true)->where('is_cancelled', false)
                ->where(fn ($query) => $query->where('is_private', false)->orWhereNull('is_private'))
                ->firstOrFail();
        }

        $body = trim((string) ($data['body'] ?? ''));
        $type = (string) ($data['type'] ?? 'text');
        $mediaPath = null;
        $mediaType = null;
        if ($request->hasFile('media')) {
            $file = $request->file('media');
            $mime = (string) $file->getMimeType();
            $mediaType = str_starts_with($mime, 'video/') ? 'video' : 'image';
            $type = $type === 'story' ? 'story' : $mediaType;
            $mediaPath = $file->storePublicly('social/'.$appId.'/'.$user->id, 'public');
        }
        if ($type === 'poll' && (empty($data['poll_question']) || count($data['poll_options'] ?? []) < 2)) {
            abort(422, 'Informe a pergunta e pelo menos duas opções para a enquete.');
        }
        if ($body === '' && ! $mediaPath && $type !== 'poll' && ! $event) {
            abort(422, 'Escreva algo, adicione uma mídia, enquete ou relacione um evento.');
        }

        if ($body !== '') {
            $duplicate = DB::table('social_posts')
                ->where('app_id', $appId)->where('user_id', $user->id)->whereNull('parent_id')
                ->where('body', $body)->where('created_at', '>', now()->subSeconds(45))->exists();
            abort_if($duplicate, 429, 'Essa publicação acabou de ser enviada. Aguarde antes de repetir.');
        }

        $expiresAt = $type === 'story' ? now()->addHours((int) ($data['story_hours'] ?? 24)) : null;
        $promoterId = $user->is_promoter ? (int) $user->id : null;

        $postId = DB::transaction(function () use ($data, $body, $type, $mediaType, $mediaPath, $expiresAt, $event, $clientToken, $promoterId, $user, $appId) {
            $postId = DB::table('social_posts')->insertGetId([
                'app_id' => $appId,
                'user_id' => $user->id,
                'event_id' => $event?->id,
                'parent_id' => null,
                'client_token' => $clientToken,
                'body' => $body !== '' ? $body : null,
                'type' => $type,
                'media_type' => $mediaType,
                'media_path' => $mediaPath,
                'thumbnail_path' => $mediaType === 'image' ? $mediaPath : null,
                'location_name' => trim((string) ($data['location_name'] ?? '')) ?: null,
                'location_lat' => $data['location_lat'] ?? null,
                'location_lng' => $data['location_lng'] ?? null,
                'status' => 'published',
                'visibility' => 'public',
                'source' => 'timeline',
                'campaign' => trim((string) ($data['campaign'] ?? '')) ?: null,
                'promoter_id' => $promoterId,
                'is_pinned' => false,
                'is_promoted' => false,
                'promoted_until' => null,
                'expires_at' => $expiresAt,
                'metadata' => json_encode(['created_from' => 'timeline_composer'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'published_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('social_post_metrics')->insert([
                'app_id' => $appId, 'post_id' => $postId, 'created_at' => now(), 'updated_at' => now(),
            ]);

            if ($type === 'poll') {
                $pollId = DB::table('social_polls')->insertGetId([
                    'app_id' => $appId,
                    'post_id' => $postId,
                    'question' => trim((string) $data['poll_question']),
                    'allows_multiple' => (bool) ($data['poll_allows_multiple'] ?? false),
                    'ends_at' => $data['poll_ends_at'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                foreach (array_values(array_unique(array_map('trim', $data['poll_options'] ?? []))) as $order => $label) {
                    if ($label === '') continue;
                    DB::table('social_poll_options')->insert([
                        'poll_id' => $pollId, 'label' => $label, 'sort_order' => $order, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            return (int) $postId;
        });

        $this->broadcast('created', $postId);
        return response()->json(['post' => $this->singlePost($postId, (int) $user->id)], 201);
    }

    public function comment(Request $request, int $postId)
    {
        $data = $request->validate(['body' => 'required|string|min:1|max:1500', 'client_token' => 'nullable|string|min:8|max:64']);
        $user = $request->user();
        $appId = $this->context->id();
        $parent = $this->publishedPost($postId);
        abort_if($parent->parent_id, 422, 'Responda a publicação principal.');

        $clientToken = trim((string) ($data['client_token'] ?? '')) ?: null;
        if ($clientToken) {
            $existing = DB::table('social_posts')->where(['app_id' => $appId, 'user_id' => $user->id, 'client_token' => $clientToken])->first();
            if ($existing) return response()->json(['comment' => $existing, 'replayed' => true]);
        }

        $commentId = DB::table('social_posts')->insertGetId([
            'app_id' => $appId,
            'user_id' => $user->id,
            'event_id' => $parent->event_id,
            'parent_id' => $parent->id,
            'client_token' => $clientToken,
            'body' => trim($data['body']),
            'type' => 'text',
            'status' => 'published',
            'visibility' => 'public',
            'source' => 'timeline_comment',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->refreshMetric($postId, 'comments', 'social_posts', fn ($q) => $q->where('parent_id', $postId)->where('status', 'published'));

        if ((int) $parent->user_id !== (int) $user->id) {
            $this->safeNotify((int) $parent->user_id, [
                'type' => 'social_reply',
                'title' => 'Responderam sua publicação',
                'message' => (trim((string) $user->first_name) ?: 'Alguém').' respondeu sua publicação na timeline.',
                'reference_type' => 'social_post',
                'reference_id' => $postId,
                'reference_url' => '/feed?post='.$postId,
                'data' => ['post_id' => $postId, 'comment_id' => $commentId, 'actor_id' => $user->id],
            ]);
        }

        $this->broadcast('commented', $postId);
        return response()->json(['message' => 'Comentário publicado.', 'comment_id' => $commentId], 201);
    }

    public function react(Request $request, int $postId)
    {
        $data = $request->validate(['reaction' => 'nullable|in:like,love,fire,going']);
        $post = $this->publishedPost($postId);
        $user = $request->user();
        $appId = $this->context->id();
        $reaction = $data['reaction'] ?? 'like';
        $before = DB::table('social_post_reactions')->where(['app_id' => $appId, 'post_id' => $postId, 'user_id' => $user->id])->first();
        DB::table('social_post_reactions')->updateOrInsert(
            ['app_id' => $appId, 'post_id' => $postId, 'user_id' => $user->id],
            ['reaction' => $reaction, 'created_at' => $before?->created_at ?? now(), 'updated_at' => now()]
        );
        $this->refreshMetric($postId, 'likes', 'social_post_reactions', fn ($q) => $q->where('app_id', $appId)->where('post_id', $postId));
        if (! $before && (int) $post->user_id !== (int) $user->id) {
            $this->safeNotify((int) $post->user_id, [
                'type' => 'social_reaction', 'title' => 'Curtiram sua publicação',
                'message' => (trim((string) $user->first_name) ?: 'Alguém').' reagiu à sua publicação.',
                'reference_type' => 'social_post', 'reference_id' => $postId, 'reference_url' => '/feed?post='.$postId,
                'data' => ['post_id' => $postId, 'actor_id' => $user->id, 'reaction' => $reaction],
            ]);
        }
        $this->broadcast('reacted', $postId);
        return response()->json(['reaction' => $reaction, 'active' => true]);
    }

    public function unreact(Request $request, int $postId)
    {
        $this->publishedPost($postId);
        $appId = $this->context->id();
        DB::table('social_post_reactions')->where(['app_id' => $appId, 'post_id' => $postId, 'user_id' => $request->user()->id])->delete();
        $this->refreshMetric($postId, 'likes', 'social_post_reactions', fn ($q) => $q->where('app_id', $appId)->where('post_id', $postId));
        $this->broadcast('reacted', $postId);
        return response()->json(['active' => false]);
    }

    public function save(Request $request, int $postId)
    {
        $this->publishedPost($postId);
        $appId = $this->context->id();
        DB::table('social_post_saves')->updateOrInsert(
            ['app_id' => $appId, 'post_id' => $postId, 'user_id' => $request->user()->id],
            ['created_at' => now(), 'updated_at' => now()]
        );
        $this->refreshMetric($postId, 'saves', 'social_post_saves', fn ($q) => $q->where('app_id', $appId)->where('post_id', $postId));
        return response()->json(['saved' => true]);
    }

    public function unsave(Request $request, int $postId)
    {
        $this->publishedPost($postId);
        $appId = $this->context->id();
        DB::table('social_post_saves')->where(['app_id' => $appId, 'post_id' => $postId, 'user_id' => $request->user()->id])->delete();
        $this->refreshMetric($postId, 'saves', 'social_post_saves', fn ($q) => $q->where('app_id', $appId)->where('post_id', $postId));
        return response()->json(['saved' => false]);
    }

    public function share(Request $request, int $postId)
    {
        $data = $request->validate([
            'channel' => 'nullable|in:copy,whatsapp,instagram,facebook,x,other',
            'campaign' => 'nullable|string|max:120',
        ]);
        $post = $this->publishedPost($postId);
        $appId = $this->context->id();
        $user = $request->user();
        $promoterId = $user->is_promoter ? (int) $user->id : null;
        DB::table('social_post_shares')->insert([
            'app_id' => $appId, 'post_id' => $postId, 'user_id' => $user->id,
            'channel' => $data['channel'] ?? 'copy', 'source' => 'timeline',
            'campaign' => trim((string) ($data['campaign'] ?? '')) ?: null,
            'promoter_id' => $promoterId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->refreshMetric($postId, 'shares', 'social_post_shares', fn ($q) => $q->where('app_id', $appId)->where('post_id', $postId));
        $base = rtrim((string) ($this->context->application()->url ?: config('app.url')), '/');
        $url = $post->event_id
            ? $base.'/event/'.optional(Event::find($post->event_id))->slug.'?source=timeline&post_id='.$postId.($promoterId ? '&promoter_id='.$promoterId : '')
            : $base.'/feed?post='.$postId;
        return response()->json(['shared' => true, 'url' => $url]);
    }

    public function vote(Request $request, int $postId)
    {
        $data = $request->validate(['option_id' => 'required|integer|min:1']);
        $this->publishedPost($postId);
        $appId = $this->context->id();
        $poll = DB::table('social_polls')->where(['app_id' => $appId, 'post_id' => $postId])->first();
        abort_unless($poll, 404, 'Enquete não encontrada.');
        abort_if($poll->ends_at && now()->greaterThanOrEqualTo(Carbon::parse($poll->ends_at)), 422, 'Essa enquete já terminou.');
        $option = DB::table('social_poll_options')->where('poll_id', $poll->id)->where('id', (int) $data['option_id'])->first();
        abort_unless($option, 422, 'Opção inválida.');
        $userId = (int) $request->user()->id;
        if (! $poll->allows_multiple) {
            DB::table('social_poll_votes')->where(['app_id' => $appId, 'poll_id' => $poll->id, 'user_id' => $userId])->delete();
        }
        DB::table('social_poll_votes')->updateOrInsert(
            ['app_id' => $appId, 'poll_id' => $poll->id, 'option_id' => $option->id, 'user_id' => $userId],
            ['created_at' => now(), 'updated_at' => now()]
        );
        $this->broadcast('poll_voted', $postId);
        return response()->json(['poll' => $this->pollPayload((int) $poll->id, $userId)]);
    }

    public function report(Request $request, int $postId)
    {
        $data = $request->validate([
            'reason' => 'required|in:spam,harassment,hate,violence,fraud,sexual,illegal,copyright,misleading,other',
            'details' => 'nullable|string|max:3000',
        ]);
        $this->publishedPost($postId);
        $appId = $this->context->id();
        DB::table('social_post_reports')->updateOrInsert(
            ['app_id' => $appId, 'post_id' => $postId, 'user_id' => $request->user()->id],
            ['reason' => $data['reason'], 'details' => trim((string) ($data['details'] ?? '')) ?: null, 'status' => 'open', 'reviewed_by' => null, 'reviewed_at' => null, 'moderation_note' => null, 'created_at' => now(), 'updated_at' => now()]
        );
        return response()->json(['message' => 'Denúncia enviada para revisão.']);
    }

    public function track(Request $request, int $postId)
    {
        $data = $request->validate([
            'event_type' => 'required|in:impression,open,event_click,ticket_click',
            'session_key' => 'nullable|string|max:120',
        ]);
        $this->publishedPost($postId);
        $appId = $this->context->id();
        $userId = (int) $request->user()->id;
        $sessionKey = hash('sha256', trim((string) ($data['session_key'] ?? 'user:'.$userId)));
        $eventType = $data['event_type'];
        $dedupeMinutes = $eventType === 'impression' ? 15 : 2;
        $duplicate = DB::table('social_post_events')
            ->where(['app_id' => $appId, 'post_id' => $postId, 'user_id' => $userId, 'event_type' => $eventType, 'session_key' => $sessionKey])
            ->where('created_at', '>', now()->subMinutes($dedupeMinutes))->exists();
        if ($duplicate) return response()->json(['tracked' => false, 'deduplicated' => true]);

        DB::table('social_post_events')->insert([
            'app_id' => $appId, 'post_id' => $postId, 'user_id' => $userId, 'order_id' => null,
            'event_type' => $eventType, 'session_key' => $sessionKey, 'value_cents' => 0,
            'metadata' => null, 'created_at' => now(),
        ]);
        $column = ['impression' => 'impressions', 'open' => 'opens', 'event_click' => 'event_clicks', 'ticket_click' => 'ticket_clicks'][$eventType];
        DB::table('social_post_metrics')->where(['app_id' => $appId, 'post_id' => $postId])->increment($column, 1, ['updated_at' => now()]);
        return response()->json(['tracked' => true]);
    }

    public function destroy(Request $request, int $postId)
    {
        $post = DB::table('social_posts')->where('app_id', $this->context->id())->where('id', $postId)->first();
        abort_unless($post, 404, 'Publicação não encontrada.');
        $admin = $this->isAdmin($request->user());
        abort_unless($admin || (int) $post->user_id === (int) $request->user()->id, 403);
        DB::table('social_posts')->where('app_id', $this->context->id())->where(fn ($q) => $q->where('id', $postId)->orWhere('parent_id', $postId))->update(['status' => 'hidden', 'updated_at' => now()]);
        $this->broadcast('deleted', $postId);
        return response()->json(['message' => 'Publicação removida.']);
    }

    public function analytics(Request $request)
    {
        $appId = $this->context->id();
        $user = $request->user();
        $admin = $this->isAdmin($user);
        $query = DB::table('social_posts as p')
            ->leftJoin('social_post_metrics as m', function ($join) use ($appId) {
                $join->on('m.post_id', '=', 'p.id')->where('m.app_id', '=', $appId);
            })
            ->leftJoin('events as e', 'e.id', '=', 'p.event_id')
            ->leftJoin('productions as pr', 'pr.id', '=', 'e.production_id')
            ->where('p.app_id', $appId)->whereNull('p.parent_id')
            ->when(! $admin, fn ($q) => $q->where(fn ($owned) => $owned->where('p.user_id', $user->id)->orWhere('pr.user_id', $user->id)));

        $totals = (clone $query)->selectRaw('COUNT(DISTINCT p.id) posts, COALESCE(SUM(m.impressions),0) impressions, COALESCE(SUM(m.event_clicks),0) event_clicks, COALESCE(SUM(m.ticket_clicks),0) ticket_clicks, COALESCE(SUM(m.likes),0) likes, COALESCE(SUM(m.comments),0) comments, COALESCE(SUM(m.shares),0) shares, COALESCE(SUM(m.saves),0) saves, COALESCE(SUM(m.conversions),0) conversions, COALESCE(SUM(m.gmv_cents),0) gmv_cents, COALESCE(SUM(m.platform_revenue_cents),0) platform_revenue_cents')->first();
        $top = (clone $query)->select(['p.id', 'p.body', 'p.type', 'p.event_id', 'p.published_at', 'e.title as event_title', 'e.slug as event_slug', DB::raw('COALESCE(m.impressions,0) impressions'), DB::raw('COALESCE(m.event_clicks,0) event_clicks'), DB::raw('COALESCE(m.ticket_clicks,0) ticket_clicks'), DB::raw('COALESCE(m.conversions,0) conversions'), DB::raw('COALESCE(m.gmv_cents,0) gmv_cents'), DB::raw('COALESCE(m.platform_revenue_cents,0) platform_revenue_cents')])
            ->orderByDesc('m.gmv_cents')->orderByDesc('m.ticket_clicks')->limit(30)->get();

        $impressions = max(1, (int) ($totals->impressions ?? 0));
        return response()->json([
            'summary' => [
                'posts' => (int) ($totals->posts ?? 0),
                'impressions' => (int) ($totals->impressions ?? 0),
                'event_clicks' => (int) ($totals->event_clicks ?? 0),
                'ticket_clicks' => (int) ($totals->ticket_clicks ?? 0),
                'likes' => (int) ($totals->likes ?? 0),
                'comments' => (int) ($totals->comments ?? 0),
                'shares' => (int) ($totals->shares ?? 0),
                'saves' => (int) ($totals->saves ?? 0),
                'conversions' => (int) ($totals->conversions ?? 0),
                'gmv_cents' => (int) ($totals->gmv_cents ?? 0),
                'platform_revenue_cents' => (int) ($totals->platform_revenue_cents ?? 0),
                'event_click_rate' => round(((int) ($totals->event_clicks ?? 0) / $impressions) * 100, 2),
                'ticket_click_rate' => round(((int) ($totals->ticket_clicks ?? 0) / $impressions) * 100, 2),
                'conversion_rate' => round(((int) ($totals->conversions ?? 0) / $impressions) * 100, 2),
            ],
            'posts' => $top,
        ]);
    }

    public function requestBoost(Request $request, int $postId)
    {
        $data = $request->validate([
            'budget_cents' => 'required|integer|min:500|max:10000000',
            'target_city' => 'nullable|string|max:120',
            'target_radius_km' => 'nullable|integer|min:1|max:250',
            'starts_at' => 'nullable|date|after_or_equal:now',
            'ends_at' => 'required|date|after:starts_at',
        ]);
        $post = $this->publishedPost($postId);
        abort_unless((int) $post->user_id === (int) $request->user()->id || $this->isAdmin($request->user()), 403);
        $boostId = DB::table('social_post_boosts')->insertGetId([
            'app_id' => $this->context->id(), 'post_id' => $postId, 'user_id' => $request->user()->id,
            'budget_cents' => $data['budget_cents'], 'spent_cents' => 0, 'status' => 'requested',
            'target_city' => trim((string) ($data['target_city'] ?? '')) ?: null,
            'target_radius_km' => $data['target_radius_km'] ?? null,
            'starts_at' => $data['starts_at'] ?? now(), 'ends_at' => $data['ends_at'],
            'metadata' => json_encode(['billing_status' => 'pending'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['boost_id' => $boostId, 'status' => 'requested', 'message' => 'Impulsionamento criado e aguardando cobrança/ativação.'], 201);
    }

    public function moderationIndex(Request $request)
    {
        abort_unless($this->isAdmin($request->user()), 403);
        $appId = $this->context->id();
        $reports = DB::table('social_post_reports as r')
            ->join('social_posts as p', 'p.id', '=', 'r.post_id')
            ->join('users as reporter', 'reporter.id', '=', 'r.user_id')
            ->join('users as author', 'author.id', '=', 'p.user_id')
            ->where('r.app_id', $appId)->where('r.status', 'open')
            ->orderByDesc('r.created_at')->paginate(30, ['r.*', 'p.body as post_body', 'p.type as post_type', 'p.user_id as author_id', 'author.first_name as author_first_name', 'author.last_name as author_last_name', 'reporter.first_name as reporter_first_name', 'reporter.last_name as reporter_last_name']);
        return response()->json(['reports' => $reports]);
    }

    public function moderate(Request $request, int $reportId)
    {
        abort_unless($this->isAdmin($request->user()), 403);
        $data = $request->validate(['action' => 'required|in:dismiss,hide_post,suspend_post', 'note' => 'nullable|string|max:3000']);
        $appId = $this->context->id();
        $report = DB::table('social_post_reports')->where('app_id', $appId)->find($reportId);
        abort_unless($report, 404, 'Denúncia não encontrada.');
        if (in_array($data['action'], ['hide_post', 'suspend_post'], true)) {
            DB::table('social_posts')->where('app_id', $appId)->where('id', $report->post_id)->update(['status' => $data['action'] === 'hide_post' ? 'hidden' : 'suspended', 'updated_at' => now()]);
        }
        DB::table('social_post_reports')->where('app_id', $appId)->where('id', $reportId)->update([
            'status' => $data['action'] === 'dismiss' ? 'dismissed' : 'actioned',
            'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'moderation_note' => trim((string) ($data['note'] ?? '')) ?: null, 'updated_at' => now(),
        ]);
        $this->broadcast('moderated', (int) $report->post_id);
        return response()->json(['message' => 'Moderação aplicada.']);
    }

    private function enrichPosts(Collection $posts, array $postIds, int $userId, int $appId): void
    {
        if (empty($postIds)) return;
        $reactions = DB::table('social_post_reactions')->where('app_id', $appId)->where('user_id', $userId)->whereIn('post_id', $postIds)->pluck('reaction', 'post_id');
        $saved = DB::table('social_post_saves')->where('app_id', $appId)->where('user_id', $userId)->whereIn('post_id', $postIds)->pluck('post_id')->map(fn ($id) => (int) $id)->all();
        $polls = DB::table('social_polls')->where('app_id', $appId)->whereIn('post_id', $postIds)->get()->keyBy('post_id');
        $comments = DB::table('social_posts as c')->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.app_id', $appId)->whereIn('c.parent_id', $postIds)->where('c.status', 'published')
            ->orderByDesc('c.published_at')->limit(100)->get(['c.id', 'c.parent_id', 'c.body', 'c.published_at', 'u.id as user_id', 'u.first_name', 'u.last_name', 'u.avatar'])->groupBy('parent_id');
        foreach ($posts as $post) {
            $post->my_reaction = $reactions[$post->id] ?? null;
            $post->is_saved = in_array((int) $post->id, $saved, true);
            $poll = $polls->get($post->id);
            $post->poll = $poll ? $this->pollPayload((int) $poll->id, $userId) : null;
            $post->comments_preview = collect($comments->get($post->id, []))->take(2)->values();
        }
    }

    private function singlePost(int $postId, int $userId): ?array
    {
        $appId = $this->context->id();
        $rows = DB::table('social_posts as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->leftJoin('profiles as pf', 'pf.id', '=', 'u.profile_id')
            ->leftJoin('events as e', 'e.id', '=', 'p.event_id')
            ->leftJoin('productions as pr', 'pr.id', '=', 'e.production_id')
            ->leftJoin('social_post_metrics as m', function ($join) use ($appId) { $join->on('m.post_id', '=', 'p.id')->where('m.app_id', '=', $appId); })
            ->where('p.app_id', $appId)->where('p.id', $postId)
            ->get(['p.*', 'u.first_name', 'u.last_name', 'u.user_name', 'u.avatar', 'u.is_producer', 'u.is_promoter', 'pf.name as profile_name', 'e.title as event_title', 'e.slug as event_slug', 'e.image as event_image', 'e.start_date as event_start_date', 'e.city as event_city', 'e.production_id', 'pr.name as production_name', DB::raw('COALESCE(m.impressions,0) impressions'), DB::raw('COALESCE(m.event_clicks,0) event_clicks'), DB::raw('COALESCE(m.ticket_clicks,0) ticket_clicks'), DB::raw('COALESCE(m.likes,0) likes_count'), DB::raw('COALESCE(m.comments,0) comments_count'), DB::raw('COALESCE(m.shares,0) shares_count'), DB::raw('COALESCE(m.saves,0) saves_count'), DB::raw('COALESCE(m.conversions,0) conversions'), DB::raw('COALESCE(m.gmv_cents,0) gmv_cents'), DB::raw('COALESCE(m.platform_revenue_cents,0) platform_revenue_cents')]);
        if ($rows->isEmpty()) return null;
        $this->enrichPosts($rows, [$postId], $userId, $appId);
        return $this->postItem($rows->first(), ['preferred_city' => null, 'followed_productions' => [], 'engaged_events' => [], 'ticket_events' => [], 'artist_follow_events' => []]);
    }

    private function postItem(object $post, array $context): array
    {
        $publishedAt = Carbon::parse($post->published_at ?: $post->created_at);
        $score = $this->freshnessScore($publishedAt);
        if ($post->is_promoted && (! $post->promoted_until || now()->lt(Carbon::parse($post->promoted_until)))) $score += 220;
        if ($post->is_pinned) $score += 90;
        $eventId = (int) ($post->event_id ?? 0);
        if ($eventId && in_array($eventId, $context['ticket_events'] ?? [], true)) $score += 85;
        elseif ($eventId && in_array($eventId, $context['engaged_events'] ?? [], true)) $score += 48;
        if ((int) ($post->production_id ?? 0) && in_array((int) $post->production_id, $context['followed_productions'] ?? [], true)) $score += 34;
        if (($context['preferred_city'] ?? null) && $post->event_city && mb_strtolower((string) $post->event_city) === mb_strtolower((string) $context['preferred_city'])) $score += 24;
        $score += min(42, log(1 + (int) $post->likes_count) * 7 + log(1 + (int) $post->comments_count) * 9 + log(1 + (int) $post->shares_count) * 10 + log(1 + (int) $post->ticket_clicks) * 12);
        if (in_array($post->type, ['image', 'video', 'poll'], true)) $score += 8;

        $badges = [];
        if ($post->is_producer) $badges[] = 'Produtor';
        if ($post->is_promoter) $badges[] = 'Promoter';
        if ($post->profile_name && ! in_array($post->profile_name, $badges, true)) $badges[] = $post->profile_name;

        return [
            'kind' => 'post', 'id' => (int) $post->id, 'sort_at' => $publishedAt->toIso8601String(), 'score' => round($score, 3),
            'source_key' => 'user:'.(int) $post->user_id,
            'post' => [
                'id' => (int) $post->id, 'body' => $post->body, 'type' => $post->type, 'media_type' => $post->media_type,
                'media_path' => $post->media_path, 'thumbnail_path' => $post->thumbnail_path, 'location_name' => $post->location_name,
                'location_lat' => $post->location_lat !== null ? (float) $post->location_lat : null, 'location_lng' => $post->location_lng !== null ? (float) $post->location_lng : null,
                'published_at' => $publishedAt->toIso8601String(), 'expires_at' => $post->expires_at, 'is_promoted' => (bool) $post->is_promoted,
                'campaign' => $post->campaign, 'promoter_id' => $post->promoter_id ? (int) $post->promoter_id : null,
                'author' => ['id' => (int) $post->user_id, 'name' => trim(($post->first_name ?? '').' '.($post->last_name ?? '')) ?: 'Participante Cutinapp', 'user_name' => $post->user_name, 'avatar' => $post->avatar, 'badges' => $badges],
                'event' => $eventId ? ['id' => $eventId, 'title' => $post->event_title, 'slug' => $post->event_slug, 'image' => $post->event_image, 'start_date' => $post->event_start_date, 'city' => $post->event_city, 'production_id' => $post->production_id ? (int) $post->production_id : null, 'production_name' => $post->production_name] : null,
                'metrics' => ['impressions' => (int) $post->impressions, 'event_clicks' => (int) $post->event_clicks, 'ticket_clicks' => (int) $post->ticket_clicks, 'likes' => (int) $post->likes_count, 'comments' => (int) $post->comments_count, 'shares' => (int) $post->shares_count, 'saves' => (int) $post->saves_count, 'conversions' => (int) $post->conversions, 'gmv_cents' => (int) $post->gmv_cents, 'platform_revenue_cents' => (int) $post->platform_revenue_cents],
                'my_reaction' => $post->my_reaction ?? null, 'is_saved' => (bool) ($post->is_saved ?? false), 'poll' => $post->poll ?? null, 'comments_preview' => $post->comments_preview ?? [],
            ],
        ];
    }

    private function eventItem(object $event, array $context): array
    {
        $createdAt = Carbon::parse($event->created_at);
        $eventId = (int) $event->id;
        $score = $this->freshnessScore($createdAt) + min(50, log(1 + (int) $event->engagement_count) * 8 + log(1 + (int) $event->passes_count) * 12);
        $reason = 'discovery';
        if (in_array($eventId, $context['ticket_events'] ?? [], true)) { $score += 95; $reason = 'ticket'; }
        elseif (in_array($eventId, $context['engaged_events'] ?? [], true)) { $score += 55; $reason = 'interest'; }
        elseif (in_array((int) $event->production_id, $context['followed_productions'] ?? [], true)) { $score += 40; $reason = 'production_follow'; }
        elseif (in_array($eventId, $context['artist_follow_events'] ?? [], true)) { $score += 38; $reason = 'artist_follow'; }
        elseif (($context['preferred_city'] ?? null) && mb_strtolower((string) $event->city) === mb_strtolower((string) $context['preferred_city'])) { $score += 28; $reason = 'preferred_city'; }
        if ($event->is_featured) $score += 32;
        $startsAt = $event->start_date ? Carbon::parse($event->start_date) : null;
        if ($startsAt && $startsAt->isFuture() && now()->diffInHours($startsAt, false) <= 72) $score += 18;

        return [
            'kind' => 'event', 'id' => $eventId, 'sort_at' => $createdAt->toIso8601String(), 'score' => round($score, 3), 'source_key' => 'production:'.(int) $event->production_id,
            'event' => [
                'id' => $eventId, 'title' => $event->title, 'slug' => $event->slug, 'description' => $event->description, 'category' => $event->category, 'image' => $event->image,
                'start_date' => $event->start_date, 'end_date' => $event->end_date, 'city' => $event->city, 'uf' => $event->uf, 'venue' => $event->venue,
                'production_id' => (int) $event->production_id, 'production_name' => $event->production_name, 'production_slug' => $event->production_slug, 'production_logo' => $event->production_logo,
                'price_from' => $event->price_from !== null ? (float) $event->price_from : null, 'engagement_count' => (int) $event->engagement_count, 'passes_count' => (int) $event->passes_count,
                'feed_reason' => $reason, 'is_featured' => (bool) $event->is_featured,
            ],
        ];
    }

    private function diversify(Collection $candidates, int $limit): Collection
    {
        $sorted = $candidates->sortByDesc(fn ($item) => [$item['score'], $item['sort_at']])->values();
        $result = collect(); $sourceCounts = []; $deferred = collect(); $lastKind = null; $kindStreak = 0;
        foreach ($sorted as $item) {
            $source = $item['source_key']; $kind = $item['kind'];
            if (($sourceCounts[$source] ?? 0) >= 2 || ($kind === $lastKind && $kindStreak >= 3)) { $deferred->push($item); continue; }
            $result->push($item); $sourceCounts[$source] = ($sourceCounts[$source] ?? 0) + 1;
            if ($kind === $lastKind) $kindStreak++; else { $lastKind = $kind; $kindStreak = 1; }
            if ($result->count() >= $limit) return $result;
        }
        foreach ($deferred as $item) { $result->push($item); if ($result->count() >= $limit) break; }
        return $result;
    }

    private function stories(int $appId, int $userId): Collection
    {
        return DB::table('social_posts as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.app_id', $appId)->whereNull('p.parent_id')->where('p.type', 'story')->where('p.status', 'published')->where('p.visibility', 'public')
            ->where('p.expires_at', '>', now())->orderByDesc('p.published_at')->limit(20)
            ->get(['p.id', 'p.user_id', 'p.body', 'p.media_type', 'p.media_path', 'p.published_at', 'p.expires_at', 'u.first_name', 'u.last_name', 'u.avatar'])
            ->unique('user_id')->take(12)->values();
    }

    private function trending(int $appId): Collection
    {
        return DB::table('events as e')->leftJoin('productions as pr', 'pr.id', '=', 'e.production_id')
            ->where('e.app_id', $appId)->where('e.is_published', true)->where('e.is_cancelled', false)
            ->where(fn ($q) => $q->where('e.is_private', false)->orWhereNull('e.is_private'))
            ->where(fn ($q) => $q->whereNull('e.end_date')->orWhere('e.end_date', '>', now()))
            ->select(['e.id', 'e.title', 'e.slug', 'e.image', 'e.start_date', 'e.city', 'e.production_id', 'pr.name as production_name'])
            ->selectSub(fn ($q) => $q->from('event_engagements as eg')->selectRaw('COUNT(*)')->whereColumn('eg.event_id', 'e.id')->where('eg.app_id', $appId), 'engagement_count')
            ->selectSub(fn ($q) => $q->from('event_passes as ep')->selectRaw('COUNT(*)')->whereColumn('ep.event_id', 'e.id')->whereNotIn('ep.status', ['cancelled', 'refunded', 'charged_back']), 'passes_count')
            ->orderByDesc('passes_count')->orderByDesc('engagement_count')->orderBy('e.start_date')->limit(6)->get();
    }

    private function pollPayload(int $pollId, int $userId): array
    {
        $poll = DB::table('social_polls')->find($pollId); abort_unless($poll, 404);
        $myVotes = DB::table('social_poll_votes')->where('poll_id', $pollId)->where('user_id', $userId)->pluck('option_id')->map(fn ($id) => (int) $id)->all();
        $options = DB::table('social_poll_options as o')->where('o.poll_id', $pollId)
            ->select(['o.id', 'o.label', 'o.sort_order'])->selectSub(fn ($q) => $q->from('social_poll_votes as v')->selectRaw('COUNT(*)')->whereColumn('v.option_id', 'o.id'), 'votes_count')
            ->orderBy('o.sort_order')->get()->map(fn ($option) => ['id' => (int) $option->id, 'label' => $option->label, 'votes_count' => (int) $option->votes_count, 'selected' => in_array((int) $option->id, $myVotes, true)]);
        return ['id' => (int) $poll->id, 'question' => $poll->question, 'allows_multiple' => (bool) $poll->allows_multiple, 'ends_at' => $poll->ends_at, 'ended' => $poll->ends_at ? now()->greaterThanOrEqualTo(Carbon::parse($poll->ends_at)) : false, 'options' => $options, 'total_votes' => $options->sum('votes_count')];
    }

    private function publishedPost(int $postId): object
    {
        $post = DB::table('social_posts')->where('app_id', $this->context->id())->where('id', $postId)->where('status', 'published')->first();
        abort_unless($post, 404, 'Publicação não encontrada.');
        return $post;
    }

    private function refreshMetric(int $postId, string $column, string $table, callable $scope): void
    {
        $appId = $this->context->id();
        $query = DB::table($table); $scope($query); $count = $query->count();
        DB::table('social_post_metrics')->updateOrInsert(['app_id' => $appId, 'post_id' => $postId], ['created_at' => now(), 'updated_at' => now()]);
        DB::table('social_post_metrics')->where(['app_id' => $appId, 'post_id' => $postId])->update([$column => $count, 'updated_at' => now()]);
    }

    private function freshnessScore(Carbon $at): float
    {
        $hours = max(0, $at->diffInMinutes(now()) / 60);
        return max(2, 100 / (1 + ($hours / 18)));
    }

    private function encodeCursor(string $iso): string
    {
        return rtrim(strtr(base64_encode($iso), '+/', '-_'), '=');
    }

    private function decodeCursor(?string $cursor): ?Carbon
    {
        if (! $cursor) return null;
        try {
            $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
            return $decoded ? Carbon::parse($decoded) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function isAdmin(User $user): bool
    {
        return $user->hasProfile('Administrador') || strtolower((string) $user->email) === 'petertecnet@gmail.com';
    }

    private function safeNotify(int $userId, array $payload): void
    {
        try { $this->notifications->sendToUser($this->context->id(), $userId, $payload); } catch (Throwable $e) { report($e); }
    }

    private function broadcast(string $action, int $postId): void
    {
        try { broadcast(new SocialTimelineChanged($this->context->id(), $action, $postId))->toOthers(); } catch (Throwable $e) { report($e); }
    }
}
