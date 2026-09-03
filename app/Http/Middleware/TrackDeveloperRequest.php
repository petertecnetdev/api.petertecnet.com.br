<?php

namespace App\Http\Middleware;

use App\Domain\DeveloperPlatform\Models\ApiClient;
use App\Domain\DeveloperPlatform\Models\ApiRequestLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TrackDeveloperRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);
        $response = $next($request);

        try {
            /** @var ApiClient|null $client */
            $client = $request->attributes->get('developer_client');
            $ip = $request->ip();

            ApiRequestLog::create([
                'api_client_id' => $client?->id,
                'request_id' => $request->attributes->get('request_id'),
                'method' => strtoupper($request->method()),
                'path' => '/' . ltrim($request->path(), '/'),
                'status' => $response->getStatusCode(),
                'duration_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
                'ip_hash' => $ip ? hash_hmac('sha256', $ip, (string) config('app.key')) : null,
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('developer_api.telemetry_failed', [
                'request_id' => $request->attributes->get('request_id'),
                'message' => $exception->getMessage(),
            ]);
        }

        return $response;
    }
}
