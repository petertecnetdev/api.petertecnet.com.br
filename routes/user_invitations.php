<?php

use App\Http\Controllers\UserInvitationController;
use App\Http\Middleware\PeterTecnetAdminApi;
use Illuminate\Support\Facades\Route;

Route::prefix('auth/invitations')->group(function () {
    Route::get('/{token}', [UserInvitationController::class, 'show'])
        ->middleware('throttle:30,1')
        ->where('token', '[A-Za-z0-9]+');

    Route::post('/{token}/activate', [UserInvitationController::class, 'activate'])
        ->middleware('throttle:10,1')
        ->where('token', '[A-Za-z0-9]+');
});

Route::prefix('admin/ecosystem/invitations')
    ->middleware(['auth:api', PeterTecnetAdminApi::class])
    ->group(function () {
        Route::get('/', [UserInvitationController::class, 'index']);
        Route::post('/prospect', [UserInvitationController::class, 'storeProspect'])
            ->middleware('throttle:20,1');
        Route::post('/{invitation}/resend', [UserInvitationController::class, 'resend'])
            ->whereNumber('invitation')
            ->middleware('throttle:20,1');
        Route::delete('/{invitation}', [UserInvitationController::class, 'revoke'])
            ->whereNumber('invitation');
    });
