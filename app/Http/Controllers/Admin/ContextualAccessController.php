<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\RelationshipType;
use App\Models\ResourceRelationship;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\AccessAuditService;
use App\Services\ContextualAccessService;
use App\Services\ContextualStepUpService;
use App\Services\ResourceRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContextualAccessController extends Controller
{
    public function __construct(
        private readonly ContextualAccessService $access,
        private readonly ResourceRegistryService $resources,
        private readonly AccessAuditService $audit,
        private readonly ContextualStepUpService $stepUp,
    ) {
    }

    public function catalog(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'roles' => Role::query()->with('permissions:id,code,name')->orderBy('name')->get(),
            'relationship_types' => RelationshipType::query()->active()->orderBy('name')->get(['id', 'code', 'name', 'category', 'aliases']),
        ]);
    }

    public function resources(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'application_id' => ['required', 'integer', 'exists:applications,id'],
            'resource_type' => ['nullable', 'string', 'max:120'],
            'search' => ['nullable', 'string', 'max:160'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->resources->query(
            (int) $data['application_id'],
            $data['resource_type'] ?? null,
            $data['search'] ?? null,
        )->orderBy('label')->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 50));

        return response()->json($page);
    }

    public function stepUp(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate(['password' => ['required', 'string', 'max:255']]);
        $token = $this->stepUp->issue($request->user(), $data['password']);

        $this->audit->record($request, 'ACCESS_STEP_UP', 'user', $request->user()->id, null, ['confirmed' => true]);

        return response()->json(['step_up_token' => $token, 'expires_in' => 300]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);
        $applicationId = $request->integer('application_id') ?: null;

        return response()->json([
            'user' => $user->only(['id', 'first_name', 'last_name', 'user_name', 'email']),
            'access' => $this->access->snapshot($user, $applicationId),
            'legacy' => [
                'profile_id' => $user->profile_id,
                'profile' => $user->profile?->only(['id', 'name']),
                'application_roles' => $user->applications()->get()->map(fn ($application) => [
                    'application_id' => $application->id,
                    'application' => $application->name,
                    'role' => $application->pivot->role,
                    'status' => $application->pivot->status,
                ]),
            ],
        ]);
    }

    public function assignRole(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'application_id' => ['nullable', 'integer', 'exists:applications,id'],
            'establishment_id' => ['nullable', 'integer', 'exists:establishments,id'],
            'resource_uuid' => ['nullable', 'uuid', 'exists:resource_refs,uuid'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
            'metadata' => ['nullable', 'array'],
        ]);

        $role = Role::query()->with('permissions:id,code')->findOrFail($data['role_id']);
        if ($role->code === 'super_admin') $this->requireStepUp($request);

        $resource = ! empty($data['resource_uuid']) ? $this->resources->findActive($data['resource_uuid']) : null;
        if ($resource) {
            $data['application_id'] = (int) $resource->application_id;
            if ($resource->establishment_id) $data['establishment_id'] = (int) $resource->establishment_id;
        }

        $this->validateContextConsistency($data, $resource);

        $contextKey = RoleAssignment::contextKey(
            $data['application_id'] ?? null,
            $data['establishment_id'] ?? null,
            $resource?->resource_type,
            $resource?->resource_id,
            $resource?->uuid,
        );

        $before = RoleAssignment::query()
            ->where('user_id', $user->id)
            ->where('role_id', $role->id)
            ->where('context_key', $contextKey)
            ->first()?->toArray();

        $assignment = RoleAssignment::query()->updateOrCreate(
            ['user_id' => $user->id, 'role_id' => $role->id, 'context_key' => $contextKey],
            [
                'application_id' => $data['application_id'] ?? null,
                'establishment_id' => $data['establishment_id'] ?? null,
                'resource_ref_id' => $resource?->id,
                'resource_type' => $resource?->resource_type,
                'resource_id' => $resource?->resource_id,
                'status' => 'active',
                'starts_at' => $data['starts_at'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
                'metadata' => $data['metadata'] ?? null,
                'assigned_by' => $request->user()->id,
            ],
        );

        $this->audit->record(
            $request,
            'ACCESS_ROLE_ASSIGNED',
            'role_assignment',
            $assignment->id,
            $before,
            $assignment->fresh()->toArray(),
            $assignment->application_id,
            $assignment->establishment_id,
            ['target_user_id' => $user->id, 'role_code' => $role->code],
        );

        return response()->json(['assignment' => $assignment->load(['role.permissions', 'application', 'establishment', 'resourceRef'])], 201);
    }

    public function revokeRole(Request $request, User $user, RoleAssignment $assignment): JsonResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($assignment->user_id === $user->id, 404);
        $assignment->load('role.permissions');

        if ($assignment->role->code === 'super_admin') {
            $this->requireStepUp($request);
            abort_if($this->activeRoleCount('super_admin') <= 1, 422, 'O último superadministrador não pode ser revogado.');
        }

        if ($assignment->role->code === 'owner' && $assignment->establishment_id) {
            abort_if($this->activeOwnerCount((int) $assignment->establishment_id) <= 1, 422, 'O último proprietário do estabelecimento não pode ser revogado.');
        }

        if ((int) $request->user()->id === (int) $user->id && $assignment->role->permissions->contains('code', 'ecosystem.manage')) {
            abort_if($this->activeEcosystemManagerCount($user->id) <= 1, 422, 'Você não pode remover seu último acesso administrativo ao ecossistema.');
        }

        $before = $assignment->toArray();
        $assignment->update([
            'status' => 'revoked',
            'expires_at' => $assignment->expires_at ?? now(),
            'metadata' => array_merge($assignment->metadata ?? [], [
                'revoked_by' => $request->user()->id,
                'revoked_at' => now()->toIso8601String(),
            ]),
        ]);

        $this->audit->record(
            $request,
            'ACCESS_ROLE_REVOKED',
            'role_assignment',
            $assignment->id,
            $before,
            $assignment->fresh()->toArray(),
            $assignment->application_id,
            $assignment->establishment_id,
            ['target_user_id' => $user->id, 'role_code' => $assignment->role->code],
        );

        return response()->json(['assignment' => $assignment]);
    }

    public function upsertMembership(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'establishment_id' => ['required', 'integer', 'exists:establishments,id'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'metadata' => ['nullable', 'array'],
        ]);

        $existing = Membership::query()->where('user_id', $user->id)->where('establishment_id', $data['establishment_id'])->first();
        $before = $existing?->toArray();
        $membership = Membership::query()->updateOrCreate(
            ['user_id' => $user->id, 'establishment_id' => $data['establishment_id']],
            [
                'status' => 'active',
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'metadata' => $data['metadata'] ?? null,
                'created_by' => $request->user()->id,
            ],
        );

        $this->audit->record(
            $request,
            'MEMBERSHIP_CREATED',
            'membership',
            $membership->id,
            $before,
            $membership->fresh()->toArray(),
            null,
            $membership->establishment_id,
            ['target_user_id' => $user->id],
        );

        return response()->json(['membership' => $membership->load('establishment')], 201);
    }

    public function revokeMembership(Request $request, User $user, Membership $membership): JsonResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($membership->user_id === $user->id, 404);

        $userIsOwner = RoleAssignment::query()->active()
            ->where('user_id', $user->id)
            ->where('establishment_id', $membership->establishment_id)
            ->whereHas('role', fn ($q) => $q->where('code', 'owner'))
            ->exists();
        if ($userIsOwner) {
            abort_if($this->activeOwnerCount((int) $membership->establishment_id) <= 1, 422, 'O vínculo do último proprietário não pode ser revogado.');
        }

        $before = $membership->toArray();
        DB::transaction(function () use ($membership, $request, $user) {
            $membership->update([
                'status' => 'revoked',
                'ends_at' => $membership->ends_at ?? now(),
                'metadata' => array_merge($membership->metadata ?? [], [
                    'revoked_by' => $request->user()->id,
                    'revoked_at' => now()->toIso8601String(),
                ]),
            ]);

            RoleAssignment::query()
                ->where('user_id', $user->id)
                ->where('establishment_id', $membership->establishment_id)
                ->where('status', 'active')
                ->update(['status' => 'revoked', 'expires_at' => now()]);
        });

        $this->audit->record(
            $request,
            'MEMBERSHIP_REVOKED',
            'membership',
            $membership->id,
            $before,
            $membership->fresh()->toArray(),
            null,
            $membership->establishment_id,
            ['target_user_id' => $user->id],
        );

        return response()->json(['membership' => $membership->fresh()]);
    }

    public function addRelationship(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'resource_uuid' => ['required', 'uuid', 'exists:resource_refs,uuid'],
            'relationship_type' => ['required', 'string', 'max:100'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'metadata' => ['nullable', 'array'],
        ]);

        $resource = $this->resources->findActive($data['resource_uuid']);
        $relationshipType = RelationshipType::canonicalize($data['relationship_type']);
        abort_unless($relationshipType, 422, 'Tipo de relacionamento não reconhecido.');

        $relationshipKey = ResourceRelationship::relationshipKey(
            'user',
            $user->id,
            $relationshipType,
            $resource->resource_type,
            (int) $resource->resource_id,
            (int) $resource->application_id,
            $resource->uuid,
        );

        $existing = ResourceRelationship::query()->where('relationship_key', $relationshipKey)->first();
        $before = $existing?->toArray();
        $relationship = ResourceRelationship::query()->updateOrCreate(
            ['relationship_key' => $relationshipKey],
            [
                'subject_type' => 'user',
                'subject_id' => $user->id,
                'application_id' => $resource->application_id,
                'resource_ref_id' => $resource->id,
                'relationship_type' => $relationshipType,
                'resource_type' => $resource->resource_type,
                'resource_id' => $resource->resource_id,
                'status' => 'active',
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'metadata' => $data['metadata'] ?? null,
                'created_by' => $request->user()->id,
            ],
        );

        $this->audit->record(
            $request,
            'RELATIONSHIP_CREATED',
            'resource_relationship',
            $relationship->id,
            $before,
            $relationship->fresh()->toArray(),
            $resource->application_id,
            $resource->establishment_id,
            ['target_user_id' => $user->id, 'relationship_type' => $relationshipType, 'resource_uuid' => $resource->uuid],
        );

        return response()->json(['relationship' => $relationship->load(['application', 'resourceRef'])], 201);
    }

    public function revokeRelationship(Request $request, User $user, ResourceRelationship $relationship): JsonResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($relationship->subject_type === 'user' && $relationship->subject_id === $user->id, 404);

        $before = $relationship->toArray();
        $relationship->update([
            'status' => 'revoked',
            'ends_at' => $relationship->ends_at ?? now(),
            'metadata' => array_merge($relationship->metadata ?? [], [
                'revoked_by' => $request->user()->id,
                'revoked_at' => now()->toIso8601String(),
            ]),
        ]);

        $this->audit->record(
            $request,
            'RELATIONSHIP_REVOKED',
            'resource_relationship',
            $relationship->id,
            $before,
            $relationship->fresh()->toArray(),
            $relationship->application_id,
            $relationship->resourceRef?->establishment_id,
            ['target_user_id' => $user->id, 'relationship_type' => $relationship->relationship_type],
        );

        return response()->json(['relationship' => $relationship]);
    }

    private function authorizeAdmin(Request $request): void
    {
        $actor = $request->user();
        $legacyAdmin = $actor && ($actor->hasProfile('Administrador') || $actor->hasPermission('user_edit'));
        $contextualAdmin = $actor && $this->access->hasPermission($actor, 'ecosystem.manage');
        abort_unless($legacyAdmin || $contextualAdmin, 403);
    }

    private function requireStepUp(Request $request): void
    {
        abort_unless(
            $this->stepUp->valid($request->header('X-Peter-Step-Up'), $request->user()),
            428,
            'Esta ação crítica exige confirmação de identidade recente.',
        );
    }

    private function validateContextConsistency(array $data, $resource = null): void
    {
        $applicationId = $data['application_id'] ?? null;
        $establishmentId = $data['establishment_id'] ?? null;

        abort_if(($establishmentId || $resource) && ! $applicationId, 422, 'Contextos de estabelecimento ou recurso exigem application_id.');

        if ($resource) {
            abort_unless((int) $resource->application_id === (int) $applicationId, 422, 'O recurso não pertence à aplicação informada.');
            if ($establishmentId && $resource->establishment_id) {
                abort_unless((int) $resource->establishment_id === (int) $establishmentId, 422, 'O recurso não pertence ao estabelecimento informado.');
            }
        }

        if ($establishmentId && $applicationId) {
            abort_unless(
                $this->resources->establishmentBelongsToApplication((int) $establishmentId, (int) $applicationId),
                422,
                'O estabelecimento não pertence ao contexto da aplicação informada.',
            );
        }
    }

    private function activeRoleCount(string $roleCode): int
    {
        return RoleAssignment::query()->active()->whereHas('role', fn ($q) => $q->where('code', $roleCode))->count();
    }

    private function activeOwnerCount(int $establishmentId): int
    {
        return RoleAssignment::query()->active()
            ->where('establishment_id', $establishmentId)
            ->whereHas('role', fn ($q) => $q->where('code', 'owner'))
            ->count();
    }

    private function activeEcosystemManagerCount(int $userId): int
    {
        return RoleAssignment::query()->active()
            ->where('user_id', $userId)
            ->whereHas('role.permissions', fn ($q) => $q->where('permissions.code', 'ecosystem.manage'))
            ->count();
    }
}
