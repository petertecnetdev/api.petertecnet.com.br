<?php

namespace App\Services\Catalog;

use App\Models\Item;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogProductService
{
    public function __construct(private readonly CatalogQualityService $quality)
    {
    }

    public function attach(Item $item, array $input): array
    {
        return DB::transaction(function () use ($item, $input) {
            $canonicalName = trim((string) ($input['canonical_name'] ?? $item->name));
            $normalizedName = $this->normalize($canonicalName);
            $brand = trim((string) ($input['brand'] ?? $item->brand ?? ''));
            $gtin = $this->digits($input['gtin'] ?? null);
            $sku = trim((string) ($input['sku'] ?? $item->sku ?? '')) ?: null;

            $product = $this->resolveProduct($normalizedName, $brand, $input, $gtin);
            $variant = $this->resolveVariant($product, $input, $gtin, $sku);

            $item->forceFill([
                'name' => $canonicalName ?: $item->name,
                'sku' => $sku ?: $item->sku,
                'brand' => $brand ?: $item->brand,
                'category' => $input['category'] ?? $item->category,
                'subcategory' => $input['subcategory'] ?? $item->subcategory,
                'product_variant_id' => $variant->id,
                'sale_unit' => $input['sale_unit'] ?? 'un',
                'data_source' => $input['source'] ?? 'manual',
            ])->save();

            $quality = $this->quality->evaluate($item->fresh(), $variant->loadMissing('product'));
            $metadata = [
                'quality' => $quality,
                'provenance' => $input['provenance'] ?? [],
                'canonicalized_at' => now()->toIso8601String(),
            ];

            $item->forceFill([
                'quality_score' => $quality['score'],
                'quality_status' => $quality['status'],
                'catalog_metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ])->save();

            if (! empty($input['alias'])) {
                $this->rememberAlias($product, (string) $input['alias'], $input['source'] ?? 'manual');
            }

            return [
                'item' => $item->fresh(),
                'product' => $product->fresh(),
                'variant' => $variant->fresh(),
                'quality' => $quality,
            ];
        }, 3);
    }

    public function resolveByIdentity(?string $gtin, ?string $name, ?string $brand = null): ?ProductVariant
    {
        $digits = $this->digits($gtin);
        if ($digits) {
            return ProductVariant::query()->with('product')->where('gtin', $digits)->first();
        }

        $normalized = $this->normalize((string) $name);
        if ($normalized === '') {
            return null;
        }

        $aliasProduct = $this->resolveAliasProduct($normalized, (string) $brand);
        if ($aliasProduct) {
            return $aliasProduct->variants()->first();
        }

        $key = $this->productKey($normalized, (string) $brand);
        $product = Product::query()->where('canonical_key', $key)->with('variants')->first();

        return $product?->variants?->first();
    }

    public function rememberAlias(Product $product, string $alias, string $source = 'manual'): ProductAlias
    {
        $normalized = $this->normalize($alias);

        return ProductAlias::query()->updateOrCreate(
            ['product_id' => $product->id, 'normalized_alias' => $normalized],
            ['alias' => trim($alias), 'source' => $source]
        );
    }

    private function resolveProduct(string $normalizedName, string $brand, array $input, ?string $gtin): Product
    {
        if ($gtin) {
            $variant = ProductVariant::query()->with('product')->where('gtin', $gtin)->first();
            if ($variant) {
                return $variant->product;
            }
        }

        $aliasProduct = $this->resolveAliasProduct($normalizedName, $brand);
        if ($aliasProduct) {
            return $aliasProduct;
        }

        $key = $this->productKey($normalizedName, $brand);

        return Product::query()->firstOrCreate(
            ['canonical_key' => $key],
            [
                'public_id' => (string) Str::uuid(),
                'name' => trim((string) ($input['canonical_name'] ?? $input['name'] ?? 'Produto')),
                'brand' => $brand ?: null,
                'category' => $input['category'] ?? null,
                'subcategory' => $input['subcategory'] ?? null,
                'description' => $input['canonical_description'] ?? null,
                'metadata' => ['created_from' => $input['source'] ?? 'manual'],
            ]
        );
    }

    private function resolveVariant(Product $product, array $input, ?string $gtin, ?string $sku): ProductVariant
    {
        if ($gtin) {
            $existing = ProductVariant::query()->where('gtin', $gtin)->first();
            if ($existing) {
                return $existing;
            }
        }

        $specifications = $this->normalizeSpecifications($input['specifications'] ?? []);
        $variantKey = hash('sha256', json_encode([
            'sku' => $sku,
            'package_quantity' => $input['package_quantity'] ?? null,
            'package_unit' => $input['package_unit'] ?? null,
            'specifications' => $specifications,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return ProductVariant::query()->firstOrCreate(
            ['product_id' => $product->id, 'variant_key' => $variantKey],
            [
                'public_id' => (string) Str::uuid(),
                'name' => $input['variant_name'] ?? null,
                'sku' => $sku,
                'gtin' => $gtin,
                'specifications' => $specifications,
                'package_quantity' => $input['package_quantity'] ?? null,
                'package_unit' => $input['package_unit'] ?? null,
                'source' => $input['source'] ?? 'manual',
                'source_confidence' => $input['source_confidence'] ?? null,
                'metadata' => ['provenance' => $input['provenance'] ?? []],
            ]
        );
    }

    private function resolveAliasProduct(string $normalizedAlias, string $brand = ''): ?Product
    {
        $aliases = ProductAlias::query()
            ->where('normalized_alias', $normalizedAlias)
            ->with('product')
            ->get();

        if ($aliases->isEmpty()) {
            return null;
        }

        if (filled($brand)) {
            $normalizedBrand = $this->normalize($brand);
            $matches = $aliases->filter(fn (ProductAlias $alias) => $this->normalize((string) $alias->product?->brand) === $normalizedBrand);
            if ($matches->count() === 1) {
                return $matches->first()->product;
            }
        }

        return $aliases->count() === 1 ? $aliases->first()->product : null;
    }

    private function normalizeSpecifications(array $specifications): array
    {
        return collect($specifications)
            ->mapWithKeys(fn ($value, $key) => [Str::snake(trim((string) $key)) => is_string($value) ? trim($value) : $value])
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->sortKeys()
            ->all();
    }

    private function productKey(string $normalizedName, string $brand): string
    {
        return hash('sha256', $this->normalize($brand) . '|' . $normalizedName);
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->value();
    }

    private function digits(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) ($value ?? ''));
        return $digits !== '' ? $digits : null;
    }
}
