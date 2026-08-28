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
            throw new RuntimeException('Configuração da EFI incompleta. Verifique services.efi e as variáveis de ambiente.');
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
                'json' => [
                    'grant_type' => 'client_credentials',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (is_array($data) && isset($data['access_token']) && is_string($data['access_token'])) {
                return $data['access_token'];
            }

            Log::warning('EFI não retornou um access token válido.', [
                'status' => $response->getStatusCode(),
            ]);

            return null;
        } catch (RequestException $e) {
            Log::error('Erro ao obter token de acesso da API EFI.', [
                'message' => $e->getMessage(),
                'status' => $e->hasResponse() ? $e->getResponse()->getStatusCode() : null,
            ]);

            return null;
        }
    }
}
