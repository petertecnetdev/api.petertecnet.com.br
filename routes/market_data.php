<?php

use App\Domain\MarketData\Http\Controllers\MarketDataController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'app.capability:market_data'])
    ->group(function () {
        // Realtime reads share one generous bucket per application/user (or IP),
        // avoiding collisions with other Peter Tecnet applications on the same network.
        Route::get('/market/overview', [MarketDataController::class, 'overview'])
            ->middleware('throttle:market-read');

        Route::get('/market/scanner', [MarketDataController::class, 'scanner'])
            ->middleware('throttle:market-read');

        Route::get('/market/signals', [MarketDataController::class, 'signals'])
            ->middleware('throttle:market-read');

        Route::get('/market/realtime-config', [MarketDataController::class, 'realtimeConfig'])
            ->middleware(['auth:api', 'token.version', 'throttle:market-read']);

        Route::get('/market/assets/{asset}/ohlcv', [MarketDataController::class, 'candles'])
            ->where('asset', '[A-Za-z0-9\-]+')
            ->middleware('throttle:market-read');

        Route::prefix('market')
            ->middleware(['auth:api', 'token.version'])
            ->group(function () {
                Route::post('/analyze', [MarketDataController::class, 'analyze'])->middleware('throttle:60,1');
                Route::get('/portfolio', [MarketDataController::class, 'portfolio']);
                Route::post('/positions', [MarketDataController::class, 'addPosition'])->middleware('throttle:30,1');
                Route::delete('/positions/{position}', [MarketDataController::class, 'removePosition'])->whereNumber('position');
                Route::post('/simulate', [MarketDataController::class, 'simulate'])->middleware('throttle:60,1');
                Route::get('/risk-profile', [MarketDataController::class, 'riskProfile']);
                Route::put('/risk-profile', [MarketDataController::class, 'saveRiskProfile'])->middleware('throttle:20,1');
                Route::get('/reports/{asset}', [MarketDataController::class, 'opportunityReport'])->where('asset', '[A-Za-z0-9\-]+');
                Route::get('/alerts', [MarketDataController::class, 'alerts']);
                Route::post('/alerts', [MarketDataController::class, 'addAlert'])->middleware('throttle:30,1');
                Route::delete('/alerts/{alert}', [MarketDataController::class, 'removeAlert'])->whereNumber('alert');

                Route::get('/airdrops', [MarketDataController::class, 'airdrops'])->middleware('throttle:market-read');
                Route::get('/airdrops/{slug}', [MarketDataController::class, 'airdrop'])
                    ->where('slug', '[A-Za-z0-9\-]+')
                    ->middleware('throttle:market-read');
                Route::post('/airdrops/plan', [MarketDataController::class, 'airdropPlan'])->middleware('throttle:60,1');
                Route::post('/airdrops/{slug}/tasks/{task}/complete', [MarketDataController::class, 'completeAirdropTask'])
                    ->where('slug', '[A-Za-z0-9\-]+')
                    ->where('task', '[A-Za-z0-9\-]+')
                    ->middleware('throttle:30,1');
                Route::get('/airdrops/policy/{action}', [MarketDataController::class, 'airdropActionPolicy'])
                    ->where('action', '[A-Za-z0-9_\-]+')
                    ->middleware('throttle:market-read');
            });
    });
