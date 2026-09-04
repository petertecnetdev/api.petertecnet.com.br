<?php

use App\Domain\MarketData\Http\Controllers\MarketDataController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'app.capability:market_data'])
    ->group(function () {
        Route::get('/market/assets/{asset}/ohlcv', [MarketDataController::class, 'candles'])
            ->where('asset', '[A-Za-z0-9\-]+')
            ->middleware('throttle:120,1');
    });
