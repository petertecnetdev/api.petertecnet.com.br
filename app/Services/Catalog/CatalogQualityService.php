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
        return $this->score([
            'name' => $item->name,
            'price' => $item->price,
            'category' => $item->category,
            'brand' => $item->brand ?: $variant?->product?->brand,
            'sku' => $item->sku,
            'gtin' => $variant?->gtin,
            'description' => $item->description,
            'package_quantity' => $variant?->package_quantity,
            'package_unit' => $variant?->package_unit,
            'specifications' => $variant?->specifications ?? [],
        ]);
    }

    public function evaluateRow(array $row): array
    {
        return $this->score($row);
    }

    private function score(array $data): array
    {
        $score = 0;
        $issues = [];
        $specs = is_array($data['specifications'] ?? null) ? $data['specifications'] : [];
        $profile = $this->profileFor($data['category'] ?? null, $data['name'] ?? null);
        $rules = config("catalog.specification_profiles.{$profile}", config('catalog.specification_profiles.default_product', []));

        $this->award($score, $issues, filled($data['name'] ?? null), 20, 'Nome do produto ausente.');
        $this->award($score, $issues, is_numeric($data['price'] ?? null) && (float) $data['price'] >= 0, 15, 'Preço ausente ou inválido.');
        $this->award($score, $issues, filled($data['category'] ?? null), 10, 'Categoria não informada.');
        $this->award($score, $issues, filled($data['brand'] ?? null), 10, 'Marca não informada.');
        $this->award($score, $issues, filled($data['gtin'] ?? null) || filled($data['sku'] ?? null), 10, 'EAN/GTIN ou SKU não informado.');
        $this->award($score, $issues, filled($data['description'] ?? null), 10, 'Descrição ausente.');

        $required = $rules['required'] ?? [];
        $requiredComplete = true;
        foreach ($required as $field) {
            $value = $this->rowValue($field, $data, $specs);
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
            ->filter(fn ($field) => filled($this->rowValue($field, $data, $specs)))
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

    private function rowValue(string $field, array $data, array $specs): mixed
    {
        return match ($field) {
            'package_quantity', 'package_unit', 'brand' => $data[$field] ?? null,
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
