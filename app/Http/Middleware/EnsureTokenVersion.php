<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTokenVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('api');

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Não autenticado.',
                'code' => 'UNAUTHENTICATED',
                'request_id' => $request->attributes->get('request_id'),
            ], 401);
        }

        try {
            $payload = auth('api')->payload();
            $tokenVersion = (int) $payload->get('ver');
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token inválido.',
                'code' => 'INVALID_TOKEN',
                'request_id' => $request->attributes->get('request_id'),
            ], 401);
        }

        // Always compare against a fresh database value. The JWT guard may keep
        // the authenticated model in memory for the lifetime of the request or
        // test process, which must never allow an already-revoked token through.
        $currentVersion = (int) User::query()
            ->whereKey($user->getKey())
            ->value('auth_version');
        $currentVersion = max($currentVersion, 1);

        if ($tokenVersion < 1 || $tokenVersion !== $currentVersion) {
            return response()->json([
                'success' => false,
                'message' => 'Sua sessão expirou por uma alteração de segurança. Faça login novamente.',
                'code' => 'TOKEN_REVOKED',
                'request_id' => $request->attributes->get('request_id'),
            ], 401);
        }

        return $next($request);
    }
}
