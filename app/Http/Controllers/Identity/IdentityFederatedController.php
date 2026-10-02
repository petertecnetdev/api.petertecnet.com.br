<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\Models\IdentitySecuritySetting;
use App\Domain\Identity\Services\IdentityApplicationResolver;
use App\Domain\Identity\Services\IdentityAuditService;
use App\Domain\Identity\Services\IdentityChallengeService;
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
    ) {
    }

    public function google(Request $request): JsonResponse
    {
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
            if (! is_array($payload)
                || empty($payload['sub'])
                || empty($payload['email'])
                || ! ($payload['email_verified'] ?? false)) {
                return response()->json(['success' => false, 'message' => 'Token do Google inválido.'], 401);
            }

            $googleId = (string) $payload['sub'];
            $email = strtolower(trim((string) $payload['email']));
            $firstName = trim((string) ($payload['given_name'] ?? 'Usuário')) ?: 'Usuário';

            $user = User::query()->where('google_id', $googleId)->orWhere('email', $email)->first();
            if ($user && $user->google_id && $user->google_id !== $googleId) {
                return response()->json(['success' => false, 'message' => 'Esta conta já está vinculada a outro login Google.'], 409);
            }

            if (! $user) {
                $user = User::query()->create([
                    'first_name' => $firstName,
                    'email' => $email,
                    'password' => Hash::make(Str::random(64)),
                    'user_name' => $this->uniqueUsername($firstName),
                    'google_id' => $googleId,
                    'email_verified_at' => now(),
                ]);
            } else {
                $user->forceFill([
                    'google_id' => $googleId,
                    'email_verified_at' => $user->email_verified_at ?: now(),
                ])->save();
            }

            $application = $this->applications->resolve($request, $data['application'] ?? null);
            $settings = IdentitySecuritySetting::query()->firstOrCreate(['user_id' => $user->id]);

            if ($settings->two_factor_enabled && $settings->two_factor_secret) {
                $issued = $this->challenges->issue(
                    'two_factor_login',
                    $user,
                    $application,
                    ['auth_method' => 'google'],
                    (int) config('identity.two_factor.challenge_ttl_minutes', 5),
                    $request
                );
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
                'device' => $issued['session']['device'],
                'auth_method' => 'google',
            ]);

            return response()->json(array_merge([
                'success' => true,
                'message' => 'Login com Google realizado com sucesso!',
                'user' => $user,
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
            $candidate = $base . '.' . $suffix++;
        }
        return $candidate;
    }
}
