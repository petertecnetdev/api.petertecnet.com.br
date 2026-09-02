<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EcosystemPayment;
use App\Models\Establishment;
use App\Models\Order;
use App\Services\MercadoPagoService;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommercePaymentController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly MercadoPagoService $mercadoPago,
    ) {}

    public function show(Request $request, string $publicId): JsonResponse
    {
        $order = $this->buyerOrder($request, $publicId);
        $payment = EcosystemPayment::query()
            ->where('app_id', $this->context->id())
            ->where('source_type', 'order')
            ->where('source_id', $order->id)
            ->latest('id')
            ->first();

        $remote = null;
        if ($payment && $payment->provider === 'mercadopago' && $payment->status === 'pending') {
            try {
                $remote = $payment->provider_payment_id
                    ? $this->mercadoPago->getPayment($this->providerToken(), $payment->provider_payment_id)
                    : $this->mercadoPago->findPaymentByExternalReference($this->providerToken(), $payment->source_reference);

                if ($remote) {
                    $this->applyRemotePayment($payment, $remote);
                    $payment->refresh();
                    $order->refresh();
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        // PIX data is intentionally recovered from the provider instead of being
        // stored as a second source of truth. This makes a browser refresh safe.
        if ($payment && $payment->method === 'pix' && ! $remote && $payment->provider_payment_id) {
            try {
                $remote = $this->mercadoPago->getPayment($this->providerToken(), $payment->provider_payment_id);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return response()->json(['success' => true, 'data' => [
            'payment' => $payment ? $this->serializePayment($payment, $remote) : null,
            'order' => $this->serializeOrder($order->fresh(['items.item'])),
        ]]);
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

            $order = Order::query()
                ->whereKey($payment->source_id)
                ->where('app_id', $this->context->id())
                ->where('type', 'commerce')
                ->lockForUpdate()
                ->first();

            if ($order) {
                $order->forceFill([
                    'payment_status' => $mapped,
                    'payment_reference' => $payment->provider_payment_id,
                    'fulfillment_status' => $mapped === 'paid'
                        ? 'available'
                        : ($mapped === 'refunded' ? 'blocked' : $order->fulfillment_status),
                    'status' => $mapped === 'paid' && $order->status === 'pending' ? 'confirmed' : $order->status,
                    'status_updated_at' => now(),
                ])->save();
            }
        }, 3);
    }

    private function serializePayment(EcosystemPayment $payment, ?array $remote): array
    {
        $transaction = data_get($remote, 'point_of_interaction.transaction_data', []);

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
            'checkout_url' => null,
        ];
    }

    private function serializeOrder(Order $order): array
    {
        $establishment = Establishment::query()
            ->whereKey($order->entity_id)
            ->where('app_id', $this->context->id())
            ->first();

        $claim = $order->payment_status === 'paid'
            && ! in_array($order->fulfillment_status, ['fulfilled', 'delivered', 'blocked'], true)
            ? ['token' => $this->claimToken($order), 'public_id' => $order->public_id]
            : null;

        return [
            'public_id' => $order->public_id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'fulfillment' => $order->fulfillment,
            'fulfillment_status' => $order->fulfillment_status,
            'subtotal' => (float) ($order->subtotal ?? 0),
            'delivery_fee' => (float) ($order->delivery_fee ?? 0),
            'total_price' => (float) $order->total_price,
            'delivery_address' => $order->delivery_address,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'notes' => $order->notes,
            'created_at' => optional($order->created_at)->toIso8601String(),
            'fulfilled_at' => optional($order->fulfilled_at)->toIso8601String(),
            'establishment' => $establishment?->only(['id', 'name', 'fantasy', 'slug', 'logo', 'address', 'city', 'uf', 'phone']),
            'items' => $order->relationLoaded('items') ? $order->items->map(fn ($line) => [
                'item_id' => $line->item_id,
                'name' => $line->item?->name,
                'quantity' => (int) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'subtotal' => (float) $line->subtotal,
            ])->values() : [],
            'claim' => $claim,
        ];
    }

    private function claimToken(Order $order): string
    {
        return hash_hmac(
            'sha256',
            implode('|', [$order->public_id, $order->id, $order->app_id, $order->client_id]),
            (string) config('app.key')
        );
    }

    private function buyerOrder(Request $request, string $publicId): Order
    {
        return Order::query()
            ->where('app_id', $this->context->id())
            ->where('public_id', $publicId)
            ->where('client_id', $request->user()->id)
            ->where('type', 'commerce')
            ->with(['items.item'])
            ->firstOrFail();
    }

    private function providerToken(): string
    {
        $token = trim((string) config('services.mercadopago.access_token'));
        abort_if($token === '', 503, 'Mercado Pago não configurado.');
        return $token;
    }
}
