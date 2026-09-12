<?php

namespace App\Domain\Commerce\Http\Controllers;

use App\Domain\Commerce\Services\PaymentHealthSnapshotService;
use App\Domain\Commerce\Services\PaymentPendingHealthService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;

final class ApplicationAdminPaymentHealthController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly PaymentPendingHealthService $health,
        private readonly PaymentHealthSnapshotService $snapshots,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $current = $this->health->forApplication($this->context->id());
        $current['trend'] = $this->snapshots->trendForApplication($this->context->id(), $current);

        return response()->json([
            'success' => true,
            'scope' => 'global_application',
            'data' => $current,
        ]);
    }
}
