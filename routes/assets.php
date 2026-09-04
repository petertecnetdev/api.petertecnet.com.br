<?php

use App\Domain\Assets\Http\Controllers\AssetResourceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version', 'app.capability:leasing'])
    ->group(function () {
        Route::get('/assets/{assetType}/{assetId}', [AssetResourceController::class, 'show'])
            ->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
        Route::patch('/assets/{assetType}/{assetId}', [AssetResourceController::class, 'update'])
            ->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
        Route::get('/assets/{assetType}/{assetId}/inspections', [AssetResourceController::class, 'inspections'])
            ->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
        Route::post('/assets/{assetType}/{assetId}/inspections', [AssetResourceController::class, 'storeInspection'])
            ->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
        Route::get('/assets/{assetType}/{assetId}/maintenance', [AssetResourceController::class, 'maintenance'])
            ->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
        Route::post('/assets/{assetType}/{assetId}/maintenance', [AssetResourceController::class, 'storeMaintenance'])
            ->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
        Route::patch('/assets/{assetType}/{assetId}/maintenance/{operationId}', [AssetResourceController::class, 'updateMaintenance'])
            ->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId')->whereNumber('operationId');
        Route::get('/assets/{assetType}/{assetId}/timeline', [AssetResourceController::class, 'timeline'])
            ->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
    });
