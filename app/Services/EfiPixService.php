<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class EfiPixService
{
    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private string $certPath;
    private ?string $certPassword;
    private string $pixKey;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.efi.base_url'), '/');
        $this->clientId = (string) config('services.efi.client_id');
        $this->clientSecret = (string) config('services.efi.client_secret');
        $this->certPath = (string) config('services.efi.cert_path');
        $this->certPassword = config('services.efi.cert_password');
        $this->pixKey = (string) config('services.efi.pix_key');
        $this->timeout = (int) config('services.efi.timeout', 15);
    }

    public function configured(): bool
    {
        return $this->baseUrl !== '' && $this->clientId !== '' && $this->clientSecret !== '' && $this->certPath !== '' && $this->pixKey !== '';
    }

    private function certOption()
    {
        if ($this->certPassword) return [$this->certPath, $this->certPassword];
        return $this->certPath;
    }

    private function client()
    {
        if (!$this->configured()) {
            throw new RuntimeException('Pagamento PIX Efí não está configurado no servidor.');
        }

        return Http::timeout($this->timeout)
            ->acceptJson()
            ->asJson()
            ->withOptions(['cert' => $this->certOption()]);
    }

    public function accessToken(): string
    {
        return Cache::remember('efi_pix_access_token', now()->addMinutes(45), function () {
            $response = $this->client()
                ->withBasicAuth($this->clientId, $this->clientSecret)
                ->post($this->baseUrl . '/oauth/token', ['grant_type' => 'client_credentials']);

            if (!$response->successful() || !$response->json('access_token')) {
                throw new RuntimeException('Não foi possível autenticar na Efí: ' . $response->body());
            }

            return (string) $response->json('access_token');
        });
    }

    private function authorized()
    {
        return $this->client()->withToken($this->accessToken());
    }

    public function createImmediateCharge(float $amount, string $payerName, ?string $document, string $description): array
    {
        if ($amount <= 0) throw new RuntimeException('O valor da cobrança PIX deve ser maior que zero.');

        $txid = strtoupper(Str::random(30));
        $payload = [
            'calendario' => ['expiracao' => 1800],
            'valor' => ['original' => number_format($amount, 2, '.', '')],
            'chave' => $this->pixKey,
            'solicitacaoPagador' => Str::limit($description, 140, ''),
        ];

        $cleanDocument = preg_replace('/\D+/', '', (string) $document);
        if ($payerName && strlen($cleanDocument) === 11) {
            $payload['devedor'] = ['cpf' => $cleanDocument, 'nome' => Str::limit($payerName, 200, '')];
        } elseif ($payerName && strlen($cleanDocument) === 14) {
            $payload['devedor'] = ['cnpj' => $cleanDocument, 'nome' => Str::limit($payerName, 200, '')];
        }

        $charge = $this->authorized()->put($this->baseUrl . '/v2/cob/' . $txid, $payload);
        if (!$charge->successful()) {
            throw new RuntimeException('Não foi possível criar a cobrança PIX: ' . $charge->body());
        }

        $chargeData = $charge->json();
        $locId = data_get($chargeData, 'loc.id');
        if (!$locId) throw new RuntimeException('A Efí não retornou a localização da cobrança PIX.');

        $qr = $this->authorized()->get($this->baseUrl . '/v2/loc/' . $locId . '/qrcode');
        if (!$qr->successful()) {
            throw new RuntimeException('Cobrança criada, mas não foi possível gerar o QR Code PIX: ' . $qr->body());
        }

        return [
            'txid' => $txid,
            'status' => data_get($chargeData, 'status'),
            'expires_in' => data_get($chargeData, 'calendario.expiracao', 1800),
            'location_id' => $locId,
            'pix_copy_paste' => $qr->json('qrcode'),
            'qr_image' => $qr->json('imagemQrcode'),
            'payment_link' => $qr->json('linkVisualizacao'),
            'raw_charge' => $chargeData,
        ];
    }

    public function getCharge(string $txid): array
    {
        $response = $this->authorized()->get($this->baseUrl . '/v2/cob/' . $txid);
        if (!$response->successful()) throw new RuntimeException('Não foi possível consultar a cobrança PIX: ' . $response->body());
        return $response->json();
    }
}
