<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

class EcosystemSsoController extends Controller
{
    private const HANDOFF_TTL_SECONDS = 60;
    private const GLOBAL_SESSION_COOKIE = 'peter_ecosystem_session';
    private const GLOBAL_SESSION_TTL_MINUTES = 10080;

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
            'auth_version' => (int) ($user->auth_version ?? 0),
        ], now()->addSeconds(self::HANDOFF_TTL_SECONDS));

        return response()->json([
            'success' => true,
            'data' => [
                'handoff_code' => $code,
                'expires_in' => self::HANDOFF_TTL_SECONDS,
                'application' => $application->only([
                    'id',
                    'slug',
                    'name',
                    'url',
                    'operational_status',
                ]),
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
        if (! $user || (int) ($user->auth_version ?? 0) !== (int) ($handoff['auth_version'] ?? 0)) {
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

        $token = auth('api')->login($user);

        return response()->json([
            'success' => true,
            'data' => [
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => auth('api')->factory()->getTTL() * 60,
                'user' => $user,
                'application' => $application->only([
                    'id',
                    'slug',
                    'name',
                    'url',
                    'operational_status',
                ]),
            ],
        ]);
    }

    public function establishGlobalSession(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentToken = (string) $request->cookie(self::GLOBAL_SESSION_COOKIE, '');
        $currentSession = $this->readGlobalSession($currentToken);

        if ($this->globalSessionBelongsTo($currentSession, $user)) {
            $this->storeGlobalSession($currentToken, $user);

            return response()->json([
                'success' => true,
                'data' => [
                    'authenticated' => true,
                    'expires_in' => self::GLOBAL_SESSION_TTL_MINUTES * 60,
                ],
            ])->withCookie($this->globalSessionCookie($currentToken));
        }

        if ($currentToken !== '') {
            Cache::forget($this->globalSessionCacheKey($currentToken));
        }

        $sessionToken = Str::random(64);
        $this->storeGlobalSession($sessionToken, $user);

        return response()->json([
            'success' => true,
            'data' => [
                'authenticated' => true,
                'expires_in' => self::GLOBAL_SESSION_TTL_MINUTES * 60,
            ],
        ])->withCookie($this->globalSessionCookie($sessionToken));
    }

    public function exchangeGlobalSession(Request $request): JsonResponse|Response
    {
        $data = $request->validate([
            'application' => ['required', 'string', 'max:120'],
        ]);

        $sessionToken = (string) $request->cookie(self::GLOBAL_SESSION_COOKIE, '');
        if ($sessionToken === '') {
            return response()->noContent();
        }

        $session = $this->readGlobalSession($sessionToken);
        $user = User::query()->find($session['user_id'] ?? null);

        if (! $user || ! $this->globalSessionBelongsTo($session, $user)) {
            Cache::forget($this->globalSessionCacheKey($sessionToken));

            return response()->noContent()
                ->withCookie($this->forgetGlobalSessionCookie());
        }

        $application = $this->application($data['application']);
        $this->ensureRequestMatchesApplication($request, $application);
        $this->ensureAvailable($application);
        $this->ensureSelfServiceMembership($user, $application);
        abort_unless(
            $this->hasAccess($user, $application),
            403,
            'Sua Conta Peter Tecnet não possui acesso a este aplicativo.'
        );

        $this->storeGlobalSession($sessionToken, $user);
        $token = auth('api')->login($user);

        return response()->json([
            'success' => true,
            'data' => [
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => auth('api')->factory()->getTTL() * 60,
                'user' => $user,
                'application' => $application->only([
                    'id',
                    'slug',
                    'name',
                    'url',
                    'operational_status',
                ]),
            ],
        ])->withCookie($this->globalSessionCookie($sessionToken));
    }

    public function revokeGlobalSession(Request $request): Response
    {
        $sessionToken = (string) $request->cookie(self::GLOBAL_SESSION_COOKIE, '');
        if ($sessionToken !== '') {
            Cache::forget($this->globalSessionCacheKey($sessionToken));
        }

        return response()->noContent()
            ->withCookie($this->forgetGlobalSessionCookie());
    }

    private function application(string $slug): Application
    {
        return Application::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();
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

    private function storeGlobalSession(string $sessionToken, User $user): void
    {
        Cache::put($this->globalSessionCacheKey($sessionToken), [
            'user_id' => (int) $user->id,
            'auth_version' => (int) ($user->auth_version ?? 0),
        ], now()->addMinutes(self::GLOBAL_SESSION_TTL_MINUTES));
    }

    private function readGlobalSession(string $sessionToken): ?array
    {
        if ($sessionToken === '') {
            return null;
        }

        $session = Cache::get($this->globalSessionCacheKey($sessionToken));

        return is_array($session) ? $session : null;
    }

    private function globalSessionBelongsTo(?array $session, User $user): bool
    {
        return is_array($session)
            && (int) ($session['user_id'] ?? 0) === (int) $user->id
            && (int) ($session['auth_version'] ?? -1) === (int) ($user->auth_version ?? 0);
    }

    private function globalSessionCookie(string $sessionToken)
    {
        return Cookie::make(
            self::GLOBAL_SESSION_COOKIE,
            $sessionToken,
            self::GLOBAL_SESSION_TTL_MINUTES,
            '/',
            null,
            true,
            true,
            false,
            'lax'
        );
    }

    private function forgetGlobalSessionCookie()
    {
        return Cookie::forget(self::GLOBAL_SESSION_COOKIE, '/', null);
    }

    private function cacheKey(string $code): string
    {
        return 'ecosystem:sso:' . hash('sha256', $code);
    }

    private function globalSessionCacheKey(string $sessionToken): string
    {
        return 'ecosystem:global-session:' . hash('sha256', $sessionToken);
    }
}
