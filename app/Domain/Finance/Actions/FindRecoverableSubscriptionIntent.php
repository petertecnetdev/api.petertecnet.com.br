<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\SubscriptionIntent;

class FindRecoverableSubscriptionIntent
{
    public function handle(int|string $userId, string $application, int $maxAgeDays = 7): ?SubscriptionIntent
    {
        return SubscriptionIntent::query()
            ->where('application', $application)
            ->where('user_id', $userId)
            ->whereIn('status', ['created', 'payment_pending'])
            ->where('created_at', '>=', now()->subDays($maxAgeDays))
            ->latest('id')
            ->first();
    }
}
