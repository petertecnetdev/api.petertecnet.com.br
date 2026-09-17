<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Contracts\PayoutProvider;
use App\Http\Controllers\Controller;
use App\Models\Production;
use App\Services\FinancialPayoutService;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
            'manual_payout_requests_enabled' => true,
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
        $organization = $this->ownedOrganization($request, $organizationId);
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:999999999.99',
        ]);
        $amount = round((float) $data['amount'], 2);

        $overview = $this->payouts->overview($organization, $request->user());
        $eligible = (bool) ($overview['ready_for_payout'] ?? false)
            && $amount <= (float) data_get($overview, 'balance.available', 0) + 0.00001;

        if ($eligible) {
            if (! $this->provider->isConfigured()) {
                return response()->json([
                    'message' => 'O serviço de repasses Pix ainda não está configurado para operação.',
                ], 503);
            }

            try {
                if ($this->provider->availableBalance() + 0.00001 < $amount) {
                    return response()->json([
                        'message' => 'O repasse está temporariamente aguardando liquidação operacional. Tente novamente mais tarde.',
                    ], 503);
                }
            } catch (RuntimeException $exception) {
                report($exception);

                return response()->json([
                    'message' => 'Não foi possível confirmar a disponibilidade operacional do repasse agora. Tente novamente.',
                ], 503);
            }
        }

        try {
            return response()->json(
                $this->payouts->requestPayout($organization, $request->user(), $amount),
                201
            );
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json(['message' => $exception->getMessage()], 502);
        }
    }

    public function cancel(Request $request, int $organizationId, int $payoutId)
    {
        $this->ownedOrganization($request, $organizationId);

        $payout = DB::table('financial_payouts')
            ->where('source_type', 'production')
            ->where('source_id', $organizationId)
            ->where('id', $payoutId)
            ->first();

        abort_unless($payout, 404, 'Repasse não encontrado.');

        // PIX is submitted immediately after the local reservation. Cancelling a
        // bank transfer locally after submission could release balance and cause a
        // duplicate payment. Provider cancellation/failure is therefore reconciled
        // only from authenticated provider events.
        abort(422, 'Este repasse Pix não pode ser cancelado manualmente após a solicitação. Aguarde a confirmação ou a conciliação do provedor.');
    }

    private function ownedOrganization(Request $request, int $organizationId): Production
    {
        $organization = Production::query()
            ->where('app_id', $this->context->id())
            ->findOrFail($organizationId);

        $user = $request->user();
        $admin = $user && method_exists($user, 'hasProfile') && $user->hasProfile('Administrador');
        abort_unless($user && ($admin || (int) $organization->user_id === (int) $user->id), 403);

        return $organization;
    }
}
