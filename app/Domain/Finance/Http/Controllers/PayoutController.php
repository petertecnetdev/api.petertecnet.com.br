<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Contracts\PayoutProvider;
use App\Http\Controllers\Controller;
use App\Services\FinancialPayoutService;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;

/**
 * Canonical provider-neutral payout surface.
 *
 * Collections remain on the payment provider configured for commerce, while
 * actual producer withdrawals are delegated to the payout provider (Asaas in
 * production today). Both canonical and compatibility routes share the same
 * financial_payouts storage and balance rules.
 */
final class PayoutController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly FinancialPayoutService $payouts,
        private readonly PayoutProvider $provider,
    ) {}

    public function summary(Request $request, int $organizationId)
    {
        $organization = $this->ownedOrganization($request, $organizationId);
        $overview = $this->payouts->overview($organization, $request->user());

        return response()->json([
            ...$overview,
            // Backward-compatible aliases for older clients.
            'provider' => $this->provider->name(),
            'current_settlement_mode' => 'platform_collection',
            // Fail closed while payout creation lacks a caller-stable,
            // transactionally persisted idempotency contract.
            'manual_payout_requests_enabled' => false,
            'platform_collection_enabled' => (bool) config(
                "platform.applications.{$organization->app_slug}.commerce.allow_platform_collection",
                false
            ),
            'platform_collected_credit' => (float) data_get($overview, 'balance.producer_credit', 0),
            'available_for_payout' => (float) data_get($overview, 'balance.available', 0),
            'payout_pending' => (float) data_get($overview, 'balance.payout_pending', 0),
            'payout_paid' => (float) data_get($overview, 'balance.payout_paid', 0),
            'history' => $overview['payouts'] ?? [],
        ]);
    }

    public function requestPayout(Request $request, int $organizationId)
    {
        // Authorization still runs before the circuit breaker so callers cannot
        // use this endpoint to probe organizations belonging to another app/user.
        $this->ownedOrganization($request, $organizationId);

        // P0 safety circuit breaker: FinancialPayoutService currently generates
        // a fresh idempotency key for every request. A client retry after an
        // ambiguous provider response can therefore create a second transfer.
        // Keep producer funds reserved and fail closed until the API accepts a
        // stable caller key and enforces replay/conflict semantics atomically.
        return response()->json([
            'message' => 'Solicitações de repasse Pix estão temporariamente indisponíveis enquanto uma proteção de idempotência é aplicada. Nenhum saldo será perdido.',
            'code' => 'payout_idempotency_safety_hold',
        ], 503);
    }

    public function cancel(Request $request, int $organizationId, int $payoutId)
    {
        $organization = $this->ownedOrganization($request, $organizationId);
        $this->payouts->rejectManualCancellation($organization, $payoutId);
    }

    private function ownedOrganization(Request $request, int $organizationId)
    {
        return $this->payouts->ownedProduction(
            $this->context->id(),
            $organizationId,
            $request->user()
        );
    }
}
