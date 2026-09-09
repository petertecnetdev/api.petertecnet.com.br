<?php

namespace App\Domain\Analytics\Services;

final class RecoverySurfaceEconomics
{
    private const VIEWED = 'frontend_checkout_recovery_notification_cta_viewed';
    private const CLICKED = 'frontend_checkout_recovery_notification_cta_clicked';
    private const LANDED = 'frontend_checkout_recovery_landed';
    private const RESUMED = 'frontend_checkout_recovery_resumed';
    private const MIN_IMPRESSION_ORDERS_FOR_DECISION = 30;

    /**
     * Summarize the observed recovery funnel by UI surface.
     *
     * Interactions must be provided in chronological order so landing/resume events can be
     * conservatively attributed to the latest CTA click for the same order.
     *
     * @param iterable<mixed> $interactions
     * @param iterable<mixed> $orders
     * @return array<int, array<string, mixed>>
     */
    public function summarize(iterable $interactions, iterable $orders): array
    {
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

        $surfaces = [];
        $latestClickedSurfaceByOrder = [];

        foreach ($interactions as $interaction) {
            $type = trim((string) data_get($interaction, 'interaction_type'));
            if (! in_array($type, [self::VIEWED, self::CLICKED, self::LANDED, self::RESUMED], true)) {
                continue;
            }

            $publicId = trim((string) data_get($interaction, 'content.metadata.order_public_id'));
            if ($publicId === '' || ! isset($ordersByPublicId[$publicId])) {
                continue;
            }

            if ($type === self::VIEWED || $type === self::CLICKED) {
                $surface = $this->surface(data_get($interaction, 'content.metadata.recovery_entrypoint'));
                $this->initializeSurface($surfaces, $surface);

                if ($type === self::VIEWED) {
                    $surfaces[$surface]['cta_impressions']++;
                    $surfaces[$surface]['impression_orders'][$publicId] = true;
                    continue;
                }

                $surfaces[$surface]['cta_clicks']++;
                $surfaces[$surface]['clicked_orders'][$publicId] = true;
                $latestClickedSurfaceByOrder[$publicId] = $surface;
                continue;
            }

            $surface = $latestClickedSurfaceByOrder[$publicId] ?? null;
            if ($surface === null) {
                continue;
            }

            $this->initializeSurface($surfaces, $surface);
            if ($type === self::LANDED) {
                $surfaces[$surface]['landed_orders'][$publicId] = true;
            } elseif ($type === self::RESUMED) {
                $surfaces[$surface]['resumed_orders'][$publicId] = true;
            }
        }

        foreach ($latestClickedSurfaceByOrder as $publicId => $surface) {
            $order = $ordersByPublicId[$publicId] ?? null;
            if (! $order || (string) data_get($order, 'status') !== 'paid') {
                continue;
            }

            $this->initializeSurface($surfaces, $surface);
            if (! isset($surfaces[$surface]['paid_orders'][$publicId])) {
                $surfaces[$surface]['paid_orders'][$publicId] = true;
                $surfaces[$surface]['observed_platform_contribution'] += $this->platformContribution($order);
            }
        }

        return collect($surfaces)
            ->map(function (array $stats, string $surface): array {
                $impressions = (int) $stats['cta_impressions'];
                $impressionOrders = count($stats['impression_orders']);
                $clicks = (int) $stats['cta_clicks'];
                $clickedOrders = count($stats['clicked_orders']);
                $paidOrders = count($stats['paid_orders']);
                $contribution = (float) $stats['observed_platform_contribution'];
                $observedContributionPerImpression = $impressions > 0 ? round($contribution / $impressions, 4) : null;
                $sampleIsMature = $impressionOrders >= self::MIN_IMPRESSION_ORDERS_FOR_DECISION;

                return [
                    'surface' => $surface,
                    'cta_impressions' => $impressions,
                    'cta_impression_orders' => $impressionOrders,
                    'cta_clicks' => $clicks,
                    'cta_clicked_orders' => $clickedOrders,
                    'cta_ctr_percent' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
                    'landed_orders' => count($stats['landed_orders']),
                    'resumed_orders' => count($stats['resumed_orders']),
                    'paid_orders' => $paidOrders,
                    'click_to_paid_rate_percent' => $clickedOrders > 0 ? round(($paidOrders / $clickedOrders) * 100, 2) : null,
                    'observed_paid_orders_per_100_impressions' => $impressions > 0 ? round(($paidOrders / $impressions) * 100, 2) : null,
                    'observed_platform_contribution' => round($contribution, 2),
                    'observed_platform_contribution_per_impression' => $observedContributionPerImpression,
                    'sample_is_mature' => $sampleIsMature,
                    'min_impression_orders_required' => self::MIN_IMPRESSION_ORDERS_FOR_DECISION,
                    'remaining_impression_orders_to_maturity' => max(0, self::MIN_IMPRESSION_ORDERS_FOR_DECISION - $impressionOrders),
                    'decision_platform_contribution_per_impression' => $sampleIsMature ? $observedContributionPerImpression : null,
                ];
            })
            ->sort(function (array $left, array $right): int {
                $maturity = ((int) $right['sample_is_mature']) <=> ((int) $left['sample_is_mature']);
                if ($maturity !== 0) {
                    return $maturity;
                }

                if ($left['sample_is_mature']) {
                    return ((float) ($right['decision_platform_contribution_per_impression'] ?? -1))
                        <=> ((float) ($left['decision_platform_contribution_per_impression'] ?? -1));
                }

                return ((int) $right['cta_impression_orders']) <=> ((int) $left['cta_impression_orders']);
            })
            ->values()
            ->all();
    }

    /** @param array<string, array<string, mixed>> $surfaces */
    private function initializeSurface(array &$surfaces, string $surface): void
    {
        $surfaces[$surface] ??= [
            'cta_impressions' => 0,
            'impression_orders' => [],
            'cta_clicks' => 0,
            'clicked_orders' => [],
            'landed_orders' => [],
            'resumed_orders' => [],
            'paid_orders' => [],
            'observed_platform_contribution' => 0.0,
        ];
    }

    private function surface(mixed $value): string
    {
        $surface = strtolower(trim((string) $value));

        return preg_match('/^[a-z][a-z0-9_\-]{0,79}$/', $surface) ? $surface : 'unknown';
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
