<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EnsureAdminAccess
{
    private const PRIMARY_ADMIN_EMAIL = 'petertecnet@gmail.com';
    private const SUPER_ADMIN_PROFILE = 'Super Admin';

    public function handle(Request $request, Closure $next)
    {
        if (! $request->is('api/admin', 'api/admin/*')) {
            return $next($request);
        }

        if ($request->isMethod('OPTIONS')) {
            return $next($request);
        }

        try {
            $user = $request->user('api');
        } catch (\Throwable $exception) {
            Log::notice('Falha de autenticação em rota administrativa.', [
                'security_event' => 'admin_auth_failed',
                'method' => $request->method(),
                'path' => $request->path(),
                'ip' => $request->ip(),
                'exception' => $exception::class,
            ]);

            return response()->json([
                'message' => 'Não autenticado.',
                'code' => 'ADMIN_AUTH_REQUIRED',
            ], 401);
        }

        if (! $user) {
            return response()->json([
                'message' => 'Não autenticado.',
                'code' => 'ADMIN_AUTH_REQUIRED',
            ], 401);
        }

        $user->loadMissing('profile');

        $email = strtolower(trim((string) $user->email));
        $profileName = trim((string) ($user->profile?->name ?? ''));
        $isPrimaryAdmin = hash_equals(self::PRIMARY_ADMIN_EMAIL, $email);
        $isSuperAdmin = strcasecmp($profileName, self::SUPER_ADMIN_PROFILE) === 0;

        if (! $isPrimaryAdmin && ! $isSuperAdmin) {
            Log::warning('Acesso administrativo negado.', [
                'security_event' => 'admin_access_denied',
                'user_id' => $user->getAuthIdentifier(),
                'email' => $email,
                'profile' => $profileName ?: null,
                'method' => $request->method(),
                'path' => $request->path(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'message' => 'Acesso administrativo não autorizado.',
                'code' => 'ADMIN_ACCESS_DENIED',
            ], 403);
        }

        $request->attributes->set('admin_access_authorized', true);

        return $next($request);
    }
}
