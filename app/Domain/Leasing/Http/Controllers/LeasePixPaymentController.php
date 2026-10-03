<?php

namespace App\Domain\Leasing\Http\Controllers;

use App\Domain\Leasing\Services\LeasePaymentService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;

final class LeasePixPaymentController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly LeasePaymentService $payments,
    ) {}

    public function profile(Request $request)
    {
        return response()->json($this->payments->receivingProfile(
            $this->context->id(),
            (int) $request->user()->id,
        ));
    }

    public function saveProfile(Request $request)
    {
        $data = $request->validate([
            'pix_key_type' => 'required|in:cpf,cnpj,email,phone,random',
            'pix_key' => 'required|string|max:190',
            'holder_name' => 'required|string|min:2|max:190',
            'merchant_city' => 'required|string|min:2|max:80',
        ]);

        return response()->json($this->payments->saveReceivingProfile(
            $this->context->id(),
            (int) $request->user()->id,
            $data,
        ));
    }

    public function prepare(Request $request, int $leaseId, int $chargeId)
    {
        $data = $request->validate([
            'method' => 'required|in:pix,boleto,card',
        ]);

        return response()->json($this->payments->prepare(
            $this->context->id(),
            $this->context->slug(),
            $request->user(),
            $leaseId,
            $chargeId,
            $data['method'],
        ));
    }
}
