<?php

namespace App\Domain\DeveloperPlatform\Http\Controllers;

use App\Domain\DeveloperPlatform\Services\SandboxDataService;
use App\Domain\DeveloperPlatform\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicResourceController extends Controller
{
    public function __construct(private readonly SandboxDataService $sandbox)
    {
    }

    public function establishments(Request $request): JsonResponse
    {
        if ($this->isSandbox($request)) {
            return $this->sandbox->establishments($request);
        }

        $query = Establishment::query()
            ->where('is_published', true)
            ->where('is_approved', true)
            ->where('is_cancelled', false);

        $this->applySearch($query, $request, ['name', 'fantasy', 'description', 'category', 'city']);

        foreach (['city', 'uf', 'type', 'category'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->string($filter)->toString());
            }
        }

        $this->applySort($query, $request, ['name', 'fantasy', 'city', 'created_at'], '-created_at');

        $paginator = $query->paginate($this->perPage($request))->withQueryString();

        return ApiResponse::paginated($paginator, fn (Establishment $establishment) => $this->serializeEstablishment($establishment));
    }

    public function establishment(Request $request, string $slug): JsonResponse
    {
        if ($this->isSandbox($request)) {
            return $this->sandbox->establishment($request, $slug);
        }

        $establishment = Establishment::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->where('is_approved', true)
            ->where('is_cancelled', false)
            ->first();

        if (!$establishment) {
            return ApiResponse::error($request, 'resource_not_found', 'Estabelecimento não encontrado.', 404);
        }

        return ApiResponse::data($this->serializeEstablishment($establishment));
    }

    public function items(Request $request): JsonResponse
    {
        if ($this->isSandbox($request)) {
            return $this->sandbox->items($request);
        }

        $query = $this->publicItemsQuery();

        $this->applySearch($query, $request, ['name', 'description', 'category', 'subcategory', 'brand']);

        foreach (['type', 'category', 'brand'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->string($filter)->toString());
            }
        }

        if ($request->filled('establishment_id')) {
            $query->where('entity_id', (int) $request->input('establishment_id'));
        }

        $this->applySort($query, $request, ['name', 'price', 'created_at'], '-created_at');

        $paginator = $query->paginate($this->perPage($request))->withQueryString();

        return ApiResponse::paginated($paginator, fn (Item $item) => $this->serializeItem($item));
    }

    public function item(Request $request, string $slug): JsonResponse
    {
        if ($this->isSandbox($request)) {
            return $this->sandbox->item($request, $slug);
        }

        $item = $this->publicItemsQuery()->where('slug', $slug)->first();

        if (!$item) {
            return ApiResponse::error($request, 'resource_not_found', 'Item não encontrado.', 404);
        }

        return ApiResponse::data($this->serializeItem($item));
    }

    private function publicItemsQuery(): Builder
    {
        return Item::query()
            ->with('files')
            ->active()
            ->where('entity_name', 'establishment')
            ->whereHas('establishment', function (Builder $query) {
                $query->where('is_published', true)
                    ->where('is_approved', true)
                    ->where('is_cancelled', false);
            });
    }

    private function isSandbox(Request $request): bool
    {
        return $request->is('api/sandbox/v1*');
    }

    private function perPage(Request $request): int
    {
        return min(
            max((int) $request->input('per_page', 20), 1),
            (int) config('developer.max_per_page', 100)
        );
    }

    private function applySearch(Builder $query, Request $request, array $columns): void
    {
        if (!$request->filled('q')) {
            return;
        }

        $term = trim($request->string('q')->toString());
        $query->where(function (Builder $search) use ($columns, $term) {
            foreach ($columns as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $search->{$method}($column, 'like', '%' . $term . '%');
            }
        });
    }

    private function applySort(Builder $query, Request $request, array $allowed, string $default): void
    {
        $sort = $request->string('sort', $default)->toString();
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        if (!in_array($column, $allowed, true)) {
            $column = ltrim($default, '-');
            $direction = str_starts_with($default, '-') ? 'desc' : 'asc';
        }

        $query->orderBy($column, $direction);
    }

    private function serializeEstablishment(Establishment $establishment): array
    {
        return [
            'id' => $establishment->id,
            'slug' => $establishment->slug,
            'name' => $establishment->fantasy ?: $establishment->name,
            'legal_name' => $establishment->name,
            'type' => $establishment->type,
            'category' => $establishment->category,
            'description' => $establishment->description,
            'city' => $establishment->city,
            'state' => $establishment->uf,
            'segments' => $establishment->segments ?? [],
            'featured' => (bool) $establishment->is_featured,
            'links' => array_filter([
                'website' => $establishment->website_url,
                'instagram' => $establishment->instagram_url,
                'facebook' => $establishment->facebook_url,
                'youtube' => $establishment->youtube_url,
            ]),
            'created_at' => $establishment->created_at?->toISOString(),
            'updated_at' => $establishment->updated_at?->toISOString(),
        ];
    }

    private function serializeItem(Item $item): array
    {
        return [
            'id' => $item->id,
            'slug' => $item->slug,
            'name' => $item->name,
            'type' => $item->type,
            'description' => $item->description,
            'price' => $item->price !== null ? (string) $item->price : null,
            'category' => $item->category,
            'subcategory' => $item->subcategory,
            'brand' => $item->brand,
            'tags' => $item->tags ?? [],
            'featured' => (bool) $item->is_featured,
            'image_url' => $item->image_url,
            'owner' => [
                'type' => $item->entity_name,
                'id' => $item->entity_id,
            ],
            'availability' => [
                'starts_at' => $item->availability_start?->toISOString(),
                'ends_at' => $item->availability_end?->toISOString(),
            ],
            'created_at' => $item->created_at?->toISOString(),
            'updated_at' => $item->updated_at?->toISOString(),
        ];
    }
}
