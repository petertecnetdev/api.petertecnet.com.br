<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\Application;
use App\Models\CutinappArtist;
use App\Models\CutinappArtistClaim;
use App\Models\Event;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;
use Tymon\JWTAuth\Facades\JWTAuth;

class CutinappArtistClaimController extends Controller
{
    private const APP = 'cutinapp';

    public function manageable(Request $request)
    {
        $user = $this->requestUser($request);
        $appId = $this->applicationId();

        $query = CutinappArtist::query()->where('app_id', $appId);
        if (! $user->hasProfile('Administrador')) {
            $query->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)->orWhere('created_by_user_id', $user->id);
            });
        }

        return response()->json([
            'artists' => $query->orderBy('stage_name')->paginate(min(max((int) $request->input('per_page', 100), 1), 100)),
        ]);
    }

    public function storeProvisional(Request $request)
    {
        $user = $this->requestUser($request);
        $data = $this->artistData($request);
        $claimMyself = (bool) ($data['claim_myself'] ?? false);
        unset($data['claim_myself']);

        $data['app_id'] = $this->applicationId();
        $data['created_by_user_id'] = $user->id;
        $data['user_id'] = $claimMyself ? $user->id : null;
        $data['claimed_at'] = $claimMyself ? now() : null;
        $data['slug'] = $this->uniqueArtistSlug($data['stage_name']);

        $artist = CutinappArtist::create($data);

        return response()->json([
            'message' => $claimMyself
                ? 'Seu perfil artístico foi criado e vinculado à sua conta.'
                : 'Perfil provisório criado. O artista poderá reivindicá-lo ao se cadastrar na Cutinapp.',
            'artist' => $artist,
        ], 201);
    }

    public function updateManaged(Request $request, int $artistId)
    {
        $artist = $this->managedArtist($artistId, $this->requestUser($request));
        $data = $this->artistData($request, false);
        unset($data['claim_myself']);

        if (! empty($data['stage_name']) && $data['stage_name'] !== $artist->stage_name) {
            $data['slug'] = $this->uniqueArtistSlug($data['stage_name'], $artist->id);
        }

        $artist->update($data);
        return response()->json(['message' => 'Perfil artístico atualizado.', 'artist' => $artist->fresh()]);
    }

    public function claimability(Request $request, int $eventId, int $artistId)
    {
        $user = $this->requestUser($request);
        [$event, $artist] = $this->eventArtist($eventId, $artistId);

        $existing = CutinappArtistClaim::query()
            ->where('app_id', $this->applicationId())
            ->where('event_id', $event->id)
            ->where('artist_id', $artist->id)
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        return response()->json([
            'artist' => $artist,
            'event' => ['id' => $event->id, 'slug' => $event->slug, 'title' => $event->title],
            'claimable' => is_null($artist->claimed_at) || (int) $artist->user_id === (int) $user->id,
            'is_owner' => ! is_null($artist->claimed_at) && (int) $artist->user_id === (int) $user->id,
            'claim' => $existing,
        ]);
    }

    public function claim(Request $request, int $eventId, int $artistId)
    {
        $user = $this->requestUser($request);
        [$event, $artist] = $this->eventArtist($eventId, $artistId);

        if ($artist->claimed_at && (int) $artist->user_id !== (int) $user->id) {
            throw ValidationException::withMessages(['artist' => ['Este perfil artístico já foi reivindicado por outro usuário.']]);
        }

        if ($artist->claimed_at && (int) $artist->user_id === (int) $user->id) {
            return response()->json(['message' => 'Este perfil artístico já está vinculado à sua conta.', 'already_owner' => true]);
        }

        $data = $request->validate(['message' => 'nullable|string|max:2000']);

        $claim = CutinappArtistClaim::query()->updateOrCreate([
            'app_id' => $this->applicationId(),
            'artist_id' => $artist->id,
            'event_id' => $event->id,
            'user_id' => $user->id,
        ], [
            'status' => 'pending',
            'message' => $data['message'] ?? null,
            'reviewed_by_user_id' => null,
            'review_notes' => null,
            'reviewed_at' => null,
        ]);

        $producerUserId = $event->production?->user_id;
        if ($producerUserId) {
            AppNotification::query()->create([
                'app_id' => $this->applicationId(),
                'user_id' => $producerUserId,
                'type' => 'artist_claim_requested',
                'title' => 'Artista solicitou vínculo',
                'message' => $user->first_name . ' solicitou o vínculo com ' . $artist->stage_name . ' no evento ' . $event->title . '.',
                'reference_type' => 'artist_claim',
                'reference_id' => $claim->id,
                'reference_url' => '/event/' . $event->id . '/artist-claims',
                'data' => ['claim_id' => $claim->id, 'artist_id' => $artist->id, 'event_id' => $event->id],
            ]);
        }

        return response()->json([
            'message' => 'Solicitação enviada ao produtor do evento. Você será avisado quando ela for analisada.',
            'claim' => $claim,
        ], 201);
    }

    public function myClaims(Request $request)
    {
        $user = $this->requestUser($request);
        return response()->json([
            'claims' => CutinappArtistClaim::query()
                ->where('app_id', $this->applicationId())
                ->where('user_id', $user->id)
                ->with(['artist:id,slug,stage_name,artist_type,photo', 'event:id,slug,title,start_date'])
                ->latest()
                ->paginate(min(max((int) $request->input('per_page', 50), 1), 100)),
        ]);
    }

    public function eventClaims(Request $request, int $eventId)
    {
        $event = $this->managedEvent($eventId, $this->requestUser($request));

        return response()->json([
            'event' => ['id' => $event->id, 'title' => $event->title, 'slug' => $event->slug],
            'claims' => CutinappArtistClaim::query()
                ->where('app_id', $this->applicationId())
                ->where('event_id', $event->id)
                ->with(['artist:id,slug,stage_name,artist_type,photo,user_id,claimed_at', 'user:id,first_name,last_name,email,image'])
                ->latest()
                ->get(),
        ]);
    }

    public function review(Request $request, int $eventId, int $claimId)
    {
        $reviewer = $this->requestUser($request);
        $event = $this->managedEvent($eventId, $reviewer);
        $data = $request->validate([
            'decision' => 'required|in:approve,reject',
            'review_notes' => 'nullable|string|max:2000',
        ]);

        $claim = CutinappArtistClaim::query()
            ->where('app_id', $this->applicationId())
            ->where('event_id', $event->id)
            ->with(['artist', 'user'])
            ->findOrFail($claimId);

        if ($claim->status !== 'pending') {
            throw ValidationException::withMessages(['claim' => ['Esta solicitação já foi analisada.']]);
        }

        DB::transaction(function () use ($claim, $reviewer, $data) {
            $artist = CutinappArtist::query()->lockForUpdate()->findOrFail($claim->artist_id);

            if ($data['decision'] === 'approve') {
                if ($artist->claimed_at && (int) $artist->user_id !== (int) $claim->user_id) {
                    throw ValidationException::withMessages(['artist' => ['Este artista foi reivindicado por outro usuário enquanto a solicitação estava pendente.']]);
                }

                $artist->update(['user_id' => $claim->user_id, 'claimed_at' => now()]);
                $claim->update([
                    'status' => 'approved',
                    'reviewed_by_user_id' => $reviewer->id,
                    'review_notes' => $data['review_notes'] ?? null,
                    'reviewed_at' => now(),
                ]);

                CutinappArtistClaim::query()
                    ->where('artist_id', $artist->id)
                    ->where('id', '!=', $claim->id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'rejected',
                        'reviewed_by_user_id' => $reviewer->id,
                        'review_notes' => 'Outro usuário teve a reivindicação aprovada.',
                        'reviewed_at' => now(),
                        'updated_at' => now(),
                    ]);
            } else {
                $claim->update([
                    'status' => 'rejected',
                    'reviewed_by_user_id' => $reviewer->id,
                    'review_notes' => $data['review_notes'] ?? null,
                    'reviewed_at' => now(),
                ]);
            }
        });

        $claim->refresh();
        AppNotification::query()->create([
            'app_id' => $this->applicationId(),
            'user_id' => $claim->user_id,
            'type' => 'artist_claim_reviewed',
            'title' => $claim->status === 'approved' ? 'Vínculo artístico aprovado' : 'Solicitação de vínculo analisada',
            'message' => $claim->status === 'approved'
                ? 'Seu perfil artístico foi confirmado e agora está vinculado à sua conta Cutinapp.'
                : 'Sua solicitação de vínculo artístico não foi aprovada pelo produtor.',
            'reference_type' => 'artist_claim',
            'reference_id' => $claim->id,
            'reference_url' => '/artist/' . $claim->artist->slug,
            'data' => ['claim_id' => $claim->id, 'artist_id' => $claim->artist_id, 'event_id' => $claim->event_id, 'status' => $claim->status],
        ]);

        return response()->json(['message' => $claim->status === 'approved' ? 'Vínculo aprovado.' : 'Solicitação rejeitada.', 'claim' => $claim]);
    }

    private function eventArtist(int $eventId, int $artistId): array
    {
        $event = Event::query()
            ->where('app_id', $this->applicationId())
            ->where('app_slug', self::APP)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where('is_private', false)
            ->with('production')
            ->findOrFail($eventId);

        $artist = $event->artists()
            ->where('cutinapp_artists.app_id', $this->applicationId())
            ->where('cutinapp_artists.id', $artistId)
            ->firstOrFail();

        return [$event, $artist];
    }

    private function managedEvent(int $eventId, User $user): Event
    {
        $event = Event::query()
            ->where('app_id', $this->applicationId())
            ->where('app_slug', self::APP)
            ->with('production')
            ->findOrFail($eventId);

        abort_unless($event->production && ($user->hasProfile('Administrador') || (int) $event->production->user_id === (int) $user->id), 403, 'Você não pode analisar solicitações deste evento.');
        return $event;
    }

    private function managedArtist(int $artistId, User $user): CutinappArtist
    {
        $artist = CutinappArtist::query()->where('app_id', $this->applicationId())->findOrFail($artistId);
        abort_unless(
            $user->hasProfile('Administrador') ||
            (int) $artist->user_id === (int) $user->id ||
            (int) $artist->created_by_user_id === (int) $user->id,
            403,
            'Você não pode administrar este artista.'
        );
        return $artist;
    }

    private function artistData(Request $request, bool $creating = true): array
    {
        $required = $creating ? 'required|' : 'sometimes|';
        return $request->validate([
            'artist_type' => $required . 'in:solo,band,group,duo,collective,orchestra',
            'stage_name' => $required . 'string|min:2|max:255',
            'bio' => 'nullable|string|max:20000',
            'city' => 'nullable|string|max:120',
            'uf' => 'nullable|string|size:2',
            'genres' => 'nullable|array|max:30',
            'genres.*' => 'string|max:80',
            'photo' => 'nullable|string|max:2048',
            'cover' => 'nullable|string|max:2048',
            'instagram_url' => 'nullable|url|max:2048',
            'youtube_url' => 'nullable|url|max:2048',
            'spotify_url' => 'nullable|url|max:2048',
            'website_url' => 'nullable|url|max:2048',
            'is_published' => 'nullable|boolean',
            'claim_myself' => 'nullable|boolean',
        ]);
    }

    private function uniqueArtistSlug(string $name, ?int $ignore = null): string
    {
        $base = Str::slug($name) ?: 'artista';
        $slug = $base;
        $i = 2;
        while (CutinappArtist::query()->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    private function requestUser(Request $request): User
    {
        $token = $request->bearerToken();
        if (! $token) abort(401, 'Sua sessão expirou. Entre novamente.');
        try {
            $user = JWTAuth::setToken($token)->authenticate();
        } catch (Throwable) {
            $user = null;
        }
        abort_unless($user instanceof User, 401, 'Sua sessão expirou. Entre novamente.');
        return $user;
    }

    private function applicationId(): int
    {
        $app = Application::query()->where('slug', self::APP)->where('is_active', true)->first();
        abort_unless($app, 503, 'A Cutinapp não está registrada corretamente na API.');
        return (int) $app->id;
    }
}
