<?php

namespace App\Domain\Analytics\Services;

final class RecoveryProminenceExperimentEconomics
{
    private const VIEWED = 'frontend_checkout_recovery_notification_cta_viewed';
    private const CLICKED = 'frontend_checkout_recovery_notification_cta_clicked';
    private const MIN_EXPOSED_ORDERS_PER_VARIANT = 30;

    /**
     * Summarize a deterministic recovery prominence experiment by assigned variant.
     *
     * Paid value is attributed only to orders that clicked a CTA carrying the same
     * experiment metadata, keeping unrelated recovery traffic out of the comparison.
     *
     * @param iterable<mixed> $interactions
     * @param iterable<mixed> $orders
     * @return array<string, mixed>
     */
    public function summarize(iterable $interactions, iterable $orders, string $experiment): array
    {
        $experiment = trim($experiment);
        if ($experiment === '') {
            return [];
        }

        $ordersByPublicId = [];
        foreach ($orders as $order) {
            $publicId = trim((string) data_get($order, 'public_id'));
            if ($publicId !== '') {
                $ordersByPublicId[$publicId] = $order;
            }
        }

        if ($ordersByPublicId === []) {
            return [];
        }

        $variants = [];
        $clickedVariantByOrder = [];

        foreach ($interactions as $interaction) {
            $type = trim((string) data_get($interaction, 'interaction_type'));
            if (! in_array($type, [self::VIEWED, self::CLICKED], true)) {
                continue;
            }

            $metadata = data_get($interaction, 'content.metadata', []);
            if (trim((string) data_get($metadata, 'recovery_prominence_experiment')) !== $experiment) {
                continue;
            }

            $publicId = trim((string) data_get($metadata, 'order_public_id'));
            if ($publicId === '' || ! isset($ordersByPublicId[$publicId])) {
                continue;
            }

            $variant = $this->variant(data_get($metadata, 'recovery_prominence_variant'));
            if ($variant === null) {
                continue;
            }

            $this->initializeVariant($variants, $variant);

            if ($type === self::VIEWED) {
                $variants[$variant]['impressions']++;
                $variants[$variant]['exposed_orders'][$publicId] = true;
                continue;
            }

            $variants[$variant]['clicks']++;
            $variants[$variant]['clicked_orders'][$publicId] = true;
            $clickedVariantByOrder[$publicId] = $variant;
        }

        foreach ($clickedVariantByOrder as $publicId => $variant) {
            $order = $ordersByPublicId[$publicId] ?? null;
            if (! $order || (string) data_get($order, 'status') !== 'paid') {
                continue;
            }

            $this->initializeVariant($variants, $variant);
            if (! isset($variants[$variant]['paid_orders'][$publicId])) {
                $variants[$variant]['paid_orders'][$publicId] = true;
                $variants[$variant]['platform_contribution'] += $this->platformContribution($order);
            }
        }

        $rows = collect($variants)->map(function (array $stats, string $variant): array {
            $impressions = (int) $stats['impressions'];
            $exposedOrders = count($stats['exposed_orders']);
            $clicks = (int) $stats['clicks'];
            $paidOrders = count($stats['paid_orders']);
            $contribution = (float) $stats['platform_contribution'];

            return [
                'variant' => $variant,
                'cta_impressions' => $impressions,
                'exposed_orders' => $exposedOrders,
                'cta_clicks' => $clicks,
                'clicked_orders' => count($stats['clicked_orders']),
                'paid_orders' => $paidOrders,
                'cta_ctr_percent' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
                'paid_orders_per_100_impressions' => $impressions > 0 ? round(($paidOrders / $impressions) * 100, 2) : null,
                'platform_contribution' => round($contribution, 2),
                'platform_contribution_per_impression' => $impressions > 0 ? round($contribution / $impressions, 4) : null,
                'sample_is_mature' => $exposedOrders >= self::MIN_EXPOSED_ORDERS_PER_VARIANT,
                'min_exposed_orders_required' => self::MIN_EXPOSED_ORDERS_PER_VARIANT,
                'remaining_exposed_orders_to_maturity' => max(0, self::MIN_EXPOSED_ORDERS_PER_VARIANT - $exposedOrders),
            ];
        })->keyBy('variant');

        $control = $rows->get('control');
        $treatment = $rows->get('prominent');
        $comparisonIsMature = (bool) ($control['sample_is_mature'] ?? false)
            && (bool) ($treatment['sample_is_mature'] ?? false);

        $comparison = [
            'control_variant' => 'control',
            'treatment_variant' => 'prominent',
            'sample_is_mature' => $comparisonIsMature,
            'incremental_paid_orders_per_100_impressions' => null,
            'incremental_platform_contribution_per_impression' => null,
            'relative_contribution_lift_percent' => null,
        ];

        if ($comparisonIsMature) {
            $controlPaidRate = (float) ($control['paid_orders_per_100_impressions'] ?? 0);
            $treatmentPaidRate = (float) ($treatment['paid_orders_per_100_impressions'] ?? 0);
            $controlContribution = (float) ($control['platform_contribution_per_impression'] ?? 0);
            $treatmentContribution = (float) ($treatment['platform_contribution_per_impression'] ?? 0);

            $comparison['incremental_paid_orders_per_100_impressions'] = round($treatmentPaidRate - $controlPaidRate, 2);
            $comparison['incremental_platform_contribution_per_impression'] = round($treatmentContribution - $controlContribution, 4);
            $comparison['relative_contribution_lift_percent'] = $controlContribution !== 0.0
                ? round((($treatmentContribution - $controlContribution) / abs($controlContribution)) * 100, 2)
                : null;
        }

        return [
            'experiment' => $experiment,
            'variants' => $rows->values()->all(),
            'comparison' => $comparison,
        ];
    }

    /** @param array<string, array<string, mixed>> $variants */
    private function initializeVariant(array &$variants, string $variant): void
    {
        $variants[$variant] ??= [
            'impressions' => 0,
            'exposed_orders' => [],
            'clicks' => 0,
            'clicked_orders' => [],
            'paid_orders' => [],
            'platform_contribution' => 0.0,
        ];
    }

    private function variant(mixed $value): ?string
    {
        $variant = strtolower(trim((string) $value));

        return in_array($variant, ['control', 'prominent'], true) ? $variant : null;
    }

    private function platformContribution(mixed $order): float
    {
        $platformFee = (float) data_get($order, 'platform_fee', 0);
        $processorFee = (float) data_get($order, 'processor_fee', 0);
        $settlementMode = (string) data_get($order, 'metadata.settlement_mode', 'unknown');

        return $settlementMode === 'platform_collection'
            ? $platformFee - $processorFee
            : $platformFee;
    }
}
