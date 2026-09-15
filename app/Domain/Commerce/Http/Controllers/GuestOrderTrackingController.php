<?php

namespace App\Domain\Commerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\EcosystemPayment;
use App\Models\Order;
use App\Services\MercadoPagoService;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class GuestOrderTrackingController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly MercadoPagoService $paymentProvider,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'integer', 'min:1'],
            'phone' => ['required', 'string', 'max:30'],
        ]);

        $providedPhone = $this->normalizePhone($validated['phone']);
        abort_if(strlen($providedPhone) < 8, 404);

        $order = Order::query()
            ->where('app_id', $this->context->id())
            ->where('entity_name', 'establishment')
            ->whereKey((int) $validated['order_id'])
            ->whereNull('client_id')
            ->where('created_at', '>=', now()->subDays(30))
            ->with(['entity', 'items.item'])
            ->first();

        abort_unless(
            $order && hash_equals($this->normalizePhone((string) $order->customer_phone), $providedPhone),
            404
        );

        $establishment = $order->entity;

        return response()->json([
            'data' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status ?: 'pending',
                'payment_status' => $order->payment_status,
                'payment_method' => $order->payment_method,
                'payment' => $this->paymentInstructions($order),
                'fulfillment' => $order->fulfillment,
                'total_price' => (float) $order->total_price,
                'created_at' => optional($order->created_at)->toIso8601String(),
                'updated_at' => optional($order->updated_at)->toIso8601String(),
                'establishment' => $establishment ? [
                    'id' => $establishment->id,
                    'name' => $establishment->name,
                    'fantasy' => $establishment->fantasy,
                    'slug' => $establishment->slug,
                    'logo' => $establishment->logo,
                ] : null,
                'items' => $order->items->map(static fn ($orderItem) => [
                    'id' => $orderItem->id,
                    'item_id' => $orderItem->item_id,
                    'name' => $orderItem->item?->name,
                    'quantity' => (int) $orderItem->quantity,
                    'subtotal' => (float) $orderItem->subtotal,
                ])->values(),
            ],
        ]);
    }

    private function paymentInstructions(Order $order): ?array
    {
        if ($order->payment_method !== 'pix' || $order->payment_status === 'paid' || $order->status === 'cancelled') {
            return null;
        }

        $payment = EcosystemPayment::query()
            ->where('app_id', $this->context->id())
            ->where('source_type', 'order')
            ->where('source_id', $order->id)
            ->where('method', 'pix')
            ->latest('id')
            ->first();

        if ($payment?->provider === 'mercadopago' && $payment->provider_payment_id) {
            $token = trim((string) config('services.mercadopago.access_token'));
            if ($token !== '') {
                try {
                    $remote = $this->paymentProvider->getPayment($token, (string) $payment->provider_payment_id);
                    $transaction = data_get($remote, 'point_of_interaction.transaction_data', []);

                    return [
                        'provider' => 'mercadopago',
                        'status' => (string) ($remote['status'] ?? $payment->status ?? 'pending'),
                        'amount' => (float) $order->total_price,
                        'qr_code' => $transaction['qr_code'] ?? null,
                        'qr_code_base64' => $transaction['qr_code_base64'] ?? null,
                        'ticket_url' => $transaction['ticket_url'] ?? null,
                    ];
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }

        $pixKey = trim((string) ($order->entity?->pix_key ?? ''));
        if ($pixKey !== '') {
            return [
                'provider' => 'manual_pix',
                'status' => 'pending',
                'amount' => (float) $order->total_price,
                'pix_key' => $pixKey,
            ];
        }

        return null;
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?: '';
    }
}
