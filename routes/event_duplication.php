<?php

use App\Domain\Events\Http\Controllers\DuplicateEventController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['api', 'app.context', 'auth:api', 'token.version', 'app.capability:events'])
    ->group(function (): void {
        Route::post('/events/{id}/duplicate', DuplicateEventController::class)
            ->whereNumber('id');
    });
