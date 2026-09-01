<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinancialController extends Controller
{
    public function overview(Request $request)
    {
        if (!Schema::hasTable('cutinapp_payments') || !Schema::hasTable('cutinapp_orders')) {
            return response()->json([
                'summary' => $this->emptySummary(),
                'applications' => [],
                'methods' => [],
                'statuses' => [],
                'providers' => [],
                'recent' => [],
                'notice' => 'O módulo financeiro está ativo, mas ainda não há tabelas de pagamentos publicadas neste ambiente.',
            ]);
        }

        $base = $this->paymentsQuery($request);
        $approved = (clone $base)->where('p.status', 'paid');

        $summary = [
            'payments' => (clone $base)->count(),
            'approved_payments' => (clone $approved)->count(),
            'gross_volume' => round((float) (clone $approved)->sum('p.amount'), 2),
            'platform_revenue' => round((float) (clone $approved)->sum('o.platform_fee'), 2),
            'provider_fees' => round((float) (clone $approved)->sum('p.provider_fee'), 2),
            'producer_net' => round((float) (clone $approved)->sum('o.producer_net'), 2),
            'pending_amount' => round((float) (clone $base)->whereIn('p.status', ['pending','in_process','authorized'])->sum('p.amount'), 2),
            'refunded_amount' => round((float) (clone $base)->whereIn('p.status', ['refunded','charged_back'])->sum('p.amount'), 2),
        ];

        $applications = (clone $approved)
            ->selectRaw("'cutinapp' as app_slug, COUNT(*) as payments_count, SUM(p.amount) as gross_volume, SUM(o.platform_fee) as platform_revenue, SUM(o.producer_net) as seller_net")
            ->groupByRaw("'cutinapp'")
            ->get();

        $recent = (clone $base)
            ->select([
                'p.id','p.provider','p.method','p.status','p.provider_payment_id','p.amount','p.provider_fee','p.paid_at','p.refunded_at','p.created_at',
                'o.public_id as order_public_id','o.platform_fee','o.producer_net','o.production_id','o.event_id','o.user_id',
                'e.title as event_title','pr.name as production_name','u.first_name','u.last_name','u.email',
            ])
            ->orderByDesc('p.id')
            ->limit(20)
            ->get()
            ->map(fn ($row) => $this->serializePayment($row));

        return response()->json([
            'summary' => $summary,
            'applications' => $applications,
            'methods' => DB::table('cutinapp_payments')->select('method')->distinct()->orderBy('method')->pluck('method'),
            'statuses' => DB::table('cutinapp_payments')->select('status')->distinct()->orderBy('status')->pluck('status'),
            'providers' => DB::table('cutinapp_payments')->select('provider')->distinct()->orderBy('provider')->pluck('provider'),
            'recent' => $recent,
        ]);
    }

    public function payments(Request $request)
    {
        if (!Schema::hasTable('cutinapp_payments') || !Schema::hasTable('cutinapp_orders')) {
            return response()->json(['payments' => [], 'pagination' => ['page'=>1,'per_page'=>50,'total'=>0,'last_page'=>1]]);
        }

        $request->validate([
            'app' => 'nullable|string|max:80',
            'status' => 'nullable|string|max:40',
            'method' => 'nullable|string|max:40',
            'provider' => 'nullable|string|max:40',
            'production_id' => 'nullable|integer',
            'user_id' => 'nullable|integer',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'search' => 'nullable|string|max:160',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        $query = $this->paymentsQuery($request)
            ->select([
                'p.id','p.provider','p.method','p.status','p.provider_payment_id','p.amount','p.provider_fee','p.qr_code','p.ticket_url','p.paid_at','p.refunded_at','p.failed_at','p.created_at','p.updated_at',
                'o.public_id as order_public_id','o.status as order_status','o.subtotal','o.platform_fee','o.processor_fee','o.total','o.producer_net','o.production_id','o.event_id','o.user_id',
                'e.title as event_title','pr.name as production_name','u.first_name','u.last_name','u.email',
            ])
            ->orderByDesc('p.id');

        $perPage = (int) $request->input('per_page', 50);
        $page = $query->paginate($perPage);

        return response()->json([
            'payments' => collect($page->items())->map(fn ($row) => $this->serializePayment($row)),
            'pagination' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function payment(int $paymentId)
    {
        abort_unless(Schema::hasTable('cutinapp_payments'), 404);

        $row = $this->paymentsQuery(new Request())
            ->where('p.id', $paymentId)
            ->select([
                'p.*','o.public_id as order_public_id','o.status as order_status','o.subtotal','o.platform_fee','o.processor_fee','o.discount_amount','o.total','o.producer_net','o.production_id','o.event_id','o.user_id','o.metadata as order_metadata',
                'e.title as event_title','pr.name as production_name','u.first_name','u.last_name','u.email',
            ])
            ->firstOrFail();

        $items = DB::table('cutinapp_order_items')->where('order_id', $row->order_id)->orderBy('id')->get();
        $ledger = Schema::hasTable('cutinapp_ledger_entries')
            ? DB::table('cutinapp_ledger_entries')->where('payment_id', $paymentId)->orderBy('id')->get()
            : collect();

        return response()->json([
            'payment' => $this->serializePayment($row),
            'items' => $items,
            'ledger' => $ledger,
            'provider_payload' => $this->decodeJson($row->provider_payload ?? null),
        ]);
    }

    private function paymentsQuery(Request $request)
    {
        $query = DB::table('cutinapp_payments as p')
            ->join('cutinapp_orders as o', 'o.id', '=', 'p.order_id')
            ->leftJoin('events as e', 'e.id', '=', 'o.event_id')
            ->leftJoin('productions as pr', 'pr.id', '=', 'o.production_id')
            ->leftJoin('users as u', 'u.id', '=', 'o.user_id');

        if ($request->filled('app') && $request->string('app')->toString() !== 'cutinapp') $query->whereRaw('1 = 0');
        if ($request->filled('status')) $query->where('p.status', $request->string('status')->toString());
        if ($request->filled('method')) $query->where('p.method', $request->string('method')->toString());
        if ($request->filled('provider')) $query->where('p.provider', $request->string('provider')->toString());
        if ($request->filled('production_id')) $query->where('o.production_id', (int) $request->input('production_id'));
        if ($request->filled('user_id')) $query->where('o.user_id', (int) $request->input('user_id'));
        if ($request->filled('from')) $query->whereDate('p.created_at', '>=', $request->date('from'));
        if ($request->filled('to')) $query->whereDate('p.created_at', '<=', $request->date('to'));
        if ($request->filled('search')) {
            $search = '%' . trim($request->string('search')->toString()) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('o.public_id', 'like', $search)
                    ->orWhere('p.provider_payment_id', 'like', $search)
                    ->orWhere('e.title', 'like', $search)
                    ->orWhere('pr.name', 'like', $search)
                    ->orWhere('u.email', 'like', $search);
            });
        }

        return $query;
    }

    private function serializePayment(object $row): array
    {
        return [
            'id' => $row->id,
            'app_slug' => 'cutinapp',
            'provider' => $row->provider,
            'method' => $row->method,
            'status' => $row->status,
            'provider_payment_id' => $row->provider_payment_id,
            'order_public_id' => $row->order_public_id,
            'amount' => (float) $row->amount,
            'platform_fee' => (float) ($row->platform_fee ?? 0),
            'provider_fee' => (float) ($row->provider_fee ?? 0),
            'seller_net' => (float) ($row->producer_net ?? 0),
            'production' => ['id' => $row->production_id ?? null, 'name' => $row->production_name ?? null],
            'event' => ['id' => $row->event_id ?? null, 'title' => $row->event_title ?? null],
            'customer' => [
                'id' => $row->user_id ?? null,
                'name' => trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')),
                'email' => $row->email ?? null,
            ],
            'paid_at' => $row->paid_at ?? null,
            'refunded_at' => $row->refunded_at ?? null,
            'created_at' => $row->created_at ?? null,
            'updated_at' => $row->updated_at ?? null,
        ];
    }

    private function decodeJson(?string $value): mixed
    {
        if (!$value) return null;
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function emptySummary(): array
    {
        return ['payments'=>0,'approved_payments'=>0,'gross_volume'=>0,'platform_revenue'=>0,'provider_fees'=>0,'producer_net'=>0,'pending_amount'=>0,'refunded_amount'=>0];
    }
}
