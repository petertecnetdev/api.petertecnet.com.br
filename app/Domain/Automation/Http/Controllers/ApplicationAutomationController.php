<?php

namespace App\Domain\Automation\Http\Controllers;

use App\Domain\Automation\Services\AutomationControlService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApplicationAutomationController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AutomationControlService $service,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->dashboard($this->context->id()),
        ]);
    }

    public function configure(Request $request, string $key): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'interval_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->service->configure(
                $this->context->id(), $key,
                (bool) $data['enabled'], (int) $data['interval_minutes'],
                (int) $request->user('api')->getKey()
            ),
        ]);
    }

    public function dispatch(Request $request, string $key): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'run_id' => $this->service->dispatchNow(
                    $this->context->id(), $key,
                    (int) $request->user('api')->getKey()
                ),
            ],
        ], 202);
    }
}
