<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EcosystemPayment;
use App\Models\Employer;
use App\Models\Establishment;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\MercadoPagoService;
use App\Support\ApplicationContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlatOrderController extends Controller
{
    private const STATUSES = ['pending', 'confirmed', 'preparing', 'ready', 'completed', 'cancelled'];

    public function __construct(private readonly ApplicationContext $context, private readonly MercadoPagoService $mercadoPago) {}

    public function ordering(string $slug): JsonResponse
    {
        $est = $this->publicRestaurant($slug);
        $items = Item::query()->where('app_id', $this->context->id())->where('entity_name', 'establishment')->where('entity_id', $est->id)->where('status', true)->orderBy('category')->orderBy('name')->get();
        return response()->json(['success' => true, 'data' => ['establishment' => $est->only(['id','name','fantasy','slug','description','logo','background','address','city','uf','phone','instagram_url','location','segments']), 'items' => $items, 'ordering' => $this->orderingConfig($est)]]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'establishment_id'=>['required','integer'], 'fulfillment'=>['required',Rule::in(['delivery','pickup','dine-in'])], 'payment_method'=>['required',Rule::in(['pix','cash','card_on_delivery'])],
            'customer_name'=>['required','string','max:160'], 'customer_phone'=>['required','string','max:30'], 'delivery_address'=>['nullable','string','max:1000'], 'notes'=>['nullable','string','max:2000'],
            'items'=>['required','array','min:1','max:60'], 'items.*.item_id'=>['required','integer'], 'items.*.quantity'=>['required','integer','min:1','max:99'], 'items.*.notes'=>['nullable','string','max:500'],
            'items.*.additions'=>['nullable','array','max:30'], 'items.*.additions.*'=>['integer'], 'items.*.removals'=>['nullable','array','max:30'], 'items.*.removals.*'=>['integer'],
        ]);
        $user = $request->user();

        [$order, $est] = DB::transaction(function () use ($data, $user) {
            $est = Establishment::query()->whereKey($data['establishment_id'])->where('app_id',$this->context->id())->where('is_cancelled',false)->where('is_published',true)->lockForUpdate()->firstOrFail();
            $cfg = $this->orderingConfig($est);
            abort_unless($cfg['available'],422,$cfg['unavailable_reason'] ?: 'O restaurante não está recebendo pedidos agora.');
            abort_unless($cfg['fulfillment'][$data['fulfillment']] ?? false,422,'Modalidade de atendimento indisponível.');
            abort_unless(in_array($data['payment_method'],$cfg['payment_methods'],true),422,'Forma de pagamento indisponível.');
            if ($data['fulfillment']==='delivery') abort_if(trim((string)($data['delivery_address']??''))==='',422,'Informe o endereço de entrega.');

            $primary=collect($data['items'])->pluck('item_id')->map(fn($id)=>(int)$id)->unique();
            $mods=collect($data['items'])->flatMap(fn($r)=>array_merge($r['additions']??[],$r['removals']??[]))->map(fn($id)=>(int)$id)->unique();
            $catalog=Item::query()->whereIn('id',$primary->merge($mods)->unique())->where('app_id',$this->context->id())->where('entity_name','establishment')->where('entity_id',$est->id)->where('status',true)->lockForUpdate()->get()->keyBy('id');
            abort_unless($primary->every(fn($id)=>$catalog->has($id)),422,'Há item indisponível no carrinho.');
            abort_unless($mods->every(fn($id)=>$catalog->has($id)),422,'Há adicional indisponível no carrinho.');

            $subtotal=0.0; $demand=[]; $lines=[];
            foreach($data['items'] as $row){
                $item=$catalog->get((int)$row['item_id']); $qty=(int)$row['quantity']; abort_if($item->type==='modifier',422,"{$item->name} não pode ser item principal.");
                $lineTotal=(float)$item->price*$qty; $demand[$item->id]=($demand[$item->id]??0)+$qty; $adds=[];
                foreach($row['additions']??[] as $id){$mod=$catalog->get((int)$id);abort_unless($mod,422,'Adicional inválido.');$lineTotal+=(float)$mod->price*$qty;$demand[$mod->id]=($demand[$mod->id]??0)+$qty;$adds[]=$mod->id;}
                $removes=collect($row['removals']??[])->map(fn($id)=>(int)$id)->filter(fn($id)=>$catalog->has($id))->values()->all();
                $subtotal+=$lineTotal; $lines[]=['item'=>$item,'qty'=>$qty,'lineTotal'=>$lineTotal,'adds'=>$adds,'removes'=>$removes,'notes'=>$row['notes']??null];
            }
            foreach($demand as $id=>$qty){$item=$catalog->get((int)$id);abort_if((int)$item->stock<$qty,422,"Estoque insuficiente para {$item->name}.");}
            $minimum=(float)($est->minimum_order??0); abort_if($subtotal<$minimum,422,'Pedido mínimo: R$ '.number_format($minimum,2,',','.')); $fee=$data['fulfillment']==='delivery'?(float)($est->delivery_fee??0):0.0;

            $order=Order::query()->forceCreate([
                'app_id'=>$this->context->id(),'entity_name'=>'establishment','entity_id'=>$est->id,'order_number'=>Order::nextOrderNumber($this->context->id()),'order_datetime'=>now('America/Sao_Paulo'),
                'created_by'=>$user->id,'attendant_id'=>null,'client_id'=>$user->id,'customer_name'=>$data['customer_name'],'customer_phone'=>$data['customer_phone'],'customer_email'=>$user->email,
                'access_code'=>Order::generateAccessCode(),'origin'=>'Online','fulfillment'=>$data['fulfillment'],'payment_status'=>'pending','payment_method'=>$data['payment_method'],'subtotal'=>$subtotal,'delivery_fee'=>$fee,
                'delivery_address'=>$data['fulfillment']==='delivery'?trim($data['delivery_address']):null,'total_price'=>$subtotal+$fee,'status'=>'pending','status_updated_at'=>now(),'notes'=>$data['notes']??null,'type'=>'direct',
            ]);
            foreach($lines as $line){
                $oi=OrderItem::create(['order_id'=>$order->id,'item_id'=>$line['item']->id,'quantity'=>$line['qty'],'unit_price'=>(float)$line['item']->price,'subtotal'=>$line['lineTotal'],'notes'=>$line['notes']]);
                foreach($line['adds'] as $id) DB::table('order_item_modifiers')->insert(['order_item_id'=>$oi->id,'modifier_id'=>$id,'type'=>'addition','created_at'=>now(),'updated_at'=>now()]);
                foreach($line['removes'] as $id) DB::table('order_item_modifiers')->insert(['order_item_id'=>$oi->id,'modifier_id'=>$id,'type'=>'removal','created_at'=>now(),'updated_at'=>now()]);
            }
            foreach($demand as $id=>$qty) Item::whereKey($id)->decrement('stock',$qty);
            return [$order->fresh(['items.item']),$est];
        },3);

        $payment=$data['payment_method']==='pix'?$this->createPixPayment($order,$est,$user):null;
        return response()->json(['success'=>true,'message'=>'Pedido criado com sucesso.','data'=>['order'=>$this->serializeOrder($order),'payment'=>$payment]],201);
    }

    public function myOrders(Request $request): JsonResponse
    {
        $orders=Order::query()->where('app_id',$this->context->id())->where('client_id',$request->user()->id)->where('entity_name','establishment')->with(['items.item'])->latest('id')->paginate(min(max((int)$request->query('per_page',20),1),100));
        $orders->getCollection()->transform(fn(Order $o)=>$this->serializeOrder($o)); return response()->json(['success'=>true,'data'=>$orders]);
    }

    public function myOrder(Request $request,int $order): JsonResponse
    {
        $o=Order::query()->whereKey($order)->where('app_id',$this->context->id())->where('client_id',$request->user()->id)->where('entity_name','establishment')->with(['items.item'])->firstOrFail();
        return response()->json(['success'=>true,'data'=>$this->serializeOrder($o)]);
    }

    public function establishmentOrders(Request $request,int $establishment): JsonResponse
    {
        $est=$this->manageable($request,$establishment); $q=Order::query()->where('app_id',$this->context->id())->where('entity_name','establishment')->where('entity_id',$est->id)->with(['items.item'])->latest('id');
        if($request->filled('status'))$q->where('status',$request->string('status')); if($request->filled('q')){$term='%'.trim((string)$request->query('q')).'%';$q->where(fn($x)=>$x->where('customer_name','like',$term)->orWhere('order_number','like',$term));}
        $orders=$q->paginate(min(max((int)$request->query('per_page',50),1),100));$orders->getCollection()->transform(fn(Order $o)=>$this->serializeOrder($o));return response()->json(['success'=>true,'data'=>$orders]);
    }

    public function updateStatus(Request $request,int $order): JsonResponse
    {
        $data=$request->validate(['status'=>['required',Rule::in(self::STATUSES)]]);
        $o=DB::transaction(function()use($request,$order,$data){$o=Order::query()->whereKey($order)->where('app_id',$this->context->id())->where('entity_name','establishment')->with('items')->lockForUpdate()->firstOrFail();$this->manageable($request,(int)$o->entity_id);$previous=(string)$o->status;abort_if($previous==='cancelled'&&$data['status']!=='cancelled',422,'Pedido cancelado não pode ser reaberto automaticamente.');if($data['status']==='cancelled'&&$previous!=='cancelled')$this->restoreStock($o);$o->forceFill(['status'=>$data['status'],'status_updated_at'=>now(),'attended_at'=>$data['status']==='completed'?now():$o->attended_at])->save();return $o->fresh(['items.item']);},3);
        return response()->json(['success'=>true,'message'=>'Status do pedido atualizado.','data'=>$this->serializeOrder($o)]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $ests=Establishment::query()->where('app_id',$this->context->id())->where('user_id',$request->user()->id)->where('is_cancelled',false)->get(['id','name','fantasy','slug','logo','accepting_orders']);
        $start=Carbon::now('America/Sao_Paulo')->startOfDay()->utc();$end=Carbon::now('America/Sao_Paulo')->endOfDay()->utc();$orders=Order::query()->where('app_id',$this->context->id())->where('entity_name','establishment')->whereIn('entity_id',$ests->pluck('id'))->whereBetween('created_at',[$start,$end])->where('status','!=','cancelled')->get(['id','entity_id','total_price']);$grouped=$orders->groupBy('entity_id');
        $rows=$ests->map(function($est)use($grouped){$set=$grouped->get($est->id,collect());$rev=(float)$set->sum('total_price');return['establishment'=>$est,'orders'=>$set->count(),'revenue'=>round($rev,2),'average_ticket'=>$set->count()?round($rev/$set->count(),2):0];})->values();$rev=(float)$orders->sum('total_price');$count=$orders->count();
        return response()->json(['success'=>true,'data'=>['totals'=>['orders'=>$count,'revenue'=>round($rev,2),'average_ticket'=>$count?round($rev/$count,2):0,'establishments'=>$ests->count()],'establishments'=>$rows]]);
    }

    public function updateOrderingSettings(Request $request,int $establishment): JsonResponse
    {
        $est=$this->manageable($request,$establishment);abort_unless((int)$est->user_id===(int)$request->user()->id,403,'Somente o proprietário altera as configurações comerciais.');$data=$request->validate([
            'ordering_enabled'=>['sometimes','boolean'],'accepting_orders'=>['sometimes','boolean'],'delivery_enabled'=>['sometimes','boolean'],'pickup_enabled'=>['sometimes','boolean'],'dine_in_enabled'=>['sometimes','boolean'],
            'delivery_fee'=>['sometimes','numeric','min:0','max:9999.99'],'minimum_order'=>['sometimes','numeric','min:0','max:999999.99'],'estimated_delivery_minutes'=>['nullable','integer','min:1','max:1440'],'opening_hours'=>['nullable','array'],
            'payment_methods'=>['sometimes','array','min:1'],'payment_methods.*'=>[Rule::in(['pix','cash','card_on_delivery'])],'pix_key'=>['nullable','string','max:255'],]);
        if(array_key_exists('opening_hours',$data))$data['opening_hours']=json_encode($data['opening_hours']);if(array_key_exists('payment_methods',$data))$data['payment_methods']=json_encode(array_values(array_unique($data['payment_methods'])));$est->forceFill($data+['updated_by'=>$request->user()->id])->save();
        return response()->json(['success'=>true,'message'=>'Configurações de pedidos atualizadas.','data'=>$this->orderingConfig($est->fresh())]);
    }

    public function mercadoPagoWebhook(Request $request): JsonResponse
    {
        $id=(string)($request->input('data.id')?:$request->query('data_id')?:'');if($id==='')return response()->json(['success'=>true]);abort_unless($this->mercadoPago->validateWebhookSignature($request->header('x-signature'),$request->header('x-request-id'),$id),401,'Assinatura de webhook inválida.');$token=trim((string)config('services.mercadopago.access_token'));abort_if($token==='',503,'Mercado Pago não configurado.');$remote=$this->mercadoPago->getPayment($token,$id);
        $payment=EcosystemPayment::query()->where('app_slug',$this->context->slug())->where('provider','mercadopago')->where('provider_payment_id',(string)($remote['id']??$id))->first();if(!$payment)return response()->json(['success'=>true]);$mapped=match((string)($remote['status']??'')){'approved'=>'paid','refunded','charged_back'=>'refunded','rejected','cancelled'=>'failed',default=>'pending'};
        DB::transaction(function()use($payment,$mapped,$remote){$fees=(float)collect($remote['fee_details']??[])->sum('amount');$payment->forceFill(['status'=>$mapped,'provider_fee'=>$fees,'seller_net'=>max(0,(float)$payment->gross_amount-$fees-(float)$payment->platform_fee),'paid_at'=>$mapped==='paid'?now():$payment->paid_at,'refunded_at'=>$mapped==='refunded'?now():$payment->refunded_at,'failed_at'=>$mapped==='failed'?now():$payment->failed_at,'metadata'=>array_merge($payment->metadata??[],['last_webhook_status'=>$remote['status']??null])])->save();if($payment->source_type==='order'&&$payment->source_id)Order::query()->whereKey($payment->source_id)->where('app_id',$this->context->id())->update(['payment_status'=>$mapped,'payment_reference'=>$payment->provider_payment_id,'updated_at'=>now()]);});return response()->json(['success'=>true]);
    }

    private function createPixPayment(Order $order,Establishment $est,$user): array
    {
        $token=trim((string)config('services.mercadopago.access_token'));if($token===''){if(!empty($est->pix_key))return['provider'=>'manual_pix','status'=>'pending','pix_key'=>$est->pix_key,'amount'=>(float)$order->total_price];abort(503,'Pagamento Pix ainda não foi configurado para a Plat.');}
        $public=(string)Str::uuid();$reference='plat-order-'.$order->id.'-'.$public;$payment=EcosystemPayment::create(['public_id'=>$public,'app_id'=>$this->context->id(),'app_slug'=>$this->context->slug(),'provider'=>'mercadopago','source_type'=>'order','source_reference'=>$reference,'source_id'=>$order->id,'user_id'=>$user->id,'establishment_id'=>$est->id,'currency'=>'BRL','method'=>'pix','status'=>'pending','gross_amount'=>$order->total_price,'platform_fee'=>0,'provider_fee'=>0,'seller_net'=>$order->total_price,'metadata'=>['order_number'=>$order->order_number]]);
        try{$remote=$this->mercadoPago->createPayment($token,['transaction_amount'=>(float)$order->total_price,'description'=>'Pedido Plat #'.$order->order_number,'payment_method_id'=>'pix','external_reference'=>$reference,'notification_url'=>rtrim((string)config('app.url'),'/').'/api/v1/apps/'.$this->context->slug().'/payments/mercadopago/webhook','payer'=>['email'=>$user->email,'first_name'=>$user->first_name?:$order->customer_name,'last_name'=>$user->last_name?:'']],'plat-order-'.$order->id);$payment->forceFill(['provider_payment_id'=>(string)($remote['id']??''),'metadata'=>array_merge($payment->metadata??[],['remote_status'=>$remote['status']??null])])->save();$order->forceFill(['payment_reference'=>$payment->provider_payment_id])->save();$tx=$remote['point_of_interaction']['transaction_data']??[];return['provider'=>'mercadopago','public_id'=>$payment->public_id,'status'=>'pending','amount'=>(float)$order->total_price,'qr_code'=>$tx['qr_code']??null,'qr_code_base64'=>$tx['qr_code_base64']??null,'ticket_url'=>$tx['ticket_url']??null];}catch(\Throwable $e){$payment->forceFill(['status'=>'failed','failed_at'=>now(),'metadata'=>array_merge($payment->metadata??[],['error'=>$e->getMessage()])])->save();throw $e;}
    }

    private function publicRestaurant(string $slug): Establishment {return Establishment::query()->where('app_id',$this->context->id())->where('slug',$slug)->where('is_cancelled',false)->where('is_published',true)->firstOrFail();}
    private function manageable(Request $request,int $id): Establishment {$est=Establishment::query()->whereKey($id)->where('app_id',$this->context->id())->where('is_cancelled',false)->firstOrFail();$uid=(int)$request->user()->id;$owner=(int)$est->user_id===$uid;$employee=Employer::query()->where('establishment_id',$est->id)->where('user_id',$uid)->exists();abort_unless($owner||$employee,403,'Você não possui acesso a esta operação.');return $est;}
    private function restoreStock(Order $order): void {foreach($order->items as $line){if($line->item_id)Item::whereKey($line->item_id)->increment('stock',(int)$line->quantity);$ids=DB::table('order_item_modifiers')->where('order_item_id',$line->id)->where('type','addition')->pluck('modifier_id');foreach($ids as $id)if($id)Item::whereKey($id)->increment('stock',(int)$line->quantity);}}

    private function orderingConfig(Establishment $est): array
    {
        $enabled=(bool)($est->ordering_enabled??true);$accepting=(bool)($est->accepting_orders??true);$open=$this->isOpenNow($est);$available=$enabled&&$accepting&&$open;
        return['available'=>$available,'open_now'=>$open,'accepting_orders'=>$accepting,'ordering_enabled'=>$enabled,'unavailable_reason'=>!$enabled?'Pedidos online estão desativados.':(!$accepting?'O restaurante pausou novos pedidos.':(!$open?'O restaurante está fechado agora.':null)),'fulfillment'=>['delivery'=>(bool)($est->delivery_enabled??true),'pickup'=>(bool)($est->pickup_enabled??true),'dine-in'=>(bool)($est->dine_in_enabled??false)],'delivery_fee'=>(float)($est->delivery_fee??0),'minimum_order'=>(float)($est->minimum_order??0),'estimated_delivery_minutes'=>$est->estimated_delivery_minutes?(int)$est->estimated_delivery_minutes:null,'opening_hours'=>$this->json($est->opening_hours,[]),'payment_methods'=>$this->json($est->payment_methods,['pix','cash','card_on_delivery']),'pix_configured'=>trim((string)config('services.mercadopago.access_token'))!==''||!empty($est->pix_key)];
    }

    private function isOpenNow(Establishment $est): bool
    {
        $hours=$this->json($est->opening_hours,[]);if($hours===[])return true;$now=Carbon::now('America/Sao_Paulo');
        foreach([0,-1] as $offset){$day=$now->copy()->addDays($offset);$ranges=null;foreach([strtolower($day->format('l')),(string)$day->dayOfWeekIso] as $key){if(array_key_exists($key,$hours)){$ranges=$hours[$key];break;}}if($ranges===true||$ranges==='24h')return true;if(!is_array($ranges))continue;if(isset($ranges['open'],$ranges['close']))$ranges=[$ranges];foreach($ranges as $range){if(!is_array($range)||empty($range['open'])||empty($range['close']))continue;try{$open=Carbon::parse($day->toDateString().' '.$range['open'],'America/Sao_Paulo');$close=Carbon::parse($day->toDateString().' '.$range['close'],'America/Sao_Paulo');if($close->lte($open))$close->addDay();if($now->betweenIncluded($open,$close))return true;}catch(\Throwable){}}}return false;
    }

    private function json($value,array $fallback): array {if(is_array($value))return$value;if(!is_string($value)||trim($value)==='')return$fallback;$decoded=json_decode($value,true);return is_array($decoded)?$decoded:$fallback;}
    private function serializeOrder(Order $o): array {$est=Establishment::query()->whereKey($o->entity_id)->where('app_id',$this->context->id())->first(['id','name','fantasy','slug','logo']);return['id'=>$o->id,'order_number'=>$o->order_number,'status'=>$o->status?:'pending','payment_status'=>$o->payment_status,'payment_method'=>$o->payment_method,'fulfillment'=>$o->fulfillment,'subtotal'=>(float)($o->subtotal??0),'delivery_fee'=>(float)($o->delivery_fee??0),'total_price'=>(float)$o->total_price,'delivery_address'=>$o->delivery_address,'customer_name'=>$o->customer_name,'customer_phone'=>$o->customer_phone,'notes'=>$o->notes,'created_at'=>optional($o->created_at)->toIso8601String(),'updated_at'=>optional($o->updated_at)->toIso8601String(),'status_updated_at'=>$o->status_updated_at?Carbon::parse($o->status_updated_at)->toIso8601String():null,'establishment'=>$est,'items'=>$o->relationLoaded('items')?$o->items->map(fn($line)=>['id'=>$line->id,'item_id'=>$line->item_id,'name'=>$line->item?->name,'quantity'=>(int)$line->quantity,'unit_price'=>(float)$line->unit_price,'subtotal'=>(float)$line->subtotal,'notes'=>$line->notes??null])->values():[]];}
}
