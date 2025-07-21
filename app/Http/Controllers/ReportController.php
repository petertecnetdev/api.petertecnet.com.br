<?php

namespace App\Http\Controllers;

use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportController extends Controller
{
    public function order(Request $request)
    {
        $request->validate([
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after_or_equal:period_start',
        ]);

        $start = Carbon::parse($request->input('period_start'))->startOfDay();
        $end   = Carbon::parse($request->input('period_end'))->endOfDay();

        $totalOrders = DB::table('orders')
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $totalRevenue = DB::table('orders')
            ->whereBetween('created_at', [$start, $end])
            ->sum('total');

        $totalItemsSold = DB::table('order_items')
            ->join('orders','order_items.order_id','orders.id')
            ->whereBetween('orders.created_at', [$start, $end])
            ->sum('order_items.quantity');

        $averageTicketValue    = $totalOrders ? round($totalRevenue / $totalOrders, 2) : 0;
        $averageItemsPerOrder  = $totalOrders ? round($totalItemsSold / $totalOrders, 2) : 0;

        $newCustomersCount = DB::table('users')
            ->leftJoin('orders', 'users.id', '=', 'orders.user_id')
            ->groupBy('users.id')
            ->havingRaw('MIN(orders.created_at) BETWEEN ? AND ?', [$start, $end])
            ->get()->count();

        $returningCustomersCount = DB::table('orders as o1')
            ->join('orders as o2', 'o1.user_id', '=', 'o2.user_id')
            ->whereBetween('o1.created_at', [$start, $end])
            ->where('o2.created_at', '<', $start)
            ->groupBy('o1.user_id')
            ->get()->count();

        $cancellationCount = DB::table('orders')
            ->where('status', 'cancelled')
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $cancellationRate = $totalOrders ? round(($cancellationCount / $totalOrders) * 100, 2) : 0;

        $breakdownByItem = DB::table('order_items')
            ->join('orders','order_items.order_id','orders.id')
            ->select('order_items.item_name', DB::raw('SUM(order_items.quantity) as total'))
            ->whereBetween('orders.created_at', [$start, $end])
            ->groupBy('order_items.item_name')
            ->pluck('total','order_items.item_name');

        $breakdownByPaymentMethod = DB::table('orders')
            ->select('payment_method', DB::raw('COUNT(*) as count'))
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('payment_method')
            ->pluck('count','payment_method');

        $breakdownByChannel = DB::table('orders')
            ->select('origin', DB::raw('COUNT(*) as count'))
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('origin')
            ->pluck('count','origin');

        $topCustomers = DB::table('orders')
            ->select('user_id', DB::raw('COUNT(*) as orders_count'))
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('user_id')
            ->orderByDesc('orders_count')
            ->limit(5)
            ->get()
            ->map(function($row){
                return ['user_id' => $row->user_id, 'orders_count' => $row->orders_count];
            });

        $peakHours = DB::table('orders')
            ->select(DB::raw('HOUR(created_at) as hour'), DB::raw('COUNT(*) as count'))
            ->whereBetween('created_at', [$start, $end])
            ->groupBy(DB::raw('HOUR(created_at)'))
            ->orderBy('hour')
            ->pluck('count','hour');

        $report = Report::create([
            'report_type'                   => 'order',
            'period_start'                  => $start,
            'period_end'                    => $end,
            'cash_flow'                     => $totalRevenue,
            'gross_profit'                  => $totalRevenue,
            'net_profit'                    => $totalRevenue,
            'total_expenses'                => 0,
            'revenue_by_channel'            => $breakdownByChannel,
            'avg_service_time'              => 0,
            'cancellation_rate'             => $cancellationRate,
            'peak_hours'                    => $peakHours,
            'resource_utilization'          => 0,
            'new_customers_count'           => $newCustomersCount,
            'returning_customers_count'     => $returningCustomersCount,
            'avg_ticket_per_customer'       => $averageTicketValue,
            'visit_frequency'               => 0,
            'satisfaction_index'            => 0,
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

        return response()->json($report, 201);
    }
}
