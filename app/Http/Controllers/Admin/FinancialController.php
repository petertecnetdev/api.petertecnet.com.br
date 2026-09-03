<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentRevenueRecognitionService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinancialController extends Controller
{
    public function __construct(private PaymentRevenueRecognitionService $recognition)
    {
    }

    public function dashboard(Request $request)
    {
        $rows = $this->normalizedPayments($request);
        $realized = $this->recognition->realized($rows);
        $failed = $this->recognition->failed($rows);
        $pending = $this->recognition->open($rows);
        $refunded = $this->recognition->reversed($rows);
        $totals = $this->recognition->totals($rows);

        $applications = $rows->groupBy(fn (array $row) => $row['app_slug'] ?: 'unscoped')
            ->map(function (Collection $group, string $slug) {
                return array_merge([
                    'app_slug' => $slug,
                    'application_name' => $group->first()['application_name'] ?? $slug,
                ], $this->paymentMetrics($group));
            })->sortByDesc('gross')->values();

        $providers = $rows->groupBy(fn (array $row) => $row['provider'] ?: 'não informado')
            ->map(function (Collection $group, string $provider) {
                $metrics = $this->paymentMetrics($group);
                return array_merge([
                    'provider' => $provider,
                    'success_rate' => $metrics['transactions'] > 0
                        ? round(($metrics['approved'] / $metrics['transactions']) * 100, 2)
                        : 0,
                ], $metrics);
            })->sortByDesc('transactions')->values();

        $methods = $rows->groupBy(fn (array $row) => $row['method'] ?: 'não informado')
            ->map(fn (Collection $group, string $method) => array_merge(['method' => $method], $this->paymentMetrics($group)))
            ->sortByDesc('gross')->values();

        $start = now()->subDays(29)->startOfDay();
        $timelineGroups = $rows
            ->filter(fn (array $row) => ! empty($row['financial_at']) && strtotime((string) $row['financial_at']) >= $start->timestamp)
            ->groupBy(fn (array $row) => date('Y-m-d', strtotime((string) $row['financial_at'])));

        $timeline = collect(range(0, 29))->map(function (int $offset) use ($start, $timelineGroups) {
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

        $alerts = collect();
        if ($failed->isNotEmpty()) $alerts->push(['severity' => 'critical', 'title' => 'Falhas de pagamento', 'message' => $failed->count().' transações falharam no período selecionado.']);
        if ($pending->isNotEmpty()) $alerts->push(['severity' => 'warning', 'title' => 'Pagamentos pendentes', 'message' => $pending->count().' transações aguardam conclusão.']);
        if ($refunded->isNotEmpty()) $alerts->push(['severity' => 'warning', 'title' => 'Estornos/chargebacks', 'message' => $refunded->count().' transações exigem acompanhamento financeiro.']);

        return response()->json([
            'summary' => [
                'totals' => $totals,
                'approved' => $this->recognition->bucket($realized),
                'failed' => $this->recognition->bucket($failed),
                'pending' => $this->recognition->bucket($pending),
                'refunded' => $this->recognition->bucket($refunded),
            ],
            'reconciliation' => [
                'recognition_rule' => 'Somente pagamentos confirmados como paid/approved entram no caixa e na receita realizada.',
                'attempted' => $this->recognition->bucket($rows),
                'realized' => $this->recognition->bucket($realized),
                'open' => $this->recognition->bucket($pending),
                'failed' => $this->recognition->bucket($failed),
                'reversed' => $this->recognition->bucket($refunded),
            ],
            'applications' => $applications,
            'providers' => $providers,
            'methods' => $methods,
            'timeline' => $timeline,
            'commerce' => $this->commerceSnapshot(),
            'health' => $this->healthSnapshot(),
            'alerts' => $alerts,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function transactions(Request $request)
    {
        $rows = $this->normalizedPayments($request)->sortByDesc('financial_at')->values();
        $perPage = min(max((int) $request->input('per_page', 50), 10), 200);
        $page = max((int) $request->input('page', 1), 1);
        $slice = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json(new LengthAwarePaginator($slice, $rows->count(), $perPage, $page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]));
    }

    public function transaction(Request $request, int $payment)
    {
        abort_unless(Schema::hasTable('ecosystem_payments'), 503, 'Módulo financeiro ainda não foi migrado.');

        $row = DB::table('ecosystem_payments as p')
            ->leftJoin('applications as a', 'a.id', '=', 'p.app_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.user_id')
            ->leftJoin('productions as pr', 'pr.id', '=', 'p.production_id')
            ->select('p.*', 'a.name as application_name', 'u.email as user_email', 'u.first_name', 'u.last_name', 'pr.name as production_name')
            ->where('p.id', $payment)
            ->first();

        abort_unless($row, 404, 'Transação não encontrada.');
        return response()->json(['transaction' => (object) $this->recognition->normalize((array) $row)]);
    }

    public function orders(Request $request)
    {
        if (! Schema::hasTable('orders')) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $q = DB::table('orders as o')->orderByDesc('o.created_at');
        if ($request->filled('status')) $q->where('o.status', (string) $request->input('status'));
        if ($request->filled('app_id') && Schema::hasColumn('orders', 'app_id')) $q->where('o.app_id', (int) $request->input('app_id'));
        if ($request->filled('from')) $q->whereDate('o.created_at', '>=', $request->date('from'));
        if ($request->filled('to')) $q->whereDate('o.created_at', '<=', $request->date('to'));

        return response()->json($q->paginate(min(max((int) $request->input('per_page', 50), 10), 200)));
    }

    public function payouts(Request $request)
    {
        if (! Schema::hasTable('payout_requests')) {
            return response()->json(['data' => [], 'summary' => ['total' => 0, 'pending' => 0, 'paid' => 0, 'failed' => 0, 'amount_pending' => 0, 'amount_paid' => 0]]);
        }

        $q = DB::table('payout_requests')->orderByDesc('created_at');
        if ($request->filled('status')) $q->where('status', (string) $request->input('status'));
        if ($request->filled('app_id') && Schema::hasColumn('payout_requests', 'app_id')) $q->where('app_id', (int) $request->input('app_id'));

        $all = (clone $q)->get();
        $amountField = Schema::hasColumn('payout_requests', 'amount') ? 'amount' : (Schema::hasColumn('payout_requests', 'net_amount') ? 'net_amount' : null);
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
        return response()->json($this->healthSnapshot());
    }

    private function normalizedPayments(Request $request): Collection
    {
        if (! Schema::hasTable('ecosystem_payments')) return collect();

        $rows = DB::table('ecosystem_payments as p')
            ->leftJoin('applications as a', 'a.id', '=', 'p.app_id')
            ->select('p.*', 'a.name as application_name')
            ->orderByDesc('p.created_at')
            ->limit(5000)
            ->get()
            ->map(fn ($row) => $this->recognition->normalize((array) $row));

        return $rows->filter(function (array $row) use ($request) {
            if ($request->filled('app_slug') && ($row['app_slug'] ?? null) !== (string) $request->input('app_slug')) return false;
            if ($request->filled('provider') && ($row['provider'] ?? null) !== (string) $request->input('provider')) return false;
            if ($request->filled('status') && ($row['status'] ?? null) !== strtolower((string) $request->input('status'))) return false;
            if ($request->filled('method') && ($row['method'] ?? null) !== (string) $request->input('method')) return false;

            $financialAt = (string) ($row['financial_at'] ?? $row['created_at'] ?? '1970-01-01');
            if ($request->filled('from') && strtotime($financialAt) < strtotime((string) $request->input('from').' 00:00:00')) return false;
            if ($request->filled('to') && strtotime($financialAt) > strtotime((string) $request->input('to').' 23:59:59')) return false;
            return true;
        })->values();
    }

    private function paymentMetrics(Collection $rows): array
    {
        $realized = $this->recognition->realized($rows);
        $failed = $this->recognition->failed($rows);
        $bucket = $this->recognition->bucket($realized);

        return [
            'transactions' => $rows->count(),
            'approved' => $realized->count(),
            'failed' => $failed->count(),
            'gross' => $bucket->amount,
            'platform_fees' => $bucket->platform_fees,
            'provider_fees' => $bucket->provider_fees,
            'seller_net' => $bucket->seller_net,
            'attempted_gross' => $this->recognition->bucket($rows)->amount,
        ];
    }

    private function commerceSnapshot(): array
    {
        return [
            'orders' => Schema::hasTable('orders') ? DB::table('orders')->count() : 0,
            'payments' => Schema::hasTable('ecosystem_payments') ? DB::table('ecosystem_payments')->count() : 0,
            'payout_requests' => Schema::hasTable('payout_requests') ? DB::table('payout_requests')->count() : 0,
        ];
    }

    private function healthSnapshot(): array
    {
        return [
            'ready' => Schema::hasTable('ecosystem_payments'),
            'tables' => [
                'payments' => Schema::hasTable('ecosystem_payments'),
                'orders' => Schema::hasTable('orders'),
                'payouts' => Schema::hasTable('payout_requests'),
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
