<?php

use App\Domain\Leasing\Http\Controllers\LeaseContractController;
use App\Domain\Leasing\Http\Controllers\LeaseLifecycleController;
use App\Domain\Leasing\Http\Controllers\LeaseReadController;
use App\Domain\Leasing\Http\Controllers\LeasingController;
use App\Domain\Leasing\Http\Middleware\PreventLeaseOverlap;
use App\Domain\Leasing\Http\Middleware\ProtectPropertyLeaseState;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/apps/{application}')
    ->middleware(['app.context', 'auth:api', 'token.version', 'app.capability:leasing'])
    ->group(function () {
        Route::get('/leasing/dashboard', [LeasingController::class, 'dashboard']);
        Route::get('/leasing/lifecycle', [LeaseLifecycleController::class, 'index']);

        Route::get('/properties', [LeaseReadController::class, 'properties']);
        Route::post('/properties', [LeasingController::class, 'storeProperty']);
        Route::match(['put', 'patch'], '/properties/{propertyId}', [LeasingController::class, 'updateProperty'])
            ->whereNumber('propertyId')->middleware(ProtectPropertyLeaseState::class);
        Route::delete('/properties/{propertyId}', [LeasingController::class, 'destroyProperty'])->whereNumber('propertyId');
        Route::get('/properties/{propertyId}/timeline', [LeaseLifecycleController::class, 'propertyTimeline'])->whereNumber('propertyId');
        Route::get('/properties/{propertyId}/inspections', [LeasingController::class, 'inspections'])->whereNumber('propertyId');
        Route::post('/properties/{propertyId}/inspections', [LeasingController::class, 'storeInspection'])->whereNumber('propertyId');

        Route::get('/leases', [LeaseReadController::class, 'leases']);
        Route::post('/leases', [LeasingController::class, 'storeLease'])->middleware(PreventLeaseOverlap::class);
        Route::get('/leases/{leaseId}', [LeasingController::class, 'showLease'])->whereNumber('leaseId');
        Route::match(['put', 'patch'], '/leases/{leaseId}', [LeasingController::class, 'updateLease'])
            ->whereNumber('leaseId')->middleware(PreventLeaseOverlap::class);
        Route::post('/leases/{leaseId}/renew', [LeaseLifecycleController::class, 'renew'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/terminate', [LeaseLifecycleController::class, 'terminate'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/contract/generate', [LeaseContractController::class, 'generate'])->whereNumber('leaseId');
        Route::post('/leases/{leaseId}/contract/send', [LeasingController::class, 'sendContract'])->whereNumber('leaseId')->middleware('throttle:10,1');
        Route::post('/leases/{leaseId}/contract/sign', [LeasingController::class, 'sign'])->whereNumber('leaseId')->middleware('throttle:20,1');

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
