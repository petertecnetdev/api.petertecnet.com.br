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
        $ready = $productionId ? $this->isReady($productionId) : false;

        if ($isCheckout && !$ready) {
            return response()->json([
                'message' => 'Esta produção ainda não ativou os recebimentos. O produtor precisa verificar a identidade e cadastrar uma chave Pix.',
            ], 422);
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
                'message' => 'Vendas pagas aguardando a ativação dos recebimentos via Pix pelo produtor.',
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

    private function isReady(int $productionId): bool
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
            ->exists();
    }
}
