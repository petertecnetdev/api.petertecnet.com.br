<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\Models\IdentityGlobalSession;
use App\Domain\Identity\Services\IdentityAuditService;
use App\Domain\Identity\Services\IdentityGlobalSessionService;
use App\Domain\Identity\Services\IdentitySessionService;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class IdentityGlobalSsoController extends Controller
{
    public function __construct(
        private readonly IdentityGlobalSessionService $globalSessions,
        private readonly IdentitySessionService $appSessions,
        private readonly IdentityAuditService $audit,
    ) {
    }

    public function establish(Request $request): JsonResponse
    {
        $data = $request->validate([
            'application' => ['required', 'string', 'max:120'],
        ]);
        $application = $this->application($data['application']);
        $this->ensureRequestMatchesApplication($request, $application);
        $this->ensureAvailable($application);

        $user = $request->user('api');
        $this->ensureSelfServiceMembership($user, $application);
        $this->ensureAccess($user, $application);

        $issued = $this->globalSessions->establish($request, $user);
        $session = $issued['session'];

        $this->audit->record(
            $issued['created'] ? 'global_session_created' : 'global_session_refreshed',
            $user,
            $request,
            $application,
            ['global_session_id' => $session->session_id, 'device' => $session->device_label]
        );

        $response = response()->json([
            'success' => true,
            'data' => [
                'authenticated' => true,
                'expires_in' => max(0, now()->diffInSeconds($session->expires_at, false)),
                'session' => $this->globalSessions->present($session),
            ],
        ])->withCookie($this->globalSessions->sessionCookie($issued['session_token']));

        if ($issued['refresh_token']) {
            $response->withCookie($this->globalSessions->refreshCookie($issued['refresh_token']));
        }

        return $response;
    }

    public function csrf(Request $request): JsonResponse|Response
    {
        $data = $request->validate([
            'application' => ['required', 'string', 'max:120'],
        ]);
        $application = $this->application($data['application']);
        $this->ensureRequestMatchesApplication($request, $application);

        $session = $this->globalSessions->resolve($request);
        if (! $session) {
            return $this->emptyWithForgottenCookies();
        }

        if ($this->globalSessions->hasHighRiskContextChange($session, $request)) {
            $this->globalSessions->revoke($session, 'device_context_changed');
            $this->audit->record('global_session_context_rejected', $session->user, $request, $application, [
                'global_session_id' => $session->session_id,
                'expected_device' => $session->device_label,
            ], true);
            return $this->emptyWithForgottenCookies();
        }

        if ($this->globalSessions->ipChanged($session, $request)) {
            $this->audit->record('global_session_ip_changed', $session->user, $request, $application, [
                'global_session_id' => $session->session_id,
                'previous_ip' => $session->ip_address,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'csrf_token' => $this->globalSessions->issueCsrfToken($session, $application, $request),
                'expires_in' => (int) config('identity.global_sso.csrf_ttl_seconds', 300),
            ],
        ]);
    }

    public function exchange(Request $request): JsonResponse|Response
    {
        $data = $request->validate([
            'application' => ['required', 'string', 'max:120'],
        ]);
        $application = $this->application($data['application']);
        $this->ensureRequestMatchesApplication($request, $application);
        $this->ensureAvailable($application);

        $session = $this->globalSessions->resolve($request);
        if (! $session || ! $session->user) {
            return $this->emptyWithForgottenCookies();
        }

        if ($this->globalSessions->hasHighRiskContextChange($session, $request)) {
            $this->globalSessions->revoke($session, 'device_context_changed');
            $this->audit->record('global_session_context_rejected', $session->user, $request, $application, [
                'global_session_id' => $session->session_id,
            ], true);
            return $this->emptyWithForgottenCookies();
        }

        $csrf = (string) $request->header('X-Peter-CSRF', '');
        if (! $this->globalSessions->validateCsrfToken($csrf, $session, $application, $request)) {
            $this->audit->record('global_session_csrf_rejected', $session->user, $request, $application, [
                'global_session_id' => $session->session_id,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'A validação de segurança da sessão expirou. Tente novamente.',
                'code' => 'IDENTITY_CSRF_INVALID',
            ], 419);
        }

        $rotation = $this->globalSessions->rotateRefresh($request, $session);
        if (! $rotation['valid']) {
            $this->globalSessions->revoke($session, 'refresh_token_rejected');
            $this->audit->record('global_session_refresh_rejected', $session->user, $request, $application, [
                'global_session_id' => $session->session_id,
            ], true);
            return $this->emptyWithForgottenCookies();
        }

        $user = $session->user;
        $this->ensureSelfServiceMembership($user, $application);
        $this->ensureAccess($user, $application);
        $this->globalSessions->touch($session, $request);

        $issued = $this->appSessions->issue($user, $request, 'sso', $application);
        $this->audit->record('global_sso_exchanged', $user, $request, $application, [
            'global_session_id' => $session->session_id,
            'application_session_id' => $issued['session']['id'],
            'refresh_rotated' => $rotation['rotated'],
        ]);

        $response = response()->json([
            'success' => true,
            'data' => array_merge($issued, [
                'user' => $user,
                'application' => $application->only(['id', 'slug', 'name', 'url', 'operational_status']),
                'global_session' => $this->globalSessions->present($session),
            ]),
        ])->withCookie($this->globalSessions->sessionCookie((string) $request->cookie(
            (string) config('identity.global_sso.session_cookie', 'peter_ecosystem_session')
        )));

        if ($rotation['refresh_token']) {
            $response->withCookie($this->globalSessions->refreshCookie($rotation['refresh_token']));
        }

        return $response;
    }

    public function revokeCurrent(Request $request): JsonResponse|Response
    {
        $data = $request->validate([
            'application' => ['required', 'string', 'max:120'],
        ]);
        $application = $this->application($data['application']);
        $this->ensureRequestMatchesApplication($request, $application);

        $session = $this->globalSessions->resolve($request);
        if ($session) {
            $csrf = (string) $request->header('X-Peter-CSRF', '');
            if (! $this->globalSessions->validateCsrfToken($csrf, $session, $application, $request)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validação de segurança inválida.',
                    'code' => 'IDENTITY_CSRF_INVALID',
                ], 419);
            }

            $this->globalSessions->revoke($session, 'global_sso_logout');
            $this->audit->record('global_session_revoked', $session->user, $request, $application, [
                'global_session_id' => $session->session_id,
            ]);
        }

        return $this->emptyWithForgottenCookies();
    }

    public function logoutEverywhere(Request $request): JsonResponse
    {
        $user = $request->user('api');
        $current = $this->appSessions->current();
        $revokedAppSessions = $this->appSessions->revokeAll($user, 'global_logout');
        $revokedGlobalSessions = $this->globalSessions->revokeAll($user, 'global_logout');

        $user->forceFill([
            'auth_version' => max((int) ($user->auth_version ?? 1), 1) + 1,
        ])->save();

        $this->audit->record('logout_everywhere', $user, $request, $current?->application, [
            'application_sessions_revoked' => $revokedAppSessions,
            'global_sessions_revoked' => $revokedGlobalSessions,
        ], true);

        try {
            auth('api')->logout();
        } catch (\Throwable) {
        }

        $response = response()->json([
            'success' => true,
            'message' => 'Todas as sessões da Conta Peter Tecnet foram encerradas.',
        ]);

        foreach ($this->globalSessions->forgetCookies() as $cookie) {
            $response->withCookie($cookie);
        }

        return $response;
    }

    private function application(string $slug): Application
    {
        return Application::query()
            ->where('slug', Str::lower(trim($slug)))
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function ensureRequestMatchesApplication(Request $request, Application $application): void
    {
        $requested = Str::lower(trim((string) $request->header('X-Peter-App', '')));
        abort_unless(
            $requested !== '' && hash_equals(Str::lower((string) $application->slug), $requested),
            403,
            'O aplicativo solicitante não corresponde à sessão requisitada.'
        );

        $origin = trim((string) $request->header('Origin', ''));
        $requireOrigin = (bool) config('identity.global_sso.require_https_origin', true);
        if ($origin === '' && ! $requireOrigin) {
            return;
        }

        $originParts = parse_url($origin);
        $applicationParts = parse_url((string) $application->url);
        $originHost = Str::lower((string) ($originParts['host'] ?? ''));
        $appHost = Str::lower((string) ($applicationParts['host'] ?? ''));
        $scheme = Str::lower((string) ($originParts['scheme'] ?? ''));

        if ($scheme === 'https' && $originHost !== '' && $appHost !== '' && hash_equals($appHost, $originHost)) {
            return;
        }

        if (app()->environment(['local', 'testing'])
            && in_array($originHost, ['localhost', '127.0.0.1'], true)) {
            return;
        }

        abort(403, 'A origem não corresponde ao aplicativo solicitado.');
    }

    private function ensureAvailable(Application $application): void
    {
        if ($application->isOperational()) {
            return;
        }

        abort(503, $application->maintenance_message
            ?: 'Este aplicativo está temporariamente indisponível. Tente novamente em instantes.');
    }

    private function ensureSelfServiceMembership(User $user, Application $application): void
    {
        if (! $application->self_service_access) {
            return;
        }

        if (! $user->applications()->where('applications.id', $application->id)->exists()) {
            $user->applications()->syncWithoutDetaching([
                $application->id => [
                    'status' => 'active',
                    'role' => 'member',
                    'joined_at' => now(),
                ],
            ]);
        }
    }

    private function ensureAccess(User $user, Application $application): void
    {
        abort_unless(
            $user->applications()
                ->where('applications.id', $application->id)
                ->where(function ($query) {
                    $query->where('application_user.status', 'active')
                        ->orWhereNull('application_user.status');
                })->exists(),
            403,
            'Sua Conta Peter Tecnet não possui acesso a este aplicativo.'
        );
    }

    private function emptyWithForgottenCookies(): Response
    {
        $response = response()->noContent();
        foreach ($this->globalSessions->forgetCookies() as $cookie) {
            $response->withCookie($cookie);
        }
        return $response;
    }
}
