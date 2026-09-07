<?php

namespace App\Domain\Commerce\Services;

use App\Models\CommerceOrder;
use Illuminate\Database\Eloquent\Builder;

final class PendingCheckoutRecoveryService
{
    public function latest(int $appId, int $userId): ?CommerceOrder
    {
        return $this->recoverableQuery($appId, $userId)
            ->latest('id')
            ->first();
    }

    public function recover(int $appId, int $userId, int $orderId): ?CommerceOrder
    {
        $order = $this->recoverableQuery($appId, $userId)
            ->whereKey($orderId)
            ->first();

        if (! $order) {
            return null;
        }

        if ($order->recovery_started_at === null) {
            $order->forceFill(['recovery_started_at' => now()])->saveQuietly();
            $order->refresh();
        }

        return $order;
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
}
