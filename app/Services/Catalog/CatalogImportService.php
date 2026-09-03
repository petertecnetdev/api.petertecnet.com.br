<?php

namespace App\Services\Catalog;

use App\Models\CatalogImport;
use App\Models\CatalogImportRow;
use App\Models\Establishment;
use App\Models\Item;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogImportService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CatalogProductService $products,
        private readonly CatalogQualityService $quality,
    ) {
    }

    public function stage(Establishment $establishment, User $user, array $rows, string $sourceType = 'manual', ?string $filename = null): CatalogImport
    {
        return DB::transaction(function () use ($establishment, $user, $rows, $sourceType, $filename) {
            $import = CatalogImport::query()->create([
                'public_id' => (string) Str::uuid(),
                'application_id' => $this->context->id(),
                'establishment_id' => $establishment->id,
                'user_id' => $user->id,
                'source_type' => $sourceType,
                'filename' => $filename,
                'status' => 'review',
                'total_rows' => count($rows),
                'metadata' => ['application_slug' => $this->context->slug()],
            ]);

            $ready = 0;
            $review = 0;

            foreach (array_values($rows) as $index => $raw) {
                $normalized = $this->normalizeRow(is_array($raw) ? $raw : []);
                $quality = $this->quality->evaluateRow($normalized);
                $match = $this->products->resolveByIdentity(
                    $normalized['gtin'] ?? null,
                    $normalized['name'] ?? null,
                    $normalized['brand'] ?? null,
                );

                $status = $quality['publish_ready'] ? 'ready' : 'review';
                $status === 'ready' ? $ready++ : $review++;

                CatalogImportRow::query()->create([
                    'catalog_import_id' => $import->id,
                    'row_number' => $index + 1,
                    'raw_data' => $raw,
                    'normalized_data' => $normalized,
                    'matched_product_variant_id' => $match?->id,
                    'confidence' => $match ? 100 : $this->identityConfidence($normalized),
                    'status' => $status,
                    'issues' => $quality['issues'],
                ]);
            }

            $import->forceFill([
                'ready_rows' => $ready,
                'review_rows' => $review,
                'status' => $review > 0 ? 'review' : 'ready',
            ])->save();

            return $import->fresh('rows.matchedVariant.product');
        }, 3);
    }

    public function publish(CatalogImport $import, User $user, bool $includeReview = false): CatalogImport
    {
        abort_unless((int) $import->application_id === $this->context->id(), 404);

        $establishment = Establishment::query()->findOrFail($import->establishment_id);

        DB::transaction(function () use ($import, $establishment, $user, $includeReview) {
            $rows = $import->rows()
                ->whereIn('status', $includeReview ? ['ready', 'review'] : ['ready'])
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                $data = $row->normalized_data ?? [];
                if (! filled($data['name'] ?? null) || ! is_numeric($data['price'] ?? null)) {
                    continue;
                }

                $item = Item::query()->create([
                    'app_id' => $establishment->app_id ?: $this->context->id(),
                    'entity_name' => 'establishment',
                    'entity_id' => $establishment->id,
                    'user_id' => $user->id,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                    'name' => $data['name'],
                    'type' => 'product',
                    'sku' => $data['sku'] ?? null,
                    'description' => $data['description'] ?? null,
                    'price' => (float) $data['price'],
                    'stock' => isset($data['stock']) && is_numeric($data['stock']) ? (int) $data['stock'] : null,
                    'status' => true,
                    'category' => $data['category'] ?? null,
                    'subcategory' => $data['subcategory'] ?? null,
                    'brand' => $data['brand'] ?? null,
                ]);

                $attached = $this->products->attach($item, array_merge($data, [
                    'canonical_name' => $data['name'],
                    'source' => $import->source_type,
                    'source_confidence' => $row->confidence,
                    'provenance' => [
                        'catalog_import' => $import->public_id,
                        'row' => $row->row_number,
                    ],
                ]));

                $row->forceFill([
                    'matched_product_variant_id' => $attached['variant']->id,
                    'status' => 'imported',
                ])->save();
            }

            $import->forceFill([
                'imported_rows' => $import->rows()->where('status', 'imported')->count(),
                'status' => $import->rows()->whereIn('status', ['ready', 'review'])->exists() ? 'partial' : 'completed',
            ])->save();
        }, 3);

        return $import->fresh('rows.matchedVariant.product');
    }

    public function normalizeRow(array $row): array
    {
        $lookup = [];
        foreach ($row as $key => $value) {
            $lookup[$this->normalizeKey((string) $key)] = is_string($value) ? trim($value) : $value;
        }

        $specifications = is_array($row['specifications'] ?? null) ? $row['specifications'] : [];
        foreach (['length', 'width', 'height', 'diameter', 'thickness', 'color', 'finish', 'material', 'application'] as $field) {
            $value = $this->first($lookup, [$field, $this->ptKey($field)]);
            if (filled($value)) {
                $specifications[$field] = $value;
            }
        }

        return array_filter([
            'name' => $this->first($lookup, ['name', 'nome', 'produto', 'descricao produto']),
            'description' => $this->first($lookup, ['description', 'descricao', 'detalhes']),
            'price' => $this->money($this->first($lookup, ['price', 'preco', 'valor'])),
            'stock' => $this->number($this->first($lookup, ['stock', 'estoque', 'quantidade estoque'])),
            'sku' => $this->first($lookup, ['sku', 'codigo', 'codigo interno', 'referencia']),
            'gtin' => preg_replace('/\D+/', '', (string) $this->first($lookup, ['gtin', 'ean', 'codigo barras', 'codigo de barras'])),
            'brand' => $this->first($lookup, ['brand', 'marca', 'fabricante']),
            'category' => $this->first($lookup, ['category', 'categoria']),
            'subcategory' => $this->first($lookup, ['subcategory', 'subcategoria']),
            'sale_unit' => $this->first($lookup, ['sale unit', 'unidade venda', 'unidade de venda', 'unidade']),
            'package_quantity' => $this->number($this->first($lookup, ['package quantity', 'volume', 'peso', 'conteudo', 'conteudo embalagem'])),
            'package_unit' => $this->first($lookup, ['package unit', 'unidade embalagem', 'unidade volume', 'unidade peso']),
            'specifications' => $specifications,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function identityConfidence(array $row): float
    {
        $score = 0;
        if (filled($row['name'] ?? null)) $score += 35;
        if (filled($row['brand'] ?? null)) $score += 20;
        if (filled($row['gtin'] ?? null)) $score += 35;
        if (filled($row['sku'] ?? null)) $score += 10;
        return min(100, $score);
    }

    private function first(array $lookup, array $keys): mixed
    {
        foreach ($keys as $key) {
            $normalized = $this->normalizeKey($key);
            if (array_key_exists($normalized, $lookup) && filled($lookup[$normalized])) {
                return $lookup[$normalized];
            }
        }
        return null;
    }

    private function normalizeKey(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->value();
    }

    private function ptKey(string $field): string
    {
        return [
            'length' => 'comprimento', 'width' => 'largura', 'height' => 'altura', 'diameter' => 'diametro',
            'thickness' => 'espessura', 'color' => 'cor', 'finish' => 'acabamento', 'material' => 'material',
            'application' => 'aplicacao',
        ][$field] ?? $field;
    }

    private function money(mixed $value): ?float
    {
        if ($value === null || $value === '') return null;
        if (is_numeric($value)) return (float) $value;
        $normalized = preg_replace('/[^0-9,.-]/', '', (string) $value);
        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = str_replace('.', '', $normalized);
        }
        $normalized = str_replace(',', '.', $normalized);
        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function number(mixed $value): int|float|null
    {
        if ($value === null || $value === '') return null;
        $normalized = str_replace(',', '.', preg_replace('/[^0-9,.-]/', '', (string) $value));
        return is_numeric($normalized) ? (float) $normalized : null;
    }
}
