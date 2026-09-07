<?php

namespace App\Domain\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class FeedController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

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

        // One social-graph query replaces separate production/artist queries.
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

        // Direct join avoids hydrating EventPass models and a whereHas subquery.
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
            ->orderByDesc('p.created_at')
            ->limit(12)
            ->get([
                'p.id', 'p.event_id', 'p.body', 'p.created_at', 'p.is_pinned',
                'e.title as event_title', 'e.slug as event_slug',
                'u.id as user_id', 'u.first_name', 'u.last_name', 'u.avatar',
            ]);

        // Notification data has its own endpoint and navbar lifecycle. Avoid two
        // extra notification queries on every feed request while preserving shape.
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
