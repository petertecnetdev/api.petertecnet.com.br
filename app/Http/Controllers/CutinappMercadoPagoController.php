<?php

namespace App\Http\Controllers;

use App\Models\CutinappOrder;
use App\Models\CutinappPayment;
use App\Models\EventPass;
use App\Services\MercadoPagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CutinappMercadoPagoController extends Controller
{
    public function __construct(private MercadoPagoService $mercadoPago) {}

    public function connect(Request $request, int $productionId)
    {
        $this->assertProductionOwner($request, $productionId);

        $state = Str::random(64);
        Cache::put($this->stateKey($state), [
            'production_id' => $productionId,
            'actor_id' => (int) $request->user()->id,
            'created_at' => now()->timestamp,
        ], now()->addMinutes(10));

        return response()->json([
            'authorization_url' => $this->mercadoPago->authorizationUrl($state),
            'expires_in' => 600,
        ]);
    }

    public function callback(Request $request)
    {
        $request->validate(['code' => 'required|string', 'state' => 'required|string|min:32|max:200']);
        $stateToken = $request->string('state')->toString();
        $state = Cache::pull($this->stateKey($stateToken));
        abort_unless(is_array($state), 422, 'A autorização expirou ou já foi utilizada. Conecte a conta novamente.');

        $productionId = (int) ($state['production_id'] ?? 0);
        abort_if($productionId <= 0 || !DB::table('productions')->where('id', $productionId)->exists(), 422, 'Produção da autorização inválida.');

        try {
            $tokens = $this->mercadoPago->exchangeAuthorizationCode($request->string('code')->toString());
            $accessToken = trim((string) ($tokens['access_token'] ?? ''));
            if ($accessToken === '') throw new RuntimeException('O Mercado Pago não retornou um token de acesso válido.');

            $now = now();
            DB::table('cutinapp_producer_payment_accounts')->updateOrInsert(
                ['production_id' => $productionId],
                [
                    'provider' => 'mercadopago',
                    'status' => 'connected',
                    'provider_recipient_id' => (string) ($tokens['user_id'] ?? ''),
                    'access_token' => Crypt::encryptString($accessToken),
                    'refresh_token' => !empty($tokens['refresh_token']) ? Crypt::encryptString((string) $tokens['refresh_token']) : null,
                    'token_expires_at' => !empty($tokens['expires_in']) ? $now->copy()->addSeconds((int) $tokens['expires_in']) : null,
                    'metadata' => json_encode([
                        'public_key' => $tokens['public_key'] ?? null,
                        'scope' => $tokens['scope'] ?? null,
                        'live_mode' => $tokens['live_mode'] ?? null,
                        'connected_by_user_id' => $state['actor_id'] ?? null,
                    ], JSON_UNESCAPED_UNICODE),
                    'connected_at' => $now,
                    'verified_at' => $now,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            $frontend = rtrim((string) config('services.cutinapp.frontend_url', 'https://cutinapp.petertecnet.com.br'), '/');
            return redirect($frontend . '/producer/finance?mercadopago=connected&production=' . $productionId);
        } catch (Throwable $e) {
            report($e);
            return response('Não foi possível conectar o Mercado Pago. Volte à Cutinapp e tente novamente.', 502);
        }
    }

    public function webhook(Request $request)
    {
        $dataId = (string) ($request->query('data.id') ?: data_get($request->all(), 'data.id', ''));
        if ($dataId === '') return response()->json(['ok' => true]);

        abort_unless(
            $this->mercadoPago->validateWebhookSignature(
                $request->header('x-signature'),
                $request->header('x-request-id'),
                $dataId
            ),
            401,
            'Assinatura inválida.'
        );

        $type = (string) ($request->input('type') ?: $request->query('type', 'payment'));
        if ($type !== 'payment') return response()->json(['ok' => true]);

        $payment = CutinappPayment::query()
            ->where('provider', 'mercadopago')
            ->where('provider_payment_id', $dataId)
            ->with('order')
            ->first();

        if (!$payment || !$payment->order) return response()->json(['ok' => true]);

        try {
            $token = $this->paymentAccessToken($payment, $payment->order);
            $remote = $this->mercadoPago->getPayment($token, $dataId);
            $this->syncPayment($payment, $remote);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['ok' => false], 502);
        }

        return response()->json(['ok' => true]);
    }

    public function sync(Request $request, string $publicId)
    {
        $order = CutinappOrder::query()->where('public_id', $publicId)->with('payments')->firstOrFail();
        abort_unless((int) $order->user_id === (int) $request->user()->id || $this->isProductionOwner($request, (int) $order->production_id), 403);

        $payment = $order->payments()->where('provider', 'mercadopago')->latest('id')->first();
        if (!$payment || !$payment->provider_payment_id) return response()->json(['order' => $order->fresh(['payments'])]);

        try {
            $token = $this->paymentAccessToken($payment, $order);
            $remote = $this->mercadoPago->getPayment($token, (string) $payment->provider_payment_id);
            $this->syncPayment($payment, $remote);
        } catch (Throwable $e) {
            report($e);
        }

        return response()->json(['order' => $order->fresh(['items','event','payments'])]);
    }

    private function syncPayment(CutinappPayment $payment, array $remote): void
    {
        DB::transaction(function () use ($payment, $remote) {
            $payment = CutinappPayment::query()->lockForUpdate()->findOrFail($payment->id);
            $order = CutinappOrder::query()->with(['items','event','user'])->lockForUpdate()->findOrFail($payment->order_id);

            $remoteId = (string) ($remote['id'] ?? '');
            $status = (string) ($remote['status'] ?? 'pending');
            $amount = round((float) ($remote['transaction_amount'] ?? 0), 2);
            $externalReference = (string) ($remote['external_reference'] ?? '');
            $providerFee = collect($remote['fee_details'] ?? [])->sum(fn ($fee) => (float) ($fee['amount'] ?? 0));
            $settlementMode = (string) data_get($order->metadata, 'settlement_mode', 'automatic_split');

            abort_if($remoteId === '' || $remoteId !== (string) $payment->provider_payment_id, 422, 'Pagamento remoto não corresponde ao pagamento local.');
            abort_if($externalReference === '' || $externalReference !== (string) $order->public_id, 422, 'Referência externa do pagamento é inválida.');
            abort_if(abs($amount - (float) $order->total) > 0.009, 422, 'Valor confirmado pelo Mercado Pago é diferente do pedido.');

            if ($settlementMode === 'automatic_split' && array_key_exists('application_fee', $remote) && abs((float) $remote['application_fee'] - (float) $order->platform_fee) > 0.009) {
                abort(422, 'A comissão confirmada pelo Mercado Pago é diferente da comissão do pedido.');
            }

            $payment->update(['provider_fee' => round($providerFee, 2), 'provider_payload' => $remote]);
            $order->update(['processor_fee' => round($providerFee, 2)]);

            if (in_array($status, ['refunded', 'charged_back'], true)) {
                $this->reversePayment($payment, $order, $status);
                return;
            }

            if (in_array($status, ['rejected', 'cancelled'], true)) {
                if ($payment->status !== 'paid') {
                    $payment->update(['status' => $status, 'failed_at' => now()]);
                    $order->update(['status' => 'cancelled', 'cancelled_at' => now()]);
                    DB::table('cutinapp_inventory_reservations')->where('order_id', $order->id)->whereNull('released_at')->update(['released_at'=>now(),'updated_at'=>now()]);
                }
                return;
            }

            if ($status !== 'approved') {
                $payment->update(['status' => $status]);
                return;
            }

            if ($payment->status === 'paid' && $order->status === 'paid') return;

            $payment->update(['status' => 'paid', 'paid_at' => $payment->paid_at ?: now(), 'failed_at' => null]);
            $order->update(['status' => 'paid', 'paid_at' => $order->paid_at ?: now(), 'cancelled_at' => null]);

            foreach ($order->items->where('type', 'ticket') as $line) {
                $alreadyIssued = EventPass::query()->where('cutinapp_order_item_id', $line->id)->count();
                $toIssue = max(0, (int) $line->quantity - $alreadyIssued);

                for ($i = 0; $i < $toIssue; $i++) {
                    EventPass::create([
                        'event_id' => $order->event_id,
                        'ticket_id' => $line->ticket_id,
                        'cutinapp_order_item_id' => $line->id,
                        'user_id' => $order->user_id,
                        'holder_name' => trim(($order->user->first_name ?? '') . ' ' . ($order->user->last_name ?? '')) ?: null,
                        'holder_email' => $order->user->email ?? null,
                        'token' => 'CUT-' . Str::upper(Str::replace('-', '', (string) Str::uuid())),
                        'status' => 'issued',
                    ]);
                }
            }

            DB::table('cutinapp_inventory_reservations')->where('order_id', $order->id)->whereNull('released_at')->update(['released_at' => now(), 'updated_at' => now()]);

            if (!DB::table('cutinapp_ledger_entries')->where('payment_id', $payment->id)->where('type', 'gross_sale')->exists()) {
                $producerDescription = $settlementMode === 'automatic_split'
                    ? 'Crédito líquido do produtor via split Mercado Pago'
                    : 'Crédito líquido do produtor a repassar pela plataforma';
                foreach ([
                    ['type'=>'gross_sale','amount'=>$order->subtotal,'description'=>'Venda aprovada pelo Mercado Pago'],
                    ['type'=>'platform_fee','amount'=>-$order->platform_fee,'description'=>'Comissão Peter Tecnet / Cutinapp'],
                    ['type'=>'producer_credit','amount'=>$order->producer_net,'description'=>$producerDescription],
                ] as $entry) {
                    DB::table('cutinapp_ledger_entries')->insert(array_merge($entry, [
                        'production_id'=>$order->production_id,'order_id'=>$order->id,'payment_id'=>$payment->id,
                        'status'=>'posted','metadata'=>json_encode(['provider'=>'mercadopago','settlement_mode'=>$settlementMode]), 'created_at'=>now(),'updated_at'=>now(),
                    ]));
                }
            }
        });
    }

    private function reversePayment(CutinappPayment $payment, CutinappOrder $order, string $status): void
    {
        if (in_array($payment->status, ['refunded','charged_back'], true)) return;

        $payment->update(['status' => $status, 'refunded_at' => now()]);
        $order->update(['status' => $status]);

        $orderItemIds = $order->items->where('type', 'ticket')->pluck('id');
        EventPass::query()
            ->whereIn('cutinapp_order_item_id', $orderItemIds)
            ->whereIn('status', ['issued','active'])
            ->update(['status' => $status === 'charged_back' ? 'charged_back' : 'refunded', 'updated_at' => now()]);

        DB::table('cutinapp_ledger_entries')
            ->where('payment_id', $payment->id)
            ->whereIn('type', ['gross_sale','platform_fee','producer_credit'])
            ->where('status', 'posted')
            ->update(['status' => 'reversed', 'updated_at' => now()]);

        if (!DB::table('cutinapp_ledger_entries')->where('payment_id', $payment->id)->where('type', 'reversal')->exists()) {
            DB::table('cutinapp_ledger_entries')->insert([
                'production_id' => $order->production_id,
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'type' => 'reversal',
                'status' => 'posted',
                'amount' => -(float) $order->subtotal,
                'description' => $status === 'charged_back' ? 'Reversão por contestação/chargeback' : 'Reversão por reembolso',
                'metadata' => json_encode(['provider'=>'mercadopago','remote_status'=>$status]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function paymentAccessToken(CutinappPayment $payment, CutinappOrder $order): string
    {
        $settlementMode = (string) data_get($order->metadata, 'settlement_mode', data_get($payment->provider_payload, 'metadata.settlement_mode', 'automatic_split'));
        if ($settlementMode === 'platform_collection') {
            $token = trim((string) config('services.mercadopago.access_token'));
            if ($token === '') throw new RuntimeException('Token Mercado Pago da plataforma não configurado.');
            return $token;
        }

        $account = DB::table('cutinapp_producer_payment_accounts')
            ->where('production_id', $order->production_id)
            ->where('provider', 'mercadopago')
            ->where('status', 'connected')
            ->first();
        if (!$account || !$account->access_token) throw new RuntimeException('Conta Mercado Pago da produção indisponível.');
        return $this->sellerToken($account)[1];
    }

    private function sellerToken(object $account): array
    {
        $accessToken = Crypt::decryptString($account->access_token);
        if (!$account->token_expires_at || now()->lt($account->token_expires_at)) return [$account, $accessToken];
        if (!$account->refresh_token) throw new RuntimeException('A autorização do Mercado Pago expirou e precisa ser renovada.');

        $tokens = $this->mercadoPago->refreshAccessToken(Crypt::decryptString($account->refresh_token));
        $newAccess = trim((string) ($tokens['access_token'] ?? ''));
        if ($newAccess === '') throw new RuntimeException('O Mercado Pago não retornou um novo token de acesso.');

        DB::table('cutinapp_producer_payment_accounts')->where('id', $account->id)->update([
            'access_token' => Crypt::encryptString($newAccess),
            'refresh_token' => !empty($tokens['refresh_token']) ? Crypt::encryptString((string) $tokens['refresh_token']) : $account->refresh_token,
            'token_expires_at' => !empty($tokens['expires_in']) ? now()->addSeconds((int) $tokens['expires_in']) : null,
            'status' => 'connected',
            'updated_at' => now(),
        ]);

        return [DB::table('cutinapp_producer_payment_accounts')->find($account->id), $newAccess];
    }

    private function assertProductionOwner(Request $request, int $productionId): void
    {
        abort_unless($this->isProductionOwner($request, $productionId), 403);
    }

    private function isProductionOwner(Request $request, int $productionId): bool
    {
        $production = DB::table('productions')->where('id', $productionId)->first();
        if (!$production || !$request->user()) return false;
        $admin = method_exists($request->user(), 'hasProfile') && $request->user()->hasProfile('Administrador');
        return $admin || (int) $production->user_id === (int) $request->user()->id;
    }

    private function stateKey(string $state): string
    {
        return 'cutinapp:mercadopago:oauth:' . hash('sha256', $state);
    }
}
