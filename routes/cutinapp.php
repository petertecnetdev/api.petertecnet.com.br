<?php

use App\Http\Controllers\CutinappCommissionController;
use App\Http\Controllers\CutinappController;
use App\Http\Controllers\CutinappCheckoutController;
use App\Http\Controllers\CutinappPaymentController;
use App\Http\Controllers\CutinappProductController;
use App\Http\Controllers\CutinappPromoterPortalController;
use App\Http\Controllers\CutinappTeamController;
use Illuminate\Support\Facades\Route;

Route::prefix('cutinapp')->group(function () {
    Route::get('/events', [CutinappController::class, 'discover']);
    Route::get('/events/{eventId}', [CutinappController::class, 'eventPublic'])->whereNumber('eventId');

    Route::post('/payments/pix/webhook', [CutinappPaymentController::class, 'webhook'])->middleware('throttle:240,1');

    Route::middleware('auth:api')->group(function () {
        Route::get('/my/tickets', [CutinappController::class, 'myTickets']);
        Route::get('/my/sales', [CutinappController::class, 'mySales']);
        Route::get('/my/promoter', [CutinappPromoterPortalController::class, 'index']);
        Route::post('/checkin', [CutinappController::class, 'checkin'])->middleware('throttle:120,1');

        Route::post('/sales/{salePublicId}/pix', [CutinappPaymentController::class, 'createPix'])->middleware('throttle:10,1');
        Route::get('/sales/{salePublicId}/payment-status', [CutinappPaymentController::class, 'status'])->middleware('throttle:30,1');

        Route::post('/events/{eventId}/checkout', [CutinappCheckoutController::class, 'checkout'])->whereNumber('eventId')->middleware('throttle:20,1');
        Route::get('/events/{eventId}/dashboard', [CutinappController::class, 'dashboard'])->whereNumber('eventId');

        Route::get('/events/{eventId}/products', [CutinappProductController::class, 'index'])->whereNumber('eventId');
        Route::post('/events/{eventId}/products', [CutinappProductController::class, 'store'])->whereNumber('eventId');
        Route::put('/events/{eventId}/products/{itemId}', [CutinappProductController::class, 'update'])->whereNumber(['eventId','itemId']);
        Route::delete('/events/{eventId}/products/{itemId}', [CutinappProductController::class, 'destroy'])->whereNumber(['eventId','itemId']);

        Route::get('/events/{eventId}/members', [CutinappController::class, 'members'])->whereNumber('eventId');
        Route::post('/events/{eventId}/members', [CutinappTeamController::class, 'storeMember'])->whereNumber('eventId');
        Route::put('/events/{eventId}/members/{memberId}', [CutinappTeamController::class, 'updateMember'])->whereNumber(['eventId','memberId']);
        Route::delete('/events/{eventId}/members/{memberId}', [CutinappController::class, 'deleteMember'])->whereNumber(['eventId','memberId']);

        Route::get('/events/{eventId}/promoters', [CutinappController::class, 'promoters'])->whereNumber('eventId');
        Route::post('/events/{eventId}/promoters', [CutinappTeamController::class, 'storePromoter'])->whereNumber('eventId');
        Route::put('/events/{eventId}/promoters/{promoterId}', [CutinappController::class, 'updatePromoter'])->whereNumber(['eventId','promoterId']);
        Route::get('/events/{eventId}/promoters/{promoterId}/stats', [CutinappController::class, 'promoterStats'])->whereNumber(['eventId','promoterId']);
        Route::get('/events/{eventId}/promoters/{promoterId}/commissions', [CutinappCommissionController::class, 'summary'])->whereNumber(['eventId','promoterId']);
        Route::post('/events/{eventId}/promoters/{promoterId}/payout', [CutinappCommissionController::class, 'payout'])->whereNumber(['eventId','promoterId']);

        Route::get('/events/{eventId}/promotions', [CutinappController::class, 'promotions'])->whereNumber('eventId');
        Route::post('/events/{eventId}/promotions', [CutinappController::class, 'storePromotion'])->whereNumber('eventId');
        Route::put('/events/{eventId}/promotions/{promotionId}', [CutinappController::class, 'updatePromotion'])->whereNumber(['eventId','promotionId']);

        Route::post('/events/{eventId}/sales/{saleId}/confirm-payment', [CutinappController::class, 'confirmPayment'])->whereNumber(['eventId','saleId']);
    });
});
