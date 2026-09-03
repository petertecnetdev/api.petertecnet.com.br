<?php

namespace App\Services\Scheduling;

use App\Models\Employer;
use App\Models\Establishment;
use Illuminate\Http\Request;

class SchedulingAuthorizationService
{
    public function establishment(int $appId, int $establishmentId): Establishment
    {
        return Establishment::query()
            ->whereKey($establishmentId)
            ->where('app_id', $appId)
            ->where('is_cancelled', false)
            ->firstOrFail();
    }

    public function managedEstablishment(Request $request, int $appId, int $establishmentId): Establishment
    {
        $establishment = $this->establishment($appId, $establishmentId);
        abort_unless(
            $this->canManage($establishment, (int) $request->user()->id),
            403,
            'Você não possui permissão para gerenciar este estabelecimento.'
        );

        return $establishment;
    }

    public function canManage(Establishment $establishment, int $userId): bool
    {
        if ((int) $establishment->user_id === $userId || (int) $establishment->created_by === $userId) {
            return true;
        }

        return Employer::query()
            ->where('establishment_id', $establishment->id)
            ->where('user_id', $userId)
            ->whereIn('role', ['gerente', 'manager', 'gestor', 'administrador', 'admin'])
            ->exists();
    }
}
