<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

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
        $application = (string) ($request->attributes->get('peter.application_slug')
            ?: $request->attributes->get('application')?->slug
            ?: '');

        $response->headers->set('Deprecation', 'true');
        $response->headers->set('X-Peter-Legacy-Route', 'true');

        if ($application !== '') {
            $response->headers->set('X-Peter-Successor-Base', '/api/v1/apps/'.$application);
        }

        Log::info('api.compatibility_route.used', [
            'application' => $application !== '' ? $application : null,
            'method' => $request->method(),
            'path' => '/'.$request->path(),
            'route_name' => optional($request->route())->getName(),
            'request_id' => $request->attributes->get('request_id'),
            'user_id' => optional($request->user('api'))->getAuthIdentifier(),
        ]);

        return $response;
    }
}
