<?php

namespace App\Domain\Assets\Services;

use App\Support\ApplicationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class AssetIntelligenceService
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function profile(string $assetType, int $assetId): array
    {
        $row = DB::table('asset_profiles')
            ->where('app_id', $this->context->id())
            ->where('asset_type', $assetType)
            ->where('asset_id', $assetId)
            ->first();

        if (! $row) return [
            'acquisition_value' => null,
            'market_value' => null,
            'acquired_on' => null,
            'insurance_expires_on' => null,
            'document_expires_on' => null,
            'registry_reference' => null,
            'metadata' => [],
        ];

        $data = (array) $row;
        $data['acquisition_value'] = $row->acquisition_value !== null ? (float) $row->acquisition_value : null;
        $data['market_value'] = $row->market_value !== null ? (float) $row->market_value : null;
        $data['metadata'] = $this->decode($row->metadata);
        return $data;
    }

    public function financial(string $assetType, int $assetId): array
    {
        $leaseIds = $this->leaseIds($assetType, $assetId);
        $charges = $leaseIds->isEmpty() ? collect() : DB::table('lease_charges')
            ->where('app_id', $this->context->id())
            ->whereIn('lease_id', $leaseIds)
            ->orderByDesc('due_date')
            ->get();

        $entries = DB::table('asset_financial_entries')
            ->where('app_id', $this->context->id())
            ->where('asset_type', $assetType)
            ->where('asset_id', $assetId)
            ->whereNull('deleted_at')
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($row) => $this->financialEntryPayload($row));

        $maintenance = DB::table('lease_operations')
            ->where('app_id', $this->context->id())
            ->where('property_id', $assetType === 'property' ? $assetId : -1)
            ->where('type', 'maintenance')
            ->whereNull('deleted_at')
            ->get();

        $now = CarbonImmutable::now();
        $yearStart = $now->startOfYear();
        $last12 = $now->subMonths(12)->startOfDay();

        $paid = $charges->filter(fn ($charge) => $charge->status === 'paid');
        $pending = $charges->filter(fn ($charge) => in_array($charge->status, ['pending', 'processing'], true));
        $overdue = $pending->filter(fn ($charge) => $charge->due_date && CarbonImmutable::parse($charge->due_date)->isBefore($now->startOfDay()));
        $paid12 = $paid->filter(fn ($charge) => $charge->paid_at && CarbonImmutable::parse($charge->paid_at)->gte($last12));
        $paidYear = $paid->filter(fn ($charge) => $charge->paid_at && CarbonImmutable::parse($charge->paid_at)->gte($yearStart));

        $ledgerIncome12 = $entries->filter(fn ($entry) => $entry['direction'] === 'income' && $entry['status'] === 'paid' && CarbonImmutable::parse($entry['occurred_on'])->gte($last12))->sum('amount');
        $ledgerExpense12 = $entries->filter(fn ($entry) => $entry['direction'] === 'expense' && $entry['status'] === 'paid' && $entry['category'] !== 'maintenance' && CarbonImmutable::parse($entry['occurred_on'])->gte($last12))->sum('amount');
        $ledgerIncomeYear = $entries->filter(fn ($entry) => $entry['direction'] === 'income' && $entry['status'] === 'paid' && CarbonImmutable::parse($entry['occurred_on'])->gte($yearStart))->sum('amount');
        $ledgerExpenseYear = $entries->filter(fn ($entry) => $entry['direction'] === 'expense' && $entry['status'] === 'paid' && $entry['category'] !== 'maintenance' && CarbonImmutable::parse($entry['occurred_on'])->gte($yearStart))->sum('amount');

        $maintenance12 = $maintenance->filter(function ($operation) use ($last12) {
            $at = $operation->completed_at ?: $operation->occurred_at ?: $operation->created_at;
            return $operation->status === 'completed' && $operation->amount !== null && $at && CarbonImmutable::parse($at)->gte($last12);
        })->sum('amount');
        $maintenanceYear = $maintenance->filter(function ($operation) use ($yearStart) {
            $at = $operation->completed_at ?: $operation->occurred_at ?: $operation->created_at;
            return $operation->status === 'completed' && $operation->amount !== null && $at && CarbonImmutable::parse($at)->gte($yearStart);
        })->sum('amount');

        $rentRevenue12 = (float) $paid12->sum('amount');
        $rentRevenueYear = (float) $paidYear->sum('amount');
        $grossIncome12 = $rentRevenue12 + (float) $ledgerIncome12;
        $grossIncomeYear = $rentRevenueYear + (float) $ledgerIncomeYear;
        $expenses12 = (float) $ledgerExpense12 + (float) $maintenance12;
        $expensesYear = (float) $ledgerExpenseYear + (float) $maintenanceYear;
        $net12 = $grossIncome12 - $expenses12;
        $netYear = $grossIncomeYear - $expensesYear;
        $profile = $this->profile($assetType, $assetId);
        $yieldBase = (float) ($profile['acquisition_value'] ?: $profile['market_value'] ?: 0);

        $monthly = collect(range(11, 0))->map(function (int $monthsAgo) use ($now, $paid, $entries, $maintenance) {
            $month = $now->subMonths($monthsAgo);
            $key = $month->format('Y-m');
            $income = (float) $paid->filter(fn ($charge) => $charge->paid_at && CarbonImmutable::parse($charge->paid_at)->format('Y-m') === $key)->sum('amount');
            $income += (float) $entries->filter(fn ($entry) => $entry['direction'] === 'income' && $entry['status'] === 'paid' && CarbonImmutable::parse($entry['occurred_on'])->format('Y-m') === $key)->sum('amount');
            $expense = (float) $entries->filter(fn ($entry) => $entry['direction'] === 'expense' && $entry['status'] === 'paid' && $entry['category'] !== 'maintenance' && CarbonImmutable::parse($entry['occurred_on'])->format('Y-m') === $key)->sum('amount');
            $expense += (float) $maintenance->filter(function ($operation) use ($key) {
                $at = $operation->completed_at ?: $operation->occurred_at ?: $operation->created_at;
                return $operation->status === 'completed' && $operation->amount !== null && $at && CarbonImmutable::parse($at)->format('Y-m') === $key;
            })->sum('amount');
            return ['month' => $key, 'income' => round($income, 2), 'expenses' => round($expense, 2), 'net' => round($income - $expense, 2)];
        })->values();

        $scheduledDue = $charges->filter(fn ($charge) => $charge->due_date && CarbonImmutable::parse($charge->due_date)->lte($now))->sum('amount');
        $delinquencyRate = $scheduledDue > 0 ? ((float) $overdue->sum('amount') / (float) $scheduledDue) * 100 : 0;

        return [
            'summary' => [
                'pending' => round((float) $pending->sum('amount'), 2),
                'overdue' => round((float) $overdue->sum('amount'), 2),
                'paid_total' => round((float) $paid->sum('amount'), 2),
                'paid_this_year' => round($rentRevenueYear, 2),
                'rent_revenue_12m' => round($rentRevenue12, 2),
                'other_income_12m' => round((float) $ledgerIncome12, 2),
                'owner_expenses_12m' => round((float) $ledgerExpense12, 2),
                'maintenance_cost_12m' => round((float) $maintenance12, 2),
                'gross_income_12m' => round($grossIncome12, 2),
                'net_result_12m' => round($net12, 2),
                'gross_income_this_year' => round($grossIncomeYear, 2),
                'expenses_this_year' => round($expensesYear, 2),
                'net_result_this_year' => round($netYear, 2),
                'gross_yield_12m' => $yieldBase > 0 ? round(($grossIncome12 / $yieldBase) * 100, 2) : null,
                'net_yield_12m' => $yieldBase > 0 ? round(($net12 / $yieldBase) * 100, 2) : null,
                'delinquency_rate' => round($delinquencyRate, 2),
                'scheduled_total' => round((float) $charges->sum('amount'), 2),
                'next_due' => $pending->sortBy('due_date')->first(),
            ],
            'profile' => $profile,
            'monthly' => $monthly,
            'entries' => $entries->values(),
            'charges' => $charges->take(240)->values(),
        ];
    }

    public function analytics(string $assetType, int $assetId): array
    {
        $financial = $this->financial($assetType, $assetId);
        $leases = $this->leases($assetType, $assetId);
        $occupancy = $this->occupancyMetrics($leases, $assetType, $assetId);
        $rents = $leases->sortBy('starts_on')->values()->map(fn ($lease) => [
            'lease_id' => (int) $lease->id,
            'starts_on' => $lease->starts_on,
            'ends_on' => $lease->ends_on,
            'rent_amount' => (float) $lease->rent_amount,
            'tenant_name' => $lease->tenant_name,
        ]);
        $firstRent = (float) ($rents->first()['rent_amount'] ?? 0);
        $lastRent = (float) ($rents->last()['rent_amount'] ?? 0);

        return [
            'occupancy' => $occupancy,
            'financial' => $financial['summary'],
            'rent_evolution' => $rents,
            'rent_change_percent' => $firstRent > 0 ? round((($lastRent - $firstRent) / $firstRent) * 100, 2) : null,
            'health' => $this->health($assetType, $assetId, $financial, $occupancy),
        ];
    }

    public function health(string $assetType, int $assetId, ?array $financial = null, ?array $occupancy = null): array
    {
        $financial ??= $this->financial($assetType, $assetId);
        $occupancy ??= $this->occupancyMetrics($this->leases($assetType, $assetId), $assetType, $assetId);
        $alerts = $this->alerts($assetType, $assetId, false);
        $score = 100;
        $factors = [];

        $deduct = function (int $points, string $key, string $label, string $detail) use (&$score, &$factors) {
            $score = max(0, $score - $points);
            $factors[] = ['key' => $key, 'impact' => -$points, 'label' => $label, 'detail' => $detail];
        };

        $overdue = (float) ($financial['summary']['overdue'] ?? 0);
        if ($overdue > 0) $deduct(min(25, 10 + (int) floor(log10(max(1, $overdue)))), 'overdue', 'Há cobrança em atraso', 'Regularize recebimentos vencidos para melhorar a saúde financeira.');

        $urgent = collect($alerts)->whereIn('key', ['maintenance_urgent', 'maintenance_overdue'])->count();
        if ($urgent > 0) $deduct(min(20, $urgent * 7), 'maintenance', 'Manutenção requer atenção', "{$urgent} ocorrência(s) crítica(s) ou atrasada(s).");

        $preventive = collect($alerts)->where('key', 'preventive_overdue')->count();
        if ($preventive > 0) $deduct(min(15, $preventive * 5), 'preventive', 'Preventivas vencidas', "{$preventive} rotina(s) preventiva(s) precisam ser executadas.");

        if (($occupancy['vacancy_days'] ?? 0) > 30) $deduct(($occupancy['vacancy_days'] ?? 0) > 90 ? 15 : 8, 'vacancy', 'Vacância elevada', "Imóvel desocupado há {$occupancy['vacancy_days']} dia(s).");
        if (collect($alerts)->where('key', 'inspection_stale')->isNotEmpty()) $deduct(8, 'inspection', 'Vistoria desatualizada', 'Registre uma vistoria para manter o estado do patrimônio documentado.');
        if (collect($alerts)->where('key', 'documents_missing')->isNotEmpty()) $deduct(5, 'documents', 'Documentação patrimonial incompleta', 'Adicione documentos permanentes do imóvel.');
        if (collect($alerts)->where('key', 'insurance_expired')->isNotEmpty()) $deduct(10, 'insurance', 'Seguro vencido', 'Atualize a cobertura ou a data de validade.');
        if (collect($alerts)->where('key', 'document_expired')->isNotEmpty()) $deduct(10, 'document_expiry', 'Documento vencido', 'Há um documento patrimonial com validade expirada.');
        if (($financial['profile']['market_value'] ?? null) === null && ($financial['profile']['acquisition_value'] ?? null) === null) $deduct(3, 'valuation', 'Valor patrimonial não informado', 'Informe valor de aquisição ou valor de mercado para calcular rentabilidade.');

        $positive = [];
        if ($overdue <= 0) $positive[] = 'Sem inadimplência vencida';
        if (($occupancy['occupancy_rate_12m'] ?? 0) >= 90) $positive[] = 'Ocupação de 12 meses acima de 90%';
        if (($financial['summary']['net_result_12m'] ?? 0) > 0) $positive[] = 'Resultado líquido positivo em 12 meses';
        if (! collect($alerts)->whereIn('key', ['maintenance_urgent', 'maintenance_overdue'])->count()) $positive[] = 'Sem manutenção crítica atrasada';

        return [
            'score' => $score,
            'grade' => match (true) {
                $score >= 90 => 'excellent',
                $score >= 75 => 'good',
                $score >= 60 => 'attention',
                default => 'critical',
            },
            'factors' => $factors,
            'positive_factors' => $positive,
        ];
    }

    public function alerts(string $assetType, int $assetId, bool $includeHealth = true): array
    {
        $now = CarbonImmutable::now();
        $alerts = collect();
        $leaseIds = $this->leaseIds($assetType, $assetId);
        $leases = $this->leases($assetType, $assetId);
        $current = $leases->first(function ($lease) use ($now) {
            return $lease->status === 'active'
                && (! $lease->starts_on || CarbonImmutable::parse($lease->starts_on)->lte($now))
                && (! $lease->ends_on || CarbonImmutable::parse($lease->ends_on)->gte($now));
        });

        if (! $leaseIds->isEmpty()) {
            DB::table('lease_charges')
                ->where('app_id', $this->context->id())
                ->whereIn('lease_id', $leaseIds)
                ->whereIn('status', ['pending', 'processing'])
                ->whereDate('due_date', '<', today())
                ->orderBy('due_date')
                ->limit(20)
                ->get()
                ->each(fn ($row) => $alerts->push($this->alert('charge_overdue', 'critical', 'Cobrança vencida', $row->description, $row->due_date, ['charge_id' => $row->id, 'amount' => (float) $row->amount])));
        }

        if ($current?->ends_on) {
            $days = $now->startOfDay()->diffInDays(CarbonImmutable::parse($current->ends_on)->startOfDay(), false);
            if ($days >= 0 && $days <= 60) $alerts->push($this->alert('lease_ending', $days <= 30 ? 'attention' : 'upcoming', 'Contrato perto do fim', "A locação termina em {$days} dia(s).", $current->ends_on, ['lease_id' => $current->id, 'days' => $days]));
        }

        if ($current?->starts_on) {
            $frequency = max(1, (int) ($current->adjustment_frequency_months ?: 12));
            $lastAdjustment = DB::table('lease_operations')->where('app_id', $this->context->id())->where('lease_id', $current->id)->where('type', 'rent_adjustment')->whereNull('deleted_at')->orderByDesc('occurred_at')->first();
            $base = $lastAdjustment?->occurred_at ? CarbonImmutable::parse($lastAdjustment->occurred_at) : CarbonImmutable::parse($current->starts_on);
            $next = $base->addMonths($frequency);
            $days = $now->startOfDay()->diffInDays($next->startOfDay(), false);
            if ($days <= 30) $alerts->push($this->alert('rent_adjustment', $days < 0 ? 'attention' : 'upcoming', $days < 0 ? 'Reajuste vencido' : 'Reajuste próximo', 'Revise o índice e o novo aluguel.', $next->toDateString(), ['lease_id' => $current->id, 'days' => $days]));
        }

        DB::table('lease_operations')
            ->where('app_id', $this->context->id())
            ->where('property_id', $assetType === 'property' ? $assetId : -1)
            ->where('type', 'maintenance')
            ->whereIn('status', ['open', 'in_progress', 'waiting'])
            ->whereNull('deleted_at')
            ->get()
            ->each(function ($row) use ($alerts, $now) {
                $overdue = $row->due_at && CarbonImmutable::parse($row->due_at)->lt($now);
                if ($overdue) $alerts->push($this->alert('maintenance_overdue', in_array($row->priority, ['urgent', 'high'], true) ? 'critical' : 'attention', 'Manutenção atrasada', $row->title, $row->due_at, ['operation_id' => $row->id]));
                elseif ($row->priority === 'urgent') $alerts->push($this->alert('maintenance_urgent', 'critical', 'Manutenção urgente', $row->title, $row->due_at, ['operation_id' => $row->id]));
            });

        DB::table('asset_maintenance_plans')
            ->where('app_id', $this->context->id())
            ->where('asset_type', $assetType)
            ->where('asset_id', $assetId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->get()
            ->each(function ($plan) use ($alerts, $now) {
                $days = $now->startOfDay()->diffInDays(CarbonImmutable::parse($plan->next_due_on)->startOfDay(), false);
                if ($days < 0) $alerts->push($this->alert('preventive_overdue', $plan->priority === 'urgent' ? 'critical' : 'attention', 'Manutenção preventiva vencida', $plan->title, $plan->next_due_on, ['plan_id' => $plan->id, 'days' => $days]));
                elseif ($days <= 30) $alerts->push($this->alert('preventive_due', 'upcoming', 'Preventiva próxima', $plan->title, $plan->next_due_on, ['plan_id' => $plan->id, 'days' => $days]));
            });

        $lastInspection = DB::table('property_inspections')
            ->where('app_id', $this->context->id())
            ->where('property_id', $assetType === 'property' ? $assetId : -1)
            ->orderByDesc('occurred_at')
            ->first();
        if (! $lastInspection || CarbonImmutable::parse($lastInspection->occurred_at)->lt($now->subYear())) {
            $alerts->push($this->alert('inspection_stale', 'attention', 'Vistoria pendente', $lastInspection ? 'A última vistoria tem mais de 12 meses.' : 'Nenhuma vistoria foi registrada.', null));
        }

        $documents = DB::table('files')->where('app_id', $this->context->id())->where('entity_name', $assetType)->where('entity_id', $assetId)->where('status', 'active')->where('group', 'documents')->count();
        if ($documents === 0) $alerts->push($this->alert('documents_missing', 'attention', 'Documentação patrimonial ausente', 'Adicione matrícula, IPTU, planta, seguro ou outros documentos permanentes.', null));

        $profile = $this->profile($assetType, $assetId);
        if ($profile['insurance_expires_on'] && CarbonImmutable::parse($profile['insurance_expires_on'])->lt($now->startOfDay())) $alerts->push($this->alert('insurance_expired', 'critical', 'Seguro vencido', 'A validade do seguro informada já passou.', $profile['insurance_expires_on']));
        elseif ($profile['insurance_expires_on'] && $now->diffInDays(CarbonImmutable::parse($profile['insurance_expires_on']), false) <= 30) $alerts->push($this->alert('insurance_expiring', 'upcoming', 'Seguro perto do vencimento', 'Revise a renovação da cobertura.', $profile['insurance_expires_on']));
        if ($profile['document_expires_on'] && CarbonImmutable::parse($profile['document_expires_on'])->lt($now->startOfDay())) $alerts->push($this->alert('document_expired', 'attention', 'Documento patrimonial vencido', 'Atualize o documento e a validade cadastrada.', $profile['document_expires_on']));

        DB::table('asset_financial_entries')
            ->where('app_id', $this->context->id())
            ->where('asset_type', $assetType)
            ->where('asset_id', $assetId)
            ->where('status', 'pending')
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<', today())
            ->whereNull('deleted_at')
            ->limit(20)
            ->get()
            ->each(fn ($row) => $alerts->push($this->alert('asset_expense_overdue', 'attention', 'Despesa patrimonial vencida', $row->description, $row->due_on, ['entry_id' => $row->id, 'amount' => (float) $row->amount])));

        $occupancy = $this->occupancyMetrics($leases, $assetType, $assetId);
        if (($occupancy['vacancy_days'] ?? 0) > 30) $alerts->push($this->alert('vacancy', ($occupancy['vacancy_days'] ?? 0) > 90 ? 'attention' : 'upcoming', 'Imóvel desocupado', "Sem locação vigente há {$occupancy['vacancy_days']} dia(s).", null, ['vacancy_days' => $occupancy['vacancy_days']]));

        $damaged = DB::table('asset_inventory_items')
            ->where('app_id', $this->context->id())
            ->where('asset_type', $assetType)
            ->where('asset_id', $assetId)
            ->whereIn('condition', ['poor', 'damaged', 'missing'])
            ->whereNull('deleted_at')
            ->count();
        if ($damaged > 0) $alerts->push($this->alert('inventory_condition', 'attention', 'Inventário requer revisão', "{$damaged} item(ns) estão em estado ruim, danificado ou ausente.", null, ['count' => $damaged]));

        return $alerts->sortBy(fn ($alert) => ['critical' => 0, 'attention' => 1, 'upcoming' => 2][$alert['severity']] ?? 9)->values()->all();
    }

    public function audit(string $assetType, int $assetId, ?int $actorUserId, string $eventType, string $title, ?string $description = null, mixed $before = null, mixed $after = null, array $metadata = []): void
    {
        DB::table('asset_audit_events')->insert([
            'app_id' => $this->context->id(),
            'asset_type' => $assetType,
            'asset_id' => $assetId,
            'actor_user_id' => $actorUserId,
            'event_type' => $eventType,
            'title' => $title,
            'description' => $description,
            'before' => $before === null ? null : $this->json($before),
            'after' => $after === null ? null : $this->json($after),
            'metadata' => $metadata === [] ? null : $this->json($metadata),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function auditEvents(string $assetType, int $assetId, int $limit = 200): Collection
    {
        return DB::table('asset_audit_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.actor_user_id')
            ->where('e.app_id', $this->context->id())
            ->where('e.asset_type', $assetType)
            ->where('e.asset_id', $assetId)
            ->orderByDesc('e.occurred_at')
            ->limit(max(1, min(500, $limit)))
            ->get(['e.*', DB::raw("COALESCE(u.name, u.first_name, u.email) as actor_name"), 'u.email as actor_email'])
            ->map(function ($row) {
                $data = (array) $row;
                $data['before'] = $this->decode($row->before);
                $data['after'] = $this->decode($row->after);
                $data['metadata'] = $this->decode($row->metadata);
                return $data;
            });
    }

    public function occupancyMetrics(Collection $leases, string $assetType, int $assetId): array
    {
        $today = CarbonImmutable::today();
        $start = $today->subDays(364);
        $occupiedDays = 0;
        foreach ($leases as $lease) {
            if (in_array($lease->status, ['cancelled', 'draft'], true)) continue;
            $leaseStart = CarbonImmutable::parse($lease->starts_on)->startOfDay();
            $leaseEnd = CarbonImmutable::parse($lease->ends_on)->startOfDay();
            $from = $leaseStart->greaterThan($start) ? $leaseStart : $start;
            $to = $leaseEnd->lessThan($today) ? $leaseEnd : $today;
            if ($to->gte($from)) $occupiedDays += $from->diffInDays($to) + 1;
        }
        $occupiedDays = min(365, $occupiedDays);

        $sorted = $leases->sortBy('starts_on')->values();
        $gaps = [];
        for ($i = 1; $i < $sorted->count(); $i++) {
            $previousEnd = CarbonImmutable::parse($sorted[$i - 1]->ends_on)->startOfDay();
            $nextStart = CarbonImmutable::parse($sorted[$i]->starts_on)->startOfDay();
            $gap = $previousEnd->diffInDays($nextStart, false) - 1;
            if ($gap > 0) $gaps[] = $gap;
        }

        $current = $leases->first(function ($lease) use ($today) {
            return $lease->status === 'active'
                && CarbonImmutable::parse($lease->starts_on)->startOfDay()->lte($today)
                && CarbonImmutable::parse($lease->ends_on)->startOfDay()->gte($today);
        });
        if ($current) $vacancyDays = 0;
        else {
            $latestEnd = $leases->filter(fn ($lease) => $lease->ends_on && CarbonImmutable::parse($lease->ends_on)->lt($today))->sortByDesc('ends_on')->first();
            if ($latestEnd) $vacancyDays = CarbonImmutable::parse($latestEnd->ends_on)->startOfDay()->diffInDays($today);
            else {
                $created = $assetType === 'property' ? DB::table('properties')->where('app_id', $this->context->id())->where('id', $assetId)->value('created_at') : null;
                $vacancyDays = $created ? CarbonImmutable::parse($created)->startOfDay()->diffInDays($today) : 0;
            }
        }

        return [
            'occupied_days_12m' => $occupiedDays,
            'vacant_days_12m' => max(0, 365 - $occupiedDays),
            'occupancy_rate_12m' => round(($occupiedDays / 365) * 100, 2),
            'vacancy_days' => $vacancyDays,
            'average_vacancy_between_leases' => $gaps ? round(array_sum($gaps) / count($gaps), 1) : null,
            'historical_lease_count' => $leases->count(),
        ];
    }

    private function leases(string $assetType, int $assetId): Collection
    {
        if ($assetType !== 'property') return collect();
        return DB::table('leases')
            ->where('app_id', $this->context->id())
            ->where('property_id', $assetId)
            ->whereNull('deleted_at')
            ->orderByDesc('starts_on')
            ->get();
    }

    private function leaseIds(string $assetType, int $assetId): Collection
    {
        return $this->leases($assetType, $assetId)->pluck('id');
    }

    private function financialEntryPayload(object $row): array
    {
        $data = (array) $row;
        $data['amount'] = (float) $row->amount;
        $data['recurring'] = (bool) $row->recurring;
        $data['metadata'] = $this->decode($row->metadata);
        return $data;
    }

    private function alert(string $key, string $severity, string $title, string $message, mixed $dueAt = null, array $data = []): array
    {
        return compact('key', 'severity', 'title', 'message', 'dueAt', 'data');
    }

    private function decode(mixed $value): array
    {
        if (is_array($value)) return $value;
        if ($value === null || $value === '') return [];
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
