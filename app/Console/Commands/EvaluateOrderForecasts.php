<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderForecast;
use Carbon\Carbon;
use Illuminate\Console\Command;

class EvaluateOrderForecasts extends Command
{
    protected $signature = 'forecasts:evaluate';

    protected $description = 'Avalia previsões já vencidas, relaciona com pedidos reais e calcula métricas de acurácia';

    public function handle(): int
    {
        $now = Carbon::now('America/Sao_Paulo')->toDateTimeString();

        $forecasts = OrderForecast::where('status', 'forecasted')
            ->whereRaw("STR_TO_DATE(CONCAT(forecast_date,' ', forecast_time),'%Y-%m-%d %H:%i:%s') <= ?", [$now])
            ->get();

        foreach ($forecasts as $forecast) {
            $dateTime = Carbon::parse(
                "{$forecast->forecast_date} {$forecast->forecast_time}",
                'America/Sao_Paulo'
            );

            $order = Order::where('entity_name', $forecast->entity_name)
                ->where('entity_id', $forecast->entity_id)
                ->whereBetween('order_datetime', [
                    $dateTime->copy()->subMinutes(5),
                    $dateTime->copy()->addMinutes(5),
                ])
                ->with('items')
                ->orderBy('order_datetime')
                ->first();

            if (! $order) {
                $forecast->status = 'missed';
                $forecast->save();
                continue;
            }

            $forecast->order_id = $order->id;
            $forecast->order_datetime_real = $order->order_datetime;
            $forecast->customer_name_real = $order->customer_name;
            $forecast->origin_real = $order->origin;
            $forecast->fulfillment_real = $order->fulfillment;
            $forecast->items_real = $order->items->map(fn ($item) => [
                'item_id' => $item->item_id,
                'quantity' => $item->quantity,
            ])->toArray();
            $forecast->total_real = $order->total_price;
            $forecast->payment_method_real = $order->payment_method;
            $forecast->notes_real = $order->notes;

            $forecast->hit_customer_name = $forecast->customer_name_forecast === $forecast->customer_name_real;
            $forecast->hit_origin = $forecast->origin_forecast === $forecast->origin_real;
            $forecast->hit_fulfillment = $forecast->fulfillment_forecast === $forecast->fulfillment_real;
            $forecast->hit_items = $forecast->items_forecast == $forecast->items_real;
            $forecast->hit_payment_method = $forecast->payment_method_forecast === $forecast->payment_method_real;
            $forecast->hit_notes = ($forecast->notes_forecast ?? '') === ($forecast->notes_real ?? '');

            $difference = abs($forecast->total_real - $forecast->total_forecast);
            $forecast->diff_total = round($difference, 2);
            $forecast->accuracy_value = $forecast->total_real > 0
                ? round(1 - ($difference / $forecast->total_real), 2)
                : 0.00;

            $hits = collect([
                $forecast->hit_customer_name,
                $forecast->hit_origin,
                $forecast->hit_fulfillment,
                $forecast->hit_items,
                $forecast->hit_payment_method,
                $forecast->hit_notes,
            ])->filter()->count();

            $forecast->score = $hits + $forecast->accuracy_value;
            $forecast->status = 'evaluated';
            $forecast->save();
        }

        $this->info('Avaliação de previsões concluída: '.$forecasts->count().' registros processados.');

        return self::SUCCESS;
    }
}
