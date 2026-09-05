<?php

namespace App\Domain\Commerce\Services;

use App\Models\CommerceOrder;

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

    private function recoverableQuery(int $appId, int $userId)
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
