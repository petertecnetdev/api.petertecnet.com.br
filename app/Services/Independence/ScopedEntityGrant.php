<?php

declare(strict_types=1);

namespace App\Services\Independence;

use Illuminate\Support\Facades\DB;

/**
 * Ownership approval grants access only to the explicitly reviewed entity.
 * Original creator/user_id fields remain unchanged for production/event;
 * consequently a production claim cannot confer access to its event roster.
 */
final class ScopedEntityGrant
{
    public function canManage(int $appId, string $type, int $entityId, int $userId): bool
    {
        if ($appId < 1 || $entityId < 1 || $userId < 1 || ! in_array($type, ['production', 'event'], true)) {
            return false;
        }

        return DB::table('independence_entity_grants')
            ->where('app_id', $appId)
            ->where('entity_type', $type)
            ->where('entity_id', $entityId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->exists();
    }

    public function idsForUser(int $appId, string $type, int $userId): array
    {
        if ($appId < 1 || $userId < 1 || ! in_array($type, ['production', 'event'], true)) {
            return [];
        }

        return DB::table('independence_entity_grants')
            ->where('app_id', $appId)
            ->where('entity_type', $type)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->pluck('entity_id')
            ->all();
    }
}
