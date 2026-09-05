<?php

namespace App\Domain\Messaging\Services;

use Illuminate\Support\Facades\DB;

class AppMessagingPrivacyService
{
    public function blockStatus(int $userId, int $targetUserId, int $applicationId): array
    {
        $blockedByMe = DB::table('app_messaging_blocks')
            ->where('application_id', $applicationId)
            ->where('blocker_user_id', $userId)
            ->where('blocked_user_id', $targetUserId)
            ->exists();

        $blockedMe = DB::table('app_messaging_blocks')
            ->where('application_id', $applicationId)
            ->where('blocker_user_id', $targetUserId)
            ->where('blocked_user_id', $userId)
            ->exists();

        return [
            'blocked_by_me' => $blockedByMe,
            'blocked_me' => $blockedMe,
            'is_blocked' => $blockedByMe || $blockedMe,
        ];
    }
}
