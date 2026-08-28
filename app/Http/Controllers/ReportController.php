<?php

namespace App\Http\Controllers;

use App\Models\Establishment;
use App\Models\Order;
use App\Models\Report;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReportController extends Controller
{
    public function order(Request $request)
    {
        $data = $request->validate([
            'entity_id' => 'required|integer|min:1',
            'entity_name' => 'nullable|string|in:establishment',
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date|after_or_equal:period_start',
        ]);

        $entityName = $data['entity_name'] ?? 'establishment';
        $entityId = (int) $data['entity_id'];
        $this->assertEntityAccess($entityName, $entityId);

        $periodStart = isset($data['period_start'])
            ? Carbon::parse($data['period_start'])->startOfDay()
            : now()->startOfDay();
        $periodEnd = isset($data['period_end'])
            ? Carbon::parse($data['period_end'])->endOfDay()
            : now()->endOfDay();

        $orders = Order::query()
            ->where('entity_name', $entityName)
            ->where('entity_id', $entityId)
            ->whereBetween('order_datetime', [$periodStart, $periodEnd])
            ->with(['attendant.user:id,first_name,last_name,user_name', 'client:id,first_name,last_name,user_name', 'items.item'])
            ->get();

        $totalOrders = $orders->count();
        $cashFlow = (float) $orders->sum('total_price');
        $cancelled = $orders->filter(fn ($order) => in_array($order->appointment_status ?? $order->status, ['cancelled', 'rejected'], true))->count();
        $customers = $orders->whereNotNull('client_id')->groupBy('client_id');

        $breakdown = [];
        foreach ($orders as $order) {
            foreach ($order->items as $orderItem) {
                $id = (int) ($orderItem->item_id ?? 0);
                if (! $id) continue;
                $breakdown[$id]['quantity'] = ($breakdown[$id]['quantity'] ?? 0) + (int) ($orderItem->quantity ?? 0);
                $breakdown[$id]['subtotal'] = ($breakdown[$id]['subtotal'] ?? 0) + (float) ($orderItem->subtotal ?? 0);
            }
        }

        $report = Report::create([
            'entity_id' => $entityId,
            'entity_name' => $entityName,
            'report_type' => 'order',
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'cash_flow' => $cashFlow,
            'gross_profit' => $cashFlow,
            'net_profit' => $cashFlow,
            'total_expenses' => 0,
            'revenue_by_channel' => $orders->groupBy('origin')->map(fn ($g) => (float) $g->sum('total_price'))->all(),
            'avg_service_time' => $orders->avg('total_duration') ?: 0,
            'cancellation_rate' => $totalOrders ? round(($cancelled / $totalOrders) * 100, 2) : 0,
            'peak_hours' => $orders->filter(fn ($o) => $o->order_datetime)->map(fn ($o) => Carbon::parse($o->order_datetime)->format('H'))->countBy()->all(),
            'resource_utilization' => 0,
            'new_customers_count' => $customers->count(),
            'returning_customers_count' => $customers->filter(fn ($g) => $g->count() > 1)->count(),
            'avg_ticket_per_customer' => $customers->count() ? round($cashFlow / $customers->count(), 2) : 0,
            'visit_frequency' => $customers->count() ? round($totalOrders / $customers->count(), 2) : 0,
            'top_customers' => $customers->map(fn ($g, $clientId) => ['client_id' => $clientId, 'total' => (float) $g->sum('total_price')])->sortByDesc('total')->take(5)->values()->all(),
            'breakdown_by_item' => $breakdown,
            'individual_performance' => [],
            'lead_origin' => [],
            'endpoint_usage' => [],
        ]);

        return response()->json([
            'message' => 'Relatório de pedidos gerado com sucesso.',
            'report' => $report->fresh(),
            'orders' => $orders,
        ], 201);
    }

    private function assertEntityAccess(string $entityName, int $entityId): void
    {
        $user = Auth::user();
        if ($user && $user->hasProfile('Administrador')) return;

        if ($entityName === 'establishment') {
            $establishment = Establishment::findOrFail($entityId);
            abort_unless($user && (
                (int) $establishment->user_id === (int) $user->id
                || $establishment->employers()->where('user_id', $user->id)->exists()
            ), 403, 'Você não pode gerar relatórios desta entidade.');
            return;
        }

        abort(403, 'Entidade não autorizada para relatórios.');
    }
}
