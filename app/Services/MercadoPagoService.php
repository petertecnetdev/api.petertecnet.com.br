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
        $response = $this->postPayment($sellerAccessToken, $payload, $idempotencyKey);

        if ($response->successful()) {
            return $response->json();
        }

        // Mercado Pago does not allow application_fee when the seller OAuth
        // account is the same account that owns the platform credentials.
        // This is a valid setup for Peter Tecnet's own productions: all funds
        // already settle into the same Mercado Pago account, so there is no
        // marketplace split to perform. Real third-party producers keep using
        // application_fee and automatic split normally.
        if (
            array_key_exists('application_fee', $payload)
            && $this->isApplicationFeeNotAllowed($response->json())
            && $this->sellerIsPlatformAccount($sellerAccessToken)
        ) {
            unset($payload['application_fee']);
            data_set($payload, 'metadata.settlement_mode', 'same_account');

            // The rejected request created no payment. Because the retry has a
            // different payload, use a fresh idempotency key instead of reusing
            // the key tied to the rejected split attempt.
            $retryKey = $idempotencyKey . '-same-account';
            $retry = $this->postPayment($sellerAccessToken, $payload, $retryKey);
            if ($retry->successful()) {
                $result = $retry->json();
                $result['_cutinapp_same_account'] = true;
                return $result;
            }

            throw new RuntimeException('Mercado Pago recusou a criação do pagamento sem split para a conta própria da plataforma: ' . $retry->body());
        }

        throw new RuntimeException('Mercado Pago recusou a criação do pagamento: ' . $response->body());
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

    private function postPayment(string $accessToken, array $payload, string $idempotencyKey)
    {
        return Http::acceptJson()
            ->withToken($accessToken)
            ->withHeaders(['X-Idempotency-Key' => $idempotencyKey])
            ->timeout(20)
            ->post($this->baseUrl . '/v1/payments', $payload);
    }

    private function isApplicationFeeNotAllowed(array $body): bool
    {
        foreach (($body['cause'] ?? []) as $cause) {
            if ((int) ($cause['code'] ?? 0) === 2059) return true;
        }

        return str_contains(strtolower((string) ($body['message'] ?? '')), 'cannot use application_fee');
    }

    private function sellerIsPlatformAccount(string $sellerAccessToken): bool
    {
        $platformAccessToken = trim((string) config('services.mercadopago.access_token'));
        if ($platformAccessToken === '') return false;

        $seller = $this->currentUser($sellerAccessToken);
        $platform = $this->currentUser($platformAccessToken);

        $sellerId = (string) ($seller['id'] ?? '');
        $platformId = (string) ($platform['id'] ?? '');

        return $sellerId !== '' && $platformId !== '' && hash_equals($platformId, $sellerId);
    }

    private function currentUser(string $accessToken): array
    {
        $response = Http::acceptJson()
            ->withToken($accessToken)
            ->timeout(20)
            ->get($this->baseUrl . '/users/me');

        if (!$response->successful()) return [];

        return $response->json();
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
