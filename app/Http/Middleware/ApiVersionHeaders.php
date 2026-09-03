<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiVersionHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Peter-API-Version', '1');
        $response->headers->set('X-Peter-API-Environment', $request->is('api/sandbox/v1*') ? 'sandbox' : 'production');
        $response->headers->set('X-API-Deprecated', 'false');

        return $response;
    }
}
