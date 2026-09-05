<?php

namespace App\Domain\People\Services;

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PublicUserProfileService
{
    public function show(int $userId, int $appId): array
    {
        $user = User::query()
            ->select([
                'id',
                'first_name',
                'last_name',
                'user_name',
                'avatar',
                'background',
                'city',
                'uf',
                'about',
                'favorite_artist',
                'favorite_genre',
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
        ];

        abort_unless($settings['discoverable'], 404, 'Este perfil não está disponível.');

        $interests = [];
        if ($settings['show_interests']) {
            $rawInterests = DB::table('application_user_preferences')
                ->where(['app_id' => $appId, 'user_id' => $user->id])
                ->value('interests');

            if (is_string($rawInterests)) {
                $decoded = json_decode($rawInterests, true);
                $rawInterests = is_array($decoded) ? $decoded : [];
            }

            $interests = collect(is_array($rawInterests) ? $rawInterests : [])
                ->filter(fn ($interest) => is_string($interest) && trim($interest) !== '')
                ->map(fn ($interest) => trim($interest))
                ->unique(fn ($interest) => mb_strtolower($interest))
                ->values()
                ->take(50)
                ->all();
        }

        $interestedEvents = collect();
        if ($settings['show_event_interests']) {
            $interestedIds = DB::table('event_engagements')
                ->where([
                    'app_id' => $appId,
                    'user_id' => $user->id,
                    'is_interested' => true,
                ])
                ->pluck('event_id');

            $interestedEvents = Event::query()
                ->where('app_id', $appId)
                ->whereIn('id', $interestedIds)
                ->where('is_published', true)
                ->where('is_cancelled', false)
                ->where(fn ($query) => $query->where('is_private', false)->orWhereNull('is_private'))
                ->where('end_date', '>', now())
                ->with('production:id,app_id,name,slug,logo')
                ->orderBy('start_date')
                ->limit(24)
                ->get();
        }

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
            ],
            'stats' => [
                'interested' => $settings['show_event_interests'] ? $interestedEvents->count() : null,
                'followers' => DB::table('follows')->where([
                    'app_id' => $appId,
                    'target_type' => 'user',
                    'target_id' => $user->id,
                ])->count(),
                'following_participants' => DB::table('follows')->where([
                    'app_id' => $appId,
                    'user_id' => $user->id,
                    'target_type' => 'user',
                ])->count(),
                'following_artists' => DB::table('follows')->where([
                    'app_id' => $appId,
                    'user_id' => $user->id,
                    'target_type' => 'artist',
                ])->count(),
                'following_productions' => DB::table('follows')->where([
                    'app_id' => $appId,
                    'user_id' => $user->id,
                    'target_type' => 'production',
                ])->count(),
                'posts' => DB::table('event_posts')->where([
                    'app_id' => $appId,
                    'user_id' => $user->id,
                    'status' => 'published',
                ])->count(),
            ],
            'interests' => $interests,
            'social_settings' => $settings,
            'interested_events' => $interestedEvents,
        ];
    }
}
