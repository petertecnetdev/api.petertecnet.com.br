<?php

use App\Domain\People\Http\Controllers\ArtistClaimController;
use Illuminate\Support\Facades\Route;

// These specific endpoints must be registered before the public /artists/{slug}
// routes, otherwise "manageable" is interpreted as an artist slug and returns 404.
Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'app.capability:social', 'auth:api', 'token.version'])
    ->group(function (): void {
        Route::get('/artists/manageable', [ArtistClaimController::class, 'manageable']);
    });

// The legacy product-prefixed alias lives exclusively in routes/compatibility.php.
// Keep the remaining artist workflow endpoints in this priority block so
// app-scoped routes are registered before the generic public /artists/{slug} route.
require __DIR__.'/artist_workflow.php';
