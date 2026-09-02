<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\Event;
use App\Models\Production;
use App\Models\Ticket;
use App\Services\OrganizerContractService;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Facades\Image;
use Throwable;

class EventManagementController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function mine(Request $request)
    {
        $this->context->requireCapability('events');
        $data=$request->validate(['q'=>'nullable|string|max:120','city'=>'nullable|string|max:120','status'=>'nullable|in:draft,published,cancelled,upcoming,past','production_id'=>'nullable|integer|min:1','from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d|after_or_equal:from','per_page'=>'nullable|integer|min:1|max:100']);
        $query=Event::query()->where('app_id',$this->context->id())->where('app_slug',$this->context->slug())
            ->whereHas('production',fn($q)=>$q->where('app_id',$this->context->id())->where('user_id',$request->user()->id))
            ->with(['production:id,app_id,name,slug,user_id,app_slug','artists:id,app_id,slug,stage_name'])
            ->withCount(['tickets'=>fn($q)=>$q->where('app_id',$this->context->id())]);
        if($term=trim((string)($data['q']??'')))$query->where(fn($q)=>$q->where('title','like',"%{$term}%")->orWhere('city','like',"%{$term}%")->orWhere('venue','like',"%{$term}%"));
        if(!empty($data['city']))$query->whereRaw('LOWER(city)=LOWER(?)',[$data['city']]);
        if(!empty($data['production_id']))$query->where('production_id',(int)$data['production_id']);
        if(!empty($data['from']))$query->where('start_date','>=',Carbon::createFromFormat('Y-m-d',$data['from'],config('app.timezone'))->startOfDay());
        if(!empty($data['to']))$query->where('start_date','<=',Carbon::createFromFormat('Y-m-d',$data['to'],config('app.timezone'))->endOfDay());
        match($data['status']??null){'draft'=>$query->where('is_published',false)->where('is_cancelled',false),'published'=>$query->where('is_published',true)->where('is_cancelled',false),'cancelled'=>$query->where('is_cancelled',true),'upcoming'=>$query->where('is_cancelled',false)->where('end_date','>',now()),'past'=>$query->where('end_date','<=',now()),default=>null};
        return response()->json(['events'=>$query->orderByDesc('start_date')->paginate($data['per_page']??50)->appends($request->query())]);
    }

    public function show(Request $request,int $id)
    {
        $this->context->requireCapability('events');
        $event=$this->ownedEvent($id,$request);$event->load(['production:id,app_id,name,slug,user_id,app_slug','artists:id,app_id,slug,stage_name']);
        $event->loadCount(['tickets'=>fn($q)=>$q->where('app_id',$this->context->id())]);
        return response()->json(['event'=>$event]);
    }

    public function store(Request $request)
    {
        $this->context->requireCapability('events');
        $this->normalizeInput($request);
        $data=$request->validate($this->rules(true));
        $production=$this->ownedProduction((int)$data['production_id'],$request);
        if($this->context->supports('contracts')){
            $signed=DB::table('cutinapp_producer_contract_acceptances')->where('production_id',$production->id)->where('contract_version',OrganizerContractService::VERSION)->exists();
            abort_unless($signed,428,'Antes de criar o primeiro evento desta organização, leia e assine o termo de adesão.');
        }
        $this->validateDates($data,null);
        if(empty($data['city'])&&$production->city)$data['city']=$production->city;
        if(empty($data['uf'])&&$production->uf)$data['uf']=$production->uf;
        $data['app_id']=$this->context->id();$data['app_slug']=$this->context->slug();$data['slug']=$this->uniqueSlug($data['title']);$data['is_published']=false;$data['is_cancelled']=false;unset($data['image']);
        $event=Event::create($data);
        if($request->hasFile('image')){$event->image=$this->storeImage($request->file('image'));$event->save();}
        return response()->json(['message'=>'Evento criado como rascunho.','event'=>$event->fresh()->load('production:id,app_id,name,slug,user_id,app_slug')],201);
    }

    public function update(Request $request,int $id)
    {
        $this->context->requireCapability('events');
        $event=$this->ownedEvent($id,$request);$this->normalizeInput($request);$data=$request->validate($this->rules(false));
        if(isset($data['production_id']))$this->ownedProduction((int)$data['production_id'],$request);
        $this->validateDates($data,$event);
        if(!empty($data['title'])&&$data['title']!==$event->title)$data['slug']=$this->uniqueSlug($data['title'],$event->id);
        unset($data['image'],$data['app_id'],$data['app_slug'],$data['is_published'],$data['is_cancelled']);$event->update($data);
        if($request->hasFile('image')){if($event->image&&str_starts_with($event->image,'images/'))Storage::disk('public')->delete($event->image);$event->image=$this->storeImage($request->file('image'));$event->save();}
        return response()->json(['message'=>'Evento atualizado com sucesso.','event'=>$event->fresh()->load('production:id,app_id,name,slug,user_id,app_slug')]);
    }

    public function publish(Request $request,int $id)
    {
        $this->context->requireCapability('events');
        $event=$this->ownedEvent($id,$request);$timezone=config('app.timezone','America/Sao_Paulo');$now=Carbon::now($timezone);
        abort_if($event->is_cancelled,422,'Um evento cancelado não pode ser publicado.');
        abort_if(!$event->end_date||Carbon::parse($event->end_date,$timezone)->lte($now),422,'Um evento já encerrado não pode ser publicado.');
        abort_if(!$event->start_date||Carbon::parse($event->start_date,$timezone)->lte($now),422,'O evento precisa ser publicado antes do horário de início.');
        $hasAvailableTicket=Ticket::query()->where('app_id',$this->context->id())->where('event_id',$event->id)->where('quantity','>',0)->where(fn($q)=>$q->whereNull('limit_date')->orWhere('limit_date','>',$now))->exists();
        abort_unless($hasAvailableTicket,422,'Crie ao menos uma credencial de acesso disponível antes de publicar o evento.');
        $wasPublished=(bool)$event->is_published;$event->forceFill(['is_published'=>true])->save();
        if(!$wasPublished&&!$event->is_private)$this->notifyOrganizationFollowers($event);
        return response()->json(['message'=>$event->is_private?'Evento privado ativado.':'Evento publicado com sucesso.','event'=>$event->fresh()->load('production:id,app_id,name,slug,user_id,app_slug')]);
    }

    public function unpublish(Request $request,int $id)
    {
        $this->context->requireCapability('events');
        $event=$this->ownedEvent($id,$request);$event->forceFill(['is_published'=>false])->save();
        return response()->json(['message'=>'Evento retirado da publicação. As credenciais já emitidas foram preservadas.','event'=>$event->fresh()->load('production:id,app_id,name,slug,user_id,app_slug')]);
    }

    private function rules(bool $creating):array
    {
        $r=$creating?'required|':'sometimes|';
        return ['production_id'=>$r.'integer|exists:productions,id','title'=>$r.'string|min:2|max:255','description'=>$r.'string|max:50000','category'=>'sometimes|nullable|string|max:120','image'=>'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:5120','address'=>$r.'string|max:500','google_maps_url'=>'sometimes|nullable|url:http,https|max:2048','start_date'=>$r.'date','end_date'=>$r.'date','venue'=>'sometimes|nullable|string|max:255','uf'=>'sometimes|nullable|string|size:2','city'=>'sometimes|nullable|string|max:120','cep'=>'sometimes|nullable|string|max:20','latitude'=>'sometimes|nullable|numeric|between:-90,90','longitude'=>'sometimes|nullable|numeric|between:-180,180','max_attendees'=>'sometimes|nullable|integer|min:1|max:1000000','contact_email'=>'sometimes|nullable|email|max:255','contact_phone'=>'sometimes|nullable|string|max:50','is_private'=>'sometimes|boolean'];
    }

    private function normalizeInput(Request $request):void
    {
        $merge=[];$errors=[];if($request->has('uf'))$merge['uf']=strtoupper(trim((string)$request->input('uf')));
        foreach(['start_date','end_date'] as $field){if(!$request->filled($field))continue;try{$merge[$field]=Carbon::parse((string)$request->input($field),config('app.timezone'))->format('Y-m-d H:i:s');}catch(Throwable){$errors[$field][]='Informe uma data válida.';}}
        if($errors!==[])throw ValidationException::withMessages($errors);if($merge!==[])$request->merge($merge);
    }

    private function validateDates(array $data,?Event $event):void
    {
        $startValue=$data['start_date']??$event?->start_date;$endValue=$data['end_date']??$event?->end_date;if(!$startValue||!$endValue)return;
        $timezone=config('app.timezone','America/Sao_Paulo');$now=Carbon::now($timezone);$start=Carbon::parse($startValue,$timezone);$end=Carbon::parse($endValue,$timezone);$errors=[];
        if($event===null&&$start->lte($now))$errors['start_date'][]='O horário de início precisa estar no futuro.';
        if(!$end->gt($start))$errors['end_date'][]='O término precisa ser posterior ao início.';
        if($start->diffInDays($end)>30)$errors['end_date'][]='A duração do evento não pode ultrapassar 30 dias.';
        if($errors!==[])throw ValidationException::withMessages($errors);
    }

    private function ownedProduction(int $id,Request $request):Production
    {
        $production=Production::query()->where('app_id',$this->context->id())->where('app_slug',$this->context->slug())->findOrFail($id);
        $user=$request->user();abort_unless($user->hasProfile('Administrador')||(int)$production->user_id===(int)$user->id,403,'Você não pode gerenciar esta organização.');return $production;
    }

    private function ownedEvent(int $id,Request $request):Event
    {
        $event=Event::query()->where('app_id',$this->context->id())->where('app_slug',$this->context->slug())->with('production')->findOrFail($id);
        abort_unless($event->production&&(int)$event->production->app_id===$this->context->id(),404,'Evento não encontrado.');
        $user=$request->user();abort_unless($user->hasProfile('Administrador')||(int)$event->production->user_id===(int)$user->id,403,'Você não pode gerenciar este evento.');return $event;
    }

    private function notifyOrganizationFollowers(Event $event):void
    {
        $followers=DB::table('cutinapp_follows')->where(['app_id'=>$this->context->id(),'target_type'=>'production','target_id'=>$event->production_id])->pluck('user_id');
        foreach($followers as $userId)AppNotification::create(['app_id'=>$this->context->id(),'user_id'=>$userId,'type'=>'organization_event_published','title'=>'Novo evento publicado','message'=>$event->production?->name.' publicou '.$event->title.'.','reference_type'=>'event','reference_id'=>$event->id,'reference_url'=>'/event/'.$event->slug,'data'=>['production_id'=>$event->production_id,'event_id'=>$event->id]]);
    }

    private function uniqueSlug(string $title,?int $ignoreId=null):string
    {
        $base=Str::slug($title)?:'evento';$slug=$base;$counter=2;while(Event::query()->when($ignoreId,fn($q)=>$q->whereKeyNot($ignoreId))->where('slug',$slug)->exists())$slug=$base.'-'.$counter++;return $slug;
    }

    private function storeImage($file):string
    {
        $directory='images/'.$this->context->slug().'/events';$path=$directory.'/'.Str::uuid().'.webp';
        $image=Image::make($file)->orientate()->resize(1920,1080,function($constraint){$constraint->aspectRatio();$constraint->upsize();})->encode('webp',86);
        Storage::disk('public')->put($path,(string)$image);return $path;
    }
}
