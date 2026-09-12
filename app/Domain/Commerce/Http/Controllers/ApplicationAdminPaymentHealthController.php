<?php

namespace App\Domain\Commerce\Http\Controllers;

use App\Domain\Commerce\Services\PaymentPendingHealthService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;

final class ApplicationAdminPaymentHealthController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly PaymentPendingHealthService $health,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'scope' => 'global_application',
            'data' => $this->health->forApplication($this->context->id()),
        ]);
    }
}
