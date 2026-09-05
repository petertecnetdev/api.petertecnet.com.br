<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\ApiLogInsightsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApiLogInsightsController extends Controller
{
    public function __invoke(Request $request, ApiLogInsightsService $logs): JsonResponse
    {
        $validated = $request->validate([
            'range' => ['nullable', Rule::in(['1h', '24h', '7d', '30d'])],
        ]);

        return response()->json($logs->summarize($validated['range'] ?? '24h'));
    }
}
