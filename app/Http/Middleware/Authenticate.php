<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    /**
     * Every auth:api route must honor User::auth_version. This makes password
     * changes and global logout revoke already-issued JWTs across the ecosystem,
     * instead of relying on individual applications to remember token.version.
     */
    public function handle($request, Closure $next, ...$guards)
    {
        return parent::handle($request, function ($request) use ($next, $guards) {
            if (in_array('api', $guards, true)) {
                return app(EnsureTokenVersion::class)->handle($request, $next);
            }

            return $next($request);
        }, ...$guards);
    }

    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo($request)
    {
        if (! $request->expectsJson()) {
            return route('login');
        }
    }
}
