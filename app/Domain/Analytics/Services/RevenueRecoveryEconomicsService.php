<?php

namespace App\Domain\Analytics\Services;

use App\Models\CommerceOrder;

final class RevenueRecoveryEconomicsService
{
    public function __construct(
        private readonly ObservedRecoveryChannelEconomics $observedEconomics,
    ) {
    }

    /** @param array<string, mixed> $metrics @return array<string, mixed> */
    public function enrich(int $appId, int $organizationId, int $days, array $metrics): array
    {
        $days = min(max($days, 1), 365);
        $orders = CommerceOrder::query()
            ->where('app_id', $appId)
            ->where('production_id', $organizationId)
            ->where('created_at', '>=', now()->subDays($days))
            ->whereNotNull('recovery_started_at')
            ->select([
                'id',
                'payment_method',
                'status',
                'platform_fee',
                'processor_fee',
                'created_at',
                'recovery_started_at',
                'metadata',
            ])
            ->lazyById(1000);

        $metrics['checkout_recovery_channel_economics'] = $this->observedEconomics->summarize($orders);

        return $metrics;
    }
}
