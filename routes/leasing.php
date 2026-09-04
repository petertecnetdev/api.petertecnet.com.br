<?php

use App\Domain\Assets\Http\Controllers\AssetAuditController;
use App\Domain\Assets\Http\Controllers\AssetFileController;
use App\Domain\Assets\Http\Controllers\AssetInspectionController;
use App\Domain\Assets\Http\Controllers\AssetManagementController;
use App\Domain\Assets\Http\Controllers\AssetReportController;
use App\Domain\Assets\Http\Controllers\AssetSharingController;
use App\Domain\Assets\Http\Controllers\AssetStructureController;
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

        // Generic asset intelligence and management. The first registered adapter is "property";
        // future applications can register other asset types without app-specific controllers or tables.
        Route::get('/assets/{assetType}/portfolio', [AssetManagementController::class, 'portfolio'])->where('assetType', '[A-Za-z0-9_-]+');
        Route::get('/assets/{assetType}/{assetId}/profile', [AssetManagementController::class, 'profile'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
        Route::put('/assets/{assetType}/{assetId}/profile', [AssetManagementController::class, 'updateProfile'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
        Route::get('/assets/{assetType}/{assetId}/analytics', [AssetManagementController::class, 'analytics'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
        Route::get('/assets/{assetType}/{assetId}/health', [AssetManagementController::class, 'health'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
        Route::get('/assets/{assetType}/{assetId}/alerts', [AssetManagementController::class, 'alerts'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
        Route::get('/assets/{assetType}/{assetId}/financial', [AssetManagementController::class, 'financial'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
        Route::post('/assets/{assetType}/{assetId}/financial/entries', [AssetManagementController::class, 'storeFinancialEntry'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
        Route::patch('/assets/{assetType}/{assetId}/financial/entries/{entryId}', [AssetManagementController::class, 'updateFinancialEntry'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId')->whereNumber('entryId');
        Route::delete('/assets/{assetType}/{assetId}/financial/entries/{entryId}', [AssetManagementController::class, 'deleteFinancialEntry'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId')->whereNumber('entryId');

        Route::get('/assets/{assetType}/{assetId}/spaces', [AssetStructureController::class, 'spaces'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
        Route::post('/assets/{assetType}/{assetId}/spaces', [AssetStructureController::class, 'storeSpace'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
        Route::patch('/assets/{assetType}/{assetId}/spaces/{spaceId}', [AssetStructureController::class, 'updateSpace'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId')->whereNumber('spaceId');
        Route::delete('/assets/{assetType}/{assetId}/spaces/{spaceId}', [AssetStructureController::class, 'deleteSpace'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId')->whereNumber('spaceId');

        Route::get('/assets/{assetType}/{assetId}/inventory', [AssetStructureController::class, 'inventory'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
        Route::post('/assets/{assetType}/{assetId}/inventory', [AssetStructureController::class, 'storeInventory'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
        Route::patch('/assets/{assetType}/{assetId}/inventory/{itemId}', [AssetStructureController::class, 'updateInventory'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId')->whereNumber('itemId');
        Route::delete('/assets/{assetType}/{assetId}/inventory/{itemId}', [AssetStructureController::class, 'deleteInventory'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId')->whereNumber('itemId');

        Route::get('/assets/{assetType}/{assetId}/preventive', [AssetStructureController::class, 'preventive'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
        Route::post('/assets/{assetType}/{assetId}/preventive', [AssetStructureController::class, 'storePreventive'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
        Route::patch('/assets/{assetType}/{assetId}/preventive/{planId}', [AssetStructureController::class, 'updatePreventive'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId')->whereNumber('planId');
        Route::post('/assets/{assetType}/{assetId}/preventive/{planId}/complete', [AssetStructureController::class, 'completePreventive'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId')->whereNumber('planId');
        Route::delete('/assets/{assetType}/{assetId}/preventive/{planId}', [AssetStructureController::class, 'deletePreventive'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId')->whereNumber('planId');

        Route::get('/assets/{assetType}/{assetId}/tags', [AssetStructureController::class, 'tags'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
        Route::put('/assets/{assetType}/{assetId}/tags', [AssetStructureController::class, 'setTags'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
        Route::get('/assets/{assetType}/{assetId}/ownerships', [AssetStructureController::class, 'ownerships'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
        Route::post('/assets/{assetType}/{assetId}/ownerships', [AssetStructureController::class, 'storeOwnership'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
        Route::patch('/assets/{assetType}/{assetId}/ownerships/{ownershipId}', [AssetStructureController::class, 'updateOwnership'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId')->whereNumber('ownershipId');
        Route::delete('/assets/{assetType}/{assetId}/ownerships/{ownershipId}', [AssetStructureController::class, 'deleteOwnership'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId')->whereNumber('ownershipId');

        Route::get('/assets/{assetType}/{assetId}/shares', [AssetSharingController::class, 'index'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
        Route::post('/assets/{assetType}/{assetId}/shares', [AssetSharingController::class, 'store'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId')->middleware('throttle:20,1');
        Route::delete('/assets/{assetType}/{assetId}/shares/{grantId}', [AssetSharingController::class, 'revoke'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId')->whereNumber('grantId');

        Route::get('/assets/{assetType}/{assetId}/files', [AssetFileController::class, 'index'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
        Route::post('/assets/{assetType}/{assetId}/files', [AssetFileController::class, 'store'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId')->middleware('throttle:30,1');
        Route::patch('/assets/{assetType}/{assetId}/files/{fileId}', [AssetFileController::class, 'update'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId')->whereNumber('fileId');
        Route::get('/assets/{assetType}/{assetId}/files/{fileId}', [AssetFileController::class, 'download'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId')->whereNumber('fileId');
        Route::delete('/assets/{assetType}/{assetId}/files/{fileId}', [AssetFileController::class, 'destroy'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId')->whereNumber('fileId');

        Route::get('/assets/{assetType}/{assetId}/inspection-comparison', [AssetInspectionController::class, 'compare'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
        Route::get('/assets/{assetType}/{assetId}/audit', [AssetAuditController::class, 'index'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId');
        Route::get('/assets/{assetType}/{assetId}/report.pdf', [AssetReportController::class, 'dossier'])->where('assetType', '[A-Za-z0-9_-]+')->whereNumber('assetId')->middleware('throttle:10,1');

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

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'app.capability:leasing', 'throttle:60,1'])
    ->group(function () {
        Route::get('/shared-assets/{token}', [AssetSharingController::class, 'sharedView'])->where('token', '[A-Za-z0-9]{32,128}');
        Route::get('/shared-assets/{token}/files/{fileId}', [AssetSharingController::class, 'sharedFile'])->where('token', '[A-Za-z0-9]{32,128}')->whereNumber('fileId');
    });
