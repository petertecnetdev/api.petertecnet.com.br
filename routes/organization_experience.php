<?php

use App\Domain\Organizations\Http\Controllers\OrganizationCommunityController;
use App\Domain\Organizations\Http\Controllers\OrganizationExperienceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')->middleware('app.context')->group(function () {
    Route::middleware('app.capability:organizations')->group(function () {
        Route::get('/organizations/taxonomy', [OrganizationExperienceController::class, 'taxonomy']);
        Route::get('/organizations/public/{slug}/experience', [OrganizationExperienceController::class, 'publicExperience']);
        Route::get('/organizations/public/{slug}/community', [OrganizationCommunityController::class, 'publicCommunity']);
    });

    Route::middleware(['auth:api', 'token.version', 'app.capability:organizations'])->group(function () {
        Route::get('/organizations/{id}/workspace', [OrganizationExperienceController::class, 'workspace'])->whereNumber('id');
        Route::patch('/organizations/{id}/experience-profile', [OrganizationExperienceController::class, 'updateProfile'])->whereNumber('id');
        Route::post('/organizations/{organizationId}/community', [OrganizationCommunityController::class, 'createPost'])->whereNumber('organizationId')->middleware('throttle:30,1');
        Route::delete('/organization-community/{postId}', [OrganizationCommunityController::class, 'deletePost'])->whereNumber('postId');
        Route::post('/organization-community/{postId}/like', [OrganizationCommunityController::class, 'like'])->whereNumber('postId')->middleware('throttle:120,1');
        Route::delete('/organization-community/{postId}/like', [OrganizationCommunityController::class, 'unlike'])->whereNumber('postId');
        Route::post('/organizations/{organizationId}/media', [OrganizationCommunityController::class, 'storeMedia'])->whereNumber('organizationId')->middleware('throttle:20,1');
        Route::delete('/organizations/{organizationId}/media/{mediaId}', [OrganizationCommunityController::class, 'deleteMedia'])->whereNumber('organizationId')->whereNumber('mediaId');
    });
});
