<?php

namespace App\Http\Controllers;

use App\Models\OrderForecast;
use App\Models\Order;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class OrderForecastController extends Controller
{
    protected function getValidationMessages()
    {
        return [
            'entity_id.required' => 'O ID do estabelecimento é obrigatório.',
            'entity_id.integer' => 'O ID do estabelecimento deve ser um número inteiro.',
            'start_date.required' => 'A data inicial é obrigatória.',
            'start_date.date' => 'A data inicial deve ser uma data válida.',
            'end_date.required' => 'A data final é obrigatória.',
            'end_date.date' => 'A data final deve ser uma data válida.',
            'end_date.after_or_equal' => 'A data final deve ser igual ou posterior à data inicial.',
        ];
    }

    public function index(Request $request)
    {
        Log::info('Listando previsões order forecast - início', ['input' => $request->all()]);

        $validated = $request->validate([
            'entity_id' => 'required|integer',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ], $this->getValidationMessages());

        $forecasts = OrderForecast::where('entity_id', $validated['entity_id'])
            ->whereBetween('forecast_date', [$validated['start_date'], $validated['end_date']])
            ->orderBy('forecast_date')
            ->orderBy('forecast_time')
            ->orderByDesc('probability_of_approval')
            ->get();

        Log::info('Previsões order forecast listadas com sucesso', [
            'entity_id' => $validated['entity_id'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'count' => $forecasts->count(),
        ]);

        return response()->json([
            'message' => 'Previsões listadas com sucesso.',
            'data' => $forecasts,
        ], 200);
    }

    public function generate(Request $request)
    {
        Log::info('Iniciando geração de previsões order forecast', ['input' => $request->all()]);

        $validated = $request->validate([
            'entity_id' => 'required|integer',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ], $this->getValidationMessages());

        $entityId = $validated['entity_id'];
        $startDate = Carbon::parse($validated['start_date'])->startOfDay();
        $endDate = Carbon::parse($validated['end_date'])->endOfDay();

        try {
            $products = Item::where('entity_id', $entityId)->get();
            if ($products->isEmpty()) {
                Log::warning('Nenhum produto encontrado para gerar previsão', ['entity_id' => $entityId]);
                return response()->json(['error' => 'Nenhum produto encontrado para gerar previsão.'], 422);
            }

            // Histórico dos últimos 90 dias antes do período inicial
            $historyOrders = Order::where('entity_id', $entityId)
                ->whereDate('order_datetime', '<', $startDate->toDateString())
                ->orderBy('order_datetime', 'desc')
                ->take(90)
                ->with(['items', 'items.item'])
                ->get();

            Log::info('Pedidos históricos carregados para geração', [
                'entity_id' => $entityId,
                'count' => $historyOrders->count(),
                'period_start' => $startDate->toDateString(),
            ]);

            DB::beginTransaction();

            $allForecasts = [];
            $period = \Carbon\CarbonPeriod::create($startDate, $endDate);

            foreach ($period as $date) {
                $forecastDate = $date->toDateString();
                Log::info("Gerando previsões para o dia $forecastDate", ['entity_id' => $entityId]);

                $forecasts = $this->generateForecastList($historyOrders, $products, $forecastDate, $entityId);

                foreach ($forecasts as $f) {
                    OrderForecast::updateOrCreate([
                        'entity_id' => $entityId,
                        'forecast_date' => $f['forecast_date'],
                        'forecast_time' => $f['forecast_time'],
                    ], $f);
                }
                $allForecasts = array_merge($allForecasts, $forecasts);
            }

            DB::commit();

            Log::info('Previsões geradas e salvas com sucesso', [
                'entity_id' => $entityId,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'total_forecasts' => count($allForecasts),
            ]);

            $outputForecasts = OrderForecast::where('entity_id', $entityId)
                ->whereBetween('forecast_date', [$startDate->toDateString(), $endDate->toDateString()])
                ->orderBy('forecast_date')
                ->orderBy('forecast_time')
                ->orderByDesc('probability_of_approval')
                ->get();

            return response()->json([
                'message' => 'Previsões geradas com sucesso.',
                'data' => $outputForecasts,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao gerar previsões order forecast: ' . $e->getMessage(), [
                'input' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'Erro ao gerar previsões: ' . $e->getMessage()], 500);
        }
    }

    private function generateForecastList($orders, $products, $date, $entityId)
    {
        Log::info("Gerando lista de previsões para data $date e entidade $entityId");

        if ($products->isEmpty()) {
            Log::warning('Nenhum produto disponível para geração de previsões', ['entity_id' => $entityId]);
            return [];
        }

        $days = $orders->groupBy(function ($o) {
            return Carbon::parse($o->order_datetime)->format('Y-m-d');
        });
        $mediaPedidos = max(round($days->map->count()->avg()), 8);

        $horarios = [];
        $origins = [];
        $fulfillments = [];
        $payments = [];
        $clientes = [];
        $statusPayments = [];
        $itemCount = [];

        foreach ($orders as $o) {
            $dt = Carbon::parse($o->order_datetime);
            $horarios[] = $dt->format('H:i');
            $origins[] = $o->origin;
            $fulfillments[] = $o->fulfillment;
            $payments[] = $o->payment_method;
            $statusPayments[] = $o->payment_status;
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

        // Geração de slots de horário únicos
        $startHour = 18;
        $endHour = 23;
        $slots = [];
        for ($h = $startHour; $h <= $endHour; $h++) {
            $slots[] = sprintf("%02d:00", $h);
            $slots[] = sprintf("%02d:30", $h);
        }
        shuffle($slots);

        $usedTimes = [];
        $list = [];
        for ($i = 0; $i < $mediaPedidos; $i++) {
            $horario = null;
            $tentativas = 0;
            do {
                $horario = $randomWeighted($horariosDia);
                $tentativas++;
            } while ($horario && in_array($horario, $usedTimes) && $tentativas < 8);

            if (!$horario || in_array($horario, $usedTimes)) {
                foreach ($slots as $slot) {
                    if (!in_array($slot, $usedTimes)) {
                        $horario = $slot;
                        break;
                    }
                }
                if (!$horario) $horario = sprintf("%02d:00", rand($startHour, $endHour));
            }
            $horario = strlen($horario) === 5 ? $horario : substr($horario, 0, 5);
            $usedTimes[] = $horario;

            $cliente = $clientes[array_rand($clientes)];
            $origin = $randomWeighted($origins) ?: "Balcão";
            $fulfillment = $randomWeighted($fulfillments) ?: "dine-in";
            $payment = $randomWeighted($payments) ?: "Dinheiro";

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

            // Métricas "inteligentes" e campos extra para análise preditiva
            $probability = min(99, 55 + rand(0, 40)); // Exemplo, pode melhorar baseado em histórico real
            $modelConfidence = min(99, 60 + rand(0, 35));
            $historicalSimilarity = rand(50, 100);
            $isRecommended = $probability > 80 ? true : false;
            $isImprobable = $probability < 60 ? true : false;
            $repeatCount = rand(0, 7);

            $list[] = [
                'entity_id' => $entityId,
                'entity_name' => 'establishment',
                'forecast_date' => $date,
                'forecast_time' => $horario,
                'customer_name_forecast' => $cliente,
                'origin_forecast' => $origin,
                'fulfillment_forecast' => $fulfillment,
                'items_forecast' => json_encode($itemsForecast),
                'total_forecast' => $total,
                'payment_method_forecast' => $payment,
                'notes_forecast' => '',
                // Real data (to be matched/filled after)
                'order_id' => null,
                'order_datetime_real' => null,
                'customer_name_real' => null,
                'origin_real' => null,
                'fulfillment_real' => null,
                'items_real' => null,
                'total_real' => null,
                'payment_method_real' => null,
                'notes_real' => null,
                // Metrics/accuracy
                'hit_customer_name' => false,
                'hit_origin' => false,
                'hit_fulfillment' => false,
                'hit_items' => false,
                'hit_payment_method' => false,
                'hit_notes' => false,
                'accuracy_value' => 0,
                'diff_total' => 0,
                'score' => 0,
                // Context/input
                'input_data' => json_encode([
                    'avg_per_day' => $mediaPedidos,
                    'history_days' => $days->count(),
                ]),
                'status' => 'pending',
                // Advanced/AI/Analytics fields
                'human_evaluation' => null,
                'human_feedback' => null,
                'probability_of_approval' => $probability,
                'model_confidence' => $modelConfidence,
                'reason_for_prediction' => 'Generated by AI based on recent sales history.',
                'historical_similarity' => $historicalSimilarity,
                'is_recommended' => $isRecommended,
                'is_improbable' => $isImprobable,
                'repeat_forecast_count' => $repeatCount,
                'operational_feedback' => null,
            ];
        }

        Log::info("Lista de previsões gerada com " . count($list) . " registros", ['entity_id' => $entityId, 'date' => $date]);

        return $list;
    }
}
