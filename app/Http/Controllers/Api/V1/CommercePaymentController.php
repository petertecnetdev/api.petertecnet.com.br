<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Models\Order;
use App\Services\CommercePaymentService;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommercePaymentController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CommercePaymentService $payments,
    ) {}

    public function show(Request $request, string $publicId): JsonResponse
    {
        $order = $this->buyerOrder($request, $publicId);
        $payment = $this->payments->latestForOrder($order);
        $remote = null;

        if ($payment && $payment->provider === 'mercadopago' && $payment->status === 'pending') {
            try {
                $remote = $this->payments->sync($payment);
                $payment->refresh();
                $order->refresh();
            } catch (\Throwable $exception) {
                // O polling do comprador não deve quebrar por indisponibilidade temporária do provedor.
                report($exception);
            }
        }

        // O QR PIX pode ser reconstruído após refresh consultando a fonte de verdade
        // no provedor. Não persistimos uma segunda cópia sensível no navegador/API.
        if ($payment && $payment->method === 'pix' && ! $remote && $payment->provider_payment_id) {
            try {
                $remote = $this->payments->remote($payment);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return response()->json(['success' => true, 'data' => [
            'payment' => $payment ? $this->payments->serialize($payment, $remote) : null,
            'order' => $this->serializeOrder($order->fresh(['items.item'])),
        ]]);
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
}
