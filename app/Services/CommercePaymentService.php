<?php

namespace App\Services;

use App\Models\EcosystemPayment;
use App\Models\Establishment;
use App\Models\Order;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CommercePaymentService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly MercadoPagoService $mercadoPago,
    ) {}

    public function create(Order $order, Establishment $establishment, $user, string $method): array
    {
        $token = $this->providerToken();
        $publicId = (string) Str::uuid();
        $reference = 'commerce-order-' . $order->public_id . '-' . $publicId;

        $payment = EcosystemPayment::query()->create([
            'public_id' => $publicId,
            'app_id' => $this->context->id(),
            'app_slug' => $this->context->slug(),
            'provider' => 'mercadopago',
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
            if ($method === 'pix') {
                return $this->createPix($payment, $order, $user, $token, $reference);
            }

            if ($method === 'card') {
                return $this->createCardCheckout($payment, $order, $user, $token, $reference);
            }

            abort(422, 'Forma de pagamento não suportada pelo comércio online.');
        } catch (\Throwable $exception) {
            report($exception);
            $payment->forceFill([
                'status' => 'failed',
                'failed_at' => now(),
                'metadata' => array_merge($payment->metadata ?? [], ['error' => $exception->getMessage()]),
            ])->save();
            $order->forceFill(['payment_status' => 'failed'])->save();

            return array_merge($this->serialize($payment), [
                'retryable' => true,
                'message' => 'Não foi possível iniciar o pagamento. Tente novamente.',
            ]);
        }
    }

    public function latestForOrder(Order $order): ?EcosystemPayment
    {
        return EcosystemPayment::query()
            ->where('app_id', $this->context->id())
            ->where('source_type', 'order')
            ->where('source_id', $order->id)
            ->latest('id')
            ->first();
    }

    public function sync(EcosystemPayment $payment): ?array
    {
        $remote = $payment->provider_payment_id
            ? $this->mercadoPago->getPayment($this->providerToken(), $payment->provider_payment_id)
            : $this->mercadoPago->findPaymentByExternalReference($this->providerToken(), $payment->source_reference);

        if ($remote) {
            $this->applyRemotePayment($payment, $remote);
        }

        return $remote;
    }

    public function remote(EcosystemPayment $payment): ?array
    {
        if ($payment->provider_payment_id) {
            return $this->mercadoPago->getPayment($this->providerToken(), $payment->provider_payment_id);
        }

        return $this->mercadoPago->findPaymentByExternalReference($this->providerToken(), $payment->source_reference);
    }

    public function handleMercadoPagoWebhook(Request $request): void
    {
        $dataId = (string) ($request->input('data.id') ?: $request->query('data_id') ?: '');
        if ($dataId === '') return;

        abort_unless($this->mercadoPago->validateWebhookSignature(
            $request->header('x-signature'),
            $request->header('x-request-id'),
            $dataId,
        ), 401, 'Assinatura de webhook inválida.');

        $remote = $this->mercadoPago->getPayment($this->providerToken(), $dataId);
        $payment = EcosystemPayment::query()
            ->where('app_id', $this->context->id())
            ->where('provider', 'mercadopago')
            ->where(function ($query) use ($remote, $dataId) {
                $query->where('provider_payment_id', (string) ($remote['id'] ?? $dataId));
                if (! empty($remote['external_reference'])) {
                    $query->orWhere('source_reference', (string) $remote['external_reference']);
                }
            })
            ->latest('id')
            ->first();

        if ($payment) {
            $this->applyRemotePayment($payment, $remote);
        }
    }

    public function serialize(EcosystemPayment $payment, ?array $remote = null): array
    {
        $transaction = data_get($remote, 'point_of_interaction.transaction_data', []);
        $metadata = $payment->metadata ?? [];

        return [
            'public_id' => $payment->public_id,
            'provider' => $payment->provider,
            'method' => $payment->method,
            'status' => $payment->status,
            'amount' => (float) $payment->gross_amount,
            'paid_at' => optional($payment->paid_at)->toIso8601String(),
            'qr_code' => $transaction['qr_code'] ?? null,
            'qr_code_base64' => $transaction['qr_code_base64'] ?? null,
            'ticket_url' => $transaction['ticket_url'] ?? null,
            'checkout_url' => $metadata['checkout_url'] ?? null,
            'sandbox_checkout_url' => $metadata['sandbox_checkout_url'] ?? null,
        ];
    }

    private function createPix(EcosystemPayment $payment, Order $order, $user, string $token, string $reference): array
    {
        $remote = $this->mercadoPago->createPayment($token, [
            'transaction_amount' => (float) $order->total_price,
            'description' => 'Compra #' . $order->order_number,
            'payment_method_id' => 'pix',
            'external_reference' => $reference,
            'notification_url' => $this->notificationUrl(),
            'payer' => [
                'email' => $user->email,
                'first_name' => $user->first_name ?: $order->customer_name,
                'last_name' => $user->last_name ?: '',
            ],
            'metadata' => [
                'app_slug' => $this->context->slug(),
                'order_public_id' => $order->public_id,
            ],
        ], 'commerce-pix-' . $order->public_id . '-' . $payment->public_id);

        $payment->forceFill([
            'provider_payment_id' => (string) ($remote['id'] ?? ''),
            'metadata' => array_merge($payment->metadata ?? [], ['remote_status' => $remote['status'] ?? null]),
        ])->save();
        $order->forceFill(['payment_reference' => $payment->provider_payment_id])->save();

        return $this->serialize($payment, $remote);
    }

    private function createCardCheckout(EcosystemPayment $payment, Order $order, $user, string $token, string $reference): array
    {
        $preferenceItems = $order->items->map(fn ($line) => [
            'id' => (string) $line->item_id,
            'title' => $line->item?->name ?: 'Item',
            'quantity' => (int) $line->quantity,
            'currency_id' => 'BRL',
            'unit_price' => (float) $line->unit_price,
        ])->values();

        if ((float) ($order->delivery_fee ?? 0) > 0) {
            $preferenceItems->push([
                'id' => 'delivery-fee',
                'title' => 'Taxa de entrega',
                'quantity' => 1,
                'currency_id' => 'BRL',
                'unit_price' => (float) $order->delivery_fee,
            ]);
        }

        $preferenceTotal = (float) $preferenceItems->sum(
            fn (array $item) => (float) $item['unit_price'] * (int) $item['quantity']
        );
        if (abs($preferenceTotal - (float) $order->total_price) > 0.01) {
            throw new \RuntimeException('O total enviado ao provedor diverge do total da compra.');
        }

        $returnUrl = rtrim((string) $this->context->application()->url, '/') . '/purchase/' . $order->public_id;
        $remote = $this->mercadoPago->createPreference($token, [
            'items' => $preferenceItems->all(),
            'payer' => ['email' => $user->email],
            'external_reference' => $reference,
            'notification_url' => $this->notificationUrl(),
            'back_urls' => [
                'success' => $returnUrl,
                'pending' => $returnUrl,
                'failure' => $returnUrl,
            ],
            'auto_return' => 'approved',
            'payment_methods' => [
                'excluded_payment_methods' => [['id' => 'pix'], ['id' => 'account_money']],
                'excluded_payment_types' => [['id' => 'ticket'], ['id' => 'bank_transfer']],
            ],
            'metadata' => [
                'app_slug' => $this->context->slug(),
                'order_public_id' => $order->public_id,
            ],
        ], 'commerce-card-' . $order->public_id . '-' . $payment->public_id);

        $metadata = array_merge($payment->metadata ?? [], [
            'preference_id' => $remote['id'] ?? null,
            'checkout_url' => $remote['init_point'] ?? null,
            'sandbox_checkout_url' => $remote['sandbox_init_point'] ?? null,
        ]);
        $payment->forceFill(['metadata' => $metadata])->save();

        return $this->serialize($payment);
    }

    private function applyRemotePayment(EcosystemPayment $payment, array $remote): void
    {
        $mapped = match ((string) ($remote['status'] ?? '')) {
            'approved' => 'paid',
            'refunded', 'charged_back' => 'refunded',
            'rejected', 'cancelled' => 'failed',
            default => 'pending',
        };

        DB::transaction(function () use ($payment, $remote, $mapped) {
            $providerFee = (float) collect($remote['fee_details'] ?? [])->sum('amount');
            $payment->forceFill([
                'provider_payment_id' => (string) ($remote['id'] ?? $payment->provider_payment_id),
                'status' => $mapped,
                'provider_fee' => $providerFee,
                'seller_net' => max(0, (float) $payment->gross_amount - $providerFee - (float) $payment->platform_fee),
                'paid_at' => $mapped === 'paid' ? now() : $payment->paid_at,
                'refunded_at' => $mapped === 'refunded' ? now() : $payment->refunded_at,
                'failed_at' => $mapped === 'failed' ? now() : $payment->failed_at,
                'metadata' => array_merge($payment->metadata ?? [], ['remote_status' => $remote['status'] ?? null]),
            ])->save();

            if ($payment->source_type !== 'order' || ! $payment->source_id) return;

            $order = Order::query()
                ->whereKey($payment->source_id)
                ->where('app_id', $this->context->id())
                ->where('type', 'commerce')
                ->lockForUpdate()
                ->first();

            if (! $order) return;

            $order->forceFill([
                'payment_status' => $mapped,
                'payment_reference' => $payment->provider_payment_id,
                'fulfillment_status' => $mapped === 'paid'
                    ? 'available'
                    : ($mapped === 'refunded' ? 'blocked' : $order->fulfillment_status),
                'status' => $mapped === 'paid' && $order->status === 'pending' ? 'confirmed' : $order->status,
                'status_updated_at' => now(),
            ])->save();
        }, 3);
    }

    private function notificationUrl(): string
    {
        return rtrim((string) config('app.url'), '/')
            . '/api/v1/apps/' . rawurlencode($this->context->slug())
            . '/commerce/payments/mercadopago/webhook';
    }

    private function providerToken(): string
    {
        $token = trim((string) config('services.mercadopago.access_token'));
        abort_if($token === '', 503, 'Mercado Pago não configurado.');
        return $token;
    }
}
