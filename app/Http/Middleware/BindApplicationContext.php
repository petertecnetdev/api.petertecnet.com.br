<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class BindApplicationContext
{
    public function handle(Request $request, Closure $next, string $applicationSlug)
    {
        $request->attributes->set('peter.application_slug', mb_strtolower(trim($applicationSlug)));

        return $next($request);
    }
}
