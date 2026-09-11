<?php

namespace App\Domain\Discovery\Services;

use Illuminate\Support\Str;

final class SearchRelevance
{
    public function score(
        string $query,
        string $title,
        ?string $subtitle = null,
        array $aliases = [],
        array $signals = []
    ): float {
        $query = $this->normalize($query);
        $title = $this->normalize($title);
        $subtitle = $this->normalize((string) $subtitle);
        $aliasText = $this->normalize(implode(' ', $aliases));
        $haystack = trim($title.' '.$subtitle.' '.$aliasText);

        if ($query === '') {
            return $this->signalScore($signals);
        }

        $score = 0.0;

        if ($title === $query) {
            $score += 1200;
        } elseif (str_starts_with($title, $query)) {
            $score += 760;
        } elseif (str_contains($title, $query)) {
            $score += 520;
        } elseif ($subtitle !== '' && str_contains($subtitle, $query)) {
            $score += 260;
        } elseif ($aliasText !== '' && str_contains($aliasText, $query)) {
            $score += 220;
        }

        $queryTokens = array_values(array_filter(explode(' ', $query)));
        $haystackTokens = array_values(array_filter(explode(' ', $haystack)));

        foreach ($queryTokens as $token) {
            $best = 0.0;
            foreach ($haystackTokens as $candidate) {
                if ($candidate === $token) {
                    $best = max($best, 150);
                    continue;
                }

                if (str_starts_with($candidate, $token) || str_starts_with($token, $candidate)) {
                    $best = max($best, 110);
                    continue;
                }

                if (str_contains($candidate, $token) || str_contains($token, $candidate)) {
                    $best = max($best, 80);
                    continue;
                }

                $distance = levenshtein($token, $candidate);
                $maxLength = max(strlen($token), strlen($candidate), 1);
                $ratio = 1 - ($distance / $maxLength);

                if ($distance <= 1) {
                    $best = max($best, 72);
                } elseif ($distance <= 2 && $maxLength >= 5) {
                    $best = max($best, 52);
                } elseif ($ratio >= .72) {
                    $best = max($best, 34 * $ratio);
                }
            }
            $score += $best;
        }

        if (count($queryTokens) > 1) {
            $matchedTokens = collect($queryTokens)->filter(fn ($token) => str_contains($haystack, $token))->count();
            $score += ($matchedTokens / count($queryTokens)) * 180;
        }

        return round($score + $this->signalScore($signals), 4);
    }

    public function normalize(string $value): string
    {
        $value = Str::ascii(Str::lower(trim($value)));
        $value = preg_replace('/[^a-z0-9\s\-]+/', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    private function signalScore(array $signals): float
    {
        $score = 0.0;

        $popularity = max(0, (float) ($signals['popularity'] ?? 0));
        $views = max(0, (float) ($signals['views'] ?? 0));
        $sales = max(0, (float) ($signals['sales'] ?? 0));
        $followers = max(0, (float) ($signals['followers'] ?? 0));

        $score += min(130, log10($popularity + 1) * 36);
        $score += min(90, log10($views + 1) * 24);
        $score += min(120, log10($sales + 1) * 32);
        $score += min(90, log10($followers + 1) * 24);

        if (! empty($signals['followed'])) {
            $score += 180;
        }
        if (! empty($signals['previously_viewed'])) {
            $score += 80;
        }
        if (! empty($signals['purchased'])) {
            $score += 145;
        }
        if (! empty($signals['happening_now'])) {
            $score += 220;
        }
        if (! empty($signals['upcoming'])) {
            $score += 100;
        }
        if (! empty($signals['featured'])) {
            $score += 70;
        }

        if (isset($signals['distance_km']) && is_numeric($signals['distance_km'])) {
            $distance = max(0.0, (float) $signals['distance_km']);
            $score += max(0, 160 - min(160, $distance * 4));
        }

        if (isset($signals['hours_until']) && is_numeric($signals['hours_until'])) {
            $hours = max(0.0, (float) $signals['hours_until']);
            $score += max(0, 130 - min(130, $hours / 4));
        }

        if (! empty($signals['sponsored'])) {
            $score += 250 + min(250, (float) ($signals['campaign_priority'] ?? 0));
        }

        return $score;
    }
}
