<?php

namespace App\Domain\Commerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CommerceOrder;
use App\Models\Production;
use App\Support\ApplicationContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class OrderHistoryController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function purchases(Request $request)
    {
        return response()->json(CommerceOrder::query()
            ->where('app_id',$this->context->id())->where('user_id',$request->user()->id)
            ->with(['event:id,title,slug,start_date,end_date','production:id,name,slug','items','payments'=>fn($q)=>$q->latest('id')])
            ->latest('id')->paginate(min(max((int)$request->integer('per_page',20),1),50)));
    }

    public function purchase(Request $request,string $publicId)
    {
        $order=$this->findOrder($publicId);abort_unless((int)$order->user_id===(int)$request->user()->id,403);return response()->json(['order'=>$this->decorate($order)]);
    }

    public function organizationSales(Request $request,int $organizationId)
    {
        $this->ownedOrganization($request,$organizationId);$query=CommerceOrder::query()->where('app_id',$this->context->id())->where('production_id',$organizationId)->with(['user:id,first_name,last_name,email','event:id,title,slug,start_date,end_date','items','payments'=>fn($q)=>$q->latest('id')]);
        if($request->filled('status'))$query->where('status',$request->string('status')->toString());if($request->filled('event_id'))$query->where('event_id',$request->integer('event_id'));if($request->filled('from'))$query->whereDate('created_at','>=',$request->date('from'));if($request->filled('to'))$query->whereDate('created_at','<=',$request->date('to'));
        if($request->filled('q')){$term=trim($request->string('q')->toString());$query->where(fn($q)=>$q->where('public_id','like',"%{$term}%")->orWhereHas('user',fn($u)=>$u->where('email','like',"%{$term}%")->orWhere('first_name','like',"%{$term}%")->orWhere('last_name','like',"%{$term}%")));}
        $orders=$query->latest('id')->paginate(min(max((int)$request->integer('per_page',30),1),100));$base=CommerceOrder::query()->where('app_id',$this->context->id())->where('production_id',$organizationId);
        return response()->json(['orders'=>$orders,'summary'=>['paid_count'=>(clone$base)->where('status','paid')->count(),'pending_count'=>(clone$base)->where('status','pending')->count(),'cancelled_count'=>(clone$base)->whereIn('status',['cancelled','refunded','charged_back'])->count(),'gross_paid'=>round((float)(clone$base)->where('status','paid')->sum('total'),2),'platform_fees'=>round((float)(clone$base)->where('status','paid')->sum('platform_fee'),2),'processor_fees'=>round((float)(clone$base)->where('status','paid')->sum('processor_fee'),2),'organization_net'=>round((float)(clone$base)->where('status','paid')->sum('producer_net'),2)]]);
    }

    public function organizationSale(Request $request,int $organizationId,string $publicId){$this->ownedOrganization($request,$organizationId);$order=$this->findOrder($publicId);abort_unless((int)$order->production_id===$organizationId,404);return response()->json(['order'=>$this->decorate($order)]);}
    public function receipt(Request $request,string $publicId){$order=$this->findOrder($publicId);$this->authorizeOrderAccess($request,$order);return response()->json(['receipt'=>$this->receiptData($order)]);}
    public function receiptPdf(Request $request,string $publicId){$order=$this->findOrder($publicId);$this->authorizeOrderAccess($request,$order);$receipt=$this->receiptData($order);$application=$this->context->application();return Pdf::loadView('pdf.order-receipt',compact('receipt','application'))->setPaper('a4')->download('recibo-'.substr($order->public_id,0,8).'.pdf');}

    private function findOrder(string $publicId):CommerceOrder{return CommerceOrder::query()->where('app_id',$this->context->id())->where('public_id',$publicId)->with(['user:id,first_name,last_name,email','event:id,title,slug,start_date,end_date','production:id,name,slug','items','payments'=>fn($q)=>$q->latest('id')])->firstOrFail();}
    private function decorate(CommerceOrder $order):array{$data=$order->toArray();$data['passes']=DB::table('event_passes as ep')->join('commerce_order_items as oi','oi.id','=','ep.commerce_order_item_id')->where('oi.order_id',$order->id)->select('ep.id','ep.ticket_id','ep.status','ep.checked_in_at','ep.created_at')->orderBy('ep.id')->get();$data['fulfillment_status']=data_get($order->metadata,'fulfillment_status');return$data;}
    private function receiptData(CommerceOrder $order):array{$payment=$order->payments->first();$buyer=trim(implode(' ',array_filter([$order->user?->first_name,$order->user?->last_name])));return['number'=>strtoupper(substr($order->public_id,0,8)),'public_id'=>$order->public_id,'status'=>$order->status,'created_at'=>optional($order->created_at)->toIso8601String(),'paid_at'=>optional($order->paid_at)->toIso8601String(),'currency'=>$order->currency,'subtotal'=>$order->subtotal,'discount_amount'=>$order->discount_amount,'total'=>$order->total,'payment_method'=>$order->payment_method,'event'=>$order->event,'organization'=>$order->production,'buyer'=>['name'=>$buyer!==''?$buyer:($order->user?->email??'Cliente'),'email'=>$order->user?->email],'items'=>$order->items,'payment'=>$payment?['provider'=>$payment->provider,'status'=>$payment->status,'provider_payment_id'=>$payment->provider_payment_id,'amount'=>$payment->amount,'created_at'=>optional($payment->created_at)->toIso8601String()]:null,'fulfillment_status'=>data_get($order->metadata,'fulfillment_status'),'generated_at'=>now()->toIso8601String()];}
    private function authorizeOrderAccess(Request $request,CommerceOrder $order):void{if((int)$order->user_id===(int)$request->user()->id)return;$this->ownedOrganization($request,(int)$order->production_id);}
    private function ownedOrganization(Request $request,int $id):Production{$organization=Production::query()->where('app_id',$this->context->id())->findOrFail($id);$admin=method_exists($request->user(),'hasProfile')&&$request->user()->hasProfile('Administrador');abort_unless($admin||(int)$organization->user_id===(int)$request->user()->id,403);return$organization;}
}
