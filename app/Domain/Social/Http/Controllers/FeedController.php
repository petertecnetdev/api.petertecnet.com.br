<?php

namespace App\Domain\Social\Http\Controllers;

use App\Domain\Commerce\Services\TicketInventoryService;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Ticket;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class FeedController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly TicketInventoryService $ticketInventory,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $appId = $this->context->id();
        $now = now();
        $data = $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:6|max:40',
        ]);
        $perPage = (int) ($data['per_page'] ?? 12);

        $follows = DB::table('follows')
            ->where('app_id', $appId)
            ->where('user_id', $user->id)
            ->whereIn('target_type', ['production', 'artist'])
            ->get(['target_type', 'target_id']);

        $followedOrganizations = $follows
            ->where('target_type', 'production')
            ->pluck('target_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
        $followedArtists = $follows
            ->where('target_type', 'artist')
            ->pluck('target_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $engaged = DB::table('event_engagements')
            ->where('app_id', $appId)
            ->where('user_id', $user->id)
            ->where(fn ($query) => $query
                ->where('is_favorite', true)
                ->orWhere('is_interested', true))
            ->pluck('event_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $ticketEvents = DB::table('event_passes as ep')
            ->join('events as te', 'te.id', '=', 'ep.event_id')
            ->where('ep.user_id', $user->id)
            ->where('te.app_id', $appId)
            ->distinct()
            ->pluck('ep.event_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $preference = DB::table('application_user_preferences')
            ->where([
                'app_id' => $appId,
                'user_id' => $user->id,
            ])
            ->first();

        $feed = Event::query()
            ->where('app_id', $appId)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where(fn ($query) => $query
                ->where('is_private', false)
                ->orWhereNull('is_private'))
            ->where(fn ($query) => $query
                ->whereNull('end_date')
                ->orWhere('end_date', '>', $now))
            ->with([
                'production:id,app_id,user_id,name,slug,logo',
                'artists:id,app_id,slug,stage_name,photo',
            ])
            ->withCount([
                'tickets as ticket_batches_count' => fn ($query) => $query->where('app_id', $appId),
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $feed->getCollection()->transform(function (Event $event) use (
            $followedOrganizations,
            $followedArtists,
            $engaged,
            $ticketEvents,
            $preference
        ) {
            $artistIds = $event->artists
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $reason = 'discovery';
            if (in_array((int) $event->id, $ticketEvents, true)) {
                $reason = 'ticket';
            } elseif (in_array((int) $event->id, $engaged, true)) {
                $reason = 'interest';
            } elseif (in_array((int) $event->production_id, $followedOrganizations, true)) {
                $reason = 'organization_follow';
            } elseif (array_intersect($artistIds, $followedArtists) !== []) {
                $reason = 'artist_follow';
            } elseif (
                $preference?->preferred_city
                && mb_strtolower((string) $event->city) === mb_strtolower((string) $preference->preferred_city)
            ) {
                $reason = 'preferred_city';
            }

            $event->setAttribute('feed_reason', $reason);
            return $event;
        });

        $community = DB::table('event_posts as p')
            ->leftJoin('events as e', 'e.id', '=', 'p.event_id')
            ->leftJoin('productions as pr', 'pr.id', '=', 'e.production_id')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.app_id', $appId)
            ->whereNull('p.parent_id')
            ->where('p.status', 'published')
            ->where(function ($query) use ($appId, $now) {
                $query->whereNull('p.event_id')
                    ->orWhere(function ($eventQuery) use ($appId, $now) {
                        $eventQuery->where('e.app_id', $appId)
                            ->where('e.is_published', true)
                            ->where('e.is_cancelled', false)
                            ->where(fn ($privacyQuery) => $privacyQuery
                                ->where('e.is_private', false)
                                ->orWhereNull('e.is_private'))
                            ->where(fn ($dateQuery) => $dateQuery
                                ->whereNull('e.end_date')
                                ->orWhere('e.end_date', '>', $now));
                    });
            })
            ->select([
                'p.id', 'p.event_id', 'p.body', 'p.created_at', 'p.is_pinned',
                'e.title as event_title', 'e.slug as event_slug',
                'pr.id as production_id', 'pr.name as production_name', 'pr.slug as production_slug',
                'u.id as user_id', 'u.first_name', 'u.last_name', 'u.avatar',
            ])
            ->selectSub(fn ($q) => $q->from('event_post_likes as l')
                ->selectRaw('COUNT(*)')
                ->whereColumn('l.post_id', 'p.id')
                ->where('l.app_id', $appId), 'likes_count')
            ->selectSub(fn ($q) => $q->from('event_posts as r')
                ->selectRaw('COUNT(*)')
                ->whereColumn('r.parent_id', 'p.id')
                ->where('r.status', 'published'), 'comments_count')
            ->orderByDesc('p.created_at')
            ->limit(24)
            ->get();

        $postIds = $community->pluck('id')->filter()->values();
        $replies = $postIds->isEmpty() ? collect() : DB::table('event_posts as p')
            ->leftJoin('events as e', 'e.id', '=', 'p.event_id')
            ->leftJoin('productions as pr', 'pr.id', '=', 'e.production_id')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.app_id', $appId)
            ->whereIn('p.parent_id', $postIds)
            ->where('p.status', 'published')
            ->select([
                'p.id', 'p.parent_id', 'p.event_id', 'p.body', 'p.created_at',
                'e.title as event_title', 'e.slug as event_slug',
                'pr.id as production_id', 'pr.name as production_name', 'pr.slug as production_slug',
                'u.id as user_id', 'u.first_name', 'u.last_name', 'u.avatar',
            ])
            ->selectSub(fn ($q) => $q->from('event_post_likes as l')
                ->selectRaw('COUNT(*)')
                ->whereColumn('l.post_id', 'p.id')
                ->where('l.app_id', $appId), 'likes_count')
            ->orderBy('p.created_at')
            ->get()
            ->groupBy('parent_id');

        $communityEventIds = $community->pluck('event_id')
            ->merge($replies->flatten(1)->pluck('event_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $communityTicketAvailability = $communityEventIds->isEmpty()
            ? collect()
            : $this->ticketInventory->availabilityByEvent(
                Ticket::query()
                    ->where('app_id', $appId)
                    ->whereIn('event_id', $communityEventIds)
                    ->get(['id', 'event_id', 'price', 'quantity', 'limit_date']),
                $now
            );

        $allPostIds = $postIds->merge($replies->flatten(1)->pluck('id'))->filter()->values();
        $liked = $allPostIds->isEmpty() ? collect() : DB::table('event_post_likes')
            ->where('app_id', $appId)
            ->where('user_id', $user->id)
            ->whereIn('post_id', $allPostIds)
            ->pluck('post_id');

        $decoratePostAvailability = static function ($post) use ($communityTicketAvailability) {
            if (! $post->event_id) {
                return $post;
            }

            $availability = $communityTicketAvailability->get((int) $post->event_id, [
                'status' => 'tickets_pending',
                'sellable_lots_count' => 0,
                'sellable_free_lots_count' => 0,
            ]);
            $post->ticket_availability_status = $availability['status'];
            $post->sellable_ticket_lots_count = (int) $availability['sellable_lots_count'];
            $post->sellable_free_ticket_lots_count = (int) $availability['sellable_free_lots_count'];

            return $post;
        };

        $community = $community->map(function ($post) use ($replies, $liked, $decoratePostAvailability) {
            $post->is_liked = $liked->contains($post->id);
            $decoratePostAvailability($post);
            $post->replies = collect($replies->get($post->id, []))->map(function ($reply) use ($liked, $decoratePostAvailability) {
                $reply->is_liked = $liked->contains($reply->id);
                $reply->comments_count = 0;
                $reply->replies = [];
                return $decoratePostAvailability($reply);
            })->values();
            return $post;
        })->values();

        return response()->json([
            'feed' => $feed,
            'community_activity' => $community,
            'notifications' => [],
            'unread_count' => null,
            'context' => [
                'preferred_city' => $preference?->preferred_city,
                'preferred_uf' => $preference?->preferred_uf,
                'following_organizations' => count($followedOrganizations),
                'following_artists' => count($followedArtists),
            ],
        ]);
    }
}
