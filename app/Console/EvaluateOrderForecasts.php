<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\OrderForecast;
use App\Models\Order;
use Carbon\Carbon;

class EvaluateOrderForecasts extends Command
{
    protected $signature   = 'forecasts:evaluate';
    protected $description = 'Avalia previsões já vencidas, relaciona com pedidos reais e calcula métricas de acurácia';

    public function handle()
    {
        $now = Carbon::now('America/Sao_Paulo')->toDateTimeString();

        // Seleciona previsões já vencidas e ainda não avaliadas
        $forecasts = OrderForecast::where('status', 'forecasted')
            ->whereRaw("STR_TO_DATE(CONCAT(forecast_date,' ', forecast_time),'%Y-%m-%d %H:%i:%s') <= ?", [$now])
            ->get();

        foreach ($forecasts as $f) {
            // Define janela de busca real: ±5 minutos em torno da previsão
            $dt     = Carbon::parse("{$f->forecast_date} {$f->forecast_time}", 'America/Sao_Paulo');
            $window = [$dt->copy()->subMinutes(5), $dt->copy()->addMinutes(5)];

            // Tenta achar o pedido real
            $order = Order::where('entity_name', $f->entity_name)
                ->where('entity_id',   $f->entity_id)
                ->whereBetween('order_datetime', $window)
                ->with('items')
                ->orderBy('order_datetime','asc')
                ->first();

            if ($order) {
                // Preenche campos reais
                $f->order_id           = $order->id;
                $f->order_datetime_real= $order->order_datetime;
                $f->customer_name_real = $order->customer_name;
                $f->origin_real        = $order->origin;
                $f->fulfillment_real   = $order->fulfillment;
                $f->items_real         = $order->items->map(fn($oi)=>[
                    'item_id'  => $oi->item_id,
                    'quantity' => $oi->quantity,
                ])->toArray();
                $f->total_real         = $order->total_price;
                $f->payment_method_real= $order->payment_method;
                $f->notes_real         = $order->notes;

                // Calcula acertos ponto a ponto
                $f->hit_customer_name  = $f->customer_name_forecast === $f->customer_name_real;
                $f->hit_origin         = $f->origin_forecast === $f->origin_real;
                $f->hit_fulfillment    = $f->fulfillment_forecast === $f->fulfillment_real;
                $f->hit_items          = $f->items_forecast == $f->items_real;
                $f->hit_payment_method = $f->payment_method_forecast === $f->payment_method_real;
                $f->hit_notes          = ($f->notes_forecast ?? '') === ($f->notes_real ?? '');

                // Diferença e acurácia de total
                $diff                    = abs($f->total_real - $f->total_forecast);
                $f->diff_total           = round($diff, 2);
                $f->accuracy_value       = $f->total_real > 0
                    ? round(1 - ($diff / $f->total_real), 2)
                    : 0.00;

                // Score simples: soma de booleans + accuracy
                $hitsCount = collect([
                    $f->hit_customer_name,
                    $f->hit_origin,
                    $f->hit_fulfillment,
                    $f->hit_items,
                    $f->hit_payment_method,
                    $f->hit_notes,
                ])->filter()->count();

                $f->score = $hitsCount + $f->accuracy_value;

                $f->status = 'evaluated';
                $f->save();
            } else {
                // Se não achar nenhum pedido real na janela, marca como 'missed'
                $f->status = 'missed';
                $f->save();
            }
        }

        $this->info('Avaliação de previsões concluída: ' . $forecasts->count() . ' registros processados.');
    }
}
