<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminCenterOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('api') ?? $request->user();
        $allowedEmail = strtolower(trim((string) config('admin_center.email')));
        $currentEmail = strtolower(trim((string) ($user?->email ?? '')));

        if (! $user || $allowedEmail === '' || $currentEmail !== $allowedEmail) {
            abort(403, 'Acesso restrito ao administrador da Peter Tecnet.');
        }

        return $next($request);
    }
}
