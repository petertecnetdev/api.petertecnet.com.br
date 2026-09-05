<?php

namespace App\Domain\People\Services;

use App\Models\Event;
use App\Models\EventPass;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class UserActivityProfileService
{
    public function overview(User $user, int $appId): array
    {
        $now = now();
        $relations = ['production:id,app_id,name,slug,logo', 'artists:id,app_id,slug,stage_name,photo'];

        $ticketEventIds = EventPass::where('user_id', $user->id)
            ->whereHas('event', fn ($q) => $q->where('app_id', $appId))
            ->pluck('event_id')->unique()->values();
        $interestedIds = DB::table('event_engagements')->where(['app_id' => $appId, 'user_id' => $user->id, 'is_interested' => true])->pluck('event_id');
        $favoriteIds = DB::table('event_engagements')->where(['app_id' => $appId, 'user_id' => $user->id, 'is_favorite' => true])->pluck('event_id');
        $base = fn () => Event::query()->where('app_id', $appId)->with($relations);

        $ticketUpcoming = $base()->whereIn('id', $ticketEventIds)->where('end_date', '>', $now)->orderBy('start_date')->limit(24)->get();
        $ticketPast = $base()->whereIn('id', $ticketEventIds)->where('end_date', '<=', $now)->orderByDesc('start_date')->limit(24)->get();
        $interested = $base()->whereIn('id', $interestedIds)->where('end_date', '>', $now)->where('is_cancelled', false)->orderBy('start_date')->limit(24)->get();
        $favorites = $base()->whereIn('id', $favoriteIds)->where('end_date', '>', $now)->where('is_cancelled', false)->orderBy('start_date')->limit(24)->get();
        $passes = EventPass::where('user_id', $user->id)
            ->whereHas('event', fn ($q) => $q->where('app_id', $appId))
            ->with(['ticket:id,event_id,name,type,ticket_type', 'event:id,title,slug,start_date,end_date,city,uf,image'])
            ->latest()->limit(50)->get();

        $followingParticipantIds = DB::table('follows')
            ->where(['app_id' => $appId, 'user_id' => $user->id, 'target_type' => 'user'])
            ->pluck('target_id')->map(fn ($id) => (int) $id)->values();
        $followerIds = DB::table('follows')
            ->where(['app_id' => $appId, 'target_type' => 'user', 'target_id' => $user->id])
            ->pluck('user_id')->map(fn ($id) => (int) $id)->values();
        $connections = $followingParticipantIds->intersect($followerIds)->count();

        $rawInterests = DB::table('application_user_preferences')->where(['app_id' => $appId, 'user_id' => $user->id])->value('interests');
        if (is_string($rawInterests)) {
            $decoded = json_decode($rawInterests, true);
            $rawInterests = is_array($decoded) ? $decoded : [];
        }
        $interests = collect(is_array($rawInterests) ? $rawInterests : [])
            ->filter(fn ($interest) => is_string($interest) && trim($interest) !== '')
            ->map(fn ($interest) => trim($interest))
            ->unique(fn ($interest) => mb_strtolower($interest))
            ->values()->take(50)->all();

        $social = Schema::hasTable('user_social_preferences')
            ? DB::table('user_social_preferences')->where(['app_id' => $appId, 'user_id' => $user->id])->first()
            : null;
        $socialSettings = [
            'discoverable' => $social ? (bool) $social->discoverable : true,
            'show_city' => $social ? (bool) $social->show_city : true,
            'show_interests' => $social ? (bool) $social->show_interests : true,
            'show_event_interests' => $social ? (bool) $social->show_event_interests : true,
            'allow_follows' => $social ? (bool) $social->allow_follows : true,
        ];

        $followingProductions = DB::table('follows')->where(['app_id' => $appId, 'user_id' => $user->id, 'target_type' => 'production'])->count();

        return [
            'profile' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'user_name' => $user->user_name,
                'avatar' => $user->avatar,
                'background' => $user->background,
                'city' => $user->city,
                'uf' => $user->uf,
                'about' => $user->about,
                'favorite_artist' => $user->favorite_artist,
                'favorite_genre' => $user->favorite_genre,
            ],
            'stats' => [
                'tickets' => $passes->count(),
                'upcoming_with_ticket' => $ticketUpcoming->count(),
                'interested' => $interested->count(),
                'favorites' => $favorites->count(),
                'following_artists' => DB::table('follows')->where(['app_id' => $appId, 'user_id' => $user->id, 'target_type' => 'artist'])->count(),
                'following_organizations' => $followingProductions,
                'following_productions' => $followingProductions,
                'followers' => $followerIds->count(),
                'following_participants' => $followingParticipantIds->count(),
                'connections' => $connections,
                'posts' => DB::table('event_posts')->where(['app_id' => $appId, 'user_id' => $user->id, 'status' => 'published'])->count(),
            ],
            'interests' => $interests,
            'social_settings' => $socialSettings,
            'upcoming_with_ticket' => $ticketUpcoming,
            'past_with_ticket' => $ticketPast,
            'interested_events' => $interested,
            'favorite_events' => $favorites,
            'passes' => $passes,
        ];
    }
}
