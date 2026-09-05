<?php

namespace App\Domain\People\Services;

use App\Models\Artist;
use App\Models\Employer;
use App\Models\Production;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class UserActorIdentityService
{
    private const ROLE_PRIORITY = [
        'artist' => 10,
        'producer' => 20,
        'promoter' => 30,
        'staff' => 40,
        'ticket_seller' => 50,
        'partner' => 60,
        'participant' => 100,
    ];

    private const ROLE_LABELS = [
        'artist' => 'Artista',
        'producer' => 'Produtor',
        'promoter' => 'Promoter',
        'staff' => 'Staff',
        'ticket_seller' => 'Bilheteria',
        'partner' => 'Parceiro',
        'participant' => 'Participante',
    ];

    public function for(User $user, int $appId, bool $public = false): array
    {
        $roles = collect();

        $artists = Artist::query()
            ->where('app_id', $appId)
            ->where('user_id', $user->id)
            ->when($public, fn ($query) => $query->where('is_published', true))
            ->select(['id', 'slug', 'stage_name', 'artist_type', 'photo', 'cover', 'is_published'])
            ->orderByDesc('is_published')
            ->orderBy('stage_name')
            ->get();

        if ($artists->isNotEmpty()) {
            $this->addRole($roles, 'artist', 'artist_profile');
        }

        $productions = Production::query()
            ->where('app_id', $appId)
            ->where('user_id', $user->id)
            ->when($public, fn ($query) => $query->where('is_published', true)->where('is_cancelled', false))
            ->select(['id', 'name', 'slug', 'logo', 'background', 'is_published'])
            ->orderByDesc('is_published')
            ->orderBy('name')
            ->get();

        if ($productions->isNotEmpty() || (bool) $user->is_producer) {
            $this->addRole($roles, 'producer', $productions->isNotEmpty() ? 'production_owner' : 'legacy_flag');
        }

        $employmentRoles = $this->employmentRoles($user->id, $appId);
        foreach ($employmentRoles as $role) {
            $mapped = $this->normalizeRole($role);
            $this->addRole($roles, $mapped ?? 'staff', 'team_membership');
        }

        foreach ($this->applicationRoles($user->id, $appId) as $role) {
            if ($mapped = $this->normalizeRole($role)) {
                $this->addRole($roles, $mapped, 'application_membership');
            }
        }

        if ((bool) $user->is_promoter) {
            $this->addRole($roles, 'promoter', 'legacy_flag');
        }
        if ((bool) $user->is_ticket_seller) {
            $this->addRole($roles, 'ticket_seller', 'legacy_flag');
        }
        if ((bool) $user->is_partner) {
            $this->addRole($roles, 'partner', 'legacy_flag');
        }
        if ((bool) $user->is_participant || $roles->isEmpty()) {
            $this->addRole($roles, 'participant', 'participant');
        }

        $orderedRoles = $roles
            ->sortBy(fn (array $role) => self::ROLE_PRIORITY[$role['key']] ?? 999)
            ->values();

        $primary = $orderedRoles->first();

        return [
            'primary_role' => $primary['key'] ?? 'participant',
            'roles' => $orderedRoles->map(fn (array $role) => [
                'key' => $role['key'],
                'label' => self::ROLE_LABELS[$role['key']] ?? Str::headline($role['key']),
            ])->values()->all(),
            'artist_profiles' => $artists->map(fn (Artist $artist) => [
                'id' => $artist->id,
                'slug' => $artist->slug,
                'stage_name' => $artist->stage_name,
                'artist_type' => $artist->artist_type,
                'photo' => $artist->photo,
                'cover' => $artist->cover,
            ])->values()->all(),
            'productions' => $productions->map(fn (Production $production) => [
                'id' => $production->id,
                'name' => $production->name,
                'slug' => $production->slug,
                'logo' => $production->logo,
                'background' => $production->background,
            ])->values()->all(),
            'staff_titles' => $employmentRoles
                ->filter()
                ->map(fn ($role) => Str::headline((string) $role))
                ->unique()
                ->values()
                ->all(),
        ];
    }

    private function addRole(Collection $roles, string $key, string $source): void
    {
        if (! array_key_exists($key, self::ROLE_LABELS) || $roles->has($key)) {
            return;
        }

        $roles->put($key, ['key' => $key, 'source' => $source]);
    }

    private function employmentRoles(int $userId, int $appId): Collection
    {
        if (! Schema::hasTable('employers') || ! Schema::hasTable('establishments')) {
            return collect();
        }

        return Employer::query()
            ->where('user_id', $userId)
            ->whereHas('establishment', fn ($query) => $query->where('app_id', $appId))
            ->pluck('role')
            ->filter(fn ($role) => is_string($role) && trim($role) !== '')
            ->map(fn ($role) => trim((string) $role))
            ->unique(fn ($role) => mb_strtolower($role))
            ->values();
    }

    private function applicationRoles(int $userId, int $appId): Collection
    {
        if (! Schema::hasTable('application_user')) {
            return collect();
        }

        $membership = DB::table('application_user')
            ->where('application_id', $appId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->first(['role', 'metadata']);

        if (! $membership) {
            return collect();
        }

        $roles = collect([$membership->role]);
        $metadata = $membership->metadata ?? null;

        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        } elseif (! is_array($metadata)) {
            $metadata = [];
        }

        return $roles
            ->merge(is_array($metadata['roles'] ?? null) ? $metadata['roles'] : [])
            ->filter(fn ($role) => is_string($role) && trim($role) !== '')
            ->map(fn ($role) => trim((string) $role))
            ->unique(fn ($role) => mb_strtolower($role))
            ->values();
    }

    private function normalizeRole(?string $role): ?string
    {
        if (! $role) {
            return null;
        }

        $normalized = Str::of($role)
            ->lower()
            ->ascii()
            ->replace([' ', '-'], '_')
            ->value();

        return match ($normalized) {
            'artist', 'artista', 'dj', 'musician', 'musico', 'band', 'banda' => 'artist',
            'producer', 'produtor', 'production_owner', 'organizer', 'organizador', 'event_producer' => 'producer',
            'promoter', 'promotor', 'event_promoter' => 'promoter',
            'staff', 'crew', 'team', 'team_member', 'equipe', 'host', 'manager', 'moderator', 'moderador' => 'staff',
            'ticket_seller', 'seller', 'bilheteria', 'bilheteiro', 'vendedor_de_ingresso' => 'ticket_seller',
            'partner', 'parceiro', 'acquisition_agent', 'ambassador', 'embaixador' => 'partner',
            'participant', 'participante', 'attendee' => 'participant',
            default => null,
        };
    }
}
