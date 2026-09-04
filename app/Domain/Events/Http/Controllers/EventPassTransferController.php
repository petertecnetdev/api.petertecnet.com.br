<?php

namespace App\Domain\Events\Http\Controllers;

use App\Domain\Events\Services\EventPassTransferService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EventPassTransferController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly EventPassTransferService $transfers,
    ) {}

    public function transfer(Request $request, int $passId): JsonResponse
    {
        $data = $request->validate([
            'recipient_email' => ['required', 'string', 'email:rfc', 'max:255'],
        ], [
            'recipient_email.required' => 'Informe o e-mail da pessoa que receberá o ingresso.',
            'recipient_email.email' => 'Informe um e-mail válido.',
        ]);

        $result = $this->transfers->transfer(
            $request,
            $this->context->id(),
            $passId,
            (string) $data['recipient_email'],
        );

        return response()->json([
            'message' => 'Ingresso transferido com sucesso. O QR Code anterior foi invalidado.',
            'transfer' => $result,
        ]);
    }
}
