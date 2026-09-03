<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\Models\IdentityIdentifier;
use App\Domain\Identity\Models\IdentitySecuritySetting;
use App\Domain\Identity\Services\IdentityApplicationResolver;
use App\Domain\Identity\Services\IdentityAuditService;
use App\Domain\Identity\Services\IdentityChallengeService;
use App\Domain\Identity\Services\IdentityIdentifierService;
use App\Domain\Identity\Services\IdentitySecurityAlertService;
use App\Domain\Identity\Services\IdentitySessionService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Google_Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IdentityFederatedController extends Controller
{
    public function __construct(
        private readonly IdentityApplicationResolver $applications,
        private readonly IdentityChallengeService $challenges,
        private readonly IdentitySessionService $sessions,
        private readonly IdentityAuditService $audit,
        private readonly IdentityIdentifierService $identifiers,
        private readonly IdentitySecurityAlertService $alerts,
    ) {
    }

    public function google(Request $request): JsonResponse
    {
        abort_unless(config('identity.features.google', true), 404);
        $data = $request->validate([
            'token_id' => ['required', 'string'],
            'application' => ['nullable', 'string', 'max:120'],
        ]);

        $clientId = (string) config('services.google.client_id');
        if ($clientId === '') {
            return response()->json(['success' => false, 'message' => 'Login com Google indisponível.'], 503);
        }

        try {
            $payload = (new Google_Client(['client_id' => $clientId]))->verifyIdToken($data['token_id']);
            if (! is_array($payload) || empty($payload['sub']) || empty($payload['email']) || ! ($payload['email_verified'] ?? false)) {
                return response()->json(['success' => false, 'message' => 'Token do Google inválido.'], 401);
            }

            $googleId = (string) $payload['sub'];
            $email = Str::lower(trim((string) $payload['email']));
            $googleIdentifier = IdentityIdentifier::query()
                ->with('user')
                ->where('type', 'google')
                ->where('fingerprint', $this->identifiers->fingerprint('google', $this->identifiers->normalize('google', $googleId)))
                ->whereNull('revoked_at')
                ->first();
            $emailUser = $this->identifiers->resolve($email);

            if ($googleIdentifier?->user && $emailUser && (int) $googleIdentifier->user->id !== (int) $emailUser->id) {
                return response()->json([
                    'success' => false,
                    'code' => 'IDENTITY_ACCOUNT_CONFLICT',
                    'message' => 'Este login Google e este e-mail pertencem a contas diferentes. Use a consolidação segura de contas antes de vinculá-los.',
                ], 409);
            }

            $user = $googleIdentifier?->user ?: $emailUser;
            if (! $user) {
                $firstName = trim((string) ($payload['given_name'] ?? 'Usuário')) ?: 'Usuário';
                $user = User::query()->create([
                    'first_name' => $firstName,
                    'email' => $email,
                    'password' => Hash::make(Str::random(64)),
                    'user_name' => $this->uniqueUsername($firstName),
                    'google_id' => $googleId,
                    'email_verified_at' => now(),
                ]);
            } elseif ($user->google_id && ! hash_equals((string) $user->google_id, $googleId)) {
                return response()->json(['success' => false, 'code' => 'GOOGLE_ALREADY_LINKED', 'message' => 'Esta conta já está vinculada a outro login Google.'], 409);
            } else {
                $user->forceFill(['google_id' => $googleId, 'email_verified_at' => $user->email_verified_at ?: now()])->save();
            }

            $this->identifiers->add($user, 'google', $googleId, true);
            $this->identifiers->add($user, 'email', $email, true, true);
            $application = $this->applications->resolve($request, $data['application'] ?? null);
            $settings = IdentitySecuritySetting::query()->firstOrCreate(['user_id' => $user->id]);

            if ($settings->two_factor_enabled && $settings->two_factor_secret) {
                $issued = $this->challenges->issue('two_factor_login', $user, $application, ['auth_method' => 'google'], (int) config('identity.two_factor.challenge_ttl_minutes', 5), $request);
                $this->audit->record('two_factor_challenge', $user, $request, $application, ['auth_method' => 'google']);
                return response()->json([
                    'success' => true,
                    'two_factor_required' => true,
                    'challenge' => $issued['token'],
                    'expires_in' => (int) config('identity.two_factor.challenge_ttl_minutes', 5) * 60,
                ], 202);
            }

            $issued = $this->sessions->issue($user, $request, 'google', $application);
            $this->audit->record('new_session', $user, $request, $application, [
                'session_id' => $issued['session']['id'],
                'device' => $issued['session']['display_name'] ?? $issued['session']['device'],
                'auth_method' => 'google',
                'risk' => $issued['risk'] ?? null,
            ], (($issued['risk']['score'] ?? 0) >= 55));
            $this->alerts->newSession($user, $request, $application, $issued['session']['id'], $issued['risk'] ?? []);

            return response()->json(array_merge([
                'success' => true,
                'message' => 'Login com Google realizado com sucesso!',
                'user' => $user,
                'application' => $application?->only(['id', 'name', 'slug', 'url']),
            ], $issued));
        } catch (\Throwable $e) {
            Log::error('Falha no login Google da Identity Platform.', ['message' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro durante o login com Google.'], 500);
        }
    }

    private function uniqueUsername(string $name): string
    {
        $base = Str::slug($name, '.') ?: 'usuario';
        $candidate = $base;
        $suffix = 1;
        while (User::query()->where('user_name', $candidate)->exists()) {
            $candidate = $base.'.'.$suffix++;
        }
        return $candidate;
    }
}
