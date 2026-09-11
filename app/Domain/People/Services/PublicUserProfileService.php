<?php

namespace App\Domain\People\Services;

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PublicUserProfileService
{
    public function __construct(
        private readonly UserActorIdentityService $actorIdentity,
    ) {}

    public function show(int $userId, int $appId, ?int $viewerId = null): array
    {
        $user = User::query()
            ->select([
                'id', 'first_name', 'last_name', 'user_name', 'avatar', 'background',
                'city', 'uf', 'about', 'favorite_artist', 'favorite_genre', 'created_at',
                'is_producer', 'is_participant', 'is_promoter', 'is_partner', 'is_ticket_seller',
            ])
            ->findOrFail($userId);

        $social = Schema::hasTable('user_social_preferences')
            ? DB::table('user_social_preferences')->where(['app_id' => $appId, 'user_id' => $user->id])->first()
            : null;

        $settings = [
            'discoverable' => $social ? (bool) $social->discoverable : true,
            'show_city' => $social ? (bool) $social->show_city : true,
            'show_interests' => $social ? (bool) $social->show_interests : true,
            'show_event_interests' => $social ? (bool) $social->show_event_interests : true,
            'allow_follows' => $social ? (bool) $social->allow_follows : true,
            'show_followers' => $social && property_exists($social, 'show_followers') ? (bool) $social->show_followers : true,
            'show_following' => $social && property_exists($social, 'show_following') ? (bool) $social->show_following : true,
            'show_activity' => $social && property_exists($social, 'show_activity') ? (bool) $social->show_activity : false,
        ];

        abort_unless($settings['discoverable'], 404, 'Este perfil não está disponível.');

        $interests = $settings['show_interests'] ? $this->interests($appId, $user->id) : [];
        $interestedEvents = $settings['show_event_interests'] ? $this->interestedEvents($appId, $user->id) : collect();

        $followersQuery = DB::table('follows')
            ->where(['app_id' => $appId, 'target_type' => 'user', 'target_id' => $user->id]);
        $followingQuery = DB::table('follows')
            ->where(['app_id' => $appId, 'user_id' => $user->id, 'target_type' => 'user']);

        $followerIds = (clone $followersQuery)->pluck('user_id')->map(fn ($id) => (int) $id)->values();
        $followingIds = (clone $followingQuery)->pluck('target_id')->map(fn ($id) => (int) $id)->values();

        $relationship = [
            'following' => false,
            'followed_by' => false,
            'mutual' => false,
        ];
        $mutualIds = collect();
        $commonEvents = collect();

        if ($viewerId && $viewerId !== (int) $user->id) {
            $relationship['following'] = DB::table('follows')->where([
                'app_id' => $appId, 'user_id' => $viewerId, 'target_type' => 'user', 'target_id' => $user->id,
            ])->exists();
            $relationship['followed_by'] = DB::table('follows')->where([
                'app_id' => $appId, 'user_id' => $user->id, 'target_type' => 'user', 'target_id' => $viewerId,
            ])->exists();
            $relationship['mutual'] = $relationship['following'] && $relationship['followed_by'];

            $viewerFollowing = DB::table('follows')
                ->where(['app_id' => $appId, 'user_id' => $viewerId, 'target_type' => 'user'])
                ->pluck('target_id')->map(fn ($id) => (int) $id);
            $mutualIds = $viewerFollowing->intersect($followingIds)->take(12)->values();

            if ($settings['show_event_interests']) {
                $viewerEventIds = DB::table('event_engagements')
                    ->where(['app_id' => $appId, 'user_id' => $viewerId, 'is_interested' => true])
                    ->pluck('event_id');
                $targetEventIds = DB::table('event_engagements')
                    ->where(['app_id' => $appId, 'user_id' => $user->id, 'is_interested' => true])
                    ->pluck('event_id');
                $ids = $viewerEventIds->intersect($targetEventIds)->take(12)->values();
                if ($ids->isNotEmpty()) {
                    $commonEvents = Event::query()
                        ->where('app_id', $appId)
                        ->whereIn('id', $ids)
                        ->where('is_published', true)
                        ->where('is_cancelled', false)
                        ->where(fn ($query) => $query->where('is_private', false)->orWhereNull('is_private'))
                        ->with('production:id,app_id,name,slug,logo')
                        ->orderBy('start_date')
                        ->get();
                }
            }
        }

        $mutuals = $this->userPreviews($mutualIds);
        $followersPreview = $settings['show_followers'] ? $this->userPreviews($followerIds->take(12)) : collect();
        $followingPreview = $settings['show_following'] ? $this->userPreviews($followingIds->take(12)) : collect();

        $postCount = DB::table('event_posts')->where([
            'app_id' => $appId, 'user_id' => $user->id, 'status' => 'published',
        ])->count();

        return [
            'profile' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'user_name' => $user->user_name,
                'avatar' => $user->avatar,
                'background' => $user->background,
                'city' => $settings['show_city'] ? $user->city : null,
                'uf' => $settings['show_city'] ? $user->uf : null,
                'about' => $user->about,
                'favorite_artist' => $settings['show_interests'] ? $user->favorite_artist : null,
                'favorite_genre' => $settings['show_interests'] ? $user->favorite_genre : null,
                'created_at' => $user->created_at,
            ],
            'actor_identity' => $this->actorIdentity->for($user, $appId, true),
            'relationship' => $relationship,
            'stats' => [
                'interested' => $settings['show_event_interests'] ? $interestedEvents->count() : null,
                'followers' => $followerIds->count(),
                'following_participants' => $followingIds->count(),
                'following_artists' => DB::table('follows')->where(['app_id' => $appId, 'user_id' => $user->id, 'target_type' => 'artist'])->count(),
                'following_productions' => DB::table('follows')->where(['app_id' => $appId, 'user_id' => $user->id, 'target_type' => 'production'])->count(),
                'posts' => $postCount,
            ],
            'interests' => $interests,
            'social_settings' => $settings,
            'interested_events' => $interestedEvents,
            'mutual_connections_count' => $mutualIds->count(),
            'mutual_connections' => $mutuals,
            'common_events_count' => $commonEvents->count(),
            'common_events' => $commonEvents,
            'followers_preview' => $followersPreview,
            'following_preview' => $followingPreview,
        ];
    }

    private function interests(int $appId, int $userId): array
    {
        $raw = DB::table('application_user_preferences')->where(['app_id' => $appId, 'user_id' => $userId])->value('interests');
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        return collect(is_array($raw) ? $raw : [])
            ->filter(fn ($interest) => is_string($interest) && trim($interest) !== '')
            ->map(fn ($interest) => trim($interest))
            ->unique(fn ($interest) => mb_strtolower($interest))
            ->values()->take(50)->all();
    }

    private function interestedEvents(int $appId, int $userId)
    {
        $ids = DB::table('event_engagements')->where(['app_id' => $appId, 'user_id' => $userId, 'is_interested' => true])->pluck('event_id');

        return Event::query()
            ->where('app_id', $appId)
            ->whereIn('id', $ids)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where(fn ($query) => $query->where('is_private', false)->orWhereNull('is_private'))
            ->where('end_date', '>', now())
            ->with('production:id,app_id,name,slug,logo')
            ->orderBy('start_date')->limit(24)->get();
    }

    private function userPreviews($ids)
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) return collect();

        return User::query()
            ->whereIn('id', $ids)
            ->select(['id', 'first_name', 'last_name', 'user_name', 'avatar', 'city', 'uf'])
            ->get()
            ->sortBy(fn (User $user) => $ids->search((int) $user->id))
            ->values();
    }
}
