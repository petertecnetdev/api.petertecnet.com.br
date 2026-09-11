<?php

namespace App\Domain\Commerce\Services;

use App\Models\EventPass;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class TicketInventoryService
{
    /**
     * Apply the same sellable inventory rule used by states() to a Ticket
     * query so discovery can preserve database pagination.
     */
    public function constrainSellable(Builder $query, int $appId, ?Carbon $now = null): Builder
    {
        $now ??= Carbon::now(config('app.timezone', 'America/Sao_Paulo'));

        return $query
            ->where('tickets.quantity', '>', 0)
            ->where(fn (Builder $dates) => $dates
                ->whereNull('tickets.limit_date')
                ->orWhere('tickets.limit_date', '>', $now))
            ->whereRaw(
                "tickets.quantity > ((SELECT COUNT(*) FROM event_passes WHERE event_passes.ticket_id = tickets.id AND event_passes.status NOT IN (?, ?, ?)) + COALESCE((SELECT SUM(inventory_reservations.quantity) FROM inventory_reservations WHERE inventory_reservations.ticket_id = tickets.id AND inventory_reservations.app_id = ? AND inventory_reservations.released_at IS NULL AND inventory_reservations.expires_at > ?), 0))",
                ['cancelled', 'refunded', 'charged_back', $appId, $now->toDateTimeString()]
            );
    }

    /**
     * Return inventory state keyed by ticket id without issuing N+1 queries.
     * Active reservations are unavailable until released or expired, matching
     * checkout allocation semantics.
     *
     * @param Collection<int, Ticket> $tickets
     * @return Collection<int, array{remaining:int,capacity_remaining:int,issued:int,reserved:int,expired:bool,available:bool}>
     */
    public function states(Collection $tickets, ?Carbon $now = null): Collection
    {
        $now ??= Carbon::now(config('app.timezone', 'America/Sao_Paulo'));
        $ids = $tickets->pluck('id')->filter()->map(fn ($id) => (int) $id)->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $issued = EventPass::query()
            ->whereIn('ticket_id', $ids)
            ->whereNotIn('status', ['cancelled', 'refunded', 'charged_back'])
            ->selectRaw('ticket_id, COUNT(*) as aggregate')
            ->groupBy('ticket_id')
            ->pluck('aggregate', 'ticket_id');

        $reserved = DB::table('inventory_reservations')
            ->whereIn('ticket_id', $ids)
            ->whereNull('released_at')
            ->where('expires_at', '>', $now)
            ->selectRaw('ticket_id, SUM(quantity) as aggregate')
            ->groupBy('ticket_id')
            ->pluck('aggregate', 'ticket_id');

        return $tickets->mapWithKeys(function (Ticket $ticket) use ($issued, $reserved, $now) {
            $issuedCount = (int) ($issued[$ticket->id] ?? 0);
            $reservedCount = (int) ($reserved[$ticket->id] ?? 0);
            $capacityRemaining = max(0, (int) $ticket->quantity - $issuedCount);
            $remaining = max(0, $capacityRemaining - $reservedCount);
            $expired = (bool) ($ticket->limit_date && $now->greaterThanOrEqualTo($ticket->limit_date));

            return [(int) $ticket->id => [
                'remaining' => $remaining,
                'capacity_remaining' => $capacityRemaining,
                'issued' => $issuedCount,
                'reserved' => $reservedCount,
                'expired' => $expired,
                'available' => ! $expired && $remaining > 0,
            ]];
        });
    }

    /**
     * Classify a collection of ticket lots using the same inventory facts as
     * checkout. The result is intentionally generic so any application can
     * explain availability without duplicating financial/inventory rules.
     *
     * @param Collection<int, Ticket> $tickets
     * @param Collection<int, array>|null $states
     * @return array{status:string,configured_lots_count:int,sellable_lots_count:int,sellable_free_lots_count:int,starting_price:float|null}
     */
    public function availability(Collection $tickets, ?Carbon $now = null, ?Collection $states = null): array
    {
        $now ??= Carbon::now(config('app.timezone', 'America/Sao_Paulo'));

        if ($tickets->isEmpty()) {
            return [
                'status' => 'tickets_pending',
                'configured_lots_count' => 0,
                'sellable_lots_count' => 0,
                'sellable_free_lots_count' => 0,
                'starting_price' => null,
            ];
        }

        $states ??= $this->states($tickets, $now);
        $sellable = $tickets->filter(fn (Ticket $ticket) => (bool) ($states->get((int) $ticket->id)['available'] ?? false));
        $sellableFree = $sellable->filter(fn (Ticket $ticket) => (float) $ticket->price <= 0);

        if ($sellable->isNotEmpty()) {
            return [
                'status' => $sellableFree->isNotEmpty() ? 'free_available' : 'available',
                'configured_lots_count' => $tickets->count(),
                'sellable_lots_count' => $sellable->count(),
                'sellable_free_lots_count' => $sellableFree->count(),
                'starting_price' => (float) $sellable->min('price'),
            ];
        }

        $activeLots = $tickets->filter(fn (Ticket $ticket) => ! (bool) ($states->get((int) $ticket->id)['expired'] ?? true));
        $temporarilyReserved = $activeLots->contains(function (Ticket $ticket) use ($states) {
            $state = $states->get((int) $ticket->id, []);

            return (int) ($state['capacity_remaining'] ?? 0) > 0
                && (int) ($state['remaining'] ?? 0) === 0
                && (int) ($state['reserved'] ?? 0) > 0;
        });

        $status = match (true) {
            $temporarilyReserved => 'temporarily_reserved',
            $activeLots->isEmpty() => 'sales_ended',
            default => 'sold_out',
        };

        return [
            'status' => $status,
            'configured_lots_count' => $tickets->count(),
            'sellable_lots_count' => 0,
            'sellable_free_lots_count' => 0,
            'starting_price' => null,
        ];
    }

    /**
     * Compute availability summaries for many events with one pair of issued /
     * reservation aggregate queries, keeping discovery free from N+1 lookups.
     *
     * @param Collection<int, Ticket> $tickets
     * @return Collection<int, array{status:string,configured_lots_count:int,sellable_lots_count:int,sellable_free_lots_count:int,starting_price:float|null}>
     */
    public function availabilityByEvent(Collection $tickets, ?Carbon $now = null): Collection
    {
        if ($tickets->isEmpty()) {
            return collect();
        }

        $now ??= Carbon::now(config('app.timezone', 'America/Sao_Paulo'));
        $states = $this->states($tickets, $now);

        return $tickets
            ->groupBy(fn (Ticket $ticket) => (int) $ticket->event_id)
            ->map(fn (Collection $eventTickets) => $this->availability($eventTickets, $now, $states));
    }
}
