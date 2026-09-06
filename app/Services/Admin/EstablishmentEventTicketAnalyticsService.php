<?php

namespace App\Services\Admin;

use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EstablishmentEventTicketAnalyticsService
{
    private const INVALID_PASS_STATUSES = ['cancelled', 'refunded', 'charged_back'];

    public function attachSummaries(Collection $events): Collection
    {
        $eventIds = $events->pluck('id')->map(fn ($id) => (int) $id)->filter()->values();
        if ($eventIds->isEmpty()) {
            return $events;
        }

        $ticketInventory = DB::table('tickets')
            ->selectRaw('event_id, COUNT(*) as ticket_types_count, COALESCE(SUM(quantity), 0) as capacity')
            ->whereIn('event_id', $eventIds)
            ->groupBy('event_id')
            ->get()
            ->keyBy('event_id');

        $activePasses = DB::table('event_passes')
            ->selectRaw('event_id, COUNT(*) as issued_count, SUM(CASE WHEN checked_in_at IS NOT NULL THEN 1 ELSE 0 END) as checked_in_count, SUM(CASE WHEN commerce_order_item_id IS NULL THEN 1 ELSE 0 END) as courtesy_count')
            ->whereIn('event_id', $eventIds)
            ->whereNotIn('status', self::INVALID_PASS_STATUSES)
            ->groupBy('event_id')
            ->get()
            ->keyBy('event_id');

        $reversedPasses = DB::table('event_passes')
            ->selectRaw('event_id, COUNT(*) as reversed_count')
            ->whereIn('event_id', $eventIds)
            ->whereIn('status', self::INVALID_PASS_STATUSES)
            ->groupBy('event_id')
            ->get()
            ->keyBy('event_id');

        $soldPasses = DB::table('event_passes as ep')
            ->join('commerce_order_items as coi', 'coi.id', '=', 'ep.commerce_order_item_id')
            ->join('commerce_orders as co', 'co.id', '=', 'coi.order_id')
            ->selectRaw('ep.event_id, COUNT(*) as sold_count')
            ->whereIn('ep.event_id', $eventIds)
            ->where('co.status', 'paid')
            ->whereNotIn('ep.status', self::INVALID_PASS_STATUSES)
            ->groupBy('ep.event_id')
            ->get()
            ->keyBy('event_id');

        $sales = DB::table('commerce_orders as co')
            ->join('commerce_order_items as coi', 'coi.order_id', '=', 'co.id')
            ->selectRaw('co.event_id, COUNT(DISTINCT co.id) as paid_orders_count, COALESCE(SUM(coi.subtotal), 0) as gross_revenue')
            ->whereIn('co.event_id', $eventIds)
            ->where('co.status', 'paid')
            ->where('coi.type', 'ticket')
            ->groupBy('co.event_id')
            ->get()
            ->keyBy('event_id');

        $reservations = DB::table('inventory_reservations as ir')
            ->join('tickets as t', 't.id', '=', 'ir.ticket_id')
            ->selectRaw('t.event_id, COALESCE(SUM(ir.quantity), 0) as reserved_count')
            ->whereIn('t.event_id', $eventIds)
            ->whereNull('ir.released_at')
            ->where('ir.expires_at', '>', now())
            ->groupBy('t.event_id')
            ->get()
            ->keyBy('event_id');

        return $events->map(function (Event $event) use ($ticketInventory, $activePasses, $reversedPasses, $soldPasses, $sales, $reservations) {
            $inventory = $ticketInventory->get($event->id);
            $passes = $activePasses->get($event->id);
            $reversed = $reversedPasses->get($event->id);
            $sold = $soldPasses->get($event->id);
            $sale = $sales->get($event->id);
            $reservation = $reservations->get($event->id);

            $ticketTypesCount = (int) ($inventory->ticket_types_count ?? 0);
            $capacity = (int) ($inventory->capacity ?? 0);
            $issued = (int) ($passes->issued_count ?? 0);
            $reserved = (int) ($reservation->reserved_count ?? 0);

            // tickets_count existed before this analytics endpoint. Keep it as a
            // compatibility alias for the number of ticket types, while exposing
            // tickets_sold_count for the actual paid/valid passes.
            $event->setAttribute('tickets_count', $ticketTypesCount);
            $event->setAttribute('ticket_types_count', $ticketTypesCount);
            $event->setAttribute('ticket_capacity', $capacity);
            $event->setAttribute('tickets_issued_count', $issued);
            $event->setAttribute('tickets_sold_count', (int) ($sold->sold_count ?? 0));
            $event->setAttribute('courtesy_count', (int) ($passes->courtesy_count ?? 0));
            $event->setAttribute('checked_in_count', (int) ($passes->checked_in_count ?? 0));
            $event->setAttribute('tickets_reversed_count', (int) ($reversed->reversed_count ?? 0));
            $event->setAttribute('tickets_reserved_count', $reserved);
            $event->setAttribute('tickets_available_count', max(0, $capacity - $issued - $reserved));
            $event->setAttribute('paid_orders_count', (int) ($sale->paid_orders_count ?? 0));
            $event->setAttribute('gross_ticket_revenue', round((float) ($sale->gross_revenue ?? 0), 2));

            return $event;
        });
    }

    public function eventTicketDetails(Event $event): array
    {
        $tickets = Ticket::query()
            ->where('event_id', $event->id)
            ->orderBy('price')
            ->orderBy('id')
            ->get(['id', 'event_id', 'name', 'type', 'ticket_type', 'price', 'quantity', 'limit_date', 'description']);

        $ticketIds = $tickets->pluck('id')->map(fn ($id) => (int) $id)->values();
        if ($ticketIds->isEmpty()) {
            return [
                'summary' => $this->emptySummary(),
                'tickets' => [],
                'recent_sales' => [],
            ];
        }

        $passStats = DB::table('event_passes')
            ->selectRaw('ticket_id, COUNT(*) as issued_count, SUM(CASE WHEN checked_in_at IS NOT NULL THEN 1 ELSE 0 END) as checked_in_count, SUM(CASE WHEN commerce_order_item_id IS NULL THEN 1 ELSE 0 END) as courtesy_count')
            ->whereIn('ticket_id', $ticketIds)
            ->whereNotIn('status', self::INVALID_PASS_STATUSES)
            ->groupBy('ticket_id')
            ->get()
            ->keyBy('ticket_id');

        $soldStats = DB::table('event_passes as ep')
            ->join('commerce_order_items as coi', 'coi.id', '=', 'ep.commerce_order_item_id')
            ->join('commerce_orders as co', 'co.id', '=', 'coi.order_id')
            ->selectRaw('ep.ticket_id, COUNT(*) as sold_count')
            ->whereIn('ep.ticket_id', $ticketIds)
            ->where('co.status', 'paid')
            ->whereNotIn('ep.status', self::INVALID_PASS_STATUSES)
            ->groupBy('ep.ticket_id')
            ->get()
            ->keyBy('ticket_id');

        $reversedStats = DB::table('event_passes')
            ->selectRaw('ticket_id, COUNT(*) as reversed_count')
            ->whereIn('ticket_id', $ticketIds)
            ->whereIn('status', self::INVALID_PASS_STATUSES)
            ->groupBy('ticket_id')
            ->get()
            ->keyBy('ticket_id');

        $salesStats = DB::table('commerce_order_items as coi')
            ->join('commerce_orders as co', 'co.id', '=', 'coi.order_id')
            ->selectRaw('coi.ticket_id, COUNT(DISTINCT co.id) as paid_orders_count, COALESCE(SUM(coi.subtotal), 0) as gross_revenue')
            ->whereIn('coi.ticket_id', $ticketIds)
            ->where('coi.type', 'ticket')
            ->where('co.status', 'paid')
            ->groupBy('coi.ticket_id')
            ->get()
            ->keyBy('ticket_id');

        $reservationStats = DB::table('inventory_reservations')
            ->selectRaw('ticket_id, COALESCE(SUM(quantity), 0) as reserved_count')
            ->whereIn('ticket_id', $ticketIds)
            ->whereNull('released_at')
            ->where('expires_at', '>', now())
            ->groupBy('ticket_id')
            ->get()
            ->keyBy('ticket_id');

        $ticketRows = $tickets->map(function (Ticket $ticket) use ($passStats, $soldStats, $reversedStats, $salesStats, $reservationStats) {
            $passes = $passStats->get($ticket->id);
            $sold = $soldStats->get($ticket->id);
            $reversed = $reversedStats->get($ticket->id);
            $sales = $salesStats->get($ticket->id);
            $reservation = $reservationStats->get($ticket->id);

            $capacity = max(0, (int) $ticket->quantity);
            $issued = (int) ($passes->issued_count ?? 0);
            $reserved = (int) ($reservation->reserved_count ?? 0);

            return [
                'id' => $ticket->id,
                'name' => $ticket->name,
                'type' => $ticket->type,
                'ticket_type' => $ticket->ticket_type,
                'price' => (float) $ticket->price,
                'capacity' => $capacity,
                'issued_count' => $issued,
                'sold_count' => (int) ($sold->sold_count ?? 0),
                'courtesy_count' => (int) ($passes->courtesy_count ?? 0),
                'checked_in_count' => (int) ($passes->checked_in_count ?? 0),
                'reversed_count' => (int) ($reversed->reversed_count ?? 0),
                'reserved_count' => $reserved,
                'available_count' => max(0, $capacity - $issued - $reserved),
                'paid_orders_count' => (int) ($sales->paid_orders_count ?? 0),
                'gross_revenue' => round((float) ($sales->gross_revenue ?? 0), 2),
                'limit_date' => $ticket->limit_date?->toIso8601String(),
                'description' => $ticket->description,
            ];
        })->values();

        $recentSales = DB::table('event_passes as ep')
            ->join('tickets as t', 't.id', '=', 'ep.ticket_id')
            ->join('commerce_order_items as coi', 'coi.id', '=', 'ep.commerce_order_item_id')
            ->join('commerce_orders as co', 'co.id', '=', 'coi.order_id')
            ->where('ep.event_id', $event->id)
            ->where('co.status', 'paid')
            ->whereNotIn('ep.status', self::INVALID_PASS_STATUSES)
            ->orderByDesc('co.paid_at')
            ->orderByDesc('ep.id')
            ->limit(50)
            ->get([
                'ep.id as pass_id',
                'ep.holder_name',
                'ep.holder_email',
                'ep.status',
                'ep.checked_in_at',
                't.id as ticket_id',
                't.name as ticket_name',
                'co.public_id as order_public_id',
                'co.paid_at',
                'co.payment_method',
                'coi.unit_price',
            ])
            ->map(fn ($row) => [
                'pass_id' => (int) $row->pass_id,
                'holder_name' => $row->holder_name,
                'holder_email' => $row->holder_email,
                'status' => $row->status,
                'checked_in_at' => $row->checked_in_at,
                'ticket_id' => (int) $row->ticket_id,
                'ticket_name' => $row->ticket_name,
                'order_public_id' => $row->order_public_id,
                'paid_at' => $row->paid_at,
                'payment_method' => $row->payment_method,
                'unit_price' => round((float) $row->unit_price, 2),
            ])
            ->values();

        $paidOrdersCount = DB::table('commerce_orders as co')
            ->join('commerce_order_items as coi', 'coi.order_id', '=', 'co.id')
            ->where('co.event_id', $event->id)
            ->where('co.status', 'paid')
            ->where('coi.type', 'ticket')
            ->distinct()
            ->count('co.id');

        return [
            'summary' => [
                'ticket_types_count' => $ticketRows->count(),
                'capacity' => (int) $ticketRows->sum('capacity'),
                'issued_count' => (int) $ticketRows->sum('issued_count'),
                'sold_count' => (int) $ticketRows->sum('sold_count'),
                'courtesy_count' => (int) $ticketRows->sum('courtesy_count'),
                'checked_in_count' => (int) $ticketRows->sum('checked_in_count'),
                'reversed_count' => (int) $ticketRows->sum('reversed_count'),
                'reserved_count' => (int) $ticketRows->sum('reserved_count'),
                'available_count' => (int) $ticketRows->sum('available_count'),
                'paid_orders_count' => (int) $paidOrdersCount,
                'gross_revenue' => round((float) $ticketRows->sum('gross_revenue'), 2),
            ],
            'tickets' => $ticketRows,
            'recent_sales' => $recentSales,
        ];
    }

    private function emptySummary(): array
    {
        return [
            'ticket_types_count' => 0,
            'capacity' => 0,
            'issued_count' => 0,
            'sold_count' => 0,
            'courtesy_count' => 0,
            'checked_in_count' => 0,
            'reversed_count' => 0,
            'reserved_count' => 0,
            'available_count' => 0,
            'paid_orders_count' => 0,
            'gross_revenue' => 0.0,
        ];
    }
}
