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
        $request->validate(['payment_method' => ['required', 'in:pix']]);
        [$status, $payload] = $this->payments->retryPix($publicId, $request->user());
        return response()->json($payload, $status);
    }
}
