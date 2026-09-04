<?php

namespace App\Domain\Commerce\Services;

use App\Models\CommerceOrder;
use App\Models\CommercePayment;
use App\Models\CommerceRefund;
use App\Models\Event;
use App\Models\EventPass;
use App\Models\User;
use App\Services\AppNotificationService;
use App\Services\MerchantPaymentAccountService;
use App\Services\MercadoPagoService;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class CommerceRefundService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly MercadoPagoService $mercadoPago,
        private readonly MerchantPaymentAccountService $accounts,
        private readonly AppNotificationService $notifications,
    ) {}

    public function refundEvent(Event $event, ?User $actor, string $reason): array
    {
        $orders = CommerceOrder::query()
            ->where('app_id', $this->context->id())
            ->where('event_id', $event->id)
            ->whereIn('status', ['paid', 'refund_pending'])
            ->with(['payments', 'items', 'user'])
            ->orderBy('id')
            ->get();

        $summary = ['eligible' => $orders->count(), 'completed' => 0, 'pending' => 0, 'failed' => 0];

        foreach ($orders as $order) {
            $refund = $this->requestFullRefund(
                $order,
                $actor,
                'event_cancelled',
                (int) $event->id,
                $reason
            );

            if ($refund->status === 'completed') $summary['completed']++;
            elseif ($refund->status === 'failed') $summary['failed']++;
            else $summary['pending']++;
        }

        return $summary;
    }

    public function requestFullRefund(
        CommerceOrder $order,
        ?User $requestedBy,
        string $sourceType,
        ?int $sourceId,
        string $reason
    ): CommerceRefund {
        abort_unless((int) $order->app_id === $this->context->id(), 404, 'Pedido não encontrado neste contexto.');
        abort_if((float) $order->total <= 0, 422, 'Este pedido não possui valor pago para reembolso.');

        $payment = $order->payments()
            ->where('app_id', $this->context->id())
            ->whereNotNull('provider_payment_id')
            ->whereNotIn('status', ['rejected', 'cancelled'])
            ->latest('id')
            ->first();

        abort_unless($payment, 422, 'Não há pagamento confirmado que possa ser reembolsado automaticamente.');

        $idempotencyKey = hash('sha256', implode('|', [
            'commerce-full-refund',
            $this->context->id(),
            $order->id,
            $payment->id,
        ]));

        // Older provider/webhook flows may already have marked an order/payment
        // as refunded before commerce_refunds existed. Materialize the audit row
        // instead of returning a 404 so old transactions remain traceable.
        if ($order->status === 'refunded' || $payment->status === 'refunded') {
            return CommerceRefund::query()->firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'app_id' => $this->context->id(),
                    'order_id' => $order->id,
                    'payment_id' => $payment->id,
                    'requested_by_user_id' => $requestedBy?->id,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'status' => 'completed',
                    'amount' => $order->total,
                    'currency' => $order->currency ?: 'BRL',
                    'provider' => $payment->provider,
                    'reason' => $reason,
                    'provider_payload' => ['reconciled_existing_refund' => true],
                    'requested_at' => $payment->refunded_at ?: now(),
                    'processed_at' => $payment->refunded_at ?: now(),
                ]
            );
        }

        abort_unless(in_array($order->status, ['paid', 'refund_pending'], true), 422, 'Somente pedidos pagos podem ser reembolsados.');

        $refund = CommerceRefund::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'app_id' => $this->context->id(),
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'requested_by_user_id' => $requestedBy?->id,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'status' => 'pending',
                'amount' => $order->total,
                'currency' => $order->currency ?: 'BRL',
                'provider' => $payment->provider,
                'reason' => $reason,
                'requested_at' => now(),
            ]
        );

        if ($refund->status === 'completed') return $refund;

        if ($refund->status === 'failed') {
            $refund->forceFill([
                'status' => 'pending',
                'failed_at' => null,
                'failure_message' => null,
                'requested_by_user_id' => $requestedBy?->id ?: $refund->requested_by_user_id,
                'reason' => $reason ?: $refund->reason,
            ])->save();
        }

        DB::table('commerce_orders')
            ->where('app_id', $this->context->id())
            ->where('id', $order->id)
            ->where('status', 'paid')
            ->update(['status' => 'refund_pending', 'updated_at' => now()]);

        return $this->process($refund->fresh());
    }

    public function process(CommerceRefund $refund): CommerceRefund
    {
        if ($refund->status === 'completed') return $refund;

        $order = CommerceOrder::query()
            ->where('app_id', $this->context->id())
            ->with(['payments', 'items', 'user', 'event'])
            ->findOrFail($refund->order_id);

        $payment = CommercePayment::query()
            ->where('app_id', $this->context->id())
            ->where('order_id', $order->id)
            ->findOrFail($refund->payment_id);

        if ($order->status === 'refunded' || $payment->status === 'refunded') {
            return $this->finalizeLocalRefund($refund, $order, $payment, $refund->provider_payload ?? []);
        }

        try {
            $refund->forceFill(['status' => 'processing', 'failure_message' => null, 'failed_at' => null])->save();
            $token = $this->paymentAccessToken($payment, $order);
            $remote = $this->mercadoPago->refundPayment(
                $token,
                (string) $payment->provider_payment_id,
                (float) $refund->amount,
                (string) $refund->idempotency_key
            );

            return $this->finalizeLocalRefund($refund, $order, $payment, $remote);
        } catch (Throwable $e) {
            report($e);
            $refund->forceFill([
                'status' => 'failed',
                'failed_at' => now(),
                'failure_message' => mb_substr($e->getMessage(), 0, 4000),
            ])->save();

            if ($order->user_id) {
                $this->notifications->sendToUser((int) $order->app_id, (int) $order->user_id, [
                    'type' => 'commerce.refund_attention',
                    'title' => 'Reembolso em análise',
                    'message' => 'Seu reembolso foi registrado, mas precisa de uma nova tentativa do processamento financeiro. Seu pedido continua protegido.',
                    'reference_type' => 'order',
                    'reference_id' => $order->id,
                    'reference_url' => '/passes',
                    'data' => ['order_id' => $order->id, 'refund_id' => $refund->id],
                ]);
            }

            return $refund->fresh();
        }
    }

    private function finalizeLocalRefund(CommerceRefund $refund, CommerceOrder $order, CommercePayment $payment, array $remote): CommerceRefund
    {
        DB::transaction(function () use ($refund, $order, $payment, $remote) {
            $lockedRefund = CommerceRefund::query()->lockForUpdate()->findOrFail($refund->id);
            $lockedPayment = CommercePayment::query()->where('app_id', $this->context->id())->lockForUpdate()->findOrFail($payment->id);
            $lockedOrder = CommerceOrder::query()->where('app_id', $this->context->id())->with('items')->lockForUpdate()->findOrFail($order->id);

            $lockedRefund->forceFill([
                'status' => 'completed',
                'provider_refund_id' => isset($remote['id']) ? (string) $remote['id'] : $lockedRefund->provider_refund_id,
                'provider_payload' => $remote ?: $lockedRefund->provider_payload,
                'processed_at' => $lockedRefund->processed_at ?: now(),
                'failed_at' => null,
                'failure_message' => null,
            ])->save();

            $lockedPayment->forceFill([
                'status' => 'refunded',
                'refunded_at' => $lockedPayment->refunded_at ?: now(),
            ])->save();

            DB::table('commerce_orders')
                ->where('app_id', $this->context->id())
                ->where('id', $lockedOrder->id)
                ->update(['status' => 'refunded', 'updated_at' => now()]);

            $ticketLineIds = $lockedOrder->items->where('type', 'ticket')->pluck('id');
            if ($ticketLineIds->isNotEmpty()) {
                EventPass::query()
                    ->whereIn('commerce_order_item_id', $ticketLineIds)
                    ->whereNotIn('status', ['refunded', 'charged_back'])
                    ->update(['status' => 'refunded', 'updated_at' => now()]);
            }

            DB::table('ledger_entries')
                ->where('app_id', $this->context->id())
                ->where('payment_id', $lockedPayment->id)
                ->whereIn('type', ['gross_sale', 'platform_fee', 'producer_credit'])
                ->where('status', 'posted')
                ->update(['status' => 'reversed', 'updated_at' => now()]);

            if (! DB::table('ledger_entries')
                ->where('app_id', $this->context->id())
                ->where('payment_id', $lockedPayment->id)
                ->where('type', 'reversal')
                ->exists()) {
                DB::table('ledger_entries')->insert([
                    'app_id' => $this->context->id(),
                    'production_id' => $lockedOrder->production_id,
                    'order_id' => $lockedOrder->id,
                    'payment_id' => $lockedPayment->id,
                    'type' => 'reversal',
                    'status' => 'posted',
                    'amount' => -(float) $lockedOrder->subtotal,
                    'description' => 'Reversão por reembolso',
                    'metadata' => json_encode(['provider' => $lockedPayment->provider, 'refund_id' => $lockedRefund->id]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }, 3);

        if ($order->user_id) {
            $this->notifications->sendToUser((int) $order->app_id, (int) $order->user_id, [
                'type' => 'commerce.refund_completed',
                'title' => 'Reembolso concluído',
                'message' => 'O reembolso de R$ '.number_format((float) $refund->amount, 2, ',', '.').' foi concluído. O ingresso correspondente não é mais válido para entrada.',
                'reference_type' => 'order',
                'reference_id' => $order->id,
                'reference_url' => '/passes',
                'data' => ['order_id' => $order->id, 'refund_id' => $refund->id, 'amount' => (float) $refund->amount],
            ]);
        }

        return $refund->fresh();
    }

    private function paymentAccessToken(CommercePayment $payment, CommerceOrder $order): string
    {
        if ($payment->provider !== 'mercadopago') {
            throw new RuntimeException('Este provedor ainda não possui processamento automático de reembolso.');
        }

        $mode = (string) data_get(
            $order->metadata,
            'settlement_mode',
            data_get($payment->provider_payload, 'metadata.settlement_mode', 'automatic_split')
        );

        if (in_array($mode, ['platform_collection', 'same_account'], true)) {
            $token = trim((string) config('services.mercadopago.access_token'));
            if ($token === '') throw new RuntimeException('Token do provedor da plataforma não configurado.');
            return $token;
        }

        return $this->accounts->accessTokenForOrganization((int) $order->production_id)[1];
    }
}
