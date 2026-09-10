<?php

namespace App\Domain\Finance\Services;

use Illuminate\Support\Facades\DB;

final class EntitlementAccessService
{
    /**
     * @return array{allowed: bool, key: string, plan_code: string|null, legacy_compatible: bool}
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

        if ($entitlement) {
            $metadata = $this->metadata($entitlement);

            return [
                'allowed' => $this->allows($metadata['value'] ?? true),
                'key' => $key,
                'plan_code' => isset($metadata['plan_code']) ? (string) $metadata['plan_code'] : null,
                'legacy_compatible' => false,
            ];
        }

        $subscriptionEntitlement = DB::table('ecosystem_entitlements')
            ->where('app_id', $appId)
            ->where('user_id', $userId)
            ->whereNotNull('subscription_id')
            ->orderByDesc('updated_at')
            ->first();

        if (! $subscriptionEntitlement) {
            // Accounts created before plan entitlements existed keep their current
            // behavior until they enter the subscription lifecycle.
            return [
                'allowed' => true,
                'key' => $key,
                'plan_code' => null,
                'legacy_compatible' => true,
            ];
        }

        $metadata = $this->metadata($subscriptionEntitlement);

        return [
            'allowed' => false,
            'key' => $key,
            'plan_code' => isset($metadata['plan_code']) ? (string) $metadata['plan_code'] : null,
            'legacy_compatible' => false,
        ];
    }

    private function metadata(object $entitlement): array
    {
        return json_decode((string) ($entitlement->metadata ?? '{}'), true) ?: [];
    }

    private function allows(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value > 0;
        }

        if (is_string($value)) {
            return ! in_array(strtolower(trim($value)), ['', '0', 'false', 'off', 'no'], true);
        }

        return $value !== null;
    }
}
