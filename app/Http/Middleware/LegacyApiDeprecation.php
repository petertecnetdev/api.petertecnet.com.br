<?php

namespace App\Http\Middleware;

use App\Models\ApiUsageRecord;
use App\Models\Application;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class LegacyApiDeprecation
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);
        $response = $next($request);

        $response->headers->set('Deprecation', 'true');
        $response->headers->set('X-Peter-API-Deprecated', 'true');
        $response->headers->set('Link', '<' . url('/developers') . '>; rel="deprecation"');

        try {
            if (Schema::hasTable('api_usage_records')) {
                $segments = $request->segments();
                $candidate = strtolower((string) ($segments[1] ?? ''));
                $applicationId = $candidate !== ''
                    ? Application::query()->where('slug', $candidate)->value('id')
                    : null;

                ApiUsageRecord::create([
                    'api_project_id' => null,
                    'application_id' => $applicationId,
                    'user_id' => $request->user()?->id,
                    'request_id' => $request->attributes->get('request_id'),
                    'method' => $request->method(),
                    'route' => $request->route()?->uri(),
                    'status_code' => $response->getStatusCode(),
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                    'request_bytes' => (int) ($request->server('CONTENT_LENGTH') ?: strlen($request->getContent())),
                    'response_bytes' => strlen((string) $response->getContent()),
                    'environment' => 'legacy',
                    'occurred_at' => now(),
                ]);
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return $response;
    }
}
