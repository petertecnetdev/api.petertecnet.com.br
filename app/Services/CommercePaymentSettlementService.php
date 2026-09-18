<?php

namespace App\Services;

use App\Models\CommerceOrder;
use App\Models\CommercePayment;
use App\Models\EventPass;
use App\Models\Interaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class CommercePaymentSettlementService
{
    public function __construct(private readonly EventAudienceService $audience) {}

    public function confirm(
        CommercePayment $payment,
        array $providerPayload = [],
        ?float $providerFee = null,
    ): CommerceOrder {
        $approvedOrderId = null;

        DB::transaction(function () use ($payment, $providerPayload, $providerFee, &$approvedOrderId) {
            $lockedPayment = CommercePayment::query()
                ->where('app_id', $payment->app_id)
                ->lockForUpdate()
                ->findOrFail($payment->id);

            $order = CommerceOrder::query()
                ->where('app_id', $payment->app_id)
                ->with(['items', 'event', 'user'])
                ->lockForUpdate()
                ->findOrFail($lockedPayment->order_id);

            if ($lockedPayment->status === 'paid' && $order->status === 'paid') {
                $approvedOrderId = (int) $order->id;
                return;
            }

            $remoteAmount = $this->remoteAmount($providerPayload);
            if ($remoteAmount !== null && abs($remoteAmount - (float) $order->total) > 0.009) {
                throw new RuntimeException('O valor confirmado pelo provedor é diferente do pedido.');
            }

            $externalReference = trim((string) ($providerPayload['externalReference'] ?? $providerPayload['external_reference'] ?? ''));
            if ($externalReference !== '' && $externalReference !== (string) $order->public_id) {
                throw new RuntimeException('A referência externa do pagamento é inválida.');
            }

            foreach ($order->items->where('type', 'ticket') as $line) {
                $alreadyIssued = EventPass::query()
                    ->where('commerce_order_item_id', $line->id)
                    ->count();

                $toIssue = max(0, (int) $line->quantity - $alreadyIssued);

                for ($i = 0; $i < $toIssue; $i++) {
                    EventPass::create([
                        'event_id' => $order->event_id,
                        'ticket_id' => $line->ticket_id,
                        'commerce_order_item_id' => $line->id,
                        'user_id' => $order->user_id,
                        'holder_name' => trim(($order->user->first_name ?? '') . ' ' . ($order->user->last_name ?? '')) ?: null,
                        'holder_email' => $order->user->email ?? null,
                        'token' => 'PASS-' . Str::upper(Str::replace('-', '', (string) Str::uuid())),
                        'status' => 'issued',
                    ]);
                }
            }

            foreach ($order->items->where('type', 'ticket') as $line) {
                $issued = EventPass::query()
                    ->where('commerce_order_item_id', $line->id)
                    ->count();

                if ($issued < (int) $line->quantity) {
                    throw new RuntimeException('Emissão incompleta para um item do pedido.');
                }
            }

            $metadata = $order->metadata ?? [];
            $metadata['fulfillment_status'] = 'completed';
            $metadata['fulfilled_at'] = now()->toIso8601String();
            $metadata['fulfillment_last_attempt_at'] = now()->toIso8601String();
            $metadata['settlement_mode'] = $metadata['settlement_mode'] ?? 'platform_collection';
            unset($metadata['fulfillment_error'], $metadata['fulfillment_failed_at']);

            $fee = $providerFee ?? (float) $lockedPayment->provider_fee;

            $lockedPayment->update([
                'status' => 'paid',
                'provider_fee' => round(max(0, $fee), 2),
                'provider_payload' => $providerPayload ?: $lockedPayment->provider_payload,
                'paid_at' => $lockedPayment->paid_at ?: now(),
                'failed_at' => null,
            ]);

            DB::table('commerce_orders')
                ->where('app_id', $order->app_id)
                ->where('id', $order->id)
                ->update([
                    'status' => 'paid',
                    'processor_fee' => round(max(0, $fee), 2),
                    'paid_at' => $order->paid_at ?: now(),
                    'cancelled_at' => null,
                    'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);

            DB::table('inventory_reservations')
                ->where('app_id', $order->app_id)
                ->where('order_id', $order->id)
                ->whereNull('released_at')
                ->update(['released_at' => now(), 'updated_at' => now()]);

            if (! DB::table('ledger_entries')
                ->where('app_id', $order->app_id)
                ->where('payment_id', $lockedPayment->id)
                ->where('type', 'gross_sale')
                ->exists()) {
                $settlementMode = (string) ($metadata['settlement_mode'] ?? 'platform_collection');
                $description = $settlementMode === 'automatic_split'
                    ? 'Crédito líquido da organização via split do provedor'
                    : 'Crédito líquido da organização a repassar pela plataforma';

                foreach ([
                    ['type' => 'gross_sale', 'amount' => $order->subtotal, 'description' => 'Venda aprovada pelo provedor'],
                    ['type' => 'platform_fee', 'amount' => -$order->platform_fee, 'description' => 'Comissão da plataforma'],
                    ['type' => 'producer_credit', 'amount' => $order->producer_net, 'description' => $description],
                ] as $entry) {
                    DB::table('ledger_entries')->insert(array_merge($entry, [
                        'app_id' => $order->app_id,
                        'production_id' => $order->production_id,
                        'order_id' => $order->id,
                        'payment_id' => $lockedPayment->id,
                        'status' => 'posted',
                        'metadata' => json_encode([
                            'provider' => $lockedPayment->provider,
                            'settlement_mode' => $settlementMode,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]));
                }
            }

            $approvedOrderId = (int) $order->id;
        }, 3);

        if ($approvedOrderId) {
            $this->audience->confirmPaidOrder($approvedOrderId);
            $this->recordPaidInteraction($approvedOrderId, (int) $payment->id);
        }

        return CommerceOrder::query()
            ->where('app_id', $payment->app_id)
            ->with(['items', 'event', 'payments'])
            ->findOrFail($payment->order_id);
    }

    public function markFailed(CommercePayment $payment, string $status = 'failed', array $providerPayload = []): CommerceOrder
    {
        DB::transaction(function () use ($payment, $status, $providerPayload) {
            $lockedPayment = CommercePayment::query()
                ->where('app_id', $payment->app_id)
                ->lockForUpdate()
                ->findOrFail($payment->id);

            $order = CommerceOrder::query()
                ->where('app_id', $payment->app_id)
                ->lockForUpdate()
                ->findOrFail($lockedPayment->order_id);

            if ($lockedPayment->status === 'paid' || $order->status === 'paid') {
                return;
            }

            $lockedPayment->update([
                'status' => $status,
                'provider_payload' => $providerPayload ?: $lockedPayment->provider_payload,
                'failed_at' => $lockedPayment->failed_at ?: now(),
            ]);

            $order->update([
                'status' => 'cancelled',
                'cancelled_at' => $order->cancelled_at ?: now(),
            ]);

            DB::table('inventory_reservations')
                ->where('app_id', $order->app_id)
                ->where('order_id', $order->id)
                ->whereNull('released_at')
                ->update(['released_at' => now(), 'updated_at' => now()]);
        }, 3);

        return CommerceOrder::query()
            ->where('app_id', $payment->app_id)
            ->with(['items', 'event', 'payments'])
            ->findOrFail($payment->order_id);
    }

    public function reverse(CommercePayment $payment, string $status, array $providerPayload = []): CommerceOrder
    {
        DB::transaction(function () use ($payment, $status, $providerPayload) {
            $lockedPayment = CommercePayment::query()
                ->where('app_id', $payment->app_id)
                ->lockForUpdate()
                ->findOrFail($payment->id);

            $order = CommerceOrder::query()
                ->where('app_id', $payment->app_id)
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($lockedPayment->order_id);

            if (in_array($lockedPayment->status, ['refunded', 'charged_back'], true)) {
                return;
            }

            $normalized = $status === 'charged_back' ? 'charged_back' : 'refunded';

            $lockedPayment->update([
                'status' => $normalized,
                'provider_payload' => $providerPayload ?: $lockedPayment->provider_payload,
                'refunded_at' => $lockedPayment->refunded_at ?: now(),
            ]);

            DB::table('commerce_orders')
                ->where('app_id', $order->app_id)
                ->where('id', $order->id)
                ->update(['status' => $normalized, 'updated_at' => now()]);

            $itemIds = $order->items->where('type', 'ticket')->pluck('id');
            EventPass::query()
                ->whereIn('commerce_order_item_id', $itemIds)
                ->whereIn('status', ['issued', 'active'])
                ->update([
                    'status' => $normalized,
                    'updated_at' => now(),
                ]);

            DB::table('ledger_entries')
                ->where('app_id', $order->app_id)
                ->where('payment_id', $lockedPayment->id)
                ->whereIn('type', ['gross_sale', 'platform_fee', 'producer_credit'])
                ->where('status', 'posted')
                ->update(['status' => 'reversed', 'updated_at' => now()]);

            if (! DB::table('ledger_entries')
                ->where('app_id', $order->app_id)
                ->where('payment_id', $lockedPayment->id)
                ->where('type', 'reversal')
                ->exists()) {
                DB::table('ledger_entries')->insert([
                    'app_id' => $order->app_id,
                    'production_id' => $order->production_id,
                    'order_id' => $order->id,
                    'payment_id' => $lockedPayment->id,
                    'type' => 'reversal',
                    'status' => 'posted',
                    'amount' => -(float) $order->subtotal,
                    'description' => $normalized === 'charged_back'
                        ? 'Reversão por contestação/chargeback'
                        : 'Reversão por reembolso',
                    'metadata' => json_encode([
                        'provider' => $lockedPayment->provider,
                        'remote_status' => $status,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }, 3);

        return CommerceOrder::query()
            ->where('app_id', $payment->app_id)
            ->with(['items', 'event', 'payments'])
            ->findOrFail($payment->order_id);
    }

    private function remoteAmount(array $payload): ?float
    {
        foreach (['value', 'transaction_amount', 'amount'] as $key) {
            if (array_key_exists($key, $payload) && is_numeric($payload[$key])) {
                return round((float) $payload[$key], 2);
            }
        }

        return null;
    }

    private function recordPaidInteraction(int $orderId, int $paymentId): void
    {
        try {
            $order = CommerceOrder::query()->find($orderId);
            $payment = CommercePayment::query()->find($paymentId);

            if (! $order || ! $payment || $order->status !== 'paid' || $payment->status !== 'paid') {
                return;
            }

            $alreadyRecorded = Interaction::query()
                ->where('app_id', $order->app_id)
                ->where('entity_type', 'CommerceOrder')
                ->where('entity_id', $order->id)
                ->where('interaction_type', 'payment_paid')
                ->exists();

            if ($alreadyRecorded) {
                return;
            }

            Interaction::register('payment_paid', $order, $order->user, [
                'source_channel' => 'payment_provider',
                'order_public_id' => $order->public_id,
                'payment_id' => $payment->id,
                'provider' => $payment->provider,
                'provider_payment_id' => $payment->provider_payment_id,
                'production_id' => $order->production_id,
                'payment_method' => $order->payment_method,
                'currency' => $order->currency,
                'amount' => (float) $order->total,
                'platform_fee' => (float) $order->platform_fee,
                'processor_fee' => (float) $order->processor_fee,
                'producer_net' => (float) $order->producer_net,
                'settlement_mode' => (string) data_get($order->metadata, 'settlement_mode', 'platform_collection'),
            ], 'Pagamento confirmado');
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
