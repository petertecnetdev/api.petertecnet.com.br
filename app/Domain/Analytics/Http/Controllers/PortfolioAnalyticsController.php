<?php

namespace App\Domain\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PortfolioAnalyticsController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function overview(Request $request)
    {
        $userId = (int) $request->user()->id;
        $appId = $this->context->id();
        $today = CarbonImmutable::today();

        $leaseIds = DB::table('leases')
            ->where('app_id', $appId)
            ->whereNull('deleted_at')
            ->where(function ($query) use ($request, $userId) {
                $query->where('landlord_user_id', $userId)
                    ->orWhere('tenant_user_id', $userId)
                    ->orWhere('tenant_email', $request->user()->email);
            })
            ->pluck('id');

        $properties = DB::table('properties')
            ->where('app_id', $appId)
            ->where('owner_user_id', $userId)
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'status']);

        $leases = DB::table('leases')
            ->where('app_id', $appId)
            ->whereIn('id', $leaseIds)
            ->whereNull('deleted_at')
            ->get(['id', 'property_id', 'tenant_name', 'status', 'starts_on', 'ends_on', 'rent_amount', 'due_day', 'deposit_months', 'deposit_amount', 'contract_generated_at']);

        $charges = DB::table('lease_charges')
            ->where('app_id', $appId)
            ->whereIn('lease_id', $leaseIds);

        $pendingAmount = (clone $charges)->whereIn('status', ['pending', 'processing'])->sum('amount');
        $overdueAmount = (clone $charges)->whereIn('status', ['pending', 'processing'])->whereDate('due_date', '<', $today)->sum('amount');
        $receivedThisYear = (clone $charges)->where('status', 'paid')->whereYear('paid_at', $today->year)->sum('amount');
        $expectedThisYear = (clone $charges)->whereYear('due_date', $today->year)->sum('amount');

        $occupied = $properties->where('status', 'occupied')->count();
        $available = $properties->whereIn('status', ['available', 'vacant'])->count();
        $maintenance = $properties->whereIn('status', ['maintenance', 'unavailable'])->count();
        $active = $leases->where('status', 'active')->count();
        $awaitingSignature = $leases->where('status', 'awaiting_signature')->count();
        $awaitingDocuments = $leases->whereIn('status', ['draft', 'awaiting_documents'])->count();

        $expiring = $leases->filter(function ($lease) use ($today) {
            if ($lease->status !== 'active' || ! $lease->ends_on) return false;
            $end = CarbonImmutable::parse($lease->ends_on);
            return $end->betweenIncluded($today, $today->addDays(30));
        });

        $issues = collect();
        if ($overdueAmount > 0) $issues->push(['type' => 'overdue', 'severity' => 'critical', 'count' => 1, 'amount' => round((float) $overdueAmount, 2), 'title' => 'Recebimentos em atraso']);
        if ($expiring->isNotEmpty()) $issues->push(['type' => 'expiring_contracts', 'severity' => 'warning', 'count' => $expiring->count(), 'title' => 'Contratos vencendo em até 30 dias']);
        if ($awaitingDocuments > 0) $issues->push(['type' => 'documents', 'severity' => 'warning', 'count' => $awaitingDocuments, 'title' => 'Locações aguardando documentação']);
        if ($awaitingSignature > 0) $issues->push(['type' => 'signatures', 'severity' => 'warning', 'count' => $awaitingSignature, 'title' => 'Contratos aguardando assinatura']);
        if ($available > 0) $issues->push(['type' => 'vacancy', 'severity' => 'info', 'count' => $available, 'title' => 'Imóveis disponíveis']);

        $penalty = min(100,
            ($overdueAmount > 0 ? 35 : 0) +
            min(25, $expiring->count() * 8) +
            min(20, $awaitingDocuments * 5) +
            min(10, $awaitingSignature * 4) +
            min(10, $available * 3)
        );
        $healthScore = max(0, 100 - $penalty);

        $cashFlow = collect([7, 30, 60, 90])->map(function (int $days) use ($charges, $today) {
            $until = $today->addDays($days);
            $future = (clone $charges)
                ->whereIn('status', ['pending', 'processing'])
                ->whereDate('due_date', '>=', $today)
                ->whereDate('due_date', '<=', $until)
                ->sum('amount');
            return [
                'horizon_days' => $days,
                'expected_amount' => round((float) $future, 2),
                'net_amount' => round((float) $future, 2),
            ];
        })->values();

        $calendarCharges = (clone $charges)
            ->whereIn('status', ['pending', 'processing'])
            ->whereDate('due_date', '>=', $today)
            ->whereDate('due_date', '<=', $today->addDays(90))
            ->orderBy('due_date')
            ->limit(60)
            ->get(['id', 'lease_id', 'description', 'due_date', 'amount', 'status'])
            ->map(fn ($charge) => [
                'id' => 'charge-'.$charge->id,
                'type' => 'charge',
                'date' => $charge->due_date,
                'title' => $charge->description ?: 'Cobrança',
                'amount' => round((float) $charge->amount, 2),
                'severity' => 'normal',
                'resource_type' => 'lease',
                'resource_id' => $charge->lease_id,
            ]);

        $calendarContracts = $leases
            ->filter(fn ($lease) => $lease->ends_on && CarbonImmutable::parse($lease->ends_on)->betweenIncluded($today, $today->addDays(90)))
            ->map(fn ($lease) => [
                'id' => 'lease-end-'.$lease->id,
                'type' => 'contract_expiration',
                'date' => $lease->ends_on,
                'title' => 'Fim do contrato · '.($lease->tenant_name ?: 'Locação #'.$lease->id),
                'amount' => null,
                'severity' => CarbonImmutable::parse($lease->ends_on)->lte($today->addDays(30)) ? 'warning' : 'normal',
                'resource_type' => 'lease',
                'resource_id' => $lease->id,
            ]);

        $monthly = collect(range(5, 0))->map(function (int $offset) use ($charges, $today) {
            $month = $today->subMonths($offset);
            $start = $month->startOfMonth();
            $end = $month->endOfMonth();
            $expected = (clone $charges)->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])->sum('amount');
            $received = (clone $charges)->where('status', 'paid')->whereBetween('paid_at', [$start->startOfDay(), $end->endOfDay()])->sum('amount');
            $overdue = (clone $charges)->whereIn('status', ['pending', 'processing'])->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])->sum('amount');
            return [
                'month' => $month->format('Y-m'),
                'label' => $month->locale('pt_BR')->translatedFormat('M'),
                'expected' => round((float) $expected, 2),
                'received' => round((float) $received, 2),
                'overdue' => round((float) $overdue, 2),
            ];
        })->values();

        $actions = collect();
        if ($overdueAmount > 0) $actions->push(['type' => 'collect', 'label' => 'Revisar atrasos', 'target' => 'leases', 'priority' => 1]);
        if ($expiring->isNotEmpty()) $actions->push(['type' => 'renew', 'label' => 'Revisar renovações', 'target' => 'leases', 'priority' => 2]);
        if ($awaitingDocuments > 0) $actions->push(['type' => 'documents', 'label' => 'Completar documentos', 'target' => 'leases', 'priority' => 3]);
        if ($available > 0) $actions->push(['type' => 'lease', 'label' => 'Criar nova locação', 'target' => 'leases', 'priority' => 4]);

        return response()->json([
            'summary' => [
                'properties' => $properties->count(),
                'occupied_properties' => $occupied,
                'available_properties' => $available,
                'maintenance_properties' => $maintenance,
                'active_contracts' => $active,
                'expected_this_year' => round((float) $expectedThisYear, 2),
                'received_this_year' => round((float) $receivedThisYear, 2),
                'pending_amount' => round((float) $pendingAmount, 2),
                'overdue_amount' => round((float) $overdueAmount, 2),
            ],
            'health' => [
                'score' => $healthScore,
                'level' => $healthScore >= 85 ? 'excellent' : ($healthScore >= 65 ? 'attention' : 'critical'),
                'issues' => $issues->values(),
            ],
            'cash_flow' => $cashFlow,
            'calendar' => $calendarCharges->concat($calendarContracts)->sortBy('date')->values(),
            'series' => ['monthly_cash_flow' => $monthly],
            'occupancy' => [
                'total' => $properties->count(),
                'occupied' => $occupied,
                'available' => $available,
                'maintenance' => $maintenance,
            ],
            'documents' => [
                'pending_count' => $awaitingDocuments,
                'leases' => $leases->whereIn('status', ['draft', 'awaiting_documents'])->values()->map(fn ($lease) => [
                    'lease_id' => $lease->id,
                    'tenant_name' => $lease->tenant_name,
                    'status' => $lease->status,
                ]),
            ],
            'actions' => $actions->sortBy('priority')->values(),
        ]);
    }
}
