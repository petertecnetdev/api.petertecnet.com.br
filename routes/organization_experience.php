<?php

use App\Domain\Organizations\Http\Controllers\OrganizationCommunityController;
use App\Domain\Organizations\Http\Controllers\OrganizationExperienceController;
use App\Domain\Organizations\Http\Controllers\OrganizationMediaController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')->middleware('app.context')->group(function () {
    Route::middleware('app.capability:organizations')->group(function () {
        Route::get('/organizations/public/{slug}/experience', [OrganizationExperienceController::class, 'publicExperience']);
        Route::get('/organizations/public/{slug}/community', [OrganizationCommunityController::class, 'publicCommunity']);
    });

    Route::middleware(['auth:api', 'token.version', 'app.capability:organizations'])->group(function () {
        Route::post('/organizations/public/{slug}/media/{mediaId}/report', [OrganizationMediaController::class, 'report'])->whereNumber('mediaId')->middleware('throttle:10,1');
        Route::get('/organization-media-reports', [OrganizationMediaController::class, 'moderationReports']);
        Route::patch('/organization-media-reports/{reportId}', [OrganizationMediaController::class, 'reviewReport'])->whereNumber('reportId');
        Route::get('/organizations/{id}/workspace', [OrganizationExperienceController::class, 'workspace'])->whereNumber('id');
        Route::patch('/organizations/{id}/experience-profile', [OrganizationExperienceController::class, 'updateProfile'])->whereNumber('id');
        Route::post('/organizations/{organizationId}/community', [OrganizationCommunityController::class, 'createPost'])->whereNumber('organizationId')->middleware('throttle:30,1');
        Route::delete('/organization-community/{postId}', [OrganizationCommunityController::class, 'deletePost'])->whereNumber('postId');
        Route::post('/organization-community/{postId}/like', [OrganizationCommunityController::class, 'like'])->whereNumber('postId')->middleware('throttle:120,1');
        Route::delete('/organization-community/{postId}/like', [OrganizationCommunityController::class, 'unlike'])->whereNumber('postId');
        Route::post('/organizations/{organizationId}/media', [OrganizationMediaController::class, 'store'])->whereNumber('organizationId')->middleware('throttle:40,1');
        Route::patch('/organizations/{organizationId}/media/{mediaId}', [OrganizationMediaController::class, 'update'])->whereNumber('organizationId')->whereNumber('mediaId');
        Route::post('/organizations/{organizationId}/media/{mediaId}/replace', [OrganizationMediaController::class, 'replace'])->whereNumber('organizationId')->whereNumber('mediaId')->middleware('throttle:20,1');
        Route::post('/organizations/{organizationId}/media/{mediaId}/rotate', [OrganizationMediaController::class, 'rotate'])->whereNumber('organizationId')->whereNumber('mediaId')->middleware('throttle:30,1');
        Route::patch('/organizations/{organizationId}/media-order', [OrganizationMediaController::class, 'reorder'])->whereNumber('organizationId');
        Route::patch('/organizations/{organizationId}/media-bulk', [OrganizationMediaController::class, 'bulkUpdate'])->whereNumber('organizationId');
        Route::post('/organizations/{organizationId}/media-import-cover', [OrganizationMediaController::class, 'importCover'])->whereNumber('organizationId');
        Route::post('/organizations/{organizationId}/media-delete', [OrganizationMediaController::class, 'bulkDelete'])->whereNumber('organizationId');
        Route::post('/organizations/{organizationId}/media-restore', [OrganizationMediaController::class, 'restore'])->whereNumber('organizationId');
        Route::post('/organizations/{organizationId}/media/{mediaId}/cover', [OrganizationMediaController::class, 'setCover'])->whereNumber('organizationId')->whereNumber('mediaId');
        Route::delete('/organizations/{organizationId}/media/{mediaId}', [OrganizationMediaController::class, 'delete'])->whereNumber('organizationId')->whereNumber('mediaId');
        Route::get('/organizations/{organizationId}/media-albums', [OrganizationMediaController::class, 'albums'])->whereNumber('organizationId');
        Route::post('/organizations/{organizationId}/media-albums', [OrganizationMediaController::class, 'storeAlbum'])->whereNumber('organizationId');
        Route::delete('/organizations/{organizationId}/media-albums/{albumId}', [OrganizationMediaController::class, 'deleteAlbum'])->whereNumber('organizationId')->whereNumber('albumId');
    });
});
