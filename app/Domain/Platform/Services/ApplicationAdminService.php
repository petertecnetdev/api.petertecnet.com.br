<?php

namespace App\Domain\Platform\Services;

use App\Models\ApplicationAdminAssignment;
use App\Models\ApplicationAdminAudit;
use App\Models\ApplicationAdminProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApplicationAdminService
{
    public const ROOT_ADMIN_EMAIL = ApplicationOwnerAuthorityService::BOOTSTRAP_OWNER_EMAIL;
    public const ACCESS_MANAGE_PERMISSION = 'admin.access.manage';

    public const PERMISSION_CATALOG = [
        'dashboard.view',
        'events.view',
        'events.manage',
        'establishments.view',
        'establishments.manage',
        'tickets.view',
        'tickets.manage',
        'checkin.view',
        'checkin.manage',
        'finance.view',
        'finance.refund',
        'users.view',
        'users.manage',
        'users.sessions.manage',
        'moderation.view',
        'moderation.manage',
        'support.view',
        'support.manage',
        'security.view',
        'security.manage',
        'operations.view',
        'audit.view',
    ];

    private const RESERVED_PERMISSIONS = [
        self::ACCESS_MANAGE_PERMISSION,
    ];

    public function __construct(private readonly ApplicationOwnerAuthorityService $ownerAuthority)
    {
    }

    /**
     * Legacy ecosystem root check. Application-level authority must use isOwner().
     */
    public function isRoot(User $user): bool
    {
        return $this->ownerAuthority->matchesBootstrapEmail($user);
    }

    public function isOwner(User $user, int $applicationId): bool
    {
        return $this->ownerAuthority->isOwner($user, $applicationId);
    }

    public function hasAccess(User $user, int $applicationId): bool
    {
        if ($this->isOwner($user, $applicationId)) {
            return true;
        }

        return ApplicationAdminAssignment::query()
            ->where('application_id', $applicationId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->exists();
    }

    public function hasPermission(User $user, int $applicationId, string $permission): bool
    {
        if ($this->isOwner($user, $applicationId)) {
            return true;
        }

        if (in_array($permission, self::RESERVED_PERMISSIONS, true)) {
            return false;
        }

        $assignment = $this->activeAssignment($user, $applicationId);
        if (! $assignment?->profile) {
            return false;
        }

        return in_array($permission, (array) $assignment->profile->permissions, true);
    }

    public function context(User $user, int $applicationId): array
    {
        if ($this->isOwner($user, $applicationId)) {
            return [
                'authorized' => true,
                'is_root' => true,
                'is_owner' => true,
                'authority_level' => 'owner',
                'scope' => 'global_application',
                'owner_user_id' => (int) $user->id,
                'root_email' => self::ROOT_ADMIN_EMAIL,
                'profile' => [
                    'id' => null,
                    'name' => 'Owner Peter Tecnet',
                    'slug' => 'application-owner',
                ],
                'permissions' => array_values(array_merge(self::PERMISSION_CATALOG, self::RESERVED_PERMISSIONS)),
            ];
        }

        $assignment = $this->activeAssignment($user, $applicationId);

        if (! $assignment?->profile) {
            return [
                'authorized' => false,
                'is_root' => false,
                'is_owner' => false,
                'authority_level' => 'none',
                'scope' => null,
                'owner_user_id' => $this->ownerAuthority->ownerId($applicationId),
                'root_email' => self::ROOT_ADMIN_EMAIL,
                'profile' => null,
                'permissions' => [],
            ];
        }

        return [
            'authorized' => true,
            'is_root' => false,
            'is_owner' => false,
            'authority_level' => 'delegated_admin',
            'scope' => 'global_application',
            'owner_user_id' => $this->ownerAuthority->ownerId($applicationId),
            'root_email' => self::ROOT_ADMIN_EMAIL,
            'profile' => [
                'id' => $assignment->profile->id,
                'name' => $assignment->profile->name,
                'slug' => $assignment->profile->slug,
                'description' => $assignment->profile->description,
            ],
            'permissions' => array_values(array_intersect(
                (array) $assignment->profile->permissions,
                self::PERMISSION_CATALOG,
            )),
        ];
    }

    public function permissionCatalog(): array
    {
        return [
            'permissions' => self::PERMISSION_CATALOG,
            'reserved' => self::RESERVED_PERMISSIONS,
            'owner_bootstrap_email' => self::ROOT_ADMIN_EMAIL,
        ];
    }

    public function profiles(int $applicationId, User $actor): Collection
    {
        $this->assertOwner($applicationId, $actor);

        return ApplicationAdminProfile::query()
            ->where('application_id', $applicationId)
            ->withCount(['assignments as active_assignments_count' => fn ($query) => $query
                ->where('status', 'active')
                ->whereNull('revoked_at')])
            ->orderBy('name')
            ->get();
    }

    public function createProfile(int $applicationId, User $actor, array $data, array $auditContext = []): ApplicationAdminProfile
    {
        $this->assertOwner($applicationId, $actor);

        $name = trim((string) ($data['name'] ?? ''));
        $slug = Str::slug((string) ($data['slug'] ?? $name));

        if ($name === '' || $slug === '') {
            throw ValidationException::withMessages(['name' => 'Informe um nome válido para o perfil.']);
        }

        if (ApplicationAdminProfile::query()->where('application_id', $applicationId)->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['name' => 'Já existe um perfil administrativo com esse identificador.']);
        }

        $profile = ApplicationAdminProfile::query()->create([
            'application_id' => $applicationId,
            'name' => $name,
            'slug' => $slug,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'permissions' => $this->normalizePermissions((array) ($data['permissions'] ?? [])),
            'is_system' => false,
            'created_by_user_id' => $actor->id,
        ]);

        $this->recordAudit($applicationId, $actor, null, 'admin_profile_created', [
            'profile_id' => $profile->id,
            'profile_slug' => $profile->slug,
            'permissions' => $profile->permissions,
        ], $auditContext);

        return $profile;
    }

    public function updateProfile(int $applicationId, int $profileId, User $actor, array $data, array $auditContext = []): ApplicationAdminProfile
    {
        $this->assertOwner($applicationId, $actor);
        $profile = $this->findProfile($applicationId, $profileId);

        $before = Arr::only($profile->toArray(), ['name', 'slug', 'description', 'permissions']);
        $name = array_key_exists('name', $data) ? trim((string) $data['name']) : $profile->name;
        $slug = array_key_exists('slug', $data) ? Str::slug((string) $data['slug']) : $profile->slug;

        if ($name === '' || $slug === '') {
            throw ValidationException::withMessages(['name' => 'Informe um nome válido para o perfil.']);
        }

        $duplicate = ApplicationAdminProfile::query()
            ->where('application_id', $applicationId)
            ->where('slug', $slug)
            ->whereKeyNot($profile->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['name' => 'Já existe um perfil administrativo com esse identificador.']);
        }

        $profile->fill([
            'name' => $name,
            'slug' => $slug,
            'description' => array_key_exists('description', $data)
                ? (trim((string) $data['description']) ?: null)
                : $profile->description,
            'permissions' => array_key_exists('permissions', $data)
                ? $this->normalizePermissions((array) $data['permissions'])
                : $profile->permissions,
        ])->save();

        $this->recordAudit($applicationId, $actor, null, 'admin_profile_updated', [
            'profile_id' => $profile->id,
            'before' => $before,
            'after' => Arr::only($profile->fresh()->toArray(), ['name', 'slug', 'description', 'permissions']),
        ], $auditContext);

        return $profile->fresh();
    }

    public function deleteProfile(int $applicationId, int $profileId, User $actor, array $auditContext = []): void
    {
        $this->assertOwner($applicationId, $actor);
        $profile = $this->findProfile($applicationId, $profileId);

        $activeAssignments = ApplicationAdminAssignment::query()
            ->where('application_id', $applicationId)
            ->where('profile_id', $profile->id)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->exists();

        if ($activeAssignments) {
            throw ValidationException::withMessages([
                'profile' => 'Revogue ou altere os administradores vinculados antes de excluir este perfil.',
            ]);
        }

        $snapshot = Arr::only($profile->toArray(), ['id', 'name', 'slug', 'permissions']);
        $profile->delete();

        $this->recordAudit($applicationId, $actor, null, 'admin_profile_deleted', $snapshot, $auditContext);
    }

    public function assignments(int $applicationId, User $actor): Collection
    {
        $this->assertOwner($applicationId, $actor);

        return ApplicationAdminAssignment::query()
            ->where('application_id', $applicationId)
            ->with([
                'user:id,first_name,last_name,email',
                'profile:id,application_id,name,slug,description,permissions',
                'grantedBy:id,first_name,last_name,email',
            ])
            ->orderByRaw("CASE WHEN status = 'active' AND revoked_at IS NULL THEN 0 ELSE 1 END")
            ->latest('updated_at')
            ->get();
    }

    public function assign(int $applicationId, User $actor, string $email, int $profileId, array $auditContext = []): ApplicationAdminAssignment
    {
        $this->assertOwner($applicationId, $actor);
        $normalizedEmail = strtolower(trim($email));

        if ($normalizedEmail === '') {
            throw ValidationException::withMessages(['email' => 'Selecione outro usuário para receber o perfil administrativo.']);
        }

        $target = User::query()->whereRaw('LOWER(email) = ?', [$normalizedEmail])->first();
        if (! $target) {
            throw ValidationException::withMessages(['email' => 'Usuário não encontrado.']);
        }
        if ($this->isOwner($target, $applicationId) || $normalizedEmail === self::ROOT_ADMIN_EMAIL) {
            throw ValidationException::withMessages(['email' => 'A identidade Owner não pode receber, perder ou trocar perfil delegado.']);
        }

        $profile = $this->findProfile($applicationId, $profileId);

        $assignment = ApplicationAdminAssignment::query()->updateOrCreate(
            ['application_id' => $applicationId, 'user_id' => $target->id],
            [
                'profile_id' => $profile->id,
                'granted_by_user_id' => $actor->id,
                'status' => 'active',
                'revoked_at' => null,
            ],
        );

        $this->recordAudit($applicationId, $actor, $target, 'admin_access_granted', [
            'assignment_id' => $assignment->id,
            'profile_id' => $profile->id,
            'profile_slug' => $profile->slug,
        ], $auditContext);

        return $assignment->load([
            'user:id,first_name,last_name,email',
            'profile:id,application_id,name,slug,description,permissions',
            'grantedBy:id,first_name,last_name,email',
        ]);
    }

    public function revoke(int $applicationId, int $assignmentId, User $actor, array $auditContext = []): ApplicationAdminAssignment
    {
        $this->assertOwner($applicationId, $actor);

        $assignment = ApplicationAdminAssignment::query()
            ->where('application_id', $applicationId)
            ->whereKey($assignmentId)
            ->firstOrFail();

        $target = User::query()->find($assignment->user_id);
        if ($target && $this->isOwner($target, $applicationId)) {
            throw new AuthorizationException('A identidade Owner é imutável e não pode ter o acesso revogado.');
        }

        $assignment->forceFill([
            'status' => 'revoked',
            'revoked_at' => now(),
        ])->save();

        $this->recordAudit($applicationId, $actor, $target, 'admin_access_revoked', [
            'assignment_id' => $assignment->id,
            'profile_id' => $assignment->profile_id,
        ], $auditContext);

        return $assignment->fresh()->load([
            'user:id,first_name,last_name,email',
            'profile:id,application_id,name,slug,description,permissions',
            'grantedBy:id,first_name,last_name,email',
        ]);
    }

    public function audit(int $applicationId, User $actor, int $perPage = 50): LengthAwarePaginator
    {
        if (! $this->hasPermission($actor, $applicationId, 'audit.view')) {
            throw new AuthorizationException('Você não possui permissão para consultar a auditoria administrativa.');
        }

        return ApplicationAdminAudit::query()
            ->where('application_id', $applicationId)
            ->with([
                'actor:id,first_name,last_name,email',
                'target:id,first_name,last_name,email',
            ])
            ->latest('id')
            ->paginate(max(1, min($perPage, 100)));
    }

    public function auditAction(
        int $applicationId,
        User $actor,
        ?User $target,
        string $action,
        array $metadata = [],
        array $auditContext = [],
    ): void {
        $this->recordAudit($applicationId, $actor, $target, $action, $metadata, $auditContext);
    }

    public function assertOwnerAuthority(int $applicationId, User $actor): void
    {
        $this->assertOwner($applicationId, $actor);
    }

    private function activeAssignment(User $user, int $applicationId): ?ApplicationAdminAssignment
    {
        return ApplicationAdminAssignment::query()
            ->where('application_id', $applicationId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->with('profile')
            ->first();
    }

    private function findProfile(int $applicationId, int $profileId): ApplicationAdminProfile
    {
        return ApplicationAdminProfile::query()
            ->where('application_id', $applicationId)
            ->whereKey($profileId)
            ->firstOrFail();
    }

    private function normalizePermissions(array $permissions): array
    {
        $permissions = array_values(array_unique(array_filter(array_map(
            static fn ($permission) => trim((string) $permission),
            $permissions,
        ))));

        $unknown = array_values(array_diff($permissions, self::PERMISSION_CATALOG));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'permissions' => 'Permissões inválidas: '.implode(', ', $unknown),
            ]);
        }

        return $permissions;
    }

    private function assertOwner(int $applicationId, User $actor): void
    {
        if (! $this->isOwner($actor, $applicationId)) {
            throw new AuthorizationException('Somente o Owner da aplicação pode gerenciar perfis e privilégios administrativos.');
        }
    }

    private function recordAudit(
        int $applicationId,
        User $actor,
        ?User $target,
        string $action,
        array $metadata,
        array $auditContext,
    ): void {
        ApplicationAdminAudit::query()->create([
            'application_id' => $applicationId,
            'actor_user_id' => $actor->id,
            'target_user_id' => $target?->id,
            'action' => $action,
            'metadata' => $metadata,
            'request_id' => $auditContext['request_id'] ?? null,
            'ip_address' => $auditContext['ip_address'] ?? null,
            'user_agent' => isset($auditContext['user_agent'])
                ? Str::limit((string) $auditContext['user_agent'], 1000, '')
                : null,
        ]);
    }
}
