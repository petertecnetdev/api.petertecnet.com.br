<?php

namespace App\Http\Controllers;

use App\Models\Establishment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class NexusCatalogCompanyController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'app_id' => 'required|integer|exists:applications,id',
        ]);

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
