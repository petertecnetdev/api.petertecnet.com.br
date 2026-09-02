<?php

namespace App\Http\Middleware;

use App\Models\ApiUsageRecord;
use App\Support\ApplicationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TrackApiUsage
{
    public function __construct(private readonly ApplicationContext $applicationContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);
        $response = $next($request);

        try {
            $project = $request->attributes->get('api_project');
            $route = $request->route();
            ApiUsageRecord::create([
                'api_project_id' => $project?->id,
                'application_id' => $this->applicationContext->has() ? $this->applicationContext->id() : null,
                'user_id' => $request->user()?->id,
                'request_id' => $request->attributes->get('request_id'),
                'method' => $request->method(),
                'route' => $route?->uri(),
                'status_code' => $response->getStatusCode(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'request_bytes' => (int) ($request->server('CONTENT_LENGTH') ?: strlen($request->getContent())),
                'response_bytes' => strlen((string) $response->getContent()),
                'environment' => $project?->environment ?: app()->environment(),
                'occurred_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }

        return $response;
    }
}
