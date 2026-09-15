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
            'payment_method' => ['required', 'in:pix,card'],
            'card_token' => ['required_if:payment_method,card', 'nullable', 'string', 'max:300'],
            'payment_method_id' => ['required_if:payment_method,card', 'nullable', 'string', 'max:80'],
            'issuer_id' => ['nullable', 'string', 'max:80'],
            'installments' => ['required_if:payment_method,card', 'nullable', 'integer', 'min:1', 'max:24'],
            'payer_identification_type' => ['required_if:payment_method,card', 'nullable', 'string', 'in:CPF'],
            'payer_identification_number' => ['required_if:payment_method,card', 'nullable', 'string', 'max:30'],
            'payer_email' => ['nullable', 'email', 'max:190'],
        ]);

        if ($data['payment_method'] === 'card') {
            $document = preg_replace('/\D+/', '', (string) ($data['payer_identification_number'] ?? ''));
            abort_if(strlen($document) !== 11, 422, 'Informe um CPF válido para o titular do cartão.');
            $data['payer_identification_number'] = $document;
        }

        [$status, $payload] = $this->payments->retry($publicId, $request->user(), $data);
        return response()->json($payload, $status);
    }
}
