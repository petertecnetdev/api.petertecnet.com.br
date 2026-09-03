<?php

namespace App\Services\Reporting\Builders;

use App\Models\Interaction;use App\Models\User;

class UsersReportBuilder extends BaseReportBuilder
{
    public function key():string{return'users';}public function label():string{return'Usuários';}public function description():string{return'Cadastros, perfis, acessos, atividade e estabelecimentos.';}
    public function build(array $filters):array
    {
        [$from,$to]=$this->period($filters,false);$query=User::query()->with('profile:id,name')->withCount(['interactions','establishments']);$this->applyCreatedPeriod($query,$from,$to);
        if($search=$this->textFilter($filters,'search'))$query->where(fn($q)=>$q->where('first_name','like',"%{$search}%")->orWhere('last_name','like',"%{$search}%")->orWhere('email','like',"%{$search}%")->orWhere('user_name','like',"%{$search}%"));if($profileId=$this->integerFilter($filters,'profile_id'))$query->where('profile_id',$profileId);if($appId=$this->integerFilter($filters,'app_id'))$query->whereHas('applications',fn($q)=>$q->where('applications.id',$appId));
        $total=(clone$query)->count();$ids=(clone$query)->limit($this->rowLimit($filters))->pluck('id');$lastActivity=Interaction::query()->selectRaw('user_id, MAX(created_at) last_activity_at')->whereIn('user_id',$ids)->groupBy('user_id')->pluck('last_activity_at','user_id');
        $rows=$query->latest('id')->limit($this->rowLimit($filters))->get()->map(fn($user)=>['created_at'=>$this->date($user->created_at),'name'=>$this->userName($user),'email'=>$user->email,'profile'=>$user->profile?->name?:'Sem perfil','establishments'=>$user->establishments_count,'interactions'=>$user->interactions_count,'last_activity'=>$this->dateTime($lastActivity[$user->id]??null)])->all();$profiles=collect($rows)->countBy('profile');
        return $this->report('Relatório de usuários','Cadastros e utilização dos usuários do ecossistema.',$filters,['Usuários encontrados'=>$total,'Com estabelecimento'=>(clone$query)->has('establishments')->count(),'Sem estabelecimento'=>(clone$query)->doesntHave('establishments')->count()],[['key'=>'created_at','label'=>'Cadastro'],['key'=>'name','label'=>'Usuário'],['key'=>'email','label'=>'E-mail'],['key'=>'profile','label'=>'Perfil'],['key'=>'establishments','label'=>'Estabelecimentos'],['key'=>'interactions','label'=>'Interações'],['key'=>'last_activity','label'=>'Última atividade']],$rows,$from,$to,$total>$this->rowLimit($filters),[],[$this->topVisual($profiles,'Usuários por perfil')],$total);
    }
}
