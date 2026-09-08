<?php

namespace App\Domain\Analytics\Http\Controllers;

use App\Domain\Analytics\Services\ProfitabilityRiskPrioritizer;
use App\Domain\Analytics\Services\RevenueFunnelService;
use App\Domain\Analytics\Services\RevenueRecoveryEconomicsService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RevenueFunnelController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly RevenueFunnelService $revenueFunnel,
        private readonly RevenueRecoveryEconomicsService $recoveryEconomics,
        private readonly ProfitabilityRiskPrioritizer $profitabilityRisks,
    ) {}

    public function show(Request $request, int $organizationId): JsonResponse
    {
        $days = (int) $request->query('days', 30);
        $appId = $this->context->id();
        $metrics = $this->revenueFunnel->metrics(
            $appId,
            $organizationId,
            $request->user(),
            $days,
        );
        $metrics = $this->recoveryEconomics->enrich($appId, $organizationId, $days, $metrics);
        $metrics['profitability_risks'] = $this->profitabilityRisks->prioritize($metrics['payment_methods'] ?? []);

        return response()->json($metrics);
    }
}
