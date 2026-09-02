<?php

namespace App\Domain\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Models\Interaction;
use App\Models\Item;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class EcosystemCatalogController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function companies(Request $request)
    {
        $appId = $this->context->id();
        $companies = Establishment::query()->where('user_id', Auth::id())->whereNull('source_establishment_id')
            ->with(['files', 'app:id,name,slug', 'applications:id,name,slug'])->latest()->get();

        $payload = $companies->map(function (Establishment $company) use ($appId) {
            $applicationIds = $company->applications->pluck('id')->map(fn ($id) => (int) $id);
            $active = (int) $company->app_id === $appId || $applicationIds->contains($appId);
            return [
                'id' => $company->id, 'name' => $company->name, 'fantasy' => $company->fantasy, 'slug' => $company->slug,
                'cnpj' => $company->cnpj, 'phone' => $company->phone, 'email' => $company->email, 'description' => $company->description,
                'city' => $company->city, 'uf' => $company->uf, 'app_id' => $company->app_id,
                'application_ids' => $applicationIds->values(), 'applications' => $company->applications, 'files' => $company->files,
                'source_app' => $company->app ? ['id' => $company->app->id, 'name' => $company->app->name, 'slug' => $company->app->slug] : null,
                'catalog_active' => $active, 'catalog_establishment_id' => $active ? $company->id : null,
                'catalog_slug' => $active ? $company->slug : null, 'catalog_files' => $active ? $company->files : [],
                'is_context_native' => (int) $company->app_id === $appId,
            ];
        })->values();

        return response()->json(['message' => 'Empresas do ecossistema listadas com sucesso.', 'companies' => $payload]);
    }

    public function showCatalog(Request $request, string $identifier)
    {
        $appId = $this->context->id();
        $query = Establishment::query()->where('is_cancelled', false)
            ->when(is_numeric($identifier), fn ($q) => $q->where('id', (int) $identifier), fn ($q) => $q->where('slug', $identifier));
        if (! is_numeric($identifier)) $query->orderByRaw('CASE WHEN app_id = ? THEN 0 ELSE 1 END', [$appId])->orderByDesc('updated_at');

        $company = $query->with(['files' => fn ($q) => $q->where('visibility','public')->where('status','active')->orderBy('position'), 'app:id,name,slug', 'applications:id,name,slug'])->firstOrFail();
        Interaction::registerView($company, Auth::user());

        $items = Item::query()->where('entity_name','establishment')->where('entity_id',$company->id)->where('status',true)
            ->with(['files' => fn ($q) => $q->where('visibility','public')->where('status','active')->orderBy('position'), 'app:id,name,slug', 'establishment:id,app_id,name,fantasy,slug,city,uf'])
            ->withCount(['views as total_views' => fn ($q) => $q->where('interaction_type','view')])
            ->orderByRaw('CASE WHEN app_id = ? THEN 0 ELSE 1 END', [$appId])->orderByDesc('is_featured')->orderByDesc('updated_at')->get();

        $applicationIds = $company->applications->pluck('id')->map(fn ($id) => (int) $id)->values();
        $active = (int) $company->app_id === $appId || $applicationIds->contains($appId);

        return response()->json(['success' => true, 'message' => 'Catálogo carregado com sucesso.', 'establishment' => array_merge($company->toArray(), [
            'application_ids' => $applicationIds, 'catalog_active' => $active, 'catalog_establishment_id' => $company->id,
            'catalog_slug' => $company->slug, 'is_context_native' => (int) $company->app_id === $appId,
        ]), 'items' => $items]);
    }

    public function showItem(Request $request, string $identifier)
    {
        $appId = $this->context->id();
        $query = Item::query()->where('entity_name','establishment')->where('status',true)
            ->when(is_numeric($identifier), fn ($q) => $q->where('id',(int)$identifier), fn ($q) => $q->where('slug',$identifier));
        if (! is_numeric($identifier)) $query->orderByRaw('CASE WHEN app_id = ? THEN 0 ELSE 1 END', [$appId])->orderByDesc('updated_at');

        $item = $query->with(['files' => fn ($q) => $q->where('visibility','public')->where('status','active')->orderBy('position'), 'app:id,name,slug'])
            ->withCount(['views as total_views' => fn ($q) => $q->where('interaction_type','view')])->firstOrFail();
        $company = Establishment::query()->where('is_cancelled',false)->with(['files','app:id,name,slug','applications:id,name,slug'])->findOrFail($item->entity_id);
        Interaction::registerView($item, Auth::user());

        $otherItems = Item::query()->where('entity_name','establishment')->where('entity_id',$company->id)->where('status',true)->where('id','!=',$item->id)
            ->with(['files' => fn ($q) => $q->where('visibility','public')->where('status','active')->orderBy('position'), 'app:id,name,slug'])
            ->withCount(['views as total_views' => fn ($q) => $q->where('interaction_type','view')])->orderByDesc('is_featured')->orderByDesc('updated_at')->limit(8)->get();

        $applicationIds = $company->applications->pluck('id')->map(fn ($id) => (int) $id)->values();
        return response()->json(['success'=>true,'message'=>'Item carregado com sucesso.','item'=>$item,'establishment'=>array_merge($company->toArray(),[
            'application_ids'=>$applicationIds,'catalog_active'=>(int)$company->app_id === $appId || $applicationIds->contains($appId),'is_context_native'=>(int)$company->app_id === $appId,
        ]),'other_items'=>$otherItems]);
    }

    public function activate(Request $request, int $sourceId)
    {
        $appId = $this->context->id();
        $user = Auth::user();
        $company = Establishment::query()->where('user_id',$user->id)->whereNull('source_establishment_id')->with(['files','applications:id,name,slug'])->findOrFail($sourceId);

        DB::transaction(function () use ($company, $appId, $user) {
            $company->applications()->syncWithoutDetaching([$appId => ['is_primary' => (int)$company->app_id === $appId]]);
            $existing = $user->applications()->whereKey($appId)->first()?->pivot;
            $user->applications()->syncWithoutDetaching([$appId => [
                'status'=>'active','role'=>$existing?->role ?: 'owner','metadata'=>$existing?->metadata ?: json_encode([], JSON_UNESCAPED_UNICODE),'joined_at'=>$existing?->joined_at ?: now(),
            ]]);
        });

        return response()->json(['message'=>'Empresa vinculada ao contexto da aplicação com sucesso.','establishment'=>$company->fresh()->load(['files','applications:id,name,slug'])]);
    }
}
