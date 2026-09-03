<?php

namespace App\Services\Reporting\Builders;

use App\Models\Application;use App\Models\EcosystemAuditLog;use App\Models\Establishment;use App\Models\Interaction;use App\Models\Item;use App\Models\User;

class OverviewReportBuilder extends BaseReportBuilder
{
    public function key():string{return'overview';} public function label():string{return'Visão geral';} public function description():string{return'Indicadores executivos do ecossistema e desempenho por aplicação.';}
    public function build(array $filters):array
    {
        [$from,$to]=$this->period($filters,true);[$pf,$pt]=$this->previousPeriod($from,$to);$appId=$this->integerFilter($filters,'app_id');
        $interaction=Interaction::query()->whereBetween('created_at',[$from,$to]);$users=User::query()->whereBetween('created_at',[$from,$to]);$establishments=Establishment::query()->whereBetween('created_at',[$from,$to]);$items=Item::query()->whereBetween('created_at',[$from,$to]);$audit=EcosystemAuditLog::query()->whereBetween('created_at',[$from,$to]);
        if($appId){$interaction->where('app_id',$appId);$establishments->forApplication($appId);$items->where('app_id',$appId);$users->whereHas('applications',fn($q)=>$q->where('applications.id',$appId));}
        $activityByApp=Interaction::query()->selectRaw('app_id, COUNT(*) total, COUNT(DISTINCT user_id) unique_users')->whereBetween('created_at',[$from,$to])->whereNotNull('app_id')->when($appId,fn($q)=>$q->where('app_id',$appId))->groupBy('app_id')->get()->keyBy('app_id');
        $apps=Application::query()->withCount(['users','establishments','items'])->when($appId,fn($q)=>$q->whereKey($appId))->orderBy('name')->get();
        $rows=$apps->map(function($app)use($activityByApp){$u=$activityByApp->get($app->id);return['application'=>$app->name,'status'=>$app->is_active?'Ativa':'Inativa','users'=>$app->users_count,'establishments'=>$app->establishments_count,'items'=>$app->items_count,'active_users'=>(int)($u?->unique_users??0),'interactions'=>(int)($u?->total??0)];})->all();
        $currentInteractions=(clone$interaction)->count();$previousInteractions=Interaction::query()->whereBetween('created_at',[$pf,$pt])->when($appId,fn($q)=>$q->where('app_id',$appId))->count();$currentUsers=(clone$users)->count();$previousUsers=User::query()->whereBetween('created_at',[$pf,$pt])->when($appId,fn($q)=>$q->whereHas('applications',fn($a)=>$a->where('applications.id',$appId)))->count();
        return $this->report('Visão geral do ecossistema','Indicadores consolidados da Peter Tecnet no período selecionado.',$filters,['Aplicações atuais'=>Application::count(),'Usuários atuais'=>User::count(),'Novos usuários no período'=>$currentUsers,'Novos estabelecimentos'=>$establishments->count(),'Novos itens'=>$items->count(),'Interações'=>$currentInteractions,'Usuários ativos'=>(clone$interaction)->whereNotNull('user_id')->distinct()->count('user_id'),'Eventos de auditoria'=>$audit->count()],[['key'=>'application','label'=>'Aplicação'],['key'=>'status','label'=>'Status'],['key'=>'users','label'=>'Usuários'],['key'=>'establishments','label'=>'Estabelecimentos'],['key'=>'items','label'=>'Itens'],['key'=>'active_users','label'=>'Ativos no período'],['key'=>'interactions','label'=>'Interações']],$rows,$from,$to,false,['Interações vs. período anterior'=>$this->comparison($currentInteractions,$previousInteractions),'Novos usuários vs. período anterior'=>$this->comparison($currentUsers,$previousUsers)],[$this->topVisual(collect($rows)->pluck('interactions','application'),'Interações por aplicação')]);
    }
}
