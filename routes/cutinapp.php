<?php

use App\Http\Controllers\CutinappController;
use Illuminate\Support\Facades\Route;

Route::prefix('cutinapp')->group(function () {
    Route::get('/events', [CutinappController::class, 'discover']);
    Route::get('/events/{eventId}', [CutinappController::class, 'eventPublic'])->whereNumber('eventId');

    Route::middleware('auth:api')->group(function () {
        Route::get('/my/tickets', [CutinappController::class, 'myTickets']);
        Route::get('/my/sales', [CutinappController::class, 'mySales']);
        Route::post('/checkin', [CutinappController::class, 'checkin'])->middleware('throttle:120,1');

        Route::post('/events/{eventId}/checkout', [CutinappController::class, 'checkout'])->whereNumber('eventId')->middleware('throttle:20,1');
        Route::get('/events/{eventId}/dashboard', [CutinappController::class, 'dashboard'])->whereNumber('eventId');

        Route::get('/events/{eventId}/members', [CutinappController::class, 'members'])->whereNumber('eventId');
        Route::post('/events/{eventId}/members', [CutinappController::class, 'storeMember'])->whereNumber('eventId');
        Route::put('/events/{eventId}/members/{memberId}', [CutinappController::class, 'updateMember'])->whereNumber(['eventId','memberId']);
        Route::delete('/events/{eventId}/members/{memberId}', [CutinappController::class, 'deleteMember'])->whereNumber(['eventId','memberId']);

        Route::get('/events/{eventId}/promoters', [CutinappController::class, 'promoters'])->whereNumber('eventId');
        Route::post('/events/{eventId}/promoters', [CutinappController::class, 'storePromoter'])->whereNumber('eventId');
        Route::put('/events/{eventId}/promoters/{promoterId}', [CutinappController::class, 'updatePromoter'])->whereNumber(['eventId','promoterId']);
        Route::get('/events/{eventId}/promoters/{promoterId}/stats', [CutinappController::class, 'promoterStats'])->whereNumber(['eventId','promoterId']);

        Route::get('/events/{eventId}/promotions', [CutinappController::class, 'promotions'])->whereNumber('eventId');
        Route::post('/events/{eventId}/promotions', [CutinappController::class, 'storePromotion'])->whereNumber('eventId');
        Route::put('/events/{eventId}/promotions/{promotionId}', [CutinappController::class, 'updatePromotion'])->whereNumber(['eventId','promotionId']);

        Route::post('/events/{eventId}/sales/{saleId}/confirm-payment', [CutinappController::class, 'confirmPayment'])->whereNumber(['eventId','saleId']);
    });
});
