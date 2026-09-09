<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

class DeliveryEffectService
{
    private const CLAIM_TTL_MINUTES = 10;

    public function run(
        int $appId,
        string $aggregateType,
        string|int $aggregateId,
        string $effectKey,
        Closure $effect,
        array $context = []
    ): bool {
        $aggregateType = trim($aggregateType);
        $aggregateId = trim((string) $aggregateId);
        $effectKey = trim($effectKey);
        $dedupeKey = $this->dedupeKey($appId, $aggregateType, $aggregateId, $effectKey);
        $now = now();

        DB::table('delivery_effects')->insertOrIgnore([
            'app_id' => $appId,
            'dedupe_key' => $dedupeKey,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'effect_key' => $effectKey,
            'status' => 'pending',
            'attempts' => 0,
            'context' => $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $claimed = DB::transaction(function () use ($dedupeKey, $context): bool {
            $row = DB::table('delivery_effects')->where('dedupe_key', $dedupeKey)->lockForUpdate()->first();
            if (! $row || $row->status === 'completed') {
                return false;
            }

            if ($row->status === 'processing' && $row->claimed_at) {
                $claimedAt = \Illuminate\Support\Carbon::parse($row->claimed_at);
                if ($claimedAt->gt(now()->subMinutes(self::CLAIM_TTL_MINUTES))) {
                    return false;
                }
            }

            DB::table('delivery_effects')->where('id', $row->id)->update([
                'status' => 'processing',
                'attempts' => ((int) $row->attempts) + 1,
                'claimed_at' => now(),
                'failed_at' => null,
                'last_error' => null,
                'context' => $context === [] ? $row->context : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);

            return true;
        });

        if (! $claimed) {
            return false;
        }

        try {
            $effect();
            DB::table('delivery_effects')->where('dedupe_key', $dedupeKey)->update([
                'status' => 'completed',
                'completed_at' => now(),
                'failed_at' => null,
                'last_error' => null,
                'updated_at' => now(),
            ]);
            return true;
        } catch (Throwable $e) {
            DB::table('delivery_effects')->where('dedupe_key', $dedupeKey)->update([
                'status' => 'failed',
                'failed_at' => now(),
                'last_error' => mb_substr($e->getMessage(), 0, 4000),
                'updated_at' => now(),
            ]);
            throw $e;
        }
    }

    public function hasPendingForAggregate(int $appId, string $aggregateType, string|int $aggregateId): bool
    {
        return DB::table('delivery_effects')
            ->where('app_id', $appId)
            ->where('aggregate_type', trim($aggregateType))
            ->where('aggregate_id', trim((string) $aggregateId))
            ->where('status', '!=', 'completed')
            ->exists();
    }

    private function dedupeKey(int $appId, string $aggregateType, string $aggregateId, string $effectKey): string
    {
        return hash('sha256', implode("\0", [$appId, $aggregateType, $aggregateId, $effectKey]));
    }
}
