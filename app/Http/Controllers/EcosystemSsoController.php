<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\User;
use App\Services\IdentitySessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class EcosystemSsoController extends Controller
{
    private const HANDOFF_TTL_SECONDS = 60;

    public function __construct(private readonly IdentitySessionService $identity)
    {
    }

    public function createHandoff(Request $request): JsonResponse
    {
        $data = $request->validate([
            'application' => ['required', 'string', 'max:120'],
        ]);

        $application = $this->application($data['application']);
        $this->ensureAvailable($application);

        $user = $request->user();
        $this->ensureSelfServiceMembership($user, $application);
        abort_unless(
            $this->hasAccess($user, $application),
            403,
            'Sua Conta Peter Tecnet não possui acesso a este aplicativo.'
        );

        $code = Str::random(64);
        Cache::put($this->cacheKey($code), [
            'user_id' => (int) $user->id,
            'application_id' => (int) $application->id,
            'auth_version' => max((int) ($user->auth_version ?? 1), 1),
        ], now()->addSeconds(self::HANDOFF_TTL_SECONDS));

        return response()->json([
            'success' => true,
            'data' => [
                'handoff_code' => $code,
                'expires_in' => self::HANDOFF_TTL_SECONDS,
                'application' => $this->applicationPayload($application),
            ],
        ]);
    }

    public function exchange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'handoff_code' => ['required', 'string', 'size:64'],
            'application' => ['required', 'string', 'max:120'],
        ]);

        $application = $this->application($data['application']);
        $this->ensureRequestMatchesApplication($request, $application);
        $this->ensureAvailable($application);

        $handoff = Cache::pull($this->cacheKey($data['handoff_code']));

        if (! is_array($handoff)
            || (int) ($handoff['application_id'] ?? 0) !== (int) $application->id) {
            return response()->json([
                'success' => false,
                'message' => 'Código SSO inválido, expirado ou já utilizado.',
                'code' => 'SSO_HANDOFF_INVALID',
            ], 401);
        }

        $user = User::query()->find($handoff['user_id'] ?? null);
        if (! $user
            || max((int) ($user->auth_version ?? 1), 1) !== (int) ($handoff['auth_version'] ?? 0)) {
            return response()->json([
                'success' => false,
                'message' => 'A sessão de origem não é mais válida.',
                'code' => 'SSO_SESSION_INVALID',
            ], 401);
        }

        $this->ensureSelfServiceMembership($user, $application);
        abort_unless(
            $this->hasAccess($user, $application),
            403,
            'Sua Conta Peter Tecnet não possui mais acesso a este aplicativo.'
        );

        $token = $this->issueApplicationToken($user, $application);
        $this->identity->audit($request, 'handoff_exchanged', 'success', $user, null, $application);

        return response()->json([
            'success' => true,
            'data' => [
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => $this->accessTokenTtlMinutes() * 60,
                'user' => $user,
                'application' => $this->applicationPayload($application),
            ],
        ]);
    }

    public function establishGlobalSession(Request $request): JsonResponse
    {
        $user = $request->user();
        $application = $this->applicationFromHeader($request);

        if ($application) {
            $this->ensureRequestMatchesApplication($request, $application);
            $this->ensureAvailable($application);
            $this->ensureSelfServiceMembership($user, $application);
            abort_unless(
                $this->hasAccess($user, $application),
                403,
                'Sua Conta Peter Tecnet não possui acesso a este aplicativo.'
            );
        }

        $result = $this->identity->establish($request, $user, $application);
        $session = $result['session'];

        $response = response()->json([
            'success' => true,
            'data' => [
                'authenticated' => true,
                'session_id' => $session->id,
                'expires_in' => max(0, now()->diffInSeconds($session->expires_at, false)),
                'refresh_expires_in' => max(0, now()->diffInSeconds($session->refresh_expires_at, false)),
                'device' => $this->devicePayload($session->device),
            ],
        ]);

        if (($result['session_token'] ?? '') !== '') {
            $response->withCookie($this->identity->sessionCookie($result['session_token']));
        }

        if (($result['refresh_token'] ?? '') !== '') {
            $response->withCookie($this->identity->refreshCookie($result['refresh_token']));
        }

        return $response;
    }

    public function globalSessionCsrf(Request $request): JsonResponse|Response
    {
        $data = $request->validate([
            'application' => ['required', 'string', 'max:120'],
        ]);

        $application = $this->application($data['application']);
        $this->ensureRequestMatchesApplication($request, $application);
        $this->ensureAvailable($application);

        $session = $this->identity->resolve($request);
        if (! $session) {
            return $this->noContentWithForgottenIdentityCookies();
        }

        $this->ensureSelfServiceMembership($session->user, $application);
        abort_unless(
            $this->hasAccess($session->user, $application),
            403,
            'Sua Conta Peter Tecnet não possui acesso a este aplicativo.'
        );

        $token = $this->identity->issueCsrfToken($session, $application, $request);

        return response()->json([
            'success' => true,
            'data' => [
                'csrf_token' => $token,
                'expires_in' => (int) config('identity.csrf_ttl_seconds', 300),
            ],
        ]);
    }

    public function exchangeGlobalSession(Request $request): JsonResponse|Response
    {
        $data = $request->validate([
            'application' => ['required', 'string', 'max:120'],
        ]);

        $application = $this->application($data['application']);
        $this->ensureRequestMatchesApplication($request, $application);
        $this->ensureAvailable($application);

        $session = $this->identity->resolve($request);
        if (! $session) {
            return $this->noContentWithForgottenIdentityCookies();
        }

        $csrf = (string) $request->header('X-Peter-CSRF', '');
        if (! $this->identity->validateCsrfToken($csrf, $session, $application, $request)) {
            $this->identity->audit(
                $request,
                'csrf_rejected',
                'failure',
                $session->user,
                $session,
                $application
            );

            return response()->json([
                'success' => false,
                'message' => 'Proteção da sessão expirada. Renove o desafio de segurança e tente novamente.',
                'code' => 'IDENTITY_CSRF_INVALID',
            ], 419);
        }

        if (! $this->identity->refreshMatches($request, $session)) {
            $this->identity->audit(
                $request,
                'refresh_rejected',
                'failure',
                $session->user,
                $session,
                $application
            );
            $this->identity->revoke($session, 'refresh_missing_or_reused', $request);

            return response()->json([
                'success' => false,
                'message' => 'A sessão central precisa ser autenticada novamente.',
                'code' => 'IDENTITY_REFRESH_INVALID',
            ], 401)->withCookies($this->identity->forgetCookies());
        }

        $user = $session->user;
        $this->ensureSelfServiceMembership($user, $application);
        abort_unless(
            $this->hasAccess($user, $application),
            403,
            'Sua Conta Peter Tecnet não possui acesso a este aplicativo.'
        );

        $this->identity->touch($session, $request, $application);
        $newRefreshToken = $this->identity->rotateRefresh($request, $session);
        $token = $this->issueApplicationToken($user, $application, $session->id);
        $this->identity->audit($request, 'session_exchanged', 'success', $user, $session, $application);

        $response = response()->json([
            'success' => true,
            'data' => [
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => $this->accessTokenTtlMinutes() * 60,
                'user' => $user,
                'session' => [
                    'id' => $session->id,
                    'expires_at' => $session->expires_at?->toIso8601String(),
                    'refresh_expires_at' => $session->refresh_expires_at?->toIso8601String(),
                    'device' => $this->devicePayload($session->device),
                ],
                'application' => $this->applicationPayload($application),
            ],
        ]);

        $rawSessionToken = (string) $request->cookie((string) config('identity.session_cookie'), '');
        if ($rawSessionToken !== '') {
            $response->withCookie($this->identity->sessionCookie($rawSessionToken));
        }

        if ($newRefreshToken !== null) {
            $response->withCookie($this->identity->refreshCookie($newRefreshToken));
        }

        return $response;
    }

    public function revokeGlobalSession(Request $request): JsonResponse|Response
    {
        $session = $this->identity->resolve($request);
        if (! $session) {
            return $this->noContentWithForgottenIdentityCookies();
        }

        $applicationSlug = trim((string) $request->input('application', $request->header('X-Peter-App', '')));
        if ($applicationSlug !== '') {
            $application = $this->application($applicationSlug);
            $this->ensureRequestMatchesApplication($request, $application);

            $csrf = (string) $request->header('X-Peter-CSRF', '');
            if (! $this->identity->validateCsrfToken($csrf, $session, $application, $request)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Proteção da sessão expirada.',
                    'code' => 'IDENTITY_CSRF_INVALID',
                ], 419);
            }
        }

        $this->identity->revoke($session, 'global_cookie_logout', $request);

        return response()->noContent()->withCookies($this->identity->forgetCookies());
    }

    private function application(string $slug): Application
    {
        return Application::query()
            ->where('slug', Str::lower(trim($slug)))
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function applicationFromHeader(Request $request): ?Application
    {
        $slug = Str::lower(trim((string) $request->header('X-Peter-App', '')));
        return $slug === '' ? null : $this->application($slug);
    }

    private function ensureRequestMatchesApplication(Request $request, Application $application): void
    {
        $requestedApp = Str::lower(trim((string) $request->header('X-Peter-App', '')));
        abort_unless(
            hash_equals(Str::lower((string) $application->slug), $requestedApp),
            403,
            'O aplicativo solicitante não corresponde à sessão requisitada.'
        );

        $origin = trim((string) $request->header('Origin', ''));
        if ($origin === '') {
            abort_unless(
                app()->environment(['local', 'testing']) || ! (bool) config('identity.require_https_origin', true),
                403,
                'A origem HTTPS do aplicativo é obrigatória.'
            );
            return;
        }

        $originParts = parse_url($origin);
        $applicationParts = parse_url((string) $application->url);
        $originHost = Str::lower((string) ($originParts['host'] ?? ''));
        $applicationHost = Str::lower((string) ($applicationParts['host'] ?? ''));
        $originScheme = Str::lower((string) ($originParts['scheme'] ?? ''));

        if ($originScheme === 'https' && $originHost !== '' && hash_equals($applicationHost, $originHost)) {
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

        abort(
            503,
            $application->maintenance_message
                ?: 'Este aplicativo está temporariamente indisponível. Tente novamente em instantes.'
        );
    }

    private function ensureSelfServiceMembership(User $user, Application $application): void
    {
        if (! $application->self_service_access) {
            return;
        }

        $alreadyMember = $user->applications()
            ->where('applications.id', $application->id)
            ->exists();

        if (! $alreadyMember) {
            $user->applications()->attach($application->id, [
                'status' => 'active',
                'role' => 'member',
                'joined_at' => now(),
            ]);
        }
    }

    private function hasAccess(User $user, Application $application): bool
    {
        return $user->applications()
            ->where('applications.id', $application->id)
            ->where(function ($query) {
                $query->where('application_user.status', 'active')
                    ->orWhereNull('application_user.status');
            })
            ->exists();
    }

    private function issueApplicationToken(User $user, Application $application, ?string $identitySessionId = null): string
    {
        $factory = auth('api')->factory();
        $previousTtl = $factory->getTTL();
        $factory->setTTL($this->accessTokenTtlMinutes());

        try {
            return auth('api')->claims(array_filter([
                'app' => (string) $application->slug,
                'identity_session_id' => $identitySessionId,
                'auth_version' => max((int) ($user->auth_version ?? 1), 1),
            ]))->login($user);
        } finally {
            $factory->setTTL($previousTtl);
        }
    }

    private function accessTokenTtlMinutes(): int
    {
        return max(5, (int) config('identity.access_token_ttl_minutes', 30));
    }

    private function applicationPayload(Application $application): array
    {
        return $application->only([
            'id',
            'slug',
            'name',
            'url',
            'operational_status',
        ]);
    }

    private function devicePayload($device): ?array
    {
        if (! $device) {
            return null;
        }

        return [
            'id' => $device->uuid,
            'name' => $device->name,
            'browser' => $device->browser,
            'platform' => $device->platform,
            'trusted' => (bool) $device->trusted,
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
        ];
    }

    private function noContentWithForgottenIdentityCookies(): Response
    {
        return response()->noContent()->withCookies($this->identity->forgetCookies());
    }

    private function cacheKey(string $code): string
    {
        return 'ecosystem:sso:'.hash('sha256', $code);
    }
}
