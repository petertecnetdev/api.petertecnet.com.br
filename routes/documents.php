<?php

use App\Domain\Documents\Http\Controllers\DocumentCatalogController;
use App\Domain\Documents\Http\Controllers\DocumentExportController;
use App\Domain\Documents\Http\Controllers\PublicSignatureController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version'])
    ->group(function () {
        Route::get('/document-templates', [DocumentCatalogController::class, 'templates']);
        Route::post('/document-templates', [DocumentCatalogController::class, 'storeTemplate']);
        Route::post('/document-templates/{templateId}/versions', [DocumentCatalogController::class, 'addTemplateVersion'])->whereNumber('templateId');
        Route::get('/document-clauses', [DocumentCatalogController::class, 'clauses']);
        Route::post('/document-clauses', [DocumentCatalogController::class, 'storeClause']);
        Route::patch('/document-clauses/{clauseId}', [DocumentCatalogController::class, 'updateClause'])->whereNumber('clauseId');
        Route::get('/documents/{publicId}/pdf', [DocumentExportController::class, 'pdf'])->whereUuid('publicId');
    });

Route::prefix('v1/document-signatures')->middleware('throttle:30,1')->group(function () {
    Route::get('/{token}', [PublicSignatureController::class, 'show'])->where('token', '[A-Za-z0-9]{40,128}');
    Route::post('/{token}/sign', [PublicSignatureController::class, 'sign'])->where('token', '[A-Za-z0-9]{40,128}')->middleware('throttle:10,1');
});
