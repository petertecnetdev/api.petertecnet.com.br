<?php

namespace App\Domain\Leasing\Services;

use Illuminate\Support\Facades\DB;

final class LeaseLifecycleReadService
{
    public function __construct(private readonly LeaseLifecycleService $lifecycle) {}

    public function workspace(int $appId, int $userId, string $email, array $filters = []): array
    {
        $allAccessibleLeases = $this->accessibleLeases($appId, $userId, $email);
        $leases = $allAccessibleLeases;

        $vigency = trim((string) ($filters['vigency'] ?? ''));
        if ($vigency !== '') {
            $leases = $leases->filter(fn (array $lease) => $lease['vigency_status'] === $vigency)->values();
        }

        $endingWithin = max(0, min(3650, (int) ($filters['ending_within'] ?? 0)));
        if ($endingWithin > 0) {
            $leases = $leases->filter(fn (array $lease) => $lease['days_until_end'] !== null && $lease['days_until_end'] <= $endingWithin)->values();
        }

        $search = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        if ($search !== '') {
            $leases = $leases->filter(function (array $lease) use ($search) {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $lease['property_name'] ?? null,
                    $lease['tenant_name'] ?? null,
                    $lease['tenant_email'] ?? null,
                    $lease['property_city'] ?? null,
                    $lease['property_state'] ?? null,
                ])));

                return str_contains($haystack, $search);
            })->values();
        }

        $properties = DB::table('properties')
            ->where('app_id', $appId)
            ->where('owner_user_id', $userId)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->get()
            ->map(function ($property) use ($allAccessibleLeases) {
                $related = $allAccessibleLeases->where('property_id', $property->id)->values();

                return array_merge((array) $property, $this->lifecycle->effectivePropertyState($property, $related));
            });

        $alerts = $allAccessibleLeases
            ->filter(fn (array $lease) => $lease['requires_attention'] || in_array($lease['vigency_status'], ['expired', 'awaiting_signature'], true))
            ->map(fn (array $lease) => $this->alertPayload($lease))
            ->sortBy(fn (array $alert) => $this->attentionWeight($alert['level']))
            ->values();

        return [
            'summary' => [
                'in_force' => $allAccessibleLeases->where('vigency_status', 'in_force')->count(),
                'future' => $allAccessibleLeases->where('vigency_status', 'future')->count(),
                'awaiting_signature' => $allAccessibleLeases->where('vigency_status', 'awaiting_signature')->count(),
                'ending_within_90_days' => $allAccessibleLeases->filter(fn (array $lease) => $lease['is_in_force'] && $lease['days_until_end'] !== null && $lease['days_until_end'] <= 90)->count(),
                'ending_within_30_days' => $allAccessibleLeases->filter(fn (array $lease) => $lease['is_in_force'] && $lease['days_until_end'] !== null && $lease['days_until_end'] <= 30)->count(),
                'expired_needing_closure' => $allAccessibleLeases->filter(fn (array $lease) => $lease['status'] === 'active' && $lease['vigency_status'] === 'expired')->count(),
            ],
            'alerts' => $alerts,
            'properties' => $properties,
            'leases' => $leases,
            'filters' => [
                'vigency_statuses' => ['in_force', 'future', 'awaiting_signature', 'draft', 'expired', 'ended', 'cancelled'],
                'ending_within_options' => [7, 30, 60, 90, 180],
            ],
        ];
    }

    public function propertyTimeline(int $appId, object $property): array
    {
        $propertyId = (int) $property->id;
        $leases = DB::table('leases')
            ->where('app_id', $appId)
            ->where('property_id', $propertyId)
            ->whereNull('deleted_at')
            ->orderByDesc('starts_on')
            ->get()
            ->map(fn ($lease) => $this->augmentLease($lease));

        $leaseIds = $leases->pluck('id');
        $inspections = DB::table('property_inspections')
            ->where('app_id', $appId)
            ->where('property_id', $propertyId)
            ->orderByDesc('inspected_at')
            ->get();

        $signatures = $leaseIds->isEmpty() ? collect() : DB::table('lease_signatures')
            ->where('app_id', $appId)
            ->whereIn('lease_id', $leaseIds)
            ->orderByDesc('signed_at')
            ->get();

        $charges = $leaseIds->isEmpty() ? collect() : DB::table('lease_charges')
            ->where('app_id', $appId)
            ->whereIn('lease_id', $leaseIds)
            ->selectRaw('lease_id, status, COUNT(*) as total, SUM(amount) as amount')
            ->groupBy('lease_id', 'status')
            ->get();

        $events = collect();
        foreach ($leases as $lease) {
            $events->push([
                'type' => 'lease_created',
                'date' => $lease['created_at'] ?? $lease['starts_on'],
                'lease_id' => $lease['id'],
                'title' => 'Locação criada para '.$lease['tenant_name'],
                'status' => $lease['vigency_status'],
            ]);
            if (! empty($lease['starts_on'])) {
                $events->push(['type' => 'lease_start', 'date' => $lease['starts_on'], 'lease_id' => $lease['id'], 'title' => 'Início da locação']);
            }
            if (! empty($lease['ends_on'])) {
                $events->push(['type' => 'lease_end', 'date' => $lease['ends_on'], 'lease_id' => $lease['id'], 'title' => 'Fim previsto da locação']);
            }
        }
        foreach ($inspections as $inspection) {
            $events->push(['type' => 'inspection', 'date' => $inspection->inspected_at ?? $inspection->created_at, 'inspection_id' => $inspection->id, 'title' => 'Vistoria '.($inspection->type ?? '')]);
        }
        foreach ($signatures as $signature) {
            $events->push(['type' => 'signature', 'date' => $signature->signed_at, 'lease_id' => $signature->lease_id, 'title' => ($signature->party === 'landlord' ? 'Locador' : 'Inquilino').' assinou o contrato']);
        }

        return [
            'property' => array_merge((array) $property, $this->lifecycle->effectivePropertyState($property, $leases)),
            'leases' => $leases,
            'inspections' => $inspections,
            'signatures' => $signatures,
            'charge_summary' => $charges,
            'events' => $events->sortByDesc('date')->values(),
        ];
    }

    public function augmentLease(object $lease): array
    {
        $data = (array) $lease;
        foreach (['clauses', 'included_expenses', 'tenant_expenses', 'metadata'] as $column) {
            if (array_key_exists($column, $data)) {
                $data[$column] = $this->decode($data[$column]);
            }
        }

        return array_merge($data, $this->lifecycle->evaluate($lease));
    }

    private function accessibleLeases(int $appId, int $userId, string $email)
    {
        return DB::table('leases as l')
            ->join('properties as p', function ($join) {
                $join->on('p.id', '=', 'l.property_id')->on('p.app_id', '=', 'l.app_id');
            })
            ->where('l.app_id', $appId)
            ->whereNull('l.deleted_at')
            ->where(function ($query) use ($userId, $email) {
                $query->where('l.landlord_user_id', $userId)
                    ->orWhere('l.tenant_user_id', $userId)
                    ->orWhere('l.tenant_email', $email);
            })
            ->select('l.*', 'p.name as property_name', 'p.city as property_city', 'p.state as property_state')
            ->orderByDesc('l.id')
            ->get()
            ->map(fn ($lease) => $this->augmentLease($lease));
    }

    private function alertPayload(array $lease): array
    {
        $days = $lease['days_until_end'];
        $title = match (true) {
            $lease['status'] === 'active' && $lease['vigency_status'] === 'expired' => 'Contrato expirado precisa ser encerrado',
            $lease['vigency_status'] === 'awaiting_signature' => 'Contrato aguardando assinatura',
            $lease['renewal_due'] && $days !== null && $days <= 7 => 'Contrato vence nesta semana',
            $lease['renewal_due'] && $days !== null && $days <= 30 => 'Contrato vence em até 30 dias',
            $lease['renewal_due'] => 'Contrato entra na janela de renovação',
            default => $lease['vigency_label'],
        };

        return [
            'lease_id' => $lease['id'],
            'property_id' => $lease['property_id'],
            'property_name' => $lease['property_name'] ?? null,
            'tenant_name' => $lease['tenant_name'] ?? null,
            'title' => $title,
            'level' => $lease['attention_level'],
            'vigency_status' => $lease['vigency_status'],
            'days_until_end' => $lease['days_until_end'],
            'ends_on' => $lease['ends_on'],
        ];
    }

    private function attentionWeight(string $level): int
    {
        return match ($level) {
            'critical' => 0,
            'high' => 1,
            'medium' => 2,
            default => 3,
        };
    }

    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return [];
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
