<?php

namespace App\Domain\Commerce\Http\Controllers;

use App\Domain\Commerce\Services\CommerceOrderPaymentRetryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CommerceOrderPaymentRetryController extends Controller
{
    public function __construct(private readonly CommerceOrderPaymentRetryService $payments) {}

    public function store(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate([
            'payment_method' => ['required', 'in:pix,card,boleto'],
            'payer_cpf_cnpj' => ['nullable', 'string', 'max:30'],
            'payer_name' => ['nullable', 'string', 'max:160'],
            'payer_email' => ['nullable', 'email', 'max:190'],
            'card_token' => ['nullable', 'string', 'max:300'],
            'payment_method_id' => ['nullable', 'string', 'max:80'],
            'issuer_id' => ['nullable', 'string', 'max:80'],
            'installments' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);

        [$status, $payload] = $this->payments->retry($publicId, $request->user(), $data);

        return response()->json($payload, $status);
    }
}
