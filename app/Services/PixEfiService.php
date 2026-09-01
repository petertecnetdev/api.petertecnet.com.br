<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PixEfiService
{
    protected Client $client;
    protected string $clientId;
    protected string $clientSecret;
    protected string $certPath;

    public function __construct()
    {
        $baseUrl = (string) config('services.efi.base_url', 'https://pix.api.efipay.com.br');
        $this->clientId = (string) config('services.efi.client_id');
        $this->clientSecret = (string) config('services.efi.client_secret');
        $this->certPath = (string) config('services.efi.cert_path');
        $timeout = (int) config('services.efi.timeout', 15);

        $options = [
            'base_uri' => rtrim($baseUrl !== '' ? $baseUrl : 'https://pix.api.efipay.com.br', '/'),
            'timeout' => $timeout,
            'connect_timeout' => min($timeout, 10),
            'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
        ];

        if ($this->certPath !== '' && is_file($this->certPath) && is_readable($this->certPath)) {
            $options['cert'] = $this->certPath;
        }

        $this->client = new Client($options);
    }

    private function assertConfigured(): void
    {
        if ($this->clientId === '' || $this->clientSecret === '' || $this->certPath === '') {
            throw new RuntimeException('Configuração da EFI incompleta.');
        }
        if (! is_file($this->certPath) || ! is_readable($this->certPath)) {
            throw new RuntimeException('Certificado EFI não encontrado ou sem permissão de leitura.');
        }
    }

    public function getAccessToken(): ?string
    {
        $this->assertConfigured();
        $cacheKey = 'efi:oauth:' . hash('sha256', $this->clientId);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') return $cached;

        try {
            $response = $this->client->post('/oauth/token', [
                'auth' => [$this->clientId, $this->clientSecret],
                'json' => ['grant_type' => 'client_credentials'],
            ]);
            $data = json_decode($response->getBody()->getContents(), true);
            if (! is_array($data) || ! is_string($data['access_token'] ?? null)) return null;
            $token = $data['access_token'];
            $expiresIn = max((int) ($data['expires_in'] ?? 300), 120);
            Cache::put($cacheKey, $token, now()->addSeconds(max($expiresIn - 60, 60)));
            return $token;
        } catch (RequestException $e) {
            Log::error('Erro ao obter token da EFI.', ['status'=>$e->hasResponse()?$e->getResponse()->getStatusCode():null,'message'=>$e->getMessage()]);
            return null;
        }
    }

    private function token(): string
    {
        $token = $this->getAccessToken();
        if (! $token) throw new RuntimeException('Não foi possível autenticar na EFI.');
        return $token;
    }

    public function createCharge(string $amount, string $pixKey, ?string $requestMessage = null, array $additionalInfo = []): array
    {
        try {
            $body = [
                'calendario' => ['expiracao' => 900],
                'valor' => ['original' => number_format((float) $amount, 2, '.', '')],
                'chave' => $pixKey,
                'solicitacaoPagador' => $requestMessage ?: 'Pagamento Cutinapp.',
            ];
            if ($additionalInfo !== []) $body['infoAdicionais'] = $additionalInfo;
            $response = $this->client->post('/v2/cob', [
                'headers' => ['Authorization' => 'Bearer ' . $this->token()],
                'json' => $body,
            ]);
            $data = json_decode($response->getBody()->getContents(), true);
            if (! is_array($data)) throw new RuntimeException('Resposta inválida da EFI.');
            return $data;
        } catch (RequestException $e) {
            Log::error('Erro ao criar cobrança PIX na EFI.', ['status'=>$e->hasResponse()?$e->getResponse()->getStatusCode():null,'message'=>$e->getMessage()]);
            throw new RuntimeException('Falha ao criar cobrança PIX.', 0, $e);
        }
    }

    public function getQrCode(int|string $locationId): array
    {
        try {
            $response = $this->client->get('/v2/loc/' . rawurlencode((string) $locationId) . '/qrcode', [
                'headers' => ['Authorization' => 'Bearer ' . $this->token()],
            ]);
            $data = json_decode($response->getBody()->getContents(), true);
            if (! is_array($data)) throw new RuntimeException('QR Code PIX inválido.');
            return $data;
        } catch (RequestException $e) {
            throw new RuntimeException('Falha ao obter QR Code PIX.', 0, $e);
        }
    }

    public function sendPix(string $idEnvio, string $amount, string $payerPixKey, string $recipientPixKey, string $description): array
    {
        try {
            $response = $this->client->put('/v3/gn/pix/' . rawurlencode($idEnvio), [
                'headers' => ['Authorization' => 'Bearer ' . $this->token()],
                'json' => [
                    'valor' => number_format((float) $amount, 2, '.', ''),
                    'pagador' => ['chave' => $payerPixKey, 'infoPagador' => $description],
                    'favorecido' => ['chave' => $recipientPixKey],
                ],
            ]);
            $data = json_decode($response->getBody()->getContents(), true);
            if (! is_array($data)) throw new RuntimeException('Resposta inválida ao enviar PIX.');
            return $data;
        } catch (RequestException $e) {
            Log::error('Erro ao enviar PIX pela EFI.', ['idEnvio'=>$idEnvio,'status'=>$e->hasResponse()?$e->getResponse()->getStatusCode():null,'message'=>$e->getMessage()]);
            throw new RuntimeException('Falha ao enviar PIX.', 0, $e);
        }
    }
}
