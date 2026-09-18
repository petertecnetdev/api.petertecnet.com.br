<?php

namespace App\Services;

use App\Models\AiEditorialProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class AiEditorialProfileService
{
    public function forProduction(int $appId, int $productionId, Collection $history): array
    {
        $descriptions = $history
            ->pluck('description')
            ->map(fn ($value) => $this->plain((string) $value))
            ->filter()
            ->values();

        $wordCounts = $descriptions->map(fn ($text) => count($this->tokens($text)));
        $paragraphCounts = $history->pluck('description')->map(function ($text) {
            return max(1, count(array_filter(preg_split('/\n\s*\n+/u', (string) $text) ?: [])));
        });

        $openingPatterns = $descriptions
            ->map(fn ($text) => trim((string) preg_split('/(?<=[.!?])\s+/u', $text)[0] ?? ''))
            ->filter()
            ->map(fn ($text) => mb_substr($text, 0, 180))
            ->take(8)
            ->values()
            ->all();

        $avoidPhrases = $this->commonPhrases($descriptions->all());

        $traits = [
            'average_words' => $wordCounts->isEmpty() ? 0 : (int) round($wordCounts->avg()),
            'average_paragraphs' => $paragraphCounts->isEmpty() ? 0 : round((float) $paragraphCounts->avg(), 1),
            'opening_examples' => $openingPatterns,
            'historical_source_count' => $descriptions->count(),
            'goal' => 'preservar identidade da produção sem repetir estruturas, aberturas ou clichês',
        ];

        AiEditorialProfile::query()->updateOrCreate(
            [
                'app_id' => $appId,
                'scope_type' => 'production',
                'scope_id' => $productionId,
            ],
            [
                'traits' => $traits,
                'avoid_phrases' => $avoidPhrases,
                'source_count' => $descriptions->count(),
                'source_updated_at' => now(),
            ],
        );

        return [
            'traits' => $traits,
            'avoid_phrases' => $avoidPhrases,
        ];
    }

    private function commonPhrases(array $texts): array
    {
        $documentCounts = [];

        foreach ($texts as $text) {
            $tokens = $this->tokens($text);
            $seen = [];
            for ($size = 4; $size <= 6; $size++) {
                for ($i = 0; $i <= count($tokens) - $size; $i++) {
                    $phrase = implode(' ', array_slice($tokens, $i, $size));
                    if (mb_strlen($phrase) < 22) continue;
                    $seen[$phrase] = true;
                }
            }

            foreach (array_keys($seen) as $phrase) {
                $documentCounts[$phrase] = ($documentCounts[$phrase] ?? 0) + 1;
            }
        }

        arsort($documentCounts);

        $result = [];
        foreach ($documentCounts as $phrase => $count) {
            if ($count < 2) continue;
            if ($this->isMostlyStopWords($phrase)) continue;
            $result[] = $phrase;
            if (count($result) >= 14) break;
        }

        return $result;
    }

    private function isMostlyStopWords(string $phrase): bool
    {
        $stop = array_flip(['a','o','as','os','de','da','do','das','dos','e','em','na','no','nas','nos','para','por','com','um','uma','que','se']);
        $tokens = preg_split('/\s+/u', $phrase) ?: [];
        if ($tokens === []) return true;
        $meaningful = count(array_filter($tokens, fn ($token) => ! isset($stop[$token]) && mb_strlen($token) > 2));
        return $meaningful < 2;
    }

    private function tokens(string $value): array
    {
        $value = Str::ascii(mb_strtolower($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
        return array_values(array_filter(preg_split('/\s+/u', trim($value)) ?: []));
    }

    private function plain(string $value): string
    {
        $value = preg_replace('/\*\*(.*?)\*\*/su', '$1', $value) ?? $value;
        $value = preg_replace('/(?<!\*)\*(?!\*)(.*?)\*(?!\*)/su', '$1', $value) ?? $value;
        $value = preg_replace('/^\s*[-•#]+\s*/mu', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        return $value;
    }
}
