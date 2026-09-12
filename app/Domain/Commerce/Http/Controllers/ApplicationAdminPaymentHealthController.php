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
        $appId = $this->context->id();
        $current = $this->health->forApplication($appId);
        $current['trend'] = $this->snapshots->trendForApplication($appId, $current);
        $current['incidents'] = $this->snapshots->incidentHistoryForApplication($appId);

        return response()->json([
            'success' => true,
            'scope' => 'global_application',
            'data' => $current,
        ]);
    }
}
