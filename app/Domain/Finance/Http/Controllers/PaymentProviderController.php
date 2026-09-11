<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\CommerceOrder;
use App\Models\CommercePayment;
use App\Models\EventPass;
use App\Models\Interaction;
use App\Models\Production;
use App\Services\EventAudienceService;
use App\Services\MerchantPaymentAccountService;
use App\Services\MercadoPagoService;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class PaymentProviderController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly MercadoPagoService $mercadoPago,
        private readonly MerchantPaymentAccountService $accounts,
        private readonly EventAudienceService $audience,
    ) {}

    public function connect(Request $request, int $organizationId)
    {
        $this->context->requireCapability('payments');
        $this->assertOrganizationOwner($request, $organizationId);
        $state = Str::random(64);
        Cache::put($this->stateKey($state), [
            'app_id' => $this->context->id(),
            'organization_id' => $organizationId,
            'actor_id' => (int) $request->user()->id,
            'created_at' => now()->timestamp,
        ], now()->addMinutes(10));
        return response()->json(['authorization_url'=>$this->mercadoPago->authorizationUrl($state),'expires_in'=>600]);
    }

    public function callback(Request $request)
    {
        $request->validate(['code'=>'required|string','state'=>'required|string|min:32|max:200']);
        $stateToken=$request->string('state')->toString();$state=Cache::pull($this->stateKey($stateToken));
        abort_unless(is_array($state),422,'A autorização expirou ou já foi utilizada. Conecte a conta novamente.');
        $application=Application::query()->whereKey((int)($state['app_id']??0))->where('is_active',true)->first();abort_unless($application,422,'Aplicação da autorização inválida.');$this->context->set($application);
        $organizationId=(int)($state['organization_id']??0);$organization=Production::query()->where('app_id',$this->context->id())->find($organizationId);abort_unless($organization,422,'Organização da autorização inválida.');
        try{
            $tokens=$this->mercadoPago->exchangeAuthorizationCode($request->string('code')->toString());$access=trim((string)($tokens['access_token']??''));if($access==='')throw new RuntimeException('O provedor não retornou um token de acesso válido.');$now=now();
            DB::table('merchant_payment_accounts')->updateOrInsert(['app_id'=>$this->context->id(),'production_id'=>$organizationId,'provider'=>'mercadopago'],[
                'status'=>'connected','provider_recipient_id'=>(string)($tokens['user_id']??''),'access_token'=>Crypt::encryptString($access),'refresh_token'=>!empty($tokens['refresh_token'])?Crypt::encryptString((string)$tokens['refresh_token']):null,'token_expires_at'=>!empty($tokens['expires_in'])?$now->copy()->addSeconds((int)$tokens['expires_in']):null,'metadata'=>json_encode(['public_key'=>$tokens['public_key']??null,'scope'=>$tokens['scope']??null,'live_mode'=>$tokens['live_mode']??null,'connected_by_user_id'=>$state['actor_id']??null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'connected_at'=>$now,'verified_at'=>$now,'updated_at'=>$now,'created_at'=>$now,
            ]);
            $frontend=rtrim((string)($application->url?:config('app.url')),'/');return redirect($frontend.'/finance?payment_provider=connected&organization='.$organizationId);
        }catch(Throwable $e){report($e);return response('Não foi possível conectar a conta de pagamento. Volte à aplicação e tente novamente.',502);}
    }

    public function webhook(Request $request)
    {
        $dataId=(string)($request->query('data.id')?:data_get($request->all(),'data.id',''));if($dataId==='')return response()->json(['ok'=>true]);
        abort_unless($this->mercadoPago->validateWebhookSignature($request->header('x-signature'),$request->header('x-request-id'),$dataId),401,'Assinatura inválida.');
        $type=(string)($request->input('type')?:$request->query('type','payment'));if($type!=='payment')return response()->json(['ok'=>true]);
        $payment=CommercePayment::query()->where('provider','mercadopago')->where('provider_payment_id',$dataId)->with('order')->first();if(!$payment||!$payment->order)return response()->json(['ok'=>true]);
        $application=Application::query()->whereKey((int)$payment->app_id)->where('is_active',true)->first();abort_unless($application,422,'Aplicação do pagamento não está ativa.');$this->context->set($application);
        try{$this->reconcilePaymentId((int)$payment->id);}catch(Throwable $e){report($e);return response()->json(['ok'=>false,'retry'=>true],502);}return response()->json(['ok'=>true]);
    }

    public function sync(Request $request, string $publicId)
    {
        $order=CommerceOrder::query()->where('app_id',$this->context->id())->where('public_id',$publicId)->with('payments')->firstOrFail();abort_unless((int)$order->user_id===(int)$request->user()->id||$this->isOrganizationOwner($request,(int)$order->production_id),403);
        $payment=$order->payments()->where('app_id',$this->context->id())->where('provider','mercadopago')->latest('id')->first();if(!$payment||!$payment->provider_payment_id)return response()->json(['order'=>$order->fresh(['items','event','payments']),'reconciliation'=>'no_payment']);
        try{$this->reconcilePaymentId((int)$payment->id);return response()->json(['order'=>$order->fresh(['items','event','payments']),'reconciliation'=>'ok']);}catch(Throwable $e){report($e);$fresh=$order->fresh(['items','event','payments']);return response()->json(['order'=>$fresh,'reconciliation'=>'retrying','message'=>$fresh->status==='paid'?'Pagamento confirmado. Estamos finalizando a entrega.':'Ainda não foi possível confirmar o pagamento. Tentaremos novamente.'],202);}
    }

    public function reconcilePaymentId(int $paymentId): CommerceOrder
    {
        $payment=CommercePayment::query()->where('app_id',$this->context->id())->with('order')->findOrFail($paymentId);if(!$payment->order||!$payment->provider_payment_id)throw new RuntimeException('Pagamento local sem vínculo válido com o provedor.');
        $token=$this->paymentAccessToken($payment,$payment->order);$remote=$this->mercadoPago->getPayment($token,(string)$payment->provider_payment_id);$approvedOrderId=$this->syncPayment($payment,$remote);
        if($approvedOrderId){
            $this->audience->confirmPaidOrder($approvedOrderId);
            $this->recordPaidInteraction($approvedOrderId,(int)$payment->id);
        }
        return $payment->order->fresh(['items','event','payments']);
    }

    private function recordPaidInteraction(int $orderId,int $paymentId):void
    {
        try{
            $order=CommerceOrder::query()->where('app_id',$this->context->id())->find($orderId);$payment=CommercePayment::query()->where('app_id',$this->context->id())->find($paymentId);
            if(!$order||!$payment||$order->status!=='paid'||$payment->status!=='paid')return;
            $alreadyRecorded=Interaction::query()->where('app_id',$this->context->id())->where('entity_type','CommerceOrder')->where('entity_id',$order->id)->where('interaction_type','payment_paid')->exists();
            if($alreadyRecorded)return;
            Interaction::register('payment_paid',$order,$order->user,[
                'source_channel'=>'payment_provider','order_public_id'=>$order->public_id,'payment_id'=>$payment->id,'provider'=>$payment->provider,'provider_payment_id'=>$payment->provider_payment_id,'production_id'=>$order->production_id,'payment_method'=>$order->payment_method,'currency'=>$order->currency,'amount'=>(float)$order->total,'platform_fee'=>(float)$order->platform_fee,'processor_fee'=>(float)$order->processor_fee,'producer_net'=>(float)$order->producer_net,'settlement_mode'=>(string)data_get($order->metadata,'settlement_mode','automatic_split'),
            ],'Pagamento confirmado');
        }catch(Throwable $e){report($e);}
    }

    private function syncPayment(CommercePayment $payment,array $remote):?int
    {
        $status=(string)($remote['status']??'pending');$approvedOrderId=null;
        DB::transaction(function()use($payment,$remote,$status,&$approvedOrderId){
            $payment=CommercePayment::query()->where('app_id',$this->context->id())->lockForUpdate()->findOrFail($payment->id);$order=CommerceOrder::query()->where('app_id',$this->context->id())->with(['items','event','user'])->lockForUpdate()->findOrFail($payment->order_id);
            $remoteId=(string)($remote['id']??'');$amount=round((float)($remote['transaction_amount']??0),2);$externalReference=(string)($remote['external_reference']??'');$providerFee=collect($remote['fee_details']??[])->sum(fn($fee)=>(float)($fee['amount']??0));$settlementMode=(string)data_get($order->metadata,'settlement_mode','automatic_split');
            abort_if($remoteId===''||$remoteId!==(string)$payment->provider_payment_id,422,'Pagamento remoto não corresponde ao pagamento local.');abort_if($externalReference===''||$externalReference!==(string)$order->public_id,422,'Referência externa do pagamento é inválida.');abort_if(abs($amount-(float)$order->total)>0.009,422,'Valor confirmado pelo provedor é diferente do pedido.');
            if($settlementMode==='automatic_split'&&array_key_exists('application_fee',$remote)&&abs((float)$remote['application_fee']-(float)$order->platform_fee)>0.009)abort(422,'A comissão confirmada pelo provedor é diferente da comissão do pedido.');
            $payment->update(['provider_fee'=>round($providerFee,2),'provider_payload'=>$remote]);$order->update(['processor_fee'=>round($providerFee,2)]);
            if(in_array($status,['refunded','charged_back'],true)){$this->reversePayment($payment,$order,$status);return;}
            if(in_array($status,['rejected','cancelled'],true)){if($payment->status!=='paid'){$payment->update(['status'=>$status,'failed_at'=>now()]);$order->update(['status'=>'cancelled','cancelled_at'=>now()]);DB::table('inventory_reservations')->where('app_id',$this->context->id())->where('order_id',$order->id)->whereNull('released_at')->update(['released_at'=>now(),'updated_at'=>now()]);}return;}
            if($status!=='approved'){$payment->update(['status'=>$status]);return;}

            // Fulfillment is created before the paid state is exposed so the
            // order can never be observed as paid without its issued passes.
            foreach($order->items->where('type','ticket')as$line){$already=EventPass::query()->where('commerce_order_item_id',$line->id)->count();$toIssue=max(0,(int)$line->quantity-$already);for($i=0;$i<$toIssue;$i++)EventPass::create(['event_id'=>$order->event_id,'ticket_id'=>$line->ticket_id,'commerce_order_item_id'=>$line->id,'user_id'=>$order->user_id,'holder_name'=>trim(($order->user->first_name??'').' '.($order->user->last_name??''))?:null,'holder_email'=>$order->user->email??null,'token'=>'PASS-'.Str::upper(Str::replace('-','',(string)Str::uuid())),'status'=>'issued']);}
            foreach($order->items->where('type','ticket')as$line){$issued=EventPass::query()->where('commerce_order_item_id',$line->id)->count();if($issued<(int)$line->quantity)throw new RuntimeException('Emissão incompleta para um item do pedido.');}

            $metadata=$order->metadata??[];$metadata['fulfillment_status']='completed';$metadata['fulfilled_at']=now()->toIso8601String();$metadata['fulfillment_last_attempt_at']=now()->toIso8601String();unset($metadata['fulfillment_error'],$metadata['fulfillment_failed_at']);
            $payment->update(['status'=>'paid','paid_at'=>$payment->paid_at?:now(),'failed_at'=>null]);
            // DB update avoids firing the model callback while still inside the transaction.
            DB::table('commerce_orders')->where('app_id',$this->context->id())->where('id',$order->id)->update(['status'=>'paid','paid_at'=>$order->paid_at?:now(),'cancelled_at'=>null,'metadata'=>json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'updated_at'=>now()]);
            DB::table('inventory_reservations')->where('app_id',$this->context->id())->where('order_id',$order->id)->whereNull('released_at')->update(['released_at'=>now(),'updated_at'=>now()]);
            if(!DB::table('ledger_entries')->where('app_id',$this->context->id())->where('payment_id',$payment->id)->where('type','gross_sale')->exists()){
                $organizationDescription=$settlementMode==='automatic_split'?'Crédito líquido da organização via split do provedor':'Crédito líquido da organização a repassar pela plataforma';
                foreach([['type'=>'gross_sale','amount'=>$order->subtotal,'description'=>'Venda aprovada pelo provedor'],['type'=>'platform_fee','amount'=>-$order->platform_fee,'description'=>'Comissão da plataforma'],['type'=>'producer_credit','amount'=>$order->producer_net,'description'=>$organizationDescription]]as$entry)DB::table('ledger_entries')->insert(array_merge($entry,['app_id'=>$this->context->id(),'production_id'=>$order->production_id,'order_id'=>$order->id,'payment_id'=>$payment->id,'status'=>'posted','metadata'=>json_encode(['provider'=>'mercadopago','settlement_mode'=>$settlementMode]),'created_at'=>now(),'updated_at'=>now()]));
            }
            $approvedOrderId=(int)$order->id;
        });
        return$approvedOrderId;
    }

    private function reversePayment(CommercePayment $payment,CommerceOrder $order,string $status):void
    {
        if(in_array($payment->status,['refunded','charged_back'],true))return;$payment->update(['status'=>$status,'refunded_at'=>now()]);DB::table('commerce_orders')->where('app_id',$this->context->id())->where('id',$order->id)->update(['status'=>$status,'updated_at'=>now()]);$itemIds=$order->items->where('type','ticket')->pluck('id');EventPass::query()->whereIn('commerce_order_item_id',$itemIds)->whereIn('status',['issued','active'])->update(['status'=>$status==='charged_back'?'charged_back':'refunded','updated_at'=>now()]);DB::table('ledger_entries')->where('app_id',$this->context->id())->where('payment_id',$payment->id)->whereIn('type',['gross_sale','platform_fee','producer_credit'])->where('status','posted')->update(['status'=>'reversed','updated_at'=>now()]);
        if(!DB::table('ledger_entries')->where('app_id',$this->context->id())->where('payment_id',$payment->id)->where('type','reversal')->exists())DB::table('ledger_entries')->insert(['app_id'=>$this->context->id(),'production_id'=>$order->production_id,'order_id'=>$order->id,'payment_id'=>$payment->id,'type'=>'reversal','status'=>'posted','amount'=>-(float)$order->subtotal,'description'=>$status==='charged_back'?'Reversão por contestação/chargeback':'Reversão por reembolso','metadata'=>json_encode(['provider'=>'mercadopago','remote_status'=>$status]),'created_at'=>now(),'updated_at'=>now()]);
    }

    private function paymentAccessToken(CommercePayment $payment,CommerceOrder $order):string
    {
        $mode=(string)data_get($order->metadata,'settlement_mode',data_get($payment->provider_payload,'metadata.settlement_mode','automatic_split'));if($mode==='platform_collection'){$token=trim((string)config('services.mercadopago.access_token'));if($token==='')throw new RuntimeException('Token do provedor da plataforma não configurado.');return$token;}return$this->accounts->accessTokenForOrganization((int)$order->production_id)[1];
    }
    private function assertOrganizationOwner(Request $request,int $id):void{abort_unless($this->isOrganizationOwner($request,$id),403);}
    private function isOrganizationOwner(Request $request,int $id):bool{$organization=Production::query()->where('app_id',$this->context->id())->find($id);if(!$organization||!$request->user())return false;$admin=method_exists($request->user(),'hasProfile')&&$request->user()->hasProfile('Administrador');return$admin||(int)$organization->user_id===(int)$request->user()->id;}
    private function stateKey(string $state):string{return'payments:mercadopago:oauth:'.hash('sha256',$state);}
}
