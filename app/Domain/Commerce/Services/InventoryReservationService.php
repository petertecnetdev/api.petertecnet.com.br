<?php

namespace App\Domain\Commerce\Services;

use App\Models\InventoryReservation;
use App\Support\ApplicationContext;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class InventoryReservationService
{
    public function __construct(
        private readonly ApplicationContext $application,
        private readonly TenantContext $tenant,
    ) {}

    public function reserve(Model $resource, int $quantity, ?Model $orderable = null, int $ttlMinutes = 15): InventoryReservation
    {
        if ($quantity < 1) throw new RuntimeException('Reservation quantity must be greater than zero.');

        return DB::transaction(function () use ($resource, $quantity, $orderable, $ttlMinutes) {
            return InventoryReservation::create([
                'application_id' => $this->application->id(),
                'tenant_type' => $this->tenant->has() ? $this->tenant->type() : null,
                'tenant_id' => $this->tenant->has() ? $this->tenant->id() : null,
                'resource_type' => $resource->getMorphClass(),
                'resource_id' => $resource->getKey(),
                'orderable_type' => $orderable?->getMorphClass(),
                'orderable_id' => $orderable?->getKey(),
                'quantity' => $quantity,
                'status' => 'reserved',
                'expires_at' => now()->addMinutes(max(1, $ttlMinutes)),
            ]);
        });
    }

    public function reservedQuantity(Model $resource): int
    {
        return (int) InventoryReservation::query()
            ->where('application_id', $this->application->id())
            ->where('resource_type', $resource->getMorphClass())
            ->where('resource_id', $resource->getKey())
            ->where('status', 'reserved')
            ->whereNull('released_at')
            ->where('expires_at', '>', now())
            ->sum('quantity');
    }

    public function release(InventoryReservation $reservation): void
    {
        abort_unless((int) $reservation->application_id === $this->application->id(), 404);
        if ($reservation->released_at) return;
        $reservation->update(['status' => 'released', 'released_at' => now()]);
    }

    public function expire(): int
    {
        return InventoryReservation::query()
            ->where('application_id', $this->application->id())
            ->where('status', 'reserved')
            ->whereNull('released_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired', 'released_at' => now(), 'updated_at' => now()]);
    }
}
