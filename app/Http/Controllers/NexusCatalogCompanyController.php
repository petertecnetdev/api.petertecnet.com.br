<?php

namespace App\Http\Controllers;

use App\Models\Establishment;
use App\Models\Interaction;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class NexusCatalogCompanyController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['app_id' => 'required|integer|exists:applications,id']);
        $targetAppId = (int) $data['app_id'];

        $companies = Establishment::query()
            ->where('user_id', Auth::id())
            ->whereNull('source_establishment_id')
            ->with(['files', 'app:id,name,slug', 'applications:id,name,slug'])
            ->latest()
            ->get();

        $payload = $companies->map(function (Establishment $company) use ($targetAppId) {
            $linkedApplicationIds = $company->applications->pluck('id')->map(fn ($id) => (int) $id);
            $catalogActive = (int) $company->app_id === $targetAppId || $linkedApplicationIds->contains($targetAppId);

            return [
                'id' => $company->id,
                'name' => $company->name,
                'fantasy' => $company->fantasy,
                'slug' => $company->slug,
                'cnpj' => $company->cnpj,
                'phone' => $company->phone,
                'email' => $company->email,
                'description' => $company->description,
                'city' => $company->city,
                'uf' => $company->uf,
                'app_id' => $company->app_id,
                'application_ids' => $linkedApplicationIds->values(),
                'applications' => $company->applications,
                'files' => $company->files,
                'source_app' => $company->app ? [
                    'id' => $company->app->id,
                    'name' => $company->app->name,
                    'slug' => $company->app->slug,
                ] : null,
                'catalog_active' => $catalogActive,
                'catalog_establishment_id' => $catalogActive ? $company->id : null,
                'catalog_slug' => $catalogActive ? $company->slug : null,
                'catalog_files' => $catalogActive ? $company->files : [],
                'is_nexus_native' => (int) $company->app_id === $targetAppId,
            ];
        })->values();

        return response()->json([
            'message' => 'Empresas do ecossistema listadas com sucesso.',
            'companies' => $payload,
        ]);
    }

    public function showCatalog(Request $request, string $identifier)
    {
        $data = $request->validate([
            'app_id' => 'required|integer|exists:applications,id',
        ]);
        $targetAppId = (int) $data['app_id'];

        $companyQuery = Establishment::query()
            ->where('is_cancelled', false)
            ->when(
                is_numeric($identifier),
                fn ($query) => $query->where('id', (int) $identifier),
                fn ($query) => $query->where('slug', $identifier)
            );

        if (!is_numeric($identifier)) {
            $companyQuery
                ->orderByRaw('CASE WHEN app_id = ? THEN 0 ELSE 1 END', [$targetAppId])
                ->orderByDesc('updated_at');
        }

        $company = $companyQuery
            ->with([
                'files' => fn ($query) => $query
                    ->where('visibility', 'public')
                    ->where('status', 'active')
                    ->orderBy('position'),
                'app:id,name,slug',
                'applications:id,name,slug',
            ])
            ->firstOrFail();

        Interaction::registerView($company, Auth::user());

        // A Nexus é a vitrine transversal do ecossistema. Se a empresa aparece
        // na descoberta pública, seu catálogo também precisa abrir. Por isso os
        // itens são obtidos pelo vínculo real com a empresa (entity_id), sem
        // exigir que tenham sido criados originalmente com app_id da Nexus.
        $items = Item::query()
            ->where('entity_name', 'establishment')
            ->where('entity_id', $company->id)
            ->where('status', true)
            ->with([
                'files' => fn ($query) => $query
                    ->where('visibility', 'public')
                    ->where('status', 'active')
                    ->orderBy('position'),
                'app:id,name,slug',
                'establishment:id,app_id,name,fantasy,slug,city,uf',
            ])
            ->withCount(['views as total_views' => fn ($query) => $query->where('interaction_type', 'view')])
            ->orderByRaw('CASE WHEN app_id = ? THEN 0 ELSE 1 END', [$targetAppId])
            ->orderByDesc('is_featured')
            ->orderByDesc('updated_at')
            ->get();

        $linkedApplicationIds = $company->applications
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();
        $catalogActive = (int) $company->app_id === $targetAppId || $linkedApplicationIds->contains($targetAppId);

        return response()->json([
            'success' => true,
            'message' => 'Catálogo do ecossistema carregado com sucesso na Nexus.',
            'establishment' => array_merge($company->toArray(), [
                'application_ids' => $linkedApplicationIds,
                'catalog_active' => $catalogActive,
                'catalog_establishment_id' => $company->id,
                'catalog_slug' => $company->slug,
                'is_nexus_native' => (int) $company->app_id === $targetAppId,
                'source_app' => $company->app ? [
                    'id' => $company->app->id,
                    'name' => $company->app->name,
                    'slug' => $company->app->slug,
                ] : null,
            ]),
            'items' => $items,
        ]);
    }

    public function showItem(Request $request, string $identifier)
    {
        $data = $request->validate([
            'app_id' => 'required|integer|exists:applications,id',
        ]);
        $targetAppId = (int) $data['app_id'];

        $itemQuery = Item::query()
            ->where('entity_name', 'establishment')
            ->where('status', true)
            ->when(
                is_numeric($identifier),
                fn ($query) => $query->where('id', (int) $identifier),
                fn ($query) => $query->where('slug', $identifier)
            );

        if (!is_numeric($identifier)) {
            $itemQuery
                ->orderByRaw('CASE WHEN app_id = ? THEN 0 ELSE 1 END', [$targetAppId])
                ->orderByDesc('updated_at');
        }

        $item = $itemQuery
            ->with([
                'files' => fn ($query) => $query
                    ->where('visibility', 'public')
                    ->where('status', 'active')
                    ->orderBy('position'),
                'app:id,name,slug',
            ])
            ->withCount(['views as total_views' => fn ($query) => $query->where('interaction_type', 'view')])
            ->firstOrFail();

        $company = Establishment::query()
            ->where('is_cancelled', false)
            ->with([
                'files' => fn ($query) => $query
                    ->where('visibility', 'public')
                    ->where('status', 'active')
                    ->orderBy('position'),
                'app:id,name,slug',
                'applications:id,name,slug',
            ])
            ->findOrFail($item->entity_id);

        Interaction::registerView($item, Auth::user());

        $otherItems = Item::query()
            ->where('entity_name', 'establishment')
            ->where('entity_id', $company->id)
            ->where('status', true)
            ->where('id', '!=', $item->id)
            ->with([
                'files' => fn ($query) => $query
                    ->where('visibility', 'public')
                    ->where('status', 'active')
                    ->orderBy('position'),
                'app:id,name,slug',
            ])
            ->withCount(['views as total_views' => fn ($query) => $query->where('interaction_type', 'view')])
            ->orderByRaw('CASE WHEN app_id = ? THEN 0 ELSE 1 END', [$item->app_id])
            ->orderByDesc('is_featured')
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get();

        $linkedApplicationIds = $company->applications
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();
        $catalogActive = (int) $company->app_id === $targetAppId || $linkedApplicationIds->contains($targetAppId);

        return response()->json([
            'success' => true,
            'message' => 'Item do ecossistema carregado com sucesso na Nexus.',
            'item' => array_merge($item->toArray(), [
                'source_app' => $item->app ? [
                    'id' => $item->app->id,
                    'name' => $item->app->name,
                    'slug' => $item->app->slug,
                ] : null,
            ]),
            'establishment' => array_merge($company->toArray(), [
                'application_ids' => $linkedApplicationIds,
                'catalog_active' => $catalogActive,
                'is_nexus_native' => (int) $company->app_id === $targetAppId,
                'source_app' => $company->app ? [
                    'id' => $company->app->id,
                    'name' => $company->app->name,
                    'slug' => $company->app->slug,
                ] : null,
            ]),
            'other_items' => $otherItems,
        ]);
    }

    public function activate(Request $request, int $sourceId)
    {
        $data = $request->validate([
            'app_id' => 'required|integer|exists:applications,id',
        ]);
        $user = Auth::user();
        $targetAppId = (int) $data['app_id'];

        $company = Establishment::query()
            ->where('user_id', $user->id)
            ->whereNull('source_establishment_id')
            ->with(['files', 'applications:id,name,slug'])
            ->findOrFail($sourceId);

        DB::transaction(function () use ($company, $targetAppId, $user) {
            $company->applications()->syncWithoutDetaching([
                $targetAppId => ['is_primary' => (int) $company->app_id === $targetAppId],
            ]);

            $existing = $user->applications()->whereKey($targetAppId)->first()?->pivot;
            $user->applications()->syncWithoutDetaching([
                $targetAppId => [
                    'status' => 'active',
                    'role' => $existing?->role ?: 'owner',
                    'metadata' => $existing?->metadata ?: json_encode([], JSON_UNESCAPED_UNICODE),
                    'joined_at' => $existing?->joined_at ?: now(),
                ],
            ]);
        });

        return response()->json([
            'message' => 'Empresa vinculada à Nexus com sucesso.',
            'establishment' => $company->fresh()->load(['files', 'applications:id,name,slug']),
        ]);
    }
}
