<?php

namespace App\Jobs;

use App\Models\Forecast;
use App\Services\ForecastEngineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProposeForecastResolution implements ShouldQueue
{
    use Queueable;

    public int $tries=3;
    public int $backoff=120;

    public function __construct(public readonly int $forecastId) {}

    public function handle(ForecastEngineService $engine): void
    {
        $forecast=Forecast::find($this->forecastId);
        if(!$forecast || $forecast->status!=='resolving') return;

        $recent=\Illuminate\Support\Facades\DB::table('forecast_resolutions')
            ->where('forecast_id',$forecast->id)->where('is_final',false)->where('created_at','>=',now()->subHours(6))->exists();
        if(!$recent) $engine->proposeResolution($forecast);
    }
}
