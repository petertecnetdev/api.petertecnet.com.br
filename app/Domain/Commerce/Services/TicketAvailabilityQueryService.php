<?php

namespace App\Domain\Commerce\Services;

use App\Models\Ticket;
use Illuminate\Support\Collection;

final class TicketAvailabilityQueryService
{
    public function __construct(private readonly TicketInventoryService $ticketInventory) {}

    public function forEvents(int $appId, array $eventIds): Collection
    {
        $eventIds = collect($eventIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->take(50)
            ->values();

        if ($eventIds->isEmpty()) {
            return collect();
        }

        $availability = $this->ticketInventory->availabilityByEvent(
            Ticket::query()
                ->where('app_id', $appId)
                ->whereIn('event_id', $eventIds)
                ->get(['id', 'event_id', 'price', 'quantity', 'limit_date'])
        );

        return $eventIds->mapWithKeys(function (int $eventId) use ($availability) {
            $summary = $availability->get($eventId, [
                'status' => 'tickets_pending',
                'sellable_lots_count' => 0,
                'sellable_free_lots_count' => 0,
            ]);

            return [(string) $eventId => [
                'ticket_availability_status' => $summary['status'],
                'sellable_ticket_lots_count' => (int) $summary['sellable_lots_count'],
                'sellable_free_ticket_lots_count' => (int) $summary['sellable_free_lots_count'],
            ]];
        });
    }
}
