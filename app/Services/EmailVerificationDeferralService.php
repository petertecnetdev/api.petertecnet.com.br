<?php

namespace App\Services;

use App\Models\User;
use App\Support\EmailVerificationDeferrals;
use Illuminate\Support\Facades\DB;

final class EmailVerificationDeferralService
{
    public function stateForUserId(int $userId): array
    {
        $user = User::query()->findOrFail($userId);

        return EmailVerificationDeferrals::state($user);
    }

    public function deferForUserId(int $userId): array
    {
        return DB::transaction(function () use ($userId): array {
            $user = User::query()->lockForUpdate()->findOrFail($userId);

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
    }

    public function reset(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $before = EmailVerificationDeferrals::state($lockedUser);
            $after = EmailVerificationDeferrals::reset($lockedUser);

            return [
                'before' => $before,
                'after' => $after,
            ];
        });
    }
}
