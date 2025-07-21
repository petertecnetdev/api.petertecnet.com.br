<?php

namespace App\Http\Controllers;

use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class ReportController extends Controller
{
    /**
     * Mensagens customizadas de validação.
     */
    protected function getValidationMessages(): array
    {
        return [
            'period_start.required'     => 'O início do período é obrigatório.',
            'period_start.date'         => 'O início do período deve ser uma data válida.',
            'period_end.required'       => 'O fim do período é obrigatório.',
            'period_end.date'           => 'O fim do período deve ser uma data válida.',
            'period_end.after_or_equal' => 'O fim do período deve ser igual ou posterior ao início.',
        ];
    }

    /**
     * Gera relatório de pedidos detalhado.
     */
    public function order(Request $request)
    {
        try {
            if (! Auth::check()) {
                Log::warning('Usuário não autenticado tentou gerar relatório de pedidos.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();
            Log::info('Gerando relatório de pedidos iniciado.', [
                'user_id'      => $user->id,
                'period_start' => $request->input('period_start'),
                'period_end'   => $request->input('period_end'),
            ]);

            // validação
            $data = $request->validate([
                'period_start' => 'required|date',
                'period_end'   => 'required|date|after_or_equal:period_start',
            ], $this->getValidationMessages());

            $start = Carbon::parse($data['period_start'])->startOfDay();
            $end   = Carbon::parse($data['period_end'])->endOfDay();

            // --- Métricas básicas ---
            $totalOrders      = DB::table('orders')->whereBetween('order_datetime', [$start, $end])->count();
            $totalRevenue     = DB::table('orders')->whereBetween('order_datetime', [$start, $end])->sum('total_price');
            $totalItemsSold   = DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->whereBetween('orders.order_datetime', [$start, $end])
                ->sum('order_items.quantity');

            $avgTicketValue   = $totalOrders ? round($totalRevenue / $totalOrders, 2) : 0;
            $avgItemsPerOrder = $totalOrders ? round($totalItemsSold / $totalOrders, 2) : 0;

            // --- Quebras ---
            $breakdownByItem = DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->select('order_items.item_id', DB::raw('SUM(order_items.quantity) as total'))
                ->whereBetween('orders.order_datetime', [$start, $end])
                ->groupBy('order_items.item_id')
                ->pluck('total', 'order_items.item_id')
                ->toArray();

            $breakdownByChannel = DB::table('orders')
                ->select('origin', DB::raw('COUNT(*) as total'))
                ->whereBetween('order_datetime', [$start, $end])
                ->groupBy('origin')
                ->pluck('total', 'origin')
                ->toArray();

            $breakdownByPayment = DB::table('orders')
                ->select('payment_method', DB::raw('COUNT(*) as total'))
                ->whereBetween('order_datetime', [$start, $end])
                ->groupBy('payment_method')
                ->pluck('total', 'payment_method')
                ->toArray();

            // --- Clientes ---
            $newCustomersCount = DB::table('orders')
                ->select('client_id', DB::raw('MIN(order_datetime) as first_order'))
                ->groupBy('client_id')
                ->havingRaw('first_order BETWEEN ? AND ?', [$start, $end])
                ->count();

            $returningCustomersCount = DB::table('orders as o1')
                ->join('orders as o2', 'o1.client_id', '=', 'o2.client_id')
                ->whereBetween('o1.order_datetime', [$start, $end])
                ->where('o2.order_datetime', '<', $start)
                ->groupBy('o1.client_id')
                ->count();

            // --- Top Clientes ---
            $topCustomers = DB::table('orders')
                ->select('client_id', DB::raw('COUNT(*) as orders_count'))
                ->whereBetween('order_datetime', [$start, $end])
                ->groupBy('client_id')
                ->orderByDesc('orders_count')
                ->limit(5)
                ->get()
                ->map(fn($r) => [
                    'client_id'    => $r->client_id,
                    'orders_count' => $r->orders_count,
                ])
                ->toArray();

            // --- Cancelamentos e Pico ---
            $cancellationCount = DB::table('orders')
                ->where('status', 'cancelled')
                ->whereBetween('order_datetime', [$start, $end])
                ->count();

            $cancellationRate = $totalOrders
                ? round(($cancellationCount / $totalOrders) * 100, 2)
                : 0;

            $peakHours = DB::table('orders')
                ->select(DB::raw('HOUR(order_datetime) as hour'), DB::raw('COUNT(*) as total'))
                ->whereBetween('order_datetime', [$start, $end])
                ->groupBy('hour')
                ->orderBy('hour')
                ->pluck('total', 'hour')
                ->toArray();

            // --- Outros ---
            $avgServiceTime = DB::table('orders')
                ->whereBetween('order_datetime', [$start, $end])
                ->where('payment_status', 'paid')
                ->value(DB::raw('AVG(TIMESTAMPDIFF(MINUTE, order_datetime, updated_at))'));

            $visitFrequency = ($newCustomersCount + $returningCustomersCount)
                ? round($totalOrders / ($newCustomersCount + $returningCustomersCount), 2)
                : 0;

            // --- Persiste ---
            $report = Report::create([
                'report_type'                   => 'order',
                'period_start'                  => $start,
                'period_end'                    => $end,
                'cash_flow'                     => $totalRevenue,
                'gross_profit'                  => $totalRevenue,
                'net_profit'                    => $totalRevenue,
                'total_expenses'                => 0,
                'revenue_by_channel'            => $breakdownByChannel,
                'avg_service_time'              => $avgServiceTime ?: 0,
                'cancellation_rate'             => $cancellationRate,
                'peak_hours'                    => $peakHours,
                'resource_utilization'          => 0,
                'new_customers_count'           => $newCustomersCount,
                'returning_customers_count'     => $returningCustomersCount,
                'avg_ticket_per_customer'       => $avgTicketValue,
                'visit_frequency'               => $visitFrequency,
                'satisfaction_index'            => 0,
                'stock_turnover'                => 0,
                'reorder_alerts_count'          => 0,
                'raw_material_cost'             => 0,
                'individual_performance'        => [],
                'labor_efficiency'              => 0,
                'commissions_and_bonuses'       => 0,
                'campaign_roi'                  => 0,
                'promotion_conversion_rate'     => 0,
                'lead_origin'                   => $breakdownByPayment,
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
                'top_customers'                 => $topCustomers,
            ]);

            Log::info('Relatório de pedidos gerado com sucesso.', ['report_id' => $report->id]);

            return response()->json([
                'message' => 'Relatório de pedidos gerado com sucesso.',
                'report'  => $report->fresh(),
            ], 201);

        } catch (ValidationException $e) {
            Log::warning('Erro de validação ao gerar relatório de pedidos.', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            Log::error('Erro ao gerar relatório de pedidos: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao gerar o relatório.'], 500);
        }
    }
}
