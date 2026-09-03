<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UseIdentityAccessTokenTtl
{
    public function handle(Request $request, Closure $next): Response
    {
        $factory = auth('api')->factory();
        $previous = $factory->getTTL();
        $factory->setTTL(max((int) config('identity.access_token.ttl_minutes', 30), 5));

        try {
            return $next($request);
        } finally {
            $factory->setTTL($previous);
        }
    }
}
