<?php

namespace App\Domain\Acquisition\Services;

use App\Models\AcquisitionReferral;
use App\Models\Production;
use App\Models\User;
use App\Support\ApplicationContext;

final class AcquisitionAccess
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function isAgent(?User $user): bool
    {
        if (! $user) return false;

        return $user->applications()
            ->whereKey($this->context->id())
            ->wherePivot('status', 'active')
            ->wherePivot('role', 'acquisition_agent')
            ->exists();
    }

    public function assertAgent(?User $user): User
    {
        abort_unless($user && $this->isAgent($user), 403, 'Este recurso é exclusivo para agentes desta aplicação.');
        return $user;
    }

    public function canManageProduction(?User $user, Production $production): bool
    {
        if (! $user || (int) $production->app_id !== $this->context->id()) return false;
        if ($user->hasProfile('Administrador') || (int) $production->user_id === (int) $user->id) return true;
        if (! $this->isAgent($user)) return false;

        return AcquisitionReferral::query()
            ->where('application_id', $this->context->id())
            ->where('agent_user_id', $user->id)
            ->where('production_id', $production->id)
            ->whereIn('status', ['pending', 'accepted'])
            ->exists();
    }
}
