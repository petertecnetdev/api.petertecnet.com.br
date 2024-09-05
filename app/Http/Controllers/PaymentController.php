<?php

namespace App\Http\Controllers;

use App\Services\PixEfiService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    protected $pixService;

    public function __construct(PixEfiService $pixService)
    {
        $this->pixService = $pixService;
    }

    public function createCharge(Request $request)
    {
        $request->validate([
            'valor' => 'required|numeric',
            'chave' => 'required|string',
        ]);
    
        $token = $this->pixService->getAccessToken();
    
        if (!$token) {
            return response()->json(['error' => 'Falha ao obter token de acesso'], 500);
        }
    
        try {
            $response = $this->pixService->client->post('/v2/cob', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
                'json' => [
                    'calendario' => ['expiracao' => 3600],
                    'valor' => ['original' => $request->valor],
                    'chave' => $request->chave,
                    'solicitacaoPagador' => 'Informar o número ou identificador do pedido.',
                ],
            ]);
    
            return response()->json(json_decode($response->getBody(), true));
        } catch (\Exception $e) {
            Log::error('Erro ao criar cobrança: ' . $e->getMessage());
            return response()->json(['error' => 'Erro ao criar cobrança: ' . $e->getMessage()], 500);
        }
    }
    
    
}
