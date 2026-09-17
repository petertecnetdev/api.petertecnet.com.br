<?php

use App\Domain\People\Http\Controllers\ArtistClaimController;
use Illuminate\Support\Facades\Route;

// This canonical endpoint must be registered before the public /artists/{slug}
// routes, otherwise "manageable" is interpreted as an artist slug and returns 404.
// Legacy product-prefixed aliases live exclusively in routes/compatibility.php.
Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'app.capability:social', 'auth:api', 'token.version'])
    ->group(function (): void {
        Route::get('/artists/manageable', [ArtistClaimController::class, 'manageable']);
    });
