<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LegacyApiDeprecation
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Deprecation', 'true');
        $response->headers->set('X-Peter-API-Deprecated', 'true');
        $response->headers->set('Link', '<' . url('/developers') . '>; rel="deprecation"');
        return $response;
    }
}
