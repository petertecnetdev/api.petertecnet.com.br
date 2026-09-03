<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApplicationDirectoryController extends Controller
{
    public function __construct(private readonly ApplicationContext $applicationContext)
    {
    }

    public function companies(Request $request): JsonResponse
    {
        $user = $request->user();
        $targetApplication = $this->applicationContext->get();
        $targetAppId = (int) $targetApplication->id;

        $companies = Establishment::query()
            ->where('user_id', $user->id)
            ->whereNull('source_establishment_id')
            ->where('is_cancelled', false)
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
        $targetApplication = $this->applicationContext->get();
        $targetAppId = (int) $targetApplication->id;

        $company = Establishment::query()
            ->where('user_id', $user->id)
            ->whereNull('source_establishment_id')
            ->where('is_cancelled', false)
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
        $targetApplication = $this->applicationContext->get();
        $targetAppId = (int) $targetApplication->id;

        $company = Establishment::query()
            ->where('user_id', $user->id)
            ->whereNull('source_establishment_id')
            ->where('is_cancelled', false)
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
