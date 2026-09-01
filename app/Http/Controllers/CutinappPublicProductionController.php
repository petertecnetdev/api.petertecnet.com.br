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

    public function index(Request $request)
    {
        $data = $request->validate([
            'city' => 'nullable|string|max:120',
            'uf' => 'nullable|string|size:2',
            'lat' => 'nullable|numeric|between:-90,90|required_with:lng',
            'lng' => 'nullable|numeric|between:-180,180|required_with:lat',
            'radius_km' => 'nullable|integer|min:1|max:500',
            'per_page' => 'nullable|integer|min:1|max:24',
        ]);

        $appId = $this->applicationId();
        $query = Production::query()
            ->where('app_id', $appId)
            ->where('app_slug', self::APP)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->withCount(['events as upcoming_events_count' => fn ($q) => $q
                ->where('app_id', $appId)
                ->where('app_slug', self::APP)
                ->where('is_published', true)
                ->where('is_cancelled', false)
                ->where(fn ($privacy) => $privacy->where('is_private', false)->orWhereNull('is_private'))
                ->where('end_date', '>', now())]);

        if (! empty($data['city'])) {
            $query->whereRaw('LOWER(city) = LOWER(?)', [trim($data['city'])]);
        }
        if (! empty($data['uf'])) {
            $query->where('uf', strtoupper($data['uf']));
        }

        $distanceEnabled = isset($data['lat'], $data['lng']);
        if ($distanceEnabled) {
            $lat = (float) $data['lat'];
            $lng = (float) $data['lng'];
            $radius = (int) ($data['radius_km'] ?? 80);
            $distanceSql = '(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude))))';
            $query->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->select('productions.*')
                ->selectRaw("{$distanceSql} AS distance_km", [$lat, $lng, $lat])
                ->whereRaw("{$distanceSql} <= ?", [$lat, $lng, $lat, $radius])
                ->orderBy('distance_km');
        } else {
            $query->orderByDesc('is_featured')
                ->orderByDesc('upcoming_events_count')
                ->orderBy('name');
        }

        return response()->json([
            'productions' => $query->paginate($data['per_page'] ?? 8)->appends($request->query()),
        ]);
    }

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
            ->where(fn ($privacy) => $privacy->where('is_private', false)->orWhereNull('is_private'));

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
                ->where(fn ($privacy) => $privacy->where('events.is_private', false)->orWhereNull('events.is_private')))
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
        if (! $token) return null;

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
