<?php

use App\Domain\Leasing\Http\Controllers\LeaseChargeLifecycleController;
use App\Domain\Leasing\Http\Controllers\LeaseDocumentWorkflowController;
use App\Domain\Leasing\Http\Controllers\LeaseLifecycleController;
use App\Domain\Leasing\Http\Controllers\LeaseOnboardingController;
use App\Domain\Leasing\Http\Controllers\LeaseOperationsController;
use App\Domain\Leasing\Http\Controllers\LeasePaymentWorkflowController;
use App\Domain\Leasing\Http\Controllers\LeaseReadController;
use App\Domain\Leasing\Http\Controllers\LeaseWorkflowController;
use App\Domain\Leasing\Http\Controllers\LeasingController;
use App\Domain\Leasing\Http\Controllers\PropertyWorkspaceController;
use App\Domain\Leasing\Http\Middleware\PreventLeaseOverlap;
use App\Domain\Leasing\Http\Middleware\ProtectPropertyLeaseState;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version', 'app.capability:leasing'])
    ->group(function () {
        Route::get('/leasing/dashboard', [LeasingController::class, 'dashboard']);
        Route::get('/leasing/action-center', [LeaseOperationsController::class, 'actionCenter']);
        Route::get('/leasing/portfolio', [LeaseOperationsController::class, 'portfolio']);
        Route::get('/leasing/tenant-portal', [LeaseOperationsController::class, 'tenantPortal']);
        Route::get('/leasing/lifecycle', [LeaseLifecycleController::class, 'index']);

        Route::get('/properties', [LeaseReadController::class, 'properties']);
        Route::post('/properties', [LeasingController::class, 'storeProperty']);
        Route::get('/properties/{propertyId}', [PropertyWorkspaceController::class, 'show'])->whereNumber('propertyId');
        Route::match(['put', 'patch'], '/properties/{propertyId}', [LeasingController::class, 'updateProperty'])->whereNumber('propertyId')->middleware(ProtectPropertyLeaseState::class);
        Route::delete('/properties/{propertyId}', [LeasingController::class, 'destroyProperty'])->whereNumber('propertyId');
        Route::get('/properties/{propertyId}/timeline', [PropertyWorkspaceController::class, 'timeline'])->whereNumber('propertyId');
        Route::get('/properties/{propertyId}/financial', [PropertyWorkspaceController::class, 'financial'])->whereNumber('propertyId');
        Route::get('/properties/{propertyId}/inspections', [LeasingController::class, 'inspections'])->whereNumber('propertyId');
        Route::post('/properties/{propertyId}/inspections', [LeasingController::class, 'storeInspection'])->whereNumber('propertyId');
        Route::get('/properties/{propertyId}/maintenance', [PropertyWorkspaceController::class, 'maintenance'])->whereNumber('propertyId');
        Route::post('/properties/{propertyId}/maintenance', [PropertyWorkspaceController::class, 'storeMaintenance'])->whereNumber('propertyId');
        Route::patch('/properties/{propertyId}/maintenance/{operationId}', [PropertyWorkspaceController::class, 'updateMaintenance'])->whereNumber('propertyId')->whereNumber('operationId');
        Route::get('/properties/{propertyId}/assets', [PropertyWorkspaceController::class, 'assets'])->whereNumber('propertyId');
        Route::post('/properties/{propertyId}/assets', [PropertyWorkspaceController::class, 'storeAsset'])->whereNumber('propertyId')->middleware('throttle:30,1');
        Route::patch('/properties/{propertyId}/assets/{assetId}', [PropertyWorkspaceController::class, 'updateAsset'])->whereNumber('propertyId')->whereNumber('assetId');
        Route::get('/properties/{propertyId}/assets/{assetId}', [PropertyWorkspaceController::class, 'downloadAsset'])->whereNumber('propertyId')->whereNumber('assetId');
        Route::delete('/properties/{propertyId}/assets/{assetId}', [PropertyWorkspaceController::class, 'deleteAsset'])->whereNumber('propertyId')->whereNumber('assetId');

        Route::get('/leases', [LeaseReadController::class, 'leases']);
        Route::post('/leases', [LeasingController::class, 'storeLease'])->middleware(PreventLeaseOverlap::class);
        Route::get('/leases/{leaseId}', [LeasingController::class, 'showLease'])->whereNumber('leaseId');
        Route::match(['put', 'patch'], '/leases/{leaseId}', [LeasingController::class, 'updateLease'])->whereNumber('leaseId')->middleware(PreventLeaseOverlap::class);
        Route::post('/leases/{leaseId}/renew', [LeaseLifecycleController::class, 'renew'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/terminate', [LeaseLifecycleController::class, 'terminate'])->whereNumber('leaseId');

        Route::get('/leases/{leaseId}/readiness', [LeaseOnboardingController::class, 'checklist'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/tenant/invite', [LeaseOnboardingController::class, 'inviteTenant'])->whereNumber('leaseId')->middleware('throttle:10,1');
        Route::post('/leases/{leaseId}/tenant/invite/accept', [LeaseOnboardingController::class, 'acceptInvitation'])->whereNumber('leaseId')->middleware('throttle:20,1');
        Route::patch('/leases/{leaseId}/tenant/profile', [LeaseWorkflowController::class, 'updateTenantProfile'])->whereNumber('leaseId');
        Route::put('/leases/{leaseId}/initial-payment-agreement', [LeaseOnboardingController::class, 'configureAgreement'])->whereNumber('leaseId');

        Route::post('/leases/{leaseId}/proposal/generate', [LeaseDocumentWorkflowController::class, 'proposal'])->whereNumber('leaseId');
        Route::get('/leases/{leaseId}/contract', [LeaseDocumentWorkflowController::class, 'show'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/contract/generate', [LeaseDocumentWorkflowController::class, 'generate'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/contract/send', [LeaseDocumentWorkflowController::class, 'send'])->whereNumber('leaseId')->middleware('throttle:10,1');
        Route::post('/leases/{leaseId}/contract/sign', [LeaseDocumentWorkflowController::class, 'sign'])->whereNumber('leaseId')->middleware('throttle:20,1');
        Route::get('/leases/{leaseId}/contract/timeline', [LeaseDocumentWorkflowController::class, 'timeline'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/contract/amendments', [LeaseDocumentWorkflowController::class, 'storeAmendment'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/payments/request', [LeasePaymentWorkflowController::class, 'requestInitialPayment'])->whereNumber('leaseId')->middleware('throttle:10,1');
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
        Route::patch('/leases/{leaseId}/charges/{chargeId}/paid', [LeaseChargeLifecycleController::class, 'markPaid'])->whereNumber('leaseId')->whereNumber('chargeId');
    });
