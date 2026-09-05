<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Support\CatalogPublicPayload;
use App\Models\Establishment;
use App\Models\Item;
use App\Support\ApplicationContext;
use Illuminate\Database\Eloquent\Builder;

final class CatalogDiscoveryService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CatalogPublicPayload $publicPayload,
    ) {}

    public function index(array $data): array
    {
        $appId = $this->context->id();
        $currentCity = trim((string) ($data['city'] ?? ''));
        $currentUf = strtoupper(trim((string) ($data['uf'] ?? '')));
        $targetCity = trim((string) ($data['target_city'] ?? ''));
        $targetUf = strtoupper(trim((string) ($data['target_uf'] ?? '')));
        $queryText = trim((string) ($data['q'] ?? ''));
        $limit = (int) ($data['limit'] ?? 48);

        $base = $this->publicEstablishmentsQuery()->whereNull('source_establishment_id');
        $locations = (clone $base)
            ->whereNotNull('city')->whereNotNull('uf')
            ->where('city', '!=', '')->where('uf', '!=', '')
            ->select('city', 'uf')->distinct()->orderBy('uf')->orderBy('city')->get()
            ->map(fn ($row) => [
                'city' => $row->city,
                'uf' => strtoupper((string) $row->uf),
                'label' => $row->city.' - '.strtoupper((string) $row->uf),
            ])->values();

        $query = (clone $base)
            ->when($targetCity !== '', fn ($q) => $q->where('city', $targetCity))
            ->when($targetUf !== '', fn ($q) => $q->where('uf', $targetUf))
            ->when($queryText !== '', function ($q) use ($queryText) {
                $like = '%'.$queryText.'%';
                $q->where(fn ($search) => $search
                    ->where('name', 'like', $like)
                    ->orWhere('fantasy', 'like', $like)
                    ->orWhere('city', 'like', $like)
                    ->orWhere('uf', 'like', $like)
                    ->orWhere('category', 'like', $like)
                    ->orWhere('description', 'like', $like));
            })
            ->with([
                'app:id,name,slug,logo',
                'applications:id,name,slug,logo',
                'files' => fn ($q) => $q->where('visibility', 'public')->where('status', 'active')->orderBy('position'),
            ])
            ->withCount(['views as total_views' => fn ($q) => $q->where('interaction_type', 'view')]);

        if ($targetCity === '' && $targetUf === '' && $currentCity !== '' && $currentUf !== '') {
            $query->orderByRaw(
                "CASE WHEN LOWER(COALESCE(city, '')) = LOWER(?) AND UPPER(COALESCE(uf, '')) = ? THEN 0 ELSE 1 END ASC",
                [$currentCity, $currentUf]
            );
        }

        $establishments = $query
            ->orderByDesc('is_featured')->orderByDesc('updated_at')->limit($limit)->get()
            ->map(function (Establishment $establishment) use ($appId) {
                $applicationIds = $establishment->applications->pluck('id')->map(fn ($id) => (int) $id);
                $establishment->setAttribute('catalog_active', (int) $establishment->app_id === $appId || $applicationIds->contains($appId));
                $establishment->setAttribute('is_context_native', (int) $establishment->app_id === $appId);
                return $this->publicPayload->establishment($establishment);
            })->values();

        $items = Item::query()
            ->where('entity_name', 'establishment')->where('status', true)
            ->whereIn('entity_id', $establishments->pluck('id'))
            ->with([
                'files' => fn ($q) => $q->where('visibility', 'public')->where('status', 'active')->orderBy('position'),
                'establishment:id,app_id,name,fantasy,slug,city,uf',
            ])
            ->withCount(['views as total_views' => fn ($q) => $q->where('interaction_type', 'view')])
            ->orderByDesc('is_featured')->orderByDesc('updated_at')->limit($limit)->get()
            ->map(fn (Item $item) => $this->publicPayload->item($item))->values();

        return [
            'success' => true,
            'scope' => [
                'application_id' => $appId,
                'current_city' => $currentCity ?: null,
                'current_uf' => $currentUf ?: null,
                'target_city' => $targetCity ?: null,
                'target_uf' => $targetUf ?: null,
                'query' => $queryText ?: null,
            ],
            'locations' => $locations,
            'establishments' => $establishments,
            'items' => $items,
        ];
    }

    public function search(array $data): array
    {
        $term = trim($data['q']);
        $like = '%'.$term.'%';
        $limit = (int) ($data['limit'] ?? 8);

        $companies = $this->publicEstablishmentsQuery()
            ->whereNull('source_establishment_id')
            ->where(fn ($q) => $q
                ->where('name', 'like', $like)->orWhere('fantasy', 'like', $like)
                ->orWhere('category', 'like', $like)->orWhere('description', 'like', $like)
                ->orWhere('city', 'like', $like)->orWhere('uf', 'like', $like))
            ->select('id', 'app_id', 'name', 'fantasy', 'slug', 'category', 'city', 'uf')
            ->orderByDesc('is_featured')->orderByDesc('updated_at')->limit($limit)->get()
            ->map(fn (Establishment $establishment) => $this->publicPayload->establishment($establishment))->values();

        $items = Item::query()
            ->where('status', true)->where('entity_name', 'establishment')
            ->where(fn ($q) => $q
                ->where('name', 'like', $like)->orWhere('description', 'like', $like)
                ->orWhere('category', 'like', $like)->orWhere('subcategory', 'like', $like)
                ->orWhere('brand', 'like', $like)->orWhere('sku', 'like', $like))
            ->whereHas('establishment', function (Builder $query) {
                $this->applyPublicVisibility($query);
                $query->forApplication($this->context->id())->whereNull('source_establishment_id');
            })
            ->with('establishment:id,name,fantasy,slug,city,uf')
            ->select('id', 'entity_id', 'app_id', 'name', 'slug', 'type', 'category', 'price')
            ->orderByDesc('is_featured')->orderByDesc('updated_at')->limit($limit)->get()
            ->map(fn (Item $item) => $this->publicPayload->item($item))->values();

        return [
            'success' => true,
            'query' => $term,
            'companies' => $companies,
            'items' => $items,
            'total' => $companies->count() + $items->count(),
        ];
    }

    private function publicEstablishmentsQuery(): Builder
    {
        return $this->applyPublicVisibility(Establishment::query()->forApplication($this->context->id()));
    }

    private function applyPublicVisibility(Builder $query): Builder
    {
        $query->where('is_cancelled', false)->where('is_published', true);
        if (in_array($this->context->slug(), config('platform.approval_required_apps', []), true)) {
            $query->where('is_approved', true);
        }
        return $query;
    }
}
