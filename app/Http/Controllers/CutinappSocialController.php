<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\Application;
use App\Models\CutinappArtist;
use App\Models\Event;
use App\Models\Production;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappSocialController extends Controller
{
    private const APP = 'cutinapp';

    public function artists(Request $request)
    {
        $data = $request->validate([
            'q' => 'nullable|string|max:120',
            'city' => 'nullable|string|max:120',
            'uf' => 'nullable|string|size:2',
            'genre' => 'nullable|string|max:120',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $appId = $this->applicationId();
        $query = CutinappArtist::query()
            ->where('app_id', $appId)
            ->where('is_published', true)
            ->withCount(['events as upcoming_events_count' => fn ($q) => $q
                ->where('events.app_id', $appId)
                ->where('events.is_published', true)
                ->where('events.is_cancelled', false)
                ->where('events.end_date', '>', now())])
            ->withCount(['events as total_events_count' => fn ($q) => $q->where('events.app_id', $appId)])
            ->orderBy('stage_name');

        if ($q = trim((string) ($data['q'] ?? ''))) {
            $query->where(fn ($nested) => $nested
                ->where('stage_name', 'like', "%{$q}%")
                ->orWhere('bio', 'like', "%{$q}%"));
        }
        if (! empty($data['city'])) $query->where('city', $data['city']);
        if (! empty($data['uf'])) $query->where('uf', strtoupper($data['uf']));
        if (! empty($data['genre'])) $query->where('genres', 'like', '%' . $data['genre'] . '%');

        $artists = $query->paginate($data['per_page'] ?? 24);
        $artists->getCollection()->transform(fn ($artist) => $this->decorateArtist($artist, $request));
        return response()->json(['artists' => $artists]);
    }

    public function publicArtist(Request $request, string $slug)
    {
        $appId = $this->applicationId();
        $artist = CutinappArtist::query()
            ->where('app_id', $appId)
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        $artist->setAttribute('followers_count', $this->followersCount('artist', $artist->id));
        $artist->setAttribute('is_following', $this->isFollowing($request, 'artist', $artist->id));

        $upcoming = $this->artistEvents($artist->id, true)->limit(12)->get();
        $past = $this->artistEvents($artist->id, false)->limit(12)->get();

        return response()->json(['artist' => $artist, 'upcoming_events' => $upcoming, 'past_events' => $past]);
    }

    public function myArtists(Request $request)
    {
        $user = $this->requestUser($request);
        $appId = $this->applicationId();
        $query = CutinappArtist::query()->where('app_id', $appId);
        if (! $user->hasProfile('Administrador')) {
            $query->where('user_id', $user->id);
        }
        return response()->json(['artists' => $query->orderBy('stage_name')->paginate(min(max((int) $request->input('per_page', 50), 1), 100))]);
    }

    public function storeArtist(Request $request)
    {
        $user = $this->requestUser($request);
        $appId = $this->applicationId();
        $data = $this->artistData($request);
        $data['app_id'] = $appId;
        $data['user_id'] = $request->filled('user_id') && $user->hasProfile('Administrador')
            ? (int) $request->input('user_id')
            : $user->id;
        $data['slug'] = $this->uniqueArtistSlug($data['stage_name']);
        $artist = CutinappArtist::create($data);
        return response()->json(['message' => 'Artista criado com sucesso.', 'artist' => $artist], 201);
    }

    public function updateArtist(Request $request, int $id)
    {
        $user = $this->requestUser($request);
        $artist = $this->managedArtist($id, $user);
        $data = $this->artistData($request, false);
        if (! empty($data['stage_name']) && $data['stage_name'] !== $artist->stage_name) {
            $data['slug'] = $this->uniqueArtistSlug($data['stage_name'], $artist->id);
        }
        unset($data['app_id'], $data['user_id']);
        $artist->update($data);
        return response()->json(['message' => 'Perfil do artista atualizado.', 'artist' => $artist->fresh()]);
    }

    public function eventArtists(Request $request, int $eventId)
    {
        $event = $this->ownedEvent($eventId, $this->requestUser($request));
        return response()->json(['artists' => $event->artists()->orderBy('cutinapp_event_artist.sort_order')->get()]);
    }

    public function attachArtist(Request $request, int $eventId)
    {
        $user = $this->requestUser($request);
        $event = $this->ownedEvent($eventId, $user);
        $data = $request->validate([
            'artist_id' => 'required|integer',
            'participation_type' => 'required|string|max:80',
            'description' => 'nullable|string|max:2000',
            'sort_order' => 'nullable|integer|min:0|max:1000',
            'scheduled_at' => 'nullable|date',
            'stage' => 'nullable|string|max:160',
            'is_headliner' => 'nullable|boolean',
        ]);
        $artist = CutinappArtist::where('app_id', $this->applicationId())->findOrFail($data['artist_id']);
        $event->artists()->syncWithoutDetaching([$artist->id => [
            'app_id' => $this->applicationId(),
            'participation_type' => $data['participation_type'],
            'description' => $data['description'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'stage' => $data['stage'] ?? null,
            'is_headliner' => (bool) ($data['is_headliner'] ?? false),
        ]]);
        $this->notifyFollowersForArtistLineup($artist, $event);
        return response()->json(['message' => 'Artista vinculado ao evento.', 'artists' => $event->artists()->orderBy('cutinapp_event_artist.sort_order')->get()]);
    }

    public function detachArtist(Request $request, int $eventId, int $artistId)
    {
        $event = $this->ownedEvent($eventId, $this->requestUser($request));
        $event->artists()->detach($artistId);
        return response()->json(['message' => 'Artista removido do line-up.']);
    }

    public function publicProduction(Request $request, string $slug)
    {
        $appId = $this->applicationId();
        $production = Production::query()
            ->where('app_id', $appId)->where('app_slug', self::APP)->where('slug', $slug)
            ->firstOrFail();
        $production->setAttribute('followers_count', $this->followersCount('production', $production->id));
        $production->setAttribute('is_following', $this->isFollowing($request, 'production', $production->id));
        $upcoming = Event::where('app_id', $appId)->where('production_id', $production->id)->where('is_published', true)->where('is_cancelled', false)->where('end_date', '>', now())->orderBy('start_date')->limit(24)->get();
        $past = Event::where('app_id', $appId)->where('production_id', $production->id)->where('end_date', '<=', now())->orderByDesc('start_date')->limit(24)->get();
        $artists = CutinappArtist::query()->where('app_id', $appId)->whereHas('events', fn ($q) => $q->where('events.production_id', $production->id))->distinct()->limit(30)->get();
        return response()->json(compact('production', 'upcoming', 'past', 'artists'));
    }

    public function follow(Request $request)
    {
        $user = $this->requestUser($request);
        $data = $request->validate(['target_type' => 'required|in:artist,production', 'target_id' => 'required|integer|min:1']);
        $this->assertTarget($data['target_type'], $data['target_id']);
        DB::table('cutinapp_follows')->updateOrInsert([
            'app_id' => $this->applicationId(), 'user_id' => $user->id, 'target_type' => $data['target_type'], 'target_id' => $data['target_id'],
        ], ['updated_at' => now(), 'created_at' => now()]);
        return response()->json(['message' => 'Agora você está seguindo este perfil.', 'following' => true]);
    }

    public function unfollow(Request $request)
    {
        $user = $this->requestUser($request);
        $data = $request->validate(['target_type' => 'required|in:artist,production', 'target_id' => 'required|integer|min:1']);
        DB::table('cutinapp_follows')->where(['app_id' => $this->applicationId(), 'user_id' => $user->id, 'target_type' => $data['target_type'], 'target_id' => $data['target_id']])->delete();
        return response()->json(['message' => 'Você deixou de seguir este perfil.', 'following' => false]);
    }

    public function engagement(Request $request, int $eventId)
    {
        $user = $this->requestUser($request);
        $event = Event::where('app_id', $this->applicationId())->where('app_slug', self::APP)->findOrFail($eventId);
        $data = $request->validate(['is_favorite' => 'sometimes|boolean', 'is_interested' => 'sometimes|boolean']);
        DB::table('cutinapp_event_engagements')->updateOrInsert(
            ['app_id' => $this->applicationId(), 'user_id' => $user->id, 'event_id' => $event->id],
            array_merge($data, ['updated_at' => now(), 'created_at' => now()])
        );
        $row = DB::table('cutinapp_event_engagements')->where(['app_id' => $this->applicationId(), 'user_id' => $user->id, 'event_id' => $event->id])->first();
        return response()->json(['message' => 'Preferência atualizada.', 'engagement' => $row]);
    }

    public function preferences(Request $request)
    {
        $user = $this->requestUser($request);
        if ($request->isMethod('get')) {
            return response()->json(['preferences' => DB::table('cutinapp_user_preferences')->where(['app_id' => $this->applicationId(), 'user_id' => $user->id])->first()]);
        }
        $data = $request->validate([
            'preferred_city' => 'nullable|string|max:120', 'preferred_uf' => 'nullable|string|size:2',
            'latitude' => 'nullable|numeric|between:-90,90', 'longitude' => 'nullable|numeric|between:-180,180',
            'radius_km' => 'nullable|integer|min:1|max:500', 'interests' => 'nullable|array|max:50',
        ]);
        if (isset($data['preferred_uf'])) $data['preferred_uf'] = strtoupper($data['preferred_uf']);
        DB::table('cutinapp_user_preferences')->updateOrInsert(
            ['app_id' => $this->applicationId(), 'user_id' => $user->id],
            array_merge($data, ['updated_at' => now(), 'created_at' => now()])
        );
        return response()->json(['message' => 'Preferências de descoberta salvas.', 'preferences' => DB::table('cutinapp_user_preferences')->where(['app_id' => $this->applicationId(), 'user_id' => $user->id])->first()]);
    }

    public function feed(Request $request)
    {
        $user = $this->requestUser($request);
        $appId = $this->applicationId();
        $perPage = min(max((int) $request->input('per_page', 20), 1), 50);
        $followedProductions = DB::table('cutinapp_follows')->where(['app_id' => $appId, 'user_id' => $user->id, 'target_type' => 'production'])->pluck('target_id');
        $followedArtists = DB::table('cutinapp_follows')->where(['app_id' => $appId, 'user_id' => $user->id, 'target_type' => 'artist'])->pluck('target_id');
        $preferences = DB::table('cutinapp_user_preferences')->where(['app_id' => $appId, 'user_id' => $user->id])->first();

        $query = Event::query()->where('events.app_id', $appId)->where('events.app_slug', self::APP)->where('events.is_published', true)->where('events.is_cancelled', false)->where('events.end_date', '>', now())
            ->with(['production:id,app_id,name,slug,logo,city,uf', 'artists:id,app_id,slug,stage_name,photo'])
            ->withCount(['tickets as passes_available_count' => fn ($q) => $q->where('app_id', $appId)->where('price', 0)->where('quantity', '>', 0)])
            ->orderByRaw('CASE WHEN production_id IN (' . ($followedProductions->isEmpty() ? '0' : $followedProductions->map(fn ($id) => (int) $id)->implode(',')) . ') THEN 0 ELSE 1 END')
            ->orderBy('start_date');

        if ($preferences?->preferred_city) {
            $city = $preferences->preferred_city;
            $query->orderByRaw('CASE WHEN LOWER(city) = LOWER(?) THEN 0 ELSE 1 END', [$city]);
        }
        if ($followedArtists->isNotEmpty()) {
            $ids = $followedArtists->map(fn ($id) => (int) $id)->implode(',');
            $query->orderByRaw("CASE WHEN EXISTS (SELECT 1 FROM cutinapp_event_artist cea WHERE cea.event_id = events.id AND cea.artist_id IN ({$ids})) THEN 0 ELSE 1 END");
        }

        return response()->json(['feed' => $query->paginate($perPage)]);
    }

    public function notifications(Request $request)
    {
        $user = $this->requestUser($request);
        $items = AppNotification::query()->where('app_id', $this->applicationId())->where('user_id', $user->id)->latest()->paginate(min(max((int) $request->input('per_page', 30), 1), 100));
        return response()->json(['notifications' => $items]);
    }

    private function artistEvents(int $artistId, bool $upcoming)
    {
        $q = Event::query()->where('events.app_id', $this->applicationId())->where('events.is_published', true)->where('events.is_cancelled', false)
            ->whereHas('artists', fn ($a) => $a->where('cutinapp_artists.id', $artistId))->with('production:id,name,slug,logo');
        return $upcoming ? $q->where('end_date', '>', now())->orderBy('start_date') : $q->where('end_date', '<=', now())->orderByDesc('start_date');
    }

    private function decorateArtist(CutinappArtist $artist, Request $request): CutinappArtist
    {
        $artist->setAttribute('followers_count', $this->followersCount('artist', $artist->id));
        $artist->setAttribute('is_following', $this->isFollowing($request, 'artist', $artist->id));
        return $artist;
    }

    private function followersCount(string $type, int $id): int
    {
        return DB::table('cutinapp_follows')->where(['app_id' => $this->applicationId(), 'target_type' => $type, 'target_id' => $id])->count();
    }

    private function isFollowing(Request $request, string $type, int $id): bool
    {
        $user = $this->optionalRequestUser($request);
        return $user instanceof User && DB::table('cutinapp_follows')->where([
            'app_id' => $this->applicationId(),
            'user_id' => $user->id,
            'target_type' => $type,
            'target_id' => $id,
        ])->exists();
    }

    private function assertTarget(string $type, int $id): void
    {
        $appId = $this->applicationId();
        $exists = $type === 'artist'
            ? CutinappArtist::where('app_id', $appId)->whereKey($id)->exists()
            : Production::where('app_id', $appId)->where('app_slug', self::APP)->whereKey($id)->exists();
        abort_unless($exists, 404, 'Perfil não encontrado na Cutinapp.');
    }

    private function managedArtist(int $id, User $user): CutinappArtist
    {
        $artist = CutinappArtist::where('app_id', $this->applicationId())->findOrFail($id);
        abort_unless($user->hasProfile('Administrador') || (int) $artist->user_id === (int) $user->id, 403, 'Você não pode administrar este artista.');
        return $artist;
    }

    private function ownedEvent(int $id, User $user): Event
    {
        $event = Event::where('app_id', $this->applicationId())->where('app_slug', self::APP)->with('production')->findOrFail($id);
        abort_unless($event->production && ($user->hasProfile('Administrador') || (int) $event->production->user_id === (int) $user->id), 403, 'Você não pode administrar este evento.');
        return $event;
    }

    private function artistData(Request $request, bool $creating = true): array
    {
        $required = $creating ? 'required|' : 'sometimes|';
        return $request->validate([
            'stage_name' => $required . 'string|min:2|max:255', 'bio' => 'nullable|string|max:20000',
            'city' => 'nullable|string|max:120', 'uf' => 'nullable|string|size:2', 'genres' => 'nullable|array|max:30', 'genres.*' => 'string|max:80',
            'photo' => 'nullable|string|max:2048', 'cover' => 'nullable|string|max:2048', 'instagram_url' => 'nullable|url|max:2048',
            'youtube_url' => 'nullable|url|max:2048', 'spotify_url' => 'nullable|url|max:2048', 'website_url' => 'nullable|url|max:2048',
            'is_published' => 'nullable|boolean', 'user_id' => 'nullable|integer|exists:users,id',
        ]);
    }

    private function uniqueArtistSlug(string $name, ?int $ignore = null): string
    {
        $base = Str::slug($name) ?: 'artista'; $slug = $base; $i = 2;
        while (CutinappArtist::when($ignore, fn ($q) => $q->whereKeyNot($ignore))->where('slug', $slug)->exists()) $slug = $base . '-' . $i++;
        return $slug;
    }

    private function notifyFollowersForArtistLineup(CutinappArtist $artist, Event $event): void
    {
        $followers = DB::table('cutinapp_follows')->where(['app_id' => $this->applicationId(), 'target_type' => 'artist', 'target_id' => $artist->id])->pluck('user_id');
        foreach ($followers as $userId) {
            AppNotification::create([
                'app_id' => $this->applicationId(), 'user_id' => $userId, 'type' => 'artist_lineup',
                'title' => $artist->stage_name . ' confirmado em evento',
                'message' => $artist->stage_name . ' fará parte de ' . $event->title . '.',
                'reference_type' => 'event', 'reference_id' => $event->id, 'reference_url' => '/event/' . $event->slug,
                'data' => ['artist_id' => $artist->id, 'event_id' => $event->id],
            ]);
        }
    }

    private function requestUser(Request $request): User
    {
        $user = $this->optionalRequestUser($request);
        abort_unless($user instanceof User, 401, 'Sua sessão expirou. Entre novamente.');
        return $user;
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
        $app = Application::where('slug', self::APP)->where('is_active', true)->first();
        abort_unless($app, 503, 'A Cutinapp não está registrada corretamente na API.');
        return (int) $app->id;
    }
}
