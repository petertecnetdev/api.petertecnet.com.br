<?php

namespace App\Http\Controllers;

use App\Models\Establishment;
use App\Models\Item;
use Illuminate\Http\Request;

class NexusDiscoveryController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'app_id' => 'required|integer|exists:applications,id',
            'city' => 'nullable|string|max:120',
            'uf' => 'nullable|string|size:2',
            'target_city' => 'nullable|string|max:120',
            'target_uf' => 'nullable|string|size:2',
            'q' => 'nullable|string|max:120',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $nexusAppId = (int) $data['app_id'];
        $currentCity = trim((string) ($data['city'] ?? ''));
        $currentUf = strtoupper(trim((string) ($data['uf'] ?? '')));
        $targetCity = trim((string) ($data['target_city'] ?? ''));
        $targetUf = strtoupper(trim((string) ($data['target_uf'] ?? '')));
        $queryText = trim((string) ($data['q'] ?? ''));
        $limit = (int) ($data['limit'] ?? 48);

        $baseEstablishments = Establishment::query()
            ->where('is_cancelled', false)
            ->whereNull('source_establishment_id');

        $locations = (clone $baseEstablishments)
            ->whereNotNull('city')->whereNotNull('uf')
            ->where('city', '!=', '')->where('uf', '!=', '')
            ->select('city', 'uf')->distinct()->orderBy('uf')->orderBy('city')
            ->get()->map(fn ($row) => [
                'city' => $row->city,
                'uf' => strtoupper((string) $row->uf),
                'label' => $row->city . ' - ' . strtoupper((string) $row->uf),
            ])->values();

        $establishmentQuery = (clone $baseEstablishments)
            ->when($targetCity !== '', fn ($q) => $q->where('city', $targetCity))
            ->when($targetUf !== '', fn ($q) => $q->where('uf', $targetUf))
            ->when($queryText !== '', function ($q) use ($queryText) {
                $like = '%' . $queryText . '%';
                $q->where(function ($search) use ($like) {
                    $search->where('name', 'like', $like)
                        ->orWhere('fantasy', 'like', $like)
                        ->orWhere('city', 'like', $like)
                        ->orWhere('uf', 'like', $like)
                        ->orWhere('category', 'like', $like)
                        ->orWhere('description', 'like', $like);
                });
            })
            ->with([
                'app:id,name,slug,logo',
                'applications:id,name,slug,logo',
                'files' => fn ($q) => $q->where('visibility', 'public')->where('status', 'active')->orderBy('position'),
            ])
            ->withCount(['views as total_views' => fn ($q) => $q->where('interaction_type', 'view')]);

        if ($targetCity === '' && $targetUf === '' && $currentCity !== '' && $currentUf !== '') {
            $establishmentQuery->orderByRaw(
                'CASE WHEN LOWER(COALESCE(city, \'\')) = LOWER(?) AND UPPER(COALESCE(uf, \'\')) = ? THEN 0 ELSE 1 END ASC',
                [$currentCity, $currentUf]
            );
        }

        $establishments = $establishmentQuery->orderByDesc('is_featured')->orderByDesc('updated_at')
            ->limit($limit)->get()->map(function (Establishment $establishment) use ($nexusAppId) {
                $linkedApplicationIds = $establishment->applications->pluck('id')->map(fn ($id) => (int) $id);
                $catalogActive = (int) $establishment->app_id === $nexusAppId || $linkedApplicationIds->contains($nexusAppId);
                $establishment->setAttribute('catalog_active', $catalogActive);
                $establishment->setAttribute('is_nexus_native', (int) $establishment->app_id === $nexusAppId);
                $establishment->setAttribute('source_app', $establishment->app ? [
                    'id' => $establishment->app->id, 'name' => $establishment->app->name,
                    'slug' => $establishment->app->slug, 'logo' => $establishment->app->logo,
                ] : null);
                return $establishment;
            })->values();

        $establishmentIds = $establishments->pluck('id');
        $items = Item::query()->where('entity_name', 'establishment')->where('status', true)
            ->whereIn('entity_id', $establishmentIds)
            ->with(['files' => fn ($q) => $q->where('visibility', 'public')->where('status', 'active')->orderBy('position'),
                'establishment:id,app_id,name,fantasy,slug,city,uf'])
            ->withCount(['views as total_views' => fn ($q) => $q->where('interaction_type', 'view')])
            ->orderByDesc('is_featured')->orderByDesc('updated_at')->limit($limit)->get();

        return response()->json(['success' => true, 'scope' => [
            'nexus_app_id' => $nexusAppId, 'current_city' => $currentCity ?: null, 'current_uf' => $currentUf ?: null,
            'target_city' => $targetCity ?: null, 'target_uf' => $targetUf ?: null, 'query' => $queryText ?: null,
        ], 'locations' => $locations, 'establishments' => $establishments, 'items' => $items]);
    }

    public function search(Request $request)
    {
        $data = $request->validate([
            'app_id' => 'required|integer|exists:applications,id',
            'q' => 'required|string|min:2|max:120',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);
        $term = trim($data['q']);
        $like = '%' . $term . '%';
        $limit = (int) ($data['limit'] ?? 8);

        $companies = Establishment::query()
            ->where('is_cancelled', false)->whereNull('source_establishment_id')
            ->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)->orWhere('fantasy', 'like', $like)
                    ->orWhere('category', 'like', $like)->orWhere('description', 'like', $like)
                    ->orWhere('city', 'like', $like)->orWhere('uf', 'like', $like);
            })
            ->select('id', 'app_id', 'name', 'fantasy', 'slug', 'category', 'city', 'uf')
            ->orderByDesc('is_featured')->orderByDesc('updated_at')->limit($limit)->get();

        $items = Item::query()->where('status', true)->where('entity_name', 'establishment')
            ->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)->orWhere('description', 'like', $like)
                    ->orWhere('category', 'like', $like)->orWhere('subcategory', 'like', $like)
                    ->orWhere('brand', 'like', $like)->orWhere('sku', 'like', $like);
            })
            ->whereHas('establishment', fn ($q) => $q->where('is_cancelled', false)->whereNull('source_establishment_id'))
            ->with('establishment:id,name,fantasy,slug,city,uf')
            ->select('id', 'entity_id', 'app_id', 'name', 'slug', 'type', 'category', 'price')
            ->orderByDesc('is_featured')->orderByDesc('updated_at')->limit($limit)->get();

        return response()->json([
            'success' => true,
            'query' => $term,
            'companies' => $companies,
            'items' => $items,
            'total' => $companies->count() + $items->count(),
        ]);
    }
}
