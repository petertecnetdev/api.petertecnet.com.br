<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\TelemetryHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelemetryController extends Controller
{
    public function __construct(private readonly TelemetryHealthService $service)
    {
    }

    public function health(): JsonResponse
    {
        return response()->json($this->service->health());
    }

    public function journeys(Request $request): JsonResponse
    {
        $data = $request->validate([
            'app_id' => ['nullable', 'integer', 'exists:applications,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'from' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return response()->json($this->service->journeys($data));
    }

    public function journey(string $session): JsonResponse
    {
        return response()->json($this->service->journey($session));
    }
}
