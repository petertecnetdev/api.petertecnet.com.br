<?php

namespace App\Domain\Creative\Services;

final class CreativeQualityEvaluator
{
    public function evaluate(array $result, array $brief, string $prompt): array
    {
        $score = 100;
        $issues = [];

        $encoded = (string) ($result['image'] ?? '');
        $bytes = $encoded !== '' ? (int) floor(strlen($encoded) * 0.75) : 0;

        if ($bytes < 40_000) {
            $score -= 35;
            $issues[] = 'generated_asset_too_small';
        } elseif ($bytes < 100_000) {
            $score -= 15;
            $issues[] = 'generated_asset_low_detail_risk';
        }

        if (empty($result['mime_type']) || ! str_starts_with((string) $result['mime_type'], 'image/')) {
            $score -= 35;
            $issues[] = 'invalid_image_mime';
        }

        if (! str_contains(mb_strtolower($prompt), 'do not render any readable words')) {
            $score -= 20;
            $issues[] = 'text_suppression_missing';
        }

        if (empty($brief['safe_zones'])) {
            $score -= 15;
            $issues[] = 'safe_zones_missing';
        }

        if (($brief['rules']['avoid_ai_artifacts'] ?? false) !== true) {
            $score -= 10;
            $issues[] = 'artifact_guard_missing';
        }

        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'approved' => $score >= (int) config('creative.quality.minimum_score', 70),
            'issues' => $issues,
            'bytes_estimate' => $bytes,
        ];
    }
}
