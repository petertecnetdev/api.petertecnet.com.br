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
use App\Services\MercadoPagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class CutinappCommerceController extends Controller
{
    private const APP = 'cutinapp';

    public function __construct(private MercadoPagoService $mercadoPago) {}

    public function catalog(string $slug)
    {
        $event = Event::query()
            ->where('app_slug', self::APP)
            ->where('slug', $slug)
            ->where('is_published', true)
            ->where('is_cancelled', false)
            ->firstOrFail();

        $tickets = Ticket::query()->where('event_id', $event->id)->where('app_slug', self::APP)->where('price', '>', 0)->orderBy('price')->get();
        $items = CutinappEventItem::query()->where('event_id', $event->id)->where('is_active', true)->orderBy('name')->get();
        $account = DB::table('cutinapp_producer_payment_accounts')->where('production_id', $event->production_id)->where('provider', 'mercadopago')->first();
        $metadata = $account?->metadata ? json_decode($account->metadata, true) : [];
        $producerConnected = (bool) ($account && $account->status === 'connected' && $account->access_token);
        $platformToken = trim((string) config('services.mercadopago.access_token'));
        $platformPublicKey = trim((string) config('services.mercadopago.public_key'));
        $platformAvailable = $platformToken !== '' && $platformPublicKey !== '';
        $checkoutAvailable = $producerConnected || $platformAvailable;

        return response()->json([
            'event' => $event->only(['id','title','slug','start_date','end_date']),
            'tickets' => $tickets,
            'items' => $items,
            'payment_config' => [
                'provider' => 'mercadopago',
                'connected' => $checkoutAvailable,
                'available' => $checkoutAvailable,
                'producer_connected' => $producerConnected,
                'settlement_mode' => $producerConnected ? 'automatic_split' : ($platformAvailable ? 'platform_collection' : 'unavailable'),
                'public_key' => $producerConnected ? ($metadata['public_key'] ?? $platformPublicKey) : $platformPublicKey,
                'methods' => ['pix', 'card'],
            ],
        ]);
    }

    public function checkout(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401, 'Faça login para concluir a compra.');

        $data = $request->validate([
            'event_id' => 'required|integer|exists:events,id',
            'tickets' => 'nullable|array|max:20',
            'tickets.*.id' => 'required_with:tickets|integer',
            'tickets.*.quantity' => 'required_with:tickets|integer|min:1|max:20',
            'items' => 'nullable|array|max:30',
            'items.*.id' => 'required_with:items|integer',
            'items.*.quantity' => 'required_with:items|integer|min:1|max:50',
            'payment_method' => 'required|in:pix,card',
            'card_token' => 'required_if:payment_method,card|nullable|string|max:300',
            'payment_method_id' => 'required_if:payment_method,card|nullable|string|max:80',
            'issuer_id' => 'nullable|string|max:80',
            'installments' => 'required_if:payment_method,card|nullable|integer|min:1|max:24',
            'payer_identification_type' => 'required_if:payment_method,card|nullable|string|in:CPF',
            'payer_identification_number' => 'required_if:payment_method,card|nullable|string|max:30',
            'payer_email' => 'nullable|email|max:190',
        ]);

        abort_if(empty($data['tickets']) && empty($data['items']), 422, 'Selecione ao menos um ingresso ou item.');
        if ($data['payment_method'] === 'card') {
            $document = preg_replace('/\D+/', '', (string) ($data['payer_identification_number'] ?? ''));
            abort_if(strlen($document) !== 11, 422, 'Informe um CPF válido para o titular do cartão.');
            $data['payer_identification_number'] = $document;
        }

        $application = Application::query()->where('slug', self::APP)->where('is_active', true)->firstOrFail();
        $platformRate = max(0, min((float) config('services.cutinapp.platform_fee_percent', 8), 100));

        $order = DB::transaction(function () use ($data, $user, $application, $platformRate) {
            $event = Event::query()
                ->where('id', $data['event_id'])
                ->where('app_id', $application->id)
                ->where('app_slug', self::APP)
                ->with('production')
                ->lockForUpdate()
                ->firstOrFail();

            abort_if(!$event->is_published || $event->is_cancelled || $event->is_private, 422, 'Este evento não está disponível para venda.');
            abort_unless($event->production && (int) $event->production->app_id === (int) $application->id, 422, 'A produção do evento é inválida.');

            $expiresAt = now()->addMinutes(15);
            $order = CutinappOrder::create([
                'public_id' => (string) Str::uuid(),
                'event_id' => $event->id,
                'production_id' => $event->production_id,
                'user_id' => $user->id,
                'status' => 'pending',
                'currency' => 'BRL',
                'payment_method' => $data['payment_method'],
                'expires_at' => $expiresAt,
                'metadata' => ['app_slug' => self::APP],
            ]);

            $subtotal = 0.0;

            foreach (($data['tickets'] ?? []) as $requested) {
                $ticket = Ticket::query()
                    ->where('id', $requested['id'])
                    ->where('event_id', $event->id)
                    ->where('app_id', $application->id)
                    ->where('app_slug', self::APP)
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_if((float) $ticket->price <= 0, 422, 'Cortesias gratuitas não entram no checkout pago.');
                abort_if($ticket->limit_date && now()->greaterThan($ticket->limit_date), 422, "O lote {$ticket->name} não está mais disponível.");

                $issued = EventPass::query()->where('ticket_id', $ticket->id)->whereNotIn('status', ['cancelled','refunded','charged_back'])->count();
                $reserved = DB::table('cutinapp_inventory_reservations')->where('ticket_id', $ticket->id)->whereNull('released_at')->where('expires_at', '>', now())->sum('quantity');
                $qty = (int) $requested['quantity'];
                abort_if($issued + $reserved + $qty > (int) $ticket->quantity, 422, "Não há quantidade suficiente no lote {$ticket->name}.");

                $line = round((float) $ticket->price * $qty, 2);
                CutinappOrderItem::create(['order_id'=>$order->id,'type'=>'ticket','ticket_id'=>$ticket->id,'name'=>$ticket->name,'unit_price'=>$ticket->price,'quantity'=>$qty,'subtotal'=>$line]);
                DB::table('cutinapp_inventory_reservations')->insert(['order_id'=>$order->id,'type'=>'ticket','ticket_id'=>$ticket->id,'quantity'=>$qty,'expires_at'=>$expiresAt,'created_at'=>now(),'updated_at'=>now()]);
                $subtotal += $line;
            }

            foreach (($data['items'] ?? []) as $requested) {
                $item = CutinappEventItem::query()->where('id', $requested['id'])->where('event_id', $event->id)->where('is_active', true)->lockForUpdate()->firstOrFail();
                $reserved = DB::table('cutinapp_inventory_reservations')->where('event_item_id', $item->id)->whereNull('released_at')->where('expires_at', '>', now())->sum('quantity');
                $sold = DB::table('cutinapp_order_items as oi')->join('cutinapp_orders as o','o.id','=','oi.order_id')->where('oi.event_item_id', $item->id)->where('o.status', 'paid')->sum('oi.quantity');
                $qty = (int) $requested['quantity'];
                abort_if($sold + $reserved + $qty > (int) $item->quantity, 422, "Não há quantidade suficiente de {$item->name}.");

                $line = round((float) $item->price * $qty, 2);
                CutinappOrderItem::create(['order_id'=>$order->id,'type'=>'item','event_item_id'=>$item->id,'name'=>$item->name,'unit_price'=>$item->price,'quantity'=>$qty,'subtotal'=>$line]);
                DB::table('cutinapp_inventory_reservations')->insert(['order_id'=>$order->id,'type'=>'item','event_item_id'=>$item->id,'quantity'=>$qty,'expires_at'=>$expiresAt,'created_at'=>now(),'updated_at'=>now()]);
                $subtotal += $line;
            }

            $platformFee = round($subtotal * ($platformRate / 100), 2);
            $order->update(['subtotal'=>$subtotal,'platform_fee'=>$platformFee,'total'=>$subtotal,'producer_net'=>max(0, $subtotal - $platformFee)]);
            return $order->fresh(['items','event','production','user']);
        });

        $account = DB::table('cutinapp_producer_payment_accounts')
            ->where('production_id', $order->production_id)
            ->where('provider', 'mercadopago')
            ->where('status', 'connected')
            ->first();

        $usesProducerAccount = (bool) ($account && $account->access_token);
        $platformToken = trim((string) config('services.mercadopago.access_token'));
        if (!$usesProducerAccount && $platformToken === '') {
            $this->cancelOrder($order);
            return response()->json(['message' => 'Pagamentos estão temporariamente indisponíveis.'], 503);
        }

        try {
            $sellerToken = $usesProducerAccount ? $this->sellerToken($account)[1] : $platformToken;
            $settlementMode = $usesProducerAccount ? 'automatic_split' : 'platform_collection';
            $order->update(['metadata' => array_merge($order->metadata ?? [], ['settlement_mode' => $settlementMode])]);

            $idempotencyKey = (string) Str::uuid();
            $payer = ['email' => $user->email];
            if ($data['payment_method'] === 'card') {
                $payer['identification'] = ['type' => 'CPF', 'number' => $data['payer_identification_number']];
            }

            $payload = [
                'transaction_amount' => (float) $order->total,
                'description' => mb_substr('Cutinapp - ' . ($order->event->title ?? 'Evento'), 0, 255),
                'external_reference' => $order->public_id,
                'notification_url' => rtrim((string) config('app.url'), '/') . '/api/cutinapp/payments/mercadopago/webhook',
                'payer' => $payer,
                'metadata' => ['app_slug'=>self::APP,'order_id'=>$order->id,'order_public_id'=>$order->public_id,'production_id'=>$order->production_id,'settlement_mode'=>$settlementMode],
            ];
            if ($usesProducerAccount) $payload['application_fee'] = (float) $order->platform_fee;

            if ($data['payment_method'] === 'pix') {
                $payload['payment_method_id'] = 'pix';
            } else {
                $payload['token'] = $data['card_token'];
                $payload['payment_method_id'] = $data['payment_method_id'];
                $payload['installments'] = (int) $data['installments'];
                if (!empty($data['issuer_id'])) $payload['issuer_id'] = $data['issuer_id'];
            }

            $remote = $this->mercadoPago->createPayment($sellerToken, $payload, $idempotencyKey);
            $transaction = data_get($remote, 'point_of_interaction.transaction_data', []);
            $providerFee = collect($remote['fee_details'] ?? [])->sum(fn ($fee) => (float) ($fee['amount'] ?? 0));

            $payment = CutinappPayment::create([
                'order_id' => $order->id,
                'provider' => 'mercadopago',
                'method' => $data['payment_method'],
                'status' => (string) ($remote['status'] ?? 'pending'),
                'provider_payment_id' => isset($remote['id']) ? (string) $remote['id'] : null,
                'provider_txid' => data_get($remote, 'point_of_interaction.transaction_data.transaction_id'),
                'idempotency_key' => $idempotencyKey,
                'amount' => $order->total,
                'provider_fee' => $providerFee,
                'qr_code' => $transaction['qr_code'] ?? null,
                'qr_code_image' => !empty($transaction['qr_code_base64']) ? 'data:image/png;base64,' . $transaction['qr_code_base64'] : null,
                'ticket_url' => $transaction['ticket_url'] ?? null,
                'provider_payload' => $remote,
                'failed_at' => in_array(($remote['status'] ?? ''), ['rejected','cancelled'], true) ? now() : null,
            ]);

            $order->update(['processor_fee' => $providerFee]);
        } catch (Throwable $e) {
            report($e);
            $this->cancelOrder($order);
            return response()->json(['message' => 'Não foi possível iniciar o pagamento no Mercado Pago. Tente novamente.'], 502);
        }

        return response()->json([
            'message' => $data['payment_method'] === 'pix' ? 'Pedido criado. Pague o PIX para liberar seus ingressos.' : 'Pagamento enviado ao Mercado Pago.',
            'order' => $order->fresh(['items','event','production']),
            'payment' => $payment,
        ], 201);
    }

    public function mine(Request $request)
    {
        return response()->json(['orders' => CutinappOrder::query()->where('user_id', $request->user()->id)->with(['items','event','payments'])->latest()->paginate(20)]);
    }

    public function show(Request $request, string $publicId)
    {
        $order = CutinappOrder::query()->where('public_id', $publicId)->with(['items','event.production','payments'])->firstOrFail();
        abort_unless((int) $order->user_id === (int) $request->user()->id || $this->ownsProduction($request, $order->production_id), 403);
        return response()->json(['order' => $order]);
    }

    public function upsertEventItem(Request $request, int $eventId, ?int $itemId = null)
    {
        $event = $this->ownedEvent($request, $eventId);
        $data = $request->validate(['name'=>'required|string|max:140','description'=>'nullable|string|max:2000','price'=>'required|numeric|min:0.01|max:999999.99','quantity'=>'required|integer|min:0|max:1000000','is_active'=>'sometimes|boolean']);
        $item = $itemId ? CutinappEventItem::query()->where('event_id', $event->id)->findOrFail($itemId) : new CutinappEventItem(['event_id' => $event->id]);
        $item->fill($data)->save();
        return response()->json(['item' => $item], $itemId ? 200 : 201);
    }

    public function deleteEventItem(Request $request, int $eventId, int $itemId)
    {
        $event = $this->ownedEvent($request, $eventId);
        $item = CutinappEventItem::query()->where('event_id', $event->id)->findOrFail($itemId);
        $item->update(['is_active' => false]);
        return response()->json(['message' => 'Item desativado.']);
    }

    public function paymentAccount(Request $request, int $productionId)
    {
        $this->ownedProduction($request, $productionId);
        $account = DB::table('cutinapp_producer_payment_accounts')->where('production_id', $productionId)->first();
        if (!$account) return response()->json(['account' => null]);

        return response()->json(['account' => [
            'provider' => $account->provider,
            'status' => $account->status,
            'provider_recipient_id' => $account->provider_recipient_id,
            'connected_at' => $account->connected_at,
            'verified_at' => $account->verified_at,
            'token_expires_at' => $account->token_expires_at,
        ]]);
    }

    public function financialSummary(Request $request, int $productionId)
    {
        $this->ownedProduction($request, $productionId);
        $gross = (float) DB::table('cutinapp_ledger_entries')->where('production_id', $productionId)->where('type', 'gross_sale')->where('status', 'posted')->sum('amount');
        $fees = abs((float) DB::table('cutinapp_ledger_entries')->where('production_id', $productionId)->where('type', 'platform_fee')->where('status', 'posted')->sum('amount'));
        $earned = (float) DB::table('cutinapp_ledger_entries')->where('production_id', $productionId)->where('type', 'producer_credit')->where('status', 'posted')->sum('amount');
        $processorFees = (float) DB::table('cutinapp_payments as p')->join('cutinapp_orders as o','o.id','=','p.order_id')->where('o.production_id', $productionId)->where('p.status', 'paid')->sum('p.provider_fee');

        return response()->json([
            'gross_sales' => round($gross, 2),
            'platform_fees' => round($fees, 2),
            'processor_fees' => round($processorFees, 2),
            'producer_earned' => round($earned, 2),
            'settlement' => 'automatic_split_or_platform_collection',
            'provider' => 'mercadopago',
        ]);
    }

    private function sellerToken(object $account): array
    {
        $token = Crypt::decryptString($account->access_token);
        if (!$account->token_expires_at || now()->lt($account->token_expires_at)) return [$account, $token];
        abort_unless($account->refresh_token, 422, 'A autorização do Mercado Pago expirou. Reconecte a conta do produtor.');

        $tokens = $this->mercadoPago->refreshAccessToken(Crypt::decryptString($account->refresh_token));
        $updates = [
            'access_token' => Crypt::encryptString((string) $tokens['access_token']),
            'refresh_token' => !empty($tokens['refresh_token']) ? Crypt::encryptString((string) $tokens['refresh_token']) : $account->refresh_token,
            'token_expires_at' => !empty($tokens['expires_in']) ? now()->addSeconds((int) $tokens['expires_in']) : null,
            'updated_at' => now(),
        ];
        DB::table('cutinapp_producer_payment_accounts')->where('id', $account->id)->update($updates);
        $account = DB::table('cutinapp_producer_payment_accounts')->find($account->id);
        return [$account, (string) $tokens['access_token']];
    }

    private function cancelOrder(CutinappOrder $order): void
    {
        DB::transaction(function () use ($order) {
            CutinappOrder::query()->where('id', $order->id)->where('status', 'pending')->update(['status'=>'cancelled','cancelled_at'=>now()]);
            DB::table('cutinapp_inventory_reservations')->where('order_id', $order->id)->whereNull('released_at')->update(['released_at'=>now(),'updated_at'=>now()]);
        });
    }

    private function ownedEvent(Request $request, int $eventId): Event
    {
        $event = Event::query()->where('id', $eventId)->where('app_slug', self::APP)->firstOrFail();
        $this->ownedProduction($request, (int) $event->production_id);
        return $event;
    }

    private function ownedProduction(Request $request, int $productionId): Production
    {
        $production = Production::query()->findOrFail($productionId);
        abort_unless($this->ownsProduction($request, $productionId), 403);
        return $production;
    }

    private function ownsProduction(Request $request, int $productionId): bool
    {
        $production = Production::query()->find($productionId);
        if (!$production || !$request->user()) return false;
        $admin = method_exists($request->user(), 'hasProfile') && $request->user()->hasProfile('Administrador');
        return $admin || (int) $production->user_id === (int) $request->user()->id;
    }
}
