<?php

use App\Domain\Leasing\Http\Controllers\LeaseDocumentWorkflowController;
use App\Domain\Leasing\Http\Controllers\LeasingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version', 'app.capability:leasing'])
    ->group(function () {
        Route::get('/leasing/dashboard', [LeasingController::class, 'dashboard']);

        Route::get('/properties', [LeasingController::class, 'properties']);
        Route::post('/properties', [LeasingController::class, 'storeProperty']);
        Route::match(['put', 'patch'], '/properties/{propertyId}', [LeasingController::class, 'updateProperty'])->whereNumber('propertyId');
        Route::delete('/properties/{propertyId}', [LeasingController::class, 'destroyProperty'])->whereNumber('propertyId');
        Route::get('/properties/{propertyId}/inspections', [LeasingController::class, 'inspections'])->whereNumber('propertyId');
        Route::post('/properties/{propertyId}/inspections', [LeasingController::class, 'storeInspection'])->whereNumber('propertyId');

        Route::get('/leases', [LeasingController::class, 'leases']);
        Route::post('/leases', [LeasingController::class, 'storeLease']);
        Route::get('/leases/{leaseId}', [LeasingController::class, 'showLease'])->whereNumber('leaseId');
        Route::match(['put', 'patch'], '/leases/{leaseId}', [LeasingController::class, 'updateLease'])->whereNumber('leaseId');

        Route::get('/leases/{leaseId}/contract', [LeaseDocumentWorkflowController::class, 'show'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/proposal/generate', [LeaseDocumentWorkflowController::class, 'proposal'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/contract/generate', [LeaseDocumentWorkflowController::class, 'generate'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/contract/send', [LeaseDocumentWorkflowController::class, 'send'])->whereNumber('leaseId')->middleware('throttle:10,1');
        Route::post('/leases/{leaseId}/contract/sign', [LeaseDocumentWorkflowController::class, 'sign'])->whereNumber('leaseId')->middleware('throttle:20,1');
        Route::get('/leases/{leaseId}/contract/timeline', [LeaseDocumentWorkflowController::class, 'timeline'])->whereNumber('leaseId');
        Route::get('/leases/{leaseId}/contract/amendments', [LeaseDocumentWorkflowController::class, 'amendments'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/contract/amendments', [LeaseDocumentWorkflowController::class, 'storeAmendment'])->whereNumber('leaseId');

        Route::get('/leases/{leaseId}/documents', [LeasingController::class, 'documents'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/documents', [LeasingController::class, 'uploadDocument'])->whereNumber('leaseId')->middleware('throttle:30,1');
        Route::get('/leases/{leaseId}/documents/{documentId}', [LeasingController::class, 'downloadDocument'])->whereNumber('leaseId')->whereNumber('documentId');
        Route::delete('/leases/{leaseId}/documents/{documentId}', [LeasingController::class, 'deleteDocument'])->whereNumber('leaseId')->whereNumber('documentId');

        Route::get('/leases/{leaseId}/charges', [LeasingController::class, 'charges'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/charges', [LeasingController::class, 'storeCharge'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/charges/schedule', [LeasingController::class, 'generateRentSchedule'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/charges/{chargeId}/payment', [LeasingController::class, 'preparePayment'])->whereNumber('leaseId')->whereNumber('chargeId')->middleware('throttle:20,1');
        Route::patch('/leases/{leaseId}/charges/{chargeId}/paid', [LeasingController::class, 'markChargePaid'])->whereNumber('leaseId')->whereNumber('chargeId');
    });
