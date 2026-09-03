<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\Models\IdentityCredential;
use App\Domain\Identity\Models\IdentityGlobalSession;
use App\Domain\Identity\Models\IdentitySecuritySetting;
use App\Domain\Identity\Models\IdentitySession;
use App\Domain\Identity\Models\IdentityTrustedDevice;
use App\Domain\Identity\Rules\IdentityPassword;
use App\Domain\Identity\Services\CompromisedPasswordService;
use App\Domain\Identity\Services\IdentityAuditService;
use App\Domain\Identity\Services\IdentityGlobalSessionService;
use App\Domain\Identity\Services\IdentityOperationsService;
use App\Domain\Identity\Services\IdentitySessionService;
use App\Domain\Identity\Services\IdentityStepUpService;
use App\Domain\Identity\Services\IdentityTrustedDeviceService;
use App\Domain\Identity\Services\PasskeyService;
use App\Domain\Identity\Services\TotpService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class IdentityExperienceController extends Controller
{
    public function __construct(
        private readonly IdentityStepUpService $stepUp,
        private readonly IdentitySessionService $sessions,
        private readonly IdentityGlobalSessionService $globalSessions,
        private readonly IdentityTrustedDeviceService $trustedDevices,
        private readonly IdentityOperationsService $operations,
        private readonly IdentityAuditService $audit,
        private readonly TotpService $totp,
        private readonly PasskeyService $passkeys,
        private readonly CompromisedPasswordService $compromisedPasswords,
    ) {
    }

    public function protocol(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'protocol' => '3.0',
                'identity' => 'Peter Identity',
                'sso' => (bool) config('identity.features.global_sso', true),
                'step_up' => (bool) config('identity.features.step_up', true),
                'passkeys' => (bool) config('identity.features.passkeys', true),
                'magic_link' => (bool) config('identity.features.magic_link', true),
                'trusted_devices' => (bool) config('identity.features.trusted_devices', true),
            ],
        ]);
    }

    public function stepUpOptions(Request $request): JsonResponse
    {
        $user = $request->user('api');
        $settings = IdentitySecuritySetting::query()->firstOrCreate(['user_id' => $user->id]);

        return response()->json([
            'success' => true,
            'data' => [
                'methods' => [
                    'passkey' => (bool) config('identity.features.passkeys', true)
                        && IdentityCredential::query()->where('user_id', $user->id)->where('type', 'passkey')->exists(),
                    'totp' => (bool) config('identity.features.totp', true)
                        && (bool) $settings->two_factor_enabled
                        && (bool) $settings->two_factor_secret,
                    'password' => (bool) config('identity.features.password', true) && (bool) $user->password,
                ],
                'ttl_seconds' => (int) config('identity.step_up.ttl_seconds', 600),
            ],
        ]);
    }

    public function stepUpPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'max:120'],
            'current_password' => ['required', 'string', 'max:255'],
        ]);
        $user = $request->user('api');
        if (! Hash::check($data['current_password'], (string) $user->password)) {
            $this->audit->record('step_up_failed', $user, $request, $this->sessions->current()?->application, ['action' => $data['action'], 'method' => 'password'], true);
            return response()->json(['success' => false, 'code' => 'STEP_UP_FAILED', 'message' => 'Senha atual incorreta.'], 401);
        }
        return $this->grant($request, $data['action'], 'password');
    }

    public function stepUpTotp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:32'],
        ]);
        $user = $request->user('api');
        $settings = IdentitySecuritySetting::query()->where('user_id', $user->id)->first();
        $code = preg_replace('/\D/', '', $data['code']) ?? '';
        if (! $settings?->two_factor_enabled || ! $settings->two_factor_secret || ! $this->totp->verify($settings->two_factor_secret, $code)) {
            $this->audit->record('step_up_failed', $user, $request, $this->sessions->current()?->application, ['action' => $data['action'], 'method' => 'totp'], true);
            return response()->json(['success' => false, 'code' => 'STEP_UP_FAILED', 'message' => 'Código 2FA inválido.'], 401);
        }
        return $this->grant($request, $data['action'], 'totp');
    }

    public function stepUpPasskey(Request $request): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'string', 'max:120']]);
        $authenticated = $this->passkeys->authenticate($request);
        $user = $request->user('api');
        if ((int) $authenticated->id !== (int) $user->id) {
            $this->audit->record('step_up_failed', $user, $request, $this->sessions->current()?->application, ['action' => $data['action'], 'method' => 'passkey'], true);
            return response()->json(['success' => false, 'code' => 'STEP_UP_FAILED', 'message' => 'Passkey pertence a outra conta.'], 401);
        }
        return $this->grant($request, $data['action'], 'passkey');
    }

    public function devices(Request $request): JsonResponse
    {
        $user = $request->user('api');
        $rows = collect();

        foreach ($this->trustedDevices->activeFor($user) as $device) {
            $rows->push([
                'device_id' => $device->device_id,
                'name' => $device->name,
                'trusted' => true,
                'browser' => $device->browser,
                'platform' => $device->platform,
                'last_ip_address' => $device->ip_address,
                'country' => $device->country_code,
                'first_seen_at' => $device->created_at?->toIso8601String(),
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                'expires_at' => $device->expires_at?->toIso8601String(),
            ]);
        }

        $trustedIds = $rows->pluck('device_id')->all();
        foreach ($this->sessions->activeFor($user) as $session) {
            if ($session->trustedDevice && in_array($session->trustedDevice->device_id, $trustedIds, true)) {
                continue;
            }
            $rows->push([
                'device_id' => 'session:'.$session->session_id,
                'name' => $session->nickname ?: $session->device_label,
                'trusted' => false,
                'browser' => $this->browserFromLabel($session->device_label),
                'platform' => $this->platformFromLabel($session->device_label),
                'last_ip_address' => $session->ip_address,
                'country' => null,
                'first_seen_at' => $session->created_at?->toIso8601String(),
                'last_seen_at' => $session->last_seen_at?->toIso8601String(),
                'expires_at' => $session->expires_at?->toIso8601String(),
            ]);
        }

        return response()->json(['success' => true, 'data' => $rows->values()]);
    }

    public function renameDevice(Request $request, string $deviceId): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:180']]);
        $user = $request->user('api');

        if (str_starts_with($deviceId, 'session:')) {
            $session = $this->sessions->rename($user, substr($deviceId, 8), $data['name']);
            return response()->json(['success' => true, 'data' => ['device_id' => $deviceId, 'name' => $session->nickname, 'trusted' => false]]);
        }

        $device = IdentityTrustedDevice::query()->where('user_id', $user->id)->where('device_id', $deviceId)->firstOrFail();
        $device->forceFill(['name' => trim($data['name'])])->save();
        $this->audit->record('trusted_device_renamed', $user, $request, $this->sessions->current()?->application, ['device_id' => $deviceId]);
        return response()->json(['success' => true, 'data' => $this->trustedDevices->present($device)]);
    }

    public function trustDevice(Request $request, string $deviceId): JsonResponse
    {
        $data = $request->validate(['trusted' => ['required', 'boolean']]);
        $user = $request->user('api');

        if (! $data['trusted']) {
            $device = IdentityTrustedDevice::query()->where('user_id', $user->id)->where('device_id', $deviceId)->firstOrFail();
            $this->trustedDevices->revoke($device, 'trust_removed');
            $this->audit->record('trusted_device_revoked', $user, $request, $this->sessions->current()?->application, ['device_id' => $deviceId], true);
            return response()->json(['success' => true, 'data' => ['device_id' => $deviceId, 'trusted' => false]])
                ->withCookie($this->trustedDevices->forgetCookie());
        }

        abort_unless(str_starts_with($deviceId, 'session:'), 409, 'O dispositivo já possui identidade própria.');
        $sessionId = substr($deviceId, 8);
        $current = $this->sessions->current();
        abort_unless($current && hash_equals((string) $current->session_id, $sessionId), 409, 'Por segurança, marque um dispositivo como confiável a partir do próprio dispositivo.');

        $issued = $this->trustedDevices->trust($user, $request, $current->nickname ?: $current->device_label);
        $current->forceFill(['trusted_device_id' => $issued['device']->id])->save();
        $this->audit->record('trusted_device_added', $user, $request, $current->application, ['device_id' => $issued['device']->device_id], true);

        return response()->json(['success' => true, 'data' => $this->trustedDevices->present($issued['device'])])
            ->withCookie($issued['cookie']);
    }

    public function revokeDevice(Request $request, string $deviceId): JsonResponse
    {
        $user = $request->user('api');

        if (str_starts_with($deviceId, 'session:')) {
            $session = IdentitySession::query()
                ->where('user_id', $user->id)
                ->where('session_id', substr($deviceId, 8))
                ->firstOrFail();
            $this->sessions->revoke($session, 'device_revoked');
            return response()->json(['success' => true]);
        }

        $device = IdentityTrustedDevice::query()->where('user_id', $user->id)->where('device_id', $deviceId)->firstOrFail();
        $this->trustedDevices->revoke($device, 'device_revoked');
        IdentitySession::query()
            ->where('user_id', $user->id)
            ->where('trusted_device_id', $device->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoke_reason' => 'device_revoked', 'updated_at' => now()]);
        foreach (IdentityGlobalSession::query()->where('user_id', $user->id)->where('trusted_device_id', $device->id)->whereNull('revoked_at')->get() as $global) {
            $this->globalSessions->revoke($global, 'device_revoked');
        }
        $this->audit->record('device_revoked', $user, $request, $this->sessions->current()?->application, ['device_id' => $deviceId], true);

        return response()->json(['success' => true])->withCookie($this->trustedDevices->forgetCookie());
    }

    public function observability(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $minutes = (int) $request->query('minutes', 60);
        return response()->json(['success' => true, 'data' => $this->operations->observability($minutes)]);
    }

    public function rollout(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        return response()->json(['success' => true, 'data' => $this->operations->rollout()]);
    }

    public function updateRollout(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'default_percentage' => ['required', 'integer', 'between:0,100'],
            'applications' => ['nullable', 'array'],
            'applications.*.percentage' => ['nullable', 'integer', 'between:0,100'],
        ]);
        $updated = $this->operations->updateRollout($request->user('api'), $data);
        $this->audit->record('rollout_updated', $request->user('api'), $request, $this->sessions->current()?->application, $updated, true);
        return response()->json(['success' => true, 'data' => $updated]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', new IdentityPassword($this->compromisedPasswords)],
            'password_confirmation' => ['required', 'same:password'],
        ]);
        $user = $request->user('api');
        $user->forceFill(['password' => Hash::make($data['password'])])->save();
        $appCount = $this->sessions->revokeAll($user, 'password_changed');
        $globalCount = $this->globalSessions->revokeAll($user, 'password_changed');
        $deviceCount = $this->trustedDevices->revokeAll($user, 'password_changed');
        $this->stepUp->revokeAll($user);
        $this->audit->record('password_changed', $user, $request, null, [
            'application_sessions_revoked' => $appCount,
            'global_sessions_revoked' => $globalCount,
            'trusted_devices_revoked' => $deviceCount,
        ], true);

        $response = response()->json(['success' => true, 'message' => 'Senha alterada. Todas as sessões anteriores foram encerradas.'])
            ->withCookie($this->trustedDevices->forgetCookie());
        foreach ($this->globalSessions->forgetCookies() as $cookie) {
            $response->withCookie($cookie);
        }
        return $response;
    }

    private function grant(Request $request, string $action, string $method): JsonResponse
    {
        $user = $request->user('api');
        $issued = $this->stepUp->issue($user, $request, $action, $method, $this->sessions->current());
        IdentitySecuritySetting::query()->firstOrCreate(['user_id' => $user->id])
            ->forceFill(['last_step_up_at' => now()])->save();
        $this->audit->record('step_up_verified', $user, $request, $this->sessions->current()?->application, ['action' => $issued['action'], 'method' => $method], true);
        return response()->json(['success' => true, 'data' => $issued]);
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless($request->user('api')?->hasProfile('Administrador'), 403, 'Acesso administrativo necessário.');
    }

    private function browserFromLabel(?string $label): ?string
    {
        foreach (['Chrome', 'Edge', 'Firefox', 'Safari', 'Opera'] as $browser) {
            if (str_contains((string) $label, $browser)) return $browser;
        }
        return null;
    }

    private function platformFromLabel(?string $label): ?string
    {
        foreach (['Windows', 'macOS', 'Android', 'iOS', 'Linux'] as $platform) {
            if (str_contains((string) $label, $platform)) return $platform;
        }
        return null;
    }
}
