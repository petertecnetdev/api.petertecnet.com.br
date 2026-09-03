<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Models\IdentitySession;
use App\Domain\Identity\Services\IdentityAuditService;
use App\Domain\Identity\Services\IdentityRiskService;
use App\Domain\Identity\Services\IdentitySessionService;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTokenVersion
{
    public function __construct(
        private readonly IdentitySessionService $sessions,
        private readonly IdentityRiskService $risk,
        private readonly IdentityAuditService $audit,
    ) {
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
        // do not contain sid and remain valid until their normal JWT expiration or
        // until auth_version is incremented by a security event/global logout.
        if (is_string($sessionId) && $sessionId !== '') {
            $session = IdentitySession::query()
                ->with('application')
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

            if ($this->risk->hasHighRiskContextChange($session, $request)) {
                $this->sessions->revoke($session, 'device_context_changed');
                $this->audit->record('session_context_rejected', $user, $request, $session->application, [
                    'session_id' => $session->session_id,
                    'expected_device' => $session->device_label,
                    'observed_device' => $this->risk->deviceLabel($request->userAgent()),
                ], true);

                return $this->unauthorized(
                    $request,
                    'O contexto desta sessão mudou de forma incompatível. Entre novamente.',
                    'SESSION_CONTEXT_CHANGED'
                );
            }

            $touchInterval = max((int) config('identity.session.touch_interval_minutes', 5), 1);
            if ($this->risk->ipChanged($session, $request)
                && (! $session->last_seen_at || $session->last_seen_at->lte(now()->subMinutes($touchInterval)))) {
                $this->audit->record('session_ip_changed', $user, $request, $session->application, [
                    'session_id' => $session->session_id,
                    'previous_ip' => $session->ip_address,
                ]);
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
