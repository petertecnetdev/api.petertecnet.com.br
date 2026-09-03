<?php

namespace App\Services\Reporting\Builders;

use App\Models\Item;

class ItemsReportBuilder extends BaseReportBuilder
{
    public function key():string{return'items';}public function label():string{return'Itens';}public function description():string{return'Produtos, serviços e demais itens cadastrados no ecossistema.';}
    public function build(array $filters):array
    {
        [$from,$to]=$this->period($filters,false);$query=Item::query()->with('establishment:id,name,fantasy');$this->applyCreatedPeriod($query,$from,$to);if($appId=$this->integerFilter($filters,'app_id'))$query->where('app_id',$appId);if($establishmentId=$this->integerFilter($filters,'establishment_id'))$query->where('entity_name','establishment')->where('entity_id',$establishmentId);
        if($search=$this->textFilter($filters,'search'))$query->where(fn($q)=>$q->where('name','like',"%{$search}%")->orWhere('sku','like',"%{$search}%")->orWhere('category','like',"%{$search}%"));if($type=$this->textFilter($filters,'type'))$query->where('type',$type);if(($status=$this->textFilter($filters,'status'))==='active')$query->where('status',true);if($status==='archived')$query->where('status',false);
        $total=(clone$query)->count();$rows=$query->latest('id')->limit($this->rowLimit($filters))->get()->map(fn($item)=>['created_at'=>$this->date($item->created_at),'name'=>$item->name,'type'=>$this->humanize($item->type?:'item'),'category'=>$item->category?:'—','establishment'=>$item->establishment?->fantasy?:$item->establishment?->name?:'—','price'=>$this->money($item->price),'status'=>$item->status===false?'Arquivado':'Ativo'])->all();$categories=collect($rows)->countBy('category')->reject(fn($v,$k)=>$k==='—');
        return $this->report('Relatório de itens','Produtos, serviços e demais itens cadastrados no ecossistema.',$filters,['Itens encontrados'=>$total,'Ativos'=>(clone$query)->where('status',true)->count(),'Valor médio'=>$this->money((clone$query)->avg('price'))],[['key'=>'created_at','label'=>'Cadastro'],['key'=>'name','label'=>'Item'],['key'=>'type','label'=>'Tipo'],['key'=>'category','label'=>'Categoria'],['key'=>'establishment','label'=>'Estabelecimento'],['key'=>'price','label'=>'Preço'],['key'=>'status','label'=>'Status']],$rows,$from,$to,$total>$this->rowLimit($filters),[],[$this->topVisual($categories,'Itens por categoria')],$total);
    }
}
