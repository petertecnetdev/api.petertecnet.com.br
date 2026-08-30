<?php

namespace App\Http\Controllers;

use App\Models\Establishment;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NexusCatalogCompanyController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'app_id' => 'required|integer|exists:applications,id',
        ]);

        $userId = Auth::id();
        $targetAppId = (int) $data['app_id'];

        $companies = Establishment::query()
            ->where('user_id', $userId)
            ->whereNull('source_establishment_id')
            ->with(['files', 'app'])
            ->latest()
            ->get();

        $catalogs = Establishment::query()
            ->where('user_id', $userId)
            ->where('app_id', $targetAppId)
            ->whereNotNull('source_establishment_id')
            ->with('files')
            ->get()
            ->keyBy('source_establishment_id');

        $payload = $companies->map(function (Establishment $company) use ($catalogs, $targetAppId) {
            $nativeCatalog = (int) $company->app_id === $targetAppId;
            $linkedCatalog = $catalogs->get($company->id);
            $catalog = $nativeCatalog ? $company : $linkedCatalog;

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
                'files' => $company->files,
                'source_app' => $company->app ? [
                    'id' => $company->app->id,
                    'name' => $company->app->name ?? null,
                    'slug' => $company->app->slug ?? null,
                ] : null,
                'catalog_active' => (bool) $catalog,
                'catalog_establishment_id' => $catalog?->id,
                'catalog_slug' => $catalog?->slug,
                'catalog_files' => $catalog?->files ?? [],
                'is_nexus_native' => $nativeCatalog,
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

        $source = Establishment::query()
            ->where('user_id', $user->id)
            ->whereNull('source_establishment_id')
            ->with('files')
            ->findOrFail($sourceId);

        if ((int) $source->app_id === $targetAppId) {
            return response()->json([
                'message' => 'Esta empresa já possui catálogo nesta aplicação.',
                'establishment' => $source,
            ]);
        }

        $existing = Establishment::query()
            ->where('user_id', $user->id)
            ->where('app_id', $targetAppId)
            ->where('source_establishment_id', $source->id)
            ->with('files')
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Catálogo já ativado.',
                'establishment' => $existing,
            ]);
        }

        $catalog = DB::transaction(function () use ($source, $targetAppId, $user) {
            $catalog = $source->replicate(['slug', 'created_at', 'updated_at']);
            $catalog->app_id = $targetAppId;
            $catalog->source_establishment_id = $source->id;
            $catalog->slug = $this->uniqueSlug($source->fantasy ?: $source->name);
            $catalog->user_id = $user->id;
            $catalog->created_by = $user->id;
            $catalog->updated_by = $user->id;
            $catalog->is_cancelled = false;
            $catalog->save();

            foreach ($source->files as $file) {
                $copy = $file->replicate(['uuid', 'created_at', 'updated_at']);
                $copy->app_id = $targetAppId;
                $copy->entity_name = 'establishment';
                $copy->entity_id = $catalog->id;
                $copy->fileable_type = $file->fileable_type;
                $copy->fileable_id = $catalog->id;
                $copy->created_by = $user->id;
                $copy->updated_by = $user->id;
                $copy->save();
            }

            $user->applications()->syncWithoutDetaching([
                $targetAppId => ['status' => 'active', 'joined_at' => now()],
            ]);

            return $catalog;
        });

        return response()->json([
            'message' => 'Catálogo Nexus ativado com sucesso.',
            'establishment' => $catalog->fresh()->load('files'),
        ], 201);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: Str::random(12);
        $slug = $base;
        $i = 2;

        while (Establishment::query()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
