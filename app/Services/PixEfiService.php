<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class PixEfiService
{
    protected $client;
    protected $baseUrl;
    protected $clientId;
    protected $clientSecret;
    protected $certPath;

    public function __construct()
    {
        $this->baseUrl = env('EFI_API_BASE_URL');
        $this->clientId = env('EFI_CLIENT_ID');
        $this->clientSecret = env('EFI_CLIENT_SECRET');
        $this->certPath = env('EFI_CERT_PATH');

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'cert' => $this->certPath, // Certificado no formato .pem
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function getAccessToken()
    {
        try {
            $response = $this->client->post('/oauth/token', [
                'auth' => [$this->clientId, $this->clientSecret],
                'json' => [
                    'grant_type' => 'client_credentials',
                ],
            ]);
    
            $data = json_decode($response->getBody()->getContents(), true);

            // Verifica se o token foi retornado
            if (isset($data['access_token'])) {
                Log::info('Token obtido com sucesso', ['access_token' => $data['access_token']]);
                return $data['access_token'];
            } else {
                Log::warning('Token não encontrado na resposta', $data);
                return null;
            }
        } catch (RequestException $e) {
            // Log detalhado para facilitar a depuração
            Log::error('Erro ao obter token de acesso da API Pix EFI: ' . $e->getMessage(), [
                'response' => $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'Nenhuma resposta',
                'request' => $e->getRequest()->getBody()->getContents()
            ]);
            return null;
        }
    }
}
