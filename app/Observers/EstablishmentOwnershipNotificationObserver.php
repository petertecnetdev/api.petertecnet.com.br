<?php

namespace App\Observers;

use App\Models\Establishment;
use App\Models\User;
use App\Services\UserOnboardingCommunicationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EstablishmentOwnershipNotificationObserver
{
    public function created(Establishment $establishment): void
    {
        if (! $establishment->user_id) {
            return;
        }

        $this->afterCommit($establishment);
    }

    public function updated(Establishment $establishment): void
    {
        if (! $establishment->wasChanged('user_id') || ! $establishment->user_id) {
            return;
        }

        $this->afterCommit($establishment);
    }

    private function afterCommit(Establishment $establishment): void
    {
        $establishmentId = $establishment->id;
        $actorId = $establishment->updated_by ?: $establishment->created_by;

        DB::afterCommit(function () use ($establishmentId, $actorId) {
            try {
                $fresh = Establishment::query()->find($establishmentId);
                if (! $fresh || ! $fresh->user_id) {
                    return;
                }

                $actor = $actorId ? User::query()->find($actorId) : null;
                app(UserOnboardingCommunicationService::class)
                    ->notifyEstablishmentOwnership($fresh, $actor);
            } catch (\Throwable $e) {
                Log::error('Falha ao enviar comunicação de vínculo de estabelecimento.', [
                    'establishment_id' => $establishmentId,
                    'message' => $e->getMessage(),
                ]);
            }
        });
    }
}
