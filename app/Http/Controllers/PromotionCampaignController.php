<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Production;
use App\Models\PromotionCampaign;
use App\Models\PromotionCampaignAttribution;
use App\Services\PromotionCampaignComplianceService;
use App\Services\PromotionCampaignService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PromotionCampaignController extends Controller
{
    public function __construct(private PromotionCampaignComplianceService $compliance, private PromotionCampaignService $campaigns) {}

    public function index(Request $request, string $application)
    {
        $data = $request->validate(['event_id'=>'nullable|integer|exists:events,id','status'=>'nullable|string|in:draft,review,published,paused,ended,cancelled','mine'=>'nullable|boolean','per_page'=>'nullable|integer|min:1|max:100']);
        $query = PromotionCampaign::query()->with(['options','event:id,title,slug,production_id,start_date,end_date','production:id,name,slug,user_id'])->where('app_slug', $application)->when(isset($data['event_id']), fn($q)=>$q->where('event_id',$data['event_id']))->orderByDesc('created_at');
        $user = Auth::user();
        if (! empty($data['mine'])) {
            if (! $user) return response()->json(['message'=>'Não autenticado.'],401);
            $query->where(function($q) use ($user) {$q->where('created_by',$user->id)->orWhereHas('production', fn($p)=>$p->where('user_id',$user->id));});
        } else $query->where('status', $data['status'] ?? 'published')->where('visibility','public');
        return response()->json(['campaigns'=>$query->paginate($data['per_page'] ?? 24)]);
    }

    public function show(string $application, string $uuid)
    {
        $campaign = $this->findCampaign($application,$uuid)->load(['options','winners.user:id,first_name,last_name,user_name,avatar']);
        if ($campaign->status !== 'published' && ! $this->canManage($campaign)) return response()->json(['message'=>'Campanha indisponível.'],404);
        return response()->json(['campaign'=>$campaign]);
    }

    public function store(Request $request, string $application)
    {
        $data = $this->validatePayload($request, true);
        $event = Event::with('production')->findOrFail($data['event_id']);
        if (! $this->canManageProduction($event->production,'event_edit')) return response()->json(['message'=>'Sem permissão para gerenciar campanhas deste evento.'],403);
        if ($event->app_slug && $event->app_slug !== $application) return response()->json(['message'=>'Evento não pertence a esta aplicação.'],422);
        $classification = $this->compliance->classification($data);
        $campaign = DB::transaction(function() use ($data,$event,$application,$classification) {
            $campaign = PromotionCampaign::create(array_merge($data,['app_id'=>$event->app_id,'app_slug'=>$application,'production_id'=>$event->production_id,'created_by'=>Auth::id(),'status'=>'draft','requires_authorization'=>$classification['requires_authorization'],'compliance_status'=>$classification['compliance_status']]));
            $this->replaceOptions($campaign,$data['options'] ?? []);
            return $campaign;
        });
        return response()->json(['message'=>'Campanha criada.','classification'=>$classification,'campaign'=>$campaign->load('options')],201);
    }

    public function update(Request $request, string $application, string $uuid)
    {
        $campaign = $this->findCampaign($application,$uuid);
        if (! $this->canManage($campaign)) return response()->json(['message'=>'Sem permissão.'],403);
        if ($campaign->status === 'ended') return response()->json(['message'=>'Campanha encerrada não pode ser alterada.'],422);
        $data = $this->validatePayload($request, false);
        if (array_key_exists('options',$data) && $campaign->participations()->exists()) return response()->json(['message'=>'As opções não podem ser alteradas depois da primeira participação.'],422);
        $classification = $this->compliance->classification(array_merge($campaign->toArray(),$data));
        $campaign->fill($data); $campaign->requires_authorization = $classification['requires_authorization'];
        if ($classification['requires_authorization'] && $campaign->compliance_status === 'not_required') $campaign->compliance_status = 'review';
        if (! $classification['requires_authorization']) { $campaign->compliance_status = 'not_required'; $campaign->authorization_number = null; $campaign->authorization_metadata = null; }
        $campaign->save();
        if (array_key_exists('options',$data)) $this->replaceOptions($campaign,$data['options'] ?? []);
        return response()->json(['message'=>'Campanha atualizada.','classification'=>$classification,'campaign'=>$campaign->fresh()->load('options')]);
    }

    public function publish(string $application, string $uuid)
    {
        $campaign = $this->findCampaign($application,$uuid);
        if (! $this->canManage($campaign)) return response()->json(['message'=>'Sem permissão.'],403);
        $this->compliance->assertPublishable($campaign);
        if (in_array($campaign->type,['poll','contest'],true) && $campaign->options()->count() < 2) return response()->json(['message'=>'Inclua pelo menos duas opções.'],422);
        $campaign->update(['status'=>'published','published_at'=>now()]);
        return response()->json(['message'=>'Campanha publicada.','campaign'=>$campaign->fresh()->load('options')]);
    }

    public function status(Request $request, string $application, string $uuid)
    {
        $campaign = $this->findCampaign($application,$uuid);
        if (! $this->canManage($campaign)) return response()->json(['message'=>'Sem permissão.'],403);
        $data = $request->validate(['status'=>'required|string|in:paused,ended,cancelled']);
        $campaign->update(['status'=>$data['status'],'ended_at'=>$data['status']==='ended'?now():$campaign->ended_at]);
        return response()->json(['message'=>'Status atualizado.','campaign'=>$campaign->fresh()]);
    }

    public function participate(Request $request, string $application, string $uuid)
    {
        $campaign = $this->findCampaign($application,$uuid);
        $data = $request->validate(['option_id'=>'nullable|integer','source'=>'nullable|string|max:80','referral_code'=>'nullable|string|max:120','idempotency_key'=>'nullable|string|max:120','metadata'=>'nullable|array']);
        return response()->json($this->campaigns->participate($campaign,(int)Auth::id(),$data));
    }

    public function touch(Request $request, string $application, string $uuid)
    {
        $campaign = $this->findCampaign($application,$uuid);
        if ($campaign->status !== 'published') return response()->json(['message'=>'Campanha indisponível.'],404);
        $data = $request->validate(['touchpoint'=>['required',Rule::in(['view','click','share'])],'idempotency_key'=>'nullable|string|max:120','metadata'=>'nullable|array']);
        $attributes = ['user_id'=>Auth::id(),'touchpoint'=>$data['touchpoint'],'metadata'=>$data['metadata'] ?? null];
        if (! empty($data['idempotency_key'])) PromotionCampaignAttribution::firstOrCreate(['campaign_id'=>$campaign->id,'idempotency_key'=>$data['idempotency_key']],$attributes);
        else PromotionCampaignAttribution::create(array_merge(['campaign_id'=>$campaign->id],$attributes));
        return response()->json(['ok'=>true]);
    }

    public function analytics(string $application, string $uuid)
    {
        $campaign = $this->findCampaign($application,$uuid);
        if (! $this->canManage($campaign)) return response()->json(['message'=>'Sem permissão.'],403);
        return response()->json(['campaign_id'=>$campaign->id,'metrics'=>$this->campaigns->analytics($campaign)]);
    }

    public function draw(Request $request, string $application, string $uuid)
    {
        $campaign = $this->findCampaign($application,$uuid);
        if (! $this->canManage($campaign)) return response()->json(['message'=>'Sem permissão.'],403);
        $data = $request->validate(['quantity'=>'required|integer|min:1|max:100']);
        return response()->json(['winners'=>$this->campaigns->executeSystemDraw($campaign,$data['quantity'])]);
    }

    public function compliance(Request $request, string $application, string $uuid)
    {
        $campaign = $this->findCampaign($application,$uuid);
        if (! $this->canManage($campaign)) return response()->json(['message'=>'Sem permissão.'],403);
        $data = $request->validate(['compliance_status'=>'required|string|in:review,approved,rejected','authorization_number'=>'nullable|string|max:120','authorization_metadata'=>'nullable|array']);
        if (in_array($data['compliance_status'],['approved','rejected'],true) && ! $this->canApproveCompliance()) return response()->json(['message'=>'A aprovação ou rejeição regulatória exige privilégio administrativo de compliance.'],403);
        if ($data['compliance_status']==='approved' && empty($data['authorization_number'])) return response()->json(['message'=>'Informe o número da autorização.'],422);
        $campaign->update($data);
        return response()->json(['message'=>'Compliance atualizado.','campaign'=>$campaign->fresh()]);
    }

    private function validatePayload(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        return $request->validate(['event_id'=>"$required|integer|exists:events,id",'type'=>[$required,'string',Rule::in(['poll','objective_reward','referral','coupon','contest','draw','giveaway','boost','sponsored'])],'objective'=>[$required,'string',Rule::in(['engagement','conversion','acquisition','upsell','retention','awareness'])],'title'=>"$required|string|max:255",'description'=>'sometimes|nullable|string|max:10000','visibility'=>'sometimes|string|in:public,unlisted,private','starts_at'=>'sometimes|nullable|date','ends_at'=>'sometimes|nullable|date|after:starts_at','configuration'=>'sometimes|nullable|array','reward'=>'sometimes|nullable|array','reward.mode'=>'sometimes|nullable|string|in:none,all_eligible,winner,selected','reward.kind'=>'sometimes|nullable|string|max:40','reward.value'=>'sometimes|nullable|numeric|min:0','reward.label'=>'sometimes|nullable|string|max:255','reward.has_prize'=>'sometimes|boolean','sponsor_metadata'=>'sometimes|nullable|array','boost_metadata'=>'sometimes|nullable|array','options'=>'sometimes|array|max:50','options.*.label'=>'required_with:options|string|max:255','options.*.description'=>'nullable|string|max:2000','options.*.image'=>'nullable|string|max:1000','options.*.metadata'=>'nullable|array']);
    }

    private function replaceOptions(PromotionCampaign $campaign, array $options): void
    {
        $campaign->options()->delete();
        foreach (array_values($options) as $index=>$option) $campaign->options()->create(array_merge($option,['sort_order'=>$index,'votes_count'=>0]));
    }

    private function findCampaign(string $application,string $uuid): PromotionCampaign { return PromotionCampaign::where('app_slug',$application)->where('uuid',$uuid)->firstOrFail(); }
    private function canManage(PromotionCampaign $campaign): bool { return $this->canManageProduction($campaign->production,'event_edit'); }
    private function canApproveCompliance(): bool
    {
        $user = Auth::user();
        return $user && ($user->hasProfile('Administrador') || $user->hasPermission('campaign_compliance_approve'));
    }
    private function canManageProduction(?Production $production,string $permission): bool
    {
        $user = Auth::user();
        return $user && $production && ($user->hasProfile('Administrador') || (int)$production->user_id===(int)$user->id || $user->hasPermission($permission));
    }
}
