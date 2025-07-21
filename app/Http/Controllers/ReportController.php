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

            // --- Métricas básicas ---
            $totalOrders      = DB::table('orders')
                ->where('entity_name', $data['entity_name'])
                ->where('entity_id',   $data['entity_id'])
                ->whereBetween('order_datetime', [$start, $end])
                ->count();

            $totalRevenue     = DB::table('orders')
                ->where('entity_name', $data['entity_name'])
                ->where('entity_id',   $data['entity_id'])
                ->whereBetween('order_datetime', [$start, $end])
                ->sum('total_price');

            $totalItemsSold   = DB::table('order_items')
                ->join('orders','order_items.order_id','=','orders.id')
                ->where('orders.entity_name', $data['entity_name'])
                ->where('orders.entity_id',   $data['entity_id'])
                ->whereBetween('orders.order_datetime', [$start, $end])
                ->sum('order_items.quantity');

            $avgTicketValue     = $totalOrders ? round($totalRevenue / $totalOrders, 2) : 0;
            $avgItemsPerOrder   = $totalOrders ? round($totalItemsSold / $totalOrders, 2) : 0;

            // --- Quebra por item ---
            $breakdownByItem = DB::table('order_items')
                ->join('orders','order_items.order_id','=','orders.id')
                ->select('order_items.item_id', DB::raw('SUM(order_items.quantity) as total'))
                ->where('orders.entity_name', $data['entity_name'])
                ->where('orders.entity_id',   $data['entity_id'])
                ->whereBetween('orders.order_datetime', [$start, $end])
                ->groupBy('order_items.item_id')
                ->pluck('total','order_items.item_id')
                ->toArray();

            // --- Top clientes ---
            $topCustomersRaw = DB::table('orders')
                ->where('entity_name', $data['entity_name'])
                ->where('entity_id',   $data['entity_id'])
                ->select('client_id', DB::raw('COUNT(*) as orders_count'))
                ->whereBetween('order_datetime', [$start, $end])
                ->groupBy('client_id')
                ->orderByDesc('orders_count')
                ->limit(5)
                ->get()
                ->toArray();

            // --- Cancelamentos & pico de hora ---
            $cancellationCount = DB::table('orders')
                ->where('entity_name', $data['entity_name'])
                ->where('entity_id',   $data['entity_id'])
                ->where('status','cancelled')
                ->whereBetween('order_datetime', [$start, $end])
                ->count();

            $cancellationRate = $totalOrders
                ? round(($cancellationCount / $totalOrders) * 100, 2)
                : 0;

            $peakHours = DB::table('orders')
                ->select(DB::raw('HOUR(order_datetime) as hour'), DB::raw('COUNT(*) as total'))
                ->where('entity_name', $data['entity_name'])
                ->where('entity_id',   $data['entity_id'])
                ->whereBetween('order_datetime', [$start, $end])
                ->groupBy('hour')
                ->pluck('total','hour')
                ->toArray();

            // --- Serviço & frequência ---
            $avgServiceTime = DB::table('orders')
                ->where('entity_name', $data['entity_name'])
                ->where('entity_id',   $data['entity_id'])
                ->whereBetween('order_datetime', [$start, $end])
                ->where('payment_status','paid')
                ->value(DB::raw('AVG(TIMESTAMPDIFF(MINUTE, order_datetime, updated_at))'));

            $visitFrequency = ($avgItemsPerOrder && $totalItemsSold)
                ? round($totalOrders / ($totalItemsSold / $avgItemsPerOrder), 2)
                : 0;

            // --- Persiste o relatório ---
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
                'visit_frequency'            => $visitFrequency,
                'revenue_by_channel'         => [], // deixe vazio ou preencha se quiser
                'avg_service_time'           => $avgServiceTime ?: 0,
                'cancellation_rate'          => $cancellationRate,
                'peak_hours'                 => $peakHours,
                'breakdown_by_item'          => $breakdownByItem,
                'top_customers'              => $topCustomersRaw,
                // demais campos podem ficar default
                'lead_origin'                => [],
                'satisfaction_index'         => 0,
                'stock_turnover'             => 0,
                'reorder_alerts_count'       => 0,
                'raw_material_cost'          => 0,
                'individual_performance'     => [],
                'labor_efficiency'           => 0,
                'commissions_and_bonuses'    => 0,
                'campaign_roi'               => 0,
                'promotion_conversion_rate'  => 0,
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

            // --- Carrega entidade e pedidos completos ---
            $establishment = Establishment::find($data['entity_id']);
            $orders = Order::with(['items.item', 'items.modifiers'])
                ->where('entity_name', $data['entity_name'])
                ->where('entity_id',   $data['entity_id'])
                ->whereBetween('order_datetime', [$start, $end])
                ->get();

            // --- Monta detalhes de itens com top customer por item ---
            $itemsDetail = collect($breakdownByItem)->map(function($quantity, $itemId) use($data, $start, $end) {
                $item = Item::find($itemId);

                // top customer daquele item
                $top = DB::table('order_items')
                    ->join('orders','order_items.order_id','=','orders.id')
                    ->select('orders.client_id', DB::raw('SUM(order_items.quantity) as sum_qty'))
                    ->where('order_items.item_id', $itemId)
                    ->where('orders.entity_name', $data['entity_name'])
                    ->where('orders.entity_id',   $data['entity_id'])
                    ->whereBetween('orders.order_datetime', [$start, $end])
                    ->groupBy('orders.client_id')
                    ->orderByDesc('sum_qty')
                    ->first();

                $user = $top ? User::find($top->client_id) : null;

                return [
                    'item'         => $item,
                    'quantity'     => $quantity,
                    'top_customer' => $user,
                ];
            })->values()->all();

            // --- Detalhe de top customers completos ---
            $topCustomersDetail = collect($topCustomersRaw)->map(function($r) {
                return [
                    'customer'     => User::find($r->client_id),
                    'orders_count' => $r->orders_count,
                ];
            })->values()->all();

            return response()->json([
                'message'               => 'Relatório de pedidos gerado com sucesso.',
                'report'                => $report->fresh(),
                'entity'                => $establishment,
                'orders'                => $orders,
                'items_breakdown'       => $itemsDetail,
                'top_customers_detail'  => $topCustomersDetail,
            ], 201);

        } catch (ValidationException $e) {
            Log::warning('Erro de validação no relatório de pedidos.', ['errors'=>$e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            Log::error('Erro ao gerar relatório de pedidos: '.$e->getMessage());
            return response()->json(['error' => 'Erro ao gerar relatório.'], 500);
        }
    }
}
