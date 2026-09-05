<?php

use App\Domain\Events\Http\Controllers\EventPassTransferController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version', 'app.capability:events'])
    ->group(function (): void {
        Route::post('/passes/{passId}/transfer', [EventPassTransferController::class, 'transfer'])
            ->whereNumber('passId')
            ->middleware('throttle:10,1');
    });
