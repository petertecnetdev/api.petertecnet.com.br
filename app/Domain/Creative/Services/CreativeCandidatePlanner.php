<?php

namespace App\Domain\Creative\Services;

final class CreativeCandidatePlanner
{
    private const VARIATIONS = [
        'balanced' => 'Use a balanced hero composition with strong depth and commercial polish.',
        'cinematic' => 'Push cinematic lighting, believable depth, premium photography and sophisticated framing.',
        'bold' => 'Increase visual drama, energy, foreground/background layering and campaign impact without clutter.',
        'editorial' => 'Use refined editorial art direction, elegant negative space and a distinctive fashion-advertising composition.',
    ];

    public function prompts(string $basePrompt, int $count = 3, ?string $requestedVariation = null): array
    {
        $count = max(1, min(4, $count));
        $keys = array_keys(self::VARIATIONS);

        if ($requestedVariation && isset(self::VARIATIONS[$requestedVariation])) {
            $keys = array_values(array_unique([$requestedVariation, ...$keys]));
        }

        $prompts = [];
        for ($index = 0; $index < $count; $index++) {
            $key = $keys[$index % count($keys)];
            $prompts[] = [
                'variation' => $key,
                'prompt' => trim($basePrompt."\nCANDIDATE DIRECTION: ".self::VARIATIONS[$key]),
            ];
        }

        return $prompts;
    }

    public function variationKeys(): array
    {
        return array_keys(self::VARIATIONS);
    }
}
