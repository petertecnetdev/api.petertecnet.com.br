<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CutinappPromoterPortalController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $email = strtolower((string) $user->email);

        $promoters = DB::table('cutinapp_promoters as p')
            ->leftJoin('cutinapp_event_members as m', 'm.id', '=', 'p.member_id')
            ->join('events as e', 'e.id', '=', 'p.event_id')
            ->leftJoin('cutinapp_sales as s', function ($join) {
                $join->on('s.promoter_id', '=', 'p.id')->where('s.payment_status', '=', 'paid');
            })
            ->where(function ($q) use ($user, $email) {
                $q->where('p.user_id', $user->id)
                    ->orWhere('m.user_id', $user->id)
                    ->orWhereRaw('LOWER(m.email) = ?', [$email]);
            })
            ->groupBy(
                'p.id','p.event_id','p.code','p.commission_type','p.commission_value','p.active',
                'p.starts_at','p.ends_at','e.title','e.start_date','e.end_date','e.city','e.venue'
            )
            ->selectRaw('p.id,p.event_id,p.code,p.commission_type,p.commission_value,p.active,p.starts_at,p.ends_at,e.title as event_title,e.start_date,e.end_date,e.city,e.venue,COUNT(s.id) as sales_count,COALESCE(SUM(s.total),0) as revenue,COALESCE(SUM(s.commission_total),0) as commission_total')
            ->orderByDesc('e.start_date')
            ->get();

        $ids = $promoters->pluck('id');
        $ledger = DB::table('cutinapp_commission_ledger')
            ->whereIn('promoter_id', $ids)
            ->selectRaw("promoter_id,status,COUNT(*) as entries,COALESCE(SUM(amount),0) as total")
            ->groupBy('promoter_id','status')
            ->get()
            ->groupBy('promoter_id');

        $items = $promoters->map(function ($row) use ($ledger) {
            $states = collect($ledger->get($row->id, []))->keyBy('status');
            $row->commission_available = (float) optional($states->get('available'))->total;
            $row->commission_paid = (float) optional($states->get('paid'))->total;
            $row->commission_pending = (float) optional($states->get('pending'))->total;
            return $row;
        });

        return response()->json([
            'summary' => [
                'campaigns' => $items->count(),
                'sales' => (int) $items->sum('sales_count'),
                'revenue' => (float) $items->sum('revenue'),
                'commission_total' => (float) $items->sum('commission_total'),
                'commission_available' => (float) $items->sum('commission_available'),
                'commission_paid' => (float) $items->sum('commission_paid'),
            ],
            'campaigns' => $items->values(),
        ]);
    }
}
