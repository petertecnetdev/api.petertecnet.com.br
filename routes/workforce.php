<?php

use App\Domain\Workforce\Http\Controllers\TeamMemberController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Workforce API
|--------------------------------------------------------------------------
| Shared team-management reads and mutations that are consumed by any
| application with the workforce capability. The canonical create route lives
| in api_v1.php; this contract completes read/delete while clients migrate away
| from the legacy /employer endpoints.
*/
Route::prefix('v1/apps/{application}')
    ->middleware([
        'app.context',
        'auth:api',
        'token.version',
        'app.capability:workforce',
    ])
    ->group(function () {
        Route::get('/team-members', [TeamMemberController::class, 'index']);
        Route::delete('/team-members/{teamMember}', [TeamMemberController::class, 'destroy'])
            ->whereNumber('teamMember');
    });
