<?php

namespace App\Domain\Discovery\Services;

use App\Models\Production;
use App\Models\User;

final class ProducerInsightsAccessService
{
    public function authorize(int $appId, int $productionId, User $actor): int
    {
        $production = Production::query()
            ->where('app_id', $appId)
            ->findOrFail($productionId);

        abort_unless(
            $actor->hasProfile('Administrador') || (int) $production->user_id === (int) $actor->id,
            403
        );

        return (int) $production->id;
    }
}
