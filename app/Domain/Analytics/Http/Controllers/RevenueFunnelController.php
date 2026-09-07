<?php

namespace App\Domain\Analytics\Http\Controllers;

use App\Domain\Analytics\Services\ProfitabilityRiskPrioritizer;
use App\Domain\Analytics\Services\RevenueFunnelService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RevenueFunnelController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly RevenueFunnelService $revenueFunnel,
        private readonly ProfitabilityRiskPrioritizer $profitabilityRisks,
    ) {}

    public function show(Request $request, int $organizationId): JsonResponse
    {
        $metrics = $this->revenueFunnel->metrics(
            $this->context->id(),
            $organizationId,
            $request->user(),
            (int) $request->query('days', 30),
        );
        $metrics['profitability_risks'] = $this->profitabilityRisks->prioritize($metrics['payment_methods'] ?? []);

        return response()->json($metrics);
    }
}
