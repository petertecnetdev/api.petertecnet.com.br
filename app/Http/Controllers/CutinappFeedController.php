<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\AppNotification;
use App\Models\Event;
use App\Models\EventPass;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappFeedController extends Controller
{
    private const APP = 'cutinapp';

    public function index(Request $request)
    {
        $user = $this->requestUser($request);
        $appId = $this->applicationId();
        $data = $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:6|max:40',
        ]);
        $perPage = (int) ($data['per_page'] ?? 12);

        $followedProductions = DB::table('cutinapp_follows')
            ->where(['app_id' => $appId, 'user_id' => $user->id, 'target_type' => 'production'])
            ->pluck('target_id')->map(fn ($id) => (int) $id)->all();
        $followedArtists = DB::table('cutinapp_follows')
            ->where(['app_id' => $appId, 'user_id' => $user->id, 'target_type' => 'artist'])
            ->pluck('target_id')->map(fn ($id) => (int) $id)->all();
        $engagedEventIds = DB::table('cutinapp_event_engagements')
            ->where('app_id', $appId)->where('user_id', $user->id)
            ->where(fn ($q) => $q->where('is_favorite', true)->orWhere('is_interested', true))
            ->pluck('event_id')->map(fn ($id) => (int) $id)->all();
        $ticketEventIds = EventPass::query()
            ->where('user_id', $user->id)
            ->whereHas('event', fn ($q) => $q->where('app_id', $appId)->where('app_slug', self::APP))
            ->pluck('event_id')->unique()->map(fn ($id) => (int) $id)->all();

        $preference = DB::table('cutinapp_user_preferences')
            ->where(['app_id' => $appId, 'user_id' => $user->id])
            ->first();

        $query = Event::query()
            ->where('app_id', $appId)
            ->where('app_slug', self::APP)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where('is_private', false)
            ->where('end_date', '>', now())
            ->with([
                'production:id,app_id,user_id,name,slug,logo',
                'artists:id,app_id,slug,stage_name,photo',
            ])
            ->withCount([
                'tickets as ticket_batches_count' => fn ($q) => $q->where('app_id', $appId)->where('app_slug', self::APP),
            ]);

        if ($preference?->preferred_city) {
            $query->orderByRaw(
                'CASE WHEN LOWER(city) = LOWER(?) AND (? IS NULL OR uf = ?) THEN 0 ELSE 1 END',
                [$preference->preferred_city, $preference->preferred_uf, $preference->preferred_uf]
            );
        }

        if ($followedProductions !== []) {
            $ids = implode(',', array_map('intval', $followedProductions));
            $query->orderByRaw("CASE WHEN production_id IN ({$ids}) THEN 0 ELSE 1 END");
        }

        $feed = $query->orderBy('start_date')->paginate($perPage);

        $feed->getCollection()->transform(function (Event $event) use ($followedProductions, $followedArtists, $engagedEventIds, $ticketEventIds, $preference) {
            $artistIds = $event->artists->pluck('id')->map(fn ($id) => (int) $id)->all();
            $reason = 'discovery';
            if (in_array((int) $event->id, $ticketEventIds, true)) $reason = 'ticket';
            elseif (in_array((int) $event->id, $engagedEventIds, true)) $reason = 'interest';
            elseif (in_array((int) $event->production_id, $followedProductions, true)) $reason = 'production_follow';
            elseif (array_intersect($artistIds, $followedArtists) !== []) $reason = 'artist_follow';
            elseif ($preference?->preferred_city && mb_strtolower((string) $event->city) === mb_strtolower((string) $preference->preferred_city)) $reason = 'preferred_city';
            $event->setAttribute('feed_reason', $reason);
            return $event;
        });

        $relevantEventIds = collect($feed->getCollection()->pluck('id'))
            ->merge($engagedEventIds)
            ->merge($ticketEventIds)
            ->unique()->values()->take(100)->all();

        $communityActivity = collect();
        if ($relevantEventIds !== []) {
            $communityActivity = DB::table('cutinapp_event_posts as p')
                ->join('events as e', 'e.id', '=', 'p.event_id')
                ->join('users as u', 'u.id', '=', 'p.user_id')
                ->where('p.app_id', $appId)
                ->whereNull('p.parent_id')
                ->where('p.status', 'published')
                ->whereIn('p.event_id', $relevantEventIds)
                ->where('e.app_id', $appId)
                ->where('e.app_slug', self::APP)
                ->where('e.is_published', true)
                ->where('e.is_cancelled', false)
                ->where('e.is_private', false)
                ->orderByDesc('p.created_at')
                ->limit(12)
                ->get([
                    'p.id', 'p.event_id', 'p.body', 'p.created_at', 'p.is_pinned',
                    'e.title as event_title', 'e.slug as event_slug',
                    'u.id as user_id', 'u.first_name', 'u.last_name', 'u.avatar',
                ]);
        }

        $notifications = AppNotification::query()
            ->where('app_id', $appId)
            ->where('user_id', $user->id)
            ->latest()
            ->limit(12)
            ->get();

        return response()->json([
            'feed' => $feed,
            'community_activity' => $communityActivity,
            'notifications' => $notifications,
            'unread_count' => $notifications->whereNull('read_at')->count() + AppNotification::query()
                ->where('app_id', $appId)->where('user_id', $user->id)->whereNull('read_at')
                ->whereNotIn('id', $notifications->pluck('id'))->count(),
            'context' => [
                'preferred_city' => $preference?->preferred_city,
                'preferred_uf' => $preference?->preferred_uf,
                'following_productions' => count($followedProductions),
                'following_artists' => count($followedArtists),
            ],
        ]);
    }

    private function applicationId(): int
    {
        $id = Application::query()->where('slug', self::APP)->where('is_active', true)->value('id');
        abort_unless($id, 503, 'A Cutinapp não está registrada corretamente na API.');
        return (int) $id;
    }

    private function requestUser(Request $request): User
    {
        $token = trim((string) $request->bearerToken());
        abort_if($token === '', 401, 'Sessão inválida ou expirada. Faça login novamente.');
        try { $user = JWTAuth::setToken($token)->authenticate(); } catch (\Throwable) { $user = null; }
        abort_unless($user instanceof User, 401, 'Sessão inválida ou expirada. Faça login novamente.');
        return $user;
    }
}
