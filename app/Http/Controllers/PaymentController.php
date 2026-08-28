<?php

namespace App\Http\Controllers;

use App\Services\PixEfiService;
use Illuminate\Http\Request;
use RuntimeException;

class PaymentController extends Controller
{
    public function __construct(private PixEfiService $pixService)
    {
    }

    public function createCharge(Request $request)
    {
        $user = $request->user();

        abort_unless(
            $user && (
                $user->hasProfile('Administrador')
                || $user->hasPermission('payment_create')
                || $user->establishments()->exists()
            ),
            403,
            'Você não tem permissão para criar cobranças.'
        );

        $data = $request->validate([
            'valor' => 'required|numeric|min:0.01|max:99999999.99',
            'chave' => 'required|string|max:255',
            'mensagem' => 'nullable|string|max:140',
        ]);

        try {
            $charge = $this->pixService->createCharge(
                (string) $data['valor'],
                $data['chave'],
                $data['mensagem'] ?? null
            );

            return response()->json($charge, 201);
        } catch (RuntimeException $e) {
            report($e);

            return response()->json([
                'error' => 'Não foi possível criar a cobrança PIX neste momento.',
            ], 502);
        }
    }
}
