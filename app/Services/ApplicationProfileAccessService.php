<?php

namespace App\Services;

use App\Models\ApplicationProfileAssignment;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Database\Eloquent\Builder;

class ApplicationProfileAccessService
{
    private ?array $definitions = null;

    public function profiles(
        User $user,
        ?int $applicationId = null,
        ?string $scopeType = null,
        ?int $scopeId = null,
    ) {
        $applicationId = $this->resolveApplicationId($applicationId);

        if (! $applicationId) {
            return collect();
        }

        $query = ApplicationProfileAssignment::query()
            ->where('application_id', $applicationId)
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->with('profile');

        $this->applyScope($query, $scopeType, $scopeId);

        return $query
            ->get()
            ->filter(fn (ApplicationProfileAssignment $assignment) => $assignment->profile !== null)
            ->values();
    }

    public function permissions(
        User $user,
        ?int $applicationId = null,
        ?string $scopeType = null,
        ?int $scopeId = null,
    ): array {
        return $this->profiles($user, $applicationId, $scopeType, $scopeId)
            ->flatMap(fn (ApplicationProfileAssignment $assignment) => (array) $assignment->profile->permissions)
            ->map(fn ($permission) => trim((string) $permission))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function hasPermission(
        User $user,
        string $permission,
        ?int $applicationId = null,
        ?string $scopeType = null,
        ?int $scopeId = null,
    ): bool {
        $permission = trim($permission);

        if ($permission === '') {
            return false;
        }

        $effective = $this->permissions($user, $applicationId, $scopeType, $scopeId);
        $candidates = array_values(array_unique(array_merge(
            [$permission],
            (array) ($this->definitions()['permission_aliases'][$permission] ?? []),
        )));

        return (bool) array_intersect($candidates, $effective);
    }

    public function hasProfile(
        User $user,
        string $profile,
        ?int $applicationId = null,
        ?string $scopeType = null,
        ?int $scopeId = null,
    ): bool {
        $needle = mb_strtolower(trim($profile));

        if ($needle === '') {
            return false;
        }

        return $this->profiles($user, $applicationId, $scopeType, $scopeId)
            ->contains(function (ApplicationProfileAssignment $assignment) use ($needle): bool {
                $profile = $assignment->profile;

                return mb_strtolower((string) $profile->slug) === $needle
                    || mb_strtolower((string) $profile->name) === $needle;
            });
    }

    public function context(
        User $user,
        ?int $applicationId = null,
        ?string $scopeType = null,
        ?int $scopeId = null,
    ): array {
        $resolvedApplicationId = $this->resolveApplicationId($applicationId);
        $assignments = $this->profiles($user, $resolvedApplicationId, $scopeType, $scopeId);

        return [
            'application_id' => $resolvedApplicationId,
            'scope' => $scopeType && $scopeId
                ? ['type' => $scopeType, 'id' => $scopeId]
                : ['type' => 'application', 'id' => 0],
            'profiles' => $assignments->map(fn (ApplicationProfileAssignment $assignment) => [
                'id' => (int) $assignment->profile->id,
                'slug' => (string) $assignment->profile->slug,
                'name' => (string) $assignment->profile->name,
                'description' => $assignment->profile->description,
                'scope_type' => (string) $assignment->scope_type,
                'scope_id' => (int) $assignment->scope_id,
                'permissions' => array_values((array) $assignment->profile->permissions),
            ])->values()->all(),
            'permissions' => $assignments
                ->flatMap(fn (ApplicationProfileAssignment $assignment) => (array) $assignment->profile->permissions)
                ->map(fn ($permission) => trim((string) $permission))
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ];
    }

    private function applyScope(Builder $query, ?string $scopeType, ?int $scopeId): void
    {
        $scopeType = trim((string) $scopeType);
        $scopeId = (int) ($scopeId ?? 0);

        if ($scopeType === '' || $scopeId <= 0) {
            $query
                ->where('scope_type', 'application')
                ->where('scope_id', 0);

            return;
        }

        $query->where(function (Builder $scopeQuery) use ($scopeType, $scopeId): void {
            $scopeQuery
                ->where(function (Builder $applicationScope): void {
                    $applicationScope
                        ->where('scope_type', 'application')
                        ->where('scope_id', 0);
                })
                ->orWhere(function (Builder $resourceScope) use ($scopeType, $scopeId): void {
                    $resourceScope
                        ->where('scope_type', $scopeType)
                        ->where('scope_id', $scopeId);
                });
        });
    }

    private function resolveApplicationId(?int $applicationId): ?int
    {
        if ($applicationId && $applicationId > 0) {
            return $applicationId;
        }

        try {
            $context = app(ApplicationContext::class);

            return $context->has() ? $context->id() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $path = config_path('application_profiles.php');

        return $this->definitions = is_file($path) ? (array) require $path : [];
    }
}
