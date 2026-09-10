<?php

namespace App\Domain\Creative\Services;

final class CreativeProfileResolver
{
    public function resolve(array $data): array
    {
        return [
            'production_name' => trim((string) ($data['production_name'] ?? '')),
            'brand_context' => mb_substr(trim((string) ($data['brand_context'] ?? '')), 0, 500),
            'brand_colors' => collect($data['brand_colors'] ?? [])
                ->filter(fn ($value) => is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value))
                ->take(5)
                ->values()
                ->all(),
            'reference_notes' => mb_substr(trim((string) ($data['reference_notes'] ?? '')), 0, 500),
        ];
    }

    public function prompt(array $profile): string
    {
        return implode('. ', array_filter([
            $profile['production_name'] !== '' ? 'Brand/producer: '.$profile['production_name'] : null,
            $profile['brand_context'] !== '' ? 'Persistent brand character: '.$profile['brand_context'] : null,
            $profile['brand_colors'] ? 'Prefer these brand colors when aesthetically appropriate: '.implode(', ', $profile['brand_colors']) : null,
            $profile['reference_notes'] !== '' ? 'Reference direction: '.$profile['reference_notes'] : null,
        ]));
    }
}
