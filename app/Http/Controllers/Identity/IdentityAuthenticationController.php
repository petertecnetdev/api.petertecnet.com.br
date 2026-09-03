<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\Models\IdentitySecuritySetting;
use App\Domain\Identity\Rules\IdentityPassword;
use App\Domain\Identity\Services\CompromisedPasswordService;
use App\Domain\Identity\Services\IdentityApplicationResolver;
use App\Domain\Identity\Services\IdentityAuditService;
use App\Domain\Identity\Services\IdentityChallengeService;
use App\Domain\Identity\Services\IdentitySessionService;
use App\Domain\Identity\Services\TotpService;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class IdentityAuthenticationController extends Controller
{
    public function __construct(
        private readonly IdentityApplicationResolver $applications,
        private readonly IdentityChallengeService $challenges,
        private readonly IdentitySessionService $sessions,
        private readonly IdentityAuditService $audit,
        private readonly TotpService $totp,
        private readonly CompromisedPasswordService $compromisedPasswords,
    ) {
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'application' => ['nullable', 'string', 'max:120'],
        ]);

        $rateKey = 'identity:login:' . hash('sha256', mb_strtolower(trim($data['username'])) . '|' . $request->ip());
        $maxAttempts = max((int) config('identity.rate_limits.login_attempts', 8), 3);
        $decay = max((int) config('identity.rate_limits.login_decay_seconds', 60), 30);

        if (RateLimiter::tooManyAttempts($rateKey, $maxAttempts)) {
            return response()->json([
                'success' => false,
                'message' => 'Muitas tentativas. Aguarde antes de tentar novamente.',
                'code' => 'AUTH_RATE_LIMITED',
                'retry_after' => RateLimiter::availableIn($rateKey),
            ], 429);
        }

        $user = $this->findUser($data['username']);
        if (! $user || ! Hash::check($data['password'], (string) $user->password)) {
            RateLimiter::hit($rateKey, $decay);
            $this->audit->record('login_failed', $user, $request, $this->applications->resolve($request, $data['application'] ?? null));
            return response()->json([
                'success' => false,
                'message' => 'Credenciais inválidas.',
                'code' => 'INVALID_CREDENTIALS',
            ], 401);
        }

        RateLimiter::clear($rateKey);
        $application = $this->applications->resolve($request, $data['application'] ?? null);
        $settings = IdentitySecuritySetting::query()->firstOrCreate(['user_id' => $user->id]);

        if ($settings->two_factor_enabled && $settings->two_factor_secret) {
            $issued = $this->challenges->issue(
                'two_factor_login',
                $user,
                $application,
                ['auth_method' => 'password'],
                (int) config('identity.two_factor.challenge_ttl_minutes', 5),
                $request
            );

            $this->audit->record('two_factor_challenge', $user, $request, $application);

            return response()->json([
                'success' => true,
                'two_factor_required' => true,
                'challenge' => $issued['token'],
                'expires_in' => (int) config('identity.two_factor.challenge_ttl_minutes', 5) * 60,
            ], 202);
        }

        return $this->successfulLogin($user, $request, $application, 'password');
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', new IdentityPassword($this->compromisedPasswords)],
            'application' => ['nullable', 'string', 'max:120'],
        ]);

        $application = $this->applications->resolve($request, $data['application'] ?? null);
        $user = User::query()->create([
            'first_name' => trim($data['first_name']),
            'email' => strtolower(trim($data['email'])),
            'password' => Hash::make($data['password']),
            'user_name' => $this->uniqueUsername($data['first_name']),
            'email_verified_at' => now(),
        ]);

        if ($application) {
            $user->applications()->syncWithoutDetaching([
                $application->id => ['status' => 'active', 'role' => 'member', 'joined_at' => now()],
            ]);
        }

        $this->audit->record('registered', $user, $request, $application);

        return $this->successfulLogin($user, $request, $application, 'password', 201);
    }

    public function requestMagicLink(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'application' => ['nullable', 'string', 'max:120'],
        ]);

        $rateKey = 'identity:magic:' . hash('sha256', strtolower($data['email']) . '|' . $request->ip());
        if (RateLimiter::tooManyAttempts($rateKey, max((int) config('identity.rate_limits.magic_link_attempts', 5), 2))) {
            return response()->json([
                'success' => true,
                'message' => 'Se o e-mail estiver cadastrado, enviaremos um link de acesso.',
            ]);
        }
        RateLimiter::hit($rateKey, max((int) config('identity.rate_limits.magic_link_decay_seconds', 300), 60));

        $user = User::query()->where('email', strtolower(trim($data['email'])))->first();
        $application = $this->applications->resolve($request, $data['application'] ?? null);

        if ($user) {
            $issued = $this->challenges->issue(
                'magic_login',
                $user,
                $application,
                [],
                (int) config('identity.magic_link.ttl_minutes', 10),
                $request
            );
            $url = $this->actionUrl($application, 'identity_magic', $issued['token']);
            $this->sendActionEmail($user, 'Seu link de acesso · Peter Tecnet', "Use o link abaixo para entrar. Ele é de uso único e expira rapidamente.\n\n{$url}");
            $this->audit->record('magic_link_requested', $user, $request, $application);
        }

        return response()->json([
            'success' => true,
            'message' => 'Se o e-mail estiver cadastrado, enviaremos um link de acesso.',
        ]);
    }

    public function exchangeMagicLink(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'application' => ['nullable', 'string', 'max:120'],
        ]);

        $challenge = $this->challenges->consume('magic_login', $data['token']);
        if (! $challenge || ! $challenge->user) {
            return response()->json([
                'success' => false,
                'message' => 'Link inválido, expirado ou já utilizado.',
                'code' => 'MAGIC_LINK_INVALID',
            ], 401);
        }

        $application = $challenge->application ?: $this->applications->resolve($request, $data['application'] ?? null);
        $this->audit->record('magic_link_consumed', $challenge->user, $request, $application);

        return $this->successfulLogin($challenge->user, $request, $application, 'magic_link');
    }

    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32'],
        ]);

        $challenge = $this->challenges->consume('two_factor_login', $data['challenge']);
        if (! $challenge || ! $challenge->user) {
            return response()->json([
                'success' => false,
                'message' => 'Desafio 2FA inválido ou expirado.',
                'code' => 'TWO_FACTOR_CHALLENGE_INVALID',
            ], 401);
        }

        $settings = IdentitySecuritySetting::query()->where('user_id', $challenge->user_id)->first();
        if (! $settings || ! $settings->two_factor_enabled || ! $settings->two_factor_secret) {
            return response()->json(['success' => false, 'message' => '2FA não está ativo.', 'code' => 'TWO_FACTOR_NOT_ENABLED'], 409);
        }

        $valid = $this->totp->verify($settings->two_factor_secret, preg_replace('/\D/', '', $data['code']) ?? '');
        if (! $valid) {
            $hash = hash('sha256', strtoupper(trim($data['code'])));
            $codes = $settings->two_factor_recovery_codes ?: [];
            $index = array_search($hash, $codes, true);
            if ($index !== false) {
                unset($codes[$index]);
                $settings->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
                $valid = true;
            }
        }

        if (! $valid) {
            $this->audit->record('two_factor_failed', $challenge->user, $request, $challenge->application);
            return response()->json(['success' => false, 'message' => 'Código 2FA inválido.', 'code' => 'TWO_FACTOR_INVALID'], 401);
        }

        $this->audit->record('two_factor_verified', $challenge->user, $request, $challenge->application);
        $method = (($challenge->payload['auth_method'] ?? 'password') . '+totp');

        return $this->successfulLogin($challenge->user, $request, $challenge->application, $method);
    }

    public function requestPasswordReset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'application' => ['nullable', 'string', 'max:120'],
        ]);

        $user = User::query()->where('email', strtolower(trim($data['email'])))->first();
        $application = $this->applications->resolve($request, $data['application'] ?? null);

        if ($user) {
            $issued = $this->challenges->issue(
                'password_reset',
                $user,
                $application,
                [],
                10,
                $request
            );
            $url = $this->actionUrl($application, 'identity_reset', $issued['token']);
            $this->sendActionEmail($user, 'Redefinir sua senha · Peter Tecnet', "Use o link abaixo para escolher uma nova senha. O link é de uso único e expira em 10 minutos.\n\n{$url}");
            $this->audit->record('password_reset_requested', $user, $request, $application);
        }

        return response()->json([
            'success' => true,
            'message' => 'Se o e-mail estiver cadastrado, enviaremos as instruções de recuperação.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', new IdentityPassword($this->compromisedPasswords)],
            'password_confirmation' => ['required', 'same:password'],
        ]);

        $challenge = $this->challenges->consume('password_reset', $data['token']);
        if (! $challenge || ! $challenge->user) {
            return response()->json([
                'success' => false,
                'message' => 'Link de recuperação inválido, expirado ou já utilizado.',
                'code' => 'PASSWORD_RESET_INVALID',
            ], 422);
        }

        $user = $challenge->user;
        $user->forceFill(['password' => Hash::make($data['password'])])->save();
        $this->sessions->revokeAll($user, 'password_changed');
        $this->audit->record('password_changed', $user, $request, $challenge->application, [], true);

        return response()->json([
            'success' => true,
            'message' => 'Senha redefinida com sucesso. Entre novamente nos seus dispositivos.',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user('api');
        $session = $this->sessions->current();
        if ($session) {
            $this->sessions->revoke($session, 'logout');
        }

        $this->audit->record('logout', $user, $request, $session?->application);
        try {
            auth('api')->logout();
        } catch (\Throwable) {
        }

        return response()->json(['success' => true, 'message' => 'Sessão encerrada.']);
    }

    public function refresh(Request $request): JsonResponse
    {
        $session = $this->sessions->current();
        if (! $session || ! $session->isActive()) {
            return response()->json(['success' => false, 'message' => 'Sessão inválida.', 'code' => 'SESSION_REVOKED'], 401);
        }

        try {
            $token = auth('api')->claims([
                'sid' => $session->session_id,
                'amr' => [$session->auth_method],
                'ver' => max((int) $session->user->auth_version, 1),
            ])->refresh();
        } catch (\Throwable) {
            return response()->json(['success' => false, 'message' => 'Token não renovável.', 'code' => 'TOKEN_NOT_REFRESHABLE'], 401);
        }

        return response()->json([
            'success' => true,
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
        ]);
    }

    private function successfulLogin(User $user, Request $request, ?Application $application, string $method, int $status = 200): JsonResponse
    {
        $knownDevice = \App\Domain\Identity\Models\IdentitySession::query()
            ->where('user_id', $user->id)
            ->where('user_agent', $request->userAgent())
            ->exists();

        $issued = $this->sessions->issue($user, $request, $method, $application);
        $this->audit->record('new_session', $user, $request, $application, [
            'device' => $issued['session']['device'],
            'session_id' => $issued['session']['id'],
            'auth_method' => $method,
            'new_device' => ! $knownDevice,
        ], ! $knownDevice);

        return response()->json(array_merge([
            'success' => true,
            'user' => $user,
            'application' => $application?->only(['id', 'name', 'slug', 'url']),
        ], $issued), $status);
    }

    private function findUser(string $identifier): ?User
    {
        $identifier = trim($identifier);
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return User::query()->where('email', strtolower($identifier))->first();
        }

        $digits = preg_replace('/\D/', '', $identifier) ?? '';
        if (strlen($digits) === 11) {
            return User::query()->where('cpf', $digits)->orWhere('phone', $digits)->first();
        }

        return User::query()->where('user_name', $identifier)->first();
    }

    private function uniqueUsername(string $name): string
    {
        $base = Str::slug($name, '.') ?: 'usuario';
        $candidate = $base;
        $suffix = 1;
        while (User::query()->where('user_name', $candidate)->exists()) {
            $candidate = $base . '.' . $suffix++;
        }
        return $candidate;
    }

    private function actionUrl(?Application $application, string $parameter, string $token): string
    {
        $base = rtrim((string) ($application?->url ?: config('app.frontend_url', 'https://petertecnet.com.br')), '/');
        return $base . '/?' . http_build_query([$parameter => $token, 'application' => $application?->slug]);
    }

    private function sendActionEmail(User $user, string $subject, string $body): void
    {
        try {
            Mail::raw($body, fn ($message) => $message->to($user->email)->subject($subject));
        } catch (\Throwable $e) {
            Log::error('Falha ao enviar e-mail da plataforma de identidade.', [
                'user_id' => $user->id,
                'subject' => $subject,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
