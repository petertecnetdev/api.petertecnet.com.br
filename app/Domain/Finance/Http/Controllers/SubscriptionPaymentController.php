<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\SubscriptionIntent;
use App\Domain\Finance\Services\SubscriptionBillingService;
use App\Domain\Finance\Services\SubscriptionPaymentLocator;
use App\Http\Controllers\Controller;
use App\Services\MercadoPagoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

final class SubscriptionPaymentController extends Controller
{
    public function __construct(
        private readonly SubscriptionBillingService $billing,
        private readonly SubscriptionPaymentLocator $payments,
        private readonly MercadoPagoService $mercadoPago,
    ) {}

    public function checkout(Request $request, string $application, string $intent): JsonResponse
    {
        $application = strtolower(trim($application));
        $record = SubscriptionIntent::query()
            ->where('public_id', $intent)
            ->where('application', $application)
            ->where('user_id', $request->user()->getKey())
            ->firstOrFail();

        if ($record->status === 'active') {
            return response()->json($this->billing->status($record->public_id, (int) $record->user_id, $application));
        }

        $request->validate(['method' => ['required', 'string', 'in:pix']]);
        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 120) {
            return response()->json(['message' => 'Envie um Idempotency-Key válido para iniciar o checkout.'], 422);
        }

        try {
            return response()->json($this->billing->createPixCheckout($record, $idempotencyKey), 201);
        } catch (RuntimeException $exception) {
            report($exception);
            return response()->json(['message' => $exception->getMessage()], 503);
        }
    }

    public function sync(Request $request, string $application, string $intent): JsonResponse
    {
        $application = strtolower(trim($application));
        $record = SubscriptionIntent::query()
            ->where('public_id', $intent)
            ->where('application', $application)
            ->where('user_id', $request->user()->getKey())
            ->firstOrFail();

        $providerPaymentId = $this->payments->providerPaymentId($record);
        if ($providerPaymentId) {
            try {
                $this->billing->reconcileProviderPayment($providerPaymentId);
            } catch (Throwable $exception) {
                report($exception);
                return response()->json([
                    ...$this->billing->status($record->public_id, (int) $record->user_id, $application),
                    'reconciliation' => 'retrying',
                ], 202);
            }
        }

        return response()->json([
            ...$this->billing->status($record->public_id, (int) $record->user_id, $application),
            'reconciliation' => $providerPaymentId ? 'ok' : 'no_payment',
        ]);
    }

    public function webhook(Request $request): JsonResponse
    {
        $dataId = (string) ($request->query('data.id') ?: data_get($request->all(), 'data.id', ''));
        if ($dataId === '') {
            return response()->json(['ok' => true]);
        }

        abort_unless(
            $this->mercadoPago->validateWebhookSignature(
                $request->header('x-signature'),
                $request->header('x-request-id'),
                $dataId,
            ),
            401,
            'Assinatura inválida.'
        );

        $type = (string) ($request->input('type') ?: $request->query('type', 'payment'));
        if ($type !== 'payment') {
            return response()->json(['ok' => true]);
        }

        try {
            $result = $this->billing->reconcileProviderPayment($dataId);
        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['ok' => false, 'retry' => true], 502);
        }

        return response()->json(['ok' => true, 'matched' => $result !== null]);
    }
}
