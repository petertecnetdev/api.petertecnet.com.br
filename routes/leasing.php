<?php

use App\Domain\Leasing\Http\Controllers\LeaseOnboardingController;
use App\Domain\Leasing\Http\Controllers\LeaseOperationsController;
use App\Domain\Leasing\Http\Controllers\LeasePackageLifecycleController;
use App\Domain\Leasing\Http\Controllers\LeaseWorkflowController;
use App\Domain\Leasing\Http\Controllers\LeasingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version', 'app.capability:leasing'])
    ->group(function () {
        Route::get('/leasing/dashboard', [LeasingController::class, 'dashboard']);
        Route::get('/leasing/action-center', [LeaseOperationsController::class, 'actionCenter']);
        Route::get('/leasing/portfolio', [LeaseOperationsController::class, 'portfolio']);
        Route::get('/leasing/tenant-portal', [LeaseOperationsController::class, 'tenantPortal']);

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

        Route::get('/leases/{leaseId}/readiness', [LeaseOnboardingController::class, 'checklist'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/tenant/invite', [LeaseOnboardingController::class, 'inviteTenant'])->whereNumber('leaseId')->middleware('throttle:10,1');
        Route::post('/leases/{leaseId}/tenant/invite/accept', [LeaseOnboardingController::class, 'acceptInvitation'])->whereNumber('leaseId')->middleware('throttle:20,1');
        Route::patch('/leases/{leaseId}/tenant/profile', [LeaseWorkflowController::class, 'updateTenantProfile'])->whereNumber('leaseId');
        Route::put('/leases/{leaseId}/initial-payment-agreement', [LeaseOnboardingController::class, 'configureAgreement'])->whereNumber('leaseId');

        Route::post('/leases/{leaseId}/contract/generate', [LeasePackageLifecycleController::class, 'generate'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/contract/send', [LeasingController::class, 'sendContract'])->whereNumber('leaseId')->middleware('throttle:10,1');
        Route::post('/leases/{leaseId}/contract/sign', [LeasingController::class, 'sign'])->whereNumber('leaseId')->middleware('throttle:20,1');
        Route::post('/leases/{leaseId}/payments/request', [LeaseWorkflowController::class, 'requestInitialPayment'])->whereNumber('leaseId')->middleware('throttle:10,1');
        Route::post('/leases/{leaseId}/activate', [LeaseOnboardingController::class, 'activateIfReady'])->whereNumber('leaseId');

        Route::get('/leases/{leaseId}/timeline', [LeaseOperationsController::class, 'timeline'])->whereNumber('leaseId');
        Route::get('/leases/{leaseId}/document-requirements', [LeaseOperationsController::class, 'documentRequirements'])->whereNumber('leaseId');
        Route::put('/leases/{leaseId}/document-requirements', [LeaseOperationsController::class, 'setDocumentRequirements'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/adjustments/preview', [LeaseOperationsController::class, 'previewAdjustment'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/adjustments', [LeaseOperationsController::class, 'applyAdjustment'])->whereNumber('leaseId');
        Route::get('/leases/{leaseId}/maintenance', [LeaseOperationsController::class, 'maintenance'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/maintenance', [LeaseOperationsController::class, 'storeMaintenance'])->whereNumber('leaseId');
        Route::patch('/leases/{leaseId}/maintenance/{operationId}', [LeaseOperationsController::class, 'updateMaintenance'])->whereNumber('leaseId')->whereNumber('operationId');
        Route::get('/leases/{leaseId}/termination', [LeaseOperationsController::class, 'termination'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/termination', [LeaseOperationsController::class, 'startTermination'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/termination/complete', [LeaseOperationsController::class, 'completeTermination'])->whereNumber('leaseId');

        Route::get('/leases/{leaseId}/documents', [LeasingController::class, 'documents'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/documents', [LeasingController::class, 'uploadDocument'])->whereNumber('leaseId')->middleware('throttle:30,1');
        Route::post('/leases/{leaseId}/documents/{documentId}/extract', [LeaseOnboardingController::class, 'extractDocument'])->whereNumber('leaseId')->whereNumber('documentId')->middleware('throttle:10,1');
        Route::post('/leases/{leaseId}/documents/{documentId}/confirm-extraction', [LeaseOnboardingController::class, 'confirmExtraction'])->whereNumber('leaseId')->whereNumber('documentId');
        Route::get('/leases/{leaseId}/documents/{documentId}', [LeasingController::class, 'downloadDocument'])->whereNumber('leaseId')->whereNumber('documentId');
        Route::delete('/leases/{leaseId}/documents/{documentId}', [LeasingController::class, 'deleteDocument'])->whereNumber('leaseId')->whereNumber('documentId');

        Route::get('/leases/{leaseId}/charges', [LeasingController::class, 'charges'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/charges', [LeasingController::class, 'storeCharge'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/charges/schedule', [LeasingController::class, 'generateRentSchedule'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/charges/{chargeId}/payment', [LeasingController::class, 'preparePayment'])->whereNumber('leaseId')->whereNumber('chargeId')->middleware('throttle:20,1');
        Route::patch('/leases/{leaseId}/charges/{chargeId}/paid', [LeasingController::class, 'markChargePaid'])->whereNumber('leaseId')->whereNumber('chargeId');
    });
