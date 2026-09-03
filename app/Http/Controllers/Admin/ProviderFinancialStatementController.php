<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EcosystemPayment;
use App\Models\ProviderStatementEntry;
use App\Models\ProviderStatementReport;
use App\Services\Payments\ProviderFinancialStatementService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ProviderFinancialStatementController extends Controller
{
    public function __construct(private readonly ProviderFinancialStatementService $statements) {}

    public function summary(Request $request)
    {
        [$from, $to] = $this->period($request);
        $provider = $request->filled('provider') ? (string) $request->input('provider') : 'mercadopago';

        return response()->json([
            'statement' => $this->statements->snapshot($from, $to, $provider),
            'rule' => 'Saldo do provedor e saques bancários vêm do extrato oficial importado; pagamento confirmado e saldo liquidado permanecem estados distintos.',
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function reports(Request $request)
    {
        if (! Schema::hasTable('provider_statement_reports')) return response()->json(['data' => [], 'total' => 0]);

        $query = ProviderStatementReport::query()->orderByDesc('requested_at');
        if ($request->filled('provider')) $query->where('provider', (string) $request->input('provider'));
        if ($request->filled('status')) $query->where('status', (string) $request->input('status'));

        return response()->json($query->paginate(min(max((int) $request->input('per_page', 50), 10), 200)));
    }

    public function entries(Request $request)
    {
        if (! Schema::hasTable('provider_statement_entries')) return response()->json(['data' => [], 'total' => 0]);

        $query = ProviderStatementEntry::query()->orderByDesc('occurred_at')->orderByDesc('id');
        if ($request->filled('provider')) $query->where('provider', (string) $request->input('provider'));
        if ($request->filled('record_type')) $query->where('record_type', (string) $request->input('record_type'));
        if ($request->filled('description')) $query->where('description', (string) $request->input('description'));
        if ($request->filled('from')) $query->where('occurred_at', '>=', CarbonImmutable::parse((string) $request->input('from'))->startOfDay());
        if ($request->filled('to')) $query->where('occurred_at', '<=', CarbonImmutable::parse((string) $request->input('to'))->endOfDay());

        return response()->json($query->paginate(min(max((int) $request->input('per_page', 100), 10), 300)));
    }

    public function payment(Request $request, int $payment)
    {
        $model = EcosystemPayment::query()->findOrFail($payment);
        if (! Schema::hasTable('provider_statement_entries')) return response()->json(['payment_id' => $payment, 'entries' => []]);

        $entries = ProviderStatementEntry::query()
            ->where('provider', $model->provider)
            ->where(function ($query) use ($model) {
                if ($model->provider_payment_id) $query->where('provider_source_id', (string) $model->provider_payment_id);
                if ($model->source_reference) {
                    $model->provider_payment_id
                        ? $query->orWhere('external_reference', (string) $model->source_reference)
                        : $query->where('external_reference', (string) $model->source_reference);
                }
                if (! $model->provider_payment_id && ! $model->source_reference) $query->whereRaw('1 = 0');
            })
            ->orderBy('occurred_at')
            ->get();

        return response()->json([
            'payment_id' => $payment,
            'settlement_status' => $model->settlement_status,
            'settled_at' => $model->settled_at?->toIso8601String(),
            'settlement_net_amount' => $model->settlement_net_amount !== null ? (float) $model->settlement_net_amount : null,
            'entries' => $entries,
        ]);
    }

    public function sync(Request $request)
    {
        $provider = (string) $request->input('provider', 'mercadopago');
        $stats = $this->statements->maintain($provider, $request->boolean('force'));

        return response()->json([
            'message' => $stats['configured'] ? 'Sincronização de extrato executada.' : 'Provedor sem credencial configurada para extrato.',
            'stats' => $stats,
            'statement' => $this->statements->snapshot(null, null, $provider),
        ]);
    }

    private function period(Request $request): array
    {
        $from = $request->filled('from')
            ? CarbonImmutable::parse((string) $request->input('from'))->startOfDay()
            : CarbonImmutable::now()->subDays(29)->startOfDay();
        $to = $request->filled('to')
            ? CarbonImmutable::parse((string) $request->input('to'))->endOfDay()
            : CarbonImmutable::now()->endOfDay();

        return [$from, $to];
    }
}
