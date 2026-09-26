<?php

use App\Http\Controllers\AiContentController;
use App\Http\Controllers\FlyerDateAuditController;
use Illuminate\Support\Facades\Route;

Route::prefix('ai/content')
    ->middleware(['auth:api', 'app.bind', 'throttle:20,1'])
    ->group(function () {
        Route::post('/description', [AiContentController::class, 'description'])
            ->name('ai.content.description');
        Route::post('/flyer-date-review', [AiContentController::class, 'flyerDateReview'])
            ->name('ai.content.flyer-date-review');
        Route::post('/flyer-date-audits', [FlyerDateAuditController::class, 'store'])
            ->name('ai.content.flyer-date-audits.store');
        Route::get('/flyer-date-audits/{audit}', [FlyerDateAuditController::class, 'show'])
            ->name('ai.content.flyer-date-audits.show');
        Route::patch('/flyer-date-audits/{audit}/review', [FlyerDateAuditController::class, 'review'])
            ->name('ai.content.flyer-date-audits.review');
    });
