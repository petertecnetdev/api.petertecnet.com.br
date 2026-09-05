<?php

namespace App\Http\Middleware;

use App\Models\Profile;
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

        $email = strtolower(trim((string) $user->email));
        $profileName = trim((string) ($user->profile?->name ?? ''));

        // Resolve the persisted profile as the source of truth when possible.
        // This avoids authorizing against a stale relationship cached on a JWT user instance.
        $profileId = (int) ($user->profile_id ?? 0);
        if ($profileId > 0) {
            $persistedProfileName = Profile::query()->whereKey($profileId)->value('name');
            if (is_string($persistedProfileName) && trim($persistedProfileName) !== '') {
                $profileName = trim($persistedProfileName);
            }
        }

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
