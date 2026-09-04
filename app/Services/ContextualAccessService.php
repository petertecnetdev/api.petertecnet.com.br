<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\Party;
use App\Models\ResourceRelationship;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ContextualAccessService
{
    public function snapshot(User $user, ?int $applicationId = null): array
    {
        $assignments = RoleAssignment::query()
            ->active()
            ->with(['role.permissions', 'application:id,name,slug', 'establishment:id,name,fantasy,slug,app_id'])
            ->where('user_id', $user->id)
            ->when($applicationId, fn (Builder $q) => $q->where(fn (Builder $scope) => $scope
                ->whereNull('application_id')
                ->orWhere('application_id', $applicationId)))
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

        $partyId = Party::query()->where('user_id', $user->id)->value('id');
        $subjectIds = [['type' => 'user', 'id' => $user->id]];
        if ($partyId) {
            $subjectIds[] = ['type' => 'party', 'id' => (int) $partyId];
        }

        $relationships = ResourceRelationship::query()
            ->active()
            ->with('application:id,name,slug')
            ->where(function (Builder $q) use ($subjectIds) {
                foreach ($subjectIds as $index => $subject) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $q->{$method}(function (Builder $subjectQuery) use ($subject) {
                        $subjectQuery->where('subject_type', $subject['type'])
                            ->where('subject_id', $subject['id']);
                    });
                }
            })
            ->when($applicationId, fn (Builder $q) => $q->where(fn (Builder $scope) => $scope
                ->whereNull('application_id')
                ->orWhere('application_id', $applicationId)))
            ->orderBy('resource_type')
            ->orderBy('resource_id')
            ->get();

        return [
            'roles' => $assignments->map(fn (RoleAssignment $assignment) => [
                'assignment_id' => $assignment->id,
                'code' => $assignment->role->code,
                'name' => $assignment->role->name,
                'application' => $assignment->application,
                'establishment' => $assignment->establishment,
                'resource_type' => $assignment->resource_type,
                'resource_id' => $assignment->resource_id,
                'context_key' => $assignment->context_key,
                'starts_at' => $assignment->starts_at,
                'expires_at' => $assignment->expires_at,
                'permissions' => $assignment->role->permissions->pluck('code')->values(),
            ])->values(),
            'memberships' => $memberships,
            'relationships' => $relationships->map(fn (ResourceRelationship $relationship) => [
                'id' => $relationship->id,
                'type' => $relationship->relationship_type,
                'application' => $relationship->application,
                'resource_type' => $relationship->resource_type,
                'resource_id' => $relationship->resource_id,
                'starts_at' => $relationship->starts_at,
                'ends_at' => $relationship->ends_at,
                'metadata' => $relationship->metadata,
            ])->values(),
        ];
    }

    public function hasPermission(
        User $user,
        string $permission,
        ?int $applicationId = null,
        ?int $establishmentId = null,
        ?string $resourceType = null,
        ?int $resourceId = null,
    ): bool {
        return RoleAssignment::query()
            ->active()
            ->where('user_id', $user->id)
            ->whereHas('role.permissions', fn (Builder $q) => $q->where('permissions.code', $permission))
            ->where(function (Builder $q) use ($applicationId) {
                $q->whereNull('application_id');
                if ($applicationId !== null) {
                    $q->orWhere('application_id', $applicationId);
                }
            })
            ->where(function (Builder $q) use ($establishmentId) {
                $q->whereNull('establishment_id');
                if ($establishmentId !== null) {
                    $q->orWhere('establishment_id', $establishmentId);
                }
            })
            ->where(function (Builder $q) use ($resourceType, $resourceId) {
                $q->where(function (Builder $generic) {
                    $generic->whereNull('resource_type')->whereNull('resource_id');
                });

                if ($resourceType !== null && $resourceId !== null) {
                    $q->orWhere(function (Builder $resource) use ($resourceType, $resourceId) {
                        $resource->where('resource_type', $resourceType)->where('resource_id', $resourceId);
                    });
                }
            })
            ->exists();
    }

    public function hasRelationship(
        User $user,
        string $relationshipType,
        string $resourceType,
        int $resourceId,
        ?int $applicationId = null,
    ): bool {
        $partyId = Party::query()->where('user_id', $user->id)->value('id');

        return ResourceRelationship::query()
            ->active()
            ->where('relationship_type', $relationshipType)
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId)
            ->when($applicationId, fn (Builder $q) => $q->where(fn (Builder $scope) => $scope
                ->whereNull('application_id')
                ->orWhere('application_id', $applicationId)))
            ->where(function (Builder $q) use ($user, $partyId) {
                $q->where(fn (Builder $subject) => $subject
                    ->where('subject_type', 'user')
                    ->where('subject_id', $user->id));

                if ($partyId) {
                    $q->orWhere(fn (Builder $subject) => $subject
                        ->where('subject_type', 'party')
                        ->where('subject_id', $partyId));
                }
            })
            ->exists();
    }
}
