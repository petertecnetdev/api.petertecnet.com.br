<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PeterTecnetAdminApi
{
    private const ADMIN_EMAIL = 'petertecnet@gmail.com';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('api') ?? $request->user();
        $email = strtolower(trim((string) ($user?->email ?? '')));

        if (!$user || $email !== self::ADMIN_EMAIL) {
            return response()->json([
                'message' => 'Acesso administrativo não autorizado.',
            ], 403);
        }

        return $next($request);
    }
}
