<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\Payments\PaymentGateway;
use App\Data\Payments\PaymentIntent;
use App\Data\Payments\PaymentProviderResult;
use App\Services\MercadoPagoService;

class MercadoPagoPaymentGateway implements PaymentGateway
{
    public function __construct(private readonly MercadoPagoService $mercadoPago) {}

    public function name(): string
    {
        return 'mercadopago';
    }

    public function isConfigured(): bool
    {
        return trim((string) config('services.mercadopago.access_token')) !== '';
    }

    public function supportedMethods(): array
    {
        return ['pix', 'card'];
    }

    public function initiate(PaymentIntent $intent): PaymentProviderResult
    {
        $token = $this->token();

        if ($intent->method === 'pix') {
            $remote = $this->mercadoPago->createPayment($token, [
                'transaction_amount' => $intent->amount,
                'description' => $intent->description,
                'payment_method_id' => 'pix',
                'external_reference' => $intent->externalReference,
                'notification_url' => $intent->notificationUrl,
                'payer' => [
                    'email' => $intent->payerEmail,
                    'first_name' => $intent->payerFirstName,
                    'last_name' => $intent->payerLastName,
                ],
                'metadata' => $intent->metadata,
            ], $intent->idempotencyKey);

            return $this->normalizePayment($remote);
        }

        abort_unless($intent->method === 'card', 422, 'Forma de pagamento não suportada pelo provedor.');

        $remote = $this->mercadoPago->createPreference($token, [
            'items' => $intent->items,
            'payer' => ['email' => $intent->payerEmail],
            'external_reference' => $intent->externalReference,
            'notification_url' => $intent->notificationUrl,
            'back_urls' => [
                'success' => $intent->returnUrl,
                'pending' => $intent->returnUrl,
                'failure' => $intent->returnUrl,
            ],
            'auto_return' => 'approved',
            'payment_methods' => [
                'excluded_payment_methods' => [['id' => 'pix'], ['id' => 'account_money']],
                'excluded_payment_types' => [['id' => 'ticket'], ['id' => 'bank_transfer']],
            ],
            'metadata' => $intent->metadata,
        ], $intent->idempotencyKey);

        return new PaymentProviderResult(
            providerPaymentId: null,
            status: 'pending',
            providerStatus: 'preference_created',
            externalReference: $intent->externalReference,
            metadata: [
                'preference_id' => $remote['id'] ?? null,
                'checkout_url' => $remote['init_point'] ?? null,
                'sandbox_checkout_url' => $remote['sandbox_init_point'] ?? null,
            ],
        );
    }

    public function retrieve(?string $providerPaymentId, string $externalReference): ?PaymentProviderResult
    {
        $token = $this->token();

        $remote = $providerPaymentId
            ? $this->mercadoPago->getPayment($token, $providerPaymentId)
            : $this->mercadoPago->findPaymentByExternalReference($token, $externalReference);

        return $remote ? $this->normalizePayment($remote) : null;
    }

    public function retrieveById(string $providerPaymentId): PaymentProviderResult
    {
        return $this->normalizePayment(
            $this->mercadoPago->getPayment($this->token(), $providerPaymentId)
        );
    }

    public function validateWebhook(array $headers, string $resourceId): bool
    {
        return $this->mercadoPago->validateWebhookSignature(
            $headers['x-signature'] ?? null,
            $headers['x-request-id'] ?? null,
            $resourceId,
        );
    }

    private function normalizePayment(array $remote): PaymentProviderResult
    {
        $providerStatus = (string) ($remote['status'] ?? '');
        $status = match ($providerStatus) {
            'approved' => 'paid',
            'refunded', 'charged_back' => 'refunded',
            'rejected', 'cancelled' => 'failed',
            default => 'pending',
        };

        $transaction = $remote['point_of_interaction']['transaction_data'] ?? [];
        $providerFee = (float) collect($remote['fee_details'] ?? [])->sum('amount');

        return new PaymentProviderResult(
            providerPaymentId: isset($remote['id']) ? (string) $remote['id'] : null,
            status: $status,
            providerStatus: $providerStatus ?: null,
            providerFee: $providerFee,
            externalReference: isset($remote['external_reference']) ? (string) $remote['external_reference'] : null,
            metadata: [
                'qr_code' => $transaction['qr_code'] ?? null,
                'qr_code_base64' => $transaction['qr_code_base64'] ?? null,
                'ticket_url' => $transaction['ticket_url'] ?? null,
            ],
        );
    }

    private function token(): string
    {
        $token = trim((string) config('services.mercadopago.access_token'));
        abort_if($token === '', 503, 'Provedor de pagamento não configurado.');

        return $token;
    }
}
