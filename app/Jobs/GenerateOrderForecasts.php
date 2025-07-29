<?php 
// app/Jobs/GenerateOrderForecasts.php

namespace App\Jobs;

use App\Models\OrderForecast;
use App\Models\Order;
use App\Models\Item;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class GenerateOrderForecasts implements ShouldQueue
{
    use Dispatchable, Queueable;

    protected $entityId;
    protected $startDate;
    protected $endDate;

    public function __construct(int $entityId, string $startDate, string $endDate)
    {
        $this->entityId  = $entityId;
        $this->startDate = $startDate;
        $this->endDate   = $endDate;
    }

    public function handle()
    {
        $entityId  = $this->entityId;
        $startDate = Carbon::parse($this->startDate)->startOfDay();
        $endDate   = Carbon::parse($this->endDate)->endOfDay();

        $products = Item::where('entity_id', $entityId)->get();
        if ($products->isEmpty()) {
            return;
        }

        $historyOrders = Order::where('entity_id', $entityId)
            ->whereDate('order_datetime', '<', $startDate->toDateString())
            ->orderBy('order_datetime', 'desc')
            ->take(90)
            ->with(['items', 'items.item'])
            ->get();

        $period = CarbonPeriod::create($startDate, $endDate);
        $all = [];

        foreach ($period as $date) {
            $d = $date->toDateString();
            $forecasts = $this->generateForecastList($historyOrders, $products, $d, $entityId);
            $all = array_merge($all, $forecasts);
        }

        // Bulk upsert
        if (!empty($all)) {
            OrderForecast::upsert(
                $all,
                ['entity_id','forecast_date','forecast_time'],
                array_keys($all[0])
            );
        }
    }

    private function generateForecastList($orders, $products, $date, $entityId)
    {
        // ... copie aqui a lógica de geração, mas mantenha só o retorno de array simples ...
        // Certifique-se de usar Carbon::parse e rand mas sem fazer chamadas DB nem update aqui.
    }
}
