<?php

namespace App\Domain\Commerce\Services;

use App\Models\CommerceOrder;

final class PendingCheckoutRecoveryService
{
    public function latest(int $appId, int $userId): ?CommerceOrder
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
            ])
            ->latest('id')
            ->first();
    }
}
