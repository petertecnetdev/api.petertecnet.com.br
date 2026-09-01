<?php

namespace App\Http\Controllers;

use App\Models\CutinappOrder;
use App\Models\CutinappPayment;
use App\Models\EventPass;
use App\Services\MercadoPagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CutinappMercadoPagoController extends Controller
{
    public function __construct(private MercadoPagoService $mercadoPago) {}

    public function connect(Request $request, int $productionId)
    {
        $this->assertProductionOwner($request, $productionId);
        $state = Crypt::encryptString(json_encode([
            'production_id' => $productionId,
            'user_id' => $request->user()->id,
            'nonce' => (string) Str::uuid(),
            'expires_at' => now()->addMinutes(10)->timestamp,
        ]));

        return response()->json(['authorization_url' => $this->mercadoPago->authorizationUrl($state)]);
    }

    public function callback(Request $request)
    {
        $request->validate(['code' => 'required|string', 'state' => 'required|string']);
        try {
            $state = json_decode(Crypt::decryptString($request->string('state')->toString()), true, flags: JSON_THROW_ON_ERROR);
            abort_if(($state['expires_at'] ?? 0) < now()->timestamp, 422, 'A autorização expirou. Tente conectar novamente.');
            $tokens = $this->mercadoPago->exchangeAuthorizationCode($request->string('code')->toString());
            $productionId = (int) ($state['production_id'] ?? 0);
            abort_if($productionId <= 0, 422, 'Produção inválida.');

            DB::table('cutinapp_producer_payment_accounts')->updateOrInsert(
                ['production_id' => $productionId],
                [
                    'provider' => 'mercadopago',
                    'status' => 'connected',
                    'pix_key' => null,
                    'provider_recipient_id' => (string) ($tokens['user_id'] ?? ''),
                    'access_token' => Crypt::encryptString((string) ($tokens['access_token'] ?? '')),
                    'refresh_token' => !empty($tokens['refresh_token']) ? Crypt::encryptString((string) $tokens['refresh_token']) : null,
                    'token_expires_at' => !empty($tokens['expires_in']) ? now()->addSeconds((int) $tokens['expires_in']) : null,
                    'metadata' => json_encode(['public_key' => $tokens['public_key'] ?? null, 'scope' => $tokens['scope'] ?? null]),
                    'verified_at' => now(), 'updated_at' => now(), 'created_at' => now(),
                ]
            );

            return redirect(rtrim(config('app.frontend_url', 'https://cutinapp.petertecnet.com.br'), '/') . '/producer/finance?mercadopago=connected');
        } catch (RuntimeException $e) {
            report($e);
            return response('Não foi possível conectar o Mercado Pago.', 502);
        }
    }

    public function webhook(Request $request)
    {
        $dataId = (string) ($request->query('data.id') ?: data_get($request->all(), 'data.id', ''));
        abort_unless($this->mercadoPago->validateWebhookSignature($request->header('x-signature'), $request->header('x-request-id'), $dataId), 401, 'Assinatura inválida.');

        if ($dataId === '') return response()->json(['ok' => true]);
        $payment = CutinappPayment::query()->where('provider', 'mercadopago')->where('provider_payment_id', $dataId)->first();
        if (!$payment) return response()->json(['ok' => true]);

        $account = DB::table('cutinapp_producer_payment_accounts')->where('production_id', $payment->order->production_id)->where('provider', 'mercadopago')->first();
        if (!$account || !$account->access_token) return response()->json(['ok' => true]);

        try {
            $remote = $this->mercadoPago->getPayment(Crypt::decryptString($account->access_token), $dataId);
            $this->syncPayment($payment, $remote);
        } catch (RuntimeException $e) {
            report($e);
            return response()->json(['ok' => false], 502);
        }

        return response()->json(['ok' => true]);
    }

    private function syncPayment(CutinappPayment $payment, array $remote): void
    {
        DB::transaction(function () use ($payment, $remote) {
            $payment = CutinappPayment::query()->lockForUpdate()->findOrFail($payment->id);
            $order = CutinappOrder::query()->with('items')->lockForUpdate()->findOrFail($payment->order_id);
            $status = (string) ($remote['status'] ?? 'pending');
            $amount = round((float) ($remote['transaction_amount'] ?? 0), 2);

            if ($status !== 'approved') {
                $payment->update(['status' => $status, 'provider_payload' => $remote, 'failed_at' => in_array($status, ['rejected','cancelled'], true) ? now() : null]);
                return;
            }
            if ($payment->status === 'paid') return;
            abort_if(abs($amount - (float) $order->total) > 0.009, 422, 'Valor confirmado pelo Mercado Pago é diferente do pedido.');

            $payment->update(['status' => 'paid', 'provider_payload' => $remote, 'paid_at' => now()]);
            $order->update(['status' => 'paid', 'paid_at' => now()]);

            foreach ($order->items->where('type', 'ticket') as $line) {
                for ($i = 0; $i < $line->quantity; $i++) {
                    EventPass::create([
                        'app_slug' => 'cutinapp', 'app_id' => $order->event->app_id ?? null, 'event_id' => $order->event_id,
                        'ticket_id' => $line->ticket_id, 'user_id' => $order->user_id,
                        'holder_name' => $order->user->name ?? null, 'holder_email' => $order->user->email ?? null,
                        'token' => 'CUT-' . Str::upper(Str::replace('-', '', (string) Str::uuid())), 'status' => 'issued', 'issued_at' => now(),
                    ]);
                }
            }
            DB::table('cutinapp_inventory_reservations')->where('order_id', $order->id)->whereNull('released_at')->update(['released_at' => now(), 'updated_at' => now()]);

            $entries = [
                ['type'=>'gross_sale','amount'=>$order->subtotal,'description'=>'Venda aprovada pelo Mercado Pago'],
                ['type'=>'platform_fee','amount'=>-$order->platform_fee,'description'=>'Comissão Peter Tecnet/Cutinapp'],
                ['type'=>'producer_credit','amount'=>$order->producer_net,'description'=>'Valor líquido do produtor'],
            ];
            foreach ($entries as $entry) DB::table('cutinapp_ledger_entries')->insert(array_merge($entry, ['production_id'=>$order->production_id,'order_id'=>$order->id,'payment_id'=>$payment->id,'status'=>'posted','created_at'=>now(),'updated_at'=>now()]));
        });
    }

    private function assertProductionOwner(Request $request, int $productionId): void
    {
        $production = DB::table('productions')->where('id', $productionId)->first();
        abort_unless($production, 404);
        $admin = method_exists($request->user(), 'hasProfile') && $request->user()->hasProfile('Administrador');
        abort_unless($admin || (int) $production->user_id === (int) $request->user()->id, 403);
    }
}
