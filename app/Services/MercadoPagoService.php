<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class MercadoPagoService
{
    private string $baseUrl = 'https://api.mercadopago.com';

    public function authorizationUrl(string $state): string
    {
        $clientId = $this->clientId();
        $redirect = $this->redirectUri();

        return 'https://auth.mercadopago.com.br/authorization?' . http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'platform_id' => 'mp',
            'redirect_uri' => $redirect,
            'state' => $state,
        ]);
    }

    public function exchangeAuthorizationCode(string $code): array
    {
        return $this->oauthToken([
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
        ]);
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        return $this->oauthToken([
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    public function createPayment(string $sellerAccessToken, array $payload, string $idempotencyKey): array
    {
        $response = Http::acceptJson()
            ->withToken($sellerAccessToken)
            ->withHeaders(['X-Idempotency-Key' => $idempotencyKey])
            ->timeout(20)
            ->post($this->baseUrl . '/v1/payments', $payload);

        if (!$response->successful()) {
            throw new RuntimeException('Mercado Pago recusou a criação do pagamento: ' . $response->body());
        }

        return $response->json();
    }

    public function getPayment(string $sellerAccessToken, string $paymentId): array
    {
        $response = Http::acceptJson()->withToken($sellerAccessToken)->timeout(20)
            ->get($this->baseUrl . '/v1/payments/' . rawurlencode($paymentId));

        if (!$response->successful()) {
            throw new RuntimeException('Não foi possível consultar o pagamento no Mercado Pago.');
        }

        return $response->json();
    }

    public function validateWebhookSignature(?string $xSignature, ?string $xRequestId, ?string $dataId): bool
    {
        $secret = trim((string) config('services.mercadopago.webhook_secret'));
        if ($secret === '' || !$xSignature || !$xRequestId || !$dataId) return false;

        $parts = [];
        foreach (explode(',', $xSignature) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key && $value) $parts[$key] = $value;
        }
        if (empty($parts['ts']) || empty($parts['v1'])) return false;

        $manifest = 'id:' . strtolower($dataId) . ';request-id:' . $xRequestId . ';ts:' . $parts['ts'] . ';';
        $expected = hash_hmac('sha256', $manifest, $secret);
        return hash_equals($expected, $parts['v1']);
    }

    private function oauthToken(array $form): array
    {
        $response = Http::asForm()->acceptJson()->timeout(20)->post($this->baseUrl . '/oauth/token', $form);
        if (!$response->successful()) throw new RuntimeException('Não foi possível concluir a autorização do Mercado Pago: ' . $response->body());
        return $response->json();
    }

    private function clientId(): string
    {
        $value = trim((string) config('services.mercadopago.client_id'));
        if ($value === '') throw new RuntimeException('MERCADOPAGO_CLIENT_ID não configurado.');
        return $value;
    }

    private function clientSecret(): string
    {
        $value = trim((string) config('services.mercadopago.client_secret'));
        if ($value === '') throw new RuntimeException('MERCADOPAGO_CLIENT_SECRET não configurado.');
        return $value;
    }

    private function redirectUri(): string
    {
        $value = trim((string) config('services.mercadopago.redirect_uri'));
        if ($value === '') throw new RuntimeException('MERCADOPAGO_REDIRECT_URI não configurado.');
        return $value;
    }
}
