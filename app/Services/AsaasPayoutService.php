<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AsaasPayoutService
{
    private Client $client;
    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = trim((string) config('services.asaas.api_key'));
        $baseUrl = rtrim((string) config('services.asaas.base_url', 'https://api.asaas.com/v3'), '/');
        $timeout = max(5, (int) config('services.asaas.timeout', 20));

        $this->client = new Client([
            'base_uri' => $baseUrl . '/',
            'timeout' => $timeout,
            'connect_timeout' => min(10, $timeout),
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => 'PeterTecnet-Finance/1.0',
            ],
        ]);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Asaas não está configurado para repasses Pix.');
        }
    }

    public function lookupPixKey(string $type, string $key): array
    {
        $this->assertConfigured();
        $type = strtoupper(trim($type));
        $key = $this->normalizePixKey($type, $key);

        try {
            $response = $this->client->get('pix/addressKeys/external', [
                'headers' => ['access_token' => $this->apiKey],
                'query' => ['type' => $type, 'key' => $key],
            ]);

            $payload = json_decode($response->getBody()->getContents(), true);
            if (!is_array($payload)) {
                throw new RuntimeException('Resposta inválida ao consultar chave Pix.');
            }

            return $payload;
        } catch (RequestException $e) {
            $this->throwProviderException('Não foi possível validar a chave Pix.', $e);
        }
    }

    public function transferPix(
        string $reference,
        float $amount,
        string $key,
        string $keyType,
        string $description
    ): array {
        $this->assertConfigured();
        $keyType = strtoupper(trim($keyType));
        $key = $this->normalizePixKey($keyType, $key);

        try {
            $response = $this->client->post('transfers', [
                'headers' => ['access_token' => $this->apiKey],
                'json' => [
                    'value' => round($amount, 2),
                    'operationType' => 'PIX',
                    'pixAddressKey' => $key,
                    'pixAddressKeyType' => $keyType,
                    'description' => mb_substr($description, 0, 140),
                    'externalReference' => $reference,
                ],
            ]);

            $payload = json_decode($response->getBody()->getContents(), true);
            if (!is_array($payload) || empty($payload['id'])) {
                throw new RuntimeException('O provedor não retornou uma transferência Pix válida.');
            }

            return $payload;
        } catch (RequestException $e) {
            $this->throwProviderException('Não foi possível enviar o Pix.', $e);
        }
    }

    public function normalizePixKey(string $type, string $key): string
    {
        $type = strtoupper(trim($type));
        $key = trim($key);

        return match ($type) {
            'CPF', 'CNPJ' => preg_replace('/\D+/', '', $key),
            'PHONE' => $this->normalizePhone($key),
            'EMAIL' => mb_strtolower($key),
            'EVP' => mb_strtolower($key),
            default => throw new RuntimeException('Tipo de chave Pix inválido.'),
        };
    }

    private function normalizePhone(string $key): string
    {
        $digits = preg_replace('/\D+/', '', $key);
        if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
            $digits = substr($digits, 2);
        }
        return $digits;
    }

    private function throwProviderException(string $fallback, RequestException $e): never
    {
        $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : null;
        $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : null;
        $decoded = $body ? json_decode($body, true) : null;
        $providerMessage = is_array($decoded)
            ? (string) (data_get($decoded, 'errors.0.description') ?: data_get($decoded, 'errors.0.message') ?: data_get($decoded, 'message', ''))
            : '';

        Log::warning('Falha no provider Asaas.', [
            'status' => $status,
            'message' => $e->getMessage(),
            'provider_message' => $providerMessage,
        ]);

        throw new RuntimeException($providerMessage !== '' ? $providerMessage : $fallback, 0, $e);
    }
}
