<?php

namespace App\Domain\People\Http\Controllers;

use App\Domain\People\Services\ArtistIdentityService;
use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Artist;
use App\Models\Event;
use App\Models\User;
use App\Services\EventLineupNotificationService;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ArtistWorkflowController extends Controller
{
    public function __construct(private readonly ApplicationContext $context, private readonly ArtistIdentityService $identity) {}

    public function candidates(Request $request, int $eventId)
    {
        $this->ownedEvent($eventId, $request->user());
        $term = trim($request->validate(['q'=>'required|string|min:2|max:160'])['q']);
        $digits = preg_replace('/\D+/', '', $term);
        $query = User::query()->select(['id','user_name','first_name','last_name','email','phone','phone_normalized','cpf','avatar','city','uf']);
        if (filter_var($term, FILTER_VALIDATE_EMAIL)) $query->whereRaw('LOWER(email) = ?', [mb_strtolower($term)]);
        elseif (strlen($digits) >= 8) $query->where(fn($q)=>$q->where('phone_normalized',$digits)->orWhere('phone',$digits)->orWhere('cpf',$digits));
        else { $username=ltrim($term,'@'); $query->where(fn($q)=>$q->where('user_name','like',$username.'%')->orWhereRaw("CONCAT_WS(' ', first_name, last_name) LIKE ?",['%'.$username.'%'])); }

        $users=$query->limit(10)->get()->map(function(User $user)use($eventId){
            $artist=Artist::query()->where('app_id',$this->context->id())->where('user_id',$user->id)->where('artist_type','solo')->first();
            $alreadyInEvent=$artist?DB::table('event_artist')->where('app_id',$this->context->id())->where('event_id',$eventId)->where('artist_id',$artist->id)->exists():false;
            $name=trim(($user->first_name??'').' '.($user->last_name??''))?:($user->user_name?:'Usuário');
            return ['id'=>(int)$user->id,'name'=>$name,'username'=>$user->user_name?'@'.$user->user_name:null,'avatar'=>$user->avatar,'city'=>$user->city,'uf'=>$user->uf,'email_hint'=>$this->maskEmail($user->email),'phone_hint'=>$this->maskPhone($user->phone_normalized?:$user->phone),'already_in_event'=>$alreadyInEvent,'artist'=>$artist?['id'=>(int)$artist->id,'stage_name'=>$artist->stage_name,'slug'=>$artist->slug,'photo'=>$artist->photo]:null];
        })->values();
        return response()->json(['users'=>$users]);
    }

    public function resolveAndInvite(Request $request, int $eventId)
    {
        $actor=$request->user(); $event=$this->ownedEvent($eventId,$actor);
        $data=$request->validate(['identifier'=>'required|string|min:2|max:190','participation_type'=>'required|string|max:80','description'=>'nullable|string|max:2000','sort_order'=>'nullable|integer|min:0|max:1000','scheduled_at'=>'nullable|date','stage'=>'nullable|string|max:160','is_headliner'=>'nullable|boolean','fee_cents'=>'nullable|integer|min:0|max:9999999999','private_notes'=>'nullable|string|max:5000']);
        $user=$this->identity->resolveUser($data['identifier']);
        if(!$user) return $this->createExternalInvitation($event,$actor,$data);

        [$artist,$status,$conflicts,$created]=DB::transaction(function()use($event,$actor,$user,$data){
            $artist=$this->identity->getOrCreate($this->context->id(),$user,$actor,$event);
            $existing=DB::table('event_artist')->where('event_id',$event->id)->where('artist_id',$artist->id)->first();
            $status=$existing?->status==='confirmed'?'confirmed':'pending'; $token=$existing?->invite_token?:Str::random(48);
            $pivot=['app_id'=>$this->context->id(),'participation_type'=>$data['participation_type'],'description'=>$data['description']??null,'sort_order'=>$data['sort_order']??0,'scheduled_at'=>$data['scheduled_at']??null,'stage'=>$data['stage']??null,'is_headliner'=>(bool)($data['is_headliner']??false),'status'=>$status,'invited_by_user_id'=>$actor->id,'invited_at'=>$existing?->invited_at?:now(),'fee_cents'=>$data['fee_cents']??null,'payment_status'=>$existing?->payment_status?:'not_applicable','invite_token'=>$token,'private_notes'=>$data['private_notes']??null,'updated_at'=>now()];
            if($existing) DB::table('event_artist')->where('event_id',$event->id)->where('artist_id',$artist->id)->update($pivot); else DB::table('event_artist')->insert([...$pivot,'event_id'=>$event->id,'artist_id'=>$artist->id,'created_at'=>now()]);
            DB::table('artist_invitations')->updateOrInsert(['app_id'=>$this->context->id(),'event_id'=>$event->id,'invited_user_id'=>$user->id],['artist_id'=>$artist->id,'invited_by_user_id'=>$actor->id,'status'=>$status==='confirmed'?'accepted':'pending','token'=>$token,'expires_at'=>now()->addDays(30),'payload'=>json_encode(['participation_type'=>$data['participation_type'],'scheduled_at'=>$data['scheduled_at']??null,'stage'=>$data['stage']??null]),'updated_at'=>now(),'created_at'=>now()]);
            $this->audit($artist->id,$event->id,$actor->id,$existing?'participation_updated':'artist_invited',$existing?(array)$existing:null,$pivot);
            return [$artist,$status,$this->conflicts($artist->id,$event->id,$data['scheduled_at']??null),!$existing];
        },3);

        if($status!=='confirmed') AppNotification::query()->create(['app_id'=>$this->context->id(),'user_id'=>$user->id,'type'=>'artist_event_invitation','title'=>'Convite para participar de evento','message'=>'Você foi convidado para participar de '.$event->title.'.','reference_type'=>'event','reference_id'=>$event->id,'reference_url'=>'/artist/events','data'=>['event_id'=>$event->id,'artist_id'=>$artist->id,'status'=>'pending']]);
        return response()->json(['message'=>$status==='confirmed'?'Participação atualizada.':'Usuário localizado, perfil artístico vinculado e convite enviado.','artist'=>$artist->fresh(),'participation_status'=>$status,'schedule_conflicts'=>$conflicts],$created?201:200);
    }

    public function respond(Request $request,int $eventId,int $artistId)
    {
        $artist=Artist::query()->where('app_id',$this->context->id())->findOrFail($artistId); $this->assertArtistManager($artist,$request->user());
        $decision=$request->validate(['decision'=>'required|in:accept,reject'])['decision']; $pivot=DB::table('event_artist')->where('event_id',$eventId)->where('artist_id',$artist->id)->first(); abort_unless($pivot,404,'Participação não encontrada.'); $status=$decision==='accept'?'confirmed':'declined';
        DB::transaction(function()use($request,$artist,$eventId,$pivot,$status){DB::table('event_artist')->where('event_id',$eventId)->where('artist_id',$artist->id)->update(['status'=>$status,'responded_at'=>now(),'updated_at'=>now()]);DB::table('artist_invitations')->where('app_id',$this->context->id())->where('event_id',$eventId)->where('artist_id',$artist->id)->update(['status'=>$status==='confirmed'?'accepted':'declined','responded_at'=>now(),'updated_at'=>now()]);$this->audit($artist->id,$eventId,$request->user()->id,'invitation_'.$status,(array)$pivot,['status'=>$status]);});
        $event=Event::query()->where('app_id',$this->context->id())->with('production')->findOrFail($eventId); if($producerUserId=$event->production?->user_id) AppNotification::query()->create(['app_id'=>$this->context->id(),'user_id'=>$producerUserId,'type'=>'artist_event_response','title'=>$status==='confirmed'?'Artista confirmou presença':'Artista recusou o convite','message'=>$artist->stage_name.' respondeu ao convite de '.$event->title.'.','reference_type'=>'event','reference_id'=>$event->id,'reference_url'=>'/event/'.$event->id.'/lineup','data'=>['artist_id'=>$artist->id,'status'=>$status]]); if($status==='confirmed') app(EventLineupNotificationService::class)->notifyPublishedEvent($event->fresh('artists'));
        return response()->json(['message'=>$status==='confirmed'?'Participação confirmada.':'Convite recusado.','status'=>$status]);
    }

    public function checkIn(Request $request,int $eventId,int $artistId)
    {
        $event=$this->ownedEvent($eventId,$request->user()); $pivot=DB::table('event_artist')->where('event_id',$event->id)->where('artist_id',$artistId)->first(); abort_unless($pivot,404,'Artista não está no line-up.'); abort_unless(($pivot->status??'confirmed')==='confirmed',422,'Somente participações confirmadas podem fazer check-in.'); DB::table('event_artist')->where('event_id',$event->id)->where('artist_id',$artistId)->update(['checked_in_at'=>now(),'updated_at'=>now()]); $this->audit($artistId,$event->id,$request->user()->id,'artist_checked_in',(array)$pivot,['checked_in_at'=>now()->toISOString()]); return response()->json(['message'=>'Check-in do artista confirmado.','checked_in_at'=>now()->toISOString()]);
    }

    public function favorite(Request $request,int $artistId)
    {
        Artist::query()->where('app_id',$this->context->id())->where('is_active',true)->findOrFail($artistId); $key=['app_id'=>$this->context->id(),'user_id'=>$request->user()->id,'artist_id'=>$artistId]; if($request->isMethod('delete')){DB::table('artist_favorites')->where($key)->delete();return response()->json(['favorited'=>false]);} DB::table('artist_favorites')->updateOrInsert($key,['updated_at'=>now(),'created_at'=>now()]); return response()->json(['favorited'=>true]);
    }

    public function dashboard(Request $request)
    {
        $user=$request->user(); $managed=DB::table('artist_managers')->where('app_id',$this->context->id())->where('user_id',$user->id)->pluck('artist_id'); $artistIds=Artist::query()->where('app_id',$this->context->id())->where(fn($q)=>$q->where('user_id',$user->id)->orWhereIn('id',$managed))->pluck('id');
        $participations=DB::table('event_artist')->join('events','events.id','=','event_artist.event_id')->whereIn('event_artist.artist_id',$artistIds)->where('events.app_id',$this->context->id())->select(['event_artist.artist_id','event_artist.status','event_artist.participation_type','event_artist.stage','event_artist.scheduled_at','event_artist.checked_in_at','events.id as event_id','events.slug','events.title','events.start_date','events.end_date'])->orderBy('events.start_date')->get();
        $analytics=DB::table('artist_analytics_events')->whereIn('artist_id',$artistIds)->where('occurred_at','>=',now()->subDays(30))->selectRaw('artist_id,event_type,count(*) as total')->groupBy('artist_id','event_type')->get();
        return response()->json(['artists'=>Artist::query()->whereIn('id',$artistIds)->orderBy('stage_name')->get(),'participations'=>$participations,'analytics_30d'=>$analytics]);
    }

    public function managers(Request $request,int $artistId)
    {
        $artist=Artist::query()->where('app_id',$this->context->id())->findOrFail($artistId); $this->assertArtistOwner($artist,$request->user());
        if($request->isMethod('get')) return response()->json(['managers'=>DB::table('artist_managers')->join('users','users.id','=','artist_managers.user_id')->where('artist_managers.app_id',$this->context->id())->where('artist_managers.artist_id',$artist->id)->select(['artist_managers.id','artist_managers.user_id','artist_managers.role','artist_managers.permissions','users.user_name','users.first_name','users.last_name','users.avatar'])->get()]);
        $data=$request->validate(['user_id'=>'required|integer|exists:users,id','role'=>'nullable|string|max:80','permissions'=>'nullable|array']); DB::table('artist_managers')->updateOrInsert(['app_id'=>$this->context->id(),'artist_id'=>$artist->id,'user_id'=>$data['user_id']],['role'=>$data['role']??'manager','permissions'=>isset($data['permissions'])?json_encode($data['permissions']):null,'updated_at'=>now(),'created_at'=>now()]); return response()->json(['message'=>'Gestor artístico vinculado.']);
    }

    public function removeManager(Request $request,int $artistId,int $userId){$artist=Artist::query()->where('app_id',$this->context->id())->findOrFail($artistId);$this->assertArtistOwner($artist,$request->user());DB::table('artist_managers')->where(['app_id'=>$this->context->id(),'artist_id'=>$artist->id,'user_id'=>$userId])->delete();return response()->json(['message'=>'Gestor removido.']);}

    public function analytics(Request $request,int $artistId){$artist=Artist::query()->where('app_id',$this->context->id())->findOrFail($artistId);$this->assertArtistManager($artist,$request->user());$days=min(max((int)$request->input('days',30),1),365);return response()->json(['artist_id'=>$artist->id,'days'=>$days,'events'=>DB::table('artist_analytics_events')->where('app_id',$this->context->id())->where('artist_id',$artist->id)->where('occurred_at','>=',now()->subDays($days))->selectRaw('event_type,source,count(*) as total')->groupBy('event_type','source')->orderByDesc('total')->get()]);}

    public function track(Request $request,int $artistId){Artist::query()->where('app_id',$this->context->id())->where('is_published',true)->findOrFail($artistId);$data=$request->validate(['event_type'=>'required|in:view,share,follow,external_click,event_click,ticket_click','event_id'=>'nullable|integer','source'=>'nullable|string|max:120','session_id'=>'nullable|string|max:190']);DB::table('artist_analytics_events')->insert(['app_id'=>$this->context->id(),'artist_id'=>$artistId,'event_id'=>$data['event_id']??null,'user_id'=>$request->user()?->id,'event_type'=>$data['event_type'],'source'=>$data['source']??null,'session_hash'=>isset($data['session_id'])?hash('sha256',$data['session_id']):null,'metadata'=>null,'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);return response()->json(['tracked'=>true]);}

    private function createExternalInvitation(Event $event,User $actor,array $data)
    {
        $identifier=trim($data['identifier']);$digits=preg_replace('/\D+/','',$identifier);$type=filter_var($identifier,FILTER_VALIDATE_EMAIL)?'email':(strlen($digits)>=8?'phone':'username');$normalized=$type==='email'?mb_strtolower($identifier):($type==='phone'?$digits:mb_strtolower(ltrim($identifier,'@')));$token=Str::random(48);
        DB::table('artist_invitations')->insert(['app_id'=>$this->context->id(),'event_id'=>$event->id,'artist_id'=>null,'invited_user_id'=>null,'invited_by_user_id'=>$actor->id,'identifier_type'=>$type,'identifier_hash'=>hash('sha256',$normalized),'identifier_hint'=>$type==='email'?$this->maskEmail($identifier):($type==='phone'?$this->maskPhone($digits):'@'.ltrim($identifier,'@')),'status'=>'pending_external','token'=>$token,'expires_at'=>now()->addDays(30),'payload'=>json_encode(collect($data)->except('identifier')->all()),'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['message'=>'Usuário ainda não encontrado. Convite externo criado sem gerar perfil artístico duplicado.','external_invitation'=>true,'invite_token'=>$token,'invite_path'=>'/artist/invite/'.$token],202);
    }

    private function conflicts(int $artistId,int $eventId,?string $scheduledAt):array{if(!$scheduledAt)return[];$time=strtotime($scheduledAt);return DB::table('event_artist')->join('events','events.id','=','event_artist.event_id')->where('event_artist.artist_id',$artistId)->where('event_artist.event_id','!=',$eventId)->where('event_artist.status','confirmed')->whereBetween('event_artist.scheduled_at',[date('Y-m-d H:i:s',$time-7200),date('Y-m-d H:i:s',$time+7200)])->select(['events.id','events.title','event_artist.scheduled_at','event_artist.stage'])->limit(5)->get()->map(fn($r)=>(array)$r)->all();}
    private function ownedEvent(int $id,User $user):Event{$event=Event::query()->where('app_id',$this->context->id())->with('production')->findOrFail($id);abort_unless($event->production&&($user->hasProfile('Administrador')||(int)$event->production->user_id===(int)$user->id),403,'Você não pode gerenciar este evento.');return$event;}
    private function assertArtistOwner(Artist $artist,User $user):void{abort_unless($user->hasProfile('Administrador')||(int)$artist->user_id===(int)$user->id,403,'Somente o artista proprietário pode alterar esta configuração.');}
    private function assertArtistManager(Artist $artist,User $user):void{$manager=DB::table('artist_managers')->where(['app_id'=>$this->context->id(),'artist_id'=>$artist->id,'user_id'=>$user->id])->exists();abort_unless($user->hasProfile('Administrador')||(int)$artist->user_id===(int)$user->id||$manager,403,'Você não pode administrar este artista.');}
    private function audit(?int $artistId,?int $eventId,?int $actorId,string $action,?array $before,?array $after):void{DB::table('artist_audit_logs')->insert(['app_id'=>$this->context->id(),'artist_id'=>$artistId,'event_id'=>$eventId,'actor_user_id'=>$actorId,'action'=>$action,'before'=>$before?json_encode($before):null,'after'=>$after?json_encode($after):null,'created_at'=>now(),'updated_at'=>now()]);}
    private function maskEmail(?string $email):?string{if(!$email||!str_contains($email,'@'))return null;[$local,$domain]=explode('@',$email,2);return mb_substr($local,0,1).'***@'.$domain;}
    private function maskPhone(?string $phone):?string{$digits=preg_replace('/\D+/','',(string)$phone);return$digits?'***'.substr($digits,-4):null;}
}
