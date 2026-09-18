<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Contracts\PayoutProvider;
use App\Http\Controllers\Controller;
use App\Services\FinancialPayoutService;
use App\Services\PayoutIdempotencyService;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Canonical provider-neutral payout surface.
 */
final class PayoutController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly FinancialPayoutService $payouts,
        private readonly PayoutProvider $provider,
        private readonly PayoutIdempotencyService $idempotency,
    ) {}

    public function summary(Request $request, int $organizationId)
    {
        $organization = $this->ownedOrganization($request, $organizationId);
        $overview = $this->payouts->overview($organization, $request->user());

        return response()->json([
            ...$overview,
            'provider' => $this->provider->name(),
            'current_settlement_mode' => 'platform_collection',
            'manual_payout_requests_enabled' => true,
            'platform_collection_enabled' => (bool) config("platform.applications.{$organization->app_slug}.commerce.allow_platform_collection", false),
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
        $data = $request->validate(['amount' => 'required|numeric|min:0.01|max:999999999.99']);
        $amount = round((float) $data['amount'], 2);
        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));

        if ($idempotencyKey === '' || strlen($idempotencyKey) < 8 || strlen($idempotencyKey) > 128) {
            return response()->json([
                'message' => 'Envie um Idempotency-Key estável (8 a 128 caracteres) para esta intenção de repasse.',
                'code' => 'payout_idempotency_key_required',
            ], 428);
        }

        $claim = $this->idempotency->claim($organization, $request->user(), $idempotencyKey, $amount);
        if ($claim['state'] === 'conflict') {
            return response()->json(['message' => 'Este Idempotency-Key já foi usado com dados diferentes.', 'code' => 'payout_idempotency_conflict'], 409);
        }
        if ($claim['state'] === 'processing') {
            return response()->json(['message' => 'Esta solicitação de repasse já está em processamento ou aguardando conciliação.', 'code' => 'payout_idempotency_in_progress'], 409);
        }
        if ($claim['state'] === 'replay' && $claim['payout_id']) {
            $replay = $this->idempotency->replay($organization, $claim['payout_id']);
            if ($replay) {
                $replay['balance'] = $this->payouts->balance($organization);
                return response()->json($replay, 200);
            }
            return response()->json(['message' => 'A solicitação idempotente existe, mas o repasse associado precisa de conciliação.', 'code' => 'payout_idempotency_reconciliation_required'], 409);
        }

        $overview = $this->payouts->overview($organization, $request->user());
        $eligible = (bool) ($overview['ready_for_payout'] ?? false)
            && $amount <= (float) data_get($overview, 'balance.available', 0) + 0.00001;

        if ($eligible) {
            if (! $this->provider->isConfigured()) {
                $this->idempotency->release($organization, $idempotencyKey);
                return response()->json(['message' => 'O serviço de repasses Pix ainda não está configurado para operação.'], 503);
            }
            try {
                if ($this->provider->availableBalance() + 0.00001 < $amount) {
                    $this->idempotency->release($organization, $idempotencyKey);
                    return response()->json(['message' => 'O repasse está temporariamente aguardando liquidação operacional. Tente novamente mais tarde.'], 503);
                }
            } catch (RuntimeException $exception) {
                report($exception);
                $this->idempotency->release($organization, $idempotencyKey);
                return response()->json(['message' => 'Não foi possível confirmar a disponibilidade operacional do repasse agora. Tente novamente.'], 503);
            }
        }

        try {
            $result = $this->payouts->requestPayout($organization, $request->user(), $amount);
            $payoutId = (int) data_get($result, 'payout.id', 0);
            if ($payoutId > 0) {
                $this->idempotency->complete($organization, $idempotencyKey, $payoutId);
            }
            return response()->json($result, 201);
        } catch (ValidationException $exception) {
            $this->idempotency->release($organization, $idempotencyKey);
            throw $exception;
        } catch (RuntimeException $exception) {
            report($exception);
            return response()->json(['message' => $exception->getMessage()], 502);
        }
    }

    public function cancel(Request $request, int $organizationId, int $payoutId)
    {
        $organization = $this->ownedOrganization($request, $organizationId);
        $this->payouts->rejectManualCancellation($organization, $payoutId);
    }

    private function ownedOrganization(Request $request, int $organizationId)
    {
        return $this->payouts->ownedProduction($this->context->id(), $organizationId, $request->user());
    }
}
