<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CatalogImport;
use App\Models\Establishment;
use App\Models\Item;
use App\Models\ProductVariant;
use App\Services\Catalog\CatalogImportService;
use App\Services\Catalog\CatalogProductService;
use App\Services\Catalog\CatalogQualityService;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CatalogIntelligenceController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CatalogProductService $products,
        private readonly CatalogImportService $imports,
        private readonly CatalogQualityService $quality,
    ) {
    }

    public function schema(Request $request): JsonResponse
    {
        $category = $request->query('category');
        $name = $request->query('name');
        $profile = $this->quality->profileFor($category, $name);

        return response()->json(['success' => true, 'data' => [
            'profile' => $profile,
            'rules' => config("catalog.specification_profiles.{$profile}", []),
            'units' => config('catalog.units', []),
            'quality_threshold' => (int) config('catalog.quality.publish_threshold', 70),
        ]]);
    }

    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gtin' => ['nullable', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:120'],
        ]);

        $variant = $this->products->resolveByIdentity(
            $data['gtin'] ?? null,
            $data['name'] ?? null,
            $data['brand'] ?? null,
        );

        return response()->json(['success' => true, 'data' => $variant?->load('product')]);
    }

    public function showItem(Request $request, int $item): JsonResponse
    {
        $model = $this->ownedItem($request, $item);
        $variant = $model->product_variant_id
            ? ProductVariant::query()->with('product')->find($model->product_variant_id)
            : null;

        $metadata = is_string($model->catalog_metadata)
            ? json_decode($model->catalog_metadata, true)
            : ($model->catalog_metadata ?? []);

        return response()->json(['success' => true, 'data' => [
            'item_id' => $model->id,
            'variant' => $variant,
            'quality' => data_get($metadata, 'quality'),
            'provenance' => data_get($metadata, 'provenance', []),
        ]]);
    }

    public function enrich(Request $request, int $item): JsonResponse
    {
        $model = $this->ownedItem($request, $item);
        $data = $request->validate($this->enrichmentRules());

        $result = $this->products->attach($model, array_merge($data, [
            'name' => $model->name,
            'brand' => $data['brand'] ?? $model->brand,
            'category' => $data['category'] ?? $model->category,
            'subcategory' => $data['subcategory'] ?? $model->subcategory,
            'sku' => $data['sku'] ?? $model->sku,
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Produto normalizado e qualidade do catálogo recalculada.',
            'data' => $this->serializeAttached($result),
        ]);
    }

    public function health(Request $request, int $establishment): JsonResponse
    {
        $owned = $this->ownedEstablishment($request, $establishment);
        $query = Item::query()
            ->where('entity_name', 'establishment')
            ->where('entity_id', $owned->id)
            ->where('type', 'product');

        $total = (clone $query)->count();
        $average = round((float) ((clone $query)->avg('quality_score') ?? 0), 1);
        $byStatus = (clone $query)
            ->selectRaw('quality_status, COUNT(*) as total')
            ->groupBy('quality_status')
            ->pluck('total', 'quality_status');

        $needsReview = (clone $query)
            ->whereIn('quality_status', ['legacy', 'review'])
            ->orderBy('quality_score')
            ->limit(100)
            ->get(['id', 'name', 'brand', 'category', 'price', 'quality_score', 'quality_status', 'catalog_metadata']);

        $needsReview->transform(function (Item $item) {
            $metadata = is_string($item->catalog_metadata)
                ? json_decode($item->catalog_metadata, true)
                : ($item->catalog_metadata ?? []);

            return [
                'id' => $item->id,
                'name' => $item->name,
                'brand' => $item->brand,
                'category' => $item->category,
                'price' => (float) $item->price,
                'quality_score' => (int) $item->quality_score,
                'quality_status' => $item->quality_status,
                'issues' => data_get($metadata, 'quality.issues', []),
            ];
        });

        return response()->json(['success' => true, 'data' => [
            'total_products' => $total,
            'average_score' => $average,
            'excellent' => (int) ($byStatus['excellent'] ?? 0),
            'ready' => (int) ($byStatus['ready'] ?? 0),
            'review' => (int) (($byStatus['review'] ?? 0) + ($byStatus['legacy'] ?? 0)),
            'needs_review' => $needsReview,
        ]]);
    }

    public function stageImport(Request $request): JsonResponse
    {
        $data = $request->validate([
            'establishment_id' => ['required', 'integer'],
            'source_type' => ['nullable', Rule::in(['manual', 'csv', 'spreadsheet', 'supplier', 'erp', 'photo', 'ai'])],
            'filename' => ['nullable', 'string', 'max:255'],
            'rows' => ['required', 'array', 'min:1', 'max:10000'],
            'rows.*' => ['required', 'array'],
        ]);

        $establishment = $this->ownedEstablishment($request, (int) $data['establishment_id']);
        $import = $this->imports->stage(
            $establishment,
            $request->user(),
            $data['rows'],
            $data['source_type'] ?? 'manual',
            $data['filename'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Catálogo analisado. Revise somente as linhas sinalizadas.',
            'data' => $import,
        ], 201);
    }

    public function showImport(Request $request, string $publicId): JsonResponse
    {
        $import = $this->ownedImport($request, $publicId);

        return response()->json(['success' => true, 'data' => $import->load('rows.matchedVariant.product')]);
    }

    public function publishImport(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate(['include_review' => ['nullable', 'boolean']]);
        $import = $this->ownedImport($request, $publicId);
        $published = $this->imports->publish($import, $request->user(), (bool) ($data['include_review'] ?? false));

        return response()->json([
            'success' => true,
            'message' => 'Linhas aprovadas publicadas no catálogo.',
            'data' => $published,
        ]);
    }

    private function enrichmentRules(): array
    {
        return [
            'canonical_name' => ['nullable', 'string', 'max:255'],
            'variant_name' => ['nullable', 'string', 'max:255'],
            'gtin' => ['nullable', 'string', 'max:32'],
            'sku' => ['nullable', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:120'],
            'subcategory' => ['nullable', 'string', 'max:120'],
            'sale_unit' => ['nullable', 'string', 'max:24'],
            'package_quantity' => ['nullable', 'numeric', 'min:0'],
            'package_unit' => ['nullable', 'string', Rule::in(config('catalog.units', []))],
            'specifications' => ['nullable', 'array'],
            'specifications.*' => ['nullable'],
            'source' => ['nullable', Rule::in(['manual', 'csv', 'spreadsheet', 'supplier', 'erp', 'photo', 'ai'])],
            'source_confidence' => ['nullable', 'numeric', 'between:0,100'],
            'provenance' => ['nullable', 'array'],
            'alias' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function ownedEstablishment(Request $request, int $id): Establishment
    {
        return Establishment::query()
            ->whereKey($id)
            ->where('user_id', $request->user()->id)
            ->where('is_cancelled', false)
            ->where(function ($query) {
                $query->where('app_id', $this->context->id())
                    ->orWhereHas('applications', fn ($apps) => $apps->where('applications.id', $this->context->id()));
            })
            ->firstOrFail();
    }

    private function ownedItem(Request $request, int $id): Item
    {
        $item = Item::query()->whereKey($id)->where('entity_name', 'establishment')->firstOrFail();
        $this->ownedEstablishment($request, (int) $item->entity_id);

        return $item;
    }

    private function ownedImport(Request $request, string $publicId): CatalogImport
    {
        $import = CatalogImport::query()
            ->where('public_id', $publicId)
            ->where('application_id', $this->context->id())
            ->firstOrFail();
        $this->ownedEstablishment($request, (int) $import->establishment_id);

        return $import;
    }

    private function serializeAttached(array $result): array
    {
        /** @var ProductVariant $variant */
        $variant = $result['variant'];

        return [
            'item' => $result['item'],
            'product' => $result['product'],
            'variant' => $variant->load('product'),
            'quality' => $result['quality'],
        ];
    }
}
