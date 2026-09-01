<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Event;
use App\Models\EventPass;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappUserProfileController extends Controller
{
    private const APP = 'cutinapp';

    public function overview(Request $request)
    {
        $user = $this->requestUser($request);
        $appId = $this->applicationId();
        $now = now();

        $eventRelations = [
            'production:id,app_id,name,slug,logo',
            'artists:id,app_id,slug,stage_name,photo',
        ];

        $ticketEventIds = EventPass::query()
            ->where('user_id', $user->id)
            ->whereHas('event', fn ($q) => $q->where('app_id', $appId)->where('app_slug', self::APP))
            ->pluck('event_id')
            ->unique()
            ->values();

        $interestedIds = DB::table('cutinapp_event_engagements')
            ->where(['app_id' => $appId, 'user_id' => $user->id, 'is_interested' => true])
            ->pluck('event_id');

        $favoriteIds = DB::table('cutinapp_event_engagements')
            ->where(['app_id' => $appId, 'user_id' => $user->id, 'is_favorite' => true])
            ->pluck('event_id');

        $base = fn () => Event::query()
            ->where('app_id', $appId)
            ->where('app_slug', self::APP)
            ->with($eventRelations);

        $ticketUpcoming = $base()->whereIn('id', $ticketEventIds)->where('end_date', '>', $now)->orderBy('start_date')->limit(24)->get();
        $ticketPast = $base()->whereIn('id', $ticketEventIds)->where('end_date', '<=', $now)->orderByDesc('start_date')->limit(24)->get();
        $interested = $base()->whereIn('id', $interestedIds)->where('end_date', '>', $now)->where('is_cancelled', false)->orderBy('start_date')->limit(24)->get();
        $favorites = $base()->whereIn('id', $favoriteIds)->where('end_date', '>', $now)->where('is_cancelled', false)->orderBy('start_date')->limit(24)->get();

        $passes = EventPass::query()
            ->where('user_id', $user->id)
            ->whereHas('event', fn ($q) => $q->where('app_id', $appId)->where('app_slug', self::APP))
            ->with(['ticket:id,event_id,name,type,ticket_type', 'event:id,title,slug,start_date,end_date,city,uf,image'])
            ->latest()
            ->limit(50)
            ->get();

        $followingArtists = DB::table('cutinapp_follows')->where(['app_id'=>$appId,'user_id'=>$user->id,'target_type'=>'artist'])->count();
        $followingProductions = DB::table('cutinapp_follows')->where(['app_id'=>$appId,'user_id'=>$user->id,'target_type'=>'production'])->count();
        $postsCount = DB::table('cutinapp_event_posts')->where(['app_id'=>$appId,'user_id'=>$user->id,'status'=>'published'])->count();

        return response()->json([
            'profile' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'user_name' => $user->user_name,
                'avatar' => $user->avatar,
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
                'following_artists' => $followingArtists,
                'following_productions' => $followingProductions,
                'posts' => $postsCount,
            ],
            'upcoming_with_ticket' => $ticketUpcoming,
            'past_with_ticket' => $ticketPast,
            'interested_events' => $interested,
            'favorite_events' => $favorites,
            'passes' => $passes,
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
