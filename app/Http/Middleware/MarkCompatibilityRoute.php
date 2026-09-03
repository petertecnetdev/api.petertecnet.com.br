<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
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

        // Preserve old response field names only at the compatibility boundary.
        // Canonical domain controllers remain application-agnostic and expose
        // merchant_connected; legacy clients may still read producer_connected.
        if ($response instanceof JsonResponse) {
            $payload = $response->getData(true);
            $paymentConfig = is_array($payload) ? ($payload['payment_config'] ?? null) : null;

            if (is_array($paymentConfig)
                && ! array_key_exists('producer_connected', $paymentConfig)
                && array_key_exists('merchant_connected', $paymentConfig)) {
                $payload['payment_config']['producer_connected'] = (bool) $paymentConfig['merchant_connected'];
                $response->setData($payload);
            }
        }

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
