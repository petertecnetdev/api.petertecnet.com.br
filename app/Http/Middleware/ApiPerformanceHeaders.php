<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class ApiPerformanceHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);
        $queryCount = 0;
        $queryDurationMs = 0.0;

        DB::listen(function ($query) use (&$queryCount, &$queryDurationMs): void {
            $queryCount++;
            $queryDurationMs += (float) $query->time;
        });

        $response = $next($request);
        $totalDurationMs = round((microtime(true) - $startedAt) * 1000, 1);
        $queryDurationMs = round($queryDurationMs, 1);

        $response->headers->set('Server-Timing', sprintf(
            'app;dur=%.1f, db;dur=%.1f, queries;desc="%d"',
            $totalDurationMs,
            $queryDurationMs,
            $queryCount,
        ));

        $path = '/'.ltrim($request->path(), '/');
        $isProductionCreation = $request->isMethod('POST') && preg_match('#^/api/v1/apps/[^/]+/organizations$#', $path) === 1;
        $slowThreshold = $isProductionCreation ? 1200 : 2500;

        if ($totalDurationMs >= $slowThreshold) {
            Log::warning('Slow API request detected.', [
                'request_id' => $request->attributes->get('request_id') ?: $request->header('X-Request-ID'),
                'method' => $request->method(),
                'path' => $path,
                'duration_ms' => $totalDurationMs,
                'db_duration_ms' => $queryDurationMs,
                'query_count' => $queryCount,
                'status' => $response->getStatusCode(),
                'production_creation' => $isProductionCreation,
            ]);
        }

        return $response;
    }
}
