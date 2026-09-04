<?php

namespace App\Domain\Acquisition\Services;

use App\Models\AcquisitionReferral;
use App\Models\CommerceOrder;
use App\Models\EventAcquisitionCommission;
use App\Models\User;
use App\Support\ApplicationContext;

final class AcquisitionAgentService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AcquisitionAccess $access,
    ) {}

    public function context(?User $user): array
    {
        $agent = $this->access->assertAgent($user);

        return [
            'is_agent' => true,
            'role' => 'acquisition_agent',
            'application' => $this->context->application()->only(['id', 'name', 'slug', 'url']),
            'agent' => $agent->only(['id', 'first_name', 'last_name', 'email']),
        ];
    }

    public function dashboard(?User $user): array
    {
        $agent = $this->access->assertAgent($user);
        $appId = $this->context->id();
        $this->expireStaleReferrals($appId, $agent->id);

        $referrals = AcquisitionReferral::query()
            ->where('application_id', $appId)
            ->where('agent_user_id', $agent->id);

        $rules = EventAcquisitionCommission::query()
            ->where('application_id', $appId)
            ->where('agent_user_id', $agent->id)
            ->with(['event:id,production_id,title,slug,start_date,end_date,is_published', 'event.production:id,name,slug'])
            ->orderByDesc('id')
            ->get();

        $eventIds = $rules->pluck('event_id')->unique()->values();
        $sales = $eventIds->isEmpty()
            ? collect()
            : CommerceOrder::query()
                ->where('app_id', $appId)
                ->whereIn('event_id', $eventIds)
                ->where('status', 'paid')
                ->selectRaw('event_id, COUNT(*) as orders_count, COALESCE(SUM(total), 0) as gross_sales')
                ->groupBy('event_id')
                ->get()
                ->keyBy('event_id');

        $commissionRows = $rules->map(function (EventAcquisitionCommission $rule) use ($sales) {
            $sale = $sales->get($rule->event_id);
            $gross = round((float) ($sale?->gross_sales ?? 0), 2);
            $percentage = (float) $rule->percentage;

            return [
                'id' => $rule->id,
                'event_id' => $rule->event_id,
                'event' => $rule->event,
                'percentage' => $percentage,
                'basis' => $rule->basis,
                'orders_count' => (int) ($sale?->orders_count ?? 0),
                'gross_sales' => $gross,
                'commission_amount' => round($gross * ($percentage / 100), 2),
                'commission_locked' => (int) ($sale?->orders_count ?? 0) > 0,
            ];
        });

        $recent = (clone $referrals)
            ->with(['referredUser:id,first_name,last_name,email,email_verified_at', 'production:id,name,slug,is_published'])
            ->withCount('commissions')
            ->latest()
            ->limit(12)
            ->get();

        $total = (clone $referrals)->count();
        $accepted = (clone $referrals)->where('status', 'accepted')->count();

        return [
            'metrics' => [
                'referrals_total' => $total,
                'referrals_pending' => (clone $referrals)->where('status', 'pending')->count(),
                'referrals_accepted' => $accepted,
                'productions_total' => (clone $referrals)->whereNotNull('production_id')->count(),
                'events_total' => $rules->count(),
                'paid_orders' => $commissionRows->sum('orders_count'),
                'gross_sales' => round($commissionRows->sum('gross_sales'), 2),
                'commission_amount' => round($commissionRows->sum('commission_amount'), 2),
                'conversion_rate' => $total > 0 ? round(($accepted / $total) * 100, 1) : 0,
            ],
            'recent_referrals' => $recent,
            'commissions' => $commissionRows,
        ];
    }

    public function referrals(?User $user, array $filters): mixed
    {
        $agent = $this->access->assertAgent($user);
        $this->expireStaleReferrals($this->context->id(), $agent->id);
        $query = AcquisitionReferral::query()
            ->where('application_id', $this->context->id())
            ->where('agent_user_id', $agent->id)
            ->with([
                'referredUser:id,first_name,last_name,email,email_verified_at',
                'production:id,name,slug,is_published',
                'commissions.event:id,production_id,title,slug,start_date,end_date,is_published',
            ])
            ->latest();

        if (! empty($filters['status'])) $query->where('status', $filters['status']);
        if ($term = trim((string) ($filters['q'] ?? ''))) {
            $query->where(fn ($q) => $q
                ->where('email', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%")
                ->orWhereHas('production', fn ($p) => $p->where('name', 'like', "%{$term}%")));
        }

        return $query->paginate($filters['per_page'] ?? 25);
    }

    private function expireStaleReferrals(int $appId, int $agentId): void
    {
        AcquisitionReferral::query()
            ->where('application_id', $appId)
            ->where('agent_user_id', $agentId)
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);
    }

    public function updateCommission(?User $user, int $eventId, float $percentage): EventAcquisitionCommission
    {
        $agent = $this->access->assertAgent($user);
        $appId = $this->context->id();
        $rule = EventAcquisitionCommission::query()
            ->where('application_id', $appId)
            ->where('agent_user_id', $agent->id)
            ->where('event_id', $eventId)
            ->firstOrFail();

        $hasPaidSales = CommerceOrder::query()
            ->where('app_id', $appId)
            ->where('event_id', $eventId)
            ->where('status', 'paid')
            ->exists();

        abort_if(
            $hasPaidSales,
            409,
            'A comissão deste evento foi bloqueada após a primeira venda paga para preservar o histórico financeiro.'
        );

        $rule->update(['percentage' => round($percentage, 2)]);

        return $rule->fresh()->load('event:id,title,slug');
    }
}
