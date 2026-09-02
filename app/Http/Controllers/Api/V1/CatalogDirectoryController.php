<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Models\Interaction;
use App\Models\Item;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CatalogDirectoryController extends Controller
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function companies(Request $request): JsonResponse
    {
        $targetAppId = $this->applicationId($request);
        $companies = Establishment::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('source_establishment_id')
            ->with(['files', 'app:id,name,slug', 'applications:id,name,slug'])
            ->latest()->get();

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
                'source_app' => $this->sourceApplication($company),
                'catalog_active' => $catalogActive,
                'catalog_establishment_id' => $catalogActive ? $company->id : null,
                'catalog_slug' => $catalogActive ? $company->slug : null,
                'catalog_files' => $catalogActive ? $company->files : [],
                'native_to_application' => (int) $company->app_id === $targetAppId,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Empresas do ecossistema listadas com sucesso.',
            'target_application_id' => $targetAppId,
            'companies' => $payload,
        ]);
    }

    public function catalog(Request $request, string $identifier): JsonResponse
    {
        $targetAppId = $this->applicationId($request);
        $company = $this->publicCompany($targetAppId, $identifier);
        Interaction::registerView($company, Auth::user());

        $items = Item::query()
            ->where('entity_name', 'establishment')
            ->where('entity_id', $company->id)
            ->where('status', true)
            ->with([
                'files' => fn ($query) => $query->where('visibility', 'public')->where('status', 'active')->orderBy('position'),
                'app:id,name,slug',
                'establishment:id,app_id,name,fantasy,slug,city,uf',
            ])
            ->withCount(['views as total_views' => fn ($query) => $query->where('interaction_type', 'view')])
            ->orderByRaw('CASE WHEN app_id = ? THEN 0 ELSE 1 END', [$targetAppId])
            ->orderByDesc('is_featured')->orderByDesc('updated_at')->get();

        $linkedApplicationIds = $company->applications->pluck('id')->map(fn ($id) => (int) $id)->values();

        return response()->json([
            'success' => true,
            'message' => 'Catálogo carregado com sucesso.',
            'target_application_id' => $targetAppId,
            'establishment' => array_merge($company->toArray(), [
                'application_ids' => $linkedApplicationIds,
                'catalog_active' => true,
                'catalog_establishment_id' => $company->id,
                'catalog_slug' => $company->slug,
                'native_to_application' => (int) $company->app_id === $targetAppId,
                'source_app' => $this->sourceApplication($company),
            ]),
            'items' => $items,
        ]);
    }

    public function item(Request $request, string $identifier): JsonResponse
    {
        $targetAppId = $this->applicationId($request);
        $item = Item::query()
            ->where('entity_name', 'establishment')
            ->where('status', true)
            ->whereHas('establishment', fn ($query) => $query
                ->forApplication($targetAppId)
                ->where('is_cancelled', false))
            ->when(
                is_numeric($identifier),
                fn ($query) => $query->where('id', (int) $identifier),
                fn ($query) => $query->where('slug', $identifier)
            )
            ->with([
                'files' => fn ($query) => $query->where('visibility', 'public')->where('status', 'active')->orderBy('position'),
                'app:id,name,slug',
            ])
            ->withCount(['views as total_views' => fn ($query) => $query->where('interaction_type', 'view')])
            ->orderByRaw('CASE WHEN app_id = ? THEN 0 ELSE 1 END', [$targetAppId])
            ->orderByDesc('updated_at')->firstOrFail();

        $company = Establishment::query()
            ->forApplication($targetAppId)
            ->where('is_cancelled', false)
            ->with([
                'files' => fn ($query) => $query->where('visibility', 'public')->where('status', 'active')->orderBy('position'),
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
                'files' => fn ($query) => $query->where('visibility', 'public')->where('status', 'active')->orderBy('position'),
                'app:id,name,slug',
            ])
            ->withCount(['views as total_views' => fn ($query) => $query->where('interaction_type', 'view')])
            ->orderByDesc('is_featured')->orderByDesc('updated_at')->limit(8)->get();

        $linkedApplicationIds = $company->applications->pluck('id')->map(fn ($id) => (int) $id)->values();

        return response()->json([
            'success' => true,
            'message' => 'Item carregado com sucesso.',
            'target_application_id' => $targetAppId,
            'item' => array_merge($item->toArray(), [
                'source_app' => $item->app ? [
                    'id' => $item->app->id,
                    'name' => $item->app->name,
                    'slug' => $item->app->slug,
                ] : null,
            ]),
            'establishment' => array_merge($company->toArray(), [
                'application_ids' => $linkedApplicationIds,
                'catalog_active' => true,
                'native_to_application' => (int) $company->app_id === $targetAppId,
                'source_app' => $this->sourceApplication($company),
            ]),
            'other_items' => $otherItems,
        ]);
    }

    public function activate(Request $request, int $sourceId): JsonResponse
    {
        $targetAppId = $this->applicationId($request);
        $user = $request->user();
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
            'success' => true,
            'message' => 'Empresa vinculada ao catálogo do aplicativo com sucesso.',
            'target_application_id' => $targetAppId,
            'establishment' => $company->fresh()->load(['files', 'applications:id,name,slug']),
        ]);
    }

    public function deactivate(Request $request, int $sourceId): JsonResponse
    {
        $targetAppId = $this->applicationId($request);
        $company = Establishment::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('source_establishment_id')
            ->with('applications:id,name,slug')
            ->findOrFail($sourceId);

        if ((int) $company->app_id === $targetAppId) {
            return response()->json([
                'success' => false,
                'message' => 'A empresa é nativa deste aplicativo e não pode ser apenas desvinculada. Use a exclusão da própria empresa.',
                'code' => 'NATIVE_ESTABLISHMENT_CANNOT_DETACH',
            ], 422);
        }

        $company->applications()->detach($targetAppId);

        return response()->json([
            'success' => true,
            'message' => 'Catálogo desvinculado do aplicativo sem alterar a empresa de origem.',
            'target_application_id' => $targetAppId,
            'establishment_id' => $company->id,
        ]);
    }

    private function publicCompany(int $targetAppId, string $identifier): Establishment
    {
        return Establishment::query()
            ->forApplication($targetAppId)
            ->where('is_cancelled', false)
            ->when(
                is_numeric($identifier),
                fn ($query) => $query->where('id', (int) $identifier),
                fn ($query) => $query->where('slug', $identifier)
            )
            ->with([
                'files' => fn ($query) => $query->where('visibility', 'public')->where('status', 'active')->orderBy('position'),
                'app:id,name,slug',
                'applications:id,name,slug',
            ])
            ->firstOrFail();
    }

    private function applicationId(Request $request): int
    {
        if ($this->context->has()) return $this->context->id();
        return (int) $request->validate(['app_id' => 'required|integer|exists:applications,id'])['app_id'];
    }

    private function sourceApplication(Establishment $company): ?array
    {
        if (! $company->app) return null;
        return ['id' => $company->app->id, 'name' => $company->app->name, 'slug' => $company->app->slug];
    }
}
