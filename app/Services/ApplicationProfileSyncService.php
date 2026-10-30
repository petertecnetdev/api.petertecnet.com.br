<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ApplicationProfileSyncService
{
    private array $applicationIds = [];
    private array $profileIds = [];
    private int $profilesCreatedOrUpdated = 0;
    private int $assignmentsCreated = 0;

    public function sync(): array
    {
        if (! $this->tablesReady()) {
            return ['profiles_synced' => 0, 'assignments_created' => 0, 'applications' => []];
        }

        $this->resetState();
        $this->loadApplicationIds();
        $this->syncProfileCatalog();
        $this->loadProfileIds();
        $this->revokeSystemAssignments();
        $this->syncApplicationMemberships();
        $this->syncOwnedResources();
        $this->syncLegacyEventRoles();
        $this->syncArtists();
        $this->syncEmployments();
        $this->syncLegacyAdministrator();

        return $this->result();
    }

    public function syncUser(int $userId): array
    {
        if ($userId <= 0 || ! $this->tablesReady()) {
            return ['profiles_synced' => 0, 'assignments_created' => 0, 'applications' => []];
        }

        $this->resetState();
        $this->loadApplicationIds();
        $this->loadProfileIds();

        if ($this->profileIds === []) {
            $this->syncProfileCatalog();
            $this->loadProfileIds();
        }

        $this->revokeSystemAssignments($userId);
        $this->syncApplicationMemberships($userId);
        $this->syncOwnedResources($userId);
        $this->syncLegacyEventRoles($userId);
        $this->syncArtists($userId);
        $this->syncEmployments($userId);
        $this->syncLegacyAdministrator($userId);

        return $this->result();
    }

    private function tablesReady(): bool
    {
        return Schema::hasTable('applications')
            && Schema::hasTable('application_profiles')
            && Schema::hasTable('application_profile_assignments');
    }

    private function resetState(): void
    {
        $this->applicationIds = [];
        $this->profileIds = [];
        $this->profilesCreatedOrUpdated = 0;
        $this->assignmentsCreated = 0;
    }

    private function loadApplicationIds(): void
    {
        $this->applicationIds = DB::table('applications')
            ->pluck('id', 'slug')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function result(): array
    {
        return [
            'profiles_synced' => $this->profilesCreatedOrUpdated,
            'assignments_created' => $this->assignmentsCreated,
            'applications' => array_keys($this->profileIds),
        ];
    }

    private function definitions(): array
    {
        $path = config_path('application_profiles.php');

        return is_file($path) ? (array) require $path : [];
    }

    private function revokeSystemAssignments(?int $userId = null): void
    {
        DB::table('application_profile_assignments')
            ->whereIn('source', [
                'application_user',
                'resource_owner',
                'legacy_user_flag',
                'artist_ownership',
                'employment',
                'legacy_global_profile',
                'system_sync',
            ])
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->where(function ($query) {
                $query->where('status', '!=', 'revoked')
                    ->orWhereNull('revoked_at');
            })
            ->update([
                'status' => 'revoked',
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function syncProfileCatalog(): void
    {
        $definitions = (array) ($this->definitions()['applications'] ?? []);

        foreach ($definitions as $appSlug => $appDefinition) {
            $applicationId = $this->applicationIds[$appSlug] ?? null;
            if (! $applicationId) {
                continue;
            }

            foreach ((array) ($appDefinition['profiles'] ?? []) as $slug => $profile) {
                $key = [
                    'application_id' => $applicationId,
                    'slug' => $slug,
                ];

                $values = [
                    'name' => (string) ($profile['name'] ?? Str::headline($slug)),
                    'description' => $profile['description'] ?? null,
                    'permissions' => json_encode(array_values(array_unique((array) ($profile['permissions'] ?? []))), JSON_UNESCAPED_UNICODE),
                    'is_system' => true,
                    'updated_at' => now(),
                ];

                if (DB::table('application_profiles')->where($key)->exists()) {
                    DB::table('application_profiles')->where($key)->update($values);
                } else {
                    DB::table('application_profiles')->insert($key + $values + ['created_at' => now()]);
                }

                $this->profilesCreatedOrUpdated++;
            }
        }
    }

    private function loadProfileIds(): void
    {
        $rows = DB::table('application_profiles as p')
            ->join('applications as a', 'a.id', '=', 'p.application_id')
            ->select('a.slug as app_slug', 'p.slug as profile_slug', 'p.id')
            ->get();

        foreach ($rows as $row) {
            $this->profileIds[$row->app_slug][$row->profile_slug] = (int) $row->id;
        }
    }

    private function syncApplicationMemberships(?int $userId = null): void
    {
        if (! Schema::hasTable('application_user')) {
            return;
        }

        $maps = (array) config('application_profiles.legacy_membership_map', []);

        $rows = DB::table('application_user as au')
            ->join('applications as a', 'a.id', '=', 'au.application_id')
            ->where('au.status', 'active')
            ->when($userId, fn ($query) => $query->where('au.user_id', $userId))
            ->select('au.user_id', 'a.slug as app_slug', 'au.role')
            ->get();

        foreach ($rows as $row) {
            $appSlug = (string) $row->app_slug;
            $role = $this->normalizeRole($row->role);
            $profileSlug = $maps[$appSlug][$role] ?? null;

            if ($profileSlug) {
                $this->assign($appSlug, (int) $row->user_id, $profileSlug, 'application', 0, 'application_user', [
                    'legacy_role' => $row->role,
                ]);
            }
        }
    }

    private function syncOwnedResources(?int $userId = null): void
    {
        if (! Schema::hasTable('establishments')
            || ! Schema::hasColumn('establishments', 'app_id')
            || ! Schema::hasColumn('establishments', 'user_id')) {
            return;
        }

        $ownerProfileByApp = (array) config('application_profiles.resource_owner_map', []);

        $rows = DB::table('establishments as e')
            ->join('applications as a', 'a.id', '=', 'e.app_id')
            ->whereNotNull('e.user_id')
            ->when($userId, fn ($query) => $query->where('e.user_id', $userId))
            ->select('e.id', 'e.user_id', 'e.category', 'a.slug as app_slug')
            ->get();

        foreach ($rows as $row) {
            $appSlug = (string) $row->app_slug;

            if ($appSlug === (string) config('application_profiles.event_application_slug', '') && strtolower((string) $row->category) === 'production') {
                $this->assign($appSlug, (int) $row->user_id, 'producer', 'application', 0, 'resource_owner', [
                    'establishment_id' => (int) $row->id,
                    'resource_type' => 'production',
                ]);
                continue;
            }

            $profileSlug = $ownerProfileByApp[$appSlug] ?? null;
            if ($profileSlug) {
                $this->assign($appSlug, (int) $row->user_id, $profileSlug, 'establishment', (int) $row->id, 'resource_owner');
            }
        }
    }

    private function syncLegacyEventRoles(?int $userId = null): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $hasProducer = Schema::hasColumn('users', 'is_producer');
        $hasPromoter = Schema::hasColumn('users', 'is_promoter');

        if ($hasProducer) {
            DB::table('users')
                ->where('is_producer', true)
                ->when($userId, fn ($query) => $query->where('id', $userId))
                ->pluck('id')
                ->each(fn ($id) => $this->assign((string) config('application_profiles.event_application_slug', ''), (int) $id, 'producer', 'application', 0, 'legacy_user_flag'));
        }

        if ($hasPromoter) {
            DB::table('users')
                ->where('is_promoter', true)
                ->when($userId, fn ($query) => $query->where('id', $userId))
                ->pluck('id')
                ->each(fn ($id) => $this->assign((string) config('application_profiles.event_application_slug', ''), (int) $id, 'promoter', 'application', 0, 'legacy_user_flag'));
        }
    }

    private function syncArtists(?int $userId = null): void
    {
        if (! Schema::hasTable('artists')) {
            return;
        }

        $rows = DB::table('artists as ar')
            ->join('applications as a', 'a.id', '=', 'ar.app_id')
            ->whereNotNull('ar.user_id')
            ->where('a.slug', (string) config('application_profiles.event_application_slug', ''))
            ->when($userId, fn ($query) => $query->where('ar.user_id', $userId))
            ->select('ar.id', 'ar.user_id')
            ->get();

        foreach ($rows as $row) {
            $this->assign((string) config('application_profiles.event_application_slug', ''), (int) $row->user_id, 'artist', 'artist', (int) $row->id, 'artist_ownership');
        }
    }

    private function syncEmployments(?int $userId = null): void
    {
        if (! Schema::hasTable('employers') || ! Schema::hasTable('establishments')) {
            return;
        }

        $maps = (array) config('application_profiles.legacy_employment_map', []);

        $rows = DB::table('employers as em')
            ->join('establishments as e', 'e.id', '=', 'em.establishment_id')
            ->join('applications as a', 'a.id', '=', 'e.app_id')
            ->whereNotNull('em.user_id')
            ->when($userId, fn ($query) => $query->where('em.user_id', $userId))
            ->select('em.user_id', 'em.establishment_id', 'em.role', 'a.slug as app_slug')
            ->get();

        foreach ($rows as $row) {
            $appSlug = (string) $row->app_slug;
            $role = $this->normalizeRole($row->role);
            $profileSlug = $maps[$appSlug][$role] ?? null;

            if ($profileSlug) {
                $this->assign($appSlug, (int) $row->user_id, $profileSlug, 'establishment', (int) $row->establishment_id, 'employment', [
                    'legacy_role' => $row->role,
                ]);
            }
        }
    }

    private function syncLegacyAdministrator(?int $userId = null): void
    {
        if (! Schema::hasTable('profiles') || ! Schema::hasColumn('users', 'profile_id')) {
            return;
        }

        $adminProfileId = DB::table('profiles')->whereRaw('LOWER(name) = ?', ['administrador'])->value('id');
        if (! $adminProfileId) {
            return;
        }

        DB::table('users')
            ->where('profile_id', $adminProfileId)
            ->when($userId, fn ($query) => $query->where('id', $userId))
            ->pluck('id')
            ->each(fn ($id) => $this->assign((string) config('application_profiles.root_application_slug', ''), (int) $id, 'super_admin', 'application', 0, 'legacy_global_profile'));
    }

    private function assign(
        string $appSlug,
        int $userId,
        string $profileSlug,
        string $scopeType = 'application',
        int $scopeId = 0,
        string $source = 'system_sync',
        array $metadata = [],
    ): void {
        $applicationId = $this->applicationIds[$appSlug] ?? null;
        $profileId = $this->profileIds[$appSlug][$profileSlug] ?? null;

        if (! $applicationId || ! $profileId || $userId <= 0) {
            return;
        }

        $key = [
            'application_id' => $applicationId,
            'user_id' => $userId,
            'profile_id' => $profileId,
            'scope_type' => $scopeType ?: 'application',
            'scope_id' => max(0, $scopeId),
        ];

        $values = [
            'status' => 'active',
            'source' => $source,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'revoked_at' => null,
            'updated_at' => now(),
        ];

        $assignment = DB::table('application_profile_assignments')->where($key);

        if ($assignment->exists()) {
            $assignment->update($values);
        } else {
            DB::table('application_profile_assignments')->insert($key + $values + ['created_at' => now()]);
            $this->assignmentsCreated++;
        }
    }

    private function normalizeRole(?string $role): string
    {
        $normalized = Str::of((string) $role)
            ->ascii()
            ->lower()
            ->replace(['-', ' '], '_')
            ->replaceMatches('/[^a-z0-9_]/', '')
            ->toString();

        return trim($normalized, '_');
    }
}
