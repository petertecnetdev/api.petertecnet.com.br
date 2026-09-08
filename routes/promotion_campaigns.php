<?php

use App\Http\Controllers\PromotionCampaignController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}/campaigns')->middleware('app.context')->group(function () {
    Route::get('/', [PromotionCampaignController::class, 'index'])->middleware('throttle:120,1');
    Route::get('/{uuid}', [PromotionCampaignController::class, 'show'])->whereUuid('uuid')->middleware('throttle:120,1');
    Route::post('/{uuid}/touch', [PromotionCampaignController::class, 'touch'])->whereUuid('uuid')->middleware('throttle:120,1');
    Route::middleware(['auth:api','token.version'])->group(function () {
        Route::post('/', [PromotionCampaignController::class, 'store'])->middleware('throttle:30,1');
        Route::put('/{uuid}', [PromotionCampaignController::class, 'update'])->whereUuid('uuid')->middleware('throttle:60,1');
        Route::post('/{uuid}/publish', [PromotionCampaignController::class, 'publish'])->whereUuid('uuid')->middleware('throttle:30,1');
        Route::patch('/{uuid}/status', [PromotionCampaignController::class, 'status'])->whereUuid('uuid')->middleware('throttle:30,1');
        Route::post('/{uuid}/participate', [PromotionCampaignController::class, 'participate'])->whereUuid('uuid')->middleware('throttle:30,1');
        Route::get('/{uuid}/analytics', [PromotionCampaignController::class, 'analytics'])->whereUuid('uuid')->middleware('throttle:120,1');
        Route::post('/{uuid}/draw', [PromotionCampaignController::class, 'draw'])->whereUuid('uuid')->middleware('throttle:10,1');
        Route::put('/{uuid}/compliance', [PromotionCampaignController::class, 'compliance'])->whereUuid('uuid')->middleware('throttle:20,1');
    });
});
