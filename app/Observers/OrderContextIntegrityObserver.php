<?php

namespace App\Observers;

use App\Models\Employer;
use App\Models\Establishment;
use App\Models\Order;
use Illuminate\Validation\ValidationException;

class OrderContextIntegrityObserver
{
    public function creating(Order $order): void
    {
        if (strtolower(trim((string) $order->entity_name)) !== 'establishment') {
            return;
        }

        $establishment = Establishment::query()
            ->forApplication((int) $order->app_id)
            ->find($order->entity_id);

        if (! $establishment) {
            throw ValidationException::withMessages([
                'entity_id' => 'O estabelecimento informado não está disponível para esta aplicação.',
            ]);
        }

        if (empty($order->attendant_id)) {
            return;
        }

        $attendantBelongsToEstablishment = Employer::query()
            ->whereKey($order->attendant_id)
            ->where('establishment_id', $establishment->id)
            ->exists();

        if (! $attendantBelongsToEstablishment) {
            throw ValidationException::withMessages([
                'attendant_id' => 'O colaborador informado não pertence ao estabelecimento deste pedido.',
            ]);
        }
    }
}
