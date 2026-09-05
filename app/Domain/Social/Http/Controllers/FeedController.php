<?php

namespace App\Domain\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Event;
use App\Models\EventPass;
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
        $data = $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:6|max:40',
        ]);
        $perPage = (int) ($data['per_page'] ?? 12);

        $followedOrganizations = DB::table('follows')
            ->where([
                'app_id' => $appId,
                'user_id' => $user->id,
                'target_type' => 'production',
            ])
            ->pluck('target_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $followedArtists = DB::table('follows')
            ->where([
                'app_id' => $appId,
                'user_id' => $user->id,
                'target_type' => 'artist',
            ])
            ->pluck('target_id')
            ->map(fn ($id) => (int) $id)
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

        $ticketEvents = EventPass::where('user_id', $user->id)
            ->whereHas('event', fn ($query) => $query->where('app_id', $appId))
            ->pluck('event_id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->all();

        $preference = DB::table('application_user_preferences')
            ->where([
                'app_id' => $appId,
                'user_id' => $user->id,
            ])
            ->first();

        // The main Cutinapp feed behaves as a real timeline: every eligible event
        // is included and the newest creation appears first. Personalization still
        // explains why an event is relevant through feed_reason, but it no longer
        // hides a newly created event below older events with an earlier start date.
        $feed = Event::query()
            ->where('app_id', $appId)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where('is_private', false)
            ->where('end_date', '>', now())
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

        $relevant = collect($feed->getCollection()->pluck('id'))
            ->merge($engaged)
            ->merge($ticketEvents)
            ->unique()
            ->values()
            ->take(100)
            ->all();

        $community = $relevant === []
            ? collect()
            : DB::table('event_posts as p')
                ->join('events as e', 'e.id', '=', 'p.event_id')
                ->join('users as u', 'u.id', '=', 'p.user_id')
                ->where('p.app_id', $appId)
                ->whereNull('p.parent_id')
                ->where('p.status', 'published')
                ->whereIn('p.event_id', $relevant)
                ->where('e.app_id', $appId)
                ->where('e.is_published', true)
                ->where('e.is_cancelled', false)
                ->where('e.is_private', false)
                ->orderByDesc('p.created_at')
                ->limit(12)
                ->get([
                    'p.id',
                    'p.event_id',
                    'p.body',
                    'p.created_at',
                    'p.is_pinned',
                    'e.title as event_title',
                    'e.slug as event_slug',
                    'u.id as user_id',
                    'u.first_name',
                    'u.last_name',
                    'u.avatar',
                ]);

        $notifications = AppNotification::where('app_id', $appId)
            ->where('user_id', $user->id)
            ->latest()
            ->limit(12)
            ->get();

        return response()->json([
            'feed' => $feed,
            'community_activity' => $community,
            'notifications' => $notifications,
            'unread_count' => AppNotification::where('app_id', $appId)
                ->where('user_id', $user->id)
                ->whereNull('read_at')
                ->count(),
            'context' => [
                'preferred_city' => $preference?->preferred_city,
                'preferred_uf' => $preference?->preferred_uf,
                'following_organizations' => count($followedOrganizations),
                'following_artists' => count($followedArtists),
            ],
        ]);
    }
}
