<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\Establishment;
use App\Models\Order;
use App\Models\Item;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class ReportController extends Controller
{
    protected function getValidationMessages(): array
    {
        return [
            'entity_id.required'         => 'O ID da entidade é obrigatório.',
            'entity_id.integer'          => 'O ID da entidade deve ser um número inteiro.',
            'entity_name.required'       => 'O nome da entidade é obrigatório.',
            'entity_name.string'         => 'O nome da entidade deve ser uma string.',
            'entity_name.max'            => 'O nome da entidade não pode ter mais que 100 caracteres.',
            'period_start.required'      => 'O início do período é obrigatório.',
            'period_start.date'          => 'O início do período deve ser uma data válida.',
            'period_end.required'        => 'O fim do período é obrigatório.',
            'period_end.date'            => 'O fim do período deve ser uma data válida.',
            'period_end.after_or_equal'  => 'O fim do período deve ser igual ou posterior ao início.',
        ];
    }

    /**
     * Gera relatório de pedidos detalhado para uma entidade (sempre "establishment").
     */
    public function order(Request $request)
    {
        try {
            if (! Auth::check()) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $data = $request->validate([
                'entity_id'    => 'required|integer',
                'entity_name'  => 'required|string|max:100',
                'period_start' => 'required|date',
                'period_end'   => 'required|date|after_or_equal:period_start',
            ], $this->getValidationMessages());

            $start = Carbon::parse($data['period_start'])->startOfDay();
            $end   = Carbon::parse($data['period_end'])->endOfDay();

            // --- Cálculos principais ---
            $totalOrders    = Order::where('entity_name', $data['entity_name'])
                                   ->where('entity_id', $data['entity_id'])
                                   ->whereBetween('order_datetime', [$start, $end])
                                   ->count();

            $totalRevenue   = Order::where('entity_name', $data['entity_name'])
                                   ->where('entity_id', $data['entity_id'])
                                   ->whereBetween('order_datetime', [$start, $end])
                                   ->sum('total_price');

            $totalItemsSold = DB::table('order_items')
                                 ->join('orders','order_items.order_id','=','orders.id')
                                 ->where('orders.entity_name', $data['entity_name'])
                                 ->where('orders.entity_id', $data['entity_id'])
                                 ->whereBetween('orders.order_datetime', [$start, $end])
                                 ->sum('order_items.quantity');

            $avgTicketValue   = $totalOrders ? round($totalRevenue / $totalOrders, 2) : 0;
            $avgItemsPerOrder = $totalOrders ? round($totalItemsSold / $totalOrders, 2) : 0;

            // --- Breakdown por item ---
            $itemsRaw = DB::table('order_items')
                          ->join('orders','order_items.order_id','=','orders.id')
                          ->select('order_items.item_id', DB::raw('SUM(order_items.quantity) as quantity'))
                          ->where('orders.entity_name', $data['entity_name'])
                          ->where('orders.entity_id', $data['entity_id'])
                          ->whereBetween('orders.order_datetime', [$start, $end])
                          ->groupBy('order_items.item_id')
                          ->get();

            $itemsBreakdown = $itemsRaw->map(function($r) {
                $item = Item::find($r->item_id);
                return [
                    'item_id'   => $r->item_id,
                    'item_name' => $item->name ?? '-',
                    'quantity'  => (int)$r->quantity,
                ];
            })->toArray();

            // --- Top clientes por gasto ---
            $topRaw = Order::select('client_id', DB::raw('COUNT(*) as orders_count'), DB::raw('SUM(total_price) as total_spent'))
                           ->where('entity_name', $data['entity_name'])
                           ->where('entity_id', $data['entity_id'])
                           ->whereBetween('order_datetime', [$start, $end])
                           ->groupBy('client_id')
                           ->orderByDesc('total_spent')
                           ->limit(5)
                           ->get();

            $topCustomers = $topRaw->map(function($r) {
                $user = User::find($r->client_id);
                return [
                    'customer_id'   => $r->client_id,
                    'customer_name' => $user->name ?? '-',
                    'orders_count'  => (int)$r->orders_count,
                    'total_spent'   => (float)$r->total_spent,
                ];
            })->toArray();

            // --- Taxa de cancelamento ---
            $cancellations   = Order::where('entity_name', $data['entity_name'])
                                    ->where('entity_id', $data['entity_id'])
                                    ->where('status','cancelled')
                                    ->whereBetween('order_datetime', [$start, $end])
                                    ->count();

            $cancellationRate = $totalOrders
                              ? round(($cancellations / $totalOrders) * 100, 2)
                              : 0;

            // --- Pico de horas (intervalo de 1h) ---
            $peakRaw = Order::select(DB::raw('HOUR(order_datetime) as hour'), DB::raw('COUNT(*) as count'))
                           ->where('entity_name', $data['entity_name'])
                           ->where('entity_id', $data['entity_id'])
                           ->whereBetween('order_datetime', [$start, $end])
                           ->groupBy('hour')
                           ->get();

            $peakHours = [];
            for ($h = 0; $h < 24; $h++) {
                $label = sprintf('%02d:00-%02d:00', $h, ($h+1)%24);
                $peakHours[$label] = $peakRaw->firstWhere('hour',$h)->count ?? 0;
            }

            // --- Receita por canal/origem ---
            $channelRaw = Order::select('origin as channel', DB::raw('SUM(total_price) as amount'))
                               ->where('entity_name', $data['entity_name'])
                               ->where('entity_id', $data['entity_id'])
                               ->whereBetween('order_datetime', [$start, $end])
                               ->groupBy('origin')
                               ->get()
                               ->map(fn($r) => [
                                   'channel' => $r->channel,
                                   'amount'  => (float)$r->amount
                               ])
                               ->toArray();

            // --- Persistência completa (todos os campos obrigatórios) ---
            $report = Report::create([
                'entity_id'                  => $data['entity_id'],
                'entity_name'                => $data['entity_name'],
                'report_type'                => 'order',
                'period_start'               => $start,
                'period_end'                 => $end,
                'cash_flow'                  => $totalRevenue,
                'gross_profit'               => $totalRevenue,
                'net_profit'                 => $totalRevenue,
                'total_expenses'             => 0,
                'total_items_sold'           => $totalItemsSold,
                'avg_items_per_order'        => $avgItemsPerOrder,
                'avg_ticket_per_customer'    => $avgTicketValue,
                'visit_frequency'            => $avgItemsPerOrder
                                                ? round($totalOrders/($totalItemsSold/$avgItemsPerOrder),2)
                                                : 0,
                'revenue_by_channel'         => $channelRaw,
                'avg_service_time'           => DB::table('orders')
                                                   ->where('entity_name', $data['entity_name'])
                                                   ->where('entity_id', $data['entity_id'])
                                                   ->where('payment_status','paid')
                                                   ->whereBetween('order_datetime',[$start,$end])
                                                   ->value(DB::raw('AVG(TIMESTAMPDIFF(MINUTE, order_datetime, updated_at))'))
                                                ?? 0,
                'cancellation_rate'          => $cancellationRate,
                'peak_hours'                 => $peakHours,
                'breakdown_by_item'          => $itemsBreakdown,
                'top_customers'              => $topCustomers,
                'resource_utilization'       => 0,
                'new_customers_count'        => 0,
                'returning_customers_count'  => 0,
                'satisfaction_index'         => 0,
                'stock_turnover'             => 0,
                'reorder_alerts_count'       => 0,
                'raw_material_cost'          => 0,
                'individual_performance'     => [],
                'labor_efficiency'           => 0,
                'commissions_and_bonuses'    => 0,
                'campaign_roi'               => 0,
                'promotion_conversion_rate'  => 0,
                'lead_origin'                => [],
                'barbershop_completion_rate' => 0,
                'avg_service_time_barbershop'=> 0,
                'restaurant_prep_time'       => 0,
                'table_turnover_rate'        => 0,
                'legal_cases_opened_count'   => 0,
                'legal_cases_closed_count'   => 0,
                'avg_legal_case_duration'    => 0,
                'hospital_bed_occupancy_rate'=> 0,
                'hospital_readmission_rate'  => 0,
                'avg_hospital_stay_duration' => 0,
                'endpoint_usage'             => [],
                'error_rate'                 => 0,
                'avg_latency'                => 0,
                'auth_login_attempts_count'  => 0,
                'auth_login_failures_count'  => 0,
            ]);

            $establishment = Establishment::find($data['entity_id']);
            $orders        = Order::with(['items.item','items.modifiers'])
                                  ->where('entity_name',$data['entity_name'])
                                  ->where('entity_id',$data['entity_id'])
                                  ->whereBetween('order_datetime',[$start,$end])
                                  ->get();

            return response()->json([
                'message'           => 'Relatório de pedidos gerado com sucesso.',
                'report'            => $report->fresh(),
                'entity'            => $establishment,
                'orders'            => $orders,
                'items_breakdown'   => $itemsBreakdown,
                'top_customers'     => $topCustomers,
            ], 201);

        } catch (ValidationException $e) {
            Log::warning('Erro de validação no relatório de pedidos.', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            Log::error('Erro ao gerar relatório de pedidos: '.$e->getMessage());
            return response()->json(['error' => 'Erro ao gerar relatório.'], 500);
        }
    }
}
