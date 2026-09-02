<?php

use App\Http\Controllers\CutinappOrderHistoryController;
use Illuminate\Support\Facades\Route;

Route::prefix('cutinapp')->middleware(['api', 'auth:api'])->group(function () {
    Route::get('/purchases', [CutinappOrderHistoryController::class, 'purchases']);
    Route::get('/purchases/{publicId}', [CutinappOrderHistoryController::class, 'purchase']);
    Route::get('/purchases/{publicId}/receipt', [CutinappOrderHistoryController::class, 'receipt']);
    Route::get('/purchases/{publicId}/receipt.pdf', [CutinappOrderHistoryController::class, 'receiptPdf']);

    Route::get('/productions/{productionId}/sales', [CutinappOrderHistoryController::class, 'producerSales'])->whereNumber('productionId');
    Route::get('/productions/{productionId}/sales/{publicId}', [CutinappOrderHistoryController::class, 'producerSale'])->whereNumber('productionId');
});
