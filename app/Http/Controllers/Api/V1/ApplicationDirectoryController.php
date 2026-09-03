<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Models\Item;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApplicationDirectoryController extends Controller
{
    public function __construct(private readonly ApplicationContext $applicationContext)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'city' => 'nullable|string|max:120',
            'uf' => 'nullable|string|size:2',
            'target_city' => 'nullable|string|max:120',
            'target_uf' => 'nullable|string|size:2',
            'q' => 'nullable|string|max:120',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $targetAppId = $this->applicationContext->id();
        $currentCity = trim((string) ($data['city'] ?? ''));
        $currentUf = strtoupper(trim((string) ($data['uf'] ?? '')));
        $targetCity = trim((string) ($data['target_city'] ?? ''));
        $targetUf = strtoupper(trim((string) ($data['target_uf'] ?? '')));
        $queryText = trim((string) ($data['q'] ?? ''));
        $limit = (int) ($data['limit'] ?? 48);

        $baseEstablishments = Establishment::query()
            ->whereNull('source_establishment_id')
            ->where(function ($query) {
                $query->where('is_cancelled', false)->orWhereNull('is_cancelled');
            })
            ->where(function ($query) use ($targetAppId) {
                $query->where('app_id', $targetAppId)
                    ->orWhereHas('applications', fn ($apps) => $apps->whereKey($targetAppId));
            });

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
                "CASE WHEN LOWER(COALESCE(city, '')) = LOWER(?) AND UPPER(COALESCE(uf, '')) = ? THEN 0 ELSE 1 END ASC",
                [$currentCity, $currentUf]
            );
        }

        $establishments = $establishmentQuery
            ->orderByDesc('is_featured')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(function (Establishment $establishment) use ($targetAppId) {
                $establishment->setAttribute('catalog_active', true);
                $establishment->setAttribute('native_to_application', (int) $establishment->app_id === $targetAppId);
                $establishment->setAttribute('source_app', $establishment->app ? [
                    'id' => $establishment->app->id,
                    'name' => $establishment->app->name,
                    'slug' => $establishment->app->slug,
                    'logo' => $establishment->app->logo,
                ] : null);
                return $establishment;
            })->values();

        $establishmentIds = $establishments->pluck('id');
        $items = Item::query()
            ->where('entity_name', 'establishment')
            ->where('status', true)
            ->whereIn('entity_id', $establishmentIds)
            ->with([
                'files' => fn ($q) => $q->where('visibility', 'public')->where('status', 'active')->orderBy('position'),
                'establishment:id,app_id,name,fantasy,slug,city,uf',
            ])
            ->withCount(['views as total_views' => fn ($q) => $q->where('interaction_type', 'view')])
            ->orderByDesc('is_featured')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'scope' => [
                'application_id' => $targetAppId,
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

    public function companies(Request $request): JsonResponse
    {
        $user = $request->user();
        $targetAppId = $this->applicationContext->id();

        $companies = Establishment::query()
            ->where('user_id', $user->id)
            ->whereNull('source_establishment_id')
            ->where(function ($query) {
                $query->where('is_cancelled', false)->orWhereNull('is_cancelled');
            })
            ->with(['files', 'app:id,name,slug', 'applications:id,name,slug'])
            ->latest()
            ->get();

        $payload = $companies->map(function (Establishment $company) use ($targetAppId) {
            $linkedApplicationIds = $company->applications
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values();

            $nativeToApplication = (int) $company->app_id === $targetAppId;
            $activeInApplication = $nativeToApplication || $linkedApplicationIds->contains($targetAppId);

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
                'application_ids' => $linkedApplicationIds,
                'applications' => $company->applications,
                'files' => $company->files,
                'source_app' => $company->app ? [
                    'id' => $company->app->id,
                    'name' => $company->app->name,
                    'slug' => $company->app->slug,
                ] : null,
                'catalog_active' => $activeInApplication,
                'catalog_establishment_id' => $activeInApplication ? $company->id : null,
                'catalog_slug' => $activeInApplication ? $company->slug : null,
                'catalog_files' => $activeInApplication ? $company->files : [],
                'native_to_application' => $nativeToApplication,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Empresas da conta listadas com sucesso.',
            'companies' => $payload,
        ]);
    }

    public function activateCompany(Request $request, int $sourceId): JsonResponse
    {
        $user = $request->user();
        $targetAppId = $this->applicationContext->id();

        $company = Establishment::query()
            ->where('user_id', $user->id)
            ->whereNull('source_establishment_id')
            ->where(function ($query) {
                $query->where('is_cancelled', false)->orWhereNull('is_cancelled');
            })
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
            'message' => 'Empresa vinculada ao aplicativo com sucesso.',
            'establishment' => $company->fresh()->load(['files', 'applications:id,name,slug']),
        ]);
    }

    public function deactivateCompany(Request $request, int $sourceId): JsonResponse
    {
        $user = $request->user();
        $targetAppId = $this->applicationContext->id();

        $company = Establishment::query()
            ->where('user_id', $user->id)
            ->whereNull('source_establishment_id')
            ->where(function ($query) {
                $query->where('is_cancelled', false)->orWhereNull('is_cancelled');
            })
            ->findOrFail($sourceId);

        if ((int) $company->app_id === $targetAppId) {
            return response()->json([
                'success' => false,
                'message' => 'A aplicação de origem da empresa não pode ser desvinculada por esta operação.',
                'code' => 'PRIMARY_APPLICATION_CANNOT_BE_DETACHED',
            ], 422);
        }

        $company->applications()->detach($targetAppId);

        return response()->json([
            'success' => true,
            'message' => 'Empresa desvinculada do aplicativo com sucesso.',
        ]);
    }
}
