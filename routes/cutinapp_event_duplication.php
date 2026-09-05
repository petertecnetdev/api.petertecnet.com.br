<?php

use App\Domain\Events\Http\Controllers\DuplicateEventController;
use Illuminate\Support\Facades\Route;

Route::prefix('cutinapp')
    ->middleware(['api', 'app.bind:cutinapp', 'compatibility.route', 'auth:api', 'token.version'])
    ->group(function (): void {
        Route::post('/events/{id}/duplicate', DuplicateEventController::class)
            ->whereNumber('id');
    });
