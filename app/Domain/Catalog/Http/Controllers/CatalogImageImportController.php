<?php

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Services\CatalogImageAnalysisService;
use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Models\Item;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CatalogImageImportController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CatalogImageAnalysisService $analyzer,
    ) {}

    public function capabilities(Request $request, int $establishment): JsonResponse
    {
        $this->ownedEstablishment($request, $establishment);
        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => trim((string) config('services.openai.api_key')) !== '',
                'max_images' => 6,
                'max_image_mb' => 5,
                'accepted_types' => ['image/jpeg', 'image/png', 'image/webp'],
                'review_required' => true,
            ],
        ]);
    }

    public function preview(Request $request, int $establishment): JsonResponse
    {
        $data = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:6'],
            'images.*' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'locale' => ['nullable', 'string', 'max:20'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);
        $owned = $this->ownedEstablishment($request, $establishment);

        try {
            $analysis = $this->analyzer->analyze(
                $request->file('images', []),
                $data['locale'] ?? 'pt-BR',
                strtoupper($data['currency'] ?? 'BRL'),
            );
        } catch (RuntimeException $exception) {
            $status = str_contains($exception->getMessage(), 'não está configurada') ? 503 : 502;
            return response()->json(['success' => false, 'message' => $exception->getMessage()], $status);
        }

        $existing = Item::query()
            ->where('entity_name', 'establishment')
            ->where('entity_id', $owned->id)
            ->get(['id', 'name', 'price', 'category', 'status']);

        $items = collect($analysis['items'])->map(function (array $item) use ($existing) {
            $duplicate = $this->findDuplicate($item['name'], $existing->all());
            return array_merge($item, [
                'duplicate' => $duplicate,
                'recommended_action' => $duplicate ? 'skip' : 'create',
            ]);
        })->values();

        return response()->json([
            'success' => true,
            'message' => sprintf('%d item(ns) identificado(s) para revisão.', $items->count()),
            'data' => [
                'establishment' => ['id' => $owned->id, 'name' => $owned->name],
                'items' => $items,
                'warnings' => $analysis['warnings'],
                'detected_count' => $items->count(),
                'duplicate_count' => $items->whereNotNull('duplicate')->count(),
            ],
        ]);
    }

    public function store(Request $request, int $establishment): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.action' => ['required', Rule::in(['create', 'update', 'skip'])],
            'items.*.existing_item_id' => ['nullable', 'integer', 'min:1'],
            'items.*.name' => ['nullable', 'string', 'max:255'],
            'items.*.type' => ['nullable', 'string', 'max:80'],
            'items.*.sku' => ['nullable', 'string', 'max:100'],
            'items.*.description' => ['nullable', 'string', 'max:50000'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.category' => ['nullable', 'string', 'max:120'],
            'items.*.subcategory' => ['nullable', 'string', 'max:120'],
            'items.*.brand' => ['nullable', 'string', 'max:120'],
        ]);
        $owned = $this->ownedEstablishment($request, $establishment);
        $this->validateReviewedItems($data['items']);
        $userId = (int) $request->user()->id;
        $appId = (int) ($owned->app_id ?: $this->context->id());

        $result = DB::transaction(function () use ($data, $owned, $userId, $appId) {
            $created = []; $updated = []; $skipped = 0;
            foreach ($data['items'] as $row) {
                if ($row['action'] === 'skip') { $skipped++; continue; }
                $attributes = $this->itemAttributes($row);
                if ($row['action'] === 'update') {
                    $item = Item::query()
                        ->whereKey((int) $row['existing_item_id'])
                        ->where('entity_name', 'establishment')
                        ->where('entity_id', $owned->id)
                        ->firstOrFail();
                    $item->fill(array_merge($attributes, ['updated_by' => $userId]))->save();
                    $updated[] = $item->fresh();
                    continue;
                }
                $created[] = Item::create(array_merge($attributes, [
                    'app_id' => $appId,
                    'entity_name' => 'establishment',
                    'entity_id' => $owned->id,
                    'user_id' => $userId,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                    'status' => true,
                    'is_featured' => false,
                ]));
            }
            return compact('created', 'updated', 'skipped');
        });

        return response()->json([
            'success' => true,
            'message' => 'Importação de itens concluída.',
            'data' => [
                'created_count' => count($result['created']),
                'updated_count' => count($result['updated']),
                'skipped_count' => $result['skipped'],
                'created' => $result['created'],
                'updated' => $result['updated'],
            ],
        ], 201);
    }

    private function ownedEstablishment(Request $request, int $id): Establishment
    {
        return Establishment::query()
            ->whereKey($id)
            ->forApplication($this->context->id())
            ->where('user_id', $request->user()->id)
            ->where('is_cancelled', false)
            ->firstOrFail();
    }

    private function validateReviewedItems(array $items): void
    {
        $errors = [];
        foreach ($items as $index => $row) {
            if (($row['action'] ?? 'skip') === 'skip') continue;
            if (trim((string) ($row['name'] ?? '')) === '') {
                $errors["items.{$index}.name"][] = 'Informe o nome antes de cadastrar este item.';
            }
            if (! array_key_exists('price', $row) || $row['price'] === null || $row['price'] === '') {
                $errors["items.{$index}.price"][] = 'Revise o preço antes de cadastrar este item.';
            }
            if (($row['action'] ?? null) === 'update' && empty($row['existing_item_id'])) {
                $errors["items.{$index}.existing_item_id"][] = 'Selecione o item existente que deve ser atualizado.';
            }
        }
        if ($errors !== []) throw ValidationException::withMessages($errors);
    }

    private function itemAttributes(array $row): array
    {
        $attributes = Arr::only($row, ['name', 'type', 'sku', 'description', 'price', 'category', 'subcategory', 'brand']);
        $attributes['name'] = trim((string) $attributes['name']);
        $attributes['type'] = trim((string) ($attributes['type'] ?? 'product')) ?: 'product';
        $attributes['price'] = round((float) $attributes['price'], 2);
        foreach (['sku', 'description', 'category', 'subcategory', 'brand'] as $key) {
            if (array_key_exists($key, $attributes) && is_string($attributes[$key])) {
                $attributes[$key] = trim($attributes[$key]) ?: null;
            }
        }
        return $attributes;
    }

    /** @param array<int, Item> $existing */
    private function findDuplicate(string $name, array $existing): ?array
    {
        $needle = $this->normalizeName($name);
        if ($needle === '') return null;
        foreach ($existing as $item) {
            if ($this->normalizeName((string) $item->name) !== $needle) continue;
            return [
                'id' => $item->id,
                'name' => $item->name,
                'price' => $item->price !== null ? (float) $item->price : null,
                'category' => $item->category,
                'status' => (bool) $item->status,
                'match_score' => 1.0,
            ];
        }
        return null;
    }

    private function normalizeName(string $name): string
    {
        $ascii = Str::ascii(Str::lower(trim($name)));
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?: '';
        return preg_replace('/\s+/', ' ', trim($normalized)) ?: '';
    }
}
