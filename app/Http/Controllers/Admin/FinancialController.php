<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinancialController extends Controller
{
    private const OPEN_PAYMENT_STATUSES = ['pending', 'in_process', 'authorized'];
    private const CONFIRMED_PAYMENT_STATUSES = ['approved', 'paid'];
    private const FAILED_PAYMENT_STATUSES = ['failed', 'rejected', 'cancelled', 'expired'];
    private const REVERSED_PAYMENT_STATUSES = ['refunded', 'charged_back'];

    private function payments(Request $request)
    {
        $q = DB::table('ecosystem_payments as p');

        if (Schema::hasTable('applications') && Schema::hasColumn('ecosystem_payments', 'app_id')) {
            $q->leftJoin('applications as a', 'a.id', '=', 'p.app_id');
        }

        if ($request->filled('app_slug') && Schema::hasColumn('ecosystem_payments', 'app_slug')) {
            $q->where('p.app_slug', (string) $request->input('app_slug'));
        }
        if ($request->filled('provider') && Schema::hasColumn('ecosystem_payments', 'provider')) {
            $q->where('p.provider', (string) $request->input('provider'));
        }
        if ($request->filled('status') && Schema::hasColumn('ecosystem_payments', 'status')) {
            $q->where('p.status', (string) $request->input('status'));
        }
        if ($request->filled('method') && Schema::hasColumn('ecosystem_payments', 'method')) {
            $q->where('p.method', (string) $request->input('method'));
        }
        if ($request->filled('from') && Schema::hasColumn('ecosystem_payments', 'created_at')) {
            $q->whereDate('p.created_at', '>=', $request->date('from'));
        }
        if ($request->filled('to') && Schema::hasColumn('ecosystem_payments', 'created_at')) {
            $q->whereDate('p.created_at', '<=', $request->date('to'));
        }

        return $q;
    }

    public function dashboard(Request $request)
    {
        if (! Schema::hasTable('ecosystem_payments')) {
            return response()->json($this->emptyDashboard('A tabela financeira ainda não está disponível neste ambiente.'));
        }

        $base = $this->payments($request);
        $totals = (clone $base)->selectRaw('COUNT(*) transactions, COALESCE(SUM(p.gross_amount),0) gross, COALESCE(SUM(p.platform_fee),0) platform_fees, COALESCE(SUM(p.provider_fee),0) provider_fees, COALESCE(SUM(p.seller_net),0) seller_net')->first();
        $approved = (clone $base)->whereIn('p.status', self::CONFIRMED_PAYMENT_STATUSES)->selectRaw('COUNT(*) count, COALESCE(SUM(p.gross_amount),0) amount')->first();
        $failed = (clone $base)->whereIn('p.status', self::FAILED_PAYMENT_STATUSES)->selectRaw('COUNT(*) count, COALESCE(SUM(p.gross_amount),0) amount')->first();
        $pending = (clone $base)->whereIn('p.status', self::OPEN_PAYMENT_STATUSES)->selectRaw('COUNT(*) count, COALESCE(SUM(p.gross_amount),0) amount')->first();
        $refunded = (clone $base)->whereIn('p.status', self::REVERSED_PAYMENT_STATUSES)->selectRaw('COUNT(*) count, COALESCE(SUM(p.gross_amount),0) amount')->first();

        $byApp = collect();
        if (Schema::hasColumn('ecosystem_payments', 'app_slug')) {
            $byAppQuery = clone $base;
            if (Schema::hasTable('applications') && Schema::hasColumn('ecosystem_payments', 'app_id')) {
                $byApp = $byAppQuery
                    ->groupBy('p.app_slug', 'a.name')
                    ->selectRaw('p.app_slug, a.name application_name, COUNT(*) transactions, COALESCE(SUM(p.gross_amount),0) gross, COALESCE(SUM(p.platform_fee),0) platform_fees, COALESCE(SUM(p.seller_net),0) seller_net')
                    ->orderByDesc('gross')
                    ->get();
            } else {
                $byApp = $byAppQuery
                    ->groupBy('p.app_slug')
                    ->selectRaw('p.app_slug, p.app_slug application_name, COUNT(*) transactions, COALESCE(SUM(p.gross_amount),0) gross, COALESCE(SUM(p.platform_fee),0) platform_fees, COALESCE(SUM(p.seller_net),0) seller_net')
                    ->orderByDesc('gross')
                    ->get();
            }
        }

        $byProvider = Schema::hasColumn('ecosystem_payments', 'provider')
            ? (clone $base)->groupBy('p.provider')->selectRaw("p.provider, COUNT(*) transactions, COALESCE(SUM(p.gross_amount),0) gross, SUM(CASE WHEN p.status IN ('approved','paid') THEN 1 ELSE 0 END) approved, SUM(CASE WHEN p.status IN ('failed','rejected','cancelled','expired') THEN 1 ELSE 0 END) failed")->get()
            : collect();

        $timeline = Schema::hasColumn('ecosystem_payments', 'created_at')
            ? (clone $base)->where('p.created_at', '>=', now()->subDays(30))->groupByRaw('DATE(p.created_at)')->selectRaw("DATE(p.created_at) day, COUNT(*) transactions, COALESCE(SUM(p.gross_amount),0) gross, COALESCE(SUM(p.platform_fee),0) platform_fees, COALESCE(SUM(CASE WHEN p.status IN ('approved','paid') THEN p.gross_amount ELSE 0 END),0) approved_amount")->orderBy('day')->get()
            : collect();

        $alerts = collect();
        if (($failed->count ?? 0) > 0) {
            $alerts->push(['severity' => 'critical', 'title' => 'Falhas de pagamento', 'message' => "{$failed->count} transações falharam no período selecionado."]);
        }
        if (($pending->count ?? 0) > 0) {
            $alerts->push(['severity' => 'warning', 'title' => 'Pagamentos pendentes', 'message' => "{$pending->count} transações aguardam conclusão."]);
        }
        if (($refunded->count ?? 0) > 0) {
            $alerts->push(['severity' => 'warning', 'title' => 'Estornos/chargebacks', 'message' => "{$refunded->count} transações exigem acompanhamento financeiro."]);
        }

        $profitability = Cache::get('profitability:latest', [
            'status' => 'unavailable',
            'lookback_hours' => null,
            'total_contribution_shortfall' => 0.0,
            'affected_gmv' => 0.0,
            'action_count' => 0,
            'actions' => [],
            'generated_at' => null,
        ]);

        if (($profitability['status'] ?? null) === 'attention' && (float) ($profitability['total_contribution_shortfall'] ?? 0) > 0) {
            $alerts->prepend([
                'severity' => 'critical',
                'title' => 'Margem negativa detectada',
                'message' => sprintf(
                    'R$ %.2f de contribuição em risco em %d configuração(ões) de cobrança.',
                    (float) ($profitability['total_contribution_shortfall'] ?? 0),
                    (int) ($profitability['action_count'] ?? 0)
                ),
            ]);
        }

        return response()->json([
            'summary' => compact('totals', 'approved', 'failed', 'pending', 'refunded'),
            'applications' => $byApp,
            'providers' => $byProvider,
            'timeline' => $timeline,
            'health' => $this->healthSnapshot(),
            'alerts' => $alerts,
            'profitability' => $profitability,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function transactions(Request $request)
    {
        if (! Schema::hasTable('ecosystem_payments')) {
            return response()->json($this->emptyPaginator($request));
        }

        $query = $this->payments($request)->select('p.*');
        if (Schema::hasTable('applications') && Schema::hasColumn('ecosystem_payments', 'app_id')) {
            $query->addSelect('a.name as application_name');
        }

        $orderColumn = Schema::hasColumn('ecosystem_payments', 'created_at') ? 'p.created_at' : 'p.id';
        $rows = $query
            ->orderByDesc($orderColumn)
            ->paginate($this->perPage($request, 50, 200));

        return response()->json($rows);
    }

    public function transaction(int $payment)
    {
        abort_unless(Schema::hasTable('ecosystem_payments'), 404, 'Transação não encontrada.');

        $query = DB::table('ecosystem_payments as p')->select('p.*');
        if (Schema::hasTable('applications') && Schema::hasColumn('ecosystem_payments', 'app_id')) {
            $query->leftJoin('applications as a', 'a.id', '=', 'p.app_id')->addSelect('a.name as application_name');
        }
        if (Schema::hasTable('users') && Schema::hasColumn('ecosystem_payments', 'user_id')) {
            $query->leftJoin('users as u', 'u.id', '=', 'p.user_id')->addSelect('u.email as user_email', 'u.first_name', 'u.last_name');
        }

        $row = $query->where('p.id', $payment)->first();
        abort_unless($row, 404, 'Transação não encontrada.');

        return response()->json([
            'transaction' => $row,
            'ledger' => $this->paymentLedger($payment),
            'reconciliations' => $this->paymentReconciliations($payment),
        ]);
    }

    public function orders(Request $request)
    {
        if (! Schema::hasTable('orders')) {
            return response()->json($this->emptyPaginator($request));
        }

        $query = DB::table('orders as o')->select('o.*');
        $canJoinApplications = Schema::hasTable('applications') && Schema::hasColumn('orders', 'app_id');
        if ($canJoinApplications) {
            $query->leftJoin('applications as a', 'a.id', '=', 'o.app_id')->addSelect('a.name as application_name', 'a.slug as app_slug');
        }

        if ($request->filled('status')) {
            $status = (string) $request->input('status');
            $hasStatus = Schema::hasColumn('orders', 'status');
            $hasPaymentStatus = Schema::hasColumn('orders', 'payment_status');
            if ($hasStatus && $hasPaymentStatus) {
                $query->where(fn ($q) => $q->where('o.status', $status)->orWhere('o.payment_status', $status));
            } elseif ($hasStatus) {
                $query->where('o.status', $status);
            } elseif ($hasPaymentStatus) {
                $query->where('o.payment_status', $status);
            }
        }

        if ($request->filled('app_slug') && $canJoinApplications && Schema::hasColumn('applications', 'slug')) {
            $query->where('a.slug', (string) $request->input('app_slug'));
        }
        if ($request->filled('from') && Schema::hasColumn('orders', 'created_at')) {
            $query->whereDate('o.created_at', '>=', $request->date('from'));
        }
        if ($request->filled('to') && Schema::hasColumn('orders', 'created_at')) {
            $query->whereDate('o.created_at', '<=', $request->date('to'));
        }

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $hasAny = false;
                foreach (['order_number', 'customer_name', 'customer_email', 'customer_phone', 'access_code'] as $column) {
                    if (! Schema::hasColumn('orders', $column)) {
                        continue;
                    }
                    if ($hasAny) {
                        $q->orWhere("o.{$column}", 'like', "%{$search}%");
                    } else {
                        $q->where("o.{$column}", 'like', "%{$search}%");
                        $hasAny = true;
                    }
                }
            });
        }

        $orderColumn = Schema::hasColumn('orders', 'created_at') ? 'o.created_at' : 'o.id';

        return response()->json(
            $query->orderByDesc($orderColumn)->paginate($this->perPage($request, 50, 200))
        );
    }

    public function payouts(Request $request)
    {
        $table = $this->payoutTable();
        if (! $table) {
            return response()->json([
                'data' => [],
                'summary' => ['total' => 0, 'pending' => 0, 'paid' => 0, 'failed' => 0, 'amount_pending' => 0, 'amount_paid' => 0],
            ]);
        }

        $query = DB::table($table);
        if ($request->filled('status') && Schema::hasColumn($table, 'status')) {
            $query->where('status', (string) $request->input('status'));
        }
        if ($request->filled('app_slug') && Schema::hasColumn($table, 'app_slug')) {
            $query->where('app_slug', (string) $request->input('app_slug'));
        }

        $orderColumn = Schema::hasColumn($table, 'created_at') ? 'created_at' : 'id';
        $all = (clone $query)->orderByDesc($orderColumn)->limit(500)->get();
        $amountField = Schema::hasColumn($table, 'amount') ? 'amount' : (Schema::hasColumn($table, 'net_amount') ? 'net_amount' : null);

        $pending = $all->whereIn('status', ['pending', 'requested', 'processing']);
        $paid = $all->whereIn('status', ['paid', 'completed']);
        $failed = $all->whereIn('status', ['failed', 'rejected', 'cancelled']);

        return response()->json([
            'data' => $all->values(),
            'summary' => [
                'total' => $all->count(),
                'pending' => $pending->count(),
                'paid' => $paid->count(),
                'failed' => $failed->count(),
                'amount_pending' => $amountField ? round((float) $pending->sum($amountField), 2) : 0,
                'amount_paid' => $amountField ? round((float) $paid->sum($amountField), 2) : 0,
            ],
        ]);
    }

    public function health(Request $request)
    {
        return response()->json($this->healthSnapshot());
    }

    public function ledger(Request $request)
    {
        if (! Schema::hasTable('financial_ledger_entries')) {
            return response()->json($this->emptyPaginator($request, 100));
        }

        $query = DB::table('financial_ledger_entries');
        if ($request->filled('app_slug') && Schema::hasColumn('financial_ledger_entries', 'app_slug')) {
            $query->where('app_slug', (string) $request->input('app_slug'));
        }
        if ($request->filled('event_type') && Schema::hasColumn('financial_ledger_entries', 'event_type')) {
            $query->where('event_type', (string) $request->input('event_type'));
        }
        if ($request->filled('from') && Schema::hasColumn('financial_ledger_entries', 'occurred_at')) {
            $query->whereDate('occurred_at', '>=', $request->date('from'));
        }
        if ($request->filled('to') && Schema::hasColumn('financial_ledger_entries', 'occurred_at')) {
            $query->whereDate('occurred_at', '<=', $request->date('to'));
        }

        $orderColumn = Schema::hasColumn('financial_ledger_entries', 'occurred_at') ? 'occurred_at' : 'id';

        return response()->json(
            $query->orderByDesc($orderColumn)->paginate($this->perPage($request, 100, 300))
        );
    }

    public function reconciliations(Request $request)
    {
        if (! Schema::hasTable('payment_reconciliations')) {
            return response()->json($this->emptyPaginator($request, 100));
        }

        $query = DB::table('payment_reconciliations');
        if ($request->boolean('mismatches_only') && Schema::hasColumn('payment_reconciliations', 'matched')) {
            $query->where('matched', false);
        }
        if ($request->filled('provider') && Schema::hasColumn('payment_reconciliations', 'provider')) {
            $query->where('provider', (string) $request->input('provider'));
        }

        $orderColumn = Schema::hasColumn('payment_reconciliations', 'checked_at') ? 'checked_at' : (Schema::hasColumn('payment_reconciliations', 'created_at') ? 'created_at' : 'id');

        return response()->json(
            $query->orderByDesc($orderColumn)->paginate($this->perPage($request, 100, 300))
        );
    }

    public function reconcileNow(Request $request)
    {
        return response()->json([
            'message' => 'A conciliação automática com o provedor ainda não está habilitada neste build da API.',
            'status' => 'not_configured',
            'stats' => ['checked' => 0, 'matched' => 0, 'mismatched' => 0, 'errors' => 0],
        ], 409);
    }

    public function closing(Request $request)
    {
        if (! Schema::hasTable('ecosystem_payments') || ! Schema::hasColumn('ecosystem_payments', 'created_at')) {
            return response()->json(['data' => [], 'generated_at' => now()->toIso8601String()]);
        }

        $query = $this->payments($request)
            ->whereIn('p.status', self::CONFIRMED_PAYMENT_STATUSES)
            ->groupByRaw('DATE(p.created_at)')
            ->selectRaw('DATE(p.created_at) day, COUNT(*) transactions, COALESCE(SUM(p.gross_amount),0) gross, COALESCE(SUM(p.platform_fee),0) platform_fees, COALESCE(SUM(p.provider_fee),0) provider_fees, COALESCE(SUM(p.seller_net),0) seller_net')
            ->orderBy('day');

        return response()->json(['data' => $query->get(), 'generated_at' => now()->toIso8601String()]);
    }

    public function export(Request $request, string $format)
    {
        abort_unless(in_array($format, ['csv', 'pdf'], true), 404);

        if (! Schema::hasTable('ecosystem_payments')) {
            $rows = collect();
        } else {
            $query = $this->payments($request)->select('p.*');
            if (Schema::hasTable('applications') && Schema::hasColumn('ecosystem_payments', 'app_id')) {
                $query->addSelect('a.name as application_name');
            }
            $orderColumn = Schema::hasColumn('ecosystem_payments', 'created_at') ? 'p.created_at' : 'p.id';
            $rows = $query->orderByDesc($orderColumn)->limit(5000)->get();
        }

        if ($format === 'csv') {
            $stream = fopen('php://temp', 'w+');
            fputcsv($stream, ['Data', 'Aplicação', 'Provedor', 'Método', 'Status', 'Valor bruto', 'Taxa Peter', 'Taxa gateway', 'Líquido vendedor', 'ID provedor'], ';');
            foreach ($rows as $row) {
                fputcsv($stream, [
                    $this->csvValue($row->created_at ?? ''),
                    $this->csvValue($row->application_name ?? $row->app_slug ?? ''),
                    $this->csvValue($row->provider ?? ''),
                    $this->csvValue($row->method ?? ''),
                    $this->csvValue($row->status ?? ''),
                    number_format((float) ($row->gross_amount ?? 0), 2, ',', ''),
                    number_format((float) ($row->platform_fee ?? 0), 2, ',', ''),
                    number_format((float) ($row->provider_fee ?? 0), 2, ',', ''),
                    number_format((float) ($row->seller_net ?? 0), 2, ',', ''),
                    $this->csvValue($row->provider_payment_id ?? ''),
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

        $tableRows = $rows->map(function ($row): string {
            return '<tr>'
                . '<td>' . e((string) ($row->created_at ?? '')) . '</td>'
                . '<td>' . e((string) ($row->application_name ?? $row->app_slug ?? '')) . '</td>'
                . '<td>' . e((string) ($row->provider ?? '')) . '</td>'
                . '<td>' . e((string) ($row->method ?? '')) . '</td>'
                . '<td>' . e((string) ($row->status ?? '')) . '</td>'
                . '<td>R$ ' . number_format((float) ($row->gross_amount ?? 0), 2, ',', '.') . '</td>'
                . '<td>R$ ' . number_format((float) ($row->platform_fee ?? 0), 2, ',', '.') . '</td>'
                . '</tr>';
        })->implode('');

        $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><style>'
            . 'body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#111827}h1{font-size:18px;margin-bottom:4px}p{color:#4b5563}table{width:100%;border-collapse:collapse;margin-top:16px}th,td{border:1px solid #d1d5db;padding:6px;text-align:left}th{background:#f3f4f6}'
            . '</style></head><body><h1>Centro financeiro do ecossistema</h1><p>Gerado em ' . e(now()->format('d/m/Y H:i:s')) . '</p>'
            . '<table><thead><tr><th>Data</th><th>Aplicação</th><th>Provedor</th><th>Método</th><th>Status</th><th>Valor bruto</th><th>Taxa Peter</th></tr></thead><tbody>'
            . $tableRows . '</tbody></table></body></html>';

        return Pdf::loadHTML($html)
            ->setPaper('a4', 'landscape')
            ->download('financeiro-' . now()->format('Ymd-His') . '.pdf');
    }

    private function healthSnapshot(): array
    {
        $stalePayments = 0;
        if (Schema::hasTable('ecosystem_payments') && Schema::hasColumn('ecosystem_payments', 'status') && Schema::hasColumn('ecosystem_payments', 'created_at')) {
            $stalePayments = DB::table('ecosystem_payments')
                ->whereIn('status', self::OPEN_PAYMENT_STATUSES)
                ->where('created_at', '<', now()->subMinutes(30))
                ->count();
        }

        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;

        $payoutFailures = 0;
        if ($payoutTable = $this->payoutTable()) {
            if (Schema::hasColumn($payoutTable, 'status')) {
                $payoutFailures = DB::table($payoutTable)->whereIn('status', ['failed', 'rejected', 'cancelled'])->count();
            }
        }

        $mismatches = 0;
        if (Schema::hasTable('payment_reconciliations') && Schema::hasColumn('payment_reconciliations', 'matched')) {
            $query = DB::table('payment_reconciliations')->where('matched', false);
            if (Schema::hasColumn('payment_reconciliations', 'checked_at')) {
                $query->where('checked_at', '>=', now()->subDay());
            } elseif (Schema::hasColumn('payment_reconciliations', 'created_at')) {
                $query->where('created_at', '>=', now()->subDay());
            }
            $mismatches = $query->count();
        }

        $failedFulfillment = 0;
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'fulfillment_status')) {
            $failedFulfillment = DB::table('orders')->where('fulfillment_status', 'failed')->count();
        }

        $recentErrors = 0;
        if (Schema::hasTable('interactions') && Schema::hasColumn('interactions', 'outcome')) {
            $query = DB::table('interactions')->where('outcome', 'error');
            if (Schema::hasColumn('interactions', 'created_at')) {
                $query->where('created_at', '>=', now()->subHour());
            }
            $recentErrors = $query->count();
        }

        $penalty = min(100, ($stalePayments * 3) + ($mismatches * 5) + ($failedFulfillment * 4) + ($failedJobs * 5) + ($payoutFailures * 5) + ($recentErrors * 2));
        $score = max(0, 100 - $penalty);
        $status = $score >= 90 ? 'healthy' : ($score >= 70 ? 'attention' : 'critical');

        return [
            'status' => $status,
            'score' => $score,
            'stale_payments' => $stalePayments,
            'reconciliation_mismatches_24h' => $mismatches,
            'failed_fulfillment' => $failedFulfillment,
            'failed_jobs' => $failedJobs,
            'payout_failures' => $payoutFailures,
            'recent_errors_1h' => $recentErrors,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function payoutTable(): ?string
    {
        foreach (['financial_payouts', 'payout_requests'] as $table) {
            if (Schema::hasTable($table)) {
                return $table;
            }
        }

        return null;
    }

    private function paymentLedger(int $payment): array
    {
        if (! Schema::hasTable('financial_ledger_entries') || ! Schema::hasColumn('financial_ledger_entries', 'payment_id')) {
            return [];
        }

        $query = DB::table('financial_ledger_entries')->where('payment_id', $payment);
        $orderColumn = Schema::hasColumn('financial_ledger_entries', 'occurred_at') ? 'occurred_at' : 'id';

        return $query->orderBy($orderColumn)->limit(100)->get()->all();
    }

    private function paymentReconciliations(int $payment): array
    {
        if (! Schema::hasTable('payment_reconciliations') || ! Schema::hasColumn('payment_reconciliations', 'payment_id')) {
            return [];
        }

        $query = DB::table('payment_reconciliations')->where('payment_id', $payment);
        $orderColumn = Schema::hasColumn('payment_reconciliations', 'checked_at') ? 'checked_at' : (Schema::hasColumn('payment_reconciliations', 'created_at') ? 'created_at' : 'id');

        return $query->orderByDesc($orderColumn)->limit(50)->get()->all();
    }

    private function perPage(Request $request, int $default = 50, int $max = 200): int
    {
        return min(max((int) $request->input('per_page', $default), 10), $max);
    }

    private function emptyPaginator(Request $request, int $default = 50): array
    {
        $perPage = $this->perPage($request, $default, 300);

        return [
            'current_page' => 1,
            'data' => [],
            'from' => null,
            'last_page' => 1,
            'per_page' => $perPage,
            'to' => null,
            'total' => 0,
        ];
    }

    private function emptyDashboard(string $message): array
    {
        $zeroBucket = (object) ['count' => 0, 'amount' => 0];
        $totals = (object) [
            'transactions' => 0,
            'gross' => 0,
            'platform_fees' => 0,
            'provider_fees' => 0,
            'seller_net' => 0,
        ];

        return [
            'summary' => [
                'totals' => $totals,
                'approved' => $zeroBucket,
                'failed' => $zeroBucket,
                'pending' => $zeroBucket,
                'refunded' => $zeroBucket,
            ],
            'applications' => [],
            'providers' => [],
            'timeline' => [],
            'health' => [
                'status' => 'critical',
                'score' => 0,
                'stale_payments' => 0,
                'reconciliation_mismatches_24h' => 0,
                'failed_fulfillment' => 0,
                'failed_jobs' => 0,
                'payout_failures' => 0,
            ],
            'alerts' => [[
                'severity' => 'critical',
                'title' => 'Módulo financeiro indisponível',
                'message' => $message,
            ]],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function csvValue(mixed $value): string
    {
        $value = (string) $value;
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'" . $value;
        }

        return $value;
    }
}
