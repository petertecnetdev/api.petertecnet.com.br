<?php

namespace App\Support;

use App\Models\User;

final class EmailVerificationDeferrals
{
    public const MAX_DEFERRALS = 2;
    public const DEFERRALS_KEY = 'email_verification_deferrals';
    public const LAST_DEFERRED_AT_KEY = 'email_verification_last_deferred_at';

    public static function state(User $user): array
    {
        $verified = (bool) $user->email_verified_at;
        $used = self::used($user);
        $remaining = max(0, self::MAX_DEFERRALS - $used);
        $canDefer = ! $verified && $remaining > 0;
        $extraInfo = is_array($user->extra_info) ? $user->extra_info : [];

        return [
            'verified' => $verified,
            'deferrals_used' => $used,
            'deferrals_remaining' => $remaining,
            'max_deferrals' => self::MAX_DEFERRALS,
            'can_defer' => $canDefer,
            'confirmation_required' => ! $verified && ! $canDefer,
            'mandatory_from_prompt' => self::MAX_DEFERRALS + 1,
            'last_deferred_at' => $extraInfo[self::LAST_DEFERRED_AT_KEY] ?? null,
        ];
    }

    public static function used(User $user): int
    {
        $extraInfo = is_array($user->extra_info) ? $user->extra_info : [];
        $used = max(0, (int) ($extraInfo[self::DEFERRALS_KEY] ?? 0));

        return min(self::MAX_DEFERRALS, $used);
    }

    public static function reset(User $user): array
    {
        $extraInfo = is_array($user->extra_info) ? $user->extra_info : [];

        unset(
            $extraInfo[self::DEFERRALS_KEY],
            $extraInfo[self::LAST_DEFERRED_AT_KEY],
        );

        $user->forceFill(['extra_info' => $extraInfo])->save();
        $user->refresh();

        return self::state($user);
    }
}
