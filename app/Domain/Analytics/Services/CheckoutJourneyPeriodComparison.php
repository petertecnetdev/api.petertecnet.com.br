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
        ];
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
