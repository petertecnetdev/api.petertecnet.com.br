<?php

namespace App\Http\Middleware;

use App\Models\Event;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnsureVerifiedFinancialRecipient
{
    public function handle(Request $request, Closure $next)
    {
        $isCheckout = $request->is('api/cutinapp/checkout') && $request->isMethod('post');
        $isCatalog = $request->is('api/cutinapp/events/public/*/commerce') && $request->isMethod('get');

        if (!$isCheckout && !$isCatalog) return $next($request);

        $productionId = $this->productionId($request, $isCheckout);
        $recipientReady = $productionId ? $this->recipientReady($productionId) : false;
        $paymentPlatformReady = $this->paymentPlatformReady();
        $ready = $recipientReady && $paymentPlatformReady;

        if ($isCheckout && !$ready) {
            return response()->json([
                'message' => $recipientReady
                    ? 'Os recebimentos desta produção estão verificados, mas a plataforma de pagamentos ainda não está habilitada.'
                    : 'Esta produção ainda não ativou os recebimentos. O produtor precisa verificar a identidade e cadastrar uma chave Pix.',
            ], 422);
        }

        // A nova arquitetura nunca deve voltar ao split OAuth legado. Mesmo que
        // uma autorização antiga ainda exista, o checkout usa somente a conta da
        // plataforma e o repasse posterior para a chave Pix verificada.
        if ($isCheckout && $ready && $productionId) {
            DB::table('cutinapp_producer_payment_accounts')
                ->where('production_id', $productionId)
                ->where('provider', 'mercadopago')
                ->where('status', 'connected')
                ->update([
                    'status' => 'legacy_disabled',
                    'updated_at' => now(),
                ]);
        }

        $response = $next($request);
        if (!$isCatalog || !$response instanceof JsonResponse) return $response;

        $payload = $response->getData(true);
        if (!is_array($payload) || !isset($payload['payment_config'])) return $response;

        if (!$ready) {
            $payload['payment_config'] = array_merge($payload['payment_config'], [
                'connected' => false,
                'available' => false,
                'producer_connected' => false,
                'settlement_mode' => 'sales_disabled',
                'public_key' => '',
                'methods' => [],
                'message' => $recipientReady
                    ? 'Recebimentos verificados. A plataforma de pagamentos ainda não está habilitada para novas vendas.'
                    : 'Vendas pagas aguardando a ativação dos recebimentos via Pix pelo produtor.',
            ]);
        } else {
            $publicKey = trim((string) config('services.mercadopago.public_key'));
            $methods = ['pix'];
            if ($publicKey !== '') $methods[] = 'card';
            $payload['payment_config'] = array_merge($payload['payment_config'], [
                'connected' => true,
                'available' => true,
                'producer_connected' => false,
                'settlement_mode' => 'platform_collection',
                'public_key' => $publicKey,
                'methods' => $methods,
                'message' => 'Pagamentos habilitados. O produtor recebe posteriormente na chave Pix verificada.',
            ]);
        }

        $response->setData($payload);
        return $response;
    }

    private function productionId(Request $request, bool $checkout): ?int
    {
        if ($checkout) {
            $eventId = (int) $request->input('event_id');
            if ($eventId <= 0) return null;
            $id = Event::query()->whereKey($eventId)->value('production_id');
            return $id ? (int) $id : null;
        }

        $slug = (string) $request->route('slug');
        if ($slug === '') return null;
        $id = Event::query()->where('slug', $slug)->value('production_id');
        return $id ? (int) $id : null;
    }

    private function paymentPlatformReady(): bool
    {
        return (bool) config('services.cutinapp.allow_platform_collection', false)
            && trim((string) config('services.mercadopago.access_token')) !== '';
    }

    private function recipientReady(int $productionId): bool
    {
        $production = DB::table('productions')->where('id', $productionId)->first();
        if (!$production) return false;

        $beneficiary = DB::table('financial_beneficiaries')
            ->where('user_id', $production->user_id)
            ->where('status', 'verified')
            ->first();
        if (!$beneficiary) return false;

        return DB::table('financial_payout_destinations')
            ->where('source_type', 'production')
            ->where('source_id', $productionId)
            ->where('beneficiary_id', $beneficiary->id)
            ->whereIn('status', ['active', 'cooling'])
            ->whereNotNull('verified_at')
            ->exists();
    }
}
