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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderPaymentRetryController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly MercadoPagoService $paymentProvider,
    ) {}

    public function store(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'payment_method' => ['required', 'in:pix'],
        ]);

        $user = $request->user();

        [$model, $establishment, $payment] = DB::transaction(function () use ($order, $user) {
            $model = Order::query()
                ->whereKey($order)
                ->where('app_id', $this->context->id())
                ->where('client_id', $user->id)
                ->where('entity_name', 'establishment')
                ->lockForUpdate()
                ->firstOrFail();

            abort_if($model->isPaid() || $model->payment_status === 'paid', 409, 'Este pedido já está pago.');
            abort_if(in_array($model->status, ['cancelled', 'completed'], true), 422, 'Este pedido não aceita um novo pagamento.');

            $establishment = Establishment::query()
                ->whereKey($model->entity_id)
                ->forApplication($this->context->id())
                ->where('is_cancelled', false)
                ->firstOrFail();

            $payment = EcosystemPayment::query()
                ->where('app_id', $this->context->id())
                ->where('app_slug', $this->context->slug())
                ->where('source_type', 'order')
                ->where('source_id', $model->id)
                ->where('user_id', $user->id)
                ->where('method', 'pix')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $payment) {
                $publicId = (string) Str::uuid();
                $reference = $this->context->slug().'-order-'.$model->id.'-'.$publicId;
                $payment = EcosystemPayment::create([
                    'public_id' => $publicId,
                    'app_id' => $this->context->id(),
                    'app_slug' => $this->context->slug(),
                    'provider' => 'mercadopago',
                    'source_type' => 'order',
                    'source_reference' => $reference,
                    'source_id' => $model->id,
                    'user_id' => $user->id,
                    'establishment_id' => $establishment->id,
                    'currency' => 'BRL',
                    'method' => 'pix',
                    'status' => 'pending',
                    'gross_amount' => $model->total_price,
                    'platform_fee' => 0,
                    'provider_fee' => 0,
                    'seller_net' => $model->total_price,
                    'metadata' => ['order_number' => $model->order_number],
                ]);
            }

            abort_if($payment->status === 'paid', 409, 'Este pedido já possui pagamento confirmado.');

            return [$model, $establishment, $payment];
        }, 3);

        $token = trim((string) config('services.mercadopago.access_token'));
        if ($token === '') {
            if (! empty($establishment->pix_key)) {
                return response()->json(['success' => true, 'data' => [
                    'provider' => 'manual_pix',
                    'status' => 'pending',
                    'pix_key' => $establishment->pix_key,
                    'amount' => (float) $model->total_price,
                ]]);
            }

            abort(503, 'Provedor de pagamento não configurado.');
        }

        try {
            $remote = $this->paymentProvider->createPayment($token, [
                'transaction_amount' => (float) $model->total_price,
                'description' => 'Pedido #'.$model->order_number,
                'payment_method_id' => 'pix',
                'external_reference' => $payment->source_reference,
                'notification_url' => rtrim((string) config('app.url'), '/').'/api/v1/apps/'.$this->context->slug().'/payments/mercadopago/webhook',
                'payer' => [
                    'email' => $user->email,
                    'first_name' => $user->first_name ?: $model->customer_name,
                    'last_name' => $user->last_name ?: '',
                ],
            ], $this->context->slug().'-order-'.$model->id);

            $transaction = $remote['point_of_interaction']['transaction_data'] ?? [];
            $payment->forceFill([
                'provider_payment_id' => (string) ($remote['id'] ?? $payment->provider_payment_id ?? ''),
                'status' => 'pending',
                'failed_at' => null,
                'metadata' => array_merge($payment->metadata ?? [], ['remote_status' => $remote['status'] ?? null]),
            ])->save();

            $model->forceFill([
                'payment_method' => $data['payment_method'],
                'payment_status' => 'pending',
                'payment_reference' => $payment->provider_payment_id,
            ])->save();

            return response()->json(['success' => true, 'data' => [
                'provider' => 'mercadopago',
                'public_id' => $payment->public_id,
                'status' => 'pending',
                'amount' => (float) $model->total_price,
                'qr_code' => $transaction['qr_code'] ?? null,
                'qr_code_base64' => $transaction['qr_code_base64'] ?? null,
                'ticket_url' => $transaction['ticket_url'] ?? null,
            ]]);
        } catch (\Throwable $exception) {
            report($exception);
            abort(503, 'O provedor de Pix não respondeu. Tente novamente com o mesmo pedido.');
        }
    }
}
