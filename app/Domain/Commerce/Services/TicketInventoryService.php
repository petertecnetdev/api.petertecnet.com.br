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
     * @return Collection<int, array{remaining:int,expired:bool,available:bool}>
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
            $remaining = max(
                0,
                (int) $ticket->quantity
                    - (int) ($issued[$ticket->id] ?? 0)
                    - (int) ($reserved[$ticket->id] ?? 0)
            );
            $expired = (bool) ($ticket->limit_date && $now->greaterThanOrEqualTo($ticket->limit_date));

            return [(int) $ticket->id => [
                'remaining' => $remaining,
                'expired' => $expired,
                'available' => ! $expired && $remaining > 0,
            ]];
        });
    }
}
