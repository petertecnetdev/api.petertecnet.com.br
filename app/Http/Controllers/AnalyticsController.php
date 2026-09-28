<?php

namespace App\Http\Controllers;

use App\Services\Analytics\FunnelMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AnalyticsController extends Controller
{
    public function funnel(Request $request, FunnelMetricsService $metrics): JsonResponse
    {
        $data = $request->validate([
            'app_id' => ['required', 'integer', 'exists:applications,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $from = Carbon::parse($data['from'] ?? now()->subDays(30))->startOfDay();
        $to = Carbon::parse($data['to'] ?? now())->endOfDay();

        return response()->json($metrics->summarize((int) $data['app_id'], $from, $to));
    }
}
