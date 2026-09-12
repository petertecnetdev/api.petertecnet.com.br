<?php

namespace App\Domain\Commerce\Services;

use App\Models\EcosystemPayment;
use App\Models\Establishment;
use App\Models\Order;
use App\Models\User;
use App\Services\MercadoPagoService;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class OrderPaymentRetryService
{
    private const FAILED_PROVIDER_STATUSES = ['rejected', 'cancelled', 'refunded', 'charged_back'];

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly MercadoPagoService $paymentProvider,
    ) {}

    public function retryPix(int $orderId, User $user): array
    {
        [$order, $establishment, $payment, $rotatedAttempt] = DB::transaction(function () use ($orderId, $user) {
            $order = Order::query()
                ->whereKey($orderId)
                ->where('app_id', $this->context->id())
                ->where('client_id', $user->id)
                ->where('entity_name', 'establishment')
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->isPaid() || $order->payment_status === 'paid') {
                throw new HttpException(409, 'Este pedido já está pago.');
            }

            if (in_array($order->status, ['cancelled', 'completed'], true)) {
                throw new HttpException(422, 'Este pedido não aceita um novo pagamento.');
            }

            $establishment = Establishment::query()
                ->whereKey($order->entity_id)
                ->forApplication($this->context->id())
                ->where('is_cancelled', false)
                ->firstOrFail();

            $payment = EcosystemPayment::query()
                ->where('app_id', $this->context->id())
                ->where('app_slug', $this->context->slug())
                ->where('source_type', 'order')
                ->where('source_id', $order->id)
                ->where('user_id', $user->id)
                ->where('method', 'pix')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($payment?->status === 'paid') {
                throw new HttpException(409, 'Este pedido já possui pagamento confirmado.');
            }

            $rotatedAttempt = $payment !== null
                && in_array((string) $payment->status, ['failed', 'rejected', 'cancelled'], true);

            if (! $payment || $rotatedAttempt) {
                $publicId = (string) Str::uuid();
                $reference = $this->context->slug().'-order-'.$order->id.'-'.$publicId;
                $payment = EcosystemPayment::create([
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
                    'method' => 'pix',
                    'status' => 'pending',
                    'gross_amount' => $order->total_price,
                    'platform_fee' => 0,
                    'provider_fee' => 0,
                    'seller_net' => $order->total_price,
                    'metadata' => ['order_number' => $order->order_number],
                ]);
            }

            return [$order, $establishment, $payment, $rotatedAttempt];
        }, 3);

        $token = trim((string) config('services.mercadopago.access_token'));
        if ($token === '') {
            if (! empty($establishment->pix_key)) {
                return [200, ['success' => true, 'data' => [
                    'provider' => 'manual_pix',
                    'status' => 'pending',
                    'pix_key' => $establishment->pix_key,
                    'amount' => (float) $order->total_price,
                ]]];
            }

            throw new HttpException(503, 'Provedor de pagamento não configurado.');
        }

        $idempotencyKey = $this->context->slug().'-order-'.$order->id;
        if ($rotatedAttempt) {
            $idempotencyKey .= '-payment-'.$payment->public_id;
        }

        try {
            $remote = $this->paymentProvider->createPayment($token, [
                'transaction_amount' => (float) $order->total_price,
                'description' => 'Pedido #'.$order->order_number,
                'payment_method_id' => 'pix',
                'external_reference' => $payment->source_reference,
                'notification_url' => rtrim((string) config('app.url'), '/').'/api/v1/apps/'.$this->context->slug().'/payments/mercadopago/webhook',
                'payer' => [
                    'email' => $user->email,
                    'first_name' => $user->first_name ?: $order->customer_name,
                    'last_name' => $user->last_name ?: '',
                ],
            ], $idempotencyKey);
        } catch (\Throwable $exception) {
            report($exception);
            throw new HttpException(503, 'O provedor de Pix não respondeu. Tente novamente com o mesmo pedido.');
        }

        $remoteStatus = strtolower((string) ($remote['status'] ?? 'pending'));
        $transaction = $remote['point_of_interaction']['transaction_data'] ?? [];

        if (in_array($remoteStatus, self::FAILED_PROVIDER_STATUSES, true)) {
            $payment->forceFill([
                'provider_payment_id' => (string) ($remote['id'] ?? $payment->provider_payment_id ?? ''),
                'status' => 'failed',
                'failed_at' => now(),
                'metadata' => array_merge($payment->metadata ?? [], [
                    'remote_status' => $remoteStatus,
                    'status_detail' => $remote['status_detail'] ?? null,
                ]),
            ])->save();

            $order->forceFill(['payment_status' => 'failed'])->save();

            return [422, [
                'success' => false,
                'message' => 'O Pix foi recusado. Tente novamente para gerar uma nova cobrança.',
                'data' => ['status' => 'failed', 'retryable' => true],
            ]];
        }

        $paid = $remoteStatus === 'approved';

        $payment->forceFill([
            'provider_payment_id' => (string) ($remote['id'] ?? $payment->provider_payment_id ?? ''),
            'status' => $paid ? 'paid' : 'pending',
            'paid_at' => $paid ? now() : $payment->paid_at,
            'failed_at' => null,
            'metadata' => array_merge($payment->metadata ?? [], ['remote_status' => $remoteStatus]),
        ])->save();

        $order->forceFill([
            'payment_method' => 'pix',
            'payment_status' => $paid ? 'paid' : 'pending',
            'payment_reference' => $payment->provider_payment_id,
        ])->save();

        return [200, ['success' => true, 'data' => [
            'provider' => 'mercadopago',
            'public_id' => $payment->public_id,
            'status' => $paid ? 'paid' : 'pending',
            'amount' => (float) $order->total_price,
            'qr_code' => $transaction['qr_code'] ?? null,
            'qr_code_base64' => $transaction['qr_code_base64'] ?? null,
            'ticket_url' => $transaction['ticket_url'] ?? null,
        ]]];
    }
}
