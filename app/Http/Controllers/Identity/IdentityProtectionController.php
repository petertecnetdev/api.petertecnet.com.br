<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\Models\IdentitySecuritySetting;
use App\Domain\Identity\Models\IdentityTrustedDevice;
use App\Domain\Identity\Services\IdentityAuditService;
use App\Domain\Identity\Services\IdentityGlobalSessionService;
use App\Domain\Identity\Services\IdentitySessionService;
use App\Domain\Identity\Services\IdentityStepUpService;
use App\Domain\Identity\Services\IdentityTrustedDeviceService;
use App\Domain\Identity\Services\PasskeyService;
use App\Domain\Identity\Services\TotpService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class IdentityProtectionController extends Controller
{
    public function __construct(
        private readonly IdentityStepUpService $stepUp,
        private readonly IdentitySessionService $sessions,
        private readonly IdentityGlobalSessionService $globalSessions,
        private readonly IdentityTrustedDeviceService $trustedDevices,
        private readonly IdentityAuditService $audit,
        private readonly TotpService $totp,
        private readonly PasskeyService $passkeys,
    ) {
    }

    public function capabilities(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'methods' => [
                    'password' => (bool) config('identity.features.password', true),
                    'google' => (bool) config('identity.features.google', true),
                    'magic_link' => (bool) config('identity.features.magic_link', true),
                    'passkey' => (bool) config('identity.features.passkeys', true),
                    'totp' => (bool) config('identity.features.totp', true),
                    'phone' => (bool) config('identity.features.phone', true),
                ],
                'features' => config('identity.features', []),
                'password_policy' => [
                    'min_length' => (int) config('identity.password.min_length', 8),
                    'uppercase' => (bool) config('identity.password.require_uppercase', true),
                    'lowercase' => (bool) config('identity.password.require_lowercase', true),
                    'number' => (bool) config('identity.password.require_number', true),
                    'symbol' => (bool) config('identity.password.require_symbol', true),
                    'compromised_check' => (bool) config('identity.password.compromised_check', true),
                ],
                'session' => [
                    'access_token_ttl_minutes' => (int) config('identity.access_token_ttl_minutes', 30),
                    'idle_ttl_minutes' => (int) config('identity.session.idle_ttl_minutes', 10080),
                    'admin_idle_ttl_minutes' => (int) config('identity.session.admin_idle_ttl_minutes', 120),
                ],
                'step_up' => [
                    'ttl_seconds' => (int) config('identity.step_up.ttl_seconds', 600),
                    'high_risk_score' => (int) config('identity.step_up.high_risk_score', 55),
                ],
            ],
        ]);
    }

    public function stepUp(Request $request): JsonResponse
    {
        abort_unless(config('identity.features.step_up', true), 404);
        $data = $request->validate([
            'action' => ['required', 'string', 'max:120'],
            'method' => ['required', 'string', 'in:password,totp,recovery_code,passkey'],
            'password' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:64'],
        ]);

        $user = $request->user('api');
        $method = $data['method'];
        $verified = false;
        $setting = IdentitySecuritySetting::query()->firstOrCreate(['user_id' => $user->id]);

        if ($method === 'password') {
            $verified = isset($data['password']) && Hash::check((string) $data['password'], (string) $user->password);
        } elseif ($method === 'totp') {
            $verified = $setting->two_factor_enabled
                && $setting->two_factor_secret
                && $this->totp->verify($setting->two_factor_secret, preg_replace('/\D/', '', (string) ($data['code'] ?? '')) ?: '');
        } elseif ($method === 'recovery_code') {
            $hash = hash('sha256', strtoupper(trim((string) ($data['code'] ?? ''))));
            $codes = $setting->two_factor_recovery_codes ?: [];
            $index = array_search($hash, $codes, true);
            if ($index !== false) {
                unset($codes[$index]);
                $setting->forceFill([
                    'two_factor_recovery_codes' => array_values($codes),
                    'last_recovery_code_used_at' => now(),
                ])->save();
                $verified = true;
                $this->audit->record('recovery_code_used', $user, $request, null, ['remaining_codes' => count($codes)], true);
            }
        } else {
            $passkeyUser = $this->passkeys->authenticate($request);
            $verified = (int) $passkeyUser->id === (int) $user->id;
        }

        if (! $verified) {
            $this->audit->record('step_up_failed', $user, $request, null, ['action' => $data['action'], 'method' => $method], true);
            return response()->json(['success' => false, 'code' => 'STEP_UP_FAILED', 'message' => 'Não foi possível confirmar sua identidade.'], 401);
        }

        $issued = $this->stepUp->issue($user, $request, $data['action'], $method, $this->sessions->current());
        $setting->forceFill(['last_step_up_at' => now()])->save();
        $this->audit->record('step_up_verified', $user, $request, null, ['action' => $issued['action'], 'method' => $method], true);

        return response()->json(['success' => true, 'data' => $issued]);
    }

    public function trustedDevices(Request $request): JsonResponse
    {
        $user = $request->user('api');
        $data = $this->trustedDevices->activeFor($user)->map(fn ($device) => $this->trustedDevices->present($device))->values();
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function trustCurrentDevice(Request $request): JsonResponse
    {
        abort_unless(config('identity.features.trusted_devices', true), 404);
        $data = $request->validate(['name' => ['nullable', 'string', 'max:180']]);
        $user = $request->user('api');
        $issued = $this->trustedDevices->trust($user, $request, $data['name'] ?? null);
        $this->audit->record('trusted_device_added', $user, $request, null, ['device_id' => $issued['device']->device_id], true);

        return response()->json([
            'success' => true,
            'data' => $this->trustedDevices->present($issued['device']),
        ])->withCookie($issued['cookie']);
    }

    public function revokeTrustedDevice(Request $request, string $deviceId): JsonResponse
    {
        $user = $request->user('api');
        $device = IdentityTrustedDevice::query()->where('user_id', $user->id)->where('device_id', $deviceId)->firstOrFail();
        $this->trustedDevices->revoke($device);
        $this->audit->record('trusted_device_revoked', $user, $request, null, ['device_id' => $deviceId], true);
        return response()->json(['success' => true]);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user('api');
        $setting = IdentitySecuritySetting::query()->where('user_id', $user->id)->first();
        abort_unless($setting?->two_factor_enabled, 409, 'Ative o 2FA antes de gerar códigos de recuperação.');

        $codes = $this->totp->recoveryCodes();
        $setting->forceFill([
            'two_factor_recovery_codes' => $this->totp->hashRecoveryCodes($codes),
            'recovery_codes_generated_at' => now(),
            'last_recovery_code_used_at' => null,
        ])->save();
        $this->audit->record('recovery_codes_regenerated', $user, $request, null, ['count' => count($codes)], true);

        return response()->json(['success' => true, 'data' => ['recovery_codes' => $codes]]);
    }

    public function reportNotMe(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);
        $challenge = app(\App\Domain\Identity\Services\IdentityChallengeService::class)->consume('security_not_me', $data['token']);
        if (! $challenge || ! $challenge->user) {
            return response()->json(['success' => false, 'code' => 'SECURITY_LINK_INVALID', 'message' => 'Este link de segurança expirou ou já foi utilizado.'], 410);
        }

        $user = $challenge->user;
        $this->sessions->revokeAll($user, 'security_report_not_me');
        $this->globalSessions->revokeAll($user, 'security_report_not_me');
        $this->trustedDevices->revokeAll($user, 'security_report_not_me');
        $this->stepUp->revokeAll($user);
        $user->forceFill(['auth_version' => max((int) ($user->auth_version ?? 1), 1) + 1])->save();
        $this->audit->record('security_not_me_confirmed', $user, $request, $challenge->application, $challenge->payload ?: [], true);

        return response()->json([
            'success' => true,
            'message' => 'Todas as sessões e dispositivos confiáveis foram revogados. Redefina sua senha para concluir a proteção da conta.',
            'recovery_required' => true,
        ]);
    }
}
