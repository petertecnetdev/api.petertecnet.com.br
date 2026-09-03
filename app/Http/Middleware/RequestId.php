<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->headers->get('X-Request-ID');

        if (! is_string($requestId) || ! preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $requestId)) {
            $requestId = (string) Str::uuid();
        }

        $request->attributes->set('request_id', $requestId);
        Log::withContext(['request_id' => $requestId]);
        $startedAt = hrtime(true);

        $response = $next($request);
        $durationMs = round((hrtime(true) - $startedAt) / 1_000_000, 2);

        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('X-Response-Time-Ms', (string) $durationMs);
        $response->headers->set('Server-Timing', "app;dur={$durationMs}");

        if (config('observability.request_logging', true)) {
            Log::info('http_request_completed', [
                'request_id' => $requestId,
                'application_id' => $request->attributes->get('app_id'),
                'application_slug' => $request->attributes->get('application_slug'),
                'route' => $request->route()?->getName(),
                'method' => $request->getMethod(),
                'path' => '/'.ltrim($request->path(), '/'),
                'status' => $response->getStatusCode(),
                'duration_ms' => $durationMs,
            ]);
        }

        return $response;
    }
}
