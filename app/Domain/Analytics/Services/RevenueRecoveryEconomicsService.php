<?php

namespace App\Domain\Analytics\Services;

use App\Models\CommerceOrder;
use App\Models\Interaction;

final class RevenueRecoveryEconomicsService
{
    private const NAVBAR_PROMINENCE_EXPERIMENT = 'pix_recovery_navbar_prominence_v1';

    public function __construct(
        private readonly ObservedRecoveryChannelEconomics $observedEconomics,
        private readonly RecoverySurfaceEconomics $surfaceEconomics,
        private readonly RecoveryProminenceExperimentEconomics $prominenceExperimentEconomics,
    ) {
    }

    /** @param array<string, mixed> $metrics @return array<string, mixed> */
    public function enrich(int $appId, int $organizationId, int $days, array $metrics): array
    {
        $days = min(max($days, 1), 365);
        $since = now()->subDays($days);

        $orders = CommerceOrder::query()
            ->where('app_id', $appId)
            ->where('production_id', $organizationId)
            ->where('created_at', '>=', $since)
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

        $surfaceOrders = CommerceOrder::query()
            ->where('app_id', $appId)
            ->where('production_id', $organizationId)
            ->where('created_at', '>=', $since)
            ->whereNotNull('recovery_started_at')
            ->select([
                'id',
                'public_id',
                'status',
                'platform_fee',
                'processor_fee',
                'metadata',
            ])
            ->get();

        $interactions = Interaction::query()
            ->where('app_id', $appId)
            ->where('created_at', '>=', $since)
            ->whereIn('interaction_type', [
                'frontend_checkout_recovery_notification_cta_viewed',
                'frontend_checkout_recovery_notification_cta_clicked',
                'frontend_checkout_recovery_landed',
                'frontend_checkout_recovery_resumed',
            ])
            ->select(['id', 'interaction_type', 'content', 'created_at'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursor();

        $metrics['checkout_recovery_surface_economics'] = $this->surfaceEconomics->summarize(
            $interactions,
            $surfaceOrders,
        );

        $experimentInteractions = Interaction::query()
            ->where('app_id', $appId)
            ->where('created_at', '>=', $since)
            ->whereIn('interaction_type', [
                'frontend_checkout_recovery_notification_cta_viewed',
                'frontend_checkout_recovery_notification_cta_clicked',
            ])
            ->select(['id', 'interaction_type', 'content', 'created_at'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursor();

        $metrics['checkout_recovery_prominence_experiment'] = $this->prominenceExperimentEconomics->summarize(
            $experimentInteractions,
            $surfaceOrders,
            self::NAVBAR_PROMINENCE_EXPERIMENT,
        );

        return $metrics;
    }
}
