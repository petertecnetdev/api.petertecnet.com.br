<?php

namespace App\Domain\Commerce\Http\Controllers;

use App\Domain\Commerce\Services\PendingCheckoutRecoveryService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PendingCheckoutController extends Controller
{
    public function __construct(private readonly ApplicationContext $context, private readonly PendingCheckoutRecoveryService $service) {}

    public function show(Request $request): JsonResponse
    {
        $this->context->requireCapability('commerce');
        $order = $this->service->latest($this->context->id(), (int) $request->user()->id);

        return response()->json([
            'recoverable' => $order !== null,
            'order' => $order,
        ]);
    }

    public function recover(Request $request): JsonResponse
    {
        $this->context->requireCapability('commerce');
        $validated = $request->validate([
            'order_id' => ['required', 'integer', 'min:1'],
        ]);

        $order = $this->service->recover(
            $this->context->id(),
            (int) $request->user()->id,
            (int) $validated['order_id'],
        );

        if (! $order) {
            return response()->json([
                'message' => 'Checkout não está mais disponível para recuperação.',
                'code' => 'CHECKOUT_NOT_RECOVERABLE',
            ], 409);
        }

        return response()->json([
            'recoverable' => true,
            'recovery_started' => true,
            'order' => $order,
        ]);
    }
}
