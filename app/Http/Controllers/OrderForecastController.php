<?php

namespace App\Http\Controllers;

use App\Models\OrderForecast;
use App\Models\Order;
use App\Models\Item;
use App\Models\Establishment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class OrderForecastController extends Controller
{
    protected function getValidationMessages()
    {
        return [
            'entity_id.required' => 'O ID do estabelecimento é obrigatório.',
            'forecast_date.required' => 'A data de previsão é obrigatória.',
            'forecast_date.date' => 'A data de previsão deve ser uma data válida.',
        ];
    }

    /**
     * Lista previsões de pedidos para uma data e entidade.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'entity_id' => 'required|integer',
            'forecast_date' => 'required|date',
        ], $this->getValidationMessages());

        $forecasts = OrderForecast::where('entity_id', $validated['entity_id'])
            ->where('forecast_date', $validated['forecast_date'])
            ->orderBy('forecast_time')
            ->get();

        return response()->json([
            'message' => 'Previsões listadas com sucesso.',
            'data' => $forecasts,
        ], 200);
    }

    /**
     * Gera e armazena previsões para uma data e entidade.
     * Idempotente: sobrescreve previsões do mesmo dia/entidade.
     */
    public function generate(Request $request)
    {
        $validated = $request->validate([
            'entity_id' => 'required|integer',
            'forecast_date' => 'required|date',
        ], $this->getValidationMessages());

        $entityId = $validated['entity_id'];
        $date = $validated['forecast_date'];

        // Limpa previsões antigas do mesmo dia/entidade (idempotente)
        OrderForecast::where('entity_id', $entityId)
            ->where('forecast_date', $date)
            ->delete();

        // Coleta histórico real do estabelecimento (últimos 30 dias)
        $orders = Order::where('entity_id', $entityId)
            ->whereDate('order_datetime', '<', $date)
            ->orderBy('order_datetime', 'desc')
            ->take(60)
            ->with(['items', 'items.item'])
            ->get();

        $products = Item::where('entity_id', $entityId)->get();

        // Geração da previsão (simples, pode trocar por lógica avançada)
        $forecasts = $this->generateForecastList($orders, $products, $date);

        // Salva na tabela de forecasts
        foreach ($forecasts as $f) {
            OrderForecast::create($f);
        }

        return response()->json([
            'message' => 'Previsão gerada com sucesso.',
            'data' => OrderForecast::where('entity_id', $entityId)
                ->where('forecast_date', $date)
                ->orderBy('forecast_time')
                ->get(),
        ]);
    }

    /**
     * Lógica central da previsão (ajuste conforme preferir).
     */
    private function generateForecastList($orders, $products, $date)
    {
        $qty = 10; // Número de previsões
        $list = [];
        if ($orders->isEmpty() || $products->isEmpty()) {
            // fallback vazio
            return [];
        }

        // Frequência de itens pedidos
        $itemCount = [];
        foreach ($orders as $o) {
            foreach ($o->items as $it) {
                $itemId = $it->item_id;
                if (!isset($itemCount[$itemId])) $itemCount[$itemId] = 0;
                $itemCount[$itemId] += $it->quantity;
            }
        }

        $topProducts = $products->map(function ($p) use ($itemCount) {
            $p->score = $itemCount[$p->id] ?? 0;
            return $p;
        })->sortByDesc('score')->take(5);

        for ($i = 0; $i < $qty; $i++) {
            // Gera previsão simulada, baseando-se no mais vendido
            $picks = $topProducts->random(rand(1, 2));
            $itemsForecast = [];
            $total = 0;
            foreach ($picks as $prod) {
                $itemsForecast[] = [
                    'item_id' => $prod->id,
                    'name' => $prod->name,
                    'price' => $prod->price,
                    'quantity' => 1,
                ];
                $total += $prod->price;
            }
            $horario = str_pad(18 + ($i % 4), 2, '0', STR_PAD_LEFT) . ':00';

            $list[] = [
                'entity_id' => $orders[0]->entity_id,
                'forecast_date' => $date,
                'forecast_time' => $horario,
                'customer_name_forecast' => 'Cliente ' . chr(65 + $i),
                'origin_forecast' => 'Balcão',
                'fulfillment_forecast' => 'dine-in',
                'items_forecast' => json_encode($itemsForecast),
                'payment_method_forecast' => 'Dinheiro',
                'notes_forecast' => '',
                'total_forecast' => $total,
            ];
        }
        return $list;
    }
}
