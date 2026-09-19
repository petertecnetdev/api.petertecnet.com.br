<?php

namespace App\Domain\Platform\Services;

use App\Models\ApplicationProfile;
use App\Models\ApplicationProfileAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicationProfileManagementService
{
    public function __construct(private readonly ApplicationAdminService $admin)
    {
    }

    public function profiles(int $applicationId, User $actor): Collection
    {
        $this->admin->assertOwnerAuthority($applicationId, $actor);

        return ApplicationProfile::query()
            ->where('application_id', $applicationId)
            ->withCount([
                'assignments as active_assignments_count' => fn ($query) => $query
                    ->where('status', 'active')
                    ->whereNull('revoked_at'),
            ])
            ->orderBy('name')
            ->get();
    }

    public function assignments(int $applicationId, User $actor): Collection
    {
        $this->admin->assertOwnerAuthority($applicationId, $actor);

        return ApplicationProfileAssignment::query()
            ->where('application_id', $applicationId)
            ->with([
                'user:id,first_name,last_name,email,avatar',
                'profile:id,application_id,slug,name,description,permissions,is_system',
                'grantedBy:id,first_name,last_name,email',
            ])
            ->orderByRaw("CASE WHEN status = 'active' AND revoked_at IS NULL THEN 0 ELSE 1 END")
            ->latest('updated_at')
            ->get();
    }

    public function assign(
        int $applicationId,
        User $actor,
        User $target,
        array $profileIds,
        string $scopeType = 'application',
        ?int $scopeId = null,
    ): Collection {
        $this->admin->assertOwnerAuthority($applicationId, $actor);

        $scopeType = trim($scopeType) ?: 'application';
        $scopeId = $scopeType === 'application' ? 0 : (int) ($scopeId ?? 0);

        $this->validateScope($applicationId, $scopeType, $scopeId);

        $profiles = ApplicationProfile::query()
            ->where('application_id', $applicationId)
            ->whereIn('id', array_values(array_unique(array_map('intval', $profileIds))))
            ->get();

        if ($profiles->count() !== count(array_unique(array_map('intval', $profileIds)))) {
            throw ValidationException::withMessages([
                'profile_ids' => 'Um ou mais perfis não pertencem à aplicação selecionada.',
            ]);
        }

        foreach ($profiles as $profile) {
            ApplicationProfileAssignment::query()->updateOrCreate(
                [
                    'application_id' => $applicationId,
                    'user_id' => $target->id,
                    'profile_id' => $profile->id,
                    'scope_type' => $scopeType,
                    'scope_id' => $scopeId,
                ],
                [
                    'status' => 'active',
                    'source' => 'manual_admin',
                    'metadata' => null,
                    'granted_by_user_id' => $actor->id,
                    'revoked_at' => null,
                ],
            );
        }

        return ApplicationProfileAssignment::query()
            ->where('application_id', $applicationId)
            ->where('user_id', $target->id)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->with('profile:id,application_id,slug,name,description,permissions,is_system')
            ->orderBy('profile_id')
            ->get();
    }

    public function revoke(
        int $applicationId,
        User $actor,
        int $assignmentId,
    ): ApplicationProfileAssignment {
        $this->admin->assertOwnerAuthority($applicationId, $actor);

        $assignment = ApplicationProfileAssignment::query()
            ->where('application_id', $applicationId)
            ->whereKey($assignmentId)
            ->firstOrFail();

        $assignment->forceFill([
            'status' => 'revoked',
            'revoked_at' => now(),
        ])->save();

        return $assignment->fresh()->load([
            'user:id,first_name,last_name,email,avatar',
            'profile:id,application_id,slug,name,description,permissions,is_system',
        ]);
    }

    private function validateScope(int $applicationId, string $scopeType, int $scopeId): void
    {
        if ($scopeType === 'application') {
            return;
        }

        if ($scopeId <= 0) {
            throw ValidationException::withMessages([
                'scope_id' => 'Informe o recurso ao qual o perfil será limitado.',
            ]);
        }

        $valid = match ($scopeType) {
            'establishment' => DB::table('establishments')
                ->where('app_id', $applicationId)
                ->where('id', $scopeId)
                ->exists(),
            'artist' => DB::table('artists')
                ->where('app_id', $applicationId)
                ->where('id', $scopeId)
                ->exists(),
            default => false,
        };

        if (! $valid) {
            throw ValidationException::withMessages([
                'scope_id' => 'O recurso informado não pertence à aplicação selecionada.',
            ]);
        }
    }
}
