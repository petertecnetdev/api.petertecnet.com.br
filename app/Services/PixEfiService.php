<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PixEfiService
{
    protected Client $client;
    protected string $clientId;
    protected string $clientSecret;

    public function __construct()
    {
        $baseUrl = (string) config('services.efi.base_url');
        $this->clientId = (string) config('services.efi.client_id');
        $this->clientSecret = (string) config('services.efi.client_secret');
        $certPath = (string) config('services.efi.cert_path');
        $timeout = (int) config('services.efi.timeout', 15);

        if ($baseUrl === '' || $this->clientId === '' || $this->clientSecret === '' || $certPath === '') {
            throw new RuntimeException('Configuração da EFI incompleta.');
        }
        if (! is_file($certPath) || ! is_readable($certPath)) {
            throw new RuntimeException('Certificado EFI não encontrado ou sem permissão de leitura.');
        }

        $this->client = new Client([
            'base_uri' => rtrim($baseUrl, '/'),
            'cert' => $certPath,
            'timeout' => $timeout,
            'connect_timeout' => min($timeout, 10),
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function getAccessToken(): ?string
    {
        try {
            $response = $this->client->post('/oauth/token', [
                'auth' => [$this->clientId, $this->clientSecret],
                'json' => ['grant_type' => 'client_credentials'],
            ]);
            $data = json_decode($response->getBody()->getContents(), true);

            return is_array($data) && is_string($data['access_token'] ?? null)
                ? $data['access_token']
                : null;
        } catch (RequestException $e) {
            Log::error('Erro ao obter token da EFI.', [
                'status' => $e->hasResponse() ? $e->getResponse()->getStatusCode() : null,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function createCharge(string $amount, string $pixKey, ?string $requestMessage = null): array
    {
        $token = $this->getAccessToken();
        if (! $token) {
            throw new RuntimeException('Não foi possível autenticar na EFI.');
        }

        try {
            $response = $this->client->post('/v2/cob', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'json' => [
                    'calendario' => ['expiracao' => 3600],
                    'valor' => ['original' => number_format((float) $amount, 2, '.', '')],
                    'chave' => $pixKey,
                    'solicitacaoPagador' => $requestMessage ?: 'Informe o número ou identificador do pedido.',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            if (! is_array($data)) {
                throw new RuntimeException('Resposta inválida da EFI.');
            }
            return $data;
        } catch (RequestException $e) {
            Log::error('Erro ao criar cobrança PIX na EFI.', [
                'status' => $e->hasResponse() ? $e->getResponse()->getStatusCode() : null,
                'message' => $e->getMessage(),
            ]);
            throw new RuntimeException('Falha ao criar cobrança PIX.', 0, $e);
        }
    }
}
