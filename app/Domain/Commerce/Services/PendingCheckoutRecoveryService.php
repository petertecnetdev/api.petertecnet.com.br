<?php

namespace App\Domain\Commerce\Services;

use App\Models\CommerceOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class PendingCheckoutRecoveryService
{
    public function latest(int $appId, int $userId): ?CommerceOrder
    {
        return $this->recoverableQuery($appId, $userId)
            ->latest('id')
            ->first();
    }

    public function recover(
        int $appId,
        int $userId,
        int $orderId,
        string $channel = 'in_app',
        ?float $attemptCost = 0.0,
        array $recoveryContext = [],
    ): ?CommerceOrder {
        return DB::transaction(function () use ($appId, $userId, $orderId, $channel, $attemptCost, $recoveryContext) {
            $order = $this->recoverableQuery($appId, $userId)
                ->whereKey($orderId)
                ->whereNull('recovery_started_at')
                ->lockForUpdate()
                ->first();

            if (! $order) {
                return null;
            }

            $normalizedChannel = $this->normalizeChannel($channel);
            $normalizedCost = $attemptCost !== null ? max($attemptCost, 0.0) : null;
            $metadata = is_array($order->metadata) ? $order->metadata : [];
            $context = Arr::only($recoveryContext, [
                'experiment_name',
                'timing_minutes',
                'timing_variant',
            ]);

            if (isset($context['timing_minutes'])) {
                $context['timing_minutes'] = max(1, (int) $context['timing_minutes']);
            }

            foreach (['experiment_name', 'timing_variant'] as $key) {
                if (isset($context[$key])) {
                    $context[$key] = mb_substr(trim((string) $context[$key]), 0, 80);
                }
            }

            $startedAt = now();
            $metadata['recovery'] = array_merge(
                is_array($metadata['recovery'] ?? null) ? $metadata['recovery'] : [],
                $context,
                [
                    'channel' => $normalizedChannel,
                    'attempt_cost' => $normalizedCost,
                    'started_at' => $startedAt->toIso8601String(),
                ],
            );

            $order->forceFill([
                'recovery_started_at' => $startedAt,
                'metadata' => $metadata,
            ])->saveQuietly();
            $order->refresh();

            return $order;
        }, 3);
    }

    public function recoveryState(?CommerceOrder $order): array
    {
        if (! $order) {
            return [
                'payment_recovery_eligible' => false,
                'payment_expires_at' => null,
                'payment_recovery_seconds_remaining' => 0,
            ];
        }

        $expiresAt = $order->expires_at;

        return [
            'payment_recovery_eligible' => true,
            'payment_expires_at' => $expiresAt?->toIso8601String(),
            'payment_recovery_seconds_remaining' => $expiresAt
                ? max(0, now()->diffInSeconds($expiresAt, false))
                : 0,
        ];
    }

    private function recoverableQuery(int $appId, int $userId): Builder
    {
        return CommerceOrder::query()
            ->where('app_id', $appId)
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->where('payment_method', 'pix')
            ->where('expires_at', '>', now())
            ->whereHas('payments', function ($query) use ($appId) {
                $query->where('app_id', $appId)
                    ->where('provider', 'mercadopago')
                    ->whereIn('status', ['pending', 'in_process']);
            })
            ->with([
                'items',
                'event:id,title,slug,start_date,end_date',
                'payments' => function ($query) use ($appId) {
                    $query->where('app_id', $appId)
                        ->where('provider', 'mercadopago')
                        ->whereIn('status', ['pending', 'in_process'])
                        ->latest('id');
                },
            ]);
    }

    private function normalizeChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));
        if ($channel === '' || ! preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $channel)) {
            return 'unknown';
        }

        return $channel;
    }
}
