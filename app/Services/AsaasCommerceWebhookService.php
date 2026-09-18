<?php

namespace App\Services;

use App\Models\CommerceOrder;
use App\Models\CommercePayment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AsaasCommerceWebhookService
{
    public function __construct(
        private readonly AsaasPaymentService $asaas,
        private readonly CommercePaymentSettlementService $settlement,
    ) {}

    public function process(array $payload): void
    {
        $eventId = trim((string) ($payload['id'] ?? ''));
        $eventType = strtoupper(trim((string) ($payload['event'] ?? '')));

        if ($eventId === '' || $eventType === '') {
            return;
        }

        if (str_starts_with($eventType, 'PAYMENT_')) {
            $this->processPaymentEvent($eventId, $eventType, $payload);
            return;
        }

        if (str_starts_with($eventType, 'CHECKOUT_')) {
            $this->processCheckoutEvent($eventId, $eventType, $payload);
            return;
        }

        $this->auditAndFinish($eventId, $eventType, $this->safeUnknownPayload($payload));
    }

    public function reconcile(CommercePayment $payment, array $remote): CommerceOrder
    {
        if ($payment->provider !== 'asaas') {
            throw new RuntimeException('Pagamento não pertence ao Asaas.');
        }

        $providerId = trim((string) ($remote['id'] ?? ''));
        if ($providerId !== '' && $payment->provider_payment_id && $providerId !== (string) $payment->provider_payment_id) {
            throw new RuntimeException('A cobrança retornada pelo Asaas não corresponde ao pagamento local.');
        }

        $this->applyRemoteState($payment, $remote);

        return CommerceOrder::query()
            ->where('app_id', $payment->app_id)
            ->with(['items', 'event', 'payments'])
            ->findOrFail($payment->order_id);
    }

    private function processPaymentEvent(string $eventId, string $eventType, array $payload): void
    {
        $remote = is_array($payload['payment'] ?? null) ? $payload['payment'] : [];
        $providerPaymentId = trim((string) ($remote['id'] ?? ''));
        $externalReference = trim((string) ($remote['externalReference'] ?? ''));

        $safe = [
            'id' => $eventId,
            'event' => $eventType,
            'payment' => [
                'id' => $providerPaymentId,
                'status' => $remote['status'] ?? null,
                'billingType' => $remote['billingType'] ?? null,
                'value' => $remote['value'] ?? null,
                'netValue' => $remote['netValue'] ?? null,
                'externalReference' => $externalReference,
                'invoiceUrl' => $remote['invoiceUrl'] ?? null,
                'bankSlipUrl' => $remote['bankSlipUrl'] ?? null,
            ],
        ];

        if (! $this->startReceipt($eventId, $eventType, $safe)) {
            return;
        }

        $payment = $this->findPayment($providerPaymentId, $externalReference);

        if (! $payment) {
            $this->finishReceipt($eventId);
            return;
        }

        if ($providerPaymentId !== '' && ! $payment->provider_payment_id) {
            $payment->update(['provider_payment_id' => $providerPaymentId]);
            $payment->refresh();
        }

        $this->applyRemoteState($payment, $remote, $eventType);
        $this->finishReceipt($eventId);
    }

    private function processCheckoutEvent(string $eventId, string $eventType, array $payload): void
    {
        $checkout = is_array($payload['checkout'] ?? null) ? $payload['checkout'] : [];
        $checkoutId = trim((string) ($checkout['id'] ?? ''));
        $externalReference = trim((string) ($checkout['externalReference'] ?? ''));
        $value = collect((array) ($checkout['items'] ?? []))
            ->sum(fn ($item) => (float) ($item['value'] ?? 0) * (int) ($item['quantity'] ?? 1));

        $safe = [
            'id' => $eventId,
            'event' => $eventType,
            'checkout' => [
                'id' => $checkoutId,
                'status' => $checkout['status'] ?? null,
                'externalReference' => $externalReference,
                'billingTypes' => $checkout['billingTypes'] ?? [],
                'chargeTypes' => $checkout['chargeTypes'] ?? [],
                'value' => round($value, 2),
            ],
        ];

        if (! $this->startReceipt($eventId, $eventType, $safe)) {
            return;
        }

        $payment = CommercePayment::query()
            ->where('provider', 'asaas')
            ->when($checkoutId !== '', fn ($query) => $query->where('provider_txid', $checkoutId))
            ->latest('id')
            ->first();

        if (! $payment && $externalReference !== '') {
            $payment = $this->findPayment('', $externalReference);
        }

        if (! $payment) {
            $this->finishReceipt($eventId);
            return;
        }

        if ($eventType === 'CHECKOUT_PAID') {
            $this->settlement->confirm($payment, [
                'id' => $checkoutId,
                'value' => round($value, 2),
                'externalReference' => $externalReference,
                'status' => $checkout['status'] ?? 'PAID',
            ]);
        } elseif (in_array($eventType, ['CHECKOUT_CANCELED', 'CHECKOUT_EXPIRED'], true)) {
            $this->settlement->markFailed(
                $payment,
                $eventType === 'CHECKOUT_EXPIRED' ? 'expired' : 'cancelled',
                $safe['checkout']
            );
        } else {
            $payment->update([
                'status' => 'pending',
                'provider_payload' => $safe['checkout'],
            ]);
        }

        $this->finishReceipt($eventId);
    }

    private function applyRemoteState(CommercePayment $payment, array $remote, ?string $eventType = null): void
    {
        $localStatus = $this->asaas->normalizeLocalStatus($remote);
        $value = is_numeric($remote['value'] ?? null) ? (float) $remote['value'] : null;
        $netValue = is_numeric($remote['netValue'] ?? null) ? (float) $remote['netValue'] : null;
        $providerFee = $value !== null && $netValue !== null
            ? max(0, round($value - $netValue, 2))
            : (float) $payment->provider_fee;

        if ($eventType === 'PAYMENT_PARTIALLY_REFUNDED') {
            $payment->update([
                'status' => 'partially_refunded',
                'provider_fee' => $providerFee,
                'provider_payload' => $remote,
            ]);
            return;
        }

        if ($localStatus === 'paid') {
            $this->settlement->confirm($payment, $remote, $providerFee);
            return;
        }

        if (in_array($localStatus, ['refunded', 'charged_back'], true)) {
            if ($eventType === 'PAYMENT_REFUND_IN_PROGRESS') {
                $payment->update([
                    'status' => 'refund_in_progress',
                    'provider_payload' => $remote,
                ]);
                return;
            }

            $this->settlement->reverse($payment, $localStatus, $remote);
            return;
        }

        if ($localStatus === 'failed') {
            $this->settlement->markFailed($payment, strtolower((string) ($remote['status'] ?? 'failed')), $remote);
            return;
        }

        $status = strtolower(trim((string) ($remote['status'] ?? 'pending')));
        $payment->update([
            'status' => $status !== '' ? $status : 'pending',
            'provider_fee' => $providerFee,
            'provider_payload' => $remote,
        ]);
    }

    private function findPayment(string $providerPaymentId, string $externalReference): ?CommercePayment
    {
        if ($providerPaymentId !== '') {
            $payment = CommercePayment::query()
                ->where('provider', 'asaas')
                ->where('provider_payment_id', $providerPaymentId)
                ->latest('id')
                ->first();

            if ($payment) {
                return $payment;
            }
        }

        if ($externalReference === '') {
            return null;
        }

        $order = CommerceOrder::query()
            ->where('public_id', $externalReference)
            ->first();

        if (! $order) {
            return null;
        }

        return CommercePayment::query()
            ->where('app_id', $order->app_id)
            ->where('order_id', $order->id)
            ->where('provider', 'asaas')
            ->latest('id')
            ->first();
    }

    private function startReceipt(string $eventId, string $eventType, array $safePayload): bool
    {
        DB::table('financial_webhook_events')->insertOrIgnore([
            'provider' => 'asaas',
            'event_id' => $eventId,
            'event_type' => $eventType,
            'payload' => json_encode($safePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $receipt = DB::table('financial_webhook_events')
            ->where('provider', 'asaas')
            ->where('event_id', $eventId)
            ->first();

        return (bool) ($receipt && ! $receipt->processed_at);
    }

    private function finishReceipt(string $eventId): void
    {
        DB::table('financial_webhook_events')
            ->where('provider', 'asaas')
            ->where('event_id', $eventId)
            ->update(['processed_at' => now(), 'updated_at' => now()]);
    }

    private function auditAndFinish(string $eventId, string $eventType, array $safePayload): void
    {
        if (! $this->startReceipt($eventId, $eventType, $safePayload)) {
            return;
        }

        $this->finishReceipt($eventId);
    }

    private function safeUnknownPayload(array $payload): array
    {
        $safe = [
            'id' => $payload['id'] ?? null,
            'event' => $payload['event'] ?? null,
            'dateCreated' => $payload['dateCreated'] ?? null,
        ];

        foreach (['transfer', 'payment', 'checkout', 'subscription', 'bill', 'pixTransaction'] as $resource) {
            $data = is_array($payload[$resource] ?? null) ? $payload[$resource] : null;
            if (! $data) {
                continue;
            }

            $safe[$resource] = array_intersect_key($data, array_flip([
                'id', 'status', 'externalReference', 'value', 'netValue', 'billingType',
            ]));
        }

        return $safe;
    }
}
