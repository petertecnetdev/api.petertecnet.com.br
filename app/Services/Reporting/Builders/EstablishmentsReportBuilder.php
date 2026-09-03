<?php

namespace App\Services\Reporting\Builders;

use App\Models\Establishment;

class EstablishmentsReportBuilder extends BaseReportBuilder
{
    public function key():string{return'establishments';}public function label():string{return'Estabelecimentos';}public function description():string{return'Empresas cadastradas, aprovação, publicação, localidade e aplicação.';}
    public function build(array $filters):array
    {
        [$from,$to]=$this->period($filters,false);$query=Establishment::query()->with(['app:id,name,slug','user:id,first_name,last_name,email']);$this->applyCreatedPeriod($query,$from,$to);if($appId=$this->integerFilter($filters,'app_id'))$query->forApplication($appId);
        if($search=$this->textFilter($filters,'search'))$query->where(fn($q)=>$q->where('name','like',"%{$search}%")->orWhere('fantasy','like',"%{$search}%")->orWhere('cnpj','like',"%{$search}%")->orWhere('email','like',"%{$search}%"));if($city=$this->textFilter($filters,'city'))$query->where('city','like',"%{$city}%");if($uf=$this->textFilter($filters,'uf'))$query->where('uf',strtoupper($uf));if(($status=$this->textFilter($filters,'status'))==='approved')$query->where('is_approved',true);if($status==='pending')$query->where('is_approved',false);if($status==='published')$query->where('is_published',true);if($status==='hidden')$query->where('is_published',false);
        $total=(clone$query)->count();$rows=$query->latest('id')->limit($this->rowLimit($filters))->get()->map(fn($row)=>['created_at'=>$this->date($row->created_at),'name'=>$row->fantasy?:$row->name,'document'=>$row->cnpj?:'—','application'=>$row->app?->name?:'—','owner'=>$row->user?->email?:'—','location'=>collect([$row->city,$row->uf])->filter()->join(' / ')?:'—','approval'=>$row->is_approved?'Aprovado':'Pendente','publication'=>$row->is_published?'Publicado':'Oculto'])->all();$locations=collect($rows)->countBy('location')->reject(fn($v,$k)=>$k==='—');
        return $this->report('Relatório de estabelecimentos','Empresas e estabelecimentos administrados pela Peter Tecnet.',$filters,['Estabelecimentos encontrados'=>$total,'Aprovados'=>(clone$query)->where('is_approved',true)->count(),'Publicados'=>(clone$query)->where('is_published',true)->count()],[['key'=>'created_at','label'=>'Cadastro'],['key'=>'name','label'=>'Estabelecimento'],['key'=>'document','label'=>'Documento'],['key'=>'application','label'=>'Aplicação'],['key'=>'owner','label'=>'Responsável'],['key'=>'location','label'=>'Localidade'],['key'=>'approval','label'=>'Aprovação'],['key'=>'publication','label'=>'Publicação']],$rows,$from,$to,$total>$this->rowLimit($filters),[],[$this->topVisual($locations,'Estabelecimentos por localidade')],$total);
    }
}
