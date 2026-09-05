<?php

use App\Http\Controllers\Admin\SupportController as AdminSupportController;
use App\Http\Controllers\SupportController;
use App\Http\Middleware\PeterTecnetAdminApi;
use Illuminate\Support\Facades\Route;

Route::prefix('support')->group(function () {
    Route::post('/tickets', [SupportController::class, 'store'])
        ->middleware('throttle:support-write')
        ->name('support.tickets.store');
    Route::get('/tickets/{publicId}', [SupportController::class, 'show'])
        ->whereUuid('publicId')
        ->middleware('throttle:support-read')
        ->name('support.tickets.show');
    Route::post('/tickets/{publicId}/messages', [SupportController::class, 'reply'])
        ->whereUuid('publicId')
        ->middleware('throttle:support-write')
        ->name('support.tickets.reply');
    Route::get('/my', [SupportController::class, 'my'])
        ->middleware('auth:api')
        ->name('support.tickets.my');
});

Route::prefix('admin/support')
    ->middleware(['auth:api', PeterTecnetAdminApi::class])
    ->group(function () {
        Route::get('/summary', [AdminSupportController::class, 'summary']);
        Route::get('/tickets', [AdminSupportController::class, 'index']);
        Route::get('/tickets/{ticket}', [AdminSupportController::class, 'show'])->whereNumber('ticket');
        Route::patch('/tickets/{ticket}', [AdminSupportController::class, 'update'])->whereNumber('ticket');
        Route::post('/tickets/{ticket}/messages', [AdminSupportController::class, 'reply'])->whereNumber('ticket');
    });
