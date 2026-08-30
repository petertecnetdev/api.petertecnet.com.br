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

        $appId = (int) $data['app_id'];
        $currentCity = trim((string) ($data['city'] ?? ''));
        $currentUf = strtoupper(trim((string) ($data['uf'] ?? '')));
        $targetCity = trim((string) ($data['target_city'] ?? ''));
        $targetUf = strtoupper(trim((string) ($data['target_uf'] ?? '')));
        $queryText = trim((string) ($data['q'] ?? ''));
        $limit = (int) ($data['limit'] ?? 48);

        // A descoberta da Nexus não deve ficar presa à cidade do visitante.
        // Mantemos somente catálogos pertencentes à Nexus e não cancelados.
        // A localização atual é usada apenas para ordenação/prioridade.
        $baseEstablishments = Establishment::query()
            ->where('app_id', $appId)
            ->where('is_cancelled', false);

        $locations = (clone $baseEstablishments)
            ->whereNotNull('city')
            ->whereNotNull('uf')
            ->where('city', '!=', '')
            ->where('uf', '!=', '')
            ->select('city', 'uf')
            ->distinct()
            ->orderBy('uf')
            ->orderBy('city')
            ->get()
            ->map(fn ($row) => [
                'city' => $row->city,
                'uf' => strtoupper((string) $row->uf),
                'label' => $row->city . ' - ' . strtoupper((string) $row->uf),
            ])
            ->values();

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
            ->with(['files' => fn ($q) => $q->where('visibility', 'public')->where('status', 'active')->orderBy('position')])
            ->withCount(['views as total_views' => fn ($q) => $q->where('interaction_type', 'view')]);

        // Sem filtro manual de cidade, os catálogos de fora da região aparecem
        // primeiro, mas os locais também permanecem disponíveis na exploração.
        if ($targetCity === '' && $targetUf === '' && $currentCity !== '' && $currentUf !== '') {
            $establishmentQuery->orderByRaw(
                'CASE WHEN LOWER(COALESCE(city, \'\')) = LOWER(?) AND UPPER(COALESCE(uf, \'\')) = ? THEN 1 ELSE 0 END ASC',
                [$currentCity, $currentUf]
            );
        }

        $establishments = $establishmentQuery
            ->orderByDesc('is_featured')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $establishmentIds = $establishments->pluck('id');

        $items = Item::query()
            ->where('app_id', $appId)
            ->where('entity_name', 'establishment')
            ->where('status', true)
            ->whereIn('entity_id', $establishmentIds)
            ->with([
                'files' => fn ($q) => $q->where('visibility', 'public')->where('status', 'active')->orderBy('position'),
                'establishment:id,name,fantasy,slug,city,uf',
            ])
            ->withCount(['views as total_views' => fn ($q) => $q->where('interaction_type', 'view')])
            ->orderByDesc('is_featured')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'scope' => [
                'current_city' => $currentCity ?: null,
                'current_uf' => $currentUf ?: null,
                'target_city' => $targetCity ?: null,
                'target_uf' => $targetUf ?: null,
                'query' => $queryText ?: null,
            ],
            'locations' => $locations,
            'establishments' => $establishments,
            'items' => $items,
        ]);
    }
}
