<?php

namespace App\Domain\Analytics\Services;

use App\Models\CommerceOrder;
use App\Models\Event;
use App\Models\Interaction;

final class RevenueRecoveryEconomicsService
{
    private const NAVBAR_PROMINENCE_EXPERIMENT = 'pix_recovery_navbar_prominence_v1';
    private const FRONTEND_ANALYTICS_ENVIRONMENT = 'production';

    public function __construct(
        private readonly ObservedRecoveryChannelEconomics $observedEconomics,
        private readonly RecoverySurfaceEconomics $surfaceEconomics,
        private readonly RecoveryProminenceExperimentEconomics $prominenceExperimentEconomics,
        private readonly CheckoutJourneyFunnel $checkoutJourneyFunnel,
        private readonly CheckoutJourneyPeriodComparison $checkoutJourneyPeriodComparison,
        private readonly CheckoutRecoveryJourneyEconomics $checkoutRecoveryJourneyEconomics,
    ) {
    }

    /** @param array<string, mixed> $metrics @return array<string, mixed> */
    public function enrich(int $appId, int $organizationId, int $days, array $metrics): array
    {
        $days = min(max($days, 1), 365);
        $since = now()->subDays($days);
        $previousSince = now()->subDays($days * 2);

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
                'total',
                'platform_fee',
                'processor_fee',
                'metadata',
            ])
            ->get();

        $interactions = Interaction::query()
            ->where('app_id', $appId)
            ->where('environment', self::FRONTEND_ANALYTICS_ENVIRONMENT)
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
            ->where('environment', self::FRONTEND_ANALYTICS_ENVIRONMENT)
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

        $eventIds = Event::query()
            ->where('app_id', $appId)
            ->where('production_id', $organizationId)
            ->pluck('id');

        $recoveryJourneyInteractions = Interaction::query()
            ->where('app_id', $appId)
            ->where('environment', self::FRONTEND_ANALYTICS_ENVIRONMENT)
            ->where('created_at', '>=', $since)
            ->whereIn('interaction_type', [
                'frontend_checkout_recovered',
                'frontend_checkout_opened',
                'frontend_payment_attempted',
                'frontend_payment_approved',
                'frontend_checkout_fulfilled',
            ])
            ->select(['id', 'interaction_type', 'content', 'created_at'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursor();

        $metrics['checkout_recovery_journey_economics'] = $this->checkoutRecoveryJourneyEconomics->summarize(
            $recoveryJourneyInteractions,
            $eventIds,
        );

        $journeyInteractions = Interaction::query()
            ->where('app_id', $appId)
            ->where('environment', self::FRONTEND_ANALYTICS_ENVIRONMENT)
            ->where('created_at', '>=', $since)
            ->whereIn('interaction_type', [
                'frontend_checkout_opened',
                'frontend_checkout_mobile_payment_cta_clicked',
                'frontend_payment_attempted',
                'frontend_payment_approved',
                'frontend_checkout_fulfilled',
                'frontend_checkout_abandoned',
                'frontend_checkout_abandoned_before_payment_attempt',
                'frontend_payment_failed',
            ])
            ->select(['id', 'interaction_type', 'content', 'created_at'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursor()
            ->map(fn (Interaction $interaction): Interaction => $this->normalizeCheckoutJourneyInteraction($interaction));

        $observedPlatformContributionMargin = (float) ($metrics['gross_revenue'] ?? 0) > 0
            && is_numeric($metrics['platform_contribution_margin'] ?? null)
                ? (float) $metrics['platform_contribution_margin']
                : null;
        $platformContributionMarginByPaymentMethod = collect($metrics['payment_methods'] ?? [])
            ->filter(fn (array $row): bool => (float) ($row['gross_revenue'] ?? 0) > 0
                && is_numeric($row['platform_contribution_margin'] ?? null))
            ->mapWithKeys(fn (array $row): array => [
                (string) ($row['payment_method'] ?? 'unknown') => (float) $row['platform_contribution_margin'],
            ])
            ->all();

        $currentJourneyFunnel = $this->checkoutJourneyFunnel->summarize(
            $journeyInteractions,
            $eventIds,
            $observedPlatformContributionMargin,
            $platformContributionMarginByPaymentMethod,
        );

        $previousJourneyInteractions = Interaction::query()
            ->where('app_id', $appId)
            ->where('environment', self::FRONTEND_ANALYTICS_ENVIRONMENT)
            ->where('created_at', '>=', $previousSince)
            ->where('created_at', '<', $since)
            ->whereIn('interaction_type', [
                'frontend_checkout_opened',
                'frontend_checkout_mobile_payment_cta_clicked',
                'frontend_payment_attempted',
                'frontend_payment_approved',
                'frontend_checkout_fulfilled',
                'frontend_checkout_abandoned',
                'frontend_checkout_abandoned_before_payment_attempt',
                'frontend_payment_failed',
            ])
            ->select(['id', 'interaction_type', 'content', 'created_at'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursor()
            ->map(fn (Interaction $interaction): Interaction => $this->normalizeCheckoutJourneyInteraction($interaction));

        $previousJourneyFunnel = $this->checkoutJourneyFunnel->summarize(
            $previousJourneyInteractions,
            $eventIds,
            $observedPlatformContributionMargin,
            $platformContributionMarginByPaymentMethod,
        );
        $currentJourneyFunnel['period_comparison'] = $this->checkoutJourneyPeriodComparison->compare(
            $currentJourneyFunnel,
            $previousJourneyFunnel,
            $days,
        );
        $metrics['checkout_journey_funnel'] = $currentJourneyFunnel;

        return $metrics;
    }

    private function normalizeCheckoutJourneyInteraction(Interaction $interaction): Interaction
    {
        if ($interaction->interaction_type === 'frontend_checkout_abandoned_before_payment_attempt') {
            $interaction->interaction_type = 'frontend_checkout_abandoned';
        }

        return $interaction;
    }
}
