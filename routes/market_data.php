<?php

use App\Domain\MarketData\Http\Controllers\MarketDataController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'app.capability:market_data'])
    ->group(function () {
        Route::get('/market/overview', [MarketDataController::class, 'overview'])
            ->middleware('throttle:60,1');

        Route::get('/market/scanner', [MarketDataController::class, 'scanner'])
            ->middleware('throttle:30,1');

        Route::get('/market/signals', [MarketDataController::class, 'signals'])
            ->middleware('throttle:60,1');

        Route::get('/market/realtime-config', [MarketDataController::class, 'realtimeConfig'])
            ->middleware(['auth:api', 'token.version', 'throttle:30,1']);

        Route::get('/market/assets/{asset}/ohlcv', [MarketDataController::class, 'candles'])
            ->where('asset', '[A-Za-z0-9\-]+')
            ->middleware('throttle:120,1');

        Route::prefix('market')
            ->middleware(['auth:api', 'token.version'])
            ->group(function () {
                Route::post('/analyze', [MarketDataController::class, 'analyze'])->middleware('throttle:30,1');
                Route::get('/portfolio', [MarketDataController::class, 'portfolio']);
                Route::post('/positions', [MarketDataController::class, 'addPosition'])->middleware('throttle:30,1');
                Route::delete('/positions/{position}', [MarketDataController::class, 'removePosition'])->whereNumber('position');
                Route::post('/simulate', [MarketDataController::class, 'simulate'])->middleware('throttle:60,1');
                Route::get('/risk-profile', [MarketDataController::class, 'riskProfile']);
                Route::put('/risk-profile', [MarketDataController::class, 'saveRiskProfile'])->middleware('throttle:20,1');
                Route::get('/alerts', [MarketDataController::class, 'alerts']);
                Route::post('/alerts', [MarketDataController::class, 'addAlert'])->middleware('throttle:30,1');
                Route::delete('/alerts/{alert}', [MarketDataController::class, 'removeAlert'])->whereNumber('alert');
            });
    });
