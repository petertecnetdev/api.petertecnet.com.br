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

    public function order(Request $request)
    {
        try {
            if (!Auth::check()) {
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

            $totalOrders    = DB::table('orders')
                ->where('entity_name', $data['entity_name'])
                ->where('entity_id',   $data['entity_id'])
                ->whereBetween('order_datetime', [$start, $end])
                ->count();

            $totalRevenue   = DB::table('orders')
                ->where('entity_name', $data['entity_name'])
                ->where('entity_id',   $data['entity_id'])
                ->whereBetween('order_datetime', [$start, $end])
                ->sum('total_price');

            $totalItemsSold = DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->where('orders.entity_name', $data['entity_name'])
                ->where('orders.entity_id',   $data['entity_id'])
                ->whereBetween('orders.order_datetime', [$start, $end])
                ->sum('order_items.quantity');

            $avgTicketValue   = $totalOrders ? round($totalRevenue / $totalOrders, 2) : 0;
            $avgItemsPerOrder = $totalOrders ? round($totalItemsSold / $totalOrders, 2) : 0;

            $breakdownRaw = DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->select('order_items.item_id', DB::raw('SUM(order_items.quantity) as quantity'))
                ->where('orders.entity_name', $data['entity_name'])
                ->where('orders.entity_id',   $data['entity_id'])
                ->whereBetween('orders.order_datetime', [$start, $end])
                ->groupBy('order_items.item_id')
                ->get();

            $itemsBreakdown = $breakdownRaw->map(function ($row) {
                $item = Item::find($row->item_id);
                return [
                    'item_id'   => $row->item_id,
                    'item_name' => $item->name ?? '-',
                    'quantity'  => $row->quantity,
                ];
            })->toArray();

            $topRaw = DB::table('orders')
                ->where('entity_name', $data['entity_name'])
                ->where('entity_id',   $data['entity_id'])
                ->whereBetween('order_datetime', [$start, $end])
                ->select('client_id', DB::raw('COUNT(*) as orders_count'), DB::raw('SUM(total_price) as total_spent'))
                ->groupBy('client_id')
                ->orderByDesc('total_spent')
                ->limit(5)
                ->get();

            $topCustomers = $topRaw->map(function ($row) {
                $user = User::find($row->client_id);
                return [
                    'customer_id'   => $row->client_id,
                    'customer_name' => $user->name ?? '-',
                    'orders_count'  => $row->orders_count,
                    'total_spent'   => $row->total_spent,
                ];
            })->toArray();

            $cancellationCount = DB::table('orders')
                ->where('entity_name', $data['entity_name'])
                ->where('entity_id',   $data['entity_id'])
                ->where('status', 'cancelled')
                ->whereBetween('order_datetime', [$start, $end])
                ->count();

            $cancellationRate = $totalOrders ? round(($cancellationCount / $totalOrders) * 100, 2) : 0;

            $peakRaw = DB::table('orders')
                ->select(DB::raw('HOUR(order_datetime) as hour'), DB::raw('COUNT(*) as count'))
                ->where('entity_name', $data['entity_name'])
                ->where('entity_id',   $data['entity_id'])
                ->whereBetween('order_datetime', [$start, $end])
                ->groupBy('hour')
                ->get();

            $peakHours = [];
            for ($h = 0; $h < 24; $h++) {
                $interval = sprintf('%02d:00-%02d:00', $h, ($h + 1) % 24);
                $peakHours[$interval] = $peakRaw->firstWhere('hour', $h)->count ?? 0;
            }

            $avgServiceTime = DB::table('orders')
                ->where('entity_name', $data['entity_name'])
                ->where('entity_id',   $data['entity_id'])
                ->where('payment_status', 'paid')
                ->whereBetween('order_datetime', [$start, $end])
                ->value(DB::raw('AVG(TIMESTAMPDIFF(MINUTE, order_datetime, updated_at))')) ?? 0;

            $visitFrequency = ($avgItemsPerOrder && $totalItemsSold)
                ? round($totalOrders / ($totalItemsSold / $avgItemsPerOrder), 2)
                : 0;

            $channelRaw = DB::table('orders')
                ->select('origin as channel', DB::raw('SUM(total_price) as amount'))
                ->where('entity_name', $data['entity_name'])
                ->where('entity_id',   $data['entity_id'])
                ->whereBetween('order_datetime', [$start, $end])
                ->groupBy('origin')
                ->get();

            $revenueByChannel = $channelRaw->map(fn($row) => ['channel' => $row->channel, 'amount' => $row->amount])->toArray();

            $report = Report::create([
                'entity_id'               => $data['entity_id'],
                'entity_name'             => $data['entity_name'],
                'report_type'             => 'order',
                'period_start'            => $start,
                'period_end'              => $end,
                'cash_flow'               => $totalRevenue,
                'gross_profit'            => $totalRevenue,
                'net_profit'              => $totalRevenue,
                'total_expenses'          => 0,
                'total_items_sold'        => $totalItemsSold,
                'avg_items_per_order'     => $avgItemsPerOrder,
                'avg_ticket_per_customer' => $avgTicketValue,
                'visit_frequency'         => $visitFrequency,
                'revenue_by_channel'      => $revenueByChannel,
                'avg_service_time'        => $avgServiceTime,
                'cancellation_rate'       => $cancellationRate,
                'peak_hours'              => $peakHours,
                'breakdown_by_item'       => $itemsBreakdown,
                'top_customers'           => $topCustomers,
            ]);

            $establishment = Establishment::find($data['entity_id']);
            $orders = Order::with(['items.item', 'items.modifiers'])
                ->where('entity_name', $data['entity_name'])
                ->where('entity_id',   $data['entity_id'])
                ->whereBetween('order_datetime', [$start, $end])
                ->get();

            return response()->json([
                'message'            => 'Relatório de pedidos gerado com sucesso.',
                'report'             => $report->fresh(),
                'entity'             => $establishment,
                'orders'             => $orders,
                'items_breakdown'    => $itemsBreakdown,
                'top_customers'      => $topCustomers,
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
