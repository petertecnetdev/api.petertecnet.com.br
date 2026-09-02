<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Ticket;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class EventDiscoveryController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function events(Request $request)
    {
        $this->context->requireCapability('events');
        $data = $request->validate([
            'q'=>'nullable|string|max:120','city'=>'nullable|string|max:120','uf'=>'nullable|string|size:2',
            'category'=>'nullable|string|max:120','artist_id'=>'nullable|integer|min:1','production_id'=>'nullable|integer|min:1',
            'from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d|after_or_equal:from',
            'free'=>'nullable|boolean','available'=>'nullable|boolean','sort'=>'nullable|in:soonest,newest,popular',
            'per_page'=>'nullable|integer|min:1|max:50',
        ]);

        $appId = $this->context->id();
        $appSlug = $this->context->slug();
        $now = Carbon::now(config('app.timezone','America/Sao_Paulo'));
        $query = Event::query()
            ->where('events.app_id',$appId)->where('events.app_slug',$appSlug)
            ->where('events.is_published',true)->where('events.is_cancelled',false)
            ->where(fn($q)=>$q->where('events.is_private',false)->orWhereNull('events.is_private'))
            ->where('events.end_date','>',$now)
            ->with(['production:id,app_id,name,slug,user_id,app_slug,logo,city,uf','artists:id,app_id,slug,stage_name,photo'])
            ->withCount(['tickets as ticket_lots_count'=>fn($q)=>$q->where('app_id',$appId)]);

        if ($term = trim((string)($data['q'] ?? ''))) {
            $like = "%{$term}%";
            $query->where(fn($q)=>$q->where('events.title','like',$like)->orWhere('events.city','like',$like)
                ->orWhere('events.venue','like',$like)->orWhere('events.category','like',$like)
                ->orWhereHas('production',fn($p)=>$p->where('name','like',$like))
                ->orWhereHas('artists',fn($a)=>$a->where('stage_name','like',$like)));
        }
        if (!empty($data['city'])) $query->whereRaw('LOWER(events.city)=LOWER(?)',[trim($data['city'])]);
        if (!empty($data['uf'])) $query->where('events.uf',strtoupper($data['uf']));
        if (!empty($data['category'])) $query->where('events.category',$data['category']);
        if (!empty($data['production_id'])) $query->where('events.production_id',(int)$data['production_id']);
        if (!empty($data['artist_id'])) $query->whereHas('artists',fn($q)=>$q->where('cutinapp_artists.id',(int)$data['artist_id']));
        if (!empty($data['from'])) $query->where('events.start_date','>=',Carbon::createFromFormat('Y-m-d',$data['from'])->startOfDay());
        if (!empty($data['to'])) $query->where('events.start_date','<=',Carbon::createFromFormat('Y-m-d',$data['to'])->endOfDay());
        if (($data['free'] ?? false) || ($data['available'] ?? false)) {
            $query->whereHas('tickets', function($q) use($appId,$data,$now) {
                $q->where('tickets.app_id',$appId)->where('tickets.quantity','>',0)
                    ->where(fn($d)=>$d->whereNull('tickets.limit_date')->orWhere('tickets.limit_date','>',$now));
                if ($data['free'] ?? false) $q->where('tickets.price',0);
            });
        }
        match ($data['sort'] ?? 'soonest') {
            'newest' => $query->orderByDesc('events.created_at'),
            'popular' => $query->withCount('passes')->orderByDesc('passes_count')->orderBy('events.start_date'),
            default => $query->orderBy('events.start_date'),
        };
        return response()->json(['events'=>$query->paginate($data['per_page'] ?? 24)->appends($request->query())]);
    }

    public function publicEvent(Request $request, string $slug)
    {
        $this->context->requireCapability('events');
        $appId=$this->context->id(); $appSlug=$this->context->slug();
        $event=Event::query()->where('app_id',$appId)->where('app_slug',$appSlug)->where('slug',$slug)
            ->where('is_published',true)->where('is_cancelled',false)
            ->where(fn($q)=>$q->where('is_private',false)->orWhereNull('is_private'))
            ->with(['production','artists'])->firstOrFail();
        $tickets=Ticket::query()->where('app_id',$appId)->where('event_id',$event->id)->where('price',0)
            ->withCount('passes')->orderBy('created_at')->get()->map(function(Ticket $ticket){
                $remaining=max(0,(int)$ticket->quantity-(int)$ticket->passes_count);
                $expired=$ticket->limit_date && now()->greaterThan($ticket->limit_date);
                $ticket->setAttribute('remaining',$remaining);
                $ticket->setAttribute('available',$remaining>0&&!$expired);
                return $ticket;
            });
        return response()->json(['event'=>$event,'tickets'=>$tickets]);
    }

    public function facets()
    {
        $this->context->requireCapability('events');
        $base=Event::query()->where('app_id',$this->context->id())->where('app_slug',$this->context->slug())
            ->where('is_published',true)->where('is_cancelled',false)->where('end_date','>',now());
        return response()->json([
            'cities'=>(clone $base)->whereNotNull('city')->selectRaw('city, uf, COUNT(*) total')->groupBy('city','uf')->orderByDesc('total')->limit(100)->get(),
            'categories'=>(clone $base)->whereNotNull('category')->selectRaw('category, COUNT(*) total')->groupBy('category')->orderByDesc('total')->limit(50)->get(),
        ]);
    }
}
