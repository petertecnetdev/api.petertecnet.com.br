<?php

namespace App\Services\Reporting\Builders;

use App\Models\Application;

class ApplicationsReportBuilder extends BaseReportBuilder
{
    public function key():string{return'applications';}public function label():string{return'Aplicações';}public function description():string{return'Aplicações, vínculos, estabelecimentos e itens.';}
    public function build(array $filters):array
    {
        [$from,$to]=$this->period($filters,false);$query=Application::query()->withCount(['users','establishments','items']);$this->applyCreatedPeriod($query,$from,$to);
        if($search=$this->textFilter($filters,'search'))$query->where(fn($q)=>$q->where('name','like',"%{$search}%")->orWhere('slug','like',"%{$search}%")->orWhere('url','like',"%{$search}%"));if(($status=$this->textFilter($filters,'status'))==='active')$query->where('is_active',true);if($status==='inactive')$query->where('is_active',false);
        $total=(clone$query)->count();$rows=$query->orderBy('name')->limit($this->rowLimit($filters))->get()->map(fn($app)=>['created_at'=>$this->date($app->created_at),'name'=>$app->name,'slug'=>$app->slug?:'—','status'=>$app->is_active?'Ativa':'Inativa','users'=>$app->users_count,'establishments'=>$app->establishments_count,'items'=>$app->items_count,'url'=>$app->url?:'—'])->all();
        return $this->report('Relatório de aplicações','Inventário administrativo das aplicações do ecossistema.',$filters,['Aplicações encontradas'=>$total,'Ativas'=>(clone$query)->where('is_active',true)->count()],[['key'=>'created_at','label'=>'Cadastro'],['key'=>'name','label'=>'Aplicação'],['key'=>'slug','label'=>'Slug'],['key'=>'status','label'=>'Status'],['key'=>'users','label'=>'Usuários'],['key'=>'establishments','label'=>'Estabelecimentos'],['key'=>'items','label'=>'Itens'],['key'=>'url','label'=>'URL']],$rows,$from,$to,$total>$this->rowLimit($filters),[],[$this->topVisual(collect($rows)->pluck('users','name'),'Usuários por aplicação')],$total);
    }
}
