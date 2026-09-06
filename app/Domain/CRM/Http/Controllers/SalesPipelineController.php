<?php

namespace App\Domain\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class SalesPipelineController extends Controller
{
    public function __construct(private readonly ApplicationContext $applicationContext) {}

    private function context(Request $request): array
    {
        $appId = $this->applicationContext->id();
        $data = $request->validate(['establishment_id' => 'required|integer|exists:establishments,id']);
        $establishment = Establishment::query()->forApplication($appId)->whereKey((int)$data['establishment_id'])->where('user_id', Auth::id())->firstOrFail();
        return ['app_id'=>$appId,'establishment_id'=>(int)$establishment->id,'owner_user_id'=>(int)Auth::id()];
    }

    private function tenant(array $context): array
    {
        return ['app_id'=>$context['app_id'],'establishment_id'=>$context['establishment_id']];
    }

    private function assertContact(int $contactId, array $scope): void
    {
        abort_unless(DB::table('crm_contacts')->where('id',$contactId)->where($scope)->exists(), 422, 'Contato não pertence a este estabelecimento.');
    }

    private function acquisitionPerformance(array $context): array
    {
        $tenant = $this->tenant($context);
        $sourceSql = "COALESCE(NULLIF(TRIM(c.source), ''), 'unknown')";

        $opportunityRows = DB::table('crm_opportunities as o')
            ->join('crm_contacts as c', 'c.id', '=', 'o.contact_id')
            ->where('o.app_id', $context['app_id'])
            ->where('o.establishment_id', $context['establishment_id'])
            ->where('o.owner_user_id', $context['owner_user_id'])
            ->groupBy(DB::raw($sourceSql))
            ->selectRaw($sourceSql . ' as source')
            ->selectRaw('COUNT(*) as opportunities')
            ->selectRaw("SUM(CASE WHEN o.stage = 'won' THEN 1 ELSE 0 END) as won_opportunities")
            ->selectRaw("COALESCE(SUM(CASE WHEN o.stage = 'won' THEN o.value ELSE 0 END), 0) as won_value")
            ->get()
            ->keyBy('source');

        $revenueRows = DB::table('crm_charges as ch')
            ->join('crm_proposals as p', 'p.id', '=', 'ch.proposal_id')
            ->join('crm_opportunities as o', 'o.id', '=', 'p.opportunity_id')
            ->join('crm_contacts as c', 'c.id', '=', 'o.contact_id')
            ->where('ch.app_id', $tenant['app_id'])
            ->where('ch.establishment_id', $tenant['establishment_id'])
            ->where('ch.status', 'paid')
            ->groupBy(DB::raw($sourceSql))
            ->selectRaw($sourceSql . ' as source')
            ->selectRaw('COUNT(DISTINCT ch.id) as paid_charges')
            ->selectRaw('COALESCE(SUM(ch.amount), 0) as received')
            ->get()
            ->keyBy('source');

        return $opportunityRows->keys()
            ->merge($revenueRows->keys())
            ->unique()
            ->map(function ($source) use ($opportunityRows, $revenueRows) {
                $opportunity = $opportunityRows->get($source);
                $revenue = $revenueRows->get($source);
                $opportunities = (int) ($opportunity->opportunities ?? 0);
                $won = (int) ($opportunity->won_opportunities ?? 0);

                return [
                    'source' => (string) $source,
                    'opportunities' => $opportunities,
                    'won_opportunities' => $won,
                    'win_rate' => $opportunities > 0 ? round(($won / $opportunities) * 100, 2) : 0.0,
                    'won_value' => round((float) ($opportunity->won_value ?? 0), 2),
                    'paid_charges' => (int) ($revenue->paid_charges ?? 0),
                    'received' => round((float) ($revenue->received ?? 0), 2),
                ];
            })
            ->sortByDesc(fn ($row) => [$row['received'], $row['won_value'], $row['won_opportunities']])
            ->values()
            ->all();
    }

    public function contextInfo(Request $request)
    {
        $app = $this->applicationContext->application();
        $establishments = Establishment::query()->forApplication($app->id)->where('user_id',Auth::id())->orderBy('name')->get(['id','name','fantasy','slug','app_id']);
        return response()->json(['application'=>$app->only(['id','name','slug','url','logo']),'establishments'=>$establishments]);
    }

    public function dashboard(Request $request)
    {
        $context = $this->context($request); $tenant = $this->tenant($context);
        return response()->json(['metrics'=>[
            'sold'=>(float)DB::table('crm_opportunities')->where($context)->where('stage','won')->sum('value'),
            'received'=>(float)DB::table('crm_charges')->where($tenant)->where('status','paid')->sum('amount'),
            'pending'=>(float)DB::table('crm_charges')->where($tenant)->where('status','pending')->sum('amount'),
            'contacts'=>DB::table('crm_contacts')->where($context)->count(),
            'open_opportunities'=>DB::table('crm_opportunities')->where($context)->whereNotIn('stage',['won','lost'])->count(),
        ],'acquisition_sources'=>$this->acquisitionPerformance($context),'activities'=>DB::table('crm_agent_activities')->where($tenant)->latest('executed_at')->limit(20)->get()]);
    }

    public function contacts(Request $request)
    {
        $scope=$this->context($request); $q=trim((string)$request->query('q','')); $query=DB::table('crm_contacts')->where($scope);
        if($q!==''){ $like='%'.$q.'%'; $query->where(fn($b)=>$b->where('name','like',$like)->orWhere('phone','like',$like)->orWhere('email','like',$like)); }
        return response()->json(['contacts'=>$query->latest()->paginate(30)]);
    }

    public function storeContact(Request $request)
    {
        $scope=$this->context($request); $data=$request->validate(['name'=>'required|string|max:255','phone'=>'nullable|string|max:40','email'=>'nullable|email|max:255','document'=>'nullable|string|max:40','source'=>'nullable|string|max:50','notes'=>'nullable|string|max:10000']);
        $id=DB::table('crm_contacts')->insertGetId([...$scope,...$data,'status'=>'lead','created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['message'=>'Contato criado com sucesso.','contact'=>DB::table('crm_contacts')->find($id)],201);
    }

    public function updateContact(Request $request,int $id)
    {
        $scope=$this->context($request); $data=$request->validate(['name'=>'sometimes|required|string|max:255','phone'=>'sometimes|nullable|string|max:40','email'=>'sometimes|nullable|email|max:255','document'=>'sometimes|nullable|string|max:40','source'=>'sometimes|nullable|string|max:50','status'=>['sometimes',Rule::in(['lead','customer','inactive'])],'notes'=>'sometimes|nullable|string|max:10000']);
        $query=DB::table('crm_contacts')->where('id',$id)->where($scope); abort_unless($query->exists(),404,'Contato não encontrado.'); $query->update([...$data,'updated_at'=>now()]);
        return response()->json(['message'=>'Contato atualizado com sucesso.','contact'=>DB::table('crm_contacts')->find($id)]);
    }

    public function destroyContact(Request $request,int $id)
    {
        $scope=$this->context($request); $query=DB::table('crm_contacts')->where('id',$id)->where($scope); abort_unless($query->exists(),404,'Contato não encontrado.'); $query->delete();
        return response()->json(['message'=>'Contato excluído com sucesso.']);
    }

    public function opportunities(Request $request)
    {
        $scope=$this->context($request); $stage=$request->query('stage');
        $query=DB::table('crm_opportunities as o')->join('crm_contacts as c','c.id','=','o.contact_id')->where('o.app_id',$scope['app_id'])->where('o.establishment_id',$scope['establishment_id'])->where('o.owner_user_id',$scope['owner_user_id']);
        if($stage) $query->where('o.stage',$stage);
        return response()->json(['opportunities'=>$query->select('o.*','c.name as contact_name','c.phone as contact_phone')->latest('o.created_at')->paginate(100)]);
    }

    public function storeOpportunity(Request $request)
    {
        $scope=$this->context($request); $data=$request->validate(['contact_id'=>'required|integer|exists:crm_contacts,id','title'=>'required|string|max:255','value'=>'nullable|numeric|min:0','probability'=>'nullable|integer|min:0|max:100','notes'=>'nullable|string|max:10000']); $this->assertContact((int)$data['contact_id'],$scope);
        $id=DB::table('crm_opportunities')->insertGetId([...$scope,...$data,'stage'=>'new','value'=>$data['value']??0,'probability'=>$data['probability']??20,'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['message'=>'Oportunidade criada com sucesso.','opportunity'=>DB::table('crm_opportunities')->find($id)],201);
    }

    public function updateOpportunityStage(Request $request,int $id)
    {
        $scope=$this->context($request); $data=$request->validate(['stage'=>['required',Rule::in(['new','qualified','proposal','payment_pending','won','lost'])]]); $query=DB::table('crm_opportunities')->where('id',$id)->where($scope); abort_unless($query->exists(),404,'Oportunidade não encontrada.');
        $updates=['stage'=>$data['stage'],'updated_at'=>now()]; if($data['stage']==='won')$updates['won_at']=now(); if($data['stage']==='lost')$updates['lost_at']=now(); $query->update($updates);
        return response()->json(['message'=>'Etapa atualizada com sucesso.']);
    }

    public function proposals(Request $request)
    {
        $tenant=$this->tenant($this->context($request)); return response()->json(['proposals'=>DB::table('crm_proposals as p')->join('crm_contacts as c','c.id','=','p.contact_id')->where('p.app_id',$tenant['app_id'])->where('p.establishment_id',$tenant['establishment_id'])->select('p.*','c.name as contact_name')->latest('p.created_at')->paginate(50)]);
    }

    public function storeProposal(Request $request)
    {
        $context=$this->context($request); $tenant=$this->tenant($context); $data=$request->validate(['contact_id'=>'required|integer|exists:crm_contacts,id','opportunity_id'=>'nullable|integer|exists:crm_opportunities,id','items'=>'required|array|min:1','items.*.description'=>'required|string|max:255','items.*.quantity'=>'required|numeric|min:0.001','items.*.unit_price'=>'required|numeric|min:0','discount'=>'nullable|numeric|min:0','expires_at'=>'nullable|date']); $this->assertContact((int)$data['contact_id'],$context);
        $items=collect($data['items'])->map(function($item){$item['total']=round((float)$item['quantity']*(float)$item['unit_price'],2);return $item;})->values(); $subtotal=(float)$items->sum('total'); $discount=min((float)($data['discount']??0),$subtotal); $total=$subtotal-$discount; $code='PR-'.now()->format('ymd').'-'.strtoupper(Str::random(6));
        $id=DB::table('crm_proposals')->insertGetId([...$tenant,'contact_id'=>$data['contact_id'],'opportunity_id'=>$data['opportunity_id']??null,'code'=>$code,'status'=>'draft','items'=>json_encode($items->all(),JSON_UNESCAPED_UNICODE),'subtotal'=>$subtotal,'discount'=>$discount,'total'=>$total,'expires_at'=>$data['expires_at']??null,'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['message'=>'Proposta criada com sucesso.','proposal'=>DB::table('crm_proposals')->find($id)],201);
    }

    public function charges(Request $request)
    {
        $tenant=$this->tenant($this->context($request)); return response()->json(['charges'=>DB::table('crm_charges as ch')->join('crm_contacts as c','c.id','=','ch.contact_id')->where('ch.app_id',$tenant['app_id'])->where('ch.establishment_id',$tenant['establishment_id'])->select('ch.*','c.name as contact_name')->latest('ch.created_at')->paginate(50)]);
    }

    public function storeCharge(Request $request)
    {
        $context=$this->context($request); $tenant=$this->tenant($context); $data=$request->validate(['contact_id'=>'required|integer|exists:crm_contacts,id','proposal_id'=>'nullable|integer|exists:crm_proposals,id','amount'=>'required|numeric|min:0.01','due_at'=>'nullable|date']); $this->assertContact((int)$data['contact_id'],$context);
        $id=DB::table('crm_charges')->insertGetId([...$tenant,'contact_id'=>$data['contact_id'],'proposal_id'=>$data['proposal_id']??null,'provider'=>'manual','status'=>'pending','amount'=>$data['amount'],'due_at'=>$data['due_at']??null,'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['message'=>'Cobrança criada com sucesso.','charge'=>DB::table('crm_charges')->find($id)],201);
    }

    public function markChargePaid(Request $request,int $id)
    {
        $tenant=$this->tenant($this->context($request)); $query=DB::table('crm_charges')->where('id',$id)->where($tenant); abort_unless($query->exists(),404,'Cobrança não encontrada.'); $query->update(['status'=>'paid','paid_at'=>now(),'updated_at'=>now()]);
        return response()->json(['message'=>'Pagamento confirmado.']);
    }
}
