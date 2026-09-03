<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Models\IdentitySession;
use App\Domain\Identity\Services\IdentitySessionService;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTokenVersion
{
    public function __construct(private readonly IdentitySessionService $sessions)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('api');

        if (! $user) {
            return $this->unauthorized($request, 'Não autenticado.', 'UNAUTHENTICATED');
        }

        try {
            $payload = auth('api')->payload();
            $tokenVersion = (int) $payload->get('ver');
            $sessionId = $payload->get('sid');
        } catch (\Throwable) {
            return $this->unauthorized($request, 'Token inválido.', 'INVALID_TOKEN');
        }

        $currentVersion = (int) User::query()
            ->whereKey($user->getKey())
            ->value('auth_version');
        $currentVersion = max($currentVersion, 1);

        if ($tokenVersion < 1 || $tokenVersion !== $currentVersion) {
            return $this->unauthorized(
                $request,
                'Sua sessão expirou por uma alteração de segurança. Faça login novamente.',
                'TOKEN_REVOKED'
            );
        }

        // Compatibility boundary: legacy tokens issued before centralized sessions
        // do not contain sid and remain valid until their normal JWT expiration.
        if (is_string($sessionId) && $sessionId !== '') {
            $session = IdentitySession::query()
                ->where('session_id', $sessionId)
                ->where('user_id', $user->getKey())
                ->first();

            if (! $session || ! $session->isActive()) {
                return $this->unauthorized(
                    $request,
                    'Esta sessão foi encerrada ou expirou.',
                    'SESSION_REVOKED'
                );
            }

            $request->attributes->set('identity_session', $session);
            $this->sessions->touch($session, $request);
        }

        return $next($request);
    }

    private function unauthorized(Request $request, string $message, string $code): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'code' => $code,
            'request_id' => $request->attributes->get('request_id'),
        ], 401);
    }
}
