<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Item;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CutinappController extends Controller
{
    private function eventOrFail(int $eventId): Event
    {
        return Event::with('production')->findOrFail($eventId);
    }

    private function canManage(Event $event, int $userId): bool
    {
        if ((int) optional($event->production)->user_id === $userId) return true;

        return DB::table('cutinapp_event_members')
            ->where('event_id', $event->id)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->whereIn('role', ['producer', 'manager'])
            ->exists();
    }

    private function guardManage(Event $event): void
    {
        abort_unless(auth()->check() && $this->canManage($event, (int) auth()->id()), 403, 'Você não pode gerenciar este evento.');
    }

    private function canCheckin(Event $event, int $userId): bool
    {
        if ($this->canManage($event, $userId)) return true;
        return DB::table('cutinapp_event_members')
            ->where('event_id', $event->id)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->whereIn('role', ['checker', 'collaborator'])
            ->exists();
    }

    public function discover(Request $request)
    {
        $query = Event::query()
            ->with(['production:id,name,slug,logo'])
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->where('end_date', '>=', now())
            ->orderByDesc('is_featured')
            ->orderBy('start_date');

        if ($request->filled('city')) $query->where('city', 'like', '%' . $request->string('city') . '%');
        if ($request->filled('q')) {
            $q = $request->string('q');
            $query->where(fn ($x) => $x->where('title', 'like', "%{$q}%")->orWhere('description', 'like', "%{$q}%")->orWhere('venue', 'like', "%{$q}%"));
        }

        return response()->json($query->paginate(min((int) $request->get('per_page', 24), 50)));
    }

    public function eventPublic(int $eventId)
    {
        $event = $this->eventOrFail($eventId);
        abort_if(!$event->is_published || $event->is_cancelled, 404);

        $tickets = Ticket::where('event_id', $eventId)->orderBy('price')->get();
        $products = Item::where('entity_name', 'event')->where('entity_id', $eventId)->where('status', true)->get();
        $promotions = DB::table('cutinapp_promotions')->where('event_id', $eventId)->where('active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->get(['id','name','code','discount_type','discount_value','minimum_amount','usage_limit','used_count','ends_at']);

        return response()->json(compact('event', 'tickets', 'products', 'promotions'));
    }

    public function dashboard(int $eventId)
    {
        $event = $this->eventOrFail($eventId);
        $this->guardManage($event);

        $sales = DB::table('cutinapp_sales')->where('event_id', $eventId);
        $paid = (clone $sales)->where('payment_status', 'paid');

        return response()->json([
            'event' => $event,
            'metrics' => [
                'sales_count' => (clone $paid)->count(),
                'revenue' => (float) (clone $paid)->sum('total'),
                'commissions' => (float) (clone $paid)->sum('commission_total'),
                'tickets_issued' => DB::table('cutinapp_admissions')->where('event_id', $eventId)->count(),
                'checkins' => DB::table('cutinapp_admissions')->where('event_id', $eventId)->where('status', 'used')->count(),
                'members' => DB::table('cutinapp_event_members')->where('event_id', $eventId)->where('status', 'active')->count(),
                'promoters' => DB::table('cutinapp_promoters')->where('event_id', $eventId)->where('active', true)->count(),
            ],
            'recent_sales' => DB::table('cutinapp_sales')->where('event_id', $eventId)->latest()->limit(15)->get(),
            'promoter_ranking' => DB::table('cutinapp_promoters as p')
                ->leftJoin('cutinapp_sales as s', function ($j) { $j->on('s.promoter_id', '=', 'p.id')->where('s.payment_status', '=', 'paid'); })
                ->where('p.event_id', $eventId)
                ->groupBy('p.id','p.code','p.commission_type','p.commission_value')
                ->selectRaw('p.id,p.code,p.commission_type,p.commission_value,COUNT(s.id) sales_count,COALESCE(SUM(s.total),0) revenue,COALESCE(SUM(s.commission_total),0) commissions')
                ->orderByDesc('revenue')->get(),
        ]);
    }

    public function members(int $eventId)
    {
        $event = $this->eventOrFail($eventId); $this->guardManage($event);
        return response()->json(DB::table('cutinapp_event_members')->where('event_id', $eventId)->orderBy('role')->orderBy('name')->get());
    }

    public function storeMember(Request $request, int $eventId)
    {
        $event = $this->eventOrFail($eventId); $this->guardManage($event);
        $data = $request->validate([
            'user_id' => 'nullable|integer|exists:users,id', 'name' => 'required|string|max:120', 'email' => 'nullable|email|max:190',
            'phone' => 'nullable|string|max:30', 'role' => ['required', Rule::in(['producer','manager','promoter','supplier','collaborator','checker'])],
            'permissions' => 'nullable|array', 'notes' => 'nullable|string|max:2000',
        ]);
        $id = DB::table('cutinapp_event_members')->insertGetId(array_merge($data, ['event_id'=>$eventId,'status'=>'active','permissions'=>isset($data['permissions'])?json_encode($data['permissions']):null,'created_at'=>now(),'updated_at'=>now()]));
        return response()->json(DB::table('cutinapp_event_members')->find($id), 201);
    }

    public function updateMember(Request $request, int $eventId, int $memberId)
    {
        $event = $this->eventOrFail($eventId); $this->guardManage($event);
        $data = $request->validate([
            'name'=>'sometimes|string|max:120','email'=>'nullable|email|max:190','phone'=>'nullable|string|max:30',
            'role'=>['sometimes',Rule::in(['producer','manager','promoter','supplier','collaborator','checker'])],
            'status'=>['sometimes',Rule::in(['invited','active','suspended','removed'])], 'permissions'=>'nullable|array','notes'=>'nullable|string|max:2000',
        ]);
        if (array_key_exists('permissions',$data)) $data['permissions'] = json_encode($data['permissions']);
        $data['updated_at']=now();
        DB::table('cutinapp_event_members')->where('event_id',$eventId)->where('id',$memberId)->update($data);
        return response()->json(DB::table('cutinapp_event_members')->where('event_id',$eventId)->find($memberId));
    }

    public function deleteMember(int $eventId, int $memberId)
    {
        $event = $this->eventOrFail($eventId); $this->guardManage($event);
        DB::table('cutinapp_event_members')->where('event_id',$eventId)->where('id',$memberId)->update(['status'=>'removed','updated_at'=>now()]);
        return response()->json(['message'=>'Membro removido.']);
    }

    public function promoters(int $eventId)
    {
        $event=$this->eventOrFail($eventId); $this->guardManage($event);
        return response()->json(DB::table('cutinapp_promoters as p')->leftJoin('cutinapp_event_members as m','m.id','=','p.member_id')->where('p.event_id',$eventId)->select('p.*','m.name','m.email','m.phone')->get());
    }

    public function storePromoter(Request $request, int $eventId)
    {
        $event=$this->eventOrFail($eventId); $this->guardManage($event);
        $data=$request->validate(['member_id'=>'nullable|integer|exists:cutinapp_event_members,id','user_id'=>'nullable|integer|exists:users,id','commission_type'=>['required',Rule::in(['percentage','fixed'])],'commission_value'=>'required|numeric|min:0','starts_at'=>'nullable|date','ends_at'=>'nullable|date|after:starts_at']);
        $code=strtoupper(Str::random(10));
        $id=DB::table('cutinapp_promoters')->insertGetId(array_merge($data,['event_id'=>$eventId,'code'=>$code,'active'=>true,'created_at'=>now(),'updated_at'=>now()]));
        return response()->json(DB::table('cutinapp_promoters')->find($id),201);
    }

    public function updatePromoter(Request $request, int $eventId, int $promoterId)
    {
        $event=$this->eventOrFail($eventId); $this->guardManage($event);
        $data=$request->validate(['commission_type'=>['sometimes',Rule::in(['percentage','fixed'])],'commission_value'=>'sometimes|numeric|min:0','active'=>'sometimes|boolean','starts_at'=>'nullable|date','ends_at'=>'nullable|date']);
        $data['updated_at']=now(); DB::table('cutinapp_promoters')->where('event_id',$eventId)->where('id',$promoterId)->update($data);
        return response()->json(DB::table('cutinapp_promoters')->where('event_id',$eventId)->find($promoterId));
    }

    public function promoterStats(int $eventId, int $promoterId)
    {
        $event=$this->eventOrFail($eventId);
        $promoter=DB::table('cutinapp_promoters')->where('event_id',$eventId)->find($promoterId); abort_unless($promoter,404);
        $isOwner=(int)$promoter->user_id===(int)auth()->id(); abort_unless($isOwner || $this->canManage($event,(int)auth()->id()),403);
        $sales=DB::table('cutinapp_sales')->where('promoter_id',$promoterId)->where('payment_status','paid');
        return response()->json(['promoter'=>$promoter,'sales_count'=>(clone $sales)->count(),'revenue'=>(float)(clone $sales)->sum('total'),'commission'=>(float)(clone $sales)->sum('commission_total'),'ledger'=>DB::table('cutinapp_commission_ledger')->where('promoter_id',$promoterId)->latest()->get()]);
    }

    public function promotions(int $eventId)
    {
        $event=$this->eventOrFail($eventId); $this->guardManage($event);
        return response()->json(DB::table('cutinapp_promotions')->where('event_id',$eventId)->latest()->get());
    }

    public function storePromotion(Request $request, int $eventId)
    {
        $event=$this->eventOrFail($eventId); $this->guardManage($event);
        $data=$request->validate(['name'=>'required|string|max:120','code'=>'nullable|string|max:60','discount_type'=>['required',Rule::in(['percentage','fixed'])],'discount_value'=>'required|numeric|min:0','minimum_amount'=>'nullable|numeric|min:0','usage_limit'=>'nullable|integer|min:1','active'=>'sometimes|boolean','starts_at'=>'nullable|date','ends_at'=>'nullable|date|after:starts_at']);
        if (!empty($data['code'])) $data['code']=strtoupper(trim($data['code']));
        $id=DB::table('cutinapp_promotions')->insertGetId(array_merge($data,['event_id'=>$eventId,'used_count'=>0,'active'=>$data['active']??true,'created_at'=>now(),'updated_at'=>now()]));
        return response()->json(DB::table('cutinapp_promotions')->find($id),201);
    }

    public function updatePromotion(Request $request, int $eventId, int $promotionId)
    {
        $event=$this->eventOrFail($eventId); $this->guardManage($event);
        $data=$request->validate(['name'=>'sometimes|string|max:120','code'=>'nullable|string|max:60','discount_type'=>['sometimes',Rule::in(['percentage','fixed'])],'discount_value'=>'sometimes|numeric|min:0','minimum_amount'=>'nullable|numeric|min:0','usage_limit'=>'nullable|integer|min:1','active'=>'sometimes|boolean','starts_at'=>'nullable|date','ends_at'=>'nullable|date']);
        if (isset($data['code'])) $data['code']=strtoupper(trim($data['code'])); $data['updated_at']=now();
        DB::table('cutinapp_promotions')->where('event_id',$eventId)->where('id',$promotionId)->update($data);
        return response()->json(DB::table('cutinapp_promotions')->where('event_id',$eventId)->find($promotionId));
    }

    public function checkout(Request $request, int $eventId)
    {
        $event=$this->eventOrFail($eventId); abort_if($event->is_cancelled || !$event->is_published,422,'Evento indisponível.');
        $data=$request->validate([
            'buyer_name'=>'required|string|max:120','buyer_email'=>'required|email|max:190','buyer_document'=>'nullable|string|max:40','buyer_phone'=>'nullable|string|max:30',
            'payment_method'=>['required',Rule::in(['pix','credit_card','debit_card','cash','free','external'])], 'promoter_code'=>'nullable|string|max:40','promotion_code'=>'nullable|string|max:60',
            'items'=>'required|array|min:1','items.*.type'=>['required',Rule::in(['ticket','product'])],'items.*.id'=>'required|integer','items.*.quantity'=>'required|integer|min:1|max:20',
        ]);

        return DB::transaction(function () use ($data,$event,$eventId) {
            $subtotal=0; $normalized=[];
            foreach ($data['items'] as $line) {
                if ($line['type']==='ticket') {
                    $ref=Ticket::where('event_id',$eventId)->lockForUpdate()->findOrFail($line['id']);
                    abort_if($ref->limit_date && now()->gt($ref->limit_date),422,"O lote {$ref->name} encerrou.");
                    abort_if((int)$ref->quantity < (int)$line['quantity'],422,"Quantidade indisponível para {$ref->name}.");
                } else {
                    $ref=Item::where('entity_name','event')->where('entity_id',$eventId)->where('status',true)->lockForUpdate()->findOrFail($line['id']);
                    abort_if($ref->stock !== null && (int)$ref->stock < (int)$line['quantity'],422,"Estoque indisponível para {$ref->name}.");
                }
                $unit=(float)$ref->price; $total=$unit*(int)$line['quantity']; $subtotal+=$total;
                $normalized[]=['type'=>$line['type'],'id'=>$ref->id,'name'=>$ref->name,'quantity'=>(int)$line['quantity'],'unit_price'=>$unit,'total_price'=>$total];
            }

            $promotion=null; $discount=0;
            if (!empty($data['promotion_code'])) {
                $promotion=DB::table('cutinapp_promotions')->where('event_id',$eventId)->where('code',strtoupper(trim($data['promotion_code'])))->where('active',true)->lockForUpdate()->first();
                abort_unless($promotion,422,'Cupom inválido.');
                abort_if($promotion->starts_at && now()->lt($promotion->starts_at),422,'Cupom ainda não está ativo.');
                abort_if($promotion->ends_at && now()->gt($promotion->ends_at),422,'Cupom expirado.');
                abort_if($promotion->usage_limit && $promotion->used_count >= $promotion->usage_limit,422,'Limite do cupom atingido.');
                abort_if($promotion->minimum_amount && $subtotal < $promotion->minimum_amount,422,'Valor mínimo do cupom não atingido.');
                $discount=$promotion->discount_type==='percentage' ? $subtotal*min((float)$promotion->discount_value,100)/100 : min((float)$promotion->discount_value,$subtotal);
            }

            $promoter=null; $commission=0;
            if (!empty($data['promoter_code'])) {
                $promoter=DB::table('cutinapp_promoters')->where('event_id',$eventId)->where('code',strtoupper(trim($data['promoter_code'])))->where('active',true)->first();
                if ($promoter && (!$promoter->starts_at || now()->gte($promoter->starts_at)) && (!$promoter->ends_at || now()->lte($promoter->ends_at))) {
                    $base=max($subtotal-$discount,0); $commission=$promoter->commission_type==='percentage' ? $base*min((float)$promoter->commission_value,100)/100 : min((float)$promoter->commission_value,$base);
                } else $promoter=null;
            }

            $total=max($subtotal-$discount,0); $autoPaid=$total<=0 || $data['payment_method']==='free';
            $saleId=DB::table('cutinapp_sales')->insertGetId([
                'public_id'=>(string)Str::uuid(),'event_id'=>$eventId,'buyer_user_id'=>auth()->id(),'promoter_id'=>$promoter->id??null,'promotion_id'=>$promotion->id??null,
                'buyer_name'=>$data['buyer_name'],'buyer_email'=>$data['buyer_email'],'buyer_document'=>$data['buyer_document']??null,'buyer_phone'=>$data['buyer_phone']??null,
                'subtotal'=>$subtotal,'discount'=>$discount,'fee'=>0,'total'=>$total,'commission_total'=>$commission,'payment_method'=>$data['payment_method'],'payment_status'=>$autoPaid?'paid':'pending','paid_at'=>$autoPaid?now():null,'created_at'=>now(),'updated_at'=>now(),
            ]);

            foreach ($normalized as $line) DB::table('cutinapp_sale_items')->insert(['sale_id'=>$saleId,'item_type'=>$line['type'],'reference_id'=>$line['id'],'name'=>$line['name'],'quantity'=>$line['quantity'],'unit_price'=>$line['unit_price'],'total_price'=>$line['total_price'],'commission_amount'=>$subtotal>0?$commission*($line['total_price']/$subtotal):0,'created_at'=>now(),'updated_at'=>now()]);
            if ($autoPaid) $this->fulfillSale($saleId);
            return response()->json(['sale'=>DB::table('cutinapp_sales')->find($saleId),'message'=>$autoPaid?'Compra confirmada.':'Pedido criado. Aguardando confirmação do pagamento.'],201);
        });
    }

    private function fulfillSale(int $saleId): void
    {
        $sale=DB::table('cutinapp_sales')->lockForUpdate()->find($saleId); if (!$sale || $sale->payment_status!=='paid') return;
        $items=DB::table('cutinapp_sale_items')->where('sale_id',$saleId)->get();
        $hasAdmissions=DB::table('cutinapp_admissions')->where('sale_id',$saleId)->exists();
        if (!$hasAdmissions) {
            foreach ($items as $line) {
                if ($line->item_type==='ticket') {
                    $ticket=Ticket::lockForUpdate()->findOrFail($line->reference_id); abort_if($ticket->quantity < $line->quantity,409,'Ingressos esgotados durante a confirmação.');
                    $ticket->decrement('quantity',$line->quantity);
                    for ($i=0;$i<$line->quantity;$i++) DB::table('cutinapp_admissions')->insert(['token'=>(string)Str::uuid(),'sale_id'=>$saleId,'sale_item_id'=>$line->id,'event_id'=>$sale->event_id,'ticket_id'=>$line->reference_id,'owner_user_id'=>$sale->buyer_user_id,'holder_name'=>$sale->buyer_name,'holder_email'=>$sale->buyer_email,'status'=>'valid','created_at'=>now(),'updated_at'=>now()]);
                } else {
                    $item=Item::lockForUpdate()->findOrFail($line->reference_id); if ($item->stock!==null) { abort_if($item->stock < $line->quantity,409,'Produto sem estoque durante a confirmação.'); $item->decrement('stock',$line->quantity); }
                }
            }
            if ($sale->promotion_id) DB::table('cutinapp_promotions')->where('id',$sale->promotion_id)->increment('used_count');
            if ($sale->promoter_id && $sale->commission_total>0) DB::table('cutinapp_commission_ledger')->updateOrInsert(['promoter_id'=>$sale->promoter_id,'sale_id'=>$saleId],['amount'=>$sale->commission_total,'status'=>'available','available_at'=>now(),'updated_at'=>now(),'created_at'=>now()]);
        }
    }

    public function confirmPayment(Request $request, int $eventId, int $saleId)
    {
        $event=$this->eventOrFail($eventId); $this->guardManage($event);
        $data=$request->validate(['payment_reference'=>'nullable|string|max:190']);
        DB::transaction(function () use ($saleId,$eventId,$data) {
            $sale=DB::table('cutinapp_sales')->where('event_id',$eventId)->lockForUpdate()->find($saleId); abort_unless($sale,404); abort_if(in_array($sale->payment_status,['refunded','cancelled']),422,'Venda não pode ser confirmada.');
            DB::table('cutinapp_sales')->where('id',$saleId)->update(['payment_status'=>'paid','payment_reference'=>$data['payment_reference']??$sale->payment_reference,'paid_at'=>$sale->paid_at?:now(),'updated_at'=>now()]); $this->fulfillSale($saleId);
        });
        return response()->json(['message'=>'Pagamento confirmado e itens emitidos.']);
    }

    public function myTickets()
    {
        return response()->json(DB::table('cutinapp_admissions as a')->join('events as e','e.id','=','a.event_id')->join('cutinapp_sales as s','s.id','=','a.sale_id')->where('a.owner_user_id',auth()->id())->select('a.*','e.title as event_title','e.start_date','e.venue','e.city','e.image','s.public_id as sale_public_id')->orderByDesc('e.start_date')->get());
    }

    public function mySales()
    {
        return response()->json(DB::table('cutinapp_sales')->where('buyer_user_id',auth()->id())->latest()->get());
    }

    public function checkin(Request $request)
    {
        $data=$request->validate(['token'=>'required|uuid']);
        return DB::transaction(function () use ($data) {
            $admission=DB::table('cutinapp_admissions')->where('token',$data['token'])->lockForUpdate()->first(); abort_unless($admission,404,'Ingresso não encontrado.');
            $event=$this->eventOrFail($admission->event_id); abort_unless($this->canCheckin($event,(int)auth()->id()),403,'Sem permissão para check-in.');
            if ($admission->status==='used') return response()->json(['valid'=>false,'message'=>'Ingresso já utilizado.','admission'=>$admission],409);
            abort_unless($admission->status==='valid',422,'Ingresso inválido ou cancelado.');
            DB::table('cutinapp_admissions')->where('id',$admission->id)->update(['status'=>'used','checked_in_at'=>now(),'checked_in_by'=>auth()->id(),'updated_at'=>now()]);
            return response()->json(['valid'=>true,'message'=>'Entrada liberada.','admission'=>DB::table('cutinapp_admissions')->find($admission->id)]);
        });
    }
}
