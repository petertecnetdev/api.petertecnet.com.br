<?php

namespace App\Services\Catalog;

use App\Models\Item;
use App\Models\ProductVariant;
use Illuminate\Support\Str;

class CatalogQualityService
{
    public function profileFor(?string $category, ?string $name = null): string
    {
        $haystack = Str::lower(trim(($category ?? '') . ' ' . ($name ?? '')));

        foreach (config('catalog.profile_keywords', []) as $profile => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, Str::lower($keyword))) {
                    return $profile;
                }
            }
        }

        return 'default_product';
    }

    public function evaluate(Item $item, ?ProductVariant $variant = null): array
    {
        $score = 0;
        $issues = [];
        $specs = $variant?->specifications ?? [];
        $profile = $this->profileFor($item->category, $item->name);
        $rules = config("catalog.specification_profiles.{$profile}", config('catalog.specification_profiles.default_product', []));

        $this->award($score, $issues, filled($item->name), 20, 'Nome do produto ausente.');
        $this->award($score, $issues, is_numeric($item->price) && (float) $item->price >= 0, 15, 'Preço ausente ou inválido.');
        $this->award($score, $issues, filled($item->category), 10, 'Categoria não informada.');
        $this->award($score, $issues, filled($item->brand) || filled($variant?->product?->brand), 10, 'Marca não informada.');
        $this->award($score, $issues, filled($variant?->gtin) || filled($item->sku), 10, 'EAN/GTIN ou SKU não informado.');
        $this->award($score, $issues, filled($item->description), 10, 'Descrição ausente.');

        $required = $rules['required'] ?? [];
        $requiredComplete = true;
        foreach ($required as $field) {
            $value = $this->valueFor($field, $variant, $specs);
            if (! filled($value)) {
                $requiredComplete = false;
                $issues[] = "Especificação obrigatória ausente: {$field}.";
            }
        }
        if ($requiredComplete) {
            $score += 20;
        }

        $recommended = $rules['recommended'] ?? [];
        $recommendedHits = collect($recommended)
            ->filter(fn ($field) => filled($this->valueFor($field, $variant, $specs)))
            ->count();
        if (count($recommended) === 0 || $recommendedHits === count($recommended)) {
            $score += 5;
        }

        $score = min(100, $score);
        $status = match (true) {
            $score >= (int) config('catalog.quality.excellent_threshold', 90) => 'excellent',
            $score >= (int) config('catalog.quality.publish_threshold', 70) => 'ready',
            default => 'review',
        };

        return [
            'score' => $score,
            'status' => $status,
            'profile' => $profile,
            'issues' => array_values(array_unique($issues)),
            'publish_ready' => $score >= (int) config('catalog.quality.publish_threshold', 70),
        ];
    }

    private function valueFor(string $field, ?ProductVariant $variant, array $specs): mixed
    {
        return match ($field) {
            'package_quantity' => $variant?->package_quantity,
            'package_unit' => $variant?->package_unit,
            'brand' => $variant?->product?->brand,
            default => $specs[$field] ?? null,
        };
    }

    private function award(int &$score, array &$issues, bool $condition, int $points, string $issue): void
    {
        if ($condition) {
            $score += $points;
            return;
        }

        $issues[] = $issue;
    }
}
