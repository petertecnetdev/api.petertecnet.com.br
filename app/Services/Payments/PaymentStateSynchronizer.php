<?php

namespace App\Services\Payments;

use App\Data\Payments\PaymentProviderResult;
use App\Models\EcosystemPayment;
use App\Models\Order;
use App\Services\Commerce\CommerceFulfillmentService;
use Illuminate\Support\Facades\DB;

class PaymentStateSynchronizer
{
    public function __construct(
        private readonly CommerceFulfillmentService $fulfillment,
    ) {}

    public function apply(EcosystemPayment $payment, PaymentProviderResult $result): void
    {
        DB::transaction(function () use ($payment, $result) {
            $metadata = array_filter([
                'remote_status' => $result->providerStatus,
                ...$result->metadata,
            ], static fn ($value) => $value !== null);

            $status = strtolower($result->status);
            $isPaid = in_array($status, ['paid', 'approved'], true);
            $isReversed = in_array($status, ['refunded', 'charged_back'], true);
            $isFailed = in_array($status, ['failed', 'rejected', 'cancelled', 'expired'], true);

            $payment->forceFill([
                'provider_payment_id' => $result->providerPaymentId ?: $payment->provider_payment_id,
                'status' => $status,
                'provider_fee' => $result->providerFee,
                'seller_net' => max(0, (float) $payment->gross_amount - $result->providerFee - (float) $payment->platform_fee),
                'paid_at' => $isPaid ? ($payment->paid_at ?: now()) : $payment->paid_at,
                'refunded_at' => $isReversed ? ($payment->refunded_at ?: now()) : $payment->refunded_at,
                'failed_at' => $isFailed ? ($payment->failed_at ?: now()) : $payment->failed_at,
                'expires_at' => $payment->expires_at ?: $result->expiresAt,
                'available_at' => $payment->available_at ?: $result->availableAt,
                'metadata' => array_merge($payment->metadata ?? [], $metadata),
            ])->save();

            if ($payment->source_type !== 'order' || ! $payment->source_id) {
                return;
            }

            $order = Order::query()
                ->whereKey($payment->source_id)
                ->where('app_id', $payment->app_id)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                return;
            }

            $previousFulfillment = strtolower((string) $order->fulfillment_status);
            $nextFulfillment = $previousFulfillment;

            if ($isPaid) {
                $preserved = [
                    CommerceFulfillmentService::PREPARING,
                    CommerceFulfillmentService::READY,
                    CommerceFulfillmentService::LEGACY_READY,
                    CommerceFulfillmentService::FULFILLED,
                    CommerceFulfillmentService::DELIVERED,
                    CommerceFulfillmentService::BLOCKED,
                ];

                if (! in_array($previousFulfillment, $preserved, true)) {
                    $nextFulfillment = CommerceFulfillmentService::PREPARING;
                }
            } elseif ($isReversed) {
                $nextFulfillment = CommerceFulfillmentService::BLOCKED;
            }

            $nextOrderStatus = $order->status;
            if ($isPaid && $nextFulfillment === CommerceFulfillmentService::PREPARING
                && in_array($order->status, ['pending', 'confirmed'], true)) {
                $nextOrderStatus = 'preparing';
            }

            $orderPaymentStatus = $isPaid
                ? 'paid'
                : ($isReversed ? 'refunded' : $status);

            $order->forceFill([
                'payment_status' => $orderPaymentStatus,
                'payment_reference' => $payment->provider_payment_id,
                'fulfillment_status' => $nextFulfillment ?: $order->fulfillment_status,
                'status' => $nextOrderStatus,
                'status_updated_at' => now(),
            ])->save();

            if ($nextFulfillment !== $previousFulfillment && $nextFulfillment !== '') {
                $this->fulfillment->record(
                    $order,
                    $isReversed ? 'payment_reversed' : 'payment_confirmed',
                    null,
                    $previousFulfillment ?: null,
                    $nextFulfillment,
                    'payment_provider',
                    'success',
                    [
                        'metadata' => [
                            'provider' => $payment->provider,
                            'payment_public_id' => $payment->public_id,
                            'payment_status' => $orderPaymentStatus,
                            'provider_status' => $status,
                        ],
                    ]
                );
            }
        });
    }
}
