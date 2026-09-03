<?php

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\Models\IdentityCredential;
use App\Domain\Identity\Models\IdentitySecuritySetting;
use App\Domain\Identity\Services\IdentityAuditService;
use App\Domain\Identity\Services\IdentityStepUpService;
use App\Domain\Identity\Services\PasskeyService;
use App\Domain\Identity\Services\TotpService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class IdentityStepUpController extends Controller
{
    public function __construct(
        private readonly IdentityStepUpService $stepUp,
        private readonly TotpService $totp,
        private readonly PasskeyService $passkeys,
        private readonly IdentityAuditService $audit,
    ) {
    }

    public function options(Request $request): JsonResponse
    {
        $user = $request->user('api');
        $settings = IdentitySecuritySetting::query()->where('user_id', $user->id)->first();
        $hasPasskeys = IdentityCredential::query()->where('user_id', $user->id)->where('type', 'passkey')->exists();

        return response()->json([
            'success' => true,
            'data' => [
                'methods' => [
                    'password' => (bool) $user->password,
                    'totp' => (bool) ($settings?->two_factor_enabled && $settings?->two_factor_secret),
                    'passkey' => $hasPasskeys,
                ],
                'actions' => $this->stepUp->allowedActions(),
                'ttl_seconds' => max((int) config('identity.step_up.ttl_minutes', 10), 1) * 60,
            ],
        ]);
    }

    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'max:80'],
            'current_password' => ['required', 'string', 'max:255'],
        ]);
        $user = $request->user('api');

        if (! Hash::check($data['current_password'], (string) $user->password)) {
            $this->audit->record('step_up_failed', $user, $request, null, ['method' => 'password', 'action' => $data['action']]);
            return response()->json(['success' => false, 'message' => 'Senha atual incorreta.', 'code' => 'STEP_UP_FAILED'], 401);
        }

        return $this->granted($request, $data['action'], 'password');
    }

    public function totp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'max:80'],
            'code' => ['required', 'string', 'max:32'],
        ]);
        $user = $request->user('api');
        $settings = IdentitySecuritySetting::query()->where('user_id', $user->id)->first();

        if (! $settings || ! $settings->two_factor_enabled || ! $settings->two_factor_secret) {
            return response()->json(['success' => false, 'message' => '2FA não está ativo.', 'code' => 'TWO_FACTOR_NOT_ENABLED'], 409);
        }

        $digits = preg_replace('/\D/', '', $data['code']) ?? '';
        $valid = $this->totp->verify($settings->two_factor_secret, $digits);
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
            $this->audit->record('step_up_failed', $user, $request, null, ['method' => 'totp', 'action' => $data['action']]);
            return response()->json(['success' => false, 'message' => 'Código de verificação inválido.', 'code' => 'STEP_UP_FAILED'], 401);
        }

        return $this->granted($request, $data['action'], 'totp');
    }

    public function passkey(Request $request): JsonResponse
    {
        $action = $request->validate(['action' => ['required', 'string', 'max:80']])['action'];
        $user = $this->passkeys->authenticate($request);

        if ((int) $user->id !== (int) $request->user('api')->id) {
            $this->audit->record('step_up_failed', $request->user('api'), $request, null, ['method' => 'passkey', 'action' => $action]);
            return response()->json(['success' => false, 'message' => 'Passkey pertence a outra conta.', 'code' => 'STEP_UP_FAILED'], 403);
        }

        return $this->granted($request, $action, 'passkey');
    }

    private function granted(Request $request, string $action, string $method): JsonResponse
    {
        $grant = $this->stepUp->issue($request->user('api'), $request, $action, $method);
        $this->audit->record('step_up_granted', $request->user('api'), $request, null, ['method' => $method, 'action' => $action]);

        return response()->json(['success' => true, 'data' => $grant]);
    }
}
