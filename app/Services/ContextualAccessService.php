<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\Party;
use App\Models\ResourceRef;
use App\Models\ResourceRelationship;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class ContextualAccessService
{
    public function snapshot(User $user, ?int $applicationId = null): array
    {
        $assignments = RoleAssignment::query()
            ->active()
            ->with([
                'role.permissions',
                'application:id,name,slug',
                'establishment:id,name,fantasy,slug,app_id',
                'resourceRef:id,uuid,application_id,establishment_id,resource_type,resource_id,label',
            ])
            ->where('user_id', $user->id)
            ->when($applicationId, fn (Builder $q) => $q->where(function (Builder $scope) use ($applicationId) {
                $scope->where('application_id', $applicationId)
                    ->orWhere(function (Builder $global) {
                        $global->whereNull('application_id')
                            ->whereNull('establishment_id')
                            ->whereNull('resource_ref_id')
                            ->whereNull('resource_type')
                            ->whereNull('resource_id');
                    });
            }))
            ->orderByRaw('application_id is null desc')
            ->orderBy('establishment_id')
            ->get();

        $memberships = Membership::query()
            ->active()
            ->with('establishment:id,name,fantasy,slug,app_id')
            ->where('user_id', $user->id)
            ->when($applicationId, fn (Builder $q) => $q->whereHas('establishment', fn (Builder $establishment) =>
                $establishment->forApplication($applicationId)))
            ->get();

        $relationshipLimit = max(1, (int) config('contextual_access.snapshot_relationship_limit', 50));
        $relationshipQuery = $this->relationshipQuery($user, $applicationId);
        $relationshipTotal = (clone $relationshipQuery)->count();
        $relationships = $relationshipQuery
            ->with(['application:id,name,slug', 'resourceRef:id,uuid,application_id,establishment_id,resource_type,resource_id,label'])
            ->latest('id')
            ->limit($relationshipLimit)
            ->get();

        return [
            'roles' => $assignments->map(fn (RoleAssignment $assignment) => [
                'assignment_id' => $assignment->id,
                'code' => $assignment->role->code,
                'name' => $assignment->role->name,
                'application' => $assignment->application,
                'establishment' => $assignment->establishment,
                'resource' => $assignment->resourceRef,
                'resource_type' => $assignment->resourceRef?->resource_type ?? $assignment->resource_type,
                'resource_id' => $assignment->resourceRef?->resource_id ?? $assignment->resource_id,
                'context_key' => $assignment->context_key,
                'starts_at' => $assignment->starts_at,
                'expires_at' => $assignment->expires_at,
                'permissions' => $assignment->role->permissions->pluck('code')->values(),
            ])->values(),
            'memberships' => $memberships,
            'relationships' => $relationships->map(fn (ResourceRelationship $relationship) => $this->relationshipPayload($relationship))->values(),
            'relationship_summary' => [
                'total' => $relationshipTotal,
                'returned' => $relationships->count(),
                'truncated' => $relationshipTotal > $relationships->count(),
            ],
        ];
    }

    public function relationshipsPage(User $user, ?int $applicationId, array $filters = []): LengthAwarePaginator
    {
        $query = $this->relationshipQuery($user, $applicationId)
            ->with(['application:id,name,slug', 'resourceRef:id,uuid,application_id,establishment_id,resource_type,resource_id,label']);

        if (! empty($filters['resource_uuid'])) {
            $query->whereHas('resourceRef', fn (Builder $q) => $q->where('uuid', $filters['resource_uuid']));
        }
        if (! empty($filters['resource_type'])) {
            $type = strtolower(trim((string) $filters['resource_type']));
            $query->where(function (Builder $q) use ($type) {
                $q->where('resource_type', $type)
                    ->orWhereHas('resourceRef', fn (Builder $resource) => $resource->where('resource_type', $type));
            });
        }
        if (! empty($filters['resource_id'])) {
            $id = (int) $filters['resource_id'];
            $query->where(function (Builder $q) use ($id) {
                $q->where('resource_id', $id)
                    ->orWhereHas('resourceRef', fn (Builder $resource) => $resource->where('resource_id', $id));
            });
        }
        if (! empty($filters['relationship_type'])) {
            $query->where('relationship_type', strtolower(trim((string) $filters['relationship_type'])));
        }

        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 25)));
        return $query->latest('id')->paginate($perPage);
    }

    public function hasPermission(
        User $user,
        string $permission,
        ?int $applicationId = null,
        ?int $establishmentId = null,
        ?string $resourceType = null,
        ?int $resourceId = null,
        ?string $resourceUuid = null,
    ): bool {
        $resource = $resourceUuid
            ? ResourceRef::query()->active()->where('uuid', $resourceUuid)->first()
            : null;

        if ($resource) {
            if ($applicationId !== null && (int) $resource->application_id !== (int) $applicationId) return false;
            if ($establishmentId !== null && $resource->establishment_id && (int) $resource->establishment_id !== (int) $establishmentId) return false;
            $applicationId = (int) $resource->application_id;
            $establishmentId ??= $resource->establishment_id ? (int) $resource->establishment_id : null;
            $resourceType = $resource->resource_type;
            $resourceId = (int) $resource->resource_id;
        }

        return RoleAssignment::query()
            ->active()
            ->where('user_id', $user->id)
            ->whereHas('role.permissions', fn (Builder $q) => $q->where('permissions.code', $permission))
            ->where(function (Builder $q) use ($applicationId) {
                if ($applicationId === null) {
                    $q->whereNull('application_id');
                    return;
                }

                $q->where('application_id', $applicationId)
                    ->orWhere(function (Builder $global) {
                        $global->whereNull('application_id')
                            ->whereNull('establishment_id')
                            ->whereNull('resource_ref_id')
                            ->whereNull('resource_type')
                            ->whereNull('resource_id');
                    });
            })
            ->where(function (Builder $q) use ($establishmentId) {
                $q->whereNull('establishment_id');
                if ($establishmentId !== null) $q->orWhere('establishment_id', $establishmentId);
            })
            ->where(function (Builder $q) use ($resource, $resourceType, $resourceId) {
                $q->where(function (Builder $generic) {
                    $generic->whereNull('resource_ref_id')->whereNull('resource_type')->whereNull('resource_id');
                });

                if ($resource) $q->orWhere('resource_ref_id', $resource->id);
                if ($resourceType !== null && $resourceId !== null) {
                    $q->orWhere(function (Builder $legacyResource) use ($resourceType, $resourceId) {
                        $legacyResource->whereNull('resource_ref_id')
                            ->where('resource_type', strtolower(trim($resourceType)))
                            ->where('resource_id', $resourceId);
                    });
                }
            })
            ->exists();
    }

    public function canForResource(User $user, string $permission, ResourceRef $resource): bool
    {
        if ($this->hasPermission(
            $user,
            $permission,
            (int) $resource->application_id,
            $resource->establishment_id ? (int) $resource->establishment_id : null,
            $resource->resource_type,
            (int) $resource->resource_id,
            $resource->uuid,
        )) return true;

        $relationships = $this->relationshipQuery($user, (int) $resource->application_id)
            ->where(function (Builder $q) use ($resource) {
                $q->where('resource_ref_id', $resource->id)
                    ->orWhere(function (Builder $legacy) use ($resource) {
                        $legacy->whereNull('resource_ref_id')
                            ->where('resource_type', $resource->resource_type)
                            ->where('resource_id', $resource->resource_id);
                    });
            })
            ->pluck('relationship_type');

        $policy = config('contextual_access.relationship_permissions', []);
        return $relationships->contains(function (string $relationship) use ($policy, $resource, $permission) {
            return in_array($permission, $policy[$relationship][$resource->resource_type] ?? [], true);
        });
    }

    public function hasRelationship(
        User $user,
        string $relationshipType,
        string $resourceType,
        int $resourceId,
        ?int $applicationId = null,
        ?string $resourceUuid = null,
    ): bool {
        $query = $this->relationshipQuery($user, $applicationId)
            ->where('relationship_type', strtolower(trim($relationshipType)));

        if ($resourceUuid) {
            return $query->whereHas('resourceRef', fn (Builder $q) => $q->where('uuid', $resourceUuid))->exists();
        }

        return $query->where(function (Builder $q) use ($resourceType, $resourceId) {
            $q->where(function (Builder $legacy) use ($resourceType, $resourceId) {
                $legacy->where('resource_type', strtolower(trim($resourceType)))->where('resource_id', $resourceId);
            })->orWhereHas('resourceRef', fn (Builder $resource) => $resource
                ->where('resource_type', strtolower(trim($resourceType)))
                ->where('resource_id', $resourceId));
        })->exists();
    }

    public function relationshipQuery(User $user, ?int $applicationId = null): Builder
    {
        $partyIds = Party::query()->where('user_id', $user->id)->pluck('id')
            ->merge(
                Party::query()
                    ->whereHas('users', fn (Builder $q) => $q->where('users.id', $user->id)->where('party_users.status', 'active'))
                    ->pluck('id')
            )
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values();

        return ResourceRelationship::query()
            ->active()
            ->where(function (Builder $q) use ($user, $partyIds) {
                $q->where(function (Builder $subject) use ($user) {
                    $subject->where('subject_type', 'user')->where('subject_id', $user->id);
                });
                if ($partyIds->isNotEmpty()) {
                    $q->orWhere(function (Builder $subject) use ($partyIds) {
                        $subject->where('subject_type', 'party')->whereIn('subject_id', $partyIds);
                    });
                }
            })
            ->when($applicationId !== null, fn (Builder $q) => $q->where('application_id', $applicationId));
    }

    private function relationshipPayload(ResourceRelationship $relationship): array
    {
        return [
            'id' => $relationship->id,
            'type' => $relationship->relationship_type,
            'application' => $relationship->application,
            'resource' => $relationship->resourceRef,
            'resource_type' => $relationship->resourceRef?->resource_type ?? $relationship->resource_type,
            'resource_id' => $relationship->resourceRef?->resource_id ?? $relationship->resource_id,
            'starts_at' => $relationship->starts_at,
            'ends_at' => $relationship->ends_at,
            'metadata' => $relationship->metadata,
        ];
    }
}
