<?php

namespace App\Http\Controllers;

use App\Models\OrderForecast;
use App\Models\Order;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class OrderForecastController extends Controller
{
    protected function getValidationMessages(): array
    {
        return [
            'entity_id.required'    => 'O ID da entidade é obrigatório.',
            'entity_id.integer'     => 'O ID da entidade deve ser um inteiro.',
            'entity_name.required'  => 'O nome da entidade é obrigatório.',
            'entity_name.string'    => 'O nome da entidade deve ser uma string.',
            'start_date.required'   => 'A data inicial da previsão é obrigatória.',
            'start_date.date'       => 'A data inicial deve ser uma data válida.',
            'end_date.required'     => 'A data final da previsão é obrigatória.',
            'end_date.date'         => 'A data final deve ser uma data válida.',
        ];
    }

    public function generate(Request $request)
    {
        if (! Auth::check()) {
            return response()->json(['error' => 'Usuário não autenticado.'], 401);
        }

        $data = $request->validate([
            'entity_id'   => 'required|integer',
            'entity_name' => 'required|string',
            'start_date'  => 'required|date',
            'end_date'    => 'required|date',
        ], $this->getValidationMessages());

        $now            = Carbon::now('America/Sao_Paulo');
        $minStart       = $now->copy()->addMinutes(5);
        $minEnd         = $now->copy()->addMinutes(10);
        $requestedStart = Carbon::parse($data['start_date'], 'America/Sao_Paulo');
        $requestedEnd   = Carbon::parse($data['end_date'],   'America/Sao_Paulo');

        $start = $requestedStart->lt($minStart) ? $minStart : $requestedStart;
        $end   = $requestedEnd->lt($minEnd)   ? $minEnd   : $requestedEnd;

        if ($start->gte($end)) {
            return response()->json(['error' => 'Intervalo inválido.'], 422);
        }

        // intervalo histórico deslocado em -7 dias
        $pastStart = $start->copy()->subDays(7);
        $pastEnd   = $end->copy()->subDays(7);

        $past = Order::where('entity_name', $data['entity_name'])
            ->where('entity_id', $data['entity_id'])
            ->whereBetween('order_datetime', [$pastStart, $pastEnd])
            ->with('items')
            ->get();

        $count     = $past->count();
        $forecasts = [];
        $totalSec  = $start->diffInSeconds($end);

        if ($count > 0) {
            $intervalSec = $totalSec / $count;

            foreach ($past as $i => $order) {
                $t = $start->copy()->addSeconds($intervalSec * ($i + 1));

                $forecasts[] = [
                    'forecast_date'           => $t->toDateString(),
                    'forecast_time'           => $t->toTimeString(),
                    'entity_id'               => $data['entity_id'],
                    'entity_name'             => $data['entity_name'],
                    'customer_name_forecast'  => $order->customer_name,
                    'origin_forecast'         => $order->origin,
                    'fulfillment_forecast'    => $order->fulfillment,
                    'items_forecast'          => json_encode(
                        $order->items->map(fn($it) => [
                            'item_id'  => $it->item_id,
                            'quantity' => $it->quantity,
                        ])->toArray()
                    ),
                    'total_forecast'          => $order->total_price,
                    'payment_method_forecast' => $order->payment_method,
                    'notes_forecast'          => $order->notes,
                    'input_data'              => json_encode($data),
                    'status'                  => 'forecasted',
                    'user_id'                 => Auth::id(),
                ];
            }
        } else {
            // fallback: garante ao menos uma previsão
            $mid  = $start->copy()->addSeconds($totalSec / 2);
            $item = Item::where('entity_name', $data['entity_name'])
                        ->where('entity_id',   $data['entity_id'])
                        ->first();

            if ($item) {
                $forecasts[] = [
                    'forecast_date'           => $mid->toDateString(),
                    'forecast_time'           => $mid->toTimeString(),
                    'entity_id'               => $data['entity_id'],
                    'entity_name'             => $data['entity_name'],
                    'customer_name_forecast'  => null,
                    'origin_forecast'         => 'Balcão',
                    'fulfillment_forecast'    => 'dine-in',
                    'items_forecast'          => json_encode([[
                        'item_id'  => $item->id,
                        'quantity' => 1,
                    ]]),
                    'total_forecast'          => $item->price,
                    'payment_method_forecast' => 'Dinheiro',
                    'notes_forecast'          => null,
                    'input_data'              => json_encode($data),
                    'status'                  => 'forecasted',
                    'user_id'                 => Auth::id(),
                ];
            }
        }

        if (! empty($forecasts)) {
            OrderForecast::insert($forecasts);
        }

        $result = OrderForecast::where('entity_name', $data['entity_name'])
            ->where('entity_id',   $data['entity_id'])
            ->whereBetween('forecast_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('forecast_date')
            ->orderBy('forecast_time')
            ->get();

        return response()->json([
            'message'   => 'Previsões geradas com sucesso.',
            'forecasts' => $result,
        ], 200);
    }
}
