<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Models\IdentitySession;
use App\Domain\Identity\Services\IdentityAuditService;
use App\Domain\Identity\Services\IdentityDeviceService;
use App\Domain\Identity\Services\IdentitySessionService;
use App\Models\User;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class EnsureTokenVersion
{
    public function __construct(
        private readonly IdentitySessionService $sessions,
        private readonly IdentityDeviceService $devices,
        private readonly IdentityAuditService $audit,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('api');
        if (! $user) return $this->unauthorized($request, 'Não autenticado.', 'UNAUTHENTICATED');

        try {
            $payload = auth('api')->payload();
            $tokenVersion = (int) $payload->get('ver');
            $sessionId = $payload->get('sid');
            $appClaim = $payload->get('app');
        } catch (\Throwable) {
            return $this->unauthorized($request, 'Token inválido.', 'INVALID_TOKEN');
        }

        $currentVersion = max((int) User::query()->whereKey($user->getKey())->value('auth_version'), 1);
        if ($tokenVersion < 1 || $tokenVersion !== $currentVersion) {
            return $this->unauthorized($request, 'Sua sessão expirou por uma alteração de segurança. Faça login novamente.', 'TOKEN_REVOKED');
        }

        if (! is_string($sessionId) || $sessionId === '') {
            return $this->handleLegacyToken($request, $next, $user, is_string($appClaim) ? $appClaim : null);
        }

        $session = IdentitySession::query()->with(['application', 'device', 'user'])
            ->where('session_id', $sessionId)
            ->where('user_id', $user->getKey())
            ->first();

        if (! $session || ! $session->isActive()) {
            return $this->unauthorized($request, 'Esta sessão foi encerrada ou expirou.', 'SESSION_REVOKED');
        }

        if (! $this->devices->sameContext($session->user_agent, $request->userAgent())) {
            $this->sessions->revoke($session, 'context_changed');
            $this->audit->record('session_risk_detected', $user, $request, $session->application, [
                'session_id' => $session->session_id,
                'risk' => 'browser_or_platform_changed',
            ], true);
            return $this->unauthorized($request, 'O contexto deste token mudou. Confirme sua identidade novamente.', 'IDENTITY_REAUTH_REQUIRED');
        }

        if ($session->ip_address && $request->ip() && $session->ip_address !== $request->ip()) {
            $this->auditOnce('ip-change:'.$session->session_id.':'.date('Y-m-d-H'), function () use ($user, $request, $session) {
                $this->audit->record('session_ip_changed', $user, $request, $session->application, [
                    'session_id' => $session->session_id,
                    'previous_ip' => $session->ip_address,
                ]);
            });
        }

        $request->attributes->set('identity_session', $session);
        $this->sessions->touch($session, $request);
        return $next($request);
    }

    private function handleLegacyToken(Request $request, Closure $next, User $user, ?string $app): Response
    {
        $mode = strtolower((string) config('identity.legacy_tokens.mode', 'observe'));
        $sunset = $this->sunset();
        $expired = $sunset && now()->greaterThanOrEqualTo($sunset);
        $reject = $mode === 'enforce' || ($expired && (bool) config('identity.legacy_tokens.reject_after_sunset', false));

        $this->auditOnce('legacy-token:'.$user->id.':'.($app ?: 'unknown').':'.date('Y-m-d'), function () use ($user, $request, $app) {
            $this->audit->record('legacy_token_seen', $user, $request, null, [
                'application_slug' => $app ?: $request->header('X-Peter-App'),
                'path' => $request->path(),
            ]);
        });

        if ($reject) {
            return $this->unauthorized($request, 'Este token usa o formato legado e precisa ser atualizado pelo Peter Identity.', 'LEGACY_TOKEN_RETIRED');
        }

        $response = $next($request);
        $response->headers->set('Deprecation', 'true');
        if ($sunset) $response->headers->set('Sunset', $sunset->toRfc7231String());
        $response->headers->set('X-Peter-Identity-Migration', 'legacy-token-observed');
        return $response;
    }

    private function sunset(): ?Carbon
    {
        $value = config('identity.legacy_tokens.sunset_at');
        if (! $value) return null;
        try { return Carbon::parse((string) $value); } catch (\Throwable) { return null; }
    }

    private function auditOnce(string $key, callable $callback): void
    {
        try {
            if (Cache::add('identity:audit-once:'.hash('sha256', $key), 1, now()->addDay())) $callback();
        } catch (\Throwable) {
            // Observability must never make authentication unavailable.
        }
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
