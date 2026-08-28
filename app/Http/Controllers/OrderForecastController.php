<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Order;
use App\Models\OrderForecast;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderForecastController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'entity_id' => 'nullable|integer|min:1',
            'entity_name' => 'nullable|string|max:100',
            'status' => 'nullable|string|max:50',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = OrderForecast::query()
            ->where('user_id', Auth::id())
            ->orderByDesc('forecast_date')
            ->orderByDesc('forecast_time');

        if (isset($validated['entity_id'])) {
            $query->where('entity_id', $validated['entity_id']);
        }

        if (! empty($validated['entity_name'])) {
            $query->where('entity_name', $validated['entity_name']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['from'])) {
            $query->whereDate('forecast_date', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->whereDate('forecast_date', '<=', $validated['to']);
        }

        return response()->json($query->paginate($validated['per_page'] ?? 25));
    }

    public function generate(Request $request)
    {
        $data = $request->validate([
            'entity_id' => 'required|integer|min:1',
            'entity_name' => 'required|string|max:100',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
        ]);

        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $now = Carbon::now($timezone);
        $requestedStart = Carbon::parse($data['start_date'], $timezone);
        $requestedEnd = Carbon::parse($data['end_date'], $timezone);

        $start = $requestedStart->greaterThan($now) ? $requestedStart : $now->copy()->addMinutes(5);
        $end = $requestedEnd->greaterThan($start) ? $requestedEnd : $start->copy()->addMinutes(5);

        $pastStart = $start->copy()->subDays(7);
        $pastEnd = $end->copy()->subDays(7);

        $pastOrders = Order::query()
            ->where('entity_name', $data['entity_name'])
            ->where('entity_id', $data['entity_id'])
            ->whereBetween('order_datetime', [$pastStart, $pastEnd])
            ->with('items')
            ->orderBy('order_datetime')
            ->get();

        $totalSeconds = max(1, $start->diffInSeconds($end));
        $rows = [];

        if ($pastOrders->isNotEmpty()) {
            $interval = $totalSeconds / $pastOrders->count();

            foreach ($pastOrders->values() as $index => $order) {
                $forecastAt = $start->copy()->addSeconds((int) round($interval * ($index + 1)));

                $rows[] = $this->forecastRow(
                    $data,
                    $forecastAt,
                    $order->customer_name,
                    $order->origin,
                    $order->fulfillment,
                    $order->items->map(fn ($item) => [
                        'item_id' => $item->item_id,
                        'quantity' => $item->quantity,
                    ])->values()->all(),
                    $order->total_price,
                    $order->payment_method,
                    $order->notes
                );
            }
        } else {
            $item = Item::query()
                ->where('entity_name', $data['entity_name'])
                ->where('entity_id', $data['entity_id'])
                ->first();

            if ($item) {
                $rows[] = $this->forecastRow(
                    $data,
                    $start->copy()->addSeconds((int) floor($totalSeconds / 2)),
                    null,
                    'Balcão',
                    'dine-in',
                    [['item_id' => $item->id, 'quantity' => 1]],
                    $item->price,
                    'Dinheiro',
                    null
                );
            }
        }

        DB::transaction(function () use ($rows) {
            if ($rows !== []) {
                OrderForecast::query()->insert($rows);
            }
        });

        $forecasts = OrderForecast::query()
            ->where('user_id', Auth::id())
            ->where('entity_name', $data['entity_name'])
            ->where('entity_id', $data['entity_id'])
            ->whereBetween('forecast_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('forecast_date')
            ->orderBy('forecast_time')
            ->get();

        return response()->json([
            'message' => $rows === []
                ? 'Não há histórico ou itens suficientes para gerar previsões.'
                : 'Previsões geradas com sucesso.',
            'forecasts' => $forecasts,
        ], $rows === [] ? 200 : 201);
    }

    private function forecastRow(
        array $data,
        Carbon $forecastAt,
        ?string $customerName,
        ?string $origin,
        ?string $fulfillment,
        array $items,
        $total,
        ?string $paymentMethod,
        ?string $notes
    ): array {
        $now = now();

        return [
            'forecast_date' => $forecastAt->toDateString(),
            'forecast_time' => $forecastAt->toTimeString(),
            'entity_id' => $data['entity_id'],
            'entity_name' => $data['entity_name'],
            'customer_name_forecast' => $customerName,
            'origin_forecast' => $origin,
            'fulfillment_forecast' => $fulfillment,
            'items_forecast' => json_encode($items),
            'total_forecast' => $total ?? 0,
            'payment_method_forecast' => $paymentMethod,
            'notes_forecast' => $notes,
            'input_data' => json_encode($data),
            'status' => 'forecasted',
            'user_id' => Auth::id(),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
