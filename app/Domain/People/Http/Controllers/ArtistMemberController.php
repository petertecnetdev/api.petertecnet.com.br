<?php

namespace App\Domain\People\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Models\ArtistMember;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ArtistMemberController extends Controller
{
    private const ARTIST_TYPES=['solo','band','group','duo','collective','orchestra'];
    private const GROUP_TYPES=['band','group','duo','collective','orchestra'];
    public function __construct(private readonly ApplicationContext $context){}

    public function publicIndex(string $slug)
    {
        $appId=$this->context->id();$artist=Artist::query()->where('app_id',$appId)->where('slug',$slug)->where('is_published',true)->firstOrFail();
        $members=ArtistMember::query()->where('app_id',$appId)->where('artist_id',$artist->id)->with(['linkedArtist'=>fn($q)=>$q->where('app_id',$appId)->where('is_published',true)->select('id','app_id','slug','artist_type','stage_name','photo','city','uf')])->orderByDesc('is_current')->orderBy('sort_order')->orderBy('display_name')->get();
        $memberOf=ArtistMember::query()->where('app_id',$appId)->where('member_artist_id',$artist->id)->where('is_current',true)->whereHas('artist',fn($q)=>$q->where('app_id',$appId)->where('is_published',true))->with(['artist:id,app_id,slug,artist_type,stage_name,photo'])->orderBy('sort_order')->get();
        return response()->json(['artist_id'=>$artist->id,'artist_type'=>$artist->artist_type,'members'=>$members,'member_of'=>$memberOf]);
    }

    public function updateType(Request $request,int $artistId){$artist=$this->managedArtist($artistId,$request->user());$data=$request->validate(['artist_type'=>'required|in:'.implode(',',self::ARTIST_TYPES)]);if($data['artist_type']==='solo'&&$artist->members()->exists())throw ValidationException::withMessages(['artist_type'=>['Remova ou encerre os integrantes antes de transformar este perfil em artista solo.']]);$artist->update(['artist_type'=>$data['artist_type']]);return response()->json(['message'=>'Tipo do perfil artístico atualizado.','artist'=>$artist->fresh()]);}
    public function index(Request $request,int $artistId){$artist=$this->managedGroup($artistId,$request->user());return response()->json(['members'=>$artist->members()->with('linkedArtist:id,app_id,slug,artist_type,stage_name,photo')->get()]);}

    public function store(Request $request,int $artistId)
    {
        $artist=$this->managedGroup($artistId,$request->user());$data=$this->memberData($request);$linked=$this->linkedArtist($data['member_artist_id']??null,$artist);$displayName=trim((string)($data['display_name']??''))?:$linked?->stage_name;if(!$displayName)throw ValidationException::withMessages(['display_name'=>['Informe o nome do integrante ou vincule um artista existente.']]);if($linked&&ArtistMember::where('artist_id',$artist->id)->where('member_artist_id',$linked->id)->exists())throw ValidationException::withMessages(['member_artist_id'=>['Este artista já faz parte deste grupo.']]);
        $member=ArtistMember::create([...$data,'app_id'=>$this->context->id(),'artist_id'=>$artist->id,'member_artist_id'=>$linked?->id,'display_name'=>$displayName,'photo'=>trim((string)($data['photo']??''))?:$linked?->photo]);return response()->json(['message'=>'Integrante adicionado ao perfil artístico.','member'=>$member->load('linkedArtist:id,app_id,slug,artist_type,stage_name,photo')],201);
    }

    public function update(Request $request,int $artistId,int $memberId)
    {
        $artist=$this->managedGroup($artistId,$request->user());$member=ArtistMember::where('app_id',$this->context->id())->where('artist_id',$artist->id)->findOrFail($memberId);$data=$this->memberData($request,false);$linkedId=array_key_exists('member_artist_id',$data)?$data['member_artist_id']:$member->member_artist_id;$linked=$this->linkedArtist($linkedId,$artist);if($linked&&ArtistMember::where('artist_id',$artist->id)->where('member_artist_id',$linked->id)->whereKeyNot($member->id)->exists())throw ValidationException::withMessages(['member_artist_id'=>['Este artista já faz parte deste grupo.']]);if(array_key_exists('member_artist_id',$data))$data['member_artist_id']=$linked?->id;if(array_key_exists('display_name',$data)&&trim((string)$data['display_name'])===''){if(!$linked)throw ValidationException::withMessages(['display_name'=>['O integrante precisa ter um nome.']]);$data['display_name']=$linked->stage_name;}if(isset($data['left_at'])&&$data['left_at'])$data['is_current']=false;$member->update($data);return response()->json(['message'=>'Integrante atualizado.','member'=>$member->fresh()->load('linkedArtist:id,app_id,slug,artist_type,stage_name,photo')]);
    }

    public function destroy(Request $request,int $artistId,int $memberId){$artist=$this->managedGroup($artistId,$request->user());$member=ArtistMember::where('app_id',$this->context->id())->where('artist_id',$artist->id)->findOrFail($memberId);$member->delete();return response()->json(['message'=>'Integrante removido do perfil artístico.']);}
    private function memberData(Request $request,bool $creating=true):array{$sometimes=$creating?'':'sometimes|';return$request->validate(['member_artist_id'=>$sometimes.'nullable|integer|exists:artists,id','display_name'=>$sometimes.'nullable|string|min:2|max:255','role'=>$sometimes.'nullable|string|max:160','photo'=>$sometimes.'nullable|string|max:2048','bio'=>$sometimes.'nullable|string|max:5000','sort_order'=>$sometimes.'nullable|integer|min:0|max:1000','is_current'=>$sometimes.'nullable|boolean','joined_at'=>$sometimes.'nullable|date','left_at'=>$sometimes.'nullable|date|after_or_equal:joined_at']);}
    private function linkedArtist(?int $id,Artist $group):?Artist{if(!$id)return null;if($id===(int)$group->id)throw ValidationException::withMessages(['member_artist_id'=>['Um grupo não pode ser integrante de si mesmo.']]);return Artist::where('app_id',$this->context->id())->findOrFail($id);}
    private function managedArtist(int $id,User $user):Artist{$artist=Artist::where('app_id',$this->context->id())->findOrFail($id);abort_unless($user->hasProfile('Administrador')||(int)$artist->user_id===(int)$user->id||(int)$artist->created_by_user_id===(int)$user->id,403,'Você não pode administrar este artista.');return$artist;}
    private function managedGroup(int $id,User $user):Artist{$artist=$this->managedArtist($id,$user);abort_unless(in_array($artist->artist_type,self::GROUP_TYPES,true),422,'Somente bandas, duos, grupos, coletivos ou orquestras possuem integrantes.');return$artist;}
}
