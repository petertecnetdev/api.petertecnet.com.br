<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EmailVerificationDeferralController extends Controller
{
    private const MAX_DEFERRALS = 2;
    private const DEFERRALS_KEY = 'email_verification_deferrals';
    private const LAST_DEFERRED_AT_KEY = 'email_verification_last_deferred_at';

    public function state(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        return response()->json([
            'email_verification' => $this->stateFor($user),
        ]);
    }

    public function defer(): JsonResponse
    {
        $result = DB::transaction(function (): array {
            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail(Auth::id());

            if ($user->email_verified_at) {
                return [
                    'status' => 409,
                    'body' => [
                        'message' => 'Seu e-mail já está confirmado.',
                        'email_verification' => $this->stateFor($user),
                    ],
                ];
            }

            $used = $this->deferralsUsed($user);

            if ($used >= self::MAX_DEFERRALS) {
                return [
                    'status' => 403,
                    'body' => [
                        'message' => 'O limite de adiamentos foi atingido. Confirme seu e-mail ou saia da conta.',
                        'email_verification' => $this->stateFor($user),
                    ],
                ];
            }

            $extraInfo = is_array($user->extra_info) ? $user->extra_info : [];
            $extraInfo[self::DEFERRALS_KEY] = $used + 1;
            $extraInfo[self::LAST_DEFERRED_AT_KEY] = now()->toIso8601String();

            $user->forceFill(['extra_info' => $extraInfo])->save();
            $user->refresh();

            return [
                'status' => 200,
                'body' => [
                    'message' => 'Você pode confirmar o e-mail depois nesta sessão.',
                    'email_verification' => $this->stateFor($user),
                ],
            ];
        });

        return response()->json($result['body'], $result['status']);
    }

    private function stateFor(User $user): array
    {
        $verified = (bool) $user->email_verified_at;
        $used = $this->deferralsUsed($user);
        $remaining = max(0, self::MAX_DEFERRALS - $used);
        $canDefer = ! $verified && $remaining > 0;

        return [
            'verified' => $verified,
            'deferrals_used' => $used,
            'deferrals_remaining' => $remaining,
            'can_defer' => $canDefer,
            'confirmation_required' => ! $verified && ! $canDefer,
            'mandatory_from_prompt' => self::MAX_DEFERRALS + 1,
        ];
    }

    private function deferralsUsed(User $user): int
    {
        $extraInfo = is_array($user->extra_info) ? $user->extra_info : [];
        $used = max(0, (int) ($extraInfo[self::DEFERRALS_KEY] ?? 0));

        return min(self::MAX_DEFERRALS, $used);
    }
}
