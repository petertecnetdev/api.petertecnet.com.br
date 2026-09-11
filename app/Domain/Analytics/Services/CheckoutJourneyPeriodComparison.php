<?php

namespace App\Domain\Analytics\Services;

final class CheckoutJourneyPeriodComparison
{
    /** @param array<string, mixed> $current @param array<string, mixed> $previous @return array<string, mixed> */
    public function compare(array $current, array $previous, int $periodDays): array
    {
        $currentOpened = (int) data_get($current, 'stages.opened', 0);
        $previousOpened = (int) data_get($previous, 'stages.opened', 0);
        $currentApproved = $this->number(data_get($current, 'conversion.opened_to_approved_percent'));
        $previousApproved = $this->number(data_get($previous, 'conversion.opened_to_approved_percent'));
        $currentContributionRisk = $this->number(data_get($current, 'dropoff.largest_contribution_step.platform_contribution_at_risk'));
        $previousContributionRisk = $this->number(data_get($previous, 'dropoff.largest_contribution_step.platform_contribution_at_risk'));
        $sampleComparable = $currentOpened >= 20 && $previousOpened >= 20;

        $approvedDelta = $this->delta($currentApproved, $previousApproved);
        $riskDelta = $this->delta($currentContributionRisk, $previousContributionRisk);
        $status = 'collecting';
        if ($sampleComparable) {
            if (($approvedDelta ?? 0) > 0 && ($riskDelta === null || $riskDelta <= 0)) {
                $status = 'improving';
            } elseif (($approvedDelta ?? 0) < 0 && ($riskDelta === null || $riskDelta >= 0)) {
                $status = 'regressing';
            } else {
                $status = 'mixed';
            }
        }

        return [
            'period_days' => $periodDays,
            'sample_is_comparable' => $sampleComparable,
            'minimum_opened_journeys_per_period' => 20,
            'status' => $status,
            'current_opened_journeys' => $currentOpened,
            'previous_opened_journeys' => $previousOpened,
            'conversion' => [
                'current_opened_to_approved_percent' => $currentApproved,
                'previous_opened_to_approved_percent' => $previousApproved,
                'opened_to_approved_delta_percentage_points' => $approvedDelta,
            ],
            'platform_contribution_risk' => [
                'current_largest_step_amount' => $currentContributionRisk,
                'previous_largest_step_amount' => $previousContributionRisk,
                'largest_step_amount_delta' => $riskDelta,
            ],
            'recommended_action_effectiveness' => $this->recommendedActionEffectiveness(
                $current,
                $previous,
                $sampleComparable,
            ),
        ];
    }

    /** @param array<string, mixed> $current @param array<string, mixed> $previous @return array<string, mixed>|null */
    private function recommendedActionEffectiveness(array $current, array $previous, bool $sampleComparable): ?array
    {
        $currentStep = data_get($current, 'dropoff.largest_contribution_step');
        if (! is_array($currentStep)) {
            return null;
        }

        $from = trim((string) ($currentStep['from'] ?? ''));
        $to = trim((string) ($currentStep['to'] ?? ''));
        $action = $currentStep['recommended_action'] ?? null;
        $targetMetric = is_array($action) ? trim((string) ($action['target_metric'] ?? '')) : '';
        $actionCode = is_array($action) ? trim((string) ($action['code'] ?? '')) : '';
        if ($from === '' || $to === '' || $targetMetric === '') {
            return null;
        }

        $previousStep = $this->findStep($previous, $from, $to);
        $currentRate = $this->number(data_get($current, 'conversion.'.$targetMetric));
        $previousRate = $this->number(data_get($previous, 'conversion.'.$targetMetric));
        $currentRisk = $this->number($currentStep['platform_contribution_at_risk'] ?? null);
        $previousRisk = $this->number($previousStep['platform_contribution_at_risk'] ?? null);
        $targetDelta = $this->delta($currentRate, $previousRate);
        $riskDelta = $this->delta($currentRisk, $previousRisk);

        $status = 'collecting';
        if ($sampleComparable && $currentRate !== null && $previousRate !== null) {
            if (($targetDelta ?? 0) > 0 && ($riskDelta === null || $riskDelta <= 0)) {
                $status = 'improving';
            } elseif (($targetDelta ?? 0) < 0 && ($riskDelta === null || $riskDelta >= 0)) {
                $status = 'regressing';
            } else {
                $status = 'mixed';
            }
        }

        return [
            'status' => $status,
            'action_code' => $actionCode !== '' ? $actionCode : null,
            'target_metric' => $targetMetric,
            'step' => [
                'from' => $from,
                'to' => $to,
            ],
            'target_metric_current_percent' => $currentRate,
            'target_metric_previous_percent' => $previousRate,
            'target_metric_delta_percentage_points' => $targetDelta,
            'platform_contribution_at_risk_current' => $currentRisk,
            'platform_contribution_at_risk_previous_same_step' => $previousRisk,
            'platform_contribution_at_risk_delta' => $riskDelta,
        ];
    }

    /** @param array<string, mixed> $summary @return array<string, mixed> */
    private function findStep(array $summary, string $from, string $to): array
    {
        $steps = data_get($summary, 'dropoff.steps', []);
        if (! is_array($steps)) {
            return [];
        }

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            if (($step['from'] ?? null) === $from && ($step['to'] ?? null) === $to) {
                return $step;
            }
        }

        return [];
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function delta(?float $current, ?float $previous): ?float
    {
        return $current === null || $previous === null ? null : round($current - $previous, 2);
    }
}
