<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\Models\IdentityCredential;
use App\Domain\Identity\Models\IdentitySecuritySetting;
use App\Domain\Identity\Services\IdentityAuditService;
use App\Domain\Identity\Services\IdentitySessionService;
use App\Domain\Identity\Services\TotpService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class IdentitySecurityController extends Controller
{
    public function __construct(
        private readonly TotpService $totp,
        private readonly IdentityAuditService $audit,
        private readonly IdentitySessionService $sessions,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user('api');
        $settings = IdentitySecuritySetting::query()->firstOrCreate(['user_id' => $user->id]);

        return response()->json([
            'success' => true,
            'data' => [
                'two_factor_enabled' => (bool) $settings->two_factor_enabled,
                'passkeys' => IdentityCredential::query()
                    ->where('user_id', $user->id)
                    ->where('type', 'passkey')
                    ->orderByDesc('last_used_at')
                    ->get()
                    ->map(fn ($credential) => [
                        'id' => $credential->id,
                        'name' => $credential->name,
                        'transports' => $credential->transports ?: [],
                        'last_used_at' => $credential->last_used_at?->toIso8601String(),
                        'created_at' => $credential->created_at?->toIso8601String(),
                    ])->values(),
                'policy' => [
                    'password_min_length' => (int) config('identity.password.min_length', 8),
                    'password_require_uppercase' => (bool) config('identity.password.require_uppercase', true),
                    'password_require_lowercase' => (bool) config('identity.password.require_lowercase', true),
                    'password_require_number' => (bool) config('identity.password.require_number', true),
                    'password_require_symbol' => (bool) config('identity.password.require_symbol', true),
                    'compromised_password_check' => (bool) config('identity.password.compromised_check', true),
                    'passkeys_available' => extension_loaded('openssl'),
                    'magic_links_available' => true,
                ],
            ],
        ]);
    }

    public function beginTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string', 'max:255']]);
        $user = $request->user('api');

        if (! Hash::check($data['current_password'], (string) $user->password)) {
            return response()->json(['success' => false, 'message' => 'Senha atual incorreta.', 'code' => 'PASSWORD_INVALID'], 401);
        }

        $settings = IdentitySecuritySetting::query()->firstOrCreate(['user_id' => $user->id]);
        $secret = $this->totp->generateSecret();
        $settings->forceFill([
            'two_factor_enabled' => false,
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->audit->record('two_factor_setup_started', $user, $request, $this->sessions->current()?->application);

        return response()->json([
            'success' => true,
            'data' => [
                'secret' => $secret,
                'otpauth_uri' => $this->totp->uri($secret, (string) $user->email),
                'issuer' => (string) config('identity.two_factor.issuer', 'Peter Tecnet'),
            ],
        ]);
    }

    public function confirmTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $user = $request->user('api');
        $settings = IdentitySecuritySetting::query()->where('user_id', $user->id)->first();

        if (! $settings || ! $settings->two_factor_secret) {
            return response()->json(['success' => false, 'message' => 'Inicie a configuração do 2FA primeiro.', 'code' => 'TWO_FACTOR_SETUP_REQUIRED'], 409);
        }

        $code = preg_replace('/\D/', '', $data['code']) ?? '';
        if (! $this->totp->verify($settings->two_factor_secret, $code)) {
            return response()->json(['success' => false, 'message' => 'Código inválido.', 'code' => 'TWO_FACTOR_INVALID'], 422);
        }

        $recovery = $this->totp->recoveryCodes();
        $settings->forceFill([
            'two_factor_enabled' => true,
            'two_factor_recovery_codes' => $this->totp->hashRecoveryCodes($recovery),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->audit->record('two_factor_enabled', $user, $request, $this->sessions->current()?->application, [], true);

        return response()->json([
            'success' => true,
            'message' => 'Verificação em duas etapas ativada.',
            'recovery_codes' => $recovery,
        ]);
    }

    public function disableTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32'],
        ]);
        $user = $request->user('api');

        if (! Hash::check($data['current_password'], (string) $user->password)) {
            return response()->json(['success' => false, 'message' => 'Senha atual incorreta.', 'code' => 'PASSWORD_INVALID'], 401);
        }

        $settings = IdentitySecuritySetting::query()->where('user_id', $user->id)->first();
        if (! $settings || ! $settings->two_factor_enabled || ! $settings->two_factor_secret) {
            return response()->json(['success' => true, 'message' => '2FA já está desativado.']);
        }

        $code = preg_replace('/\D/', '', $data['code']) ?? '';
        $valid = $this->totp->verify($settings->two_factor_secret, $code);
        if (! $valid) {
            $hash = hash('sha256', strtoupper(trim($data['code'])));
            $valid = in_array($hash, $settings->two_factor_recovery_codes ?: [], true);
        }

        if (! $valid) {
            return response()->json(['success' => false, 'message' => 'Código 2FA inválido.', 'code' => 'TWO_FACTOR_INVALID'], 422);
        }

        $settings->forceFill([
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->audit->record('two_factor_disabled', $user, $request, $this->sessions->current()?->application, [], true);

        return response()->json(['success' => true, 'message' => 'Verificação em duas etapas desativada.']);
    }
}
