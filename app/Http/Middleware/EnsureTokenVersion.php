<?php

namespace App\Http\Middleware;

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

        if ($tokenVersion < 1 || $tokenVersion !== (int) ($user->auth_version ?: 1)) {
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
