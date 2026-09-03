<?php

namespace App\Services\Reporting\Builders;

use App\Models\EcosystemAuditLog;

class AuditReportBuilder extends BaseReportBuilder
{
    public function key():string{return'audit';}public function label():string{return'Auditoria';}public function description():string{return'Histórico administrativo e alterações realizadas no ecossistema.';}
    public function build(array $filters):array
    {
        [$from,$to]=$this->period($filters,true);$query=EcosystemAuditLog::query()->with('user:id,first_name,last_name,email')->whereBetween('created_at',[$from,$to]);if($userId=$this->integerFilter($filters,'user_id'))$query->where('user_id',$userId);if($action=$this->textFilter($filters,'action'))$query->where('action',$action);if($search=$this->textFilter($filters,'search'))$query->where(fn($q)=>$q->where('action','like',"%{$search}%")->orWhere('entity_type','like',"%{$search}%")->orWhere('entity_id',$search)->orWhere('ip','like',"%{$search}%")->orWhereHas('user',fn($u)=>$u->where('email','like',"%{$search}%")));
        $total=(clone$query)->count();$rows=$query->latest('id')->limit($this->rowLimit($filters))->get()->map(fn($log)=>['date'=>$this->dateTime($log->created_at),'administrator'=>$log->user?->email?:'Sistema','action'=>$this->humanize($log->action),'entity'=>$log->entity_type?class_basename($log->entity_type).($log->entity_id?' #'.$log->entity_id:''):'—','ip'=>$log->ip?:'—'])->all();$actions=collect($rows)->countBy('action');
        return $this->report('Relatório de auditoria','Histórico administrativo e rastreabilidade das alterações no ecossistema.',$filters,['Eventos de auditoria'=>$total,'Administradores envolvidos'=>(clone$query)->whereNotNull('user_id')->distinct()->count('user_id'),'Tipos de ação'=>(clone$query)->distinct()->count('action')],[['key'=>'date','label'=>'Data'],['key'=>'administrator','label'=>'Administrador'],['key'=>'action','label'=>'Ação'],['key'=>'entity','label'=>'Entidade'],['key'=>'ip','label'=>'IP']],$rows,$from,$to,$total>$this->rowLimit($filters),[],[$this->topVisual($actions,'Ações administrativas mais frequentes')],$total);
    }
}
