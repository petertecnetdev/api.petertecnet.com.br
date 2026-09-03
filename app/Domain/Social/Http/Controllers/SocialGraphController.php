<?php

namespace App\Domain\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Models\Event;
use App\Models\Production;
use App\Models\User;
use App\Services\EventLineupNotificationService;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SocialGraphController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function artists(Request $request)
    {
        $data=$request->validate(['q'=>'nullable|string|max:120','city'=>'nullable|string|max:120','uf'=>'nullable|string|size:2','genre'=>'nullable|string|max:120','per_page'=>'nullable|integer|min:1|max:50']);$appId=$this->context->id();
        $query=Artist::query()->where('app_id',$appId)->where('is_published',true)->withCount(['events as upcoming_events_count'=>fn($q)=>$q->where('events.app_id',$appId)->where('events.is_published',true)->where('events.is_cancelled',false)->where('events.is_private',false)->where('events.end_date','>',now()),'events as total_events_count'=>fn($q)=>$q->where('events.app_id',$appId)->where('events.is_published',true)->where('events.is_cancelled',false)->where('events.is_private',false)])->orderBy('stage_name');
        if($q=trim((string)($data['q']??'')))$query->where(fn($n)=>$n->where('stage_name','like',"%{$q}%")->orWhere('bio','like',"%{$q}%"));if(!empty($data['city']))$query->where('city',$data['city']);if(!empty($data['uf']))$query->where('uf',strtoupper($data['uf']));if(!empty($data['genre']))$query->where('genres','like','%'.$data['genre'].'%');
        $artists=$query->paginate($data['per_page']??24);$artists->getCollection()->transform(fn($artist)=>$this->decorateArtist($artist,$request));return response()->json(['artists'=>$artists]);
    }

    public function publicArtist(Request $request,string $slug)
    {
        $artist=Artist::query()->where('app_id',$this->context->id())->where('slug',$slug)->where('is_published',true)->firstOrFail();$artist->setAttribute('followers_count',$this->followersCount('artist',$artist->id));$artist->setAttribute('is_following',$this->isFollowing($request,'artist',$artist->id));
        return response()->json(['artist'=>$artist,'upcoming_events'=>$this->artistEvents($artist->id,true)->limit(12)->get(),'past_events'=>$this->artistEvents($artist->id,false)->limit(12)->get()]);
    }

    public function myArtists(Request $request){$query=Artist::query()->where('app_id',$this->context->id());if(!$request->user()->hasProfile('Administrador'))$query->where('user_id',$request->user()->id);return response()->json(['artists'=>$query->orderBy('stage_name')->paginate(min(max((int)$request->input('per_page',50),1),100))]);}
    public function storeArtist(Request $request){$user=$request->user();$data=$this->artistData($request);$data['artist_type']=$data['artist_type']??'solo';$data['app_id']=$this->context->id();$data['user_id']=$request->filled('user_id')&&$user->hasProfile('Administrador')?(int)$request->input('user_id'):$user->id;$data['created_by_user_id']=$user->id;$data['slug']=$this->uniqueArtistSlug($data['stage_name']);$artist=Artist::create($data);return response()->json(['message'=>'Artista criado com sucesso.','artist'=>$artist],201);}
    public function updateArtist(Request $request,int $id){$artist=$this->managedArtist($id,$request->user());$data=$this->artistData($request,false);if(!empty($data['stage_name'])&&$data['stage_name']!==$artist->stage_name)$data['slug']=$this->uniqueArtistSlug($data['stage_name'],$artist->id);unset($data['app_id'],$data['user_id']);$artist->update($data);return response()->json(['message'=>'Perfil do artista atualizado.','artist'=>$artist->fresh()]);}

    public function publicEventArtists(string $slug){$event=Event::query()->where('app_id',$this->context->id())->where('slug',$slug)->where('is_published',true)->where('is_cancelled',false)->where('is_private',false)->firstOrFail();return response()->json(['artists'=>$event->artists()->where('artists.app_id',$this->context->id())->where('artists.is_published',true)->orderByDesc('event_artist.is_headliner')->orderBy('event_artist.sort_order')->get()]);}
    public function eventArtists(Request $request,int $eventId){$event=$this->ownedEvent($eventId,$request->user());return response()->json(['artists'=>$event->artists()->orderBy('event_artist.sort_order')->get()]);}
    public function attachArtist(Request $request,int $eventId){$event=$this->ownedEvent($eventId,$request->user());$data=$request->validate(['artist_id'=>'required|integer|exists:artists,id','participation_type'=>'required|string|max:80','description'=>'nullable|string|max:2000','sort_order'=>'nullable|integer|min:0|max:1000','scheduled_at'=>'nullable|date','stage'=>'nullable|string|max:160','is_headliner'=>'nullable|boolean']);$artist=Artist::where('app_id',$this->context->id())->findOrFail($data['artist_id']);$event->artists()->syncWithoutDetaching([$artist->id=>['app_id'=>$this->context->id(),'participation_type'=>$data['participation_type'],'description'=>$data['description']??null,'sort_order'=>$data['sort_order']??0,'scheduled_at'=>$data['scheduled_at']??null,'stage'=>$data['stage']??null,'is_headliner'=>(bool)($data['is_headliner']??false)]]);app(EventLineupNotificationService::class)->notifyPublishedEvent($event->fresh('artists'));return response()->json(['message'=>'Artista vinculado ao evento.','artists'=>$event->artists()->orderBy('event_artist.sort_order')->get()]);}
    public function detachArtist(Request $request,int $eventId,int $artistId){$event=$this->ownedEvent($eventId,$request->user());$event->artists()->detach($artistId);return response()->json(['message'=>'Artista removido do line-up.']);}

    public function follow(Request $request){$data=$request->validate(['target_type'=>'required|in:artist,production','target_id'=>'required|integer|min:1']);$this->assertTarget($data['target_type'],$data['target_id']);DB::table('follows')->updateOrInsert(['app_id'=>$this->context->id(),'user_id'=>$request->user()->id,'target_type'=>$data['target_type'],'target_id'=>$data['target_id']],['updated_at'=>now(),'created_at'=>now()]);return response()->json(['message'=>'Agora você está seguindo este perfil.','following'=>true]);}
    public function unfollow(Request $request){$data=$request->validate(['target_type'=>'required|in:artist,production','target_id'=>'required|integer|min:1']);DB::table('follows')->where(['app_id'=>$this->context->id(),'user_id'=>$request->user()->id,'target_type'=>$data['target_type'],'target_id'=>$data['target_id']])->delete();return response()->json(['message'=>'Você deixou de seguir este perfil.','following'=>false]);}
    public function engagement(Request $request,int $eventId){$event=Event::query()->where('app_id',$this->context->id())->where('is_published',true)->where('is_cancelled',false)->where('is_private',false)->findOrFail($eventId);$data=$request->validate(['is_favorite'=>'sometimes|boolean','is_interested'=>'sometimes|boolean']);DB::table('event_engagements')->updateOrInsert(['app_id'=>$this->context->id(),'user_id'=>$request->user()->id,'event_id'=>$event->id],array_merge($data,['updated_at'=>now(),'created_at'=>now()]));return response()->json(['message'=>'Preferência atualizada.','engagement'=>DB::table('event_engagements')->where(['app_id'=>$this->context->id(),'user_id'=>$request->user()->id,'event_id'=>$event->id])->first()]);}
    public function preferences(Request $request){$key=['app_id'=>$this->context->id(),'user_id'=>$request->user()->id];if($request->isMethod('get'))return response()->json(['preferences'=>DB::table('application_user_preferences')->where($key)->first()]);$data=$request->validate(['preferred_city'=>'nullable|string|max:120','preferred_uf'=>'nullable|string|size:2','latitude'=>'nullable|numeric|between:-90,90','longitude'=>'nullable|numeric|between:-180,180','radius_km'=>'nullable|integer|min:1|max:500','interests'=>'nullable|array|max:50']);if(isset($data['preferred_uf']))$data['preferred_uf']=strtoupper($data['preferred_uf']);DB::table('application_user_preferences')->updateOrInsert($key,array_merge($data,['updated_at'=>now(),'created_at'=>now()]));return response()->json(['message'=>'Preferências de descoberta salvas.','preferences'=>DB::table('application_user_preferences')->where($key)->first()]);}

    private function artistEvents(int $artistId,bool $upcoming){$query=Event::query()->where('events.app_id',$this->context->id())->where('events.is_published',true)->where('events.is_cancelled',false)->where('events.is_private',false)->whereHas('artists',fn($q)=>$q->where('artists.id',$artistId))->with('production:id,name,slug,logo');return$upcoming?$query->where('events.end_date','>',now())->orderBy('events.start_date'):$query->where('events.end_date','<=',now())->orderByDesc('events.start_date');}
    private function followersCount(string $type,int $id):int{return DB::table('follows')->where(['app_id'=>$this->context->id(),'target_type'=>$type,'target_id'=>$id])->count();}
    private function isFollowing(Request $request,string $type,int $id):bool{$user=$request->user();return$user?(bool)DB::table('follows')->where(['app_id'=>$this->context->id(),'user_id'=>$user->id,'target_type'=>$type,'target_id'=>$id])->exists():false;}
    private function decorateArtist(Artist $artist,Request $request):Artist{$artist->setAttribute('followers_count',$this->followersCount('artist',$artist->id));$artist->setAttribute('is_following',$this->isFollowing($request,'artist',$artist->id));return$artist;}
    private function assertTarget(string $type,int $id):void
    {
        if($type==='artist'){
            abort_unless(
                Artist::query()->where('app_id',$this->context->id())->whereKey($id)->where('is_published',true)->exists(),
                404,
                'Artista não encontrado.'
            );
            return;
        }

        abort_unless(
            Production::query()->where('app_id',$this->context->id())->whereKey($id)->where('is_published',true)->where('is_cancelled',false)->exists(),
            404,
            'Organização não encontrada.'
        );
    }
    private function ownedEvent(int $id,User $user):Event{$event=Event::where('app_id',$this->context->id())->with('production')->findOrFail($id);abort_unless($event->production&&($user->hasProfile('Administrador')||(int)$event->production->user_id===(int)$user->id),403,'Você não pode gerenciar este evento.');return$event;}
    private function managedArtist(int $id,User $user):Artist{$artist=Artist::where('app_id',$this->context->id())->findOrFail($id);abort_unless($user->hasProfile('Administrador')||(int)$artist->user_id===(int)$user->id||(int)$artist->created_by_user_id===(int)$user->id,403,'Você não pode administrar este artista.');return$artist;}
    private function artistData(Request $request,bool $creating=true):array{$required=$creating?'required|':'sometimes|';return$request->validate(['artist_type'=>'sometimes|in:solo,band,group,duo,collective,orchestra','stage_name'=>$required.'string|min:2|max:255','bio'=>'nullable|string|max:20000','city'=>'nullable|string|max:120','uf'=>'nullable|string|size:2','genres'=>'nullable|array|max:30','genres.*'=>'string|max:80','photo'=>'nullable|string|max:2048','cover'=>'nullable|string|max:2048','instagram_url'=>'nullable|url|max:2048','youtube_url'=>'nullable|url|max:2048','spotify_url'=>'nullable|url|max:2048','website_url'=>'nullable|url|max:2048','is_published'=>'nullable|boolean']);}
    private function uniqueArtistSlug(string $name,?int $ignore=null):string{$base=Str::slug($name)?:'artista';$slug=$base;$i=2;while(Artist::query()->when($ignore,fn($q)=>$q->whereKeyNot($ignore))->where('slug',$slug)->exists())$slug=$base.'-'.$i++;return$slug;}
}
