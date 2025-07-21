<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Report;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;

class ReportController extends BaseController
{
    public function order(Request $request, $entityId)
    {
        $entityName = 'establishment';
        $periodStart = $request->input('period_start')
            ? Carbon::parse($request->input('period_start'))
            : Carbon::now()->startOfDay();
        $periodEnd = $request->input('period_end')
            ? Carbon::parse($request->input('period_end'))
            : Carbon::now()->endOfDay();

        $orders = Order::where('entity_name', $entityName)
            ->where('entity_id', $entityId)
            ->whereBetween('order_datetime', [$periodStart, $periodEnd])
            ->with(['items.modifiers'])
            ->get();

        $totalOrders = $orders->count();
        $cashFlow = $orders->sum('total_price');
        $revenueByChannel = $orders
            ->groupBy('origin')
            ->map(fn($group) => $group->sum('total_price'))
            ->toArray();

        $canceled = $orders->where('status', 'cancelled')->count();
        $cancellationRate = $totalOrders
            ? round($canceled / $totalOrders * 100, 2)
            : 0;

        $hours = $orders->map(fn($o) => $o->order_datetime->format('H'))->countBy()->toArray();

        $items = [];
        foreach ($orders as $order) {
            foreach ($order->items as $oi) {
                $items[$oi->item_id]['quantity'] = ($items[$oi->item_id]['quantity'] ?? 0) + $oi->quantity;
                $items[$oi->item_id]['subtotal'] = ($items[$oi->item_id]['subtotal'] ?? 0) + $oi->subtotal;
            }
        }

        $customers = $orders->groupBy('customer_phone')->map(fn($g) => $g->sum('total_price'));
        $topCustomers = $customers->sortDesc()->take(5)->map(fn($amt, $phone) => ['phone' => $phone, 'total' => $amt])->values()->toArray();

        $avgTicket = $totalOrders ? round($cashFlow / $totalOrders, 2) : 0;

        $report = Report::create([
            'entity_id'                     => $entityId,
            'entity_name'                   => $entityName,
            'report_type'                   => 'order',
            'period_start'                  => $periodStart,
            'period_end'                    => $periodEnd,
            'cash_flow'                     => $cashFlow,
            'gross_profit'                  => $cashFlow,
            'net_profit'                    => $cashFlow,
            'total_expenses'                => 0,
            'revenue_by_channel'            => $revenueByChannel,
            'avg_service_time'              => 0,
            'cancellation_rate'             => $cancellationRate,
            'peak_hours'                    => $hours,
            'resource_utilization'          => 0,
            'new_customers_count'           => $orders->where('created_at', '>=', $periodStart)->count(),
            'returning_customers_count'     => $totalOrders - $orders->where('created_at', '>=', $periodStart)->count(),
            'avg_ticket_per_customer'       => $avgTicket,
            'visit_frequency'               => $customers->count() ? round($totalOrders / $customers->count(), 2) : 0,
            'top_customers'                 => $topCustomers,
            'breakdown_by_item'             => $items,
            'satisfaction_index'            => null,
            'stock_turnover'                => 0,
            'reorder_alerts_count'          => 0,
            'raw_material_cost'             => 0,
            'individual_performance'        => [],
            'labor_efficiency'              => 0,
            'commissions_and_bonuses'       => 0,
            'campaign_roi'                  => 0,
            'promotion_conversion_rate'     => 0,
            'lead_origin'                   => [],
            'barbershop_completion_rate'    => 0,
            'avg_service_time_barbershop'   => 0,
            'restaurant_prep_time'          => 0,
            'table_turnover_rate'           => 0,
            'legal_cases_opened_count'      => 0,
            'legal_cases_closed_count'      => 0,
            'avg_legal_case_duration'       => 0,
            'hospital_bed_occupancy_rate'   => 0,
            'hospital_readmission_rate'     => 0,
            'avg_hospital_stay_duration'    => 0,
            'endpoint_usage'                => [],
            'error_rate'                    => 0,
            'avg_latency'                   => 0,
            'auth_login_attempts_count'     => 0,
            'auth_login_failures_count'     => 0,
        ]);

        return response()->json([
            'message' => 'Relatório de pedidos gerado com sucesso.',
            'report'  => $report->fresh(),
        ], 201);
    }
}
