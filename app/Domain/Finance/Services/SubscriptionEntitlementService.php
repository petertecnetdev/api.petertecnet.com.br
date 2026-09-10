<?php

namespace App\Domain\Finance\Services;

use Illuminate\Support\Facades\DB;

final class SubscriptionEntitlementService
{
    public function syncForPlan(
        int $appId,
        int $userId,
        int $subscriptionId,
        string $application,
        string $planCode,
        mixed $startsAt,
        mixed $expiresAt,
    ): void {
        $plan = collect((array) config("subscriptions.applications.{$application}.plans", []))
            ->first(fn (array $candidate) => (string) ($candidate['code'] ?? '') === $planCode);

        $configured = (array) ($plan['entitlements'] ?? ['application_access' => true]);
        if (! array_key_exists('application_access', $configured)) {
            $configured = ['application_access' => true] + $configured;
        }

        $now = now();
        $activeKeys = [];

        foreach ($configured as $key => $value) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }

            $activeKeys[] = $key;
            DB::table('ecosystem_entitlements')->updateOrInsert(
                ['app_id' => $appId, 'user_id' => $userId, 'key' => $key],
                [
                    'subscription_id' => $subscriptionId,
                    'status' => 'active',
                    'starts_at' => $startsAt,
                    'expires_at' => $expiresAt,
                    'metadata' => json_encode([
                        'plan_code' => $planCode,
                        'value' => $value,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        $stale = DB::table('ecosystem_entitlements')
            ->where('app_id', $appId)
            ->where('user_id', $userId)
            ->where('subscription_id', $subscriptionId);

        if ($activeKeys !== []) {
            $stale->whereNotIn('key', $activeKeys);
        }

        $stale->update([
            'status' => 'inactive',
            'expires_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function activeForSubscription(int $subscriptionId): array
    {
        return DB::table('ecosystem_entitlements')
            ->where('subscription_id', $subscriptionId)
            ->where('status', 'active')
            ->orderBy('key')
            ->get()
            ->map(function (object $entitlement): array {
                $metadata = json_decode((string) ($entitlement->metadata ?? '{}'), true) ?: [];

                return [
                    'key' => (string) $entitlement->key,
                    'status' => (string) $entitlement->status,
                    'value' => $metadata['value'] ?? true,
                    'expires_at' => $entitlement->expires_at,
                ];
            })
            ->all();
    }
}
