<?php

namespace App\Domain\People\Services;

use App\Models\Artist;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ArtistOnboardingService
{
    public function __construct(
        private readonly ArtistIdentityService $identity,
        private readonly ArtistReferenceService $references,
    ) {
    }

    public function status(int $appId, User $user): array
    {
        $artist = Artist::query()
            ->where('app_id', $appId)
            ->where('user_id', $user->id)
            ->where('artist_type', 'solo')
            ->first();

        $claim = DB::table('artist_identity_claims')
            ->where('app_id', $appId)
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        return [
            'is_artist' => (bool) $artist,
            'artist' => $artist,
            'next_steps' => $artist ? $this->nextSteps($artist) : [
                ['key' => 'activate', 'label' => 'Criar sua identidade artística', 'completed' => false],
                ['key' => 'photo', 'label' => 'Adicionar uma foto', 'completed' => false],
                ['key' => 'genres', 'label' => 'Informar ao menos uma categoria ou gênero', 'completed' => false],
            ],
            'identity_claim' => $claim,
        ];
    }

    public function activate(int $appId, User $user, array $data): array
    {
        abort_unless($user->email_verified_at, 403, 'Confirme seu e-mail antes de ativar seu perfil artístico.');

        $artist = $this->identity->getOrCreate($appId, $user, $user);
        $changes = [];

        if (! empty($data['stage_name'])) {
            $stageName = trim((string) $data['stage_name']);
            if ($stageName !== '' && $stageName !== $artist->stage_name) {
                $changes['stage_name'] = $stageName;
                $changes['slug'] = $this->uniqueSlug($appId, $stageName, $artist->id);
            }
        }

        if (array_key_exists('genres', $data)) {
            $changes['genres'] = array_values(array_unique(array_filter(array_map(
                fn ($value) => trim((string) $value),
                (array) $data['genres']
            ))));
        }

        if (! empty($data['photo'])) {
            $changes['photo'] = trim((string) $data['photo']);
        }

        if (! empty($data['short_bio'])) {
            $changes['short_bio'] = trim((string) $data['short_bio']);
        }

        if ($changes !== []) {
            $artist->fill($changes);
        }

        $artist->verification_status = $artist->verification_status === 'verified'
            ? 'verified'
            : 'account_linked';
        $artist->is_active = true;
        $artist->is_published = true;
        $artist->profile_completion = $this->profileCompletion($artist);
        $artist->onboarding_completed_at = $artist->profile_completion >= 60
            ? ($artist->onboarding_completed_at ?: now())
            : null;
        $artist->save();

        $this->references->markSelfOrigin($artist);
        $this->track($appId, $artist->id, $user->id, 'artist_self_activated', 'self_onboarding');

        return [
            'message' => 'Seu perfil artístico está ativo na Cutinapp.',
            'artist' => $artist->fresh(),
            'next_steps' => $this->nextSteps($artist->fresh()),
        ];
    }

    public function setReferenceVisibility(int $appId, User $user, int $artistId, bool $visible): Artist
    {
        $artist = Artist::query()->where('app_id', $appId)->findOrFail($artistId);

        abort_unless(
            (int) $artist->user_id === (int) $user->id || $user->hasProfile('Administrador'),
            403,
            'Somente o artista pode escolher se a produção de referência aparece publicamente.'
        );

        $updated = $this->references->setVisibility($artist, $visible);
        $this->track($appId, $artist->id, $user->id, 'artist_reference_visibility_changed', $visible ? 'visible' : 'hidden');

        return $updated;
    }

    public function claimCandidates(int $appId, string $term): array
    {
        $query = trim($term);
        if (mb_strlen($query) < 2) {
            return [];
        }

        return Artist::query()
            ->where('app_id', $appId)
            ->whereNull('user_id')
            ->where('is_active', true)
            ->where('stage_name', 'like', '%'.$query.'%')
            ->orderBy('stage_name')
            ->limit(10)
            ->get(['id', 'slug', 'stage_name', 'artist_type', 'photo', 'city', 'uf', 'verification_status'])
            ->map(fn (Artist $artist) => [
                'id' => (int) $artist->id,
                'slug' => $artist->slug,
                'stage_name' => $artist->stage_name,
                'artist_type' => $artist->artist_type,
                'photo' => $artist->photo,
                'city' => $artist->city,
                'uf' => $artist->uf,
                'verification_status' => $artist->verification_status,
            ])
            ->values()
            ->all();
    }

    public function claimExisting(int $appId, User $user, int $artistId, array $data): array
    {
        abort_unless($user->email_verified_at, 403, 'Confirme seu e-mail antes de reivindicar um perfil artístico.');

        return DB::transaction(function () use ($appId, $user, $artistId, $data) {
            $artist = Artist::query()
                ->where('app_id', $appId)
                ->lockForUpdate()
                ->findOrFail($artistId);

            if ($artist->user_id) {
                if ((int) $artist->user_id === (int) $user->id) {
                    return [
                        'message' => 'Este perfil artístico já está vinculado à sua conta.',
                        'status' => 'approved',
                        'artist' => $artist,
                    ];
                }

                throw ValidationException::withMessages([
                    'artist' => ['Este perfil artístico já pertence a outro usuário.'],
                ]);
            }

            $professionalEmail = mb_strtolower(trim((string) $artist->professional_email));
            $userEmail = mb_strtolower(trim((string) $user->email));
            $canAutoApprove = $professionalEmail !== '' && hash_equals($professionalEmail, $userEmail);

            DB::table('artist_identity_claims')->updateOrInsert(
                [
                    'app_id' => $appId,
                    'artist_id' => $artist->id,
                    'user_id' => $user->id,
                ],
                [
                    'status' => $canAutoApprove ? 'approved' : 'pending',
                    'evidence_text' => $data['evidence_text'] ?? null,
                    'evidence_url' => $data['evidence_url'] ?? null,
                    'reviewed_by_user_id' => $canAutoApprove ? $user->id : null,
                    'review_notes' => $canAutoApprove ? 'E-mail profissional confirmado automaticamente.' : null,
                    'reviewed_at' => $canAutoApprove ? now() : null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            if ($canAutoApprove) {
                $artist->forceFill([
                    'user_id' => $user->id,
                    'claimed_at' => now(),
                    'verification_status' => 'account_linked',
                ])->save();

                $this->track($appId, $artist->id, $user->id, 'artist_identity_claim_approved', 'professional_email');

                return [
                    'message' => 'Perfil artístico vinculado à sua conta.',
                    'status' => 'approved',
                    'artist' => $artist->fresh(),
                ];
            }

            $this->track($appId, $artist->id, $user->id, 'artist_identity_claim_requested', 'manual_evidence');

            return [
                'message' => 'Sua reivindicação foi enviada para análise. O perfil não será duplicado.',
                'status' => 'pending',
                'artist' => $artist,
            ];
        }, 3);
    }

    public function claimQueue(int $appId, User $reviewer): array
    {
        abort_unless($reviewer->hasProfile('Administrador'), 403, 'Apenas administradores podem analisar reivindicações artísticas.');

        return DB::table('artist_identity_claims')
            ->join('artists', 'artists.id', '=', 'artist_identity_claims.artist_id')
            ->join('users', 'users.id', '=', 'artist_identity_claims.user_id')
            ->where('artist_identity_claims.app_id', $appId)
            ->where('artist_identity_claims.status', 'pending')
            ->select([
                'artist_identity_claims.id',
                'artist_identity_claims.artist_id',
                'artist_identity_claims.user_id',
                'artist_identity_claims.evidence_text',
                'artist_identity_claims.evidence_url',
                'artist_identity_claims.created_at',
                'artists.stage_name',
                'artists.photo',
                'users.first_name',
                'users.last_name',
                'users.user_name',
                'users.avatar',
            ])
            ->orderBy('artist_identity_claims.created_at')
            ->limit(100)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    public function reviewClaim(int $appId, User $reviewer, int $claimId, array $data): array
    {
        abort_unless($reviewer->hasProfile('Administrador'), 403, 'Apenas administradores podem analisar reivindicações artísticas.');

        return DB::transaction(function () use ($appId, $reviewer, $claimId, $data) {
            $claim = DB::table('artist_identity_claims')
                ->where('app_id', $appId)
                ->where('id', $claimId)
                ->lockForUpdate()
                ->first();

            abort_unless($claim, 404, 'Reivindicação não encontrada.');
            abort_unless($claim->status === 'pending', 422, 'Esta reivindicação já foi analisada.');

            $approved = ($data['decision'] ?? '') === 'approve';
            $artist = Artist::query()->where('app_id', $appId)->lockForUpdate()->findOrFail($claim->artist_id);

            if ($approved) {
                abort_if($artist->user_id && (int) $artist->user_id !== (int) $claim->user_id, 409, 'O perfil já foi vinculado a outro usuário.');

                $artist->forceFill([
                    'user_id' => (int) $claim->user_id,
                    'claimed_at' => now(),
                    'verification_status' => 'account_linked',
                ])->save();
            }

            DB::table('artist_identity_claims')->where('id', $claim->id)->update([
                'status' => $approved ? 'approved' : 'rejected',
                'reviewed_by_user_id' => $reviewer->id,
                'review_notes' => $data['review_notes'] ?? null,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

            $this->track(
                $appId,
                $artist->id,
                (int) $claim->user_id,
                $approved ? 'artist_identity_claim_approved' : 'artist_identity_claim_rejected',
                'admin_review'
            );

            return [
                'message' => $approved ? 'Vínculo artístico aprovado.' : 'Reivindicação rejeitada.',
                'status' => $approved ? 'approved' : 'rejected',
                'artist' => $artist->fresh(),
            ];
        }, 3);
    }

    private function nextSteps(Artist $artist): array
    {
        return [
            ['key' => 'identity', 'label' => 'Identidade artística ativa', 'completed' => true],
            ['key' => 'photo', 'label' => 'Adicionar uma foto', 'completed' => (bool) $artist->photo],
            ['key' => 'genres', 'label' => 'Informar ao menos uma categoria ou gênero', 'completed' => ! empty($artist->genres)],
            ['key' => 'bio', 'label' => 'Escrever uma apresentação curta', 'completed' => (bool) ($artist->short_bio || $artist->bio)],
            ['key' => 'links', 'label' => 'Adicionar uma rede ou link profissional', 'completed' => (bool) ($artist->instagram_url || $artist->spotify_url || $artist->youtube_url || $artist->website_url)],
        ];
    }

    private function profileCompletion(Artist $artist): int
    {
        $score = 25;
        if ($artist->photo) $score += 20;
        if (! empty($artist->genres)) $score += 20;
        if ($artist->short_bio || $artist->bio) $score += 20;
        if ($artist->instagram_url || $artist->spotify_url || $artist->youtube_url || $artist->website_url) $score += 15;

        return min(100, $score);
    }

    private function uniqueSlug(int $appId, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'artista';
        $slug = $base;
        $counter = 2;

        while (Artist::withTrashed()
            ->where('app_id', $appId)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
    }

    private function track(int $appId, int $artistId, int $userId, string $eventType, string $source): void
    {
        DB::table('artist_analytics_events')->insert([
            'app_id' => $appId,
            'artist_id' => $artistId,
            'event_id' => null,
            'user_id' => $userId,
            'event_type' => $eventType,
            'source' => $source,
            'session_hash' => null,
            'metadata' => null,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
