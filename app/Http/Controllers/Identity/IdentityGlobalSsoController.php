<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\Services\IdentityAuditService;
use App\Domain\Identity\Services\IdentityFeatureService;
use App\Domain\Identity\Services\IdentityGlobalSessionService;
use App\Domain\Identity\Services\IdentitySessionService;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentityGlobalSsoController extends Controller
{
    public function __construct(
        private readonly IdentityGlobalSessionService $globalSessions,
        private readonly IdentitySessionService $sessions,
        private readonly IdentityFeatureService $features,
        private readonly IdentityAuditService $audit,
    ) {
    }

    public function establish(Request $request): JsonResponse
    {
        $data = $request->validate(['application' => ['nullable', 'string', 'max:120']]);
        $user = $request->user('api');
        $application = $this->application($request, $data['application'] ?? null);
        $this->ensureAvailableAndAccessible($user, $application);
        $this->ensureFeatureEnabled($user, $application);
        $this->validateOrigin($request, $application);

        $established = $this->globalSessions->establish($request, $user, $application);
        $this->audit->record('global_sso_established', $user, $request, $application, [
            'created' => (bool) $established['created'],
            'global_session_id' => $established['session']->session_id,
        ]);

        $response = response()->json([
            'success' => true,
            'data' => [
                'session' => $this->globalSessions->present($established['session']),
                'rollout' => 'enabled',
            ],
        ])->withCookie($this->globalSessions->sessionCookie($established['session_token']));

        if ($established['refresh_token']) {
            $response->withCookie($this->globalSessions->refreshCookie($established['refresh_token']));
        }

        return $response;
    }

    public function csrf(Request $request): JsonResponse
    {
        $session = $this->resolveHealthySession($request);
        if (! $session) {
            return $this->unauthorized('Sessão global ausente ou expirada.', 'IDENTITY_SSO_SESSION_MISSING');
        }

        $application = $this->application($request, $request->query('application'));
        $this->ensureAvailableAndAccessible($session->user, $application);
        $this->ensureFeatureEnabled($session->user, $application);
        $this->validateOrigin($request, $application);

        return response()->json([
            'success' => true,
            'data' => [
                'csrf_token' => $this->globalSessions->issueCsrfToken($session, $application, $request),
                'expires_in' => max((int) config('identity.global_sso.csrf_ttl_seconds', 300), 30),
                'protocol_version' => (string) config('identity.protocol.version', '3.0'),
            ],
        ]);
    }

    public function exchange(Request $request): JsonResponse
    {
        $data = $request->validate(['application' => ['nullable', 'string', 'max:120']]);
        $session = $this->resolveHealthySession($request);
        if (! $session) {
            $this->audit->record('global_sso_failed', null, $request, null, ['reason' => 'session_missing']);
            return $this->unauthorized('Sessão global ausente ou expirada.', 'IDENTITY_SSO_SESSION_MISSING');
        }

        $application = $this->application($request, $data['application'] ?? null);
        $this->ensureAvailableAndAccessible($session->user, $application);
        $this->ensureFeatureEnabled($session->user, $application);
        $this->validateOrigin($request, $application);

        $csrf = trim((string) $request->header('X-Peter-CSRF', ''));
        if (! $this->globalSessions->validateCsrfToken($csrf, $session, $application, $request)) {
            $this->audit->record('csrf_rejected', $session->user, $request, $application);
            return response()->json(['success' => false, 'message' => 'Proteção de origem recusou a troca de sessão.', 'code' => 'IDENTITY_CSRF_INVALID'], 419);
        }

        $rotation = $this->globalSessions->rotateRefresh($request, $session);
        if (! $rotation['valid']) {
            $this->globalSessions->revoke($session, 'refresh_rejected');
            $this->audit->record('refresh_rejected', $session->user, $request, $application, [], true);
            return $this->unauthorized('A sessão global precisa ser autenticada novamente.', 'IDENTITY_REFRESH_INVALID');
        }

        $this->globalSessions->touch($session, $request, $application);
        $issued = $this->sessions->issue($session->user, $request, 'global_sso', $application);
        $this->audit->record('global_sso_exchanged', $session->user, $request, $application, [
            'global_session_id' => $session->session_id,
            'application_session_id' => $issued['session']['id'],
            'refresh_rotated' => (bool) $rotation['rotated'],
        ]);

        $response = response()->json([
            'success' => true,
            'data' => array_merge($issued, [
                'user' => $session->user,
                'application' => $application->only(['id', 'name', 'slug', 'url']),
            ]),
        ]);

        if ($rotation['refresh_token']) {
            $response->withCookie($this->globalSessions->refreshCookie($rotation['refresh_token']));
        }

        return $response;
    }

    public function revokeCurrent(Request $request): JsonResponse
    {
        $session = $this->globalSessions->resolve($request);
        if (! $session) {
            return $this->forget(response()->json(['success' => true]));
        }

        $application = $this->application($request, $request->input('application'));
        $this->validateOrigin($request, $application);
        $csrf = trim((string) $request->header('X-Peter-CSRF', ''));
        if (! $this->globalSessions->validateCsrfToken($csrf, $session, $application, $request)) {
            return response()->json(['success' => false, 'message' => 'Proteção CSRF inválida.', 'code' => 'IDENTITY_CSRF_INVALID'], 419);
        }

        $this->globalSessions->revoke($session, 'global_session_logout');
        $this->audit->record('global_session_revoked', $session->user, $request, $application);
        return $this->forget(response()->json(['success' => true]));
    }

    public function logoutEverywhere(Request $request): JsonResponse
    {
        $user = $request->user('api');
        $appSessions = $this->sessions->revokeAll($user, 'global_logout');
        $globalSessions = $this->globalSessions->revokeAll($user, 'global_logout');
        $user->forceFill(['auth_version' => max((int) ($user->auth_version ?? 1), 1) + 1])->save();
        $this->audit->record('global_logout', $user, $request, null, [
            'application_sessions' => $appSessions,
            'global_sessions' => $globalSessions,
        ], true);

        try { auth('api')->logout(); } catch (\Throwable) {}

        return $this->forget(response()->json([
            'success' => true,
            'message' => 'Todas as sessões da Conta Peter Tecnet foram encerradas.',
        ]));
    }

    private function resolveHealthySession(Request $request)
    {
        $session = $this->globalSessions->resolve($request);
        if (! $session) return null;

        if ($this->globalSessions->hasHighRiskContextChange($session, $request)) {
            $this->globalSessions->revoke($session, 'context_changed');
            $this->audit->record('session_risk_detected', $session->user, $request, null, ['risk' => 'browser_or_platform_changed'], true);
            return null;
        }

        if ($this->globalSessions->ipChanged($session, $request)) {
            $this->audit->record('session_ip_changed', $session->user, $request, null, ['previous_ip' => $session->ip_address]);
        }

        return $session;
    }

    private function application(Request $request, mixed $identifier): Application
    {
        $candidate = $identifier ?: $request->header('X-Peter-App') ?: $request->header('X-Peter-Application');
        if ($candidate) {
            $application = Application::query()->where('is_active', true)->where('slug', (string) $candidate)->first();
            if ($application) return $application;
        }

        $originHost = parse_url((string) $request->header('Origin', ''), PHP_URL_HOST);
        $application = Application::query()->where('is_active', true)->get()->first(function (Application $app) use ($originHost) {
            return $originHost && strcasecmp((string) parse_url((string) $app->url, PHP_URL_HOST), (string) $originHost) === 0;
        });

        abort_unless($application, 404, 'Aplicativo não reconhecido.');
        return $application;
    }

    private function ensureAvailableAndAccessible(User $user, Application $application): void
    {
        abort_unless($application->isOperational(), 503, $application->maintenance_message ?: 'Aplicativo temporariamente indisponível.');

        if ($application->self_service_access && ! $user->applications()->where('applications.id', $application->id)->exists()) {
            $user->applications()->syncWithoutDetaching([
                $application->id => ['status' => 'active', 'role' => 'member', 'joined_at' => now()],
            ]);
        }

        abort_unless($user->applications()->where('applications.id', $application->id)
            ->where(fn ($query) => $query->where('application_user.status', 'active')->orWhereNull('application_user.status'))->exists(), 403,
            'Sua Conta Peter Tecnet não possui acesso a este aplicativo.');
    }

    private function ensureFeatureEnabled(User $user, Application $application): void
    {
        if (! $this->features->globalSsoEnabled($user, $application)) {
            abort(response()->json([
                'success' => false,
                'message' => 'SSO global ainda não foi liberado para esta conta neste aplicativo.',
                'code' => 'IDENTITY_SSO_ROLLOUT_DISABLED',
            ], 409));
        }
    }

    private function validateOrigin(Request $request, Application $application): void
    {
        $origin = rtrim((string) $request->header('Origin', ''), '/');
        $expected = rtrim((string) $application->url, '/');
        if ($origin === '' || ! hash_equals(strtolower($expected), strtolower($origin))) {
            abort(response()->json(['success' => false, 'message' => 'Origem não autorizada.', 'code' => 'IDENTITY_ORIGIN_INVALID'], 403));
        }
        if (app()->environment('production') && ! str_starts_with(strtolower($origin), 'https://')) {
            abort(response()->json(['success' => false, 'message' => 'Identity exige HTTPS.', 'code' => 'IDENTITY_HTTPS_REQUIRED'], 403));
        }
    }

    private function unauthorized(string $message, string $code): JsonResponse
    {
        return $this->forget(response()->json(['success' => false, 'message' => $message, 'code' => $code], 401));
    }

    private function forget(JsonResponse $response): JsonResponse
    {
        foreach ($this->globalSessions->forgetCookies() as $cookie) $response->withCookie($cookie);
        return $response;
    }
}
