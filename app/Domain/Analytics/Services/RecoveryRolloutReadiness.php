<?php

namespace App\Domain\Analytics\Services;

final class RecoveryRolloutReadiness
{
    /**
     * @param array<string, mixed> $comparison
     * @return array<string, mixed>
     */
    public function evaluate(array $comparison): array
    {
        $decision = is_array($comparison['decision'] ?? null) ? $comparison['decision'] : [];
        $confidence = is_array($decision['confidence'] ?? null) ? $decision['confidence'] : [];
        $guardrails = is_array($decision['guardrails'] ?? null) ? $decision['guardrails'] : [];

        $mature = (bool) ($comparison['sample_is_mature'] ?? false);
        $eligible = ($decision['eligible_for_rollout'] ?? false) === true;
        $requiresManualReview = ($decision['requires_manual_review'] ?? true) !== false;
        $conversionGuardrail = ($confidence['paid_conversion_guardrail_satisfied'] ?? false) === true;
        $contributionGuardrail = ($guardrails['platform_contribution_positive'] ?? false) === true;

        $status = 'collecting';
        $recommendedAction = 'collect_more_data';

        if ($mature && ($decision['status'] ?? null) === 'harmful') {
            $status = 'blocked';
            $recommendedAction = 'keep_control';
        } elseif (
            $mature
            && ($decision['status'] ?? null) === 'winner'
            && $eligible
            && $conversionGuardrail
            && $contributionGuardrail
        ) {
            $status = $requiresManualReview ? 'ready_for_review' : 'ready';
            $recommendedAction = $requiresManualReview ? 'review_rollout' : 'rollout_prominent';
        } elseif ($mature) {
            $status = 'hold';
            $recommendedAction = 'keep_control';
        }

        return [
            'status' => $status,
            'recommended_action' => $recommendedAction,
            'sample_is_mature' => $mature,
            'eligible_for_rollout' => $eligible,
            'requires_manual_review' => $requiresManualReview,
            'guardrails' => [
                'conversion' => $conversionGuardrail,
                'contribution' => $contributionGuardrail,
            ],
            'confidence' => $comparison['paid_conversion_difference_confidence_95'] ?? null,
            'projection' => $comparison['observed_volume_projection'] ?? null,
        ];
    }
}
