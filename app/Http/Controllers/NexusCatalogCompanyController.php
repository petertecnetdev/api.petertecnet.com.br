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
        $companies = Establishment::query()->where('user_id', Auth::id())->whereNull('source_establishment_id')
            ->with(['files', 'app:id,name,slug', 'applications:id,name,slug'])->latest()->get();
        $payload = $companies->map(function (Establishment $company) use ($targetAppId) {
            $linkedApplicationIds = $company->applications->pluck('id')->map(fn ($id) => (int) $id);
            $catalogActive = (int) $company->app_id === $targetAppId || $linkedApplicationIds->contains($targetAppId);
            return ['id'=>$company->id,'name'=>$company->name,'fantasy'=>$company->fantasy,'slug'=>$company->slug,'cnpj'=>$company->cnpj,'phone'=>$company->phone,'email'=>$company->email,'description'=>$company->description,'city'=>$company->city,'uf'=>$company->uf,'app_id'=>$company->app_id,'application_ids'=>$linkedApplicationIds->values(),'applications'=>$company->applications,'files'=>$company->files,'source_app'=>$company->app ? ['id'=>$company->app->id,'name'=>$company->app->name,'slug'=>$company->app->slug] : null,'catalog_active'=>$catalogActive,'catalog_establishment_id'=>$catalogActive ? $company->id : null,'catalog_slug'=>$catalogActive ? $company->slug : null,'catalog_files'=>$catalogActive ? $company->files : [],'is_nexus_native'=>(int)$company->app_id === $targetAppId];
        })->values();
        return response()->json(['message'=>'Empresas do ecossistema listadas com sucesso.','companies'=>$payload]);
    }

    public function showCatalog(Request $request, string $identifier)
    {
        $data = $request->validate(['app_id'=>'required|integer|exists:applications,id']);
        $targetAppId = (int) $data['app_id'];
        $company = Establishment::query()->where('is_cancelled', false)->forApplication($targetAppId)
            ->when(is_numeric($identifier), fn($q)=>$q->where('id',(int)$identifier), fn($q)=>$q->where('slug',$identifier))
            ->with(['files'=>fn($q)=>$q->where('visibility','public')->where('status','active')->orderBy('position'),'app:id,name,slug','applications:id,name,slug'])->firstOrFail();
        Interaction::registerView($company, Auth::user());
        $items = Item::query()->where('app_id',$targetAppId)->where('entity_name','establishment')->where('entity_id',$company->id)->where('status',true)
            ->with(['files'=>fn($q)=>$q->where('visibility','public')->where('status','active')->orderBy('position')])
            ->withCount(['views as total_views'=>fn($q)=>$q->where('interaction_type','view')])->orderByDesc('is_featured')->orderByDesc('updated_at')->get();
        $linkedApplicationIds = $company->applications->pluck('id')->map(fn($id)=>(int)$id)->values();
        return response()->json(['success'=>true,'message'=>'Catálogo Nexus carregado com sucesso.','establishment'=>array_merge($company->toArray(),['application_ids'=>$linkedApplicationIds,'catalog_active'=>true,'catalog_establishment_id'=>$company->id,'catalog_slug'=>$company->slug,'is_nexus_native'=>(int)$company->app_id===$targetAppId,'source_app'=>$company->app?['id'=>$company->app->id,'name'=>$company->app->name,'slug'=>$company->app->slug]:null]),'items'=>$items]);
    }

    public function showItem(Request $request, string $identifier)
    {
        $data = $request->validate(['app_id'=>'required|integer|exists:applications,id']);
        $targetAppId = (int) $data['app_id'];

        $itemQuery = Item::query()->where('entity_name','establishment')->where('status',true)
            ->when(is_numeric($identifier), fn($q)=>$q->where('id',(int)$identifier), fn($q)=>$q->where('slug',$identifier));

        // Slugs são únicos por aplicação, não globalmente. Se houver o mesmo slug
        // em mais de um app, preferimos um item Nexus e depois o mais recente.
        if (!is_numeric($identifier)) {
            $itemQuery->orderByRaw('CASE WHEN app_id = ? THEN 0 ELSE 1 END', [$targetAppId])->orderByDesc('updated_at');
        }

        $item = $itemQuery
            ->with(['files'=>fn($q)=>$q->where('visibility','public')->where('status','active')->orderBy('position'),'app:id,name,slug'])
            ->withCount(['views as total_views'=>fn($q)=>$q->where('interaction_type','view')])->firstOrFail();

        // O entity_id é a fonte de verdade do vínculo do item. Não podemos exigir
        // source_establishment_id NULL aqui: itens legados e catálogos vinculados
        // podem apontar para um estabelecimento derivado/clonado.
        $company = Establishment::query()->where('is_cancelled',false)
            ->with(['files'=>fn($q)=>$q->where('visibility','public')->where('status','active')->orderBy('position'),'app:id,name,slug','applications:id,name,slug'])
            ->findOrFail($item->entity_id);

        Interaction::registerView($item, Auth::user());
        $otherItems = Item::query()->where('app_id',$item->app_id)->where('entity_name','establishment')->where('entity_id',$company->id)->where('status',true)->where('id','!=',$item->id)
            ->with(['files'=>fn($q)=>$q->where('visibility','public')->where('status','active')->orderBy('position')])
            ->withCount(['views as total_views'=>fn($q)=>$q->where('interaction_type','view')])->orderByDesc('is_featured')->orderByDesc('updated_at')->limit(8)->get();
        $linkedApplicationIds = $company->applications->pluck('id')->map(fn($id)=>(int)$id)->values();
        $catalogActive = (int)$company->app_id===$targetAppId || $linkedApplicationIds->contains($targetAppId);
        return response()->json(['success'=>true,'message'=>'Item do ecossistema carregado com sucesso na Nexus.','item'=>array_merge($item->toArray(),['source_app'=>$item->app?['id'=>$item->app->id,'name'=>$item->app->name,'slug'=>$item->app->slug]:null]),'establishment'=>array_merge($company->toArray(),['application_ids'=>$linkedApplicationIds,'catalog_active'=>$catalogActive,'is_nexus_native'=>(int)$company->app_id===$targetAppId,'source_app'=>$company->app?['id'=>$company->app->id,'name'=>$company->app->name,'slug'=>$company->app->slug]:null]),'other_items'=>$otherItems]);
    }

    public function activate(Request $request, int $sourceId)
    {
        $data=$request->validate(['app_id'=>'required|integer|exists:applications,id']); $user=Auth::user(); $targetAppId=(int)$data['app_id'];
        $company=Establishment::query()->where('user_id',$user->id)->whereNull('source_establishment_id')->with(['files','applications:id,name,slug'])->findOrFail($sourceId);
        DB::transaction(function() use($company,$targetAppId,$user){$company->applications()->syncWithoutDetaching([$targetAppId=>['is_primary'=>(int)$company->app_id===$targetAppId]]);$existing=$user->applications()->whereKey($targetAppId)->first()?->pivot;$user->applications()->syncWithoutDetaching([$targetAppId=>['status'=>'active','role'=>$existing?->role?:'owner','metadata'=>$existing?->metadata?:json_encode([],JSON_UNESCAPED_UNICODE),'joined_at'=>$existing?->joined_at?:now()]]);});
        return response()->json(['message'=>'Empresa vinculada à Nexus com sucesso.','establishment'=>$company->fresh()->load(['files','applications:id,name,slug'])]);
    }
}
