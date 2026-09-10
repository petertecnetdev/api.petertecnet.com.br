<?php

namespace App\Domain\Creative\Services;

final class CreativeBriefBuilder
{
    public function build(array $data, array $direction, array $safeZones): array
    {
        $palette = collect($data['brand_colors'] ?? [])
            ->filter(fn ($value) => is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value))
            ->take(5)
            ->values()
            ->all();

        $memory = collect($data['creative_memory'] ?? [])
            ->filter(fn ($value) => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn ($value) => mb_substr(trim((string) $value), 0, 120))
            ->take(8)
            ->values()
            ->all();

        return [
            'subject' => trim((string) ($data['subject'] ?? '')),
            'category' => trim((string) ($data['category'] ?? '')),
            'artist' => trim((string) ($data['artist'] ?? '')),
            'production' => trim((string) ($data['production_name'] ?? '')),
            'venue' => trim((string) ($data['venue'] ?? '')),
            'location' => trim(implode(' / ', array_filter([
                $data['city'] ?? null,
                isset($data['uf']) ? strtoupper((string) $data['uf']) : null,
            ]))),
            'style' => $direction['style_key'],
            'intensity' => $direction['intensity_key'],
            'format' => $direction['format_key'],
            'ratio' => $direction['ratio'],
            'palette' => $palette,
            'brand_context' => mb_substr(trim((string) ($data['brand_context'] ?? '')), 0, 500),
            'reference_notes' => mb_substr(trim((string) ($data['reference_notes'] ?? '')), 0, 500),
            'memory' => $memory,
            'safe_zones' => $safeZones,
            'rules' => [
                'render_text' => false,
                'render_logos' => false,
                'render_prices' => false,
                'render_dates' => false,
                'avoid_ai_artifacts' => true,
                'preserve_typography_space' => true,
            ],
        ];
    }

    public function toPromptContext(array $brief): string
    {
        $parts = [
            $brief['palette'] ? 'Preferred brand palette: '.implode(', ', $brief['palette']) : null,
            $brief['reference_notes'] !== '' ? 'Visual reference notes: '.$brief['reference_notes'] : null,
            $brief['memory'] ? 'Avoid repeating these recent visual choices: '.implode('; ', $brief['memory']) : null,
        ];

        return implode('. ', array_filter($parts));
    }
}
