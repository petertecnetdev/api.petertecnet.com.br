<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class MercadoPagoService
{
    private string $baseUrl = 'https://api.mercadopago.com';

    public function authorizationUrl(string $state): string
    {
        return 'https://auth.mercadopago.com.br/authorization?' . http_build_query([
            'client_id' => $this->clientId(),
            'response_type' => 'code',
            'platform_id' => 'mp',
            'redirect_uri' => $this->redirectUri(),
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

        if (
            array_key_exists('application_fee', $payload)
            && $this->isApplicationFeeNotAllowed($response->json())
            && $this->sellerIsPlatformAccount($sellerAccessToken)
        ) {
            unset($payload['application_fee']);
            data_set($payload, 'metadata.settlement_mode', 'same_account');

            $retry = $this->postPayment($sellerAccessToken, $payload, $idempotencyKey . '-same-account');
            if ($retry->successful()) {
                $result = $retry->json();
                $result['_same_account'] = true;
                $result['_cutinapp_same_account'] = true;
                return $result;
            }

            throw new RuntimeException('Mercado Pago recusou a criação do pagamento sem split para a conta própria da plataforma: ' . $retry->body());
        }

        throw new RuntimeException('Mercado Pago recusou a criação do pagamento: ' . $response->body());
    }

    public function createPreference(string $accessToken, array $payload, string $idempotencyKey): array
    {
        $response = Http::acceptJson()
            ->withToken($accessToken)
            ->withHeaders(['X-Idempotency-Key' => $idempotencyKey])
            ->timeout(20)
            ->post($this->baseUrl . '/checkout/preferences', $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Mercado Pago recusou a criação do checkout: ' . $response->body());
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

    public function findPaymentByExternalReference(string $accessToken, string $externalReference): ?array
    {
        $response = Http::acceptJson()->withToken($accessToken)->timeout(20)
            ->get($this->baseUrl . '/v1/payments/search', [
                'external_reference' => $externalReference,
                'sort' => 'date_created',
                'criteria' => 'desc',
                'limit' => 1,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Não foi possível sincronizar o pagamento no Mercado Pago.');
        }

        $results = $response->json('results', []);
        return is_array($results) && isset($results[0]) && is_array($results[0]) ? $results[0] : null;
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

    public function getReleaseReportConfiguration(string $accessToken): ?array
    {
        $response = Http::acceptJson()->withToken($accessToken)->timeout(20)
            ->get($this->baseUrl . '/v1/account/release_report/config');

        if ($response->status() === 404) return null;
        if (! $response->successful()) {
            throw new RuntimeException('Não foi possível consultar a configuração do relatório de liberações do Mercado Pago.');
        }

        return $response->json();
    }

    public function createReleaseReportConfiguration(string $accessToken, array $payload): array
    {
        $response = Http::acceptJson()->withToken($accessToken)->timeout(20)
            ->post($this->baseUrl . '/v1/account/release_report/config', $payload);

        if ($response->status() === 409) {
            return $this->getReleaseReportConfiguration($accessToken) ?? [];
        }
        if (! $response->successful()) {
            throw new RuntimeException('Não foi possível configurar o relatório de liberações do Mercado Pago: ' . $response->body());
        }

        return $response->json();
    }

    public function requestReleaseReport(string $accessToken, string $beginDate, string $endDate): array
    {
        $response = Http::acceptJson()->withToken($accessToken)->timeout(25)
            ->post($this->baseUrl . '/v1/account/release_report', [
                'begin_date' => $beginDate,
                'end_date' => $endDate,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Não foi possível solicitar o relatório de liberações do Mercado Pago: ' . $response->body());
        }

        return $response->json();
    }

    public function getReleaseReportTask(string $accessToken, string $taskId): array
    {
        $response = Http::acceptJson()->withToken($accessToken)->timeout(20)
            ->get($this->baseUrl . '/v1/account/release_report/task/' . rawurlencode($taskId));

        if (! $response->successful()) {
            throw new RuntimeException('Não foi possível consultar a tarefa do relatório de liberações do Mercado Pago.');
        }

        return $response->json();
    }

    public function searchReleaseReport(string $accessToken, ?string $reportId = null, ?string $fileName = null): ?array
    {
        $query = array_filter(['id' => $reportId, 'file_name' => $fileName], fn ($value) => $value !== null && $value !== '');
        if ($query === []) return null;

        $response = Http::acceptJson()->withToken($accessToken)->timeout(20)
            ->get($this->baseUrl . '/v1/account/release_report/search', $query);

        if ($response->status() === 404) return null;
        if (! $response->successful()) {
            throw new RuntimeException('Não foi possível localizar o relatório de liberações do Mercado Pago.');
        }

        $json = $response->json();
        if (isset($json['results'][0]) && is_array($json['results'][0])) return $json['results'][0];
        if (isset($json['data'][0]) && is_array($json['data'][0])) return $json['data'][0];
        return is_array($json) && (isset($json['id']) || isset($json['file_name'])) ? $json : null;
    }

    public function downloadReleaseReport(string $accessToken, string $fileName): string
    {
        $response = Http::withToken($accessToken)->timeout(30)
            ->get($this->baseUrl . '/v1/account/release_report/' . rawurlencode($fileName));

        if (! $response->successful()) {
            throw new RuntimeException('Não foi possível baixar o relatório de liberações do Mercado Pago.');
        }

        return $response->body();
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
        $response = Http::acceptJson()->withToken($accessToken)->timeout(20)
            ->get($this->baseUrl . '/users/me');

        return $response->successful() ? $response->json() : [];
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
