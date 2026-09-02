<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinancialController extends Controller
{
    private function payments(Request $request)
    {
        $q = DB::table('ecosystem_payments as p')
            ->leftJoin('applications as a', 'a.id', '=', 'p.app_id');

        if ($request->filled('app_slug')) $q->where('p.app_slug', $request->string('app_slug'));
        if ($request->filled('provider')) $q->where('p.provider', $request->string('provider'));
        if ($request->filled('status')) $q->where('p.status', $request->string('status'));
        if ($request->filled('method')) $q->where('p.method', $request->string('method'));
        if ($request->filled('from')) $q->whereDate('p.created_at', '>=', $request->date('from'));
        if ($request->filled('to')) $q->whereDate('p.created_at', '<=', $request->date('to'));

        return $q;
    }

    public function dashboard(Request $request)
    {
        abort_unless(Schema::hasTable('ecosystem_payments'), 503, 'Módulo financeiro ainda não foi migrado.');

        $base = $this->payments($request);
        $totals = (clone $base)->selectRaw('COUNT(*) transactions, COALESCE(SUM(p.gross_amount),0) gross, COALESCE(SUM(p.platform_fee),0) platform_fees, COALESCE(SUM(p.provider_fee),0) provider_fees, COALESCE(SUM(p.seller_net),0) seller_net')->first();
        $approved = (clone $base)->whereIn('p.status', ['approved','paid'])->selectRaw('COUNT(*) count, COALESCE(SUM(p.gross_amount),0) amount')->first();
        $failed = (clone $base)->whereIn('p.status', ['failed','rejected','cancelled'])->selectRaw('COUNT(*) count, COALESCE(SUM(p.gross_amount),0) amount')->first();
        $pending = (clone $base)->whereIn('p.status', ['pending','in_process','authorized'])->selectRaw('COUNT(*) count, COALESCE(SUM(p.gross_amount),0) amount')->first();
        $refunded = (clone $base)->whereIn('p.status', ['refunded','charged_back'])->selectRaw('COUNT(*) count, COALESCE(SUM(p.gross_amount),0) amount')->first();
        $byApp = (clone $base)->groupBy('p.app_slug','a.name')->selectRaw('p.app_slug, a.name application_name, COUNT(*) transactions, COALESCE(SUM(p.gross_amount),0) gross, COALESCE(SUM(p.platform_fee),0) platform_fees, COALESCE(SUM(p.seller_net),0) seller_net')->orderByDesc('gross')->get();
        $byProvider = (clone $base)->groupBy('p.provider')->selectRaw("p.provider, COUNT(*) transactions, COALESCE(SUM(p.gross_amount),0) gross, SUM(CASE WHEN p.status IN ('approved','paid') THEN 1 ELSE 0 END) approved, SUM(CASE WHEN p.status IN ('failed','rejected') THEN 1 ELSE 0 END) failed")->get();
        $timeline = (clone $base)->where('p.created_at', '>=', now()->subDays(30))->groupByRaw('DATE(p.created_at)')->selectRaw("DATE(p.created_at) day, COUNT(*) transactions, COALESCE(SUM(p.gross_amount),0) gross, COALESCE(SUM(p.platform_fee),0) platform_fees, COALESCE(SUM(CASE WHEN p.status IN ('approved','paid') THEN p.gross_amount ELSE 0 END),0) approved_amount")->orderBy('day')->get();

        $alerts = collect();
        if (($failed->count ?? 0) > 0) $alerts->push(['severity'=>'critical','title'=>'Falhas de pagamento','message'=>"{$failed->count} transações falharam no período selecionado."]);
        if (($pending->count ?? 0) > 0) $alerts->push(['severity'=>'warning','title'=>'Pagamentos pendentes','message'=>"{$pending->count} transações aguardam conclusão."]);
        if (($refunded->count ?? 0) > 0) $alerts->push(['severity'=>'warning','title'=>'Estornos/chargebacks','message'=>"{$refunded->count} transações exigem acompanhamento financeiro."]);

        return response()->json([
            'summary' => compact('totals','approved','failed','pending','refunded'),
            'applications' => $byApp,
            'providers' => $byProvider,
            'timeline' => $timeline,
            'alerts' => $alerts,
            'generated_at' => now(),
        ]);
    }

    public function transactions(Request $request)
    {
        abort_unless(Schema::hasTable('ecosystem_payments'), 503, 'Módulo financeiro ainda não foi migrado.');

        $rows = $this->payments($request)
            ->select('p.*', 'a.name as application_name')
            ->orderByDesc('p.created_at')
            ->paginate(min(max((int) $request->input('per_page', 50), 10), 200));

        return response()->json($rows);
    }

    public function transaction(int $payment)
    {
        abort_unless(Schema::hasTable('ecosystem_payments'), 503, 'Módulo financeiro ainda não foi migrado.');

        $row = DB::table('ecosystem_payments as p')
            ->leftJoin('applications as a','a.id','=','p.app_id')
            ->leftJoin('users as u','u.id','=','p.user_id')
            ->select('p.*','a.name as application_name','u.email as user_email','u.first_name','u.last_name')
            ->where('p.id',$payment)
            ->first();

        abort_unless($row, 404, 'Transação não encontrada.');
        return response()->json(['transaction'=>$row]);
    }

    public function payouts(Request $request)
    {
        if (!Schema::hasTable('cutinapp_payout_requests')) {
            return response()->json(['data'=>[], 'summary'=>['total'=>0,'pending'=>0,'paid'=>0]]);
        }

        $q = DB::table('cutinapp_payout_requests')->orderByDesc('created_at');
        if ($request->filled('status')) $q->where('status',$request->string('status'));

        $summary = [
            'total' => (clone $q)->count(),
            'pending' => (clone $q)->whereIn('status',['pending','requested','processing'])->count(),
            'paid' => (clone $q)->whereIn('status',['paid','completed'])->count(),
        ];

        return response()->json(['data'=>$q->limit(200)->get(),'summary'=>$summary]);
    }
}
