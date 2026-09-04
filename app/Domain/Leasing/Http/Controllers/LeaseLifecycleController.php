<?php

namespace App\Domain\Leasing\Http\Controllers;

use App\Domain\Leasing\Services\LeaseLifecycleService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LeaseLifecycleController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly LeaseLifecycleService $lifecycle,
    ) {}

    public function index(Request $request)
    {
        $userId = (int) $request->user()->id;
        $email = (string) $request->user()->email;
        $appId = $this->context->id();

        $leases = DB::table('leases as l')
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

        $vigency = trim((string) $request->query('vigency', ''));
        if ($vigency !== '') {
            $leases = $leases->filter(fn (array $lease) => $lease['vigency_status'] === $vigency)->values();
        }

        $endingWithin = max(0, min(3650, (int) $request->query('ending_within', 0)));
        if ($endingWithin > 0) {
            $leases = $leases->filter(fn (array $lease) => $lease['days_until_end'] !== null && $lease['days_until_end'] <= $endingWithin)->values();
        }

        $search = mb_strtolower(trim((string) $request->query('q', '')));
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

        $allAccessibleLeases = DB::table('leases as l')
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

        return response()->json([
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
        ]);
    }

    public function propertyTimeline(Request $request, int $propertyId)
    {
        $property = $this->assertPropertyOwner($request, $propertyId);
        $appId = $this->context->id();

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
            if (! empty($lease['starts_on'])) $events->push(['type' => 'lease_start', 'date' => $lease['starts_on'], 'lease_id' => $lease['id'], 'title' => 'Início da locação']);
            if (! empty($lease['ends_on'])) $events->push(['type' => 'lease_end', 'date' => $lease['ends_on'], 'lease_id' => $lease['id'], 'title' => 'Fim previsto da locação']);
        }
        foreach ($inspections as $inspection) {
            $events->push(['type' => 'inspection', 'date' => $inspection->inspected_at ?? $inspection->created_at, 'inspection_id' => $inspection->id, 'title' => 'Vistoria '.($inspection->type ?? '')]);
        }
        foreach ($signatures as $signature) {
            $events->push(['type' => 'signature', 'date' => $signature->signed_at, 'lease_id' => $signature->lease_id, 'title' => ($signature->party === 'landlord' ? 'Locador' : 'Inquilino').' assinou o contrato']);
        }

        return response()->json([
            'property' => array_merge((array) $property, $this->lifecycle->effectivePropertyState($property, $leases)),
            'leases' => $leases,
            'inspections' => $inspections,
            'signatures' => $signatures,
            'charge_summary' => $charges,
            'events' => $events->sortByDesc('date')->values(),
        ]);
    }

    public function renew(Request $request, int $leaseId)
    {
        $lease = $this->assertLeaseManager($request, $leaseId);
        abort_unless(in_array($lease->status, ['active', 'ended'], true), 422, 'A renovação só pode partir de uma locação ativa ou encerrada.');

        $data = $request->validate([
            'starts_on' => 'required|date',
            'ends_on' => 'required|date|after:starts_on',
            'rent_amount' => 'nullable|numeric|min:0.01',
            'due_day' => 'nullable|integer|min:1|max:31',
            'adjustment_index' => 'nullable|string|max:40',
            'adjustment_frequency_months' => 'nullable|integer|min:1|max:120',
        ]);

        $currentEnd = CarbonImmutable::parse($lease->ends_on)->startOfDay();
        $renewalStart = CarbonImmutable::parse($data['starts_on'])->startOfDay();
        abort_if($renewalStart->lte($currentEnd), 422, 'A renovação deve começar depois do término do contrato atual.');

        $this->assertNoOverlap((int) $lease->property_id, $data['starts_on'], $data['ends_on'], $leaseId);

        $oldMetadata = $this->decode($lease->metadata);
        $newMetadata = [
            'renewed_from_lease_id' => $lease->id,
            'renewed_from_public_id' => $lease->public_id,
            'created_via' => 'lease_renewal',
        ];

        $newId = DB::transaction(function () use ($request, $lease, $data, $oldMetadata, $newMetadata) {
            $id = DB::table('leases')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'app_id' => $this->context->id(),
                'property_id' => $lease->property_id,
                'landlord_user_id' => $lease->landlord_user_id,
                'tenant_user_id' => $lease->tenant_user_id,
                'tenant_name' => $lease->tenant_name,
                'tenant_email' => $lease->tenant_email,
                'tenant_phone' => $lease->tenant_phone,
                'tenant_tax_id' => $lease->tenant_tax_id,
                'purpose' => $lease->purpose,
                'status' => 'draft',
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'],
                'rent_amount' => $data['rent_amount'] ?? $lease->rent_amount,
                'due_day' => $data['due_day'] ?? $lease->due_day,
                'deposit_months' => $lease->deposit_months,
                'deposit_amount' => $lease->deposit_amount,
                'guarantee_type' => $lease->guarantee_type,
                'adjustment_index' => $data['adjustment_index'] ?? $lease->adjustment_index,
                'adjustment_frequency_months' => $data['adjustment_frequency_months'] ?? $lease->adjustment_frequency_months,
                'clauses' => $lease->clauses,
                'included_expenses' => $lease->included_expenses,
                'tenant_expenses' => $lease->tenant_expenses,
                'metadata' => json_encode($newMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $oldMetadata['renewal_lease_id'] = $id;
            DB::table('leases')->where('app_id', $this->context->id())->where('id', $lease->id)->update([
                'metadata' => json_encode($oldMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);

            return $id;
        });

        $created = DB::table('leases')->where('app_id', $this->context->id())->where('id', $newId)->first();
        return response()->json(['lease' => $this->augmentLease($created)], 201);
    }

    public function terminate(Request $request, int $leaseId)
    {
        $lease = $this->assertLeaseManager($request, $leaseId);
        abort_if(in_array($lease->status, ['ended', 'cancelled'], true), 422, 'Esta locação já está encerrada.');

        $data = $request->validate([
            'effective_on' => 'nullable|date|before_or_equal:today',
            'reason' => 'nullable|string|max:1000',
        ]);
        $effectiveOn = CarbonImmutable::parse($data['effective_on'] ?? today())->startOfDay();
        $startsOn = CarbonImmutable::parse($lease->starts_on)->startOfDay();
        abort_if($effectiveOn->lt($startsOn), 422, 'A data de encerramento não pode ser anterior ao início da locação.');

        $metadata = $this->decode($lease->metadata);
        $metadata['termination'] = [
            'effective_on' => $effectiveOn->toDateString(),
            'reason' => $data['reason'] ?? null,
            'recorded_by_user_id' => (int) $request->user()->id,
            'recorded_at' => now()->toIso8601String(),
        ];

        DB::transaction(function () use ($lease, $effectiveOn, $metadata) {
            DB::table('leases')->where('app_id', $this->context->id())->where('id', $lease->id)->update([
                'status' => 'ended',
                'ends_on' => $effectiveOn->toDateString(),
                'ended_at' => now(),
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);

            $otherCurrentLease = DB::table('leases')
                ->where('app_id', $this->context->id())
                ->where('property_id', $lease->property_id)
                ->where('id', '!=', $lease->id)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->whereDate('starts_on', '<=', today())
                ->whereDate('ends_on', '>=', today())
                ->exists();

            if (! $otherCurrentLease) {
                DB::table('properties')->where('app_id', $this->context->id())->where('id', $lease->property_id)
                    ->whereNotIn('status', ['maintenance', 'inactive'])
                    ->update(['status' => 'available', 'updated_at' => now()]);
            }
        });

        $updated = DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->first();
        return response()->json(['lease' => $this->augmentLease($updated)]);
    }

    private function augmentLease(object $lease): array
    {
        $data = (array) $lease;
        foreach (['clauses', 'included_expenses', 'tenant_expenses', 'metadata'] as $column) {
            if (array_key_exists($column, $data)) $data[$column] = $this->decode($data[$column]);
        }

        return array_merge($data, $this->lifecycle->evaluate($lease));
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

    private function assertPropertyOwner(Request $request, int $propertyId): object
    {
        $property = DB::table('properties')->where('app_id', $this->context->id())->where('id', $propertyId)->whereNull('deleted_at')->first();
        abort_unless($property, 404, 'Imóvel não encontrado.');
        abort_unless((int) $property->owner_user_id === (int) $request->user()->id, 403);
        return $property;
    }

    private function assertLeaseManager(Request $request, int $leaseId): object
    {
        $lease = DB::table('leases')->where('app_id', $this->context->id())->where('id', $leaseId)->whereNull('deleted_at')->first();
        abort_unless($lease, 404, 'Locação não encontrada.');
        abort_unless((int) $lease->landlord_user_id === (int) $request->user()->id, 403);
        return $lease;
    }

    private function assertNoOverlap(int $propertyId, string $startsOn, string $endsOn, ?int $exceptLeaseId = null): void
    {
        $query = DB::table('leases')
            ->where('app_id', $this->context->id())
            ->where('property_id', $propertyId)
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['ended', 'cancelled'])
            ->whereDate('starts_on', '<=', $endsOn)
            ->whereDate('ends_on', '>=', $startsOn);
        if ($exceptLeaseId) $query->where('id', '!=', $exceptLeaseId);

        abort_if($query->exists(), 422, 'Já existe uma locação com período sobreposto para este imóvel.');
    }

    private function decode(mixed $value): array
    {
        if (is_array($value)) return $value;
        if ($value === null || $value === '') return [];
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
