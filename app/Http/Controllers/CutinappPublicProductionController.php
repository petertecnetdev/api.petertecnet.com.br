<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\CutinappArtist;
use App\Models\Event;
use App\Models\Production;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappPublicProductionController extends Controller
{
    private const APP = 'cutinapp';

    public function show(Request $request, string $slug)
    {
        $appId = $this->applicationId();

        $production = Production::query()
            ->where('app_id', $appId)
            ->where('app_slug', self::APP)
            ->where('slug', $slug)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->firstOrFail();

        $production->setAttribute('followers_count', $this->followersCount($appId, $production->id));
        $production->setAttribute('is_following', $this->isFollowing($request, $appId, $production->id));

        $visibleEvents = Event::query()
            ->where('app_id', $appId)
            ->where('app_slug', self::APP)
            ->where('production_id', $production->id)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where('is_private', false);

        $upcoming = (clone $visibleEvents)
            ->where('end_date', '>', now())
            ->orderBy('start_date')
            ->limit(24)
            ->get();

        $past = (clone $visibleEvents)
            ->where('end_date', '<=', now())
            ->orderByDesc('start_date')
            ->limit(24)
            ->get();

        $artists = CutinappArtist::query()
            ->where('app_id', $appId)
            ->where('is_published', true)
            ->whereHas('events', fn ($query) => $query
                ->where('events.app_id', $appId)
                ->where('events.app_slug', self::APP)
                ->where('events.production_id', $production->id)
                ->where('events.is_published', true)
                ->where('events.is_cancelled', false)
                ->where('events.is_private', false))
            ->distinct()
            ->limit(30)
            ->get();

        return response()->json(compact('production', 'upcoming', 'past', 'artists'));
    }

    private function followersCount(int $appId, int $productionId): int
    {
        return DB::table('cutinapp_follows')
            ->where([
                'app_id' => $appId,
                'target_type' => 'production',
                'target_id' => $productionId,
            ])
            ->count();
    }

    private function isFollowing(Request $request, int $appId, int $productionId): bool
    {
        $user = $this->optionalRequestUser($request);

        return $user instanceof User && DB::table('cutinapp_follows')->where([
            'app_id' => $appId,
            'user_id' => $user->id,
            'target_type' => 'production',
            'target_id' => $productionId,
        ])->exists();
    }

    private function optionalRequestUser(Request $request): ?User
    {
        $token = $request->bearerToken();
        if (! $token) {
            return null;
        }

        try {
            $user = JWTAuth::setToken($token)->authenticate();
            return $user instanceof User ? $user : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function applicationId(): int
    {
        $app = Application::query()
            ->where('slug', self::APP)
            ->where('is_active', true)
            ->first();

        abort_unless($app, 503, 'A Cutinapp não está registrada corretamente na API.');

        return (int) $app->id;
    }
}
