<?php

namespace App\Domain\Commerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\EcosystemPayment;
use App\Models\Establishment;
use App\Models\Order;
use App\Services\MercadoPagoService;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentStatusController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly MercadoPagoService $paymentProvider,
    ) {}

    public function show(Request $request, int $order): JsonResponse
    {
        $model = Order::query()
            ->whereKey($order)
            ->where('app_id', $this->context->id())
            ->where('client_id', $request->user()->id)
            ->where('entity_name', 'establishment')
            ->firstOrFail();

        if ($model->payment_method !== 'pix') {
            return response()->json(['success' => true, 'data' => null]);
        }

        $payment = EcosystemPayment::query()
            ->where('app_id', $this->context->id())
            ->where('source_type', 'order')
            ->where('source_id', $model->id)
            ->latest('id')
            ->first();

        if ($payment && $payment->provider === 'mercadopago' && $payment->provider_payment_id) {
            $result = [
                'provider' => $payment->provider,
                'public_id' => $payment->public_id,
                'status' => $payment->status,
                'amount' => (float) $payment->gross_amount,
                'qr_code' => null,
                'qr_code_base64' => null,
                'ticket_url' => null,
            ];

            $token = trim((string) config('services.mercadopago.access_token'));
            if ($token !== '' && $payment->status === 'pending') {
                try {
                    $remote = $this->paymentProvider->getPayment($token, (string) $payment->provider_payment_id);
                    $transaction = $remote['point_of_interaction']['transaction_data'] ?? [];
                    $result['qr_code'] = $transaction['qr_code'] ?? null;
                    $result['qr_code_base64'] = $transaction['qr_code_base64'] ?? null;
                    $result['ticket_url'] = $transaction['ticket_url'] ?? null;
                } catch (\Throwable) {
                    // Local order tracking remains available during provider outages.
                }
            }

            return response()->json(['success' => true, 'data' => $result]);
        }

        $establishment = Establishment::query()
            ->whereKey($model->entity_id)
            ->where('app_id', $this->context->id())
            ->first();

        return response()->json(['success' => true, 'data' => [
            'provider' => 'manual_pix',
            'status' => $model->payment_status ?: 'pending',
            'amount' => (float) $model->total_price,
            'pix_key' => $establishment?->pix_key ?: null,
        ]]);
    }
}
