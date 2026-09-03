<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FinancialLedgerEntry;
use App\Models\PaymentReconciliation;
use App\Services\Payments\FinancialLedgerService;
use App\Services\Payments\PaymentReconciliationService;
use App\Services\Payments\PaymentRevenueRecognitionService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinancialController extends Controller
{
    public function __construct(
        private readonly PaymentRevenueRecognitionService $recognition,
        private readonly FinancialLedgerService $ledger,
        private readonly PaymentReconciliationService $providerReconciliation,
    ) {}

    public function dashboard(Request $request)
    {
        $this->authorizeAccess($request);

        $rows = $this->normalizedPayments($request);
        $realized = $this->recognition->realized($rows);
        $failed = $this->recognition->failed($rows);
        $pending = $this->recognition->open($rows);
        $refunded = $this->recognition->reversed($rows);
        $totals = $this->recognition->totals($rows);
        [$from, $to] = $this->period($request);

        $applications = $rows->groupBy('app_slug')->map(function ($group, $slug) {
            $metrics = $this->paymentMetrics($group);

            return array_merge([
                'app_slug' => $slug,
                'application_name' => $group->first()['application_name'] ?? $slug,
            ], $metrics);
        })->sortByDesc('gross')->values();

        $providers = $rows->groupBy('provider')->map(function ($group, $provider) {
            $metrics = $this->paymentMetrics($group);

            return array_merge([
                'provider' => $provider ?: 'não informado',
                'success_rate' => $metrics['transactions']
                    ? round(($metrics['approved'] / $metrics['transactions']) * 100, 2)
                    : 0,
            ], $metrics);
        })->sortByDesc('transactions')->values();

        $methods = $rows->groupBy(fn ($row) => $row['method'] ?: 'não informado')->map(function ($group, $method) {
            return array_merge(['method' => $method], $this->paymentMetrics($group));
        })->sortByDesc('gross')->values();

        $start = now()->subDays(29)->startOfDay();
        $timelineGroups = $rows
            ->filter(fn ($row) => !empty($row['financial_at']) && strtotime((string) $row['financial_at']) >= $start->timestamp)
            ->groupBy(fn ($row) => date('Y-m-d', strtotime((string) $row['financial_at'])));

        $timeline = collect(range(0, 29))->map(function ($offset) use ($start, $timelineGroups) {
            $day = $start->copy()->addDays($offset)->format('Y-m-d');
            $group = $timelineGroups->get($day, collect());
            $realized = $this->recognition->realized($group);
            $open = $this->recognition->open($group);
            $failed = $this->recognition->failed($group);
            $realizedBucket = $this->recognition->bucket($realized);

            return [
                'day' => $day,
                'transactions' => $group->count(),
                'gross' => $realizedBucket->amount,
                'attempted_gross' => $this->recognition->bucket($group)->amount,
                'pending_gross' => $this->recognition->bucket($open)->amount,
                'platform_fees' => $realizedBucket->platform_fees,
                'approved_amount' => $realizedBucket->amount,
                'failed' => $failed->count(),
            ];
        });

        $commerce = $this->cutinappCommerceSnapshot($request);
        $providerReconciliation = $this->providerReconciliation->snapshot();
        $settlement = $this->ledger->settlementSnapshot();
        $ledgerBalance = $this->ledger->snapshot();
        $ledgerPeriod = $this->ledger->snapshot($from, $to);
        $funnel = $this->ledger->funnel($from, $to);
        $closing = $this->ledger->dailyClose($from, $to);
        $health = $this->healthSnapshot($providerReconciliation, $settlement);
        $alerts = $this->alerts($rows, $commerce, $health, $providerReconciliation, $settlement);

        return response()->json([
            'summary' => [
                'totals' => $totals,
                'approved' => $this->bucket($realized),
                'failed' => $this->bucket($failed),
                'pending' => $this->bucket($pending),
                'refunded' => $this->bucket($refunded),
            ],
            'reconciliation' => [
                'recognition_rule' => 'Somente pagamentos confirmados como paid/approved entram no caixa e na receita realizada.',
                'attempted' => $this->bucket($rows),
                'realized' => $this->bucket($realized),
                'open' => $this->bucket($pending),
                'failed' => $this->bucket($failed),
                'reversed' => $this->bucket($refunded),
            ],
            'ledger' => [
                'balance' => $ledgerBalance,
                'period' => $ledgerPeriod,
                'immutability_rule' => 'Lançamentos confirmados não são alterados; estornos, chargebacks, repasses e ajustes geram novos eventos compensatórios.',
            ],
            'settlement' => $settlement,
            'payment_funnel' => $funnel,
            'provider_reconciliation' => $providerReconciliation,
            'daily_closing' => $closing,
            'applications' => $applications,
            'providers' => $providers,
            'methods' => $methods,
            'timeline' => $timeline,
            'commerce' => $commerce,
            'health' => $health,
            'alerts' => $alerts,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function transactions(Request $request)
    {
        $this->authorizeAccess($request);
        $rows = $this->normalizedPayments($request)->sortByDesc('financial_at')->values();
        $perPage = min(max((int) $request->input('per_page', 50), 10), 200);
        $page = max((int) $request->input('page', 1), 1);
        $slice = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json(new LengthAwarePaginator(
            $slice,
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        ));
    }

    public function transaction(Request $request, int $payment)
    {
        $this->authorizeAccess($request);

        $row = Schema::hasTable('ecosystem_payments')
            ? DB::table('ecosystem_payments as p')
                ->leftJoin('applications as a', 'a.id', '=', 'p.app_id')
                ->leftJoin('users as u', 'u.id', '=', 'p.user_id')
                ->leftJoin('productions as pr', 'pr.id', '=', 'p.production_id')
                ->select('p.*', 'a.name as application_name', 'u.email as user_email', 'u.first_name', 'u.last_name', 'pr.name as production_name')
                ->where('p.id', $payment)
                ->first()
            : null;

        if ($row) {
            $metadata = is_string($row->metadata) ? json_decode($row->metadata, true) : $row->metadata;
            $cutinappPaymentId = (int) data_get($metadata, 'cutinapp_payment_id', 0);
            $normalized = $this->recognition->normalize($this->objectToArray($row));
            $ledger = Schema::hasTable('financial_ledger_entries')
                ? FinancialLedgerEntry::query()->where('payment_id', $payment)->orderBy('occurred_at')->get()
                : collect();
            $reconciliations = Schema::hasTable('payment_reconciliations')
                ? PaymentReconciliation::query()->where('payment_id', $payment)->orderByDesc('checked_at')->limit(50)->get()
                : collect();

            return response()->json([
                'transaction' => (object) $normalized,
                'source' => $cutinappPaymentId ? $this->cutinappPaymentDetail($cutinappPaymentId) : null,
                'ledger' => $ledger,
                'reconciliations' => $reconciliations,
            ]);
        }

        $fallback = $this->cutinappPaymentDetail($payment);
        abort_unless($fallback, 404, 'Transação não encontrada.');

        return response()->json([
            'transaction' => (object) $this->normalizeCutinappPayment((object) $fallback['payment']),
            'source' => $fallback,
            'ledger' => [],
            'reconciliations' => [],
        ]);
    }

    public function ledger(Request $request)
    {
        $this->authorizeAccess($request);
        if (! Schema::hasTable('financial_ledger_entries')) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $query = FinancialLedgerEntry::query()->orderByDesc('occurred_at');
        if ($request->filled('app_slug')) $query->where('app_slug', (string) $request->input('app_slug'));
        if ($request->filled('event_type')) $query->where('event_type', (string) $request->input('event_type'));
        if ($request->filled('from')) $query->where('occurred_at', '>=', CarbonImmutable::parse((string) $request->input('from'))->startOfDay());
        if ($request->filled('to')) $query->where('occurred_at', '<=', CarbonImmutable::parse((string) $request->input('to'))->endOfDay());

        return response()->json($query->paginate(min(max((int) $request->input('per_page', 100), 10), 300)));
    }

    public function reconciliations(Request $request)
    {
        $this->authorizeAccess($request);
        if (! Schema::hasTable('payment_reconciliations')) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $query = PaymentReconciliation::query()->with('payment')->orderByDesc('checked_at');
        if ($request->boolean('mismatches_only')) $query->where('matched', false);
        if ($request->filled('provider')) $query->where('provider', (string) $request->input('provider'));

        return response()->json($query->paginate(min(max((int) $request->input('per_page', 100), 10), 300)));
    }

    public function closing(Request $request)
    {
        $this->authorizeAccess($request);
        [$from, $to] = $this->period($request);

        return response()->json($this->ledger->dailyClose($from, $to));
    }

    public function reconcileNow(Request $request)
    {
        $this->authorizeAccess($request);
        $stats = $this->providerReconciliation->reconcileBatch(min(max((int) $request->input('limit', 100), 1), 500));

        return response()->json(['message' => 'Conciliação executada.', 'stats' => $stats]);
    }

    public function export(Request $request, string $format)
    {
        $this->authorizeAccess($request);
        abort_unless(in_array($format, ['csv', 'pdf'], true), 404);

        $rows = $this->normalizedPayments($request);
        [$from, $to] = $this->period($request);
        $closing = $this->ledger->dailyClose($from, $to);
        $ledger = $this->ledger->snapshot($from, $to);

        if ($format === 'csv') {
            $stream = fopen('php://temp', 'w+');
            fputcsv($stream, ['Data financeira', 'Aplicação', 'Provedor', 'Método', 'Status', 'Valor bruto', 'Taxa Peter', 'Taxa gateway', 'Líquido vendedor', 'ID provedor'], ';');
            foreach ($rows as $row) {
                fputcsv($stream, [
                    $row['financial_at'] ?? '', $row['app_slug'] ?? '', $row['provider'] ?? '', $row['method'] ?? '', $row['status'] ?? '',
                    number_format((float) ($row['gross_amount'] ?? 0), 2, ',', ''),
                    number_format((float) ($row['platform_fee'] ?? 0), 2, ',', ''),
                    number_format((float) ($row['provider_fee'] ?? 0), 2, ',', ''),
                    number_format((float) ($row['seller_net'] ?? 0), 2, ',', ''),
                    $row['provider_payment_id'] ?? '',
                ], ';');
            }
            rewind($stream);
            $content = stream_get_contents($stream);
            fclose($stream);

            return response("\xEF\xBB\xBF" . $content, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="financeiro-' . now()->format('Ymd-His') . '.csv"',
            ]);
        }

        $html = view('reports.financial', [
            'rows' => $rows,
            'ledger' => $ledger,
            'closing' => $closing,
            'from' => $from,
            'to' => $to,
            'generatedAt' => now(),
        ])->render();

        return Pdf::loadHTML($html)->setPaper('a4', 'landscape')->download('financeiro-' . now()->format('Ymd-His') . '.pdf');
    }

    public function orders(Request $request)
    {
        $this->authorizeAccess($request);
        if (!Schema::hasTable('cutinapp_orders')) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $q = DB::table('cutinapp_orders as o')
            ->leftJoin('events as e', 'e.id', '=', 'o.event_id')
            ->leftJoin('productions as p', 'p.id', '=', 'o.production_id')
            ->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->selectRaw("o.*, e.title as event_title, p.name as production_name, u.email as buyer_email, CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) as buyer_name,
                (SELECT COALESCE(SUM(quantity),0) FROM cutinapp_order_items oi WHERE oi.order_id=o.id AND oi.type='ticket') as ticket_units,
                (SELECT COALESCE(SUM(quantity),0) FROM cutinapp_order_items oi WHERE oi.order_id=o.id AND oi.type='item') as item_units");

        if ($request->filled('status')) $q->where('o.status', $request->string('status'));
        if ($request->filled('production_id')) $q->where('o.production_id', (int) $request->input('production_id'));
        if ($request->filled('event_id')) $q->where('o.event_id', (int) $request->input('event_id'));
        if ($request->filled('from')) $q->whereDate('o.created_at', '>=', $request->date('from'));
        if ($request->filled('to')) $q->whereDate('o.created_at', '<=', $request->date('to'));

        if ($search = trim((string) $request->input('search', ''))) {
            $q->where(function ($x) use ($search) {
                $x->where('o.public_id', 'like', "%{$search}%")
                    ->orWhere('e.title', 'like', "%{$search}%")
                    ->orWhere('p.name', 'like', "%{$search}%")
                    ->orWhere('u.email', 'like', "%{$search}%");
            });
        }

        return response()->json($q->orderByDesc('o.created_at')->paginate(min(max((int) $request->input('per_page', 50), 10), 200)));
    }

    public function payouts(Request $request)
    {
        $this->authorizeAccess($request);
        if (!Schema::hasTable('cutinapp_payout_requests')) {
            return response()->json(['data' => [], 'summary' => ['total' => 0, 'pending' => 0, 'paid' => 0, 'amount_pending' => 0, 'amount_paid' => 0]]);
        }

        $q = DB::table('cutinapp_payout_requests as r')
            ->leftJoin('productions as p', 'p.id', '=', 'r.production_id')
            ->select('r.*', 'p.name as production_name')
            ->orderByDesc('r.created_at');

        if ($request->filled('status')) $q->where('r.status', $request->string('status'));

        $all = (clone $q)->get();
        $amountField = Schema::hasColumn('cutinapp_payout_requests', 'amount')
            ? 'amount'
            : (Schema::hasColumn('cutinapp_payout_requests', 'net_amount') ? 'net_amount' : null);

        $summary = [
            'total' => $all->count(),
            'pending' => $all->whereIn('status', ['pending', 'requested', 'processing'])->count(),
            'paid' => $all->whereIn('status', ['paid', 'completed'])->count(),
            'failed' => $all->whereIn('status', ['failed', 'rejected', 'cancelled'])->count(),
            'amount_pending' => $amountField ? round((float) $all->whereIn('status', ['pending', 'requested', 'processing'])->sum($amountField), 2) : 0,
            'amount_paid' => $amountField ? round((float) $all->whereIn('status', ['paid', 'completed'])->sum($amountField), 2) : 0,
        ];

        return response()->json(['data' => $all->take(300)->values(), 'summary' => $summary]);
    }

    public function health(Request $request)
    {
        $this->authorizeAccess($request);

        return response()->json($this->healthSnapshot($this->providerReconciliation->snapshot(), $this->ledger->settlementSnapshot()));
    }

    private function normalizedPayments(Request $request): Collection
    {
        $rows = collect();

        if (Schema::hasTable('ecosystem_payments')) {
            $ecosystem = DB::table('ecosystem_payments as p')
                ->leftJoin('applications as a', 'a.id', '=', 'p.app_id')
                ->select('p.*', 'a.name as application_name')
                ->orderByDesc('p.created_at')
                ->limit(5000)
                ->get()
                ->map(fn ($row) => $this->objectToArray($row));

            $rows = $rows->concat($ecosystem);
        }

        if (Schema::hasTable('cutinapp_payments') && Schema::hasTable('cutinapp_orders')) {
            $knownCutinapp = $rows->filter(fn ($row) => ($row['app_slug'] ?? null) === 'cutinapp')
                ->map(function ($row) {
                    $metadata = is_string($row['metadata'] ?? null)
                        ? json_decode($row['metadata'], true)
                        : ($row['metadata'] ?? []);

                    return (string) (data_get($metadata, 'cutinapp_payment_id')
                        ?: (($row['source_type'] ?? null) === 'cutinapp_payment' ? ($row['source_reference'] ?? '') : ''));
                })
                ->filter()
                ->flip();

            $native = DB::table('cutinapp_payments as cp')
                ->join('cutinapp_orders as o', 'o.id', '=', 'cp.order_id')
                ->leftJoin('applications as a', 'a.slug', '=', DB::raw("'cutinapp'"))
                ->select('cp.*', 'o.public_id as order_public_id', 'o.user_id', 'o.production_id', 'o.currency', 'o.platform_fee', 'o.producer_net', 'o.event_id', 'o.status as order_status', 'o.metadata as order_metadata', 'a.id as app_id', 'a.name as application_name')
                ->orderByDesc('cp.created_at')
                ->limit(5000)
                ->get()
                ->reject(fn ($row) => $knownCutinapp->has((string) $row->id))
                ->map(fn ($row) => $this->normalizeCutinappPayment($row));

            $rows = $rows->concat($native);
        }

        $rows = $rows->map(fn ($row) => $this->recognition->normalize($row));

        return $rows->filter(function ($row) use ($request) {
            if ($request->filled('app_slug') && ($row['app_slug'] ?? null) !== (string) $request->input('app_slug')) return false;
            if ($request->filled('provider') && ($row['provider'] ?? null) !== (string) $request->input('provider')) return false;
            if ($request->filled('status') && ($row['status'] ?? null) !== (string) $request->input('status')) return false;
            if ($request->filled('method') && ($row['method'] ?? null) !== (string) $request->input('method')) return false;

            $financialAt = (string) ($row['financial_at'] ?? $row['created_at'] ?? '1970-01-01');
            if ($request->filled('from') && strtotime($financialAt) < strtotime((string) $request->input('from') . ' 00:00:00')) return false;
            if ($request->filled('to') && strtotime($financialAt) > strtotime((string) $request->input('to') . ' 23:59:59')) return false;

            return true;
        })->values();
    }

    private function normalizeCutinappPayment(object $row): array
    {
        return $this->recognition->normalize([
            'id' => 'cutinapp-' . $row->id,
            'public_id' => $row->order_public_id ?? null,
            'app_id' => $row->app_id ?? null,
            'app_slug' => 'cutinapp',
            'application_name' => $row->application_name ?? 'Cutinapp',
            'provider' => $row->provider ?? 'mercadopago',
            'provider_payment_id' => $row->provider_payment_id ?? null,
            'source_type' => 'cutinapp_payment',
            'source_reference' => (string) $row->id,
            'source_id' => (int) $row->id,
            'user_id' => $row->user_id ?? null,
            'production_id' => $row->production_id ?? null,
            'establishment_id' => null,
            'currency' => $row->currency ?? 'BRL',
            'method' => $row->method ?? null,
            'status' => (string) ($row->status ?? 'pending'),
            'gross_amount' => (float) ($row->amount ?? 0),
            'platform_fee' => (float) ($row->platform_fee ?? 0),
            'provider_fee' => (float) ($row->provider_fee ?? 0),
            'seller_net' => (float) ($row->producer_net ?? 0),
            'metadata' => json_encode([
                'cutinapp_payment_id' => (int) $row->id,
                'order_public_id' => $row->order_public_id ?? null,
                'event_id' => $row->event_id ?? null,
                'order_status' => $row->order_status ?? null,
                'native_source' => true,
            ], JSON_UNESCAPED_UNICODE),
            'paid_at' => $row->paid_at ?? null,
            'refunded_at' => $row->refunded_at ?? null,
            'failed_at' => $row->failed_at ?? null,
            'created_at' => $row->created_at ?? null,
            'updated_at' => $row->updated_at ?? null,
        ]);
    }

    private function cutinappCommerceSnapshot(Request $request): array
    {
        if (!Schema::hasTable('cutinapp_orders')) return [];

        $q = DB::table('cutinapp_orders');
        if ($request->filled('from')) $q->whereDate('created_at', '>=', $request->date('from'));
        if ($request->filled('to')) $q->whereDate('created_at', '<=', $request->date('to'));

        $orders = (clone $q)->count();
        $paidOrders = (clone $q)->where('status', 'paid')->count();
        $paidGross = (float) (clone $q)->where('status', 'paid')->sum('total');
        $platformRevenue = (float) (clone $q)->where('status', 'paid')->sum('platform_fee');
        $producerNet = (float) (clone $q)->where('status', 'paid')->sum('producer_net');
        $cancelled = (clone $q)->whereIn('status', ['cancelled', 'rejected'])->count();
        $refunded = (clone $q)->whereIn('status', ['refunded', 'charged_back'])->count();
        $ticketUnits = Schema::hasTable('cutinapp_order_items')
            ? (int) DB::table('cutinapp_order_items as oi')->join('cutinapp_orders as o', 'o.id', '=', 'oi.order_id')->where('o.status', 'paid')->where('oi.type', 'ticket')->sum('oi.quantity')
            : 0;
        $itemUnits = Schema::hasTable('cutinapp_order_items')
            ? (int) DB::table('cutinapp_order_items as oi')->join('cutinapp_orders as o', 'o.id', '=', 'oi.order_id')->where('o.status', 'paid')->where('oi.type', 'item')->sum('oi.quantity')
            : 0;
        $issuedPasses = Schema::hasTable('event_passes')
            ? DB::table('event_passes')->whereNotNull('cutinapp_order_item_id')->whereNotIn('status', ['cancelled', 'refunded', 'charged_back'])->count()
            : 0;

        $fulfillmentFailed = (clone $q)->where('status', 'paid')->where('metadata', 'like', '%"fulfillment_status":"failed"%')->count();
        $fulfillmentProcessing = (clone $q)->where('status', 'paid')->where('metadata', 'like', '%"fulfillment_status":"processing"%')->count();

        return [
            'orders' => $orders,
            'paid_orders' => $paidOrders,
            'conversion_rate' => $orders ? round(($paidOrders / $orders) * 100, 2) : 0,
            'paid_gross' => round($paidGross, 2),
            'platform_revenue' => round($platformRevenue, 2),
            'producer_net' => round($producerNet, 2),
            'cancelled_orders' => $cancelled,
            'refunded_orders' => $refunded,
            'ticket_units' => $ticketUnits,
            'item_units' => $itemUnits,
            'issued_passes' => $issuedPasses,
            'fulfillment_failed' => $fulfillmentFailed,
            'fulfillment_processing' => $fulfillmentProcessing,
        ];
    }

    private function healthSnapshot(array $providerReconciliation = [], array $settlement = []): array
    {
        if (Schema::hasTable('ecosystem_payments')) {
            $stalePayments = DB::table('ecosystem_payments')
                ->whereIn('status', PaymentRevenueRecognitionService::OPEN_STATUSES)
                ->where('created_at', '<', now()->subMinutes(30))
                ->count();
        } else {
            $stalePayments = Schema::hasTable('cutinapp_payments')
                ? DB::table('cutinapp_payments')->whereIn('status', ['pending', 'in_process', 'authorized'])->where('created_at', '<', now()->subMinutes(30))->count()
                : 0;
        }

        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $payoutFailures = Schema::hasTable('cutinapp_payout_requests') ? DB::table('cutinapp_payout_requests')->whereIn('status', ['failed', 'rejected', 'cancelled'])->count() : 0;
        $producerAccounts = Schema::hasTable('cutinapp_producer_payment_accounts') ? DB::table('cutinapp_producer_payment_accounts')->count() : 0;
        $connectedAccounts = Schema::hasTable('cutinapp_producer_payment_accounts') ? DB::table('cutinapp_producer_payment_accounts')->where('status', 'connected')->count() : 0;
        $failedFulfillment = Schema::hasTable('cutinapp_orders') ? DB::table('cutinapp_orders')->where('status', 'paid')->where('metadata', 'like', '%"fulfillment_status":"failed"%')->count() : 0;
        $recentErrors = Schema::hasTable('interactions') && Schema::hasColumn('interactions', 'outcome') ? DB::table('interactions')->where('outcome', 'error')->where('created_at', '>=', now()->subHour())->count() : 0;
        $mismatches = (int) ($providerReconciliation['mismatches_24h'] ?? 0);
        $reconciliationErrors = (int) ($providerReconciliation['errors_24h'] ?? 0);

        $score = 100;
        $score -= min($stalePayments * 8, 32);
        $score -= min($failedJobs * 5, 20);
        $score -= min($payoutFailures * 8, 24);
        $score -= min($failedFulfillment * 12, 36);
        $score -= min($recentErrors * 2, 20);
        $score -= min($mismatches * 5, 25);
        $score -= min($reconciliationErrors * 5, 20);
        $score = max($score, 0);

        return [
            'score' => $score,
            'status' => $score >= 90 ? 'healthy' : ($score >= 70 ? 'attention' : 'critical'),
            'stale_payments' => $stalePayments,
            'failed_jobs' => $failedJobs,
            'payout_failures' => $payoutFailures,
            'failed_fulfillment' => $failedFulfillment,
            'producer_accounts' => $producerAccounts,
            'connected_producer_accounts' => $connectedAccounts,
            'recent_api_errors' => $recentErrors,
            'reconciliation_mismatches_24h' => $mismatches,
            'reconciliation_errors_24h' => $reconciliationErrors,
            'settlement_pending_count' => (int) ($settlement['settlement_pending_count'] ?? 0),
            'settlement_pending_gross' => (float) ($settlement['settlement_pending_gross'] ?? 0),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    private function alerts(Collection $rows, array $commerce, array $health, array $providerReconciliation = [], array $settlement = []): Collection
    {
        $alerts = collect();
        $failed = $this->recognition->failed($rows);
        $pending = $this->recognition->open($rows);
        $refunded = $this->recognition->reversed($rows);
        $total = max($rows->count(), 1);

        if ($failed->isNotEmpty()) {
            $alerts->push([
                'severity' => $failed->count() / $total >= .10 ? 'critical' : 'warning',
                'title' => 'Falhas de pagamento',
                'message' => "{$failed->count()} transações falharam ou expiraram no período selecionado.",
            ]);
        }

        if ($pending->isNotEmpty()) {
            $pendingAmount = $this->recognition->bucket($pending)->amount;
            $alerts->push([
                'severity' => 'warning',
                'title' => 'Pagamentos em aberto — não são caixa',
                'message' => $pending->count() . ' cobranças, somando R$ ' . number_format($pendingAmount, 2, ',', '.') . ', aguardam confirmação e não entram na receita realizada.',
            ]);
        }

        if ($refunded->isNotEmpty()) {
            $alerts->push([
                'severity' => 'warning',
                'title' => 'Estornos e chargebacks',
                'message' => "{$refunded->count()} transações geraram eventos compensatórios no ledger.",
            ]);
        }

        if (($providerReconciliation['mismatches_24h'] ?? 0) > 0) $alerts->push(['severity' => 'critical', 'title' => 'Divergência com gateway', 'message' => $providerReconciliation['mismatches_24h'] . ' divergências foram detectadas nas últimas 24 horas.']);
        if (($providerReconciliation['errors_24h'] ?? 0) > 0) $alerts->push(['severity' => 'warning', 'title' => 'Conciliação indisponível', 'message' => $providerReconciliation['errors_24h'] . ' verificações não conseguiram consultar o provedor.']);
        if (($settlement['settlement_pending_count'] ?? 0) > 0) $alerts->push(['severity' => 'warning', 'title' => 'Saldo aprovado aguardando liberação', 'message' => $settlement['settlement_pending_count'] . ' pagamentos já aprovados ainda não estão classificados como saldo disponível.']);
        if (($health['stale_payments'] ?? 0) > 0) $alerts->push(['severity' => 'critical', 'title' => 'Pagamentos travados', 'message' => "{$health['stale_payments']} pagamentos estão pendentes há mais de 30 minutos."]);
        if (($health['failed_fulfillment'] ?? 0) > 0) $alerts->push(['severity' => 'critical', 'title' => 'Ingressos não emitidos', 'message' => "{$health['failed_fulfillment']} pedidos pagos têm falha de emissão e exigem reprocessamento."]);
        if (($health['failed_jobs'] ?? 0) > 0) $alerts->push(['severity' => 'warning', 'title' => 'Jobs com falha', 'message' => "{$health['failed_jobs']} jobs estão na fila de falhas da API."]);
        if (($health['payout_failures'] ?? 0) > 0) $alerts->push(['severity' => 'critical', 'title' => 'Falhas de repasse', 'message' => "{$health['payout_failures']} solicitações de repasse falharam ou foram rejeitadas."]);
        if (($commerce['paid_orders'] ?? 0) > 0 && ($commerce['issued_passes'] ?? 0) < ($commerce['ticket_units'] ?? 0)) $alerts->push(['severity' => 'critical', 'title' => 'Divergência de fulfillment', 'message' => 'Há mais ingressos vendidos do que passes emitidos. Verifique os pedidos pagos imediatamente.']);

        return $alerts;
    }

    private function cutinappPaymentDetail(int $paymentId): ?array
    {
        if (!Schema::hasTable('cutinapp_payments')) return null;

        $payment = DB::table('cutinapp_payments as cp')
            ->join('cutinapp_orders as o', 'o.id', '=', 'cp.order_id')
            ->leftJoin('events as e', 'e.id', '=', 'o.event_id')
            ->leftJoin('productions as pr', 'pr.id', '=', 'o.production_id')
            ->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->select('cp.*', 'o.public_id as order_public_id', 'o.user_id', 'o.production_id', 'o.currency', 'o.platform_fee', 'o.producer_net', 'o.event_id', 'o.status as order_status', 'o.metadata as order_metadata', 'e.title as event_title', 'pr.name as production_name', 'u.email as buyer_email', 'u.first_name', 'u.last_name')
            ->where('cp.id', $paymentId)
            ->first();

        if (!$payment) return null;

        $items = Schema::hasTable('cutinapp_order_items')
            ? DB::table('cutinapp_order_items')->where('order_id', $payment->order_id)->get()
            : collect();

        return ['payment' => $this->objectToArray($payment), 'items' => $items];
    }

    private function paymentMetrics(Collection $group): array
    {
        $realized = $this->recognition->realized($group);
        $failed = $this->recognition->failed($group);
        $open = $this->recognition->open($group);
        $realizedBucket = $this->recognition->bucket($realized);

        return [
            'transactions' => $group->count(),
            'gross' => $realizedBucket->amount,
            'attempted_gross' => $this->recognition->bucket($group)->amount,
            'pending_gross' => $this->recognition->bucket($open)->amount,
            'platform_fees' => $realizedBucket->platform_fees,
            'provider_fees' => $realizedBucket->provider_fees,
            'seller_net' => $realizedBucket->seller_net,
            'approved' => $realized->count(),
            'pending' => $open->count(),
            'failed' => $failed->count(),
        ];
    }

    private function bucket(Collection $rows): object
    {
        return $this->recognition->bucket($rows);
    }

    private function period(Request $request): array
    {
        $from = $request->filled('from')
            ? CarbonImmutable::parse((string) $request->input('from'))->startOfDay()
            : CarbonImmutable::now()->subDays(29)->startOfDay();
        $to = $request->filled('to')
            ? CarbonImmutable::parse((string) $request->input('to'))->endOfDay()
            : CarbonImmutable::now()->endOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }

        if ($from->diffInDays($to) > 366) {
            $from = $to->subDays(366)->startOfDay();
        }

        return [$from, $to];
    }

    private function objectToArray(object $row): array
    {
        return json_decode(json_encode($row), true);
    }

    private function authorizeAccess(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && (
            $user->hasProfile('Administrador') ||
            $user->hasPermission('ecosystem_manage') ||
            $user->hasPermission('application_manage') ||
            $user->hasPermission('user_management') ||
            $user->hasPermission('permission_management')
        ), 403, 'Usuário sem permissão para administrar o financeiro do ecossistema Peter Tecnet.');
    }
}
