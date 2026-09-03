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
    public function __construct(private readonly ApplicationContext $applicationContext) {}

    private function activeEstablishments()
    {
        return Establishment::query()->whereNull('source_establishment_id')->where(function ($q) {
            $q->where('is_cancelled', false)->orWhereNull('is_cancelled');
        });
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['city'=>'nullable|string|max:120','uf'=>'nullable|string|size:2','target_city'=>'nullable|string|max:120','target_uf'=>'nullable|string|size:2','q'=>'nullable|string|max:120','limit'=>'nullable|integer|min:1|max:100']);
        $appId = $this->applicationContext->id();
        $currentCity = trim((string)($data['city'] ?? '')); $currentUf = strtoupper(trim((string)($data['uf'] ?? '')));
        $targetCity = trim((string)($data['target_city'] ?? '')); $targetUf = strtoupper(trim((string)($data['target_uf'] ?? '')));
        $text = trim((string)($data['q'] ?? '')); $limit = (int)($data['limit'] ?? 48);

        $base = $this->activeEstablishments()->where(function ($q) use ($appId) {
            $q->where('app_id', $appId)->orWhereHas('applications', fn($apps) => $apps->whereKey($appId));
        });
        $locations = (clone $base)->whereNotNull('city')->whereNotNull('uf')->where('city','!=','')->where('uf','!=','')->select('city','uf')->distinct()->orderBy('uf')->orderBy('city')->get()->map(fn($r)=>['city'=>$r->city,'uf'=>strtoupper((string)$r->uf),'label'=>$r->city.' - '.strtoupper((string)$r->uf)])->values();
        $query = (clone $base)->when($targetCity !== '', fn($q)=>$q->where('city',$targetCity))->when($targetUf !== '', fn($q)=>$q->where('uf',$targetUf))->when($text !== '', function($q) use($text){$like='%'.$text.'%';$q->where(fn($s)=>$s->where('name','like',$like)->orWhere('fantasy','like',$like)->orWhere('city','like',$like)->orWhere('uf','like',$like)->orWhere('category','like',$like)->orWhere('description','like',$like));})->with(['app:id,name,slug,logo','applications:id,name,slug,logo','files'=>fn($q)=>$q->where('visibility','public')->where('status','active')->orderBy('position')])->withCount(['views as total_views'=>fn($q)=>$q->where('interaction_type','view')]);
        if ($targetCity === '' && $targetUf === '' && $currentCity !== '' && $currentUf !== '') $query->orderByRaw("CASE WHEN LOWER(COALESCE(city, '')) = LOWER(?) AND UPPER(COALESCE(uf, '')) = ? THEN 0 ELSE 1 END ASC",[$currentCity,$currentUf]);
        $establishments = $query->orderByDesc('is_featured')->orderByDesc('updated_at')->limit($limit)->get()->map(function($e) use($appId){$e->setAttribute('catalog_active',true);$e->setAttribute('native_to_application',(int)$e->app_id===$appId);$e->setAttribute('source_app',$e->app?['id'=>$e->app->id,'name'=>$e->app->name,'slug'=>$e->app->slug,'logo'=>$e->app->logo]:null);return $e;})->values();
        $items = Item::query()->where('entity_name','establishment')->where('status',true)->whereIn('entity_id',$establishments->pluck('id'))->with(['files'=>fn($q)=>$q->where('visibility','public')->where('status','active')->orderBy('position'),'establishment:id,app_id,name,fantasy,slug,city,uf'])->withCount(['views as total_views'=>fn($q)=>$q->where('interaction_type','view')])->orderByDesc('is_featured')->orderByDesc('updated_at')->limit($limit)->get();
        return response()->json(['success'=>true,'scope'=>['application_id'=>$appId,'current_city'=>$currentCity?:null,'current_uf'=>$currentUf?:null,'target_city'=>$targetCity?:null,'target_uf'=>$targetUf?:null,'query'=>$text?:null],'locations'=>$locations,'establishments'=>$establishments,'items'=>$items]);
    }

    public function companies(Request $request): JsonResponse
    {
        $appId=$this->applicationContext->id(); $companies=$this->activeEstablishments()->where('user_id',$request->user()->id)->with(['files','app:id,name,slug','applications:id,name,slug'])->latest()->get()->map(function($c) use($appId){$ids=$c->applications->pluck('id')->map(fn($id)=>(int)$id)->values();$native=(int)$c->app_id===$appId;$active=$native||$ids->contains($appId);return ['id'=>$c->id,'name'=>$c->name,'fantasy'=>$c->fantasy,'slug'=>$c->slug,'cnpj'=>$c->cnpj,'phone'=>$c->phone,'email'=>$c->email,'description'=>$c->description,'city'=>$c->city,'uf'=>$c->uf,'app_id'=>$c->app_id,'application_ids'=>$ids,'applications'=>$c->applications,'files'=>$c->files,'source_app'=>$c->app?['id'=>$c->app->id,'name'=>$c->app->name,'slug'=>$c->app->slug]:null,'catalog_active'=>$active,'catalog_establishment_id'=>$active?$c->id:null,'catalog_slug'=>$active?$c->slug:null,'catalog_files'=>$active?$c->files:[],'native_to_application'=>$native];})->values();
        return response()->json(['success'=>true,'message'=>'Empresas da conta listadas com sucesso.','companies'=>$companies]);
    }

    public function activateCompany(Request $request,int $sourceId): JsonResponse
    {
        $user=$request->user();$appId=$this->applicationContext->id();$company=$this->activeEstablishments()->where('user_id',$user->id)->with(['files','applications:id,name,slug'])->findOrFail($sourceId);
        DB::transaction(function() use($company,$appId,$user){$company->applications()->syncWithoutDetaching([$appId=>['is_primary'=>(int)$company->app_id===$appId]]);$existing=$user->applications()->whereKey($appId)->first()?->pivot;$user->applications()->syncWithoutDetaching([$appId=>['status'=>'active','role'=>$existing?->role?:'owner','metadata'=>$existing?->metadata?:json_encode([],JSON_UNESCAPED_UNICODE),'joined_at'=>$existing?->joined_at?:now()]]);});
        return response()->json(['success'=>true,'message'=>'Empresa vinculada ao aplicativo com sucesso.','establishment'=>$company->fresh()->load(['files','applications:id,name,slug'])]);
    }

    public function deactivateCompany(Request $request,int $sourceId): JsonResponse
    {
        $appId=$this->applicationContext->id();$company=$this->activeEstablishments()->where('user_id',$request->user()->id)->findOrFail($sourceId);if((int)$company->app_id===$appId)return response()->json(['success'=>false,'message'=>'A aplicação de origem da empresa não pode ser desvinculada por esta operação.','code'=>'PRIMARY_APPLICATION_CANNOT_BE_DETACHED'],422);$company->applications()->detach($appId);return response()->json(['success'=>true,'message'=>'Empresa desvinculada do aplicativo com sucesso.']);
    }
}
