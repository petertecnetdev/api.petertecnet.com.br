<?php

namespace App\Services;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class OutboundDeliveryService
{
    private const STALE_LOCK_MINUTES = 5;

    public function deliverOnce(int $appId, string $channel, string $dedupeKey, Closure $delivery, array $metadata = []): bool
    {
        $channel = trim($channel);
        $dedupeKey = trim($dedupeKey);
        if ($appId <= 0 || $channel === '' || $dedupeKey === '') {
            throw new \InvalidArgumentException('Outbound delivery identity is incomplete.');
        }

        $identity = ['app_id' => $appId, 'channel' => $channel, 'dedupe_key' => $dedupeKey];
        $deliveryId = $this->claim($identity, $metadata);
        if ($deliveryId === null) {
            return false;
        }

        try {
            $delivery();
            DB::table('outbound_deliveries')->where('id', $deliveryId)->update([
                'status' => 'delivered',
                'locked_at' => null,
                'delivered_at' => now(),
                'failed_at' => null,
                'last_error' => null,
                'updated_at' => now(),
            ]);
            return true;
        } catch (Throwable $e) {
            DB::table('outbound_deliveries')->where('id', $deliveryId)->update([
                'status' => 'failed',
                'locked_at' => null,
                'failed_at' => now(),
                'last_error' => mb_substr($e->getMessage(), 0, 4000),
                'updated_at' => now(),
            ]);
            throw $e;
        }
    }

    public function isDelivered(int $appId, string $channel, string $dedupeKey): bool
    {
        return DB::table('outbound_deliveries')
            ->where('app_id', $appId)
            ->where('channel', trim($channel))
            ->where('dedupe_key', trim($dedupeKey))
            ->where('status', 'delivered')
            ->exists();
    }

    private function claim(array $identity, array $metadata): ?int
    {
        try {
            DB::table('outbound_deliveries')->insertOrIgnore(array_merge($identity, [
                'status' => 'pending',
                'attempts' => 0,
                'metadata' => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        } catch (QueryException $e) {
            if (! DB::table('outbound_deliveries')->where($identity)->exists()) {
                throw $e;
            }
        }

        return DB::transaction(function () use ($identity, $metadata) {
            $row = DB::table('outbound_deliveries')->where($identity)->lockForUpdate()->first();
            if (! $row) {
                throw new RuntimeException('Outbound delivery ledger row could not be claimed.');
            }
            if ($row->status === 'delivered') {
                return null;
            }
            if ($row->status === 'processing' && $row->locked_at && Carbon::parse($row->locked_at)->greaterThan(now()->subMinutes(self::STALE_LOCK_MINUTES))) {
                return null;
            }

            DB::table('outbound_deliveries')->where('id', $row->id)->update([
                'status' => 'processing',
                'attempts' => ((int) $row->attempts) + 1,
                'locked_at' => now(),
                'failed_at' => null,
                'last_error' => null,
                'metadata' => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $row->metadata,
                'updated_at' => now(),
            ]);

            return (int) $row->id;
        });
    }
}
