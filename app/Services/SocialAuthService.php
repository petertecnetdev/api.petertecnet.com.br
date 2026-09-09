<?php

namespace App\Services;

use App\Mail\VerificationCodeMail;
use App\Models\Interaction;
use App\Models\SocialAuthChallenge;
use App\Models\User;
use App\Models\UserSocialAccount;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SocialAuthService
{
    private const PROVIDER_INSTAGRAM = 'instagram';

    public function instagramStart()
    {
        $config = $this->instagramConfig();
        if (! $config) {
            return response()->json([
                'message' => 'Login com Instagram ainda não foi configurado.',
                'code' => 'instagram_not_configured',
            ], 503);
        }

        $state = Crypt::encryptString(json_encode([
            'provider' => self::PROVIDER_INSTAGRAM,
            'nonce' => Str::random(40),
            'expires_at' => now()->addMinutes(10)->timestamp,
        ], JSON_THROW_ON_ERROR));

        $query = http_build_query([
            'force_reauth' => 'true',
            'enable_fb_login' => '0',
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code',
            'scope' => implode(',', $config['scopes']),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return response()->json([
            'authorization_url' => 'https://www.instagram.com/oauth/authorize?'.$query,
            'expires_in' => 600,
        ]);
    }

    public function instagramCallback(array $data)
    {
        $config = $this->instagramConfig();
        if (! $config) {
            return response()->json([
                'message' => 'Login com Instagram ainda não foi configurado.',
                'code' => 'instagram_not_configured',
            ], 503);
        }

        if (! $this->validInstagramState($data['state'])) {
            return response()->json([
                'message' => 'A sessão de autenticação do Instagram expirou. Tente novamente.',
                'code' => 'instagram_state_invalid',
            ], 419);
        }

        try {
            $tokenResponse = Http::asForm()
                ->acceptJson()
                ->timeout(12)
                ->post('https://api.instagram.com/oauth/access_token', [
                    'client_id' => $config['client_id'],
                    'client_secret' => $config['client_secret'],
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $config['redirect_uri'],
                    'code' => trim($data['code']),
                ]);

            if (! $tokenResponse->successful()) {
                Log::warning('Instagram OAuth token exchange failed.', [
                    'status' => $tokenResponse->status(),
                ]);

                return response()->json([
                    'message' => 'Não foi possível validar sua conta do Instagram. Tente novamente.',
                    'code' => 'instagram_token_exchange_failed',
                ], 401);
            }

            $tokenPayload = $tokenResponse->json();
            $accessToken = (string) ($tokenPayload['access_token'] ?? '');
            $providerUserId = (string) ($tokenPayload['user_id'] ?? '');

            if ($accessToken === '' || $providerUserId === '') {
                return response()->json([
                    'message' => 'O Instagram não retornou uma identidade válida.',
                    'code' => 'instagram_identity_missing',
                ], 401);
            }

            $profileResponse = Http::acceptJson()
                ->timeout(12)
                ->get(rtrim($config['graph_url'], '/').'/me', [
                    'fields' => 'id,username,name,account_type,profile_picture_url',
                    'access_token' => $accessToken,
                    'appsecret_proof' => hash_hmac('sha256', $accessToken, $config['client_secret']),
                ]);

            if (! $profileResponse->successful()) {
                Log::warning('Instagram profile lookup failed.', [
                    'status' => $profileResponse->status(),
                    'provider_user_id' => $providerUserId,
                ]);

                return response()->json([
                    'message' => 'Não foi possível obter seu perfil profissional do Instagram.',
                    'code' => 'instagram_profile_failed',
                ], 401);
            }

            $profile = $profileResponse->json();
            $profileId = (string) ($profile['id'] ?? $providerUserId);
            $accountType = strtoupper(trim((string) ($profile['account_type'] ?? '')));

            if ($accountType !== '' && ! in_array($accountType, ['BUSINESS', 'CREATOR'], true)) {
                return response()->json([
                    'message' => 'O login com Instagram está disponível para contas profissionais Business ou Creator.',
                    'code' => 'instagram_professional_account_required',
                ], 422);
            }

            $identity = [
                'provider_user_id' => $profileId,
                'username' => $this->nullableString($profile['username'] ?? null),
                'display_name' => $this->nullableString($profile['name'] ?? null),
                'avatar_url' => $this->nullableString($profile['profile_picture_url'] ?? null),
                'metadata' => array_filter([
                    'account_type' => $accountType ?: null,
                ]),
            ];

            $socialAccount = UserSocialAccount::query()
                ->with('user')
                ->where('provider', self::PROVIDER_INSTAGRAM)
                ->where('provider_user_id', $profileId)
                ->first();

            if ($socialAccount?->user) {
                $socialAccount->forceFill([
                    'username' => $identity['username'],
                    'display_name' => $identity['display_name'],
                    'avatar_url' => $identity['avatar_url'],
                    'metadata' => $identity['metadata'],
                    'last_authenticated_at' => now(),
                ])->save();

                return $this->loginResponse($socialAccount->user, 'Login com Instagram realizado com sucesso!');
            }

            $completionToken = Str::random(80);
            SocialAuthChallenge::query()
                ->where('expires_at', '<', now()->subDay())
                ->delete();

            SocialAuthChallenge::create([
                'token_hash' => hash('sha256', $completionToken),
                'provider' => self::PROVIDER_INSTAGRAM,
                ...$identity,
                'expires_at' => now()->addMinutes(15),
            ]);

            return response()->json([
                'requires_completion' => true,
                'completion_token' => $completionToken,
                'expires_in' => 900,
                'provider' => self::PROVIDER_INSTAGRAM,
                'account' => [
                    'username' => $identity['username'],
                    'display_name' => $identity['display_name'],
                    'avatar_url' => $identity['avatar_url'],
                    'account_type' => $accountType ?: null,
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::error('Instagram authentication failed.', [
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'O login com Instagram está temporariamente indisponível.',
                'code' => 'instagram_auth_failed',
            ], 503);
        }
    }

    public function instagramComplete(array $data)
    {
        $email = strtolower(trim($data['email']));
        $challengeHash = hash('sha256', $data['completion_token']);
        $verificationCode = $this->newCode(6);

        $result = DB::transaction(function () use ($challengeHash, $email, $data, $verificationCode) {
            $challenge = SocialAuthChallenge::query()
                ->where('token_hash', $challengeHash)
                ->lockForUpdate()
                ->first();

            if (! $challenge || ! $challenge->isAvailable()) {
                return ['status' => 'expired'];
            }

            if (User::query()->where('email', $email)->exists()) {
                return ['status' => 'existing'];
            }

            $firstName = trim((string) ($data['first_name'] ?? ''));
            if ($firstName === '') {
                $firstName = trim((string) ($challenge->display_name ?: $challenge->username ?: 'Usuário')) ?: 'Usuário';
            }

            $user = User::create([
                'first_name' => Str::limit($firstName, 100, ''),
                'email' => $email,
                'password' => Hash::make(Str::random(48)),
                'user_name' => $this->uniqueUsername($challenge->username ?: $firstName),
                'verification_code' => Hash::make($verificationCode),
                'verification_code_expires_at' => now()->addMinutes(30),
            ]);

            UserSocialAccount::create([
                'user_id' => $user->id,
                'provider' => $challenge->provider,
                'provider_user_id' => $challenge->provider_user_id,
                'username' => $challenge->username,
                'display_name' => $challenge->display_name,
                'avatar_url' => $challenge->avatar_url,
                'metadata' => $challenge->metadata,
                'connected_at' => now(),
                'last_authenticated_at' => now(),
            ]);

            $challenge->forceFill(['consumed_at' => now()])->save();

            return ['status' => 'created', 'user' => $user];
        });

        if ($result['status'] === 'expired') {
            return response()->json([
                'message' => 'A confirmação do Instagram expirou. Inicie o login novamente.',
                'code' => 'instagram_completion_expired',
            ], 419);
        }

        if ($result['status'] === 'existing') {
            return response()->json([
                'message' => 'Este e-mail já possui uma conta. Entre com sua conta atual para vincular o Instagram com segurança.',
                'code' => 'instagram_existing_account_requires_auth',
                'requires_account_auth' => true,
                'email' => $email,
            ], 409);
        }

        $user = $result['user'];
        try {
            Mail::to($user->email)->queue(new VerificationCodeMail($verificationCode, $user));
        } catch (\Throwable $exception) {
            Log::warning('Instagram account created but verification email failed.', [
                'user_id' => $user->id,
                'message' => $exception->getMessage(),
            ]);
        }

        return $this->loginResponse($user, 'Conta criada com Instagram. Confirme seu e-mail para continuar.');
    }

    public function instagramLink(User $user, array $data)
    {
        $challengeHash = hash('sha256', $data['completion_token']);

        $result = DB::transaction(function () use ($challengeHash, $user) {
            $challenge = SocialAuthChallenge::query()
                ->where('token_hash', $challengeHash)
                ->lockForUpdate()
                ->first();

            if (! $challenge || ! $challenge->isAvailable()) {
                return ['status' => 'expired'];
            }

            $existing = UserSocialAccount::query()
                ->where('provider', $challenge->provider)
                ->where('provider_user_id', $challenge->provider_user_id)
                ->first();

            if ($existing && (int) $existing->user_id !== (int) $user->id) {
                return ['status' => 'conflict'];
            }

            UserSocialAccount::updateOrCreate([
                'provider' => $challenge->provider,
                'provider_user_id' => $challenge->provider_user_id,
            ], [
                'user_id' => $user->id,
                'username' => $challenge->username,
                'display_name' => $challenge->display_name,
                'avatar_url' => $challenge->avatar_url,
                'metadata' => $challenge->metadata,
                'connected_at' => $existing?->connected_at ?: now(),
                'last_authenticated_at' => now(),
            ]);

            $challenge->forceFill(['consumed_at' => now()])->save();

            return ['status' => 'linked'];
        });

        if ($result['status'] === 'expired') {
            return response()->json([
                'message' => 'A confirmação do Instagram expirou. Inicie o login novamente.',
                'code' => 'instagram_completion_expired',
            ], 419);
        }

        if ($result['status'] === 'conflict') {
            return response()->json([
                'message' => 'Esta conta do Instagram já está vinculada a outro usuário.',
                'code' => 'instagram_already_linked',
            ], 409);
        }

        $this->recordSocialInteraction($user, 'instagram_link', 'Instagram vinculado à conta');

        return response()->json([
            'message' => 'Instagram vinculado à sua conta com sucesso.',
        ]);
    }

    private function instagramConfig(): ?array
    {
        $clientId = trim((string) config('services.instagram.client_id'));
        $clientSecret = trim((string) config('services.instagram.client_secret'));
        $redirectUri = trim((string) config('services.instagram.redirect_uri'));
        $scopes = config('services.instagram.scopes', ['instagram_business_basic']);

        if ($clientId === '' || $clientSecret === '' || $redirectUri === '') {
            return null;
        }

        if (! is_array($scopes)) {
            $scopes = array_values(array_filter(array_map('trim', explode(',', (string) $scopes))));
        }

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'scopes' => $scopes ?: ['instagram_business_basic'],
            'graph_url' => trim((string) config('services.instagram.graph_url', 'https://graph.instagram.com')) ?: 'https://graph.instagram.com',
        ];
    }

    private function validInstagramState(string $state): bool
    {
        try {
            $payload = json_decode(Crypt::decryptString($state), true, 512, JSON_THROW_ON_ERROR);

            return ($payload['provider'] ?? null) === self::PROVIDER_INSTAGRAM
                && isset($payload['expires_at'])
                && (int) $payload['expires_at'] >= now()->timestamp;
        } catch (\Throwable $exception) {
            return false;
        }
    }

    private function loginResponse(User $user, string $message)
    {
        $token = auth()->login($user);
        $this->recordSocialInteraction($user, 'login_instagram', 'Entrou com Instagram');

        return response()->json([
            'message' => $message,
            'token' => [
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => auth()->factory()->getTTL() * 60,
                'user' => $user,
            ],
        ]);
    }

    private function recordSocialInteraction(User $user, string $type, string $name): void
    {
        try {
            Interaction::register($type, $user, $user, [
                'provider' => self::PROVIDER_INSTAGRAM,
                'source_channel' => 'instagram',
            ], $name);
        } catch (\Throwable $exception) {
            Log::warning('Unable to record social authentication interaction.', [
                'user_id' => $user->id,
                'type' => $type,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function uniqueUsername(string $name): string
    {
        $base = Str::slug($name) ?: 'user';

        do {
            $username = $base.'-'.Str::lower(Str::random(6));
        } while (User::query()->where('user_name', $username)->exists());

        return $username;
    }

    private function newCode(int $length): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }
}
