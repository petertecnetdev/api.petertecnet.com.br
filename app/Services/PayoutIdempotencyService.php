<?php

namespace App\Services;

use App\Models\Production;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PayoutIdempotencyService
{
    /**
     * Atomically claims a caller-stable payout intent.
     *
     * @return array{state:string,payout_id:?int}
     */
    public function claim(Production $production, User $user, string $key, float $amount): array
    {
        $keyHash = hash('sha256', $key);
        $payloadHash = hash('sha256', json_encode([
            'amount' => number_format($amount, 2, '.', ''),
            'requested_by_user_id' => (int) $user->id,
        ], JSON_UNESCAPED_SLASHES));

        return DB::transaction(function () use ($production, $user, $keyHash, $payloadHash) {
            $inserted = DB::table('financial_payout_idempotency_keys')->insertOrIgnore([
                'app_slug' => (string) $production->app_slug,
                'source_type' => 'production',
                'source_id' => (int) $production->id,
                'requested_by_user_id' => (int) $user->id,
                'key_hash' => $keyHash,
                'payload_hash' => $payloadHash,
                'status' => 'processing',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $claim = DB::table('financial_payout_idempotency_keys')
                ->where('app_slug', (string) $production->app_slug)
                ->where('source_type', 'production')
                ->where('source_id', (int) $production->id)
                ->where('key_hash', $keyHash)
                ->lockForUpdate()
                ->first();

            if ($inserted === 1) {
                return ['state' => 'acquired', 'payout_id' => null];
            }

            if (! $claim || ! hash_equals((string) $claim->payload_hash, $payloadHash)) {
                return ['state' => 'conflict', 'payout_id' => null];
            }

            if ($claim->status === 'completed' && $claim->payout_id) {
                return ['state' => 'replay', 'payout_id' => (int) $claim->payout_id];
            }

            return ['state' => 'processing', 'payout_id' => $claim->payout_id ? (int) $claim->payout_id : null];
        });
    }

    public function complete(Production $production, string $key, int $payoutId): void
    {
        DB::table('financial_payout_idempotency_keys')
            ->where('app_slug', (string) $production->app_slug)
            ->where('source_type', 'production')
            ->where('source_id', (int) $production->id)
            ->where('key_hash', hash('sha256', $key))
            ->where('status', 'processing')
            ->update([
                'status' => 'completed',
                'payout_id' => $payoutId,
                'updated_at' => now(),
            ]);
    }

    public function release(Production $production, string $key): void
    {
        DB::table('financial_payout_idempotency_keys')
            ->where('app_slug', (string) $production->app_slug)
            ->where('source_type', 'production')
            ->where('source_id', (int) $production->id)
            ->where('key_hash', hash('sha256', $key))
            ->where('status', 'processing')
            ->whereNull('payout_id')
            ->delete();
    }

    public function replay(Production $production, int $payoutId): ?array
    {
        $payout = DB::table('financial_payouts')
            ->where('source_type', 'production')
            ->where('source_id', (int) $production->id)
            ->where('id', $payoutId)
            ->first();

        if (! $payout) {
            return null;
        }

        return [
            'message' => 'Esta solicitação de repasse já foi recebida anteriormente.',
            'payout' => $payout,
            'balance' => null,
            'idempotent_replay' => true,
        ];
    }
}
