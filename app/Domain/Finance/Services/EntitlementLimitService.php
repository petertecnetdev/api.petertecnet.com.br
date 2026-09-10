<?php

namespace App\Domain\Finance\Services;

use Illuminate\Support\Facades\DB;

final class EntitlementLimitService
{
    /**
     * @return array{allowed: bool, key: string, limit: int|null, current: int, plan_code: string|null}
     */
    public function check(int $appId, int $userId, string $key): array
    {
        $entitlement = DB::table('ecosystem_entitlements')
            ->where('app_id', $appId)
            ->where('user_id', $userId)
            ->where('key', $key)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if (! $entitlement) {
            return [
                'allowed' => true,
                'key' => $key,
                'limit' => null,
                'current' => 0,
                'plan_code' => null,
            ];
        }

        $metadata = json_decode((string) ($entitlement->metadata ?? '{}'), true) ?: [];
        $limit = isset($metadata['value']) && is_numeric($metadata['value'])
            ? (int) $metadata['value']
            : null;

        if ($limit === null || $limit < 0) {
            return [
                'allowed' => true,
                'key' => $key,
                'limit' => $limit,
                'current' => 0,
                'plan_code' => isset($metadata['plan_code']) ? (string) $metadata['plan_code'] : null,
            ];
        }

        $current = $this->currentUsage($appId, $userId, $key);

        return [
            'allowed' => $current < $limit,
            'key' => $key,
            'limit' => $limit,
            'current' => $current,
            'plan_code' => isset($metadata['plan_code']) ? (string) $metadata['plan_code'] : null,
        ];
    }

    private function currentUsage(int $appId, int $userId, string $key): int
    {
        return match ($key) {
            'establishments.max' => DB::table('establishments')
                ->where('app_id', $appId)
                ->where('user_id', $userId)
                ->where('is_cancelled', false)
                ->count(),
            default => 0,
        };
    }
}
