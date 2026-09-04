<?php

use App\Domain\Leasing\Http\Controllers\LeaseAmendmentController;
use App\Domain\Leasing\Http\Controllers\LeaseChargeLifecycleController;
use App\Domain\Leasing\Http\Controllers\LeaseLifecycleController;
use App\Domain\Leasing\Http\Controllers\LeaseOnboardingController;
use App\Domain\Leasing\Http\Controllers\LeaseOperationsController;
use App\Domain\Leasing\Http\Controllers\LeasePackageLifecycleController;
use App\Domain\Leasing\Http\Controllers\LeasePaymentWorkflowController;
use App\Domain\Leasing\Http\Controllers\LeaseReadController;
use App\Domain\Leasing\Http\Controllers\LeaseWorkflowController;
use App\Domain\Leasing\Http\Controllers\LeasingController;
use App\Domain\Leasing\Http\Controllers\LeasingPolicyAccessController;
use App\Domain\Leasing\Http\Controllers\PropertyGovernanceController;
use App\Domain\Leasing\Http\Middleware\CaptureLeaseRevision;
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
        Route::get('/leasing/policy', [LeasingPolicyAccessController::class, 'policy']);
        Route::put('/leasing/policy', [LeasingPolicyAccessController::class, 'updatePolicy']);
        Route::get('/leasing/access-grants', [LeasingPolicyAccessController::class, 'grants']);
        Route::post('/leasing/access-grants', [LeasingPolicyAccessController::class, 'storeGrant']);
        Route::delete('/leasing/access-grants/{grantId}', [LeasingPolicyAccessController::class, 'revokeGrant'])->whereNumber('grantId');

        Route::get('/properties', [LeaseReadController::class, 'properties']);
        Route::post('/properties', [LeasingController::class, 'storeProperty']);
        Route::match(['put', 'patch'], '/properties/{propertyId}', [LeasingController::class, 'updateProperty'])
            ->whereNumber('propertyId')->middleware(ProtectPropertyLeaseState::class);
        Route::delete('/properties/{propertyId}', [LeasingController::class, 'destroyProperty'])->whereNumber('propertyId');
        Route::get('/properties/{propertyId}/timeline', [LeaseLifecycleController::class, 'propertyTimeline'])->whereNumber('propertyId');

        Route::get('/properties/{propertyId}/documents', [PropertyGovernanceController::class, 'documents'])->whereNumber('propertyId');
        Route::post('/properties/{propertyId}/documents', [PropertyGovernanceController::class, 'uploadDocument'])->whereNumber('propertyId')->middleware('throttle:30,1');
        Route::get('/properties/{propertyId}/documents/{documentId}', [PropertyGovernanceController::class, 'downloadDocument'])->whereNumber('propertyId')->whereNumber('documentId');
        Route::delete('/properties/{propertyId}/documents/{documentId}', [PropertyGovernanceController::class, 'deleteDocument'])->whereNumber('propertyId')->whereNumber('documentId');
        Route::get('/properties/{propertyId}/availability', [PropertyGovernanceController::class, 'availability'])->whereNumber('propertyId');
        Route::post('/properties/{propertyId}/availability', [PropertyGovernanceController::class, 'storeAvailability'])->whereNumber('propertyId');
        Route::delete('/properties/{propertyId}/availability/{blockId}', [PropertyGovernanceController::class, 'deleteAvailability'])->whereNumber('propertyId')->whereNumber('blockId');

        Route::get('/properties/{propertyId}/inspections', [LeasingController::class, 'inspections'])->whereNumber('propertyId');
        Route::post('/properties/{propertyId}/inspections', [LeasingController::class, 'storeInspection'])->whereNumber('propertyId');
        Route::patch('/properties/{propertyId}/inspections/{inspectionId}', [PropertyGovernanceController::class, 'updateInspection'])->whereNumber('propertyId')->whereNumber('inspectionId');
        Route::post('/properties/{propertyId}/inspections/{inspectionId}/sign', [PropertyGovernanceController::class, 'signInspection'])->whereNumber('propertyId')->whereNumber('inspectionId');
        Route::post('/properties/{propertyId}/inspections/{inspectionId}/finalize', [PropertyGovernanceController::class, 'finalizeInspection'])->whereNumber('propertyId')->whereNumber('inspectionId');
        Route::get('/properties/{propertyId}/inspections/compare', [PropertyGovernanceController::class, 'compareInspections'])->whereNumber('propertyId');

        Route::get('/leases', [LeaseReadController::class, 'leases']);
        Route::post('/leases', [LeasingController::class, 'storeLease'])->middleware(PreventLeaseOverlap::class);
        Route::get('/leases/{leaseId}', [LeasingController::class, 'showLease'])->whereNumber('leaseId');
        Route::match(['put', 'patch'], '/leases/{leaseId}', [LeasingController::class, 'updateLease'])
            ->whereNumber('leaseId')->middleware([PreventLeaseOverlap::class, CaptureLeaseRevision::class]);
        Route::post('/leases/{leaseId}/renew', [LeaseLifecycleController::class, 'renew'])->whereNumber('leaseId')->middleware(CaptureLeaseRevision::class);
        Route::post('/leases/{leaseId}/terminate', [LeaseLifecycleController::class, 'terminate'])->whereNumber('leaseId')->middleware(CaptureLeaseRevision::class);
        Route::get('/leases/{leaseId}/revisions', [LeaseAmendmentController::class, 'revisions'])->whereNumber('leaseId');
        Route::get('/leases/{leaseId}/amendments', [LeaseAmendmentController::class, 'index'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/amendments', [LeaseAmendmentController::class, 'store'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/amendments/{amendmentId}/sign', [LeaseAmendmentController::class, 'sign'])->whereNumber('leaseId')->whereNumber('amendmentId');
        Route::delete('/leases/{leaseId}/amendments/{amendmentId}', [LeaseAmendmentController::class, 'cancel'])->whereNumber('leaseId')->whereNumber('amendmentId');

        Route::get('/leases/{leaseId}/readiness', [LeaseOnboardingController::class, 'checklist'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/tenant/invite', [LeaseOnboardingController::class, 'inviteTenant'])->whereNumber('leaseId')->middleware('throttle:10,1');
        Route::post('/leases/{leaseId}/tenant/invite/accept', [LeaseOnboardingController::class, 'acceptInvitation'])->whereNumber('leaseId')->middleware('throttle:20,1');
        Route::patch('/leases/{leaseId}/tenant/profile', [LeaseWorkflowController::class, 'updateTenantProfile'])->whereNumber('leaseId')->middleware(CaptureLeaseRevision::class);
        Route::put('/leases/{leaseId}/initial-payment-agreement', [LeaseOnboardingController::class, 'configureAgreement'])->whereNumber('leaseId')->middleware(CaptureLeaseRevision::class);

        Route::post('/leases/{leaseId}/contract/generate', [LeasePackageLifecycleController::class, 'generate'])->whereNumber('leaseId')->middleware(CaptureLeaseRevision::class);
        Route::post('/leases/{leaseId}/contract/send', [LeasingController::class, 'sendContract'])->whereNumber('leaseId')->middleware('throttle:10,1');
        Route::post('/leases/{leaseId}/contract/sign', [LeasingController::class, 'sign'])->whereNumber('leaseId')->middleware(['throttle:20,1', CaptureLeaseRevision::class]);
        Route::post('/leases/{leaseId}/payments/request', [LeasePaymentWorkflowController::class, 'requestInitialPayment'])->whereNumber('leaseId')->middleware(['throttle:10,1', CaptureLeaseRevision::class]);
        Route::post('/leases/{leaseId}/activate', [LeaseOnboardingController::class, 'activateIfReady'])->whereNumber('leaseId')->middleware(CaptureLeaseRevision::class);

        Route::get('/leases/{leaseId}/timeline', [LeaseOperationsController::class, 'timeline'])->whereNumber('leaseId');
        Route::get('/leases/{leaseId}/document-requirements', [LeaseOperationsController::class, 'documentRequirements'])->whereNumber('leaseId');
        Route::put('/leases/{leaseId}/document-requirements', [LeaseOperationsController::class, 'setDocumentRequirements'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/adjustments/preview', [LeaseOperationsController::class, 'previewAdjustment'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/adjustments', [LeaseOperationsController::class, 'applyAdjustment'])->whereNumber('leaseId')->middleware(CaptureLeaseRevision::class);
        Route::get('/leases/{leaseId}/maintenance', [LeaseOperationsController::class, 'maintenance'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/maintenance', [LeaseOperationsController::class, 'storeMaintenance'])->whereNumber('leaseId');
        Route::patch('/leases/{leaseId}/maintenance/{operationId}', [LeaseOperationsController::class, 'updateMaintenance'])->whereNumber('leaseId')->whereNumber('operationId');
        Route::get('/leases/{leaseId}/termination', [LeaseOperationsController::class, 'termination'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/termination', [LeaseOperationsController::class, 'startTermination'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/termination/complete', [LeaseOperationsController::class, 'completeTermination'])->whereNumber('leaseId')->middleware(CaptureLeaseRevision::class);

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
