<?php

namespace App\Domain\Commerce\Services;

use App\Models\CommerceOrder;
use App\Models\CommercePayment;
use App\Models\User;
use App\Services\AsaasPaymentService;
use App\Services\CommercePaymentSettlementService;
use App\Services\MerchantPaymentAccountService;
use App\Services\MercadoPagoService;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class CommerceOrderPaymentRetryService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly MercadoPagoService $mercadoPago,
        private readonly AsaasPaymentService $asaas,
        private readonly CommercePaymentSettlementService $settlement,
        private readonly MerchantPaymentAccountService $accounts,
    ) {}

    public function retry(string $publicId, User $user, array $data): array
    {
        $method = strtolower(trim((string) ($data['payment_method'] ?? 'pix')));

        [$order, $existing, $retryAttempt] = DB::transaction(function () use ($publicId, $user, $method) {
            $order = CommerceOrder::query()
                ->where('app_id', $this->context->id())
                ->where('public_id', $publicId)
                ->where('user_id', $user->id)
                ->with(['event', 'production', 'payments'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status === 'paid') {
                throw new HttpException(409, 'Este pedido já está pago.');
            }

            if ($order->status !== 'pending') {
                throw new HttpException(422, 'Este pedido não aceita retomada de pagamento.');
            }

            if (! $order->expires_at || now()->greaterThanOrEqualTo($order->expires_at)) {
                throw new HttpException(410, 'Este pedido expirou. Refaça a compra.');
            }

            if ((float) $order->total <= 0 || strtolower((string) $order->payment_method) !== $method) {
                throw new HttpException(422, 'A forma de pagamento não corresponde a este pedido.');
            }

            $terminalStatuses = [
                'failed', 'rejected', 'cancelled', 'canceled', 'expired', 'overdue',
                'deleted', 'refunded', 'charged_back',
            ];

            $existing = $order->payments
                ->sortByDesc('id')
                ->first(fn ($payment) => ! in_array(strtolower((string) $payment->status), $terminalStatuses, true));

            if ($existing) {
                return [$order, $existing, null];
            }

            $retryAttempt = $order->payments
                ->filter(fn ($payment) => in_array(strtolower((string) $payment->status), $terminalStatuses, true))
                ->count() + 1;

            return [$order, null, $retryAttempt];
        }, 3);

        if ($existing) {
            return [200, $this->existingPayload($order, $existing)];
        }

        $readiness = $this->accounts->readiness((int) $order->production_id);
        if (! $readiness['available'] || ! in_array($method, $readiness['methods'], true)) {
            throw new HttpException(422, $readiness['message'] ?: 'Forma de pagamento indisponível para esta organização.');
        }

        if (($readiness['provider'] ?? null) === 'asaas') {
            return $this->retryAsaas($order, $user, $data, $method, (int) $retryAttempt, $readiness);
        }

        if ($method !== 'pix') {
            throw new HttpException(422, 'Para retomar esta forma de pagamento, volte ao checkout e informe os dados novamente.');
        }

        return $this->retryMercadoPagoPix($order, $user, (int) $retryAttempt);
    }

    public function retryPix(string $publicId, User $user): array
    {
        return $this->retry($publicId, $user, ['payment_method' => 'pix']);
    }

    private function retryAsaas(
        CommerceOrder $order,
        User $user,
        array $data,
        string $method,
        int $retryAttempt,
        array $readiness,
    ): array {
        $document = preg_replace('/\D+/', '', (string) ($data['payer_cpf_cnpj'] ?? $user->cpf ?? ''));
        if (! in_array(strlen($document), [11, 14], true)) {
            throw new HttpException(422, 'Informe o CPF ou CNPJ do pagador para retomar o pagamento.');
        }

        $name = trim((string) ($data['payer_name'] ?? ''))
            ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))
            ?: (string) ($user->name ?? '')
            ?: (string) ($user->email ?? 'Pagador');

        try {
            $customer = $this->asaas->findOrCreateCustomer(
                (int) $user->id,
                $name,
                $document,
                $data['payer_email'] ?? $user->email ?? null,
                $user->phone ?? null,
            );

            $customerId = trim((string) ($customer['id'] ?? ''));
            if ($customerId === '') {
                throw new \RuntimeException('O Asaas não retornou o identificador do pagador.');
            }

            $dueDate = $method === 'boleto'
                ? $order->expires_at->copy()->timezone('America/Sao_Paulo')->toDateString()
                : now('America/Sao_Paulo')->toDateString();

            $applicationUrl = rtrim((string) ($this->context->application()->url ?: config('app.url')), '/');
            $successUrl = $applicationUrl
                . '/checkout/' . rawurlencode((string) ($order->event->slug ?? ''))
                . '?payment_return=asaas&order=' . rawurlencode((string) $order->public_id);

            $remote = $this->asaas->createOrRecoverPayment(
                $customerId,
                $method,
                (float) $order->total,
                $dueDate,
                (string) $order->public_id,
                ($this->context->application()->name ?: 'Peter Tecnet') . ' - ' . ($order->event->title ?? 'Pedido'),
                $successUrl,
            );

            $providerId = trim((string) ($remote['id'] ?? ''));
            if ($providerId === '') {
                throw new \RuntimeException('O Asaas não retornou o identificador da cobrança.');
            }

            $remoteValue = is_numeric($remote['value'] ?? null) ? (float) $remote['value'] : (float) $order->total;
            $remoteNet = is_numeric($remote['netValue'] ?? null) ? (float) $remote['netValue'] : null;
            $providerFee = $remoteNet !== null ? max(0, round($remoteValue - $remoteNet, 2)) : 0.0;
            $pix = is_array($remote['_pix'] ?? null) ? $remote['_pix'] : [];
            $boleto = is_array($remote['_boleto'] ?? null) ? $remote['_boleto'] : [];
            $localStatus = $this->asaas->normalizeLocalStatus($remote);
            $idempotencyKey = 'commerce-order-' . $order->public_id . '-retry-' . $retryAttempt;

            $payment = CommercePayment::query()->updateOrCreate(
                [
                    'app_id' => $this->context->id(),
                    'order_id' => $order->id,
                    'provider' => 'asaas',
                    'provider_payment_id' => $providerId,
                ],
                [
                    'method' => $method,
                    'status' => $localStatus === 'failed'
                        ? strtolower((string) ($remote['status'] ?? 'failed'))
                        : $localStatus,
                    'provider_txid' => null,
                    'idempotency_key' => $idempotencyKey,
                    'amount' => $order->total,
                    'provider_fee' => $providerFee,
                    'qr_code' => $pix['payload'] ?? null,
                    'qr_code_image' => ! empty($pix['encodedImage'])
                        ? 'data:image/png;base64,' . $pix['encodedImage']
                        : null,
                    'ticket_url' => $remote['bankSlipUrl'] ?? $remote['invoiceUrl'] ?? null,
                    'provider_payload' => array_merge($remote, [
                        '_boleto' => $boleto,
                        '_petertecnet' => [
                            'provider' => 'asaas',
                            'retry_attempt' => $retryAttempt,
                        ],
                    ]),
                    'paid_at' => $localStatus === 'paid' ? now() : null,
                    'failed_at' => $localStatus === 'failed' ? now() : null,
                ]
            );

            $order->update([
                'processor_fee' => $providerFee,
                'metadata' => array_merge($order->metadata ?? [], [
                    'payment_initialization_retryable' => false,
                    'payment_resumed_at' => now()->toIso8601String(),
                    'payment_retry_attempt' => $retryAttempt,
                    'payment_provider' => 'asaas',
                ]),
            ]);

            if ($localStatus === 'paid') {
                $order = $this->settlement->confirm($payment, $remote, $providerFee);
            }

            return [200, $this->asaasPayload($order, $payment, $remote, $method, $boleto, $readiness)];
        } catch (HttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            throw new HttpException(503, 'O Asaas não respondeu. O pedido continua reservado; tente novamente.');
        }
    }

    private function retryMercadoPagoPix(CommerceOrder $order, User $user, int $retryAttempt): array
    {
        $account = $this->accounts->account((int) $order->production_id, 'mercadopago', true);
        $usesMerchant = (bool) ($account && $account->access_token);
        $requiresAutomaticSplit = (bool) $this->context->option('commerce.require_automatic_split', false);
        $allowPlatform = ! $requiresAutomaticSplit && (
            (bool) config('services.finance.allow_platform_collection', false)
            || (bool) $this->context->option('commerce.allow_platform_collection', false)
        );
        $platformToken = trim((string) config('services.mercadopago.access_token'));

        if (! $usesMerchant && ($requiresAutomaticSplit || ! $allowPlatform || $platformToken === '')) {
            throw new HttpException(
                422,
                $requiresAutomaticSplit
                    ? 'O produtor precisa conectar a conta Mercado Pago antes de retomar o pagamento.'
                    : 'Gateway de contingência indisponível.'
            );
        }

        $sellerToken = $usesMerchant ? $this->accounts->freshAccessToken($account)[1] : $platformToken;
        $settlementMode = $usesMerchant ? 'automatic_split' : 'platform_collection';
        $idempotencyKey = 'commerce-order-' . $order->public_id . '-retry-' . $retryAttempt;

        $payload = [
            'transaction_amount' => (float) $order->total,
            'description' => mb_substr(($this->context->application()->name ?: 'Peter Tecnet') . ' - ' . ($order->event->title ?? 'Pedido'), 0, 255),
            'external_reference' => $order->public_id,
            'notification_url' => rtrim((string) config('app.url'), '/') . '/api/v1/apps/' . $this->context->slug() . '/payments/mercadopago/webhook',
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

        if ($usesMerchant && (float) $order->platform_fee > 0) {
            $payload['application_fee'] = (float) $order->platform_fee;
        }

        try {
            $remote = $requiresAutomaticSplit
                ? $this->mercadoPago->createPayment($sellerToken, $payload, $idempotencyKey, false)
                : $this->mercadoPago->createPayment($sellerToken, $payload, $idempotencyKey);
        } catch (Throwable $exception) {
            report($exception);
            throw new HttpException(503, 'O provedor de PIX não respondeu. O pedido continua reservado; tente novamente.');
        }

        $transaction = data_get($remote, 'point_of_interaction.transaction_data', []);
        $providerFee = collect($remote['fee_details'] ?? [])->sum(fn ($fee) => (float) ($fee['amount'] ?? 0));

        $payment = CommercePayment::updateOrCreate(
            [
                'app_id' => $this->context->id(),
                'order_id' => $order->id,
                'idempotency_key' => $idempotencyKey,
            ],
            [
                'provider' => 'mercadopago',
                'method' => 'pix',
                'status' => (string) ($remote['status'] ?? 'pending'),
                'provider_payment_id' => isset($remote['id']) ? (string) $remote['id'] : null,
                'provider_txid' => data_get($remote, 'point_of_interaction.transaction_data.transaction_id'),
                'amount' => $order->total,
                'provider_fee' => $providerFee,
                'qr_code' => $transaction['qr_code'] ?? null,
                'qr_code_image' => ! empty($transaction['qr_code_base64'])
                    ? 'data:image/png;base64,' . $transaction['qr_code_base64']
                    : null,
                'ticket_url' => $transaction['ticket_url'] ?? null,
                'provider_payload' => $remote,
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

        return [200, [
            'message' => 'Pagamento PIX retomado no mesmo pedido.',
            'provider' => 'mercadopago',
            'order' => $order->fresh(['items', 'event', 'production']),
            'payment' => $payment,
        ]];
    }

    private function existingPayload(CommerceOrder $order, CommercePayment $payment): array
    {
        $providerPayload = is_array($payment->provider_payload) ? $payment->provider_payload : [];
        $method = strtolower((string) $payment->method);

        if ($payment->provider === 'asaas') {
            return $this->asaasPayload(
                $order,
                $payment,
                $providerPayload,
                $method,
                (array) data_get($providerPayload, '_boleto', []),
                ['fallback_provider' => config('services.finance.payment_fallback_provider')],
            );
        }

        return [
            'message' => 'Pagamento já iniciado.',
            'provider' => $payment->provider,
            'order' => $order,
            'payment' => $payment,
        ];
    }

    private function asaasPayload(
        CommerceOrder $order,
        CommercePayment $payment,
        array $remote,
        string $method,
        array $boleto,
        array $readiness,
    ): array {
        return [
            'message' => 'Pagamento retomado no mesmo pedido.',
            'provider' => 'asaas',
            'fallback_provider' => $readiness['fallback_provider'] ?? null,
            'order' => $order->fresh(['items', 'event', 'production']),
            'payment' => $payment->fresh(),
            'payment_redirect_url' => $method === 'card' ? ($remote['invoiceUrl'] ?? $payment->ticket_url) : null,
            'boleto' => $method === 'boleto'
                ? [
                    'invoice_url' => $remote['invoiceUrl'] ?? null,
                    'bank_slip_url' => $remote['bankSlipUrl'] ?? $payment->ticket_url,
                    'identification_field' => $boleto['identificationField'] ?? null,
                    'bar_code' => $boleto['barCode'] ?? null,
                    'due_date' => $remote['dueDate'] ?? null,
                ]
                : null,
        ];
    }
}
