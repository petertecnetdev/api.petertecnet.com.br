<?php

namespace App\Services\Reporting\Builders;

use App\Models\Interaction;

class ActivityReportBuilder extends BaseReportBuilder
{
    public function key():string{return'activity';} public function label():string{return'Atividade';} public function description():string{return'Interações, usuários, aplicações, resultado e severidade.';}
    public function build(array $filters):array
    {
        [$from,$to]=$this->period($filters,true);[$pf,$pt]=$this->previousPeriod($from,$to);$query=Interaction::query()->with(['user:id,first_name,last_name,user_name,email','application:id,name,slug'])->whereBetween('created_at',[$from,$to]);$this->applyFilters($query,$filters);
        $total=(clone$query)->count();$unique=(clone$query)->whereNotNull('user_id')->distinct()->count('user_id');$apps=(clone$query)->whereNotNull('app_id')->distinct()->count('app_id');$errors=(clone$query)->where(fn($q)=>$q->where('outcome','error')->orWhere('severity','critical')->orWhere('interaction_type','request_error'))->count();
        $rows=$query->latest('id')->limit($this->rowLimit($filters))->get()->map(fn($row)=>['date'=>$this->dateTime($row->created_at),'application'=>$row->application?->name?:'Não identificada','user'=>$row->user?$this->userName($row->user):'Visitante / sistema','email'=>$row->user?->email?:'—','type'=>$this->humanize($row->interaction_type),'outcome'=>$this->humanize($row->outcome?:'—'),'severity'=>$this->humanize($row->severity?:'normal'),'route'=>trim(($row->method?$row->method.' ':'').($row->route?:'—'))])->all();
        $previous=Interaction::query()->whereBetween('created_at',[$pf,$pt]);$this->applyFilters($previous,array_diff_key($filters,['from'=>true,'to'=>true]));$byType=(clone$query)->selectRaw('interaction_type, COUNT(*) total')->groupBy('interaction_type')->pluck('total','interaction_type');
        return $this->report('Relatório de atividade','Interações registradas pela telemetria central do ecossistema.',$filters,['Interações'=>$total,'Usuários únicos'=>$unique,'Aplicações com atividade'=>$apps,'Erros / críticos'=>$errors],[['key'=>'date','label'=>'Data'],['key'=>'application','label'=>'Aplicação'],['key'=>'user','label'=>'Usuário'],['key'=>'email','label'=>'E-mail'],['key'=>'type','label'=>'Ação'],['key'=>'outcome','label'=>'Resultado'],['key'=>'severity','label'=>'Severidade'],['key'=>'route','label'=>'Rota']],$rows,$from,$to,$total>$this->rowLimit($filters),['Interações vs. período anterior'=>$this->comparison($total,$previous->count())],[$this->topVisual($byType,'Principais tipos de atividade')],$total);
    }
    private function applyFilters($query,array $filters):void
    {
        if($appId=$this->integerFilter($filters,'app_id'))$query->where('app_id',$appId);if($userId=$this->integerFilter($filters,'user_id'))$query->where('user_id',$userId);if($type=$this->textFilter($filters,'type'))$query->where('interaction_type',$type);if($outcome=$this->textFilter($filters,'outcome'))$query->where('outcome',$outcome);
        if($search=$this->textFilter($filters,'search'))$query->where(fn($q)=>$q->where('name','like',"%{$search}%")->orWhere('entity_type','like',"%{$search}%")->orWhere('route','like',"%{$search}%")->orWhereHas('user',fn($u)=>$u->where('email','like',"%{$search}%")->orWhere('first_name','like',"%{$search}%")->orWhere('last_name','like',"%{$search}%")));
    }
}
