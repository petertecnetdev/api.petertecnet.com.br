<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\CutinappEventItem;
use App\Models\CutinappOrder;
use App\Models\CutinappOrderItem;
use App\Models\CutinappPayment;
use App\Models\Event;
use App\Models\EventPass;
use App\Models\Production;
use App\Models\Ticket;
use App\Services\PixEfiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CutinappCommerceController extends Controller
{
    private const APP = 'cutinapp';

    public function __construct(private PixEfiService $pix)
    {
    }

    public function catalog(string $slug)
    {
        $event = Event::query()->where('app_slug', self::APP)->where('slug', $slug)->where('is_published', true)->where('is_cancelled', false)->firstOrFail();

        $tickets = Ticket::query()->where('event_id', $event->id)->where('app_slug', self::APP)->where('price', '>', 0)->orderBy('price')->get();
        $items = CutinappEventItem::query()->where('event_id', $event->id)->where('is_active', true)->orderBy('name')->get();

        return response()->json(['event'=>$event->only(['id','title','slug','start_date','end_date']),'tickets'=>$tickets,'items'=>$items]);
    }

    public function checkout(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401, 'Faça login para concluir a compra.');
        $data = $request->validate([
            'event_id'=>'required|integer|exists:events,id',
            'tickets'=>'nullable|array|max:20','tickets.*.id'=>'required_with:tickets|integer','tickets.*.quantity'=>'required_with:tickets|integer|min:1|max:20',
            'items'=>'nullable|array|max:30','items.*.id'=>'required_with:items|integer','items.*.quantity'=>'required_with:items|integer|min:1|max:50',
            'payment_method'=>'required|in:pix',
        ]);
        abort_if(empty($data['tickets']) && empty($data['items']), 422, 'Selecione ao menos um ingresso ou item.');

        $application = Application::query()->where('slug', self::APP)->where('is_active', true)->firstOrFail();
        $platformRate = max(0, min((float) config('services.cutinapp.platform_fee_percent', 8), 100));

        $order = DB::transaction(function () use ($data, $user, $application, $platformRate) {
            $event = Event::query()->where('id', $data['event_id'])->where('app_id', $application->id)->where('app_slug', self::APP)->with('production')->lockForUpdate()->firstOrFail();
            abort_if(!$event->is_published || $event->is_cancelled || $event->is_private, 422, 'Este evento não está disponível para venda.');
            abort_unless($event->production && (int)$event->production->app_id === (int)$application->id, 422, 'A produção do evento é inválida.');

            $expiresAt = now()->addMinutes(15);
            $order = CutinappOrder::create([
                'public_id'=>(string) Str::uuid(),'event_id'=>$event->id,'production_id'=>$event->production_id,'user_id'=>$user->id,
                'status'=>'pending','currency'=>'BRL','payment_method'=>$data['payment_method'],'expires_at'=>$expiresAt,
            ]);
            $subtotal = 0.0;

            foreach (($data['tickets'] ?? []) as $requested) {
                $ticket = Ticket::query()->where('id',$requested['id'])->where('event_id',$event->id)->where('app_id',$application->id)->where('app_slug',self::APP)->lockForUpdate()->firstOrFail();
                abort_if((float)$ticket->price <= 0, 422, 'Cortesias gratuitas não entram no checkout pago.');
                abort_if($ticket->limit_date && now()->greaterThan($ticket->limit_date), 422, "O lote {$ticket->name} não está mais disponível.");
                $issued = EventPass::query()->where('ticket_id',$ticket->id)->count();
                $reserved = DB::table('cutinapp_inventory_reservations')->where('ticket_id',$ticket->id)->whereNull('released_at')->where('expires_at','>',now())->sum('quantity');
                $qty = (int)$requested['quantity'];
                abort_if($issued + $reserved + $qty > (int)$ticket->quantity, 422, "Não há quantidade suficiente no lote {$ticket->name}.");
                $line = round((float)$ticket->price * $qty, 2);
                CutinappOrderItem::create(['order_id'=>$order->id,'type'=>'ticket','ticket_id'=>$ticket->id,'name'=>$ticket->name,'unit_price'=>$ticket->price,'quantity'=>$qty,'subtotal'=>$line]);
                DB::table('cutinapp_inventory_reservations')->insert(['order_id'=>$order->id,'type'=>'ticket','ticket_id'=>$ticket->id,'quantity'=>$qty,'expires_at'=>$expiresAt,'created_at'=>now(),'updated_at'=>now()]);
                $subtotal += $line;
            }

            foreach (($data['items'] ?? []) as $requested) {
                $item = CutinappEventItem::query()->where('id',$requested['id'])->where('event_id',$event->id)->where('is_active',true)->lockForUpdate()->firstOrFail();
                $reserved = DB::table('cutinapp_inventory_reservations')->where('event_item_id',$item->id)->whereNull('released_at')->where('expires_at','>',now())->sum('quantity');
                $sold = DB::table('cutinapp_order_items as oi')->join('cutinapp_orders as o','o.id','=','oi.order_id')->where('oi.event_item_id',$item->id)->where('o.status','paid')->sum('oi.quantity');
                $qty = (int)$requested['quantity'];
                abort_if($sold + $reserved + $qty > (int)$item->quantity, 422, "Não há quantidade suficiente de {$item->name}.");
                $line = round((float)$item->price * $qty, 2);
                CutinappOrderItem::create(['order_id'=>$order->id,'type'=>'item','event_item_id'=>$item->id,'name'=>$item->name,'unit_price'=>$item->price,'quantity'=>$qty,'subtotal'=>$line]);
                DB::table('cutinapp_inventory_reservations')->insert(['order_id'=>$order->id,'type'=>'item','event_item_id'=>$item->id,'quantity'=>$qty,'expires_at'=>$expiresAt,'created_at'=>now(),'updated_at'=>now()]);
                $subtotal += $line;
            }

            $platformFee = round($subtotal * ($platformRate / 100), 2);
            $order->update(['subtotal'=>$subtotal,'platform_fee'=>$platformFee,'total'=>$subtotal,'producer_net'=>max(0,$subtotal-$platformFee)]);
            return $order->fresh(['items','event','production']);
        });

        $pixKey = trim((string) config('services.efi.pix_key'));
        if ($pixKey === '') {
            $this->cancelOrder($order);
            return response()->json(['message'=>'O recebimento PIX da Cutinapp ainda não foi configurado.'],503);
        }

        try {
            $charge = $this->pix->createCharge((string)$order->total, $pixKey, 'Pedido Cutinapp ' . $order->public_id, [
                ['nome'=>'Pedido','valor'=>$order->public_id], ['nome'=>'Evento','valor'=>(string)$order->event->title],
            ]);
            $locationId = $charge['loc']['id'] ?? null;
            $qr = $locationId ? $this->pix->getQrCode($locationId) : [];
            $payment = CutinappPayment::create([
                'order_id'=>$order->id,'provider'=>'efi','method'=>'pix','status'=>'pending','provider_payment_id'=>(string)($locationId ?? ''),
                'provider_txid'=>$charge['txid'] ?? null,'amount'=>$order->total,'qr_code'=>$qr['qrcode'] ?? null,'qr_code_image'=>$qr['imagemQrcode'] ?? null,'provider_payload'=>['charge'=>$charge,'qr'=>$qr],
            ]);
        } catch (RuntimeException $e) {
            report($e); $this->cancelOrder($order);
            return response()->json(['message'=>'Não foi possível gerar o PIX. Tente novamente.'],502);
        }

        return response()->json(['message'=>'Pedido criado. Pague o PIX para liberar seus ingressos.','order'=>$order->fresh(['items','event','production']),'payment'=>$payment],201);
    }

    public function mine(Request $request)
    {
        return response()->json(['orders'=>CutinappOrder::query()->where('user_id',$request->user()->id)->with(['items','event','payments'])->latest()->paginate(20)]);
    }

    public function show(Request $request, string $publicId)
    {
        $order = CutinappOrder::query()->where('public_id',$publicId)->with(['items','event.production','payments'])->firstOrFail();
        abort_unless((int)$order->user_id === (int)$request->user()->id || $this->ownsProduction($request,$order->production_id),403);
        return response()->json(['order'=>$order]);
    }

    public function webhook(Request $request)
    {
        $expected = trim((string) config('services.cutinapp.webhook_token'));
        if ($expected !== '' && ! hash_equals($expected, (string)$request->query('token'))) abort(401,'Webhook inválido.');
        foreach ((array)$request->input('pix',[]) as $pix) {
            $txid = trim((string)($pix['txid'] ?? ''));
            if ($txid === '') continue;
            $this->markPaidByTxid($txid, $pix);
        }
        return response()->json(['ok'=>true]);
    }

    public function upsertEventItem(Request $request, int $eventId, ?int $itemId = null)
    {
        $event = $this->ownedEvent($request,$eventId);
        $data = $request->validate(['name'=>'required|string|max:140','description'=>'nullable|string|max:2000','price'=>'required|numeric|min:0.01|max:999999.99','quantity'=>'required|integer|min:0|max:1000000','is_active'=>'sometimes|boolean']);
        $item = $itemId ? CutinappEventItem::query()->where('event_id',$event->id)->findOrFail($itemId) : new CutinappEventItem(['event_id'=>$event->id]);
        $item->fill($data)->save();
        return response()->json(['item'=>$item],$itemId?200:201);
    }

    public function deleteEventItem(Request $request, int $eventId, int $itemId)
    {
        $event = $this->ownedEvent($request,$eventId);
        $item = CutinappEventItem::query()->where('event_id',$event->id)->findOrFail($itemId);
        $item->update(['is_active'=>false]);
        return response()->json(['message'=>'Item desativado.']);
    }

    public function paymentAccount(Request $request, int $productionId)
    {
        $this->ownedProduction($request,$productionId);
        if ($request->isMethod('get')) return response()->json(['account'=>DB::table('cutinapp_producer_payment_accounts')->where('production_id',$productionId)->first()]);
        $data = $request->validate(['pix_key'=>'required|string|max:255']);
        DB::table('cutinapp_producer_payment_accounts')->updateOrInsert(['production_id'=>$productionId],[
            'provider'=>'efi','status'=>'verified','pix_key'=>trim($data['pix_key']),'verified_at'=>now(),'updated_at'=>now(),'created_at'=>now(),
        ]);
        return response()->json(['message'=>'Conta de recebimento atualizada.','account'=>DB::table('cutinapp_producer_payment_accounts')->where('production_id',$productionId)->first()]);
    }

    public function financialSummary(Request $request, int $productionId)
    {
        $this->ownedProduction($request,$productionId);
        $gross = (float) DB::table('cutinapp_ledger_entries')->where('production_id',$productionId)->where('type','gross_sale')->sum('amount');
        $fees = abs((float) DB::table('cutinapp_ledger_entries')->where('production_id',$productionId)->where('type','platform_fee')->sum('amount'));
        $earned = (float) DB::table('cutinapp_ledger_entries')->where('production_id',$productionId)->where('type','producer_credit')->sum('amount');
        $paid = (float) DB::table('cutinapp_payouts')->where('production_id',$productionId)->whereIn('status',['processing','paid'])->sum('amount');
        return response()->json(['gross_sales'=>$gross,'platform_fees'=>$fees,'producer_earned'=>$earned,'paid_or_processing'=>$paid,'available'=>max(0,round($earned-$paid,2))]);
    }

    public function payout(Request $request, int $productionId)
    {
        $this->ownedProduction($request,$productionId);
        $data = $request->validate(['amount'=>'nullable|numeric|min:0.01']);
        $account = DB::table('cutinapp_producer_payment_accounts')->where('production_id',$productionId)->where('status','verified')->first();
        abort_unless($account && $account->pix_key,422,'Cadastre e valide a chave PIX do produtor antes do repasse.');
        $earned = (float) DB::table('cutinapp_ledger_entries')->where('production_id',$productionId)->where('type','producer_credit')->sum('amount');
        $committed = (float) DB::table('cutinapp_payouts')->where('production_id',$productionId)->whereIn('status',['processing','paid'])->sum('amount');
        $available = max(0,round($earned-$committed,2));
        $amount = isset($data['amount']) ? round((float)$data['amount'],2) : $available;
        abort_if($amount <= 0 || $amount > $available,422,'Valor de repasse superior ao saldo disponível.');
        $sourceKey = trim((string)config('services.efi.payout_source_pix_key'));
        abort_if($sourceKey === '',503,'A chave PIX de origem para repasses ainda não foi configurada.');
        $publicId = (string)Str::uuid();
        $payoutId = DB::table('cutinapp_payouts')->insertGetId(['public_id'=>$publicId,'production_id'=>$productionId,'payment_account_id'=>$account->id,'provider'=>'efi','status'=>'processing','amount'=>$amount,'requested_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        try {
            $provider = $this->pix->sendPix(str_replace('-','',$publicId), (string)$amount, $sourceKey, $account->pix_key, 'Repasse Cutinapp');
            $status = strtoupper((string)($provider['status'] ?? '')) === 'REALIZADO' ? 'paid' : 'processing';
            DB::table('cutinapp_payouts')->where('id',$payoutId)->update(['status'=>$status,'provider_payout_id'=>$provider['idEnvio'] ?? str_replace('-','',$publicId),'provider_payload'=>json_encode($provider),'paid_at'=>$status==='paid'?now():null,'updated_at'=>now()]);
            return response()->json(['message'=>$status==='paid'?'Repasse realizado.':'Repasse enviado para processamento.','payout'=>DB::table('cutinapp_payouts')->where('id',$payoutId)->first()],201);
        } catch (RuntimeException $e) {
            DB::table('cutinapp_payouts')->where('id',$payoutId)->update(['status'=>'failed','failed_at'=>now(),'updated_at'=>now()]);
            report($e); return response()->json(['message'=>'Não foi possível realizar o repasse PIX.'],502);
        }
    }

    private function markPaidByTxid(string $txid, array $payload): void
    {
        DB::transaction(function () use ($txid,$payload) {
            $payment = CutinappPayment::query()->where('provider_txid',$txid)->lockForUpdate()->first();
            if (!$payment || $payment->status === 'paid') return;
            $order = CutinappOrder::query()->with('items')->lockForUpdate()->findOrFail($payment->order_id);
            if ($order->status !== 'pending') return;
            $received = round((float)($payload['valor'] ?? 0),2);
            abort_if(abs($received-(float)$order->total) > 0.01,422,'Valor PIX divergente do pedido.');
            $payment->update(['status'=>'paid','paid_at'=>now(),'provider_payload'=>array_merge($payment->provider_payload ?? [],['webhook'=>$payload])]);
            $order->update(['status'=>'paid','paid_at'=>now()]);
            foreach ($order->items->where('type','ticket') as $line) {
                for ($i=0;$i<(int)$line->quantity;$i++) EventPass::create(['ticket_id'=>$line->ticket_id,'event_id'=>$order->event_id,'user_id'=>$order->user_id,'holder_name'=>trim((string)($order->user?->first_name ?? 'Participante')),'holder_email'=>strtolower(trim((string)($order->user?->email ?? ''))),'token'=>'CUT-'.Str::upper(Str::random(16)).'-'.Str::uuid(),'status'=>'issued']);
            }
            DB::table('cutinapp_inventory_reservations')->where('order_id',$order->id)->update(['released_at'=>now(),'updated_at'=>now()]);
            $entries = [
                ['type'=>'gross_sale','amount'=>$order->subtotal,'description'=>'Venda Cutinapp'],
                ['type'=>'platform_fee','amount'=>-$order->platform_fee,'description'=>'Taxa da plataforma'],
                ['type'=>'producer_credit','amount'=>$order->producer_net,'description'=>'Crédito do produtor'],
            ];
            foreach ($entries as $entry) DB::table('cutinapp_ledger_entries')->insert(['production_id'=>$order->production_id,'order_id'=>$order->id,'payment_id'=>$payment->id,'type'=>$entry['type'],'status'=>'posted','amount'=>$entry['amount'],'description'=>$entry['description'],'created_at'=>now(),'updated_at'=>now()]);
        });
    }

    private function cancelOrder(CutinappOrder $order): void
    {
        $order->update(['status'=>'cancelled','cancelled_at'=>now()]);
        DB::table('cutinapp_inventory_reservations')->where('order_id',$order->id)->update(['released_at'=>now(),'updated_at'=>now()]);
    }

    private function ownedEvent(Request $request,int $eventId): Event
    {
        $event = Event::query()->where('app_slug',self::APP)->with('production')->findOrFail($eventId);
        abort_unless($event->production && $this->ownsProduction($request,$event->production_id),403,'Sem permissão para gerenciar este evento.');
        return $event;
    }

    private function ownedProduction(Request $request,int $productionId): Production
    {
        $production = Production::query()->where('app_slug',self::APP)->findOrFail($productionId);
        abort_unless($this->ownsProduction($request,$productionId),403,'Sem permissão para gerenciar esta produção.');
        return $production;
    }

    private function ownsProduction(Request $request,int $productionId): bool
    {
        $user = $request->user();
        return $user && ($user->hasProfile('Administrador') || Production::query()->where('id',$productionId)->where('user_id',$user->id)->exists());
    }
}
