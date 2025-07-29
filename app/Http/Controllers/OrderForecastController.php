<?php

namespace App\Http\Controllers;

use App\Models\OrderForecast;
use App\Models\Order;
use App\Models\Item;
use Illuminate\Http\Request;
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

    public function generate(Request $request)
    {
        $validated = $request->validate([
            'entity_id' => 'required|integer',
            'forecast_date' => 'required|date',
        ], $this->getValidationMessages());

        $entityId = $validated['entity_id'];
        $date = $validated['forecast_date'];

        OrderForecast::where('entity_id', $entityId)
            ->where('forecast_date', $date)
            ->delete();

        $orders = Order::where('entity_id', $entityId)
            ->whereDate('order_datetime', '<', $date)
            ->orderBy('order_datetime', 'desc')
            ->take(90)
            ->with(['items', 'items.item'])
            ->get();

        $products = Item::where('entity_id', $entityId)->get();

        $forecasts = $this->generateForecastList($orders, $products, $date, $entityId);

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

    private function generateForecastList($orders, $products, $date, $entityId)
    {
        if ($products->isEmpty()) return [];

        $days = $orders->groupBy(function ($o) {
            return Carbon::parse($o->order_datetime)->format('Y-m-d');
        });
        $mediaPedidos = max(round($days->map->count()->avg()), 8);

        $horarios = [];
        $origens = [];
        $fulfillments = [];
        $pagamentos = [];
        $clientes = [];
        $statusPagamentos = [];

        $itemCount = [];
        foreach ($orders as $o) {
            $dt = Carbon::parse($o->order_datetime);
            $horarios[] = $dt->format('H:i');
            $origens[] = $o->origin;
            $fulfillments[] = $o->fulfillment;
            $pagamentos[] = $o->payment_method;
            $statusPagamentos[] = $o->payment_status;
            if ($o->customer_name) $clientes[] = $o->customer_name;
            foreach ($o->items as $it) {
                $itemId = $it->item_id;
                if (!isset($itemCount[$itemId])) $itemCount[$itemId] = 0;
                $itemCount[$itemId] += $it->quantity;
            }
        }
        $clientes = array_unique($clientes);
        if (empty($clientes)) $clientes = ['Cliente A', 'Cliente B', 'Cliente C', 'Cliente D'];

        $randomWeighted = function($arr) {
            if (empty($arr)) return null;
            $counts = array_count_values($arr);
            $total = array_sum($counts);
            $r = rand(1, $total);
            $sum = 0;
            foreach ($counts as $val => $qty) {
                $sum += $qty;
                if ($r <= $sum) return $val;
            }
            return array_key_first($counts);
        };

        arsort($itemCount);
        $topProducts = collect($products)->filter(function($p) use ($itemCount) {
            return isset($itemCount[$p->id]);
        })->sortByDesc(function($p) use ($itemCount) {
            return $itemCount[$p->id];
        })->take(10);

        $horariosDia = [];
        foreach ($days as $dia => $pedidosDia) {
            foreach ($pedidosDia as $o) {
                $dt = Carbon::parse($o->order_datetime);
                $horariosDia[] = $dt->format('H:i');
            }
        }
        $horariosDia = array_unique($horariosDia);

        // Garantir geração de horários únicos e válidos SEMPRE
        $startHour = 18;
        $endHour = 23;
        $slots = [];
        for ($h = $startHour; $h <= $endHour; $h++) {
            $slots[] = sprintf("%02d:00", $h);
            $slots[] = sprintf("%02d:30", $h);
        }

        // Embaralhar para sortear slots livres se faltar histórico
        shuffle($slots);

        $usedTimes = [];
        $list = [];
        for ($i = 0; $i < $mediaPedidos; $i++) {
            // Tenta pegar horário do histórico ponderado e único, senão pega slot livre
            $horario = null;
            $tentativas = 0;
            do {
                $horario = $randomWeighted($horariosDia);
                $tentativas++;
            } while ($horario && in_array($horario, $usedTimes) && $tentativas < 8);

            if (!$horario || in_array($horario, $usedTimes)) {
                // Gera slot fixo não utilizado
                foreach ($slots as $slot) {
                    if (!in_array($slot, $usedTimes)) {
                        $horario = $slot;
                        break;
                    }
                }
                if (!$horario) $horario = sprintf("%02d:00", rand($startHour, $endHour));
            }
            // Garante formato sempre HH:MM
            $horario = strlen($horario) === 5 ? $horario : substr($horario, 0, 5);
            $usedTimes[] = $horario;

            $cliente = $clientes[array_rand($clientes)];
            $origem = $randomWeighted($origens) ?: "Balcão";
            $fulfillment = $randomWeighted($fulfillments) ?: "dine-in";
            $pagamento = $randomWeighted($pagamentos) ?: "Dinheiro";
            $status = $randomWeighted($statusPagamentos) ?: "previsto";

            $produtosPick = $topProducts->count() ? $topProducts->random(rand(1, 2)) : $products->random(rand(1, 2));
            $itemsForecast = [];
            $total = 0;
            foreach ($produtosPick as $prod) {
                $qty = rand(1,2);
                $itemsForecast[] = [
                    'item_id' => $prod->id,
                    'name' => $prod->name,
                    'price' => $prod->price,
                    'quantity' => $qty,
                ];
                $total += $prod->price * $qty;
            }

            $list[] = [
                'entity_id' => $entityId,
                'forecast_date' => $date,
                'forecast_time' => $horario, // Nunca será null ou vazio
                'customer_name_forecast' => $cliente,
                'origin_forecast' => $origem,
                'fulfillment_forecast' => $fulfillment,
                'items_forecast' => json_encode($itemsForecast),
                'payment_method_forecast' => $pagamento,
                'payment_status_forecast' => $status,
                'notes_forecast' => '',
                'total_forecast' => $total,
            ];
        }
        return $list;
    }
}
