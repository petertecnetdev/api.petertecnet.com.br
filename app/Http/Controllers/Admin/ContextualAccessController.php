<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\ResourceRelationship;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\ContextualAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContextualAccessController extends Controller
{
    public function __construct(private readonly ContextualAccessService $access)
    {
    }

    public function catalog(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'roles' => Role::query()->with('permissions:id,code,name')->orderBy('name')->get(),
        ]);
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
            'resource_type' => ['nullable', 'string', 'max:120', 'required_with:resource_id'],
            'resource_id' => ['nullable', 'integer', 'min:1', 'required_with:resource_type'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
            'metadata' => ['nullable', 'array'],
        ]);

        $this->validateContextConsistency($data);

        $contextKey = RoleAssignment::contextKey(
            $data['application_id'] ?? null,
            $data['establishment_id'] ?? null,
            $data['resource_type'] ?? null,
            $data['resource_id'] ?? null,
        );

        $assignment = RoleAssignment::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'role_id' => $data['role_id'],
                'context_key' => $contextKey,
            ],
            [
                'application_id' => $data['application_id'] ?? null,
                'establishment_id' => $data['establishment_id'] ?? null,
                'resource_type' => $data['resource_type'] ?? null,
                'resource_id' => $data['resource_id'] ?? null,
                'status' => 'active',
                'starts_at' => $data['starts_at'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
                'metadata' => $data['metadata'] ?? null,
                'assigned_by' => $request->user()->id,
            ],
        );

        return response()->json(['assignment' => $assignment->load(['role.permissions', 'application', 'establishment'])], 201);
    }

    public function revokeRole(Request $request, User $user, RoleAssignment $assignment): JsonResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($assignment->user_id === $user->id, 404);

        $assignment->update([
            'status' => 'revoked',
            'expires_at' => $assignment->expires_at ?? now(),
            'metadata' => array_merge($assignment->metadata ?? [], [
                'revoked_by' => $request->user()->id,
                'revoked_at' => now()->toIso8601String(),
            ]),
        ]);

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

        return response()->json(['membership' => $membership->load('establishment')], 201);
    }

    public function revokeMembership(Request $request, User $user, Membership $membership): JsonResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($membership->user_id === $user->id, 404);

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

        return response()->json(['membership' => $membership->fresh()]);
    }

    public function addRelationship(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'application_id' => ['nullable', 'integer', 'exists:applications,id'],
            'relationship_type' => ['required', 'string', 'max:100'],
            'resource_type' => ['required', 'string', 'max:120'],
            'resource_id' => ['required', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'metadata' => ['nullable', 'array'],
        ]);

        $relationshipKey = ResourceRelationship::relationshipKey(
            'user',
            $user->id,
            $data['relationship_type'],
            $data['resource_type'],
            $data['resource_id'],
            $data['application_id'] ?? null,
        );

        $relationship = ResourceRelationship::query()->updateOrCreate(
            ['relationship_key' => $relationshipKey],
            [
                'subject_type' => 'user',
                'subject_id' => $user->id,
                'application_id' => $data['application_id'] ?? null,
                'relationship_type' => strtolower(trim($data['relationship_type'])),
                'resource_type' => strtolower(trim($data['resource_type'])),
                'resource_id' => $data['resource_id'],
                'status' => 'active',
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'metadata' => $data['metadata'] ?? null,
                'created_by' => $request->user()->id,
            ],
        );

        return response()->json(['relationship' => $relationship->load('application')], 201);
    }

    public function revokeRelationship(Request $request, User $user, ResourceRelationship $relationship): JsonResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($relationship->subject_type === 'user' && $relationship->subject_id === $user->id, 404);

        $relationship->update([
            'status' => 'revoked',
            'ends_at' => $relationship->ends_at ?? now(),
            'metadata' => array_merge($relationship->metadata ?? [], [
                'revoked_by' => $request->user()->id,
                'revoked_at' => now()->toIso8601String(),
            ]),
        ]);

        return response()->json(['relationship' => $relationship]);
    }

    private function authorizeAdmin(Request $request): void
    {
        $actor = $request->user();
        $legacyAdmin = $actor && ($actor->hasProfile('Administrador') || $actor->hasPermission('user_edit'));
        $contextualAdmin = $actor && $this->access->hasPermission($actor, 'ecosystem.manage');

        abort_unless($legacyAdmin || $contextualAdmin, 403);
    }

    private function validateContextConsistency(array $data): void
    {
        if (! empty($data['establishment_id']) && ! empty($data['application_id'])) {
            $belongs = DB::table('establishments')
                ->where('id', $data['establishment_id'])
                ->where(function ($query) use ($data) {
                    $query->where('app_id', $data['application_id'])
                        ->orWhereExists(function ($subquery) use ($data) {
                            $subquery->selectRaw('1')
                                ->from('application_establishment')
                                ->whereColumn('application_establishment.establishment_id', 'establishments.id')
                                ->where('application_establishment.application_id', $data['application_id']);
                        });
                })
                ->exists();

            abort_unless($belongs, 422, 'O estabelecimento não pertence ao contexto da aplicação informada.');
        }

        if ((! empty($data['resource_type']) && empty($data['resource_id'])) || (empty($data['resource_type']) && ! empty($data['resource_id']))) {
            abort(422, 'resource_type e resource_id devem ser informados em conjunto.');
        }
    }
}
