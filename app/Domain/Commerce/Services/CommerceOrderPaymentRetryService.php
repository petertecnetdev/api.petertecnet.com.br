<?php

namespace App\Domain\Commerce\Services;

use App\Models\CommerceOrder;
use App\Models\CommercePayment;
use App\Models\User;
use App\Services\MerchantPaymentAccountService;
use App\Services\MercadoPagoService;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CommerceOrderPaymentRetryService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly MercadoPagoService $mercadoPago,
        private readonly MerchantPaymentAccountService $accounts,
    ) {}

    public function retryPix(string $publicId, User $user): array
    {
        $order = DB::transaction(function () use ($publicId, $user) {
            $order = CommerceOrder::query()
                ->where('app_id', $this->context->id())
                ->where('public_id', $publicId)
                ->where('user_id', $user->id)
                ->with(['event','production','payments'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status === 'paid') throw new HttpException(409, 'Este pedido já está pago.');
            if ($order->status !== 'pending') throw new HttpException(422, 'Este pedido não aceita retomada de pagamento.');
            if (! $order->expires_at || now()->greaterThanOrEqualTo($order->expires_at)) throw new HttpException(410, 'Este pedido expirou. Refaça a compra.');
            if ((float) $order->total <= 0 || $order->payment_method !== 'pix') throw new HttpException(422, 'Somente pedidos PIX pendentes podem ser retomados.');

            $terminalStatuses = ['failed','rejected','cancelled','canceled','refunded','charged_back'];
            $existing = $order->payments->first(fn ($payment) => ! in_array($payment->status, $terminalStatuses, true));
            if ($existing) return [$order, $existing, null];

            $retryAttempt = $order->payments->filter(
                fn ($payment) => in_array($payment->status, $terminalStatuses, true)
            )->count() + 1;

            return [$order, null, $retryAttempt];
        }, 3);

        [$order, $existing, $retryAttempt] = $order;
        if ($existing) return [200, ['message' => 'Pagamento já iniciado.', 'order' => $order, 'payment' => $existing]];

        $readiness = $this->accounts->readiness((int) $order->production_id);
        if (! $readiness['available'] || ! in_array('pix', $readiness['methods'], true)) {
            throw new HttpException(422, $readiness['message'] ?: 'PIX indisponível para esta organização.');
        }

        $account = $this->accounts->account((int) $order->production_id, 'mercadopago', true);
        $usesMerchant = (bool) ($account && $account->access_token);
        $allowPlatform = (bool) $this->context->option('commerce.allow_platform_collection', false);
        $platformToken = trim((string) config('services.mercadopago.access_token'));
        if (! $usesMerchant && (! $allowPlatform || $platformToken === '')) throw new HttpException(422, 'Conecte uma conta de pagamento antes de iniciar vendas pagas.');

        $sellerToken = $usesMerchant ? $this->accounts->freshAccessToken($account)[1] : $platformToken;
        $settlementMode = $usesMerchant ? 'automatic_split' : 'platform_collection';
        $idempotencyKey = 'commerce-order-'.$order->public_id.'-retry-'.$retryAttempt;
        $payload = [
            'transaction_amount' => (float) $order->total,
            'description' => mb_substr(($this->context->application()->name ?: 'Peter Tecnet').' - '.($order->event->title ?? 'Pedido'), 0, 255),
            'external_reference' => $order->public_id,
            'notification_url' => rtrim((string) config('app.url'), '/').'/api/v1/apps/'.$this->context->slug().'/payments/mercadopago/webhook',
            'payer' => ['email' => $user->email],
            'payment_method_id' => 'pix',
            'date_of_expiration' => $order->expires_at->copy()->utc()->format('Y-m-d\TH:i:s.000\Z'),
            'metadata' => [
                'application_id' => $this->context->id(),
                'order_id' => $order->id,
                'order_public_id' => $order->public_id,
                'organization_id' => $order->production_id,
                'settlement_mode' => $settlementMode,
            ],
        ];
        if ($usesMerchant && (float) $order->platform_fee > 0) $payload['application_fee'] = (float) $order->platform_fee;

        try {
            $remote = $this->mercadoPago->createPayment($sellerToken, $payload, $idempotencyKey);
        } catch (\Throwable $e) {
            report($e);
            throw new HttpException(503, 'O provedor de PIX não respondeu. O pedido continua reservado; tente novamente.');
        }

        $transaction = data_get($remote, 'point_of_interaction.transaction_data', []);
        $providerFee = collect($remote['fee_details'] ?? [])->sum(fn ($fee) => (float) ($fee['amount'] ?? 0));
        $payment = CommercePayment::updateOrCreate(
            ['app_id' => $this->context->id(), 'order_id' => $order->id, 'idempotency_key' => $idempotencyKey],
            [
                'provider' => 'mercadopago', 'method' => 'pix', 'status' => (string) ($remote['status'] ?? 'pending'),
                'provider_payment_id' => isset($remote['id']) ? (string) $remote['id'] : null,
                'provider_txid' => data_get($remote, 'point_of_interaction.transaction_data.transaction_id'),
                'amount' => $order->total, 'provider_fee' => $providerFee,
                'qr_code' => $transaction['qr_code'] ?? null,
                'qr_code_image' => ! empty($transaction['qr_code_base64']) ? 'data:image/png;base64,'.$transaction['qr_code_base64'] : null,
                'ticket_url' => $transaction['ticket_url'] ?? null, 'provider_payload' => $remote,
            ]
        );
        $order->update([
            'processor_fee' => $providerFee,
            'metadata' => array_merge($order->metadata ?? [], [
                'payment_initialization_retryable' => false,
                'payment_resumed_at' => now()->toIso8601String(),
                'payment_retry_attempt' => $retryAttempt,
            ]),
        ]);

        return [200, ['message' => 'Pagamento PIX retomado no mesmo pedido.', 'order' => $order->fresh(['items','event','production']), 'payment' => $payment]];
    }
}
