<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\EmailVerificationDeferrals;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EmailVerificationDeferralController extends Controller
{
    public function state(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        return response()->json([
            'email_verification' => EmailVerificationDeferrals::state($user),
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
                        'email_verification' => EmailVerificationDeferrals::state($user),
                    ],
                ];
            }

            $used = EmailVerificationDeferrals::used($user);

            if ($used >= EmailVerificationDeferrals::MAX_DEFERRALS) {
                return [
                    'status' => 403,
                    'body' => [
                        'message' => 'O limite de adiamentos foi atingido. Confirme seu e-mail ou saia da conta.',
                        'email_verification' => EmailVerificationDeferrals::state($user),
                    ],
                ];
            }

            $extraInfo = is_array($user->extra_info) ? $user->extra_info : [];
            $extraInfo[EmailVerificationDeferrals::DEFERRALS_KEY] = $used + 1;
            $extraInfo[EmailVerificationDeferrals::LAST_DEFERRED_AT_KEY] = now()->toIso8601String();

            $user->forceFill(['extra_info' => $extraInfo])->save();
            $user->refresh();

            return [
                'status' => 200,
                'body' => [
                    'message' => 'Você pode confirmar o e-mail depois nesta sessão.',
                    'email_verification' => EmailVerificationDeferrals::state($user),
                ],
            ];
        });

        return response()->json($result['body'], $result['status']);
    }
}
