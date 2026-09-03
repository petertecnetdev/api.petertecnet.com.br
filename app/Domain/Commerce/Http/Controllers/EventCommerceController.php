<?php

namespace App\Domain\Commerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\CommercePayment;
use App\Models\Event;
use App\Models\EventItem;
use App\Models\EventPass;
use App\Models\Production;
use App\Models\Ticket;
use App\Services\MerchantPaymentAccountService;
use App\Services\MercadoPagoService;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class EventCommerceController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly MercadoPagoService $mercadoPago,
        private readonly MerchantPaymentAccountService $accounts,
    ) {}

    public function catalog(string $slug)
    {
        $this->context->requireCapability('commerce');
        $event = Event::query()->where('app_id',$this->context->id())->where('slug',$slug)->where('is_published',true)->where('is_cancelled',false)->where('is_private',false)->whereHas('production',fn($q)=>$q->where('app_id',$this->context->id()))->firstOrFail();
        $tickets = Ticket::query()->where('event_id',$event->id)->where('app_id',$this->context->id())->where('price','>',0)->orderBy('price')->get();
        $items = EventItem::query()->where('app_id',$this->context->id())->where('event_id',$event->id)->where('is_active',true)->orderBy('name')->get();
        $readiness = $this->accounts->readiness((int)$event->production_id);
        return response()->json(['event'=>$event->only(['id','title','slug','start_date','end_date']),'tickets'=>$tickets,'items'=>$items,'payment_config'=>['provider'=>'mercadopago','connected'=>$readiness['available'],'available'=>$readiness['available'],'merchant_connected'=>$readiness['merchant_connected'],'settlement_mode'=>$readiness['settlement_mode'],'public_key'=>$readiness['public_key'],'methods'=>$readiness['methods'],'message'=>$readiness['message']]]);
    }

    public function checkout(Request $request)
    {
        $this->context->requireCapability('commerce');$user=$request->user();abort_unless($user,401,'Faça login para concluir a compra.');
        $data=$request->validate([
            'event_id'=>'required|integer|exists:events,id','tickets'=>'nullable|array|max:20','tickets.*.id'=>'required_with:tickets|integer','tickets.*.quantity'=>'required_with:tickets|integer|min:1|max:20',
            'items'=>'nullable|array|max:30','items.*.id'=>'required_with:items|integer','items.*.quantity'=>'required_with:items|integer|min:1|max:50',
            'payment_method'=>'required|in:pix,card','card_token'=>'required_if:payment_method,card|nullable|string|max:300','payment_method_id'=>'required_if:payment_method,card|nullable|string|max:80','issuer_id'=>'nullable|string|max:80','installments'=>'required_if:payment_method,card|nullable|integer|min:1|max:24','payer_identification_type'=>'required_if:payment_method,card|nullable|string|in:CPF','payer_identification_number'=>'required_if:payment_method,card|nullable|string|max:30','payer_email'=>'nullable|email|max:190',
        ]);
        abort_if(empty($data['tickets'])&&empty($data['items']),422,'Selecione ao menos um ingresso ou item.');
        if($data['payment_method']==='card'){$document=preg_replace('/\D+/','',(string)($data['payer_identification_number']??''));abort_if(strlen($document)!==11,422,'Informe um CPF válido para o titular do cartão.');$data['payer_identification_number']=$document;}
        $eventForReadiness=Event::query()->where('id',$data['event_id'])->where('app_id',$this->context->id())->whereHas('production',fn($q)=>$q->where('app_id',$this->context->id()))->firstOrFail();
        $readiness=$this->accounts->readiness((int)$eventForReadiness->production_id);abort_unless($readiness['available'],422,$readiness['message']);abort_unless(in_array($data['payment_method'],$readiness['methods'],true),422,'Esta forma de pagamento não está disponível para esta organização.');
        $platformRate=max(0,min((float)$this->context->option('commerce.platform_fee_percent',0),100));$expirationMinutes=(int)$this->context->option('commerce.order_expiration_minutes',30);

        $order=DB::transaction(function()use($data,$user,$platformRate,$expirationMinutes){
            $event=Event::query()->where('id',$data['event_id'])->where('app_id',$this->context->id())->with('production')->lockForUpdate()->firstOrFail();
            abort_if(!$event->is_published||$event->is_cancelled||$event->is_private,422,'Este evento não está disponível para venda.');abort_unless($event->production&&(int)$event->production->app_id===$this->context->id(),422,'A organização do evento é inválida.');abort_if($event->end_date&&now()->greaterThanOrEqualTo($event->end_date),422,'Este evento já foi encerrado.');
            $expiresAt=now()->addMinutes($expirationMinutes);
            $order=CommerceOrder::create(['app_id'=>$this->context->id(),'public_id'=>(string)Str::uuid(),'event_id'=>$event->id,'production_id'=>$event->production_id,'user_id'=>$user->id,'status'=>'pending','currency'=>'BRL','payment_method'=>$data['payment_method'],'expires_at'=>$expiresAt,'metadata'=>['application_slug'=>$this->context->slug()]]);
            $subtotal=0.0;
            foreach(($data['tickets']??[])as$requested){
                $ticket=Ticket::query()->where('id',$requested['id'])->where('event_id',$event->id)->where('app_id',$this->context->id())->lockForUpdate()->firstOrFail();abort_if((float)$ticket->price<=0,422,'Cortesias gratuitas não entram no checkout pago.');abort_if($ticket->limit_date&&now()->greaterThan($ticket->limit_date),422,"O lote {$ticket->name} não está mais disponível.");
                $issued=EventPass::query()->where('ticket_id',$ticket->id)->whereNotIn('status',['cancelled','refunded','charged_back'])->count();$reserved=DB::table('inventory_reservations')->where('app_id',$this->context->id())->where('ticket_id',$ticket->id)->whereNull('released_at')->where('expires_at','>',now())->sum('quantity');$qty=(int)$requested['quantity'];abort_if($issued+$reserved+$qty>(int)$ticket->quantity,422,"Não há quantidade suficiente no lote {$ticket->name}.");
                $line=round((float)$ticket->price*$qty,2);CommerceOrderItem::create(['app_id'=>$this->context->id(),'order_id'=>$order->id,'type'=>'ticket','ticket_id'=>$ticket->id,'name'=>$ticket->name,'unit_price'=>$ticket->price,'quantity'=>$qty,'subtotal'=>$line]);DB::table('inventory_reservations')->insert(['app_id'=>$this->context->id(),'order_id'=>$order->id,'type'=>'ticket','ticket_id'=>$ticket->id,'quantity'=>$qty,'expires_at'=>$expiresAt,'created_at'=>now(),'updated_at'=>now()]);$subtotal+=$line;
            }
            foreach(($data['items']??[])as$requested){
                $item=EventItem::query()->where('app_id',$this->context->id())->where('id',$requested['id'])->where('event_id',$event->id)->where('is_active',true)->lockForUpdate()->firstOrFail();$reserved=DB::table('inventory_reservations')->where('app_id',$this->context->id())->where('event_item_id',$item->id)->whereNull('released_at')->where('expires_at','>',now())->sum('quantity');$sold=DB::table('commerce_order_items as oi')->join('commerce_orders as o','o.id','=','oi.order_id')->where('oi.app_id',$this->context->id())->where('o.app_id',$this->context->id())->where('oi.event_item_id',$item->id)->where('o.status','paid')->sum('oi.quantity');$qty=(int)$requested['quantity'];abort_if($sold+$reserved+$qty>(int)$item->quantity,422,"Não há quantidade suficiente de {$item->name}.");
                $line=round((float)$item->price*$qty,2);CommerceOrderItem::create(['app_id'=>$this->context->id(),'order_id'=>$order->id,'type'=>'item','event_item_id'=>$item->id,'name'=>$item->name,'unit_price'=>$item->price,'quantity'=>$qty,'subtotal'=>$line]);DB::table('inventory_reservations')->insert(['app_id'=>$this->context->id(),'order_id'=>$order->id,'type'=>'item','event_item_id'=>$item->id,'quantity'=>$qty,'expires_at'=>$expiresAt,'created_at'=>now(),'updated_at'=>now()]);$subtotal+=$line;
            }
            $platformFee=round($subtotal*($platformRate/100),2);$order->update(['subtotal'=>$subtotal,'platform_fee'=>$platformFee,'total'=>$subtotal,'producer_net'=>max(0,$subtotal-$platformFee)]);return$order->fresh(['items','event','production','user']);
        });

        $account=$this->accounts->account((int)$order->production_id,'mercadopago',true);$usesMerchant=(bool)($account&&$account->access_token);$allowPlatform=(bool)$this->context->option('commerce.allow_platform_collection',false);$platformToken=trim((string)config('services.mercadopago.access_token'));
        if(!$usesMerchant&&(!$allowPlatform||$platformToken==='')){$this->cancelOrder($order);return response()->json(['message'=>'Conecte uma conta de pagamento antes de iniciar vendas pagas.'],422);}
        try{
            $sellerToken=$usesMerchant?$this->accounts->freshAccessToken($account)[1]:$platformToken;$settlementMode=$usesMerchant?'automatic_split':'platform_collection';$order->update(['metadata'=>array_merge($order->metadata??[],['settlement_mode'=>$settlementMode])]);$idempotencyKey=(string)Str::uuid();$payer=['email'=>$data['payer_email']??$user->email];if($data['payment_method']==='card')$payer['identification']=['type'=>'CPF','number'=>$data['payer_identification_number']];
            $payload=['transaction_amount'=>(float)$order->total,'description'=>mb_substr(($this->context->application()->name?:'Peter Tecnet').' - '.($order->event->title??'Pedido'),0,255),'external_reference'=>$order->public_id,'notification_url'=>rtrim((string)config('app.url'),'/').'/api/v1/apps/'.$this->context->slug().'/payments/mercadopago/webhook','payer'=>$payer,'metadata'=>['application_id'=>$this->context->id(),'order_id'=>$order->id,'order_public_id'=>$order->public_id,'organization_id'=>$order->production_id,'settlement_mode'=>$settlementMode]];
            if($usesMerchant&&(float)$order->platform_fee>0)$payload['application_fee']=(float)$order->platform_fee;if($data['payment_method']==='pix'){$payload['payment_method_id']='pix';$payload['date_of_expiration']=$order->expires_at->copy()->utc()->format('Y-m-d\TH:i:s.000\Z');}else{$payload['token']=$data['card_token'];$payload['payment_method_id']=$data['payment_method_id'];$payload['installments']=(int)$data['installments'];if(!empty($data['issuer_id']))$payload['issuer_id']=$data['issuer_id'];}
            $remote=$this->mercadoPago->createPayment($sellerToken,$payload,$idempotencyKey);$transaction=data_get($remote,'point_of_interaction.transaction_data',[]);$providerFee=collect($remote['fee_details']??[])->sum(fn($fee)=>(float)($fee['amount']??0));
            $payment=CommercePayment::create(['app_id'=>$this->context->id(),'order_id'=>$order->id,'provider'=>'mercadopago','method'=>$data['payment_method'],'status'=>(string)($remote['status']??'pending'),'provider_payment_id'=>isset($remote['id'])?(string)$remote['id']:null,'provider_txid'=>data_get($remote,'point_of_interaction.transaction_data.transaction_id'),'idempotency_key'=>$idempotencyKey,'amount'=>$order->total,'provider_fee'=>$providerFee,'qr_code'=>$transaction['qr_code']??null,'qr_code_image'=>!empty($transaction['qr_code_base64'])?'data:image/png;base64,'.$transaction['qr_code_base64']:null,'ticket_url'=>$transaction['ticket_url']??null,'provider_payload'=>$remote,'failed_at'=>in_array(($remote['status']??''),['rejected','cancelled'],true)?now():null]);$order->update(['processor_fee'=>$providerFee]);
        }catch(Throwable $e){report($e);$this->cancelOrder($order);return response()->json(['message'=>'Não foi possível iniciar o pagamento. Tente novamente.'],502);}
        return response()->json(['message'=>$data['payment_method']==='pix'?'Pedido criado. Pague o PIX antes do vencimento.':'Pagamento enviado ao provedor.','order'=>$order->fresh(['items','event','production']),'payment'=>$payment],201);
    }

    public function mine(Request $request){return response()->json(['orders'=>CommerceOrder::query()->where('app_id',$this->context->id())->where('user_id',$request->user()->id)->with(['items','event','payments'])->latest()->paginate(20)]);}
    public function show(Request $request,string $publicId){$order=CommerceOrder::query()->where('app_id',$this->context->id())->where('public_id',$publicId)->with(['items','event.production','payments'])->firstOrFail();abort_unless((int)$order->user_id===(int)$request->user()->id||$this->ownsOrganization($request,(int)$order->production_id),403);return response()->json(['order'=>$order]);}
    public function upsertEventItem(Request $request,int $eventId,?int $itemId=null){$event=$this->ownedEvent($request,$eventId);$data=$request->validate(['name'=>'required|string|max:140','description'=>'nullable|string|max:2000','price'=>'required|numeric|min:0.01|max:999999.99','quantity'=>'required|integer|min:0|max:1000000','is_active'=>'sometimes|boolean']);$item=$itemId?EventItem::query()->where('app_id',$this->context->id())->where('event_id',$event->id)->findOrFail($itemId):new EventItem(['app_id'=>$this->context->id(),'event_id'=>$event->id]);$item->fill($data)->save();return response()->json(['item'=>$item],$itemId?200:201);}
    public function deleteEventItem(Request $request,int $eventId,int $itemId){$event=$this->ownedEvent($request,$eventId);$item=EventItem::query()->where('app_id',$this->context->id())->where('event_id',$event->id)->findOrFail($itemId);$item->update(['is_active'=>false]);return response()->json(['message'=>'Item desativado.']);}
    public function paymentAccount(Request $request,int $organizationId){$this->ownedOrganization($request,$organizationId);$account=$this->accounts->account($organizationId);if(!$account)return response()->json(['account'=>null]);return response()->json(['account'=>['provider'=>$account->provider,'status'=>$account->status,'provider_recipient_id'=>$account->provider_recipient_id,'connected_at'=>$account->connected_at,'verified_at'=>$account->verified_at,'token_expires_at'=>$account->token_expires_at]]);}
    public function financialSummary(Request $request,int $organizationId){$this->ownedOrganization($request,$organizationId);$gross=(float)DB::table('ledger_entries')->where('app_id',$this->context->id())->where('production_id',$organizationId)->where('type','gross_sale')->where('status','posted')->sum('amount');$fees=abs((float)DB::table('ledger_entries')->where('app_id',$this->context->id())->where('production_id',$organizationId)->where('type','platform_fee')->where('status','posted')->sum('amount'));$earned=(float)DB::table('ledger_entries')->where('app_id',$this->context->id())->where('production_id',$organizationId)->where('type','producer_credit')->where('status','posted')->sum('amount');$processorFees=(float)DB::table('commerce_payments as p')->join('commerce_orders as o','o.id','=','p.order_id')->where('p.app_id',$this->context->id())->where('o.app_id',$this->context->id())->where('o.production_id',$organizationId)->where('p.status','paid')->sum('p.provider_fee');$readiness=$this->accounts->readiness($organizationId);return response()->json(['gross_sales'=>round($gross,2),'platform_fees'=>round($fees,2),'processor_fees'=>round($processorFees,2),'organization_earned'=>round($earned,2),'settlement'=>$readiness['settlement_mode'],'sales_enabled'=>$readiness['available'],'sales_message'=>$readiness['message'],'provider'=>'mercadopago']);}

    private function cancelOrder(CommerceOrder $order):void{DB::transaction(function()use($order){CommerceOrder::query()->where('app_id',$this->context->id())->where('id',$order->id)->where('status','pending')->update(['status'=>'cancelled','cancelled_at'=>now()]);DB::table('inventory_reservations')->where('app_id',$this->context->id())->where('order_id',$order->id)->whereNull('released_at')->update(['released_at'=>now(),'updated_at'=>now()]);});}
    private function ownedEvent(Request $request,int $id):Event{$event=Event::query()->where('app_id',$this->context->id())->with('production')->findOrFail($id);$this->ownedOrganization($request,(int)$event->production_id);return$event;}
    private function ownedOrganization(Request $request,int $id):Production{$organization=Production::query()->where('app_id',$this->context->id())->findOrFail($id);abort_unless($this->ownsOrganization($request,$id),403);return$organization;}
    private function ownsOrganization(Request $request,int $id):bool{$organization=Production::query()->where('app_id',$this->context->id())->find($id);if(!$organization||!$request->user())return false;$admin=method_exists($request->user(),'hasProfile')&&$request->user()->hasProfile('Administrador');return$admin||(int)$organization->user_id===(int)$request->user()->id;}
}
