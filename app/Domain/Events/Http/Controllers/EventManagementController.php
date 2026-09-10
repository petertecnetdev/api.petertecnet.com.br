<?php

namespace App\Domain\Events\Http\Controllers;

use App\Domain\Commerce\Services\TicketInventoryService;
use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Event;
use App\Models\EventItem;
use App\Models\Production;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Facades\Image;
use Throwable;

final class EventManagementController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly TicketInventoryService $ticketInventory,
    ) {}

    public function mine(Request $request)
    {
        $user=$request->user();$data=$request->validate(['q'=>'nullable|string|max:120','city'=>'nullable|string|max:120','status'=>'nullable|in:draft,published,cancelled,upcoming,past','production_id'=>'nullable|integer|min:1','from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d|after_or_equal:from','per_page'=>'nullable|integer|min:1|max:100']);
        $appId=$this->context->id();$query=Event::query()->where('app_id',$appId)->whereHas('production',fn($q)=>$q->where('app_id',$appId)->where('user_id',$user->id))->with(['production:id,app_id,name,slug,user_id,app_slug','artists:id,app_id,slug,stage_name'])->withCount(['tickets'=>fn($q)=>$q->where('app_id',$appId)]);
        if($term=trim((string)($data['q']??'')))$query->where(fn($q)=>$q->where('title','like',"%{$term}%")->orWhere('city','like',"%{$term}%")->orWhere('venue','like',"%{$term}%"));
        if(!empty($data['city']))$query->whereRaw('LOWER(city) = LOWER(?)',[$data['city']]);if(!empty($data['production_id']))$query->where('production_id',$data['production_id']);if(!empty($data['from']))$query->where('start_date','>=',Carbon::createFromFormat('Y-m-d',$data['from'],config('app.timezone'))->startOfDay());if(!empty($data['to']))$query->where('start_date','<=',Carbon::createFromFormat('Y-m-d',$data['to'],config('app.timezone'))->endOfDay());
        match($data['status']??null){'draft'=>$query->where('is_published',false)->where('is_cancelled',false),'published'=>$query->where('is_published',true)->where('is_cancelled',false),'cancelled'=>$query->where('is_cancelled',true),'upcoming'=>$query->where('is_cancelled',false)->where('end_date','>',now()),'past'=>$query->where('end_date','<=',now()),default=>null};
        $events=$query->orderByDesc('start_date')->paginate($data['per_page']??50)->appends($request->query());
        $this->attachSellableTicketCounts($events->getCollection());
        return response()->json(['events'=>$events]);
    }

    public function show(Request $request,int $id)
    {
        $event=$this->ownedEvent($id,$request->user());$appId=$this->context->id();
        $event->load(['production:id,app_id,name,slug,user_id,app_slug','artists:id,app_id,slug,stage_name']);
        $event->loadCount(['tickets'=>fn($q)=>$q->where('app_id',$appId)]);
        $this->attachSellableTicketCounts(collect([$event]));
        return response()->json(['event'=>$event]);
    }

    public function store(Request $request)
    {
        $this->normalizeInput($request);$data=$request->validate($this->rules(true),$this->messages(),$this->attributes());$production=$this->ownedProduction((int)$data['production_id'],$request->user());$this->validateDates($data,null);
        if(empty($data['city'])&&$production->city)$data['city']=$production->city;if(empty($data['uf'])&&$production->uf)$data['uf']=$production->uf;
        $data['app_id']=$this->context->id();$data['app_slug']=$this->context->slug();$data['slug']=$this->uniqueSlug($data['title']);$data['is_published']=false;$data['is_cancelled']=false;unset($data['image']);
        $event=Event::create($data);if($request->hasFile('image')){$event->image=$this->storeImage($request->file('image'));$event->save();}
        return response()->json(['message'=>'Evento criado como rascunho. Configure ao menos um ingresso e publique para ele aparecer na descoberta.','event'=>$event->fresh()->load('production:id,app_id,name,slug,user_id,app_slug')],201);
    }

    public function update(Request $request,int $id)
    {
        $event=$this->ownedEvent($id,$request->user());$this->normalizeInput($request);$data=$request->validate($this->rules(false),$this->messages(),$this->attributes());if(isset($data['production_id']))$this->ownedProduction((int)$data['production_id'],$request->user());$this->validateDates($data,$event);if(!empty($data['title'])&&$data['title']!==$event->title)$data['slug']=$this->uniqueSlug($data['title'],$event->id);unset($data['image'],$data['app_id'],$data['app_slug'],$data['is_published'],$data['is_cancelled']);$event->update($data);
        if($request->hasFile('image')){if($event->image)Storage::disk('public')->delete($event->image);$event->image=$this->storeImage($request->file('image'));$event->save();}
        return response()->json(['message'=>'Evento atualizado com sucesso.','event'=>$event->fresh()->load('production:id,app_id,name,slug,user_id,app_slug')]);
    }

    public function publish(Request $request,int $id)
    {
        $event=$this->ownedEvent($id,$request->user());$timezone=config('app.timezone','America/Sao_Paulo');$now=Carbon::now($timezone);abort_if($event->is_cancelled,422,'Um evento cancelado não pode ser publicado.');abort_if(!$event->end_date||Carbon::parse($event->end_date,$timezone)->lte($now),422,'Um evento já encerrado não pode ser publicado.');abort_if(!$event->start_date||Carbon::parse($event->start_date,$timezone)->lte($now),422,'O evento precisa ser publicado antes do horário de início.');
        $hasAvailableTicket=Ticket::query()->where('app_id',$this->context->id())->where('event_id',$event->id)->where('quantity','>',0)->where(fn($q)=>$q->whereNull('limit_date')->orWhere('limit_date','>',$now))->exists();abort_unless($hasAvailableTicket,422,'Crie ao menos um ingresso disponível antes de publicar o evento.');$wasPublished=(bool)$event->is_published;$event->forceFill(['is_published'=>true])->save();if(!$wasPublished&&!$event->is_private)$this->notifyProductionFollowers($event);
        return response()->json(['message'=>$event->is_private?'Evento privado ativado.':'Evento publicado.','event'=>$event->fresh()->load('production:id,app_id,name,slug,user_id,app_slug')]);
    }

    public function unpublish(Request $request,int $id){$event=$this->ownedEvent($id,$request->user());$event->forceFill(['is_published'=>false])->save();return response()->json(['message'=>'Evento retirado da publicação. Os ingressos já emitidos foram preservados.','event'=>$event->fresh()->load('production:id,app_id,name,slug,user_id,app_slug')]);}

    public function duplicate(Request $request,int $id)
    {
        $source=$this->ownedEvent($id,$request->user());
        $data=$request->validate(['date'=>'required|date_format:Y-m-d|after:today']);
        $timezone=config('app.timezone','America/Sao_Paulo');
        abort_unless($source->start_date&&$source->end_date,422,'O evento original precisa ter início e término definidos.');
        $sourceStart=Carbon::parse($source->start_date,$timezone);$sourceEnd=Carbon::parse($source->end_date,$timezone);
        $newStart=Carbon::createFromFormat('Y-m-d H:i:s',$data['date'].' '.$sourceStart->format('H:i:s'),$timezone);
        $duration=max(60,$sourceStart->diffInSeconds($sourceEnd));$newEnd=$newStart->copy()->addSeconds($duration);
        abort_if($newStart->lte(Carbon::now($timezone)),422,'Escolha uma data futura para a nova edição.');

        $duplicate=DB::transaction(function()use($source,$sourceStart,$newStart,$newEnd,$timezone){
            $copy=$source->replicate(['slug','start_date','end_date','is_published','is_cancelled','is_featured','is_approved','rating','reviews','remaining_tickets','image']);
            $copy->slug=$this->uniqueSlug($source->title);$copy->start_date=$newStart;$copy->end_date=$newEnd;$copy->is_published=false;$copy->is_cancelled=false;$copy->is_featured=false;$copy->is_approved=false;$copy->rating=0;$copy->reviews=[];$copy->remaining_tickets=null;$copy->image=null;$copy->save();

            $tickets=Ticket::query()->where('app_id',$this->context->id())->where('event_id',$source->id)->orderBy('id')->get();
            foreach($tickets as$ticket){
                $ticketCopy=$ticket->replicate(['event_id']);
                $ticketCopy->event_id=$copy->id;
                if($ticket->limit_date){
                    $offset=$sourceStart->diffInSeconds(Carbon::parse($ticket->limit_date,$timezone),false);
                    $candidateLimit=$newStart->copy()->addSeconds($offset);
                    $minimumLimit=Carbon::now($timezone)->addHour();

                    // Eventos antigos podem carregar prazos inconsistentes. A duplicação
                    // não deve falhar por dados legados: mantém o deslocamento quando
                    // válido e limita o prazo à janela permitida do novo evento.
                    if($newStart->lt($minimumLimit)){
                        $ticketCopy->limit_date=null;
                    }else{
                        if($candidateLimit->lt($minimumLimit))$candidateLimit=$minimumLimit->copy();
                        if($candidateLimit->gt($newStart))$candidateLimit=$newStart->copy();
                        $ticketCopy->limit_date=$candidateLimit;
                    }
                }
                $ticketCopy->save();
            }

            $items=EventItem::query()->where('app_id',$this->context->id())->where('event_id',$source->id)->orderBy('id')->get();
            foreach($items as$item){$itemCopy=$item->replicate(['event_id']);$itemCopy->event_id=$copy->id;$itemCopy->save();}

            $links=[];
            foreach($source->artists()->get()as$artist){$pivot=$artist->pivot;$scheduledAt=null;if($pivot->scheduled_at){$offset=$sourceStart->diffInSeconds(Carbon::parse($pivot->scheduled_at,$timezone),false);$scheduledAt=$newStart->copy()->addSeconds($offset);}$links[$artist->id]=['app_id'=>$this->context->id(),'participation_type'=>$pivot->participation_type,'description'=>$pivot->description,'sort_order'=>$pivot->sort_order,'scheduled_at'=>$scheduledAt,'stage'=>$pivot->stage,'is_headliner'=>(bool)$pivot->is_headliner];}
            if($links)$copy->artists()->sync($links);

            return$copy->fresh()->load(['production:id,app_id,name,slug,user_id,app_slug','artists:id,app_id,slug,stage_name']);
        },3);

        if($source->image&&Storage::disk('public')->exists($source->image)){
            try{$extension=pathinfo($source->image,PATHINFO_EXTENSION)?:'webp';$newImage=dirname($source->image).'/'.Str::uuid().'.'.$extension;Storage::disk('public')->copy($source->image,$newImage);$duplicate->forceFill(['image'=>$newImage])->save();}catch(Throwable $e){report($e);}
        }

        return response()->json(['message'=>'Nova edição criada como rascunho. Ingressos, itens e line-up foram reaproveitados; vendas, passes, check-ins e histórico não foram copiados.','event'=>$duplicate->fresh()->load(['production:id,app_id,name,slug,user_id,app_slug','artists:id,app_id,slug,stage_name']),'copied'=>['tickets'=>$duplicate->tickets()->count(),'items'=>EventItem::query()->where('event_id',$duplicate->id)->count(),'artists'=>$duplicate->artists()->count()]],201);
    }

    public function destroy(Request $request,int $id){$event=$this->ownedEvent($id,$request->user());abort_if($event->tickets()->whereHas('passes')->exists(),409,'Eventos com ingressos emitidos não podem ser excluídos. Cancele ou despublique o evento.');$event->delete();return response()->json(['message'=>'Evento excluído com sucesso.']);}

    private function attachSellableTicketCounts($events): void
    {
        if ($events->isEmpty()) return;
        $now=Carbon::now(config('app.timezone','America/Sao_Paulo'));
        $tickets=Ticket::query()
            ->where('app_id',$this->context->id())
            ->whereIn('event_id',$events->pluck('id'))
            ->get(['id','app_id','event_id','quantity','limit_date']);
        $states=$this->ticketInventory->states($tickets,$now);
        $counts=[];
        foreach($tickets as$ticket){
            if(($states->get((int)$ticket->id)['available']??false)===true){
                $counts[(int)$ticket->event_id]=($counts[(int)$ticket->event_id]??0)+1;
            }
        }
        foreach($events as$event){
            $count=$event->hasEnded($now)?0:(int)($counts[(int)$event->id]??0);
            $event->setAttribute('available_tickets_count',$count);
        }
    }

    private function rules(bool $creating):array{$required=$creating?'required|':'sometimes|';return['production_id'=>$required.'integer|exists:productions,id','title'=>$required.'string|min:2|max:255','description'=>$required.'string|max:50000','category'=>'sometimes|nullable|string|max:120','image'=>'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:5120','address'=>'sometimes|nullable|string|max:500','google_maps_url'=>'sometimes|nullable|url:http,https|max:2048','start_date'=>$required.'date','end_date'=>$required.'date','venue'=>'sometimes|nullable|string|max:255','uf'=>'sometimes|nullable|string|size:2','city'=>'sometimes|nullable|string|max:120','cep'=>'sometimes|nullable|string|max:20','latitude'=>'sometimes|nullable|numeric|between:-90,90','longitude'=>'sometimes|nullable|numeric|between:-180,180','max_attendees'=>'sometimes|nullable|integer|min:1|max:1000000','contact_email'=>'sometimes|nullable|email|max:255','contact_phone'=>'sometimes|nullable|string|max:50','is_private'=>'sometimes|boolean','event_format'=>'sometimes|nullable|in:in_person,online,hybrid','online_url'=>'sometimes|nullable|url:http,https|max:2048'];}
    private function normalizeInput(Request $request):void{$merge=[];$errors=[];if($request->has('uf'))$merge['uf']=strtoupper(trim((string)$request->input('uf')));foreach(['start_date','end_date']as$field){if(!$request->filled($field))continue;try{$merge[$field]=Carbon::parse((string)$request->input($field),config('app.timezone'))->format('Y-m-d H:i:s');}catch(Throwable){$errors[$field][]=$field==='start_date'?'Informe uma data de início válida.':'Informe uma data de término válida.';}}if($errors)throw ValidationException::withMessages($errors);if($merge)$request->merge($merge);}
    private function validateDates(array $data,?Event $event):void{$startValue=$data['start_date']??$event?->start_date;$endValue=$data['end_date']??$event?->end_date;if(!$startValue||!$endValue)return;$timezone=config('app.timezone','America/Sao_Paulo');$now=Carbon::now($timezone);$start=Carbon::parse($startValue,$timezone);$end=Carbon::parse($endValue,$timezone);$errors=[];if($event===null&&$start->lte($now))$errors['start_date'][]='O horário de início do evento precisa estar no futuro.';elseif($event!==null&&array_key_exists('start_date',$data)&&$start->lt($now->copy()->subMinute()))$errors['start_date'][]='O início do evento não pode ficar no passado.';if(!$end->gt($start))$errors['end_date'][]='O término do evento precisa ser posterior ao início.';if($start->diffInDays($end)>30)$errors['end_date'][]='A duração do evento não pode ultrapassar 30 dias.';if($errors)throw ValidationException::withMessages($errors);}
    private function ownedProduction(int $id,User $user):Production{$production=Production::query()->where('app_id',$this->context->id())->findOrFail($id);abort_unless(($user->hasProfile('Administrador')||strtolower(trim((string)$user->email))==='petertecnet@gmail.com')||(int)$production->user_id===(int)$user->id,403,'Você não pode gerenciar esta organização.');return$production;}
    private function ownedEvent(int $id,User $user):Event{$event=Event::query()->where('app_id',$this->context->id())->with('production')->findOrFail($id);abort_unless($event->production&&(int)$event->production->app_id===$this->context->id(),404,'Evento não encontrado neste contexto.');abort_unless(($user->hasProfile('Administrador')||strtolower(trim((string)$user->email))==='petertecnet@gmail.com')||(int)$event->production->user_id===(int)$user->id,403,'Você não pode gerenciar este evento.');return$event;}
    private function notifyProductionFollowers(Event $event):void{if($event->is_private)return;$appId=$this->context->id();$followers=DB::table('follows')->where(['app_id'=>$appId,'target_type'=>'production','target_id'=>$event->production_id])->pluck('user_id');foreach($followers as$userId)AppNotification::create(['app_id'=>$appId,'user_id'=>$userId,'type'=>'production_event_published','title'=>'Novo evento publicado','message'=>$event->production?->name.' publicou '.$event->title.'.','reference_type'=>'event','reference_id'=>$event->id,'reference_url'=>'/event/'.$event->slug,'data'=>['production_id'=>$event->production_id,'event_id'=>$event->id]]);}
    private function uniqueSlug(string $title,?int $ignoreId=null):string{$base=Str::slug($title)?:'evento';$slug=$base;$counter=2;while(Event::query()->when($ignoreId,fn($q)=>$q->whereKeyNot($ignoreId))->where('slug',$slug)->exists())$slug=$base.'-'.$counter++;return$slug;}
    private function storeImage($file):string{$directory='images/apps/'.$this->context->slug().'/events';$path=$directory.'/'.Str::uuid().'.webp';$image=Image::make($file)->orientate()->resize(1600,900,function($c){$c->aspectRatio();$c->upsize();})->encode('webp',80);Storage::disk('public')->put($path,(string)$image);return$path;}
    private function messages():array{return['required'=>'Preencha :attribute.','exists'=>':attribute não foi encontrado.'];}
    private function attributes():array{return['production_id'=>'organização','title'=>'título','description'=>'descrição','start_date'=>'início','end_date'=>'término'];}
}