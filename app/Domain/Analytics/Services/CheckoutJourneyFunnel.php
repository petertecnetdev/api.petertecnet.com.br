<?php

namespace App\Domain\Analytics\Services;

use ArrayAccess;

final class CheckoutJourneyFunnel
{
    private const STAGES = [
        'opened' => 'frontend_checkout_opened',
        'mobile_payment_cta' => 'frontend_checkout_mobile_payment_cta_clicked',
        'payment_attempted' => 'frontend_payment_attempted',
        'payment_approved' => 'frontend_payment_approved',
        'fulfilled' => 'frontend_checkout_fulfilled',
    ];

    private const TERMINAL_FAILURES = [
        'frontend_checkout_abandoned',
        'frontend_payment_failed',
    ];

    /**
     * @param iterable<mixed> $interactions Interactions in chronological order.
     * @param iterable<int|string> $eventIds Events that belong to the producer/organization in scope.
     * @return array<string, mixed>
     */
    public function summarize(
        iterable $interactions,
        iterable $eventIds,
        ?float $platformContributionMarginPercent = null,
        array $platformContributionMarginByPaymentMethod = [],
    ): array {
        $platformContributionMarginPercent = $this->normalizeMargin($platformContributionMarginPercent);
        $platformContributionMarginByPaymentMethod = $this->normalizePaymentMethodMargins($platformContributionMarginByPaymentMethod);
        $allowedEventIds = [];
        foreach ($eventIds as $eventId) {
            if (is_numeric($eventId) && (int) $eventId > 0) {
                $allowedEventIds[(int) $eventId] = true;
            }
        }

        if ($allowedEventIds === []) {
            return $this->emptySummary();
        }

        $journeys = [];
        $acceptedTypes = array_merge(array_values(self::STAGES), self::TERMINAL_FAILURES);

        foreach ($interactions as $interaction) {
            $type = trim((string) $this->value($interaction, ['interaction_type']));
            if (! in_array($type, $acceptedTypes, true)) {
                continue;
            }

            $journeyId = trim((string) $this->value($interaction, ['content', 'metadata', 'checkout_journey_id']));
            if (! preg_match('/^[a-zA-Z0-9._:-]{8,120}$/', $journeyId)) {
                continue;
            }

            $rawEventId = $this->value($interaction, ['content', 'metadata', 'event_id']);
            $eventId = is_numeric($rawEventId) ? (int) $rawEventId : null;
            $knownEventId = $journeys[$journeyId]['event_id'] ?? null;
            $effectiveEventId = $eventId ?: $knownEventId;

            if (! $effectiveEventId || ! isset($allowedEventIds[$effectiveEventId])) {
                continue;
            }

            $journeys[$journeyId] ??= $this->newJourney($effectiveEventId);
            $journeys[$journeyId]['event_id'] = $effectiveEventId;

            $amount = $this->value($interaction, ['content', 'metadata', 'amount']);
            if (is_numeric($amount) && (float) $amount >= 0) {
                $journeys[$journeyId]['amount'] ??= round((float) $amount, 2);
            }

            $paymentMethod = strtolower(trim((string) $this->value($interaction, ['content', 'metadata', 'payment_method'])));
            if (preg_match('/^[a-z][a-z0-9_-]{1,39}$/', $paymentMethod)) {
                $journeys[$journeyId]['payment_method'] = $paymentMethod;
            }

            foreach (self::STAGES as $stage => $stageType) {
                if ($type === $stageType) {
                    $journeys[$journeyId]['stages'][$stage] = true;
                    break;
                }
            }

            if ($type === 'frontend_checkout_abandoned') {
                $journeys[$journeyId]['abandoned'] = true;
                $journeys[$journeyId]['abandonment'] = $this->abandonmentMetadata($interaction);
            } elseif ($type === 'frontend_payment_failed') {
                $journeys[$journeyId]['payment_failed'] = true;
            }
        }

        if ($journeys === []) {
            return $this->emptySummary();
        }

        $stageCounts = array_fill_keys(array_keys(self::STAGES), 0);
        $stageGmv = array_fill_keys(array_keys(self::STAGES), 0.0);
        $stagePlatformContribution = array_fill_keys(array_keys(self::STAGES), 0.0);
        $methods = [];
        $openedGmv = 0.0;
        $approvedGmv = 0.0;
        $abandonedGmv = 0.0;
        $abandoned = 0;
        $paymentFailed = 0;
        $abandonmentDiagnostics = [];

        foreach ($journeys as $journey) {
            $amount = (float) ($journey['amount'] ?? 0.0);
            $estimatedPlatformContribution = $this->estimatePlatformContribution(
                $amount,
                (string) ($journey['payment_method'] ?: 'unknown'),
                $platformContributionMarginPercent,
                $platformContributionMarginByPaymentMethod,
            );

            foreach (array_keys(self::STAGES) as $stage) {
                if ($journey['stages'][$stage]) {
                    $stageCounts[$stage]++;
                    $stageGmv[$stage] += $amount;
                    if ($estimatedPlatformContribution !== null) {
                        $stagePlatformContribution[$stage] += $estimatedPlatformContribution;
                    }
                }
            }

            if ($journey['stages']['opened']) {
                $openedGmv += $amount;
            }
            if ($journey['stages']['payment_approved']) {
                $approvedGmv += $amount;
            }
            if ($journey['abandoned'] && ! $journey['stages']['payment_approved']) {
                $abandoned++;
                $abandonedGmv += $amount;
                $this->accumulateAbandonmentDiagnostic(
                    $abandonmentDiagnostics,
                    $journey,
                    $amount,
                    $estimatedPlatformContribution,
                );
            }
            if ($journey['payment_failed'] && ! $journey['stages']['payment_approved']) {
                $paymentFailed++;
            }

            $method = $journey['payment_method'] ?: 'unknown';
            $methods[$method] ??= ['journeys' => 0, 'attempted' => 0, 'approved' => 0, 'fulfilled' => 0];
            $methods[$method]['journeys']++;
            $methods[$method]['attempted'] += (int) $journey['stages']['payment_attempted'];
            $methods[$method]['approved'] += (int) $journey['stages']['payment_approved'];
            $methods[$method]['fulfilled'] += (int) $journey['stages']['fulfilled'];
        }

        $opened = $stageCounts['opened'];
        $hasContributionEstimate = $platformContributionMarginPercent !== null;
        $steps = [
            $this->step('checkout_opened', $opened, $stageCounts['payment_attempted'], $stageGmv['opened'], $stageGmv['payment_attempted'], $stagePlatformContribution['opened'], $stagePlatformContribution['payment_attempted'], $hasContributionEstimate),
            $this->step('payment_attempted', $stageCounts['payment_attempted'], $stageCounts['payment_approved'], $stageGmv['payment_attempted'], $stageGmv['payment_approved'], $stagePlatformContribution['payment_attempted'], $stagePlatformContribution['payment_approved'], $hasContributionEstimate),
            $this->step('payment_approved', $stageCounts['payment_approved'], $stageCounts['fulfilled'], $stageGmv['payment_approved'], $stageGmv['fulfilled'], $stagePlatformContribution['payment_approved'], $stagePlatformContribution['fulfilled'], $hasContributionEstimate),
        ];

        $largestDropoff = null;
        $largestEconomicDropoff = null;
        $largestContributionDropoff = null;
        foreach ($steps as $step) {
            if ($largestDropoff === null || $step['dropoff_journeys'] > $largestDropoff['dropoff_journeys']) {
                $largestDropoff = $step;
            }
            if ($largestEconomicDropoff === null || $step['gmv_at_risk'] > $largestEconomicDropoff['gmv_at_risk']) {
                $largestEconomicDropoff = $step;
            }
            if (($step['platform_contribution_at_risk'] ?? 0) > 0
                && ($largestContributionDropoff === null || $step['platform_contribution_at_risk'] > $largestContributionDropoff['platform_contribution_at_risk'])) {
                $largestContributionDropoff = $step;
            }
        }

        $byPaymentMethod = [];
        foreach ($methods as $method => $row) {
            $byPaymentMethod[] = [
                'payment_method' => $method,
                ...$row,
                'attempt_to_approved_rate_percent' => $row['attempted'] > 0
                    ? round(($row['approved'] / $row['attempted']) * 100, 2)
                    : null,
            ];
        }
        usort($byPaymentMethod, static fn (array $a, array $b): int => $b['attempted'] <=> $a['attempted']);

        return [
            'journeys' => count($journeys),
            'stages' => $stageCounts,
            'conversion' => [
                'opened_to_attempted_percent' => $this->rate($stageCounts['payment_attempted'], $opened),
                'opened_to_approved_percent' => $this->rate($stageCounts['payment_approved'], $opened),
                'attempted_to_approved_percent' => $this->rate($stageCounts['payment_approved'], $stageCounts['payment_attempted']),
                'approved_to_fulfilled_percent' => $this->rate($stageCounts['fulfilled'], $stageCounts['payment_approved']),
            ],
            'dropoff' => [
                'explicit_abandoned_journeys' => $abandoned,
                'payment_failed_journeys' => $paymentFailed,
                'steps' => $steps,
                'largest_step' => $largestDropoff,
                'largest_economic_step' => $largestEconomicDropoff,
                'largest_contribution_step' => $largestContributionDropoff,
                'abandonment_diagnostics' => $this->finalizeAbandonmentDiagnostics($abandonmentDiagnostics),
            ],
            'gmv' => [
                'opened' => round($openedGmv, 2),
                'approved' => round($approvedGmv, 2),
                'explicit_abandoned_at_risk' => round($abandonedGmv, 2),
                'by_stage' => array_map(static fn (float $value): float => round($value, 2), $stageGmv),
            ],
            'platform_contribution_estimate' => [
                'available' => $hasContributionEstimate,
                'basis' => $hasContributionEstimate ? 'observed_paid_order_margin' : null,
                'fallback_margin_percent' => $platformContributionMarginPercent,
                'payment_method_margin_percent' => $platformContributionMarginByPaymentMethod,
                'by_stage' => $hasContributionEstimate
                    ? array_map(static fn (float $value): float => round($value, 2), $stagePlatformContribution)
                    : array_fill_keys(array_keys(self::STAGES), null),
            ],
            'by_payment_method' => $byPaymentMethod,
        ];
    }

    /** @return array<string, mixed> */
    private function abandonmentMetadata(mixed $interaction): array
    {
        $reason = strtolower(trim((string) $this->value($interaction, ['content', 'metadata', 'reason'])));
        if (! preg_match('/^[a-z0-9._:-]{1,80}$/', $reason)) {
            $reason = 'unknown';
        }

        $elapsedMs = $this->value($interaction, ['content', 'metadata', 'elapsed_ms']);
        $ticketQuantity = $this->value($interaction, ['content', 'metadata', 'ticket_quantity']);
        $itemQuantity = $this->value($interaction, ['content', 'metadata', 'item_quantity']);

        return [
            'reason' => $reason,
            'elapsed_ms' => is_numeric($elapsedMs) ? max(0, (int) $elapsedMs) : null,
            'ticket_quantity' => is_numeric($ticketQuantity) ? max(0, (int) $ticketQuantity) : 0,
            'item_quantity' => is_numeric($itemQuantity) ? max(0, (int) $itemQuantity) : 0,
        ];
    }

    /** @param array<string, mixed> $diagnostics @param array<string, mixed> $journey */
    private function accumulateAbandonmentDiagnostic(array &$diagnostics, array $journey, float $amount, ?float $estimatedContribution): void
    {
        $metadata = is_array($journey['abandonment'] ?? null) ? $journey['abandonment'] : [];
        $reason = (string) ($metadata['reason'] ?? 'unknown');
        $method = (string) ($journey['payment_method'] ?: 'unknown');
        $elapsedBucket = $this->abandonmentElapsedBucket($metadata['elapsed_ms'] ?? null);
        $cartType = $this->abandonmentCartType(
            (int) ($metadata['ticket_quantity'] ?? 0),
            (int) ($metadata['item_quantity'] ?? 0),
        );

        foreach ([
            'by_reason' => $reason,
            'by_payment_method' => $method,
            'by_elapsed_time' => $elapsedBucket,
            'by_cart_type' => $cartType,
        ] as $dimension => $key) {
            $diagnostics[$dimension][$key] ??= [
                'key' => $key,
                'journeys' => 0,
                'gmv_at_risk' => 0.0,
                'platform_contribution_at_risk' => $estimatedContribution !== null ? 0.0 : null,
            ];
            $diagnostics[$dimension][$key]['journeys']++;
            $diagnostics[$dimension][$key]['gmv_at_risk'] += $amount;
            if ($estimatedContribution !== null) {
                $diagnostics[$dimension][$key]['platform_contribution_at_risk'] ??= 0.0;
                $diagnostics[$dimension][$key]['platform_contribution_at_risk'] += $estimatedContribution;
            }
        }
    }

    private function abandonmentElapsedBucket(mixed $elapsedMs): string
    {
        if (! is_numeric($elapsedMs)) {
            return 'unknown';
        }
        $seconds = max(0, ((int) $elapsedMs) / 1000);
        return match (true) {
            $seconds < 30 => 'under_30s',
            $seconds < 60 => '30_to_59s',
            $seconds < 180 => '1_to_2m',
            $seconds < 300 => '3_to_4m',
            default => '5m_plus',
        };
    }

    private function abandonmentCartType(int $tickets, int $items): string
    {
        return match (true) {
            $tickets > 0 && $items > 0 => 'tickets_plus_items',
            $tickets > 0 => 'tickets_only',
            $items > 0 => 'items_only',
            default => 'unknown',
        };
    }

    /** @param array<string, mixed> $diagnostics @return array<string, mixed> */
    private function finalizeAbandonmentDiagnostics(array $diagnostics): array
    {
        $result = [];
        foreach (['by_reason', 'by_payment_method', 'by_elapsed_time', 'by_cart_type'] as $dimension) {
            $rows = array_values($diagnostics[$dimension] ?? []);
            foreach ($rows as &$row) {
                $row['gmv_at_risk'] = round((float) $row['gmv_at_risk'], 2);
                if ($row['platform_contribution_at_risk'] !== null) {
                    $row['platform_contribution_at_risk'] = round((float) $row['platform_contribution_at_risk'], 2);
                }
            }
            unset($row);
            usort($rows, static fn (array $a, array $b): int => [$b['gmv_at_risk'], $b['journeys']] <=> [$a['gmv_at_risk'], $a['journeys']]);
            $result[$dimension] = $rows;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function newJourney(int $eventId): array
    {
        return [
            'event_id' => $eventId,
            'amount' => null,
            'payment_method' => null,
            'abandoned' => false,
            'payment_failed' => false,
            'abandonment' => [],
            'stages' => array_fill_keys(array_keys(self::STAGES), false),
        ];
    }

    /** @return array<string, mixed> */
    private function step(
        string $from,
        int $fromCount,
        int $toCount,
        float $fromGmv,
        float $toGmv,
        float $fromPlatformContribution,
        float $toPlatformContribution,
        bool $hasContributionEstimate,
    ): array {
        $dropoff = max(0, $fromCount - $toCount);

        return [
            'from' => $from,
            'to' => match ($from) {
                'checkout_opened' => 'payment_attempted',
                'payment_attempted' => 'payment_approved',
                default => 'checkout_fulfilled',
            },
            'from_journeys' => $fromCount,
            'to_journeys' => $toCount,
            'dropoff_journeys' => $dropoff,
            'dropoff_percent' => $fromCount > 0 ? round(($dropoff / $fromCount) * 100, 2) : null,
            'gmv_from' => round($fromGmv, 2),
            'gmv_to' => round($toGmv, 2),
            'gmv_at_risk' => round(max(0.0, $fromGmv - $toGmv), 2),
            'platform_contribution_from' => $hasContributionEstimate ? round($fromPlatformContribution, 2) : null,
            'platform_contribution_to' => $hasContributionEstimate ? round($toPlatformContribution, 2) : null,
            'platform_contribution_at_risk' => $hasContributionEstimate
                ? round(max(0.0, $fromPlatformContribution - $toPlatformContribution), 2)
                : null,
            'recommended_action' => $this->recommendedAction($from),
        ];
    }

    /** @return array{code: string, target_metric: string, guardrails: array<int, string>} */
    private function recommendedAction(string $from): array
    {
        return match ($from) {
            'checkout_opened' => [
                'code' => 'reduce_payment_entry_friction',
                'target_metric' => 'opened_to_attempted_percent',
                'guardrails' => ['price_transparency', 'cart_integrity'],
            ],
            'payment_attempted' => [
                'code' => 'improve_payment_approval',
                'target_metric' => 'attempted_to_approved_percent',
                'guardrails' => ['payment_idempotency', 'no_duplicate_charge'],
            ],
            default => [
                'code' => 'protect_post_payment_fulfillment',
                'target_metric' => 'approved_to_fulfilled_percent',
                'guardrails' => ['ticket_exactly_once', 'qr_checkin_integrity'],
            ],
        };
    }

    private function normalizeMargin(?float $margin): ?float
    {
        if ($margin === null || ! is_finite($margin)) {
            return null;
        }

        return round(min(max($margin, -100.0), 100.0), 4);
    }

    /** @param array<string, mixed> $margins @return array<string, float> */
    private function normalizePaymentMethodMargins(array $margins): array
    {
        $normalized = [];
        foreach ($margins as $method => $margin) {
            $method = strtolower(trim((string) $method));
            if (! preg_match('/^[a-z][a-z0-9_-]{1,39}$/', $method) || ! is_numeric($margin)) {
                continue;
            }
            $normalizedMargin = $this->normalizeMargin((float) $margin);
            if ($normalizedMargin !== null) {
                $normalized[$method] = $normalizedMargin;
            }
        }
        ksort($normalized);
        return $normalized;
    }

    /** @param array<string, float> $margins */
    private function estimatePlatformContribution(float $amount, string $paymentMethod, ?float $fallbackMargin, array $margins): ?float
    {
        if ($fallbackMargin === null) {
            return null;
        }
        $margin = $margins[$paymentMethod] ?? $fallbackMargin;
        return $amount * ($margin / 100);
    }

    private function rate(int $numerator, int $denominator): ?float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 2) : null;
    }

    private function value(mixed $source, array $path): mixed
    {
        $value = $source;

        foreach ($path as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
                continue;
            }

            if ($value instanceof ArrayAccess && $value->offsetExists($segment)) {
                $value = $value[$segment];
                continue;
            }

            if (is_object($value) && method_exists($value, 'getAttribute')) {
                $value = $value->getAttribute($segment);
                continue;
            }

            if (is_object($value) && (isset($value->{$segment}) || property_exists($value, $segment))) {
                $value = $value->{$segment};
                continue;
            }

            return null;
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function emptySummary(): array
    {
        return [
            'journeys' => 0,
            'stages' => array_fill_keys(array_keys(self::STAGES), 0),
            'conversion' => [
                'opened_to_attempted_percent' => null,
                'opened_to_approved_percent' => null,
                'attempted_to_approved_percent' => null,
                'approved_to_fulfilled_percent' => null,
            ],
            'dropoff' => [
                'explicit_abandoned_journeys' => 0,
                'payment_failed_journeys' => 0,
                'steps' => [],
                'largest_step' => null,
                'largest_economic_step' => null,
                'largest_contribution_step' => null,
                'abandonment_diagnostics' => $this->finalizeAbandonmentDiagnostics([]),
            ],
            'gmv' => [
                'opened' => 0.0,
                'approved' => 0.0,
                'explicit_abandoned_at_risk' => 0.0,
                'by_stage' => array_fill_keys(array_keys(self::STAGES), 0.0),
            ],
            'platform_contribution_estimate' => [
                'available' => false,
                'basis' => null,
                'fallback_margin_percent' => null,
                'payment_method_margin_percent' => [],
                'by_stage' => array_fill_keys(array_keys(self::STAGES), null),
            ],
            'by_payment_method' => [],
        ];
    }
}
