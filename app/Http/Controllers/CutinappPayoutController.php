<?php

namespace App\Http\Controllers;

use App\Models\Production;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CutinappPayoutController extends Controller
{
    public function summary(Request $request, int $productionId)
    {
        $this->ownedProduction($request, $productionId);

        $account = DB::table('cutinapp_producer_payment_accounts')
            ->where('production_id', $productionId)
            ->where('provider', 'mercadopago')
            ->first();

        $connected = (bool) ($account && $account->status === 'connected' && $account->access_token);
        $balance = $this->balance($productionId);

        $history = DB::table('cutinapp_payout_requests')
            ->where('production_id', $productionId)
            ->latest('id')
            ->limit(50)
            ->get();

        return response()->json([
            'provider' => 'mercadopago',
            'producer_connected' => $connected,
            'current_settlement_mode' => $connected ? 'automatic_split' : 'platform_collection',
            'platform_collected_credit' => $balance['credit'],
            'available_for_payout' => $balance['available'],
            'payout_pending' => $balance['pending'],
            'payout_paid' => $balance['paid'],
            'history' => $history,
        ]);
    }

    public function requestPayout(Request $request, int $productionId)
    {
        $production = $this->ownedProduction($request, $productionId);
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:999999999.99',
        ]);

        $account = DB::table('cutinapp_producer_payment_accounts')
            ->where('production_id', $productionId)
            ->where('provider', 'mercadopago')
            ->where('status', 'connected')
            ->first();

        abort_unless($account && $account->access_token, 422, 'Conecte a conta Mercado Pago da produção antes de solicitar o repasse.');

        $payout = DB::transaction(function () use ($request, $production, $data) {
            Production::query()->whereKey($production->id)->lockForUpdate()->firstOrFail();
            $balance = $this->balance((int) $production->id);
            $amount = round((float) $data['amount'], 2);

            abort_if($amount > $balance['available'] + 0.00001, 422, 'O valor solicitado é maior que o saldo disponível para repasse.');

            $id = DB::table('cutinapp_payout_requests')->insertGetId([
                'production_id' => $production->id,
                'requested_by_user_id' => $request->user()->id,
                'reference' => 'CUT-PAYOUT-' . strtoupper(Str::random(18)),
                'provider' => 'mercadopago',
                'settlement_mode' => 'platform_collection',
                'amount' => $amount,
                'status' => 'pending',
                'metadata' => json_encode([
                    'provider_recipient_id' => DB::table('cutinapp_producer_payment_accounts')->where('production_id', $production->id)->value('provider_recipient_id'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'requested_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('cutinapp_payout_requests')->find($id);
        });

        return response()->json([
            'message' => 'Repasse solicitado. O valor ficou reservado até a liquidação.',
            'payout' => $payout,
            'balance' => $this->balance($productionId),
        ], 201);
    }

    public function cancel(Request $request, int $productionId, int $payoutId)
    {
        $this->ownedProduction($request, $productionId);

        $updated = DB::table('cutinapp_payout_requests')
            ->where('id', $payoutId)
            ->where('production_id', $productionId)
            ->where('status', 'pending')
            ->update([
                'status' => 'cancelled',
                'updated_at' => now(),
            ]);

        abort_unless($updated, 422, 'Somente repasses pendentes podem ser cancelados.');

        return response()->json([
            'message' => 'Solicitação de repasse cancelada.',
            'balance' => $this->balance($productionId),
        ]);
    }

    private function balance(int $productionId): array
    {
        $credit = (float) DB::table('cutinapp_ledger_entries')
            ->where('production_id', $productionId)
            ->where('type', 'producer_credit')
            ->where('status', 'posted')
            ->where('metadata->settlement_mode', 'platform_collection')
            ->sum('amount');

        $pending = (float) DB::table('cutinapp_payout_requests')
            ->where('production_id', $productionId)
            ->whereIn('status', ['pending', 'processing'])
            ->sum('amount');

        $paid = (float) DB::table('cutinapp_payout_requests')
            ->where('production_id', $productionId)
            ->where('status', 'paid')
            ->sum('amount');

        return [
            'credit' => round($credit, 2),
            'pending' => round($pending, 2),
            'paid' => round($paid, 2),
            'available' => round(max(0, $credit - $pending - $paid), 2),
        ];
    }

    private function ownedProduction(Request $request, int $productionId): Production
    {
        $production = Production::query()->findOrFail($productionId);
        $user = $request->user();
        $admin = $user && method_exists($user, 'hasProfile') && $user->hasProfile('Administrador');
        abort_unless($user && ($admin || (int) $production->user_id === (int) $user->id), 403);
        return $production;
    }
}
