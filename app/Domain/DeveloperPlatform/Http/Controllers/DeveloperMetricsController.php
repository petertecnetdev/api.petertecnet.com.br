<?php

namespace App\Domain\DeveloperPlatform\Http\Controllers;

use App\Domain\DeveloperPlatform\Models\ApiClient;
use App\Domain\DeveloperPlatform\Models\ApiRequestLog;
use App\Domain\DeveloperPlatform\Support\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeveloperMetricsController extends Controller
{
    public function show(Request $request, ApiClient $client): JsonResponse
    {
        $this->assertOwned($request, $client);
        $days = min(max((int) $request->input('days', 7), 1), 90);
        $from = now()->subDays($days);
        $base = $client->requestLogs()->where('created_at', '>=', $from);
        $count = (clone $base)->count();

        $topEndpoints = (clone $base)
            ->selectRaw('method, path, COUNT(*) as requests, AVG(duration_ms) as avg_duration_ms')
            ->groupBy('method', 'path')
            ->orderByDesc('requests')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'method' => $row->method,
                'path' => $row->path,
                'requests' => (int) $row->requests,
                'avg_duration_ms' => round((float) $row->avg_duration_ms, 2),
            ]);

        return ApiResponse::data([
            'window_days' => $days,
            'requests' => $count,
            'successes' => (clone $base)->whereBetween('status', [200, 399])->count(),
            'client_errors' => (clone $base)->whereBetween('status', [400, 499])->count(),
            'server_errors' => (clone $base)->whereBetween('status', [500, 599])->count(),
            'rate_limited' => (clone $base)->where('status', 429)->count(),
            'latency_ms' => [
                'average' => round((float) ((clone $base)->avg('duration_ms') ?? 0), 2),
                'p50' => $this->percentile($client->id, $from, 0.50, $count),
                'p95' => $this->percentile($client->id, $from, 0.95, $count),
                'p99' => $this->percentile($client->id, $from, 0.99, $count),
            ],
            'top_endpoints' => $topEndpoints,
        ]);
    }

    public function logs(Request $request, ApiClient $client): JsonResponse
    {
        $this->assertOwned($request, $client);
        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);

        $logs = $client->requestLogs()
            ->latest('created_at')
            ->paginate($perPage)
            ->withQueryString();

        return ApiResponse::paginated($logs, fn (ApiRequestLog $log) => [
            'request_id' => $log->request_id,
            'method' => $log->method,
            'path' => $log->path,
            'status' => $log->status,
            'duration_ms' => $log->duration_ms,
            'created_at' => optional($log->created_at)?->toISOString(),
        ]);
    }

    private function percentile(int $clientId, $from, float $percentile, int $count): int
    {
        if ($count === 0) {
            return 0;
        }

        $offset = max(0, (int) ceil($count * $percentile) - 1);

        return (int) (ApiRequestLog::query()
            ->where('api_client_id', $clientId)
            ->where('created_at', '>=', $from)
            ->orderBy('duration_ms')
            ->offset($offset)
            ->value('duration_ms') ?? 0);
    }

    private function assertOwned(Request $request, ApiClient $client): void
    {
        abort_unless($client->user_id === $request->user()->id, 404);
    }
}
