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
            ->where(function ($query) {
                $query->whereNull('status')->orWhereNotIn('status', self::INVALID_PASS_STATUSES);
            })
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
            ->where(function ($query) {
                $query->whereNull('ep.status')->orWhereNotIn('ep.status', self::INVALID_PASS_STATUSES);
            })
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

        $orderFinancials = DB::table('commerce_orders as co')
            ->selectRaw('co.event_id, COALESCE(SUM(co.platform_fee), 0) as platform_fee, COALESCE(SUM(co.processor_fee), 0) as processor_fee, COALESCE(SUM(co.discount_amount), 0) as discount_amount, COALESCE(SUM(co.producer_net), 0) as producer_net')
            ->whereIn('co.event_id', $eventIds)
            ->where('co.status', 'paid')
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('commerce_order_items as ticket_item')
                    ->whereColumn('ticket_item.order_id', 'co.id')
                    ->where('ticket_item.type', 'ticket');
            })
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

        return $events->map(function (Event $event) use ($ticketInventory, $activePasses, $reversedPasses, $soldPasses, $sales, $orderFinancials, $reservations) {
            $inventory = $ticketInventory->get($event->id);
            $passes = $activePasses->get($event->id);
            $reversed = $reversedPasses->get($event->id);
            $sold = $soldPasses->get($event->id);
            $sale = $sales->get($event->id);
            $financial = $orderFinancials->get($event->id);
            $reservation = $reservations->get($event->id);

            $ticketTypesCount = (int) ($inventory->ticket_types_count ?? 0);
            $capacity = (int) ($inventory->capacity ?? 0);
            $issued = (int) ($passes->issued_count ?? 0);
            $checkedIn = (int) ($passes->checked_in_count ?? 0);
            $soldCount = (int) ($sold->sold_count ?? 0);
            $reserved = (int) ($reservation->reserved_count ?? 0);
            $gross = round((float) ($sale->gross_revenue ?? 0), 2);

            // tickets_count existed before this analytics endpoint. Keep it as a
            // compatibility alias for the number of ticket types, while exposing
            // tickets_sold_count for the actual paid/valid passes.
            $event->setAttribute('tickets_count', $ticketTypesCount);
            $event->setAttribute('ticket_types_count', $ticketTypesCount);
            $event->setAttribute('ticket_capacity', $capacity);
            $event->setAttribute('tickets_issued_count', $issued);
            $event->setAttribute('tickets_sold_count', $soldCount);
            $event->setAttribute('courtesy_count', (int) ($passes->courtesy_count ?? 0));
            $event->setAttribute('checked_in_count', $checkedIn);
            $event->setAttribute('tickets_reversed_count', (int) ($reversed->reversed_count ?? 0));
            $event->setAttribute('tickets_reserved_count', $reserved);
            $event->setAttribute('tickets_available_count', max(0, $capacity - $issued - $reserved));
            $event->setAttribute('paid_orders_count', (int) ($sale->paid_orders_count ?? 0));
            $event->setAttribute('gross_ticket_revenue', $gross);
            $event->setAttribute('producer_net_revenue', round((float) ($financial->producer_net ?? 0), 2));
            $event->setAttribute('platform_fee_total', round((float) ($financial->platform_fee ?? 0), 2));
            $event->setAttribute('processor_fee_total', round((float) ($financial->processor_fee ?? 0), 2));
            $event->setAttribute('discount_total', round((float) ($financial->discount_amount ?? 0), 2));
            $event->setAttribute('occupancy_rate', $this->percentage($issued, $capacity));
            $event->setAttribute('attendance_rate', $this->percentage($checkedIn, $issued));
            $event->setAttribute('average_ticket_value', $soldCount > 0 ? round($gross / $soldCount, 2) : 0.0);

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
                'financial' => $this->emptyFinancial(),
                'funnel' => $this->emptyFunnel(),
                'tickets' => [],
                'recent_sales' => [],
                'sales_timeline' => [],
                'payment_methods' => [],
                'order_statuses' => [],
            ];
        }

        $passStats = DB::table('event_passes')
            ->selectRaw('ticket_id, COUNT(*) as issued_count, SUM(CASE WHEN checked_in_at IS NOT NULL THEN 1 ELSE 0 END) as checked_in_count, SUM(CASE WHEN commerce_order_item_id IS NULL THEN 1 ELSE 0 END) as courtesy_count')
            ->whereIn('ticket_id', $ticketIds)
            ->where(function ($query) {
                $query->whereNull('status')->orWhereNotIn('status', self::INVALID_PASS_STATUSES);
            })
            ->groupBy('ticket_id')
            ->get()
            ->keyBy('ticket_id');

        $soldStats = DB::table('event_passes as ep')
            ->join('commerce_order_items as coi', 'coi.id', '=', 'ep.commerce_order_item_id')
            ->join('commerce_orders as co', 'co.id', '=', 'coi.order_id')
            ->selectRaw('ep.ticket_id, COUNT(*) as sold_count')
            ->whereIn('ep.ticket_id', $ticketIds)
            ->where('co.status', 'paid')
            ->where(function ($query) {
                $query->whereNull('ep.status')->orWhereNotIn('ep.status', self::INVALID_PASS_STATUSES);
            })
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
            $soldCount = (int) ($sold->sold_count ?? 0);
            $checkedIn = (int) ($passes->checked_in_count ?? 0);
            $reserved = (int) ($reservation->reserved_count ?? 0);
            $gross = round((float) ($sales->gross_revenue ?? 0), 2);

            return [
                'id' => $ticket->id,
                'name' => $ticket->name,
                'type' => $ticket->type,
                'ticket_type' => $ticket->ticket_type,
                'price' => (float) $ticket->price,
                'capacity' => $capacity,
                'issued_count' => $issued,
                'sold_count' => $soldCount,
                'courtesy_count' => (int) ($passes->courtesy_count ?? 0),
                'checked_in_count' => $checkedIn,
                'reversed_count' => (int) ($reversed->reversed_count ?? 0),
                'reserved_count' => $reserved,
                'available_count' => max(0, $capacity - $issued - $reserved),
                'paid_orders_count' => (int) ($sales->paid_orders_count ?? 0),
                'gross_revenue' => $gross,
                'occupancy_rate' => $this->percentage($issued, $capacity),
                'attendance_rate' => $this->percentage($checkedIn, $issued),
                'average_ticket_value' => $soldCount > 0 ? round($gross / $soldCount, 2) : 0.0,
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
            ->where(function ($query) {
                $query->whereNull('ep.status')->orWhereNotIn('ep.status', self::INVALID_PASS_STATUSES);
            })
            ->orderByDesc('co.paid_at')
            ->orderByDesc('ep.id')
            ->limit(100)
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

        $financialRow = DB::table('commerce_orders as co')
            ->where('co.event_id', $event->id)
            ->where('co.status', 'paid')
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('commerce_order_items as ticket_item')
                    ->whereColumn('ticket_item.order_id', 'co.id')
                    ->where('ticket_item.type', 'ticket');
            })
            ->selectRaw('COUNT(*) as paid_orders_count, COALESCE(SUM(co.subtotal), 0) as order_subtotal, COALESCE(SUM(co.platform_fee), 0) as platform_fee, COALESCE(SUM(co.processor_fee), 0) as processor_fee, COALESCE(SUM(co.discount_amount), 0) as discount_amount, COALESCE(SUM(co.total), 0) as total_collected, COALESCE(SUM(co.producer_net), 0) as producer_net')
            ->first();

        $allOrderStatuses = DB::table('commerce_orders as co')
            ->where('co.event_id', $event->id)
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('commerce_order_items as ticket_item')
                    ->whereColumn('ticket_item.order_id', 'co.id')
                    ->where('ticket_item.type', 'ticket');
            })
            ->selectRaw("COALESCE(co.status, 'unknown') as status, COUNT(*) as orders_count")
            ->groupBy('co.status')
            ->orderByDesc('orders_count')
            ->get()
            ->map(fn ($row) => [
                'status' => (string) $row->status,
                'orders_count' => (int) $row->orders_count,
            ])
            ->values();

        $checkoutOrdersCount = (int) $allOrderStatuses->sum('orders_count');
        $paidOrdersCount = (int) ($financialRow->paid_orders_count ?? 0);

        $salesTimeline = DB::table('event_passes as ep')
            ->join('commerce_order_items as coi', 'coi.id', '=', 'ep.commerce_order_item_id')
            ->join('commerce_orders as co', 'co.id', '=', 'coi.order_id')
            ->where('ep.event_id', $event->id)
            ->where('co.status', 'paid')
            ->whereNotNull('co.paid_at')
            ->where(function ($query) {
                $query->whereNull('ep.status')->orWhereNotIn('ep.status', self::INVALID_PASS_STATUSES);
            })
            ->selectRaw('DATE(co.paid_at) as bucket_date, COUNT(*) as sold_count, COUNT(DISTINCT co.id) as paid_orders_count, COALESCE(SUM(coi.unit_price), 0) as gross_revenue')
            ->groupByRaw('DATE(co.paid_at)')
            ->orderByRaw('DATE(co.paid_at) DESC')
            ->limit(30)
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($row) => [
                'date' => (string) $row->bucket_date,
                'sold_count' => (int) $row->sold_count,
                'paid_orders_count' => (int) $row->paid_orders_count,
                'gross_revenue' => round((float) $row->gross_revenue, 2),
            ]);

        $paymentMethods = DB::table('event_passes as ep')
            ->join('commerce_order_items as coi', 'coi.id', '=', 'ep.commerce_order_item_id')
            ->join('commerce_orders as co', 'co.id', '=', 'coi.order_id')
            ->where('ep.event_id', $event->id)
            ->where('co.status', 'paid')
            ->where(function ($query) {
                $query->whereNull('ep.status')->orWhereNotIn('ep.status', self::INVALID_PASS_STATUSES);
            })
            ->selectRaw("COALESCE(co.payment_method, 'Não informado') as payment_method, COUNT(*) as sold_count, COUNT(DISTINCT co.id) as paid_orders_count, COALESCE(SUM(coi.unit_price), 0) as gross_revenue")
            ->groupBy('co.payment_method')
            ->orderByDesc('sold_count')
            ->get()
            ->map(fn ($row) => [
                'payment_method' => (string) $row->payment_method,
                'sold_count' => (int) $row->sold_count,
                'paid_orders_count' => (int) $row->paid_orders_count,
                'gross_revenue' => round((float) $row->gross_revenue, 2),
            ])
            ->values();

        $last24Hours = $this->salesWindow($event, now()->subDay());
        $last7Days = $this->salesWindow($event, now()->subDays(7));

        $capacity = (int) $ticketRows->sum('capacity');
        $issued = (int) $ticketRows->sum('issued_count');
        $soldCount = (int) $ticketRows->sum('sold_count');
        $checkedIn = (int) $ticketRows->sum('checked_in_count');
        $grossRevenue = round((float) $ticketRows->sum('gross_revenue'), 2);

        $summary = [
            'ticket_types_count' => $ticketRows->count(),
            'capacity' => $capacity,
            'issued_count' => $issued,
            'sold_count' => $soldCount,
            'courtesy_count' => (int) $ticketRows->sum('courtesy_count'),
            'checked_in_count' => $checkedIn,
            'reversed_count' => (int) $ticketRows->sum('reversed_count'),
            'reserved_count' => (int) $ticketRows->sum('reserved_count'),
            'available_count' => (int) $ticketRows->sum('available_count'),
            'paid_orders_count' => $paidOrdersCount,
            'gross_revenue' => $grossRevenue,
            'producer_net_revenue' => round((float) ($financialRow->producer_net ?? 0), 2),
            'occupancy_rate' => $this->percentage($issued, $capacity),
            'attendance_rate' => $this->percentage($checkedIn, $issued),
            'average_ticket_value' => $soldCount > 0 ? round($grossRevenue / $soldCount, 2) : 0.0,
            'last_24h_sold_count' => $last24Hours['sold_count'],
            'last_24h_revenue' => $last24Hours['gross_revenue'],
            'last_7d_sold_count' => $last7Days['sold_count'],
            'last_7d_revenue' => $last7Days['gross_revenue'],
        ];

        $financial = [
            'ticket_gross_revenue' => $grossRevenue,
            'order_subtotal' => round((float) ($financialRow->order_subtotal ?? 0), 2),
            'platform_fee' => round((float) ($financialRow->platform_fee ?? 0), 2),
            'processor_fee' => round((float) ($financialRow->processor_fee ?? 0), 2),
            'discount_amount' => round((float) ($financialRow->discount_amount ?? 0), 2),
            'total_collected' => round((float) ($financialRow->total_collected ?? 0), 2),
            'producer_net' => round((float) ($financialRow->producer_net ?? 0), 2),
        ];

        $funnel = [
            'checkout_orders_count' => $checkoutOrdersCount,
            'paid_orders_count' => $paidOrdersCount,
            'tickets_sold_count' => $soldCount,
            'tickets_issued_count' => $issued,
            'checked_in_count' => $checkedIn,
            'payment_conversion_rate' => $this->percentage($paidOrdersCount, $checkoutOrdersCount),
            'attendance_rate' => $this->percentage($checkedIn, $issued),
        ];

        return [
            'summary' => $summary,
            'financial' => $financial,
            'funnel' => $funnel,
            'tickets' => $ticketRows,
            'recent_sales' => $recentSales,
            'sales_timeline' => $salesTimeline,
            'payment_methods' => $paymentMethods,
            'order_statuses' => $allOrderStatuses,
        ];
    }

    private function salesWindow(Event $event, $since): array
    {
        $row = DB::table('event_passes as ep')
            ->join('commerce_order_items as coi', 'coi.id', '=', 'ep.commerce_order_item_id')
            ->join('commerce_orders as co', 'co.id', '=', 'coi.order_id')
            ->where('ep.event_id', $event->id)
            ->where('co.status', 'paid')
            ->where('co.paid_at', '>=', $since)
            ->where(function ($query) {
                $query->whereNull('ep.status')->orWhereNotIn('ep.status', self::INVALID_PASS_STATUSES);
            })
            ->selectRaw('COUNT(*) as sold_count, COALESCE(SUM(coi.unit_price), 0) as gross_revenue')
            ->first();

        return [
            'sold_count' => (int) ($row->sold_count ?? 0),
            'gross_revenue' => round((float) ($row->gross_revenue ?? 0), 2),
        ];
    }

    private function percentage(int $value, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(min(100, max(0, ($value / $total) * 100)), 1);
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
            'producer_net_revenue' => 0.0,
            'occupancy_rate' => 0.0,
            'attendance_rate' => 0.0,
            'average_ticket_value' => 0.0,
            'last_24h_sold_count' => 0,
            'last_24h_revenue' => 0.0,
            'last_7d_sold_count' => 0,
            'last_7d_revenue' => 0.0,
        ];
    }

    private function emptyFinancial(): array
    {
        return [
            'ticket_gross_revenue' => 0.0,
            'order_subtotal' => 0.0,
            'platform_fee' => 0.0,
            'processor_fee' => 0.0,
            'discount_amount' => 0.0,
            'total_collected' => 0.0,
            'producer_net' => 0.0,
        ];
    }

    private function emptyFunnel(): array
    {
        return [
            'checkout_orders_count' => 0,
            'paid_orders_count' => 0,
            'tickets_sold_count' => 0,
            'tickets_issued_count' => 0,
            'checked_in_count' => 0,
            'payment_conversion_rate' => 0.0,
            'attendance_rate' => 0.0,
        ];
    }
}
