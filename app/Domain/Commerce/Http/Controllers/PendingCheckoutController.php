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
}
