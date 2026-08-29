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

        // Public API routes are allowed to continue without authentication.
        // Whenever a bearer token is present, security state is enforced globally.
        if (! $user) {
            return $next($request);
        }

        $freshUser = User::query()->find($user->getKey());
        if (! $freshUser) {
            return response()->json(['success'=>false,'message'=>'Usuário não encontrado.','code'=>'USER_NOT_FOUND'], 401);
        }

        if (isset($freshUser->status) && $freshUser->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => $freshUser->status === 'blocked' ? 'Seu acesso foi bloqueado pela administração da Peter Tecnet.' : 'Sua conta não está ativa.',
                'code' => 'ACCOUNT_BLOCKED',
                'request_id' => $request->attributes->get('request_id'),
            ], 403);
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

        $currentVersion = max((int) $freshUser->auth_version, 1);

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
