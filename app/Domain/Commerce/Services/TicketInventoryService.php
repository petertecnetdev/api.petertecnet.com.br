<?php

namespace App\Domain\Commerce\Services;

use App\Models\EventPass;
use App\Models\Ticket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class TicketInventoryService
{
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
