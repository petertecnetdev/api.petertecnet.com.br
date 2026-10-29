<?php

namespace App\Domain\Commerce\Services;

use App\Models\CommerceOrder;
use App\Models\CommercePayment;
use App\Models\User;
use App\Services\Independence\ListingCommercePolicy;
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

            // Nunca iniciar outra cobrança com tenant divergente ou pagamento já confirmado.
            // A reconciliação financeira pertence ao SettlementService e exige handoff.
            self::assertRetrySafety($order, (int) $this->context->id());
            app(ListingCommercePolicy::class)->assertLegacyOrVerified($order->event);

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
                // Não apresentar uma cobrança de outra modalidade como retomada válida.
                self::assertExistingPaymentMethod($existing, $method);

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

    private static function assertRetrySafety(CommerceOrder $order, int $appId): void
    {
        if ($appId < 1
            || (int) $order->app_id !== $appId
            || ! $order->event
            || ! $order->production
            || (int) $order->event->app_id !== $appId
            || (int) $order->production->app_id !== $appId
            || (int) $order->event->production_id !== (int) $order->production_id) {
            throw new HttpException(403, 'O pedido não pertence a esta aplicação ou produção.');
        }

        foreach ($order->payments as $payment) {
            if ((int) $payment->app_id !== $appId
                || (int) $payment->order_id !== (int) $order->id) {
                throw new HttpException(409, 'As tentativas de pagamento deste pedido exigem verificação.');
            }

            if (strtolower((string) $payment->status) === 'paid') {
                // Pending + paid deve ser conciliado, jamais gerar POST adicional.
                throw new HttpException(409, 'Pagamento já confirmado. Aguarde a conciliação do pedido.');
            }
        }
    }

    private static function assertExistingPaymentMethod(CommercePayment $payment, string $method): void
    {
        if (strtolower((string) $payment->method) !== $method) {
            throw new HttpException(409, 'A tentativa existente usa outra forma de pagamento.');
        }
    }

    /**
     * Uma cobrança recuperada por referência não comprova, sozinha, que pertence
     * ao pedido. Validar a identidade remota antes de persistir ou liquidar.
     */
    private static function assertAsaasPaymentMatches(
        array $remote,
        string $customerId,
        string $method,
        float $amount,
        string $externalReference,
    ): void {
        $expectedBillingType = match ($method) {
            'pix' => 'PIX',
            'card' => 'CREDIT_CARD',
            'boleto' => 'BOLETO',
            default => throw new HttpException(422, 'Forma de pagamento inválida.'),
        };

        $value = $remote['value'] ?? null;
        $remoteAmount = is_numeric($value) ? (float) $value : NAN;
        if (! is_string($remote['id'] ?? null) || trim($remote['id']) === ''
            || ! is_string($remote['customer'] ?? null) || trim($remote['customer']) !== $customerId
            || ! is_string($remote['billingType'] ?? null) || strtoupper(trim($remote['billingType'])) !== $expectedBillingType
            || ! is_string($remote['externalReference'] ?? null) || trim($remote['externalReference']) !== $externalReference
            || ! is_finite($remoteAmount)
            || (int) round($remoteAmount * 100) !== (int) round($amount * 100)
            || ! empty($remote['deleted'])) {
            throw new HttpException(409, 'A cobrança retornada pelo Asaas exige verificação antes da retomada.');
        }
    }

    /**
     * O retorno do POST Pix não pode associar ao pedido uma cobrança remota
     * de outro comprador, aplicação ou valor. Validar antes de gravar efeitos.
     */
    private static function assertMercadoPagoPixPaymentMatches(array $remote, CommerceOrder $order): void
    {
        $id = $remote['id'] ?? null;
        $amount = $remote['transaction_amount'] ?? null;
        $remoteAmount = is_numeric($amount) ? (float) $amount : NAN;
        $metadata = $remote['metadata'] ?? null;

        if ((! is_int($id) && ! is_string($id))
            || ! ctype_digit((string) $id)
            || (int) $id < 1
            || ! is_string($remote['external_reference'] ?? null)
            || $remote['external_reference'] !== (string) $order->public_id
            || ! is_string($remote['payment_method_id'] ?? null)
            || strtolower($remote['payment_method_id']) !== 'pix'
            || ! is_string($remote['status'] ?? null)
            || trim($remote['status']) === ''
            || ! is_finite($remoteAmount)
            || (int) round($remoteAmount * 100) !== (int) round((float) $order->total * 100)
            || ! is_array($metadata)
            || (string) ($metadata['application_id'] ?? '') !== (string) $order->app_id
            || (string) ($metadata['order_id'] ?? '') !== (string) $order->id
            || (string) ($metadata['order_public_id'] ?? '') !== (string) $order->public_id
            || (string) ($metadata['organization_id'] ?? '') !== (string) $order->production_id) {
            throw new HttpException(409, 'O PIX retornado pelo provedor exige verificação antes da retomada.');
        }
    }

    public function retryPix(string $publicId, User $user): array
    {
        return $this->retry($publicId, $user, ['payment_method' => 'pix']);
    }

    /**
     * Retomada Asaas é exclusivamente recuperação até o serviço financeiro
     * oferecer busca paginada conclusiva e criação idempotente sob handoff.
     * Consulta inconclusiva, vazia ou ambígua jamais autoriza POST /payments.
     */
    private function findUniqueAsaasRecoveryCandidate(string $externalReference): array
    {
        $payments = $this->asaas->listPaymentsByExternalReference($externalReference);

        if (count($payments) !== 1
            || ! is_string($payments[0]['id'] ?? null)
            || trim($payments[0]['id']) === '') {
            throw new HttpException(409, 'A cobrança Asaas exige conciliação antes de uma nova tentativa.');
        }

        return $payments[0];
    }

    /**
     * Não criar clientes durante a retomada: a identidade do pagador remoto
     * deve ser estável entre a listagem e a consulta por ID.
     */
    private static function assertAsaasRecoveryCustomer(array $candidate, array $remote): string
    {
        $listedCustomer = $candidate['customer'] ?? null;
        $fetchedCustomer = $remote['customer'] ?? null;

        if (! is_string($listedCustomer) || trim($listedCustomer) === ''
            || ! is_string($fetchedCustomer)
            || trim($listedCustomer) !== trim($fetchedCustomer)) {
            throw new HttpException(409, 'A identidade do pagador Asaas exige conciliação antes da retomada.');
        }

        return trim($listedCustomer);
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

        try {
            // Recuperação é somente leitura. findOrCreateCustomer pode emitir
            // POST /customers quando um GET falha ou retorna dados incompletos.
            // O customer deve ser o MESMO nas duas consultas da cobrança.
            $candidate = $this->findUniqueAsaasRecoveryCandidate((string) $order->public_id);

            // Não chamar createOrRecoverPayment: pode criar uma nova cobrança
            // após GET inconclusivo. Consultar exclusivamente o ID encontrado.
            $remote = $this->asaas->getPayment(trim($candidate['id']));
            $customerId = self::assertAsaasRecoveryCustomer($candidate, $remote);

            self::assertAsaasPaymentMatches(
                $candidate,
                $customerId,
                $method,
                (float) $order->total,
                (string) $order->public_id,
            );
            self::assertAsaasPaymentMatches(
                $remote,
                $customerId,
                $method,
                (float) $order->total,
                (string) $order->public_id,
            );

            if (trim((string) $remote['id']) !== trim($candidate['id'])) {
                throw new HttpException(409, 'A identidade da cobrança Asaas mudou durante a consulta.');
            }

            // A indisponibilidade do QR/linha digitável não deve impedir
            // a conciliação de uma cobrança já confirmada pelo provedor.
            try {
                if ($method === 'pix') {
                    $remote['_pix'] = $this->asaas->getPixQrCode((string) $remote['id']);
                } elseif ($method === 'boleto') {
                    $remote['_boleto'] = $this->asaas->getBoletoIdentification((string) $remote['id']);
                }
            } catch (Throwable $exception) {
                report($exception);
            }

            $providerId = trim((string) $remote['id']);

            $remoteValue = (float) $remote['value'];
            $remoteNet = is_numeric($remote['netValue'] ?? null) ? (float) $remote['netValue'] : null;
            $providerFee = $remoteNet !== null ? max(0, round($remoteValue - $remoteNet, 2)) : 0.0;
            $pix = is_array($remote['_pix'] ?? null) ? $remote['_pix'] : [];
            $boleto = is_array($remote['_boleto'] ?? null) ? $remote['_boleto'] : [];
            $localStatus = $this->asaas->normalizeLocalStatus($remote);
            $historicalKey = trim((string) data_get($order->metadata, 'provider_payment_idempotency_key', ''));
            $idempotencyKey = $retryAttempt === 1
                ? ($historicalKey !== '' ? $historicalKey : 'commerce-order-' . $order->public_id)
                : 'commerce-order-' . $order->public_id . '-retry-' . $retryAttempt;

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
        $allowPlatform = (bool) config('services.finance.allow_platform_collection', false)
            || (bool) $this->context->option('commerce.allow_platform_collection', false);
        $platformToken = trim((string) config('services.mercadopago.access_token'));

        if (! $usesMerchant && (! $allowPlatform || $platformToken === '')) {
            throw new HttpException(422, 'Gateway de contingência indisponível.');
        }

        $sellerToken = $usesMerchant ? $this->accounts->freshAccessToken($account)[1] : $platformToken;
        $settlementMode = $usesMerchant ? 'automatic_split' : 'platform_collection';
        // Quando a chamada inicial ao gateway falha antes de persistirmos um
        // pagamento, não sabemos se o provedor já aceitou a operação. Reusar
        // a chave original evita emitir outro PIX para o mesmo pedido.
        // Uma nova chave só é permitida após uma tentativa terminal conhecida.
        $originalKey = trim((string) data_get($order->metadata, 'provider_payment_idempotency_key', ''));
        $idempotencyKey = $retryAttempt === 1
            ? ($originalKey !== '' ? $originalKey : 'commerce-order-' . $order->public_id)
            : 'commerce-order-' . $order->public_id . '-retry-' . $retryAttempt;

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
            $remote = $this->mercadoPago->createPayment($sellerToken, $payload, $idempotencyKey);
        } catch (Throwable $exception) {
            report($exception);
            throw new HttpException(503, 'O provedor de PIX não respondeu. O pedido continua reservado; tente novamente.');
        }

        self::assertMercadoPagoPixPaymentMatches($remote, $order);

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
