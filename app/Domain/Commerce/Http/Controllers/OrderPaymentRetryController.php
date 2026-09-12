<?php

namespace App\Domain\Commerce\Http\Controllers;

use App\Domain\Commerce\Services\OrderPaymentRetryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderPaymentRetryController extends Controller
{
    public function __construct(
        private readonly OrderPaymentRetryService $payments,
    ) {}

    public function store(Request $request, int $order): JsonResponse
    {
        $request->validate([
            'payment_method' => ['required', 'in:pix'],
        ]);

        [$status, $payload] = $this->payments->retryPix($order, $request->user());

        return response()->json($payload, $status);
    }
}
