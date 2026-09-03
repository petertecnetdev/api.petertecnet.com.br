<?php

namespace App\Http\Middleware;

use App\Models\Interaction;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Temporary expand/contract boundary for legacy API URLs.
 *
 * Business logic must never live here. Legacy routes are allowed to select an
 * application context, but they always execute the same generic domain
 * controllers used by /api/v1/apps/{application}.
 */
final class MarkCompatibilityRoute
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $applicationModel = $request->attributes->get('application');
        $application = (string) ($request->attributes->get('peter.application_slug')
            ?: $applicationModel?->slug
            ?: '');
        $successor = $application !== '' ? '/api/v1/apps/'.$application : null;

        $response->headers->set('Deprecation', 'true');
        $response->headers->set('X-Peter-Legacy-Route', 'true');

        if ($successor) {
            $response->headers->set('X-Peter-Successor-Base', $successor);
        }

        $telemetry = [
            'application' => $application !== '' ? $application : null,
            'method' => $request->method(),
            'path' => '/'.$request->path(),
            'route' => optional($request->route())->uri(),
            'route_name' => optional($request->route())->getName(),
            'successor_base' => $successor,
            'status' => $response->getStatusCode(),
            'request_id' => $request->attributes->get('request_id'),
            'user_id' => optional($request->user('api'))->getAuthIdentifier(),
        ];

        Log::info('api.compatibility_route.used', $telemetry);

        try {
            Interaction::query()->create([
                'user_id' => $telemetry['user_id'],
                'app_id' => $applicationModel?->id,
                'entity_type' => 'Route',
                'interaction_type' => 'compatibility_route',
                'outcome' => $response->getStatusCode() < 400 ? 'success' : 'error',
                'severity' => $response->getStatusCode() >= 500 ? 'error' : ($response->getStatusCode() >= 400 ? 'warning' : 'info'),
                'route' => $telemetry['route'] ?: $telemetry['path'],
                'method' => $request->method(),
                'request_id' => $telemetry['request_id'],
                'name' => 'Uso de rota de compatibilidade',
                'content' => [
                    'legacy_path' => $telemetry['path'],
                    'successor_base' => $successor,
                    'status' => $response->getStatusCode(),
                    'source_channel' => 'compatibility',
                ],
            ]);
        } catch (Throwable $exception) {
            // Observability must never break a business request.
            Log::warning('api.compatibility_route.telemetry_failed', [
                'message' => $exception->getMessage(),
                'request_id' => $telemetry['request_id'],
            ]);
        }

        return $response;
    }
}
