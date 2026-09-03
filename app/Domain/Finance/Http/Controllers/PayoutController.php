<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Production;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Provider-neutral payout request compatibility surface.
 *
 * This controller deliberately scopes every row by the current application and
 * uses shared finance tables. Application-specific enablement stays in config.
 */
final class PayoutController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function summary(Request $request, int $organizationId)
    {
        $this->ownedOrganization($request, $organizationId);

        $account = DB::table('merchant_payment_accounts')
            ->where('app_id', $this->context->id())
            ->where('production_id', $organizationId)
            ->where('provider', 'mercadopago')
            ->first();

        $connected = (bool) ($account && $account->status === 'connected' && $account->access_token);
        $balance = $this->balance($organizationId);
        $manualPayoutsEnabled = (bool) config('services.'.$this->context->slug().'.manual_payout_requests_enabled', false);
        $allowPlatformCollection = (bool) config('services.'.$this->context->slug().'.allow_platform_collection', false);

        $history = DB::table('payout_requests')
            ->where('app_id', $this->context->id())
            ->where('production_id', $organizationId)
            ->latest('id')
            ->limit(50)
            ->get();

        return response()->json([
            'provider' => 'mercadopago',
            'producer_connected' => $connected,
            'current_settlement_mode' => $connected
                ? 'automatic_split'
                : ($allowPlatformCollection ? 'platform_collection' : 'sales_disabled'),
            'manual_payout_requests_enabled' => $manualPayoutsEnabled,
            'platform_collection_enabled' => $allowPlatformCollection,
            'platform_collected_credit' => $balance['credit'],
            'available_for_payout' => $balance['available'],
            'payout_pending' => $balance['pending'],
            'payout_paid' => $balance['paid'],
            'history' => $history,
        ]);
    }

    public function requestPayout(Request $request, int $organizationId)
    {
        abort_unless(
            (bool) config('services.'.$this->context->slug().'.manual_payout_requests_enabled', false),
            503,
            'Solicitações manuais de repasse ainda não estão habilitadas para esta aplicação.'
        );

        $organization = $this->ownedOrganization($request, $organizationId);
        $data = $request->validate(['amount' => 'required|numeric|min:0.01|max:999999999.99']);

        $account = DB::table('merchant_payment_accounts')
            ->where('app_id', $this->context->id())
            ->where('production_id', $organizationId)
            ->where('provider', 'mercadopago')
            ->where('status', 'connected')
            ->first();

        abort_unless($account && $account->access_token, 422, 'Conecte a conta de pagamento antes de solicitar o repasse.');

        $payout = DB::transaction(function () use ($request, $organization, $data) {
            Production::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $balance = $this->balance((int) $organization->id);
            $amount = round((float) $data['amount'], 2);

            abort_if($amount > $balance['available'] + 0.00001, 422, 'O valor solicitado é maior que o saldo disponível para repasse.');

            $id = DB::table('payout_requests')->insertGetId([
                'app_id' => $this->context->id(),
                'production_id' => $organization->id,
                'requested_by_user_id' => $request->user()->id,
                'reference' => 'PAYOUT-'.strtoupper(Str::random(18)),
                'provider' => 'mercadopago',
                'settlement_mode' => 'platform_collection',
                'amount' => $amount,
                'status' => 'pending',
                'metadata' => json_encode([
                    'provider_recipient_id' => DB::table('merchant_payment_accounts')
                        ->where('app_id', $this->context->id())
                        ->where('production_id', $organization->id)
                        ->value('provider_recipient_id'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'requested_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('payout_requests')->find($id);
        });

        return response()->json([
            'message' => 'Solicitação registrada. O valor ficou reservado até a liquidação operacional.',
            'payout' => $payout,
            'balance' => $this->balance($organizationId),
        ], 201);
    }

    public function cancel(Request $request, int $organizationId, int $payoutId)
    {
        $this->ownedOrganization($request, $organizationId);

        $updated = DB::table('payout_requests')
            ->where('app_id', $this->context->id())
            ->where('id', $payoutId)
            ->where('production_id', $organizationId)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled', 'updated_at' => now()]);

        abort_unless($updated, 422, 'Somente repasses pendentes podem ser cancelados.');

        return response()->json([
            'message' => 'Solicitação de repasse cancelada.',
            'balance' => $this->balance($organizationId),
        ]);
    }

    private function balance(int $organizationId): array
    {
        $credit = (float) DB::table('ledger_entries')
            ->where('app_id', $this->context->id())
            ->where('production_id', $organizationId)
            ->where('type', 'producer_credit')
            ->where('status', 'posted')
            ->where('metadata->settlement_mode', 'platform_collection')
            ->sum('amount');

        $pending = (float) DB::table('payout_requests')
            ->where('app_id', $this->context->id())
            ->where('production_id', $organizationId)
            ->whereIn('status', ['pending', 'processing'])
            ->sum('amount');

        $paid = (float) DB::table('payout_requests')
            ->where('app_id', $this->context->id())
            ->where('production_id', $organizationId)
            ->where('status', 'paid')
            ->sum('amount');

        return [
            'credit' => round($credit, 2),
            'pending' => round($pending, 2),
            'paid' => round($paid, 2),
            'available' => round(max(0, $credit - $pending - $paid), 2),
        ];
    }

    private function ownedOrganization(Request $request, int $organizationId): Production
    {
        $organization = Production::query()
            ->where('app_id', $this->context->id())
            ->findOrFail($organizationId);

        $user = $request->user();
        $admin = $user && method_exists($user, 'hasProfile') && $user->hasProfile('Administrador');
        abort_unless($user && ($admin || (int) $organization->user_id === (int) $user->id), 403);

        return $organization;
    }
}
