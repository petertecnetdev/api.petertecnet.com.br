<?php

namespace App\Domain\Identity\Services;

use App\Models\ApplicationAccess;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Arr;

final class ApplicationAccessService
{
    public function grantUser(int $applicationId, int $userId, string $role = 'member', array $scopes = ['profile.read']): ApplicationAccess
    {
        return ApplicationAccess::query()->updateOrCreate(
            [
                'application_id' => $applicationId,
                'user_id' => $userId,
                'organization_id' => null,
            ],
            [
                'role' => $role,
                'scopes' => array_values(array_unique($scopes)),
                'status' => 'active',
                'granted_at' => now(),
            ]
        );
    }

    public function scopesForUser(User $user, int $applicationId, ?int $organizationId = null): array
    {
        if (method_exists($user, 'hasProfile') && $user->hasProfile('Administrador')) {
            return ['*'];
        }

        $scopes = ApplicationAccess::query()
            ->where('application_id', $applicationId)
            ->where('status', 'active')
            ->where(function ($query) use ($user, $organizationId) {
                $query->where('user_id', $user->id);
                if ($organizationId) {
                    $query->orWhere('organization_id', $organizationId);
                }
            })
            ->get()
            ->flatMap(fn (ApplicationAccess $access) => $access->scopes ?? [])
            ->filter()
            ->values()
            ->all();

        if ($organizationId) {
            $organizationScopes = OrganizationMembership::query()
                ->where('organization_id', $organizationId)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->get()
                ->flatMap(fn (OrganizationMembership $membership) => $membership->scopes ?? [])
                ->filter()
                ->values()
                ->all();
            $scopes = array_merge($scopes, $organizationScopes);
        }

        // Compatibility bridge: old application_user memberships remain valid
        // while they are progressively copied into application_accesses.
        $legacyMembership = $user->applications()
            ->where('applications.id', $applicationId)
            ->wherePivot('status', 'active')
            ->first();

        if ($legacyMembership) {
            $role = (string) ($legacyMembership->pivot->role ?: 'member');
            $scopes = array_merge($scopes, $this->defaultScopesForRole($role));
        }

        return array_values(array_unique($scopes));
    }

    public function allows(User $user, int $applicationId, string $requiredScope, ?int $organizationId = null): bool
    {
        foreach ($this->scopesForUser($user, $applicationId, $organizationId) as $scope) {
            if ($scope === '*' || $scope === $requiredScope) {
                return true;
            }

            if (str_ends_with($scope, '.*') && str_starts_with($requiredScope, substr($scope, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    private function defaultScopesForRole(string $role): array
    {
        $map = config('platform.role_scopes', []);
        return Arr::wrap($map[$role] ?? $map['member'] ?? ['profile.read']);
    }
}
