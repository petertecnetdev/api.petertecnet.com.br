<?php

namespace App\Services\Commerce;

use App\Data\Payments\PaymentIntent;
use App\Models\EcosystemPayment;
use App\Models\Establishment;
use App\Models\Order;
use App\Models\User;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentStateSynchronizer;
use App\Support\ApplicationContext;
use Illuminate\Support\Str;

class CommercePaymentService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly PaymentGatewayManager $gateways,
        private readonly PaymentStateSynchronizer $synchronizer,
    ) {}

    public function create(Order $order, Establishment $establishment, User $user, string $method): array
    {
        $gateway = $this->gateways->default();
        abort_unless($gateway->isConfigured(), 503, 'Provedor de pagamento não configurado.');
        abort_unless(in_array($method, $gateway->supportedMethods(), true), 422, 'Forma de pagamento indisponível.');

        $publicId = (string) Str::uuid();
        $reference = 'commerce-order-' . $order->public_id . '-' . $publicId;

        $payment = EcosystemPayment::query()->create([
            'public_id' => $publicId,
            'app_id' => $this->context->id(),
            'app_slug' => $this->context->slug(),
            'provider' => $gateway->name(),
            'source_type' => 'order',
            'source_reference' => $reference,
            'source_id' => $order->id,
            'user_id' => $user->id,
            'establishment_id' => $establishment->id,
            'currency' => 'BRL',
            'method' => $method,
            'status' => 'pending',
            'gross_amount' => $order->total_price,
            'platform_fee' => 0,
            'provider_fee' => 0,
            'seller_net' => $order->total_price,
            'metadata' => [
                'order_public_id' => $order->public_id,
                'order_number' => $order->order_number,
            ],
        ]);

        try {
            $intent = new PaymentIntent(
                method: $method,
                amount: (float) $order->total_price,
                currency: 'BRL',
                description: 'Compra #' . $order->order_number,
                externalReference: $reference,
                notificationUrl: $this->notificationUrl($gateway->name()),
                returnUrl: rtrim((string) $this->context->application()->url, '/') . '/purchase/' . $order->public_id,
                idempotencyKey: 'commerce-' . $method . '-' . $order->public_id . '-' . $payment->public_id,
                payerEmail: (string) $user->email,
                payerFirstName: (string) ($user->first_name ?: $order->customer_name),
                payerLastName: (string) ($user->last_name ?: ''),
                items: $this->checkoutItems($order),
                metadata: [
                    'app_slug' => $this->context->slug(),
                    'order_public_id' => $order->public_id,
                ],
            );

            $result = $gateway->initiate($intent);
            $this->synchronizer->apply($payment, $result);

            if ($result->providerPaymentId) {
                $order->forceFill(['payment_reference' => $result->providerPaymentId])->save();
            }

            return $this->serialize($payment->fresh());
        } catch (\Throwable $exception) {
            report($exception);

            $payment->forceFill([
                'status' => 'failed',
                'failed_at' => $payment->failed_at ?: now(),
                'metadata' => array_merge($payment->metadata ?? [], ['error' => $exception->getMessage()]),
            ])->save();

            $order->forceFill(['payment_status' => 'failed'])->save();

            return array_merge($this->serialize($payment), [
                'retryable' => true,
                'message' => 'Não foi possível iniciar o pagamento. Tente novamente.',
            ]);
        }
    }

    public function sync(EcosystemPayment $payment): void
    {
        $gateway = $this->gateways->for($payment->provider);
        $result = $gateway->retrieve($payment->provider_payment_id, $payment->source_reference);

        if ($result) {
            $this->synchronizer->apply($payment, $result);
        }
    }

    public function handleWebhook(string $provider, string $resourceId, array $headers): void
    {
        $gateway = $this->gateways->for($provider);
        abort_unless($gateway->validateWebhook($headers, $resourceId), 401, 'Assinatura de webhook inválida.');

        $result = $gateway->retrieveById($resourceId);

        $payment = EcosystemPayment::query()
            ->where('app_id', $this->context->id())
            ->where('provider', $gateway->name())
            ->where(function ($query) use ($result, $resourceId) {
                $query->where('provider_payment_id', $result->providerPaymentId ?: $resourceId);

                if ($result->externalReference) {
                    $query->orWhere('source_reference', $result->externalReference);
                }
            })
            ->latest('id')
            ->first();

        if ($payment) {
            $this->synchronizer->apply($payment, $result);
        }
    }

    public function serialize(EcosystemPayment $payment): array
    {
        $metadata = is_array($payment->metadata) ? $payment->metadata : [];

        return [
            'public_id' => $payment->public_id,
            'provider' => $payment->provider,
            'method' => $payment->method,
            'status' => $payment->status,
            'amount' => (float) $payment->gross_amount,
            'paid_at' => optional($payment->paid_at)->toIso8601String(),
            'available_at' => optional($payment->available_at)->toIso8601String(),
            'expires_at' => optional($payment->expires_at)->toIso8601String(),
            'qr_code' => $metadata['qr_code'] ?? null,
            'qr_code_base64' => $metadata['qr_code_base64'] ?? null,
            'ticket_url' => $metadata['ticket_url'] ?? null,
            'checkout_url' => $metadata['checkout_url'] ?? null,
            'sandbox_checkout_url' => $metadata['sandbox_checkout_url'] ?? null,
        ];
    }

    private function checkoutItems(Order $order): array
    {
        $items = $order->items->map(fn ($line) => [
            'id' => (string) $line->item_id,
            'title' => $line->item?->name ?: 'Item',
            'quantity' => (int) $line->quantity,
            'currency_id' => 'BRL',
            'unit_price' => (float) $line->unit_price,
        ])->values()->all();

        if ((float) $order->delivery_fee > 0) {
            $items[] = [
                'id' => 'delivery-fee',
                'title' => 'Taxa de entrega',
                'quantity' => 1,
                'currency_id' => 'BRL',
                'unit_price' => (float) $order->delivery_fee,
            ];
        }

        return $items;
    }

    private function notificationUrl(string $provider): string
    {
        return rtrim((string) config('app.url'), '/')
            . '/api/v1/apps/'
            . rawurlencode($this->context->slug())
            . '/commerce/payments/'
            . rawurlencode($provider)
            . '/webhook';
    }
}
