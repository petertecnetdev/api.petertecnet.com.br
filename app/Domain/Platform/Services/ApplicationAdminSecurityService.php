<?php

namespace App\Domain\Platform\Services;

use App\Models\ApplicationAdminAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicationAdminSecurityService
{
    public function __construct(private readonly ApplicationAdminService $admin)
    {
    }

    public function snapshot(int $applicationId, User $actor, User $target): array
    {
        $this->assertCanTarget($applicationId, $actor, $target, false);

        $membership = DB::table('application_user')
            ->where('application_id', $applicationId)
            ->where('user_id', $target->id)
            ->first();

        $assignment = ApplicationAdminAssignment::query()
            ->where('application_id', $applicationId)
            ->where('user_id', $target->id)
            ->with('profile:id,application_id,name,slug,permissions')
            ->first();

        return [
            'user' => [
                'id' => $target->id,
                'name' => trim(($target->first_name ?? '').' '.($target->last_name ?? '')),
                'email' => $target->email,
                'email_verified_at' => $target->email_verified_at,
            ],
            'is_owner' => $this->admin->isOwner($target, $applicationId),
            'membership' => $membership ? [
                'role' => $membership->role ?? null,
                'status' => $membership->status ?? null,
                'joined_at' => $membership->joined_at ?? null,
            ] : null,
            'admin_assignment' => $assignment ? [
                'id' => $assignment->id,
                'status' => $assignment->status,
                'revoked_at' => $assignment->revoked_at,
                'profile' => $assignment->profile,
            ] : null,
            'session_version' => max((int) ($target->auth_version ?: 1), 1),
        ];
    }

    public function updateMembershipStatus(
        int $applicationId,
        User $actor,
        User $target,
        string $status,
        array $auditContext = [],
    ): array {
        $this->assertCanTarget($applicationId, $actor, $target, true);

        if (! in_array($status, ['active', 'suspended', 'blocked'], true)) {
            throw ValidationException::withMessages(['status' => 'Status administrativo inválido.']);
        }

        $row = DB::table('application_user')
            ->where('application_id', $applicationId)
            ->where('user_id', $target->id)
            ->first();

        if (! $row) {
            throw ValidationException::withMessages(['user' => 'O usuário não está vinculado a esta aplicação.']);
        }

        $before = (string) ($row->status ?? 'active');

        DB::transaction(function () use ($applicationId, $target, $status) {
            DB::table('application_user')
                ->where('application_id', $applicationId)
                ->where('user_id', $target->id)
                ->update(['status' => $status, 'updated_at' => now()]);

            if ($status !== 'active') {
                $locked = User::query()->lockForUpdate()->findOrFail($target->id);
                $locked->forceFill(['auth_version' => max((int) ($locked->auth_version ?: 1), 1) + 1])->save();
            }
        });

        $this->admin->auditAction($applicationId, $actor, $target, 'application_user_status_changed', [
            'before' => $before,
            'after' => $status,
            'sessions_revoked' => $status !== 'active',
        ], $auditContext);

        return $this->snapshot($applicationId, $actor, $target->fresh());
    }

    public function revokeSessions(
        int $applicationId,
        User $actor,
        User $target,
        array $auditContext = [],
    ): array {
        $this->assertCanTarget($applicationId, $actor, $target, false);

        $before = max((int) ($target->auth_version ?: 1), 1);

        $after = DB::transaction(function () use ($target, $before): int {
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);
            $next = max((int) ($locked->auth_version ?: $before), 1) + 1;
            $locked->forceFill(['auth_version' => $next])->save();
            return $next;
        });

        $this->admin->auditAction($applicationId, $actor, $target, 'user_sessions_revoked', [
            'auth_version_before' => $before,
            'auth_version_after' => $after,
        ], $auditContext);

        return ['user_id' => $target->id, 'session_version' => $after, 'revoked' => true];
    }

    private function assertCanTarget(int $applicationId, User $actor, User $target, bool $destructive): void
    {
        $actorIsOwner = $this->admin->isOwner($actor, $applicationId);
        $targetIsOwner = $this->admin->isOwner($target, $applicationId);

        if ($targetIsOwner && ! $actorIsOwner) {
            throw new AuthorizationException('Administradores delegados não podem alterar a identidade Owner.');
        }

        if ($targetIsOwner && $destructive) {
            throw new AuthorizationException('A identidade Owner não pode ser suspensa ou bloqueada.');
        }
    }
}
