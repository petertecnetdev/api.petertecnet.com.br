<?php

namespace App\Domain\Leasing\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LeaseLifecycleCommandService
{
    public function renew(int $appId, object $lease, array $data): object
    {
        abort_unless(in_array($lease->status, ['active', 'ended'], true), 422, 'A renovação só pode partir de uma locação ativa ou encerrada.');

        $currentEnd = CarbonImmutable::parse($lease->ends_on)->startOfDay();
        $renewalStart = CarbonImmutable::parse($data['starts_on'])->startOfDay();
        abort_if($renewalStart->lte($currentEnd), 422, 'A renovação deve começar depois do término do contrato atual.');

        $this->assertNoOverlap($appId, (int) $lease->property_id, $data['starts_on'], $data['ends_on'], (int) $lease->id);

        $oldMetadata = $this->decode($lease->metadata);
        $newMetadata = [
            'renewed_from_lease_id' => $lease->id,
            'renewed_from_public_id' => $lease->public_id,
            'created_via' => 'lease_renewal',
        ];

        $newId = DB::transaction(function () use ($appId, $lease, $data, $oldMetadata, $newMetadata) {
            $id = DB::table('leases')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'app_id' => $appId,
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
                'metadata' => $this->json($newMetadata),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $oldMetadata['renewal_lease_id'] = $id;
            DB::table('leases')->where('app_id', $appId)->where('id', $lease->id)->update([
                'metadata' => $this->json($oldMetadata),
                'updated_at' => now(),
            ]);

            return $id;
        });

        return DB::table('leases')->where('app_id', $appId)->where('id', $newId)->firstOrFail();
    }

    public function terminate(int $appId, object $lease, array $data, int $actorUserId): object
    {
        abort_if(in_array($lease->status, ['ended', 'cancelled'], true), 422, 'Esta locação já está encerrada.');

        $effectiveOn = CarbonImmutable::parse($data['effective_on'] ?? today())->startOfDay();
        $startsOn = CarbonImmutable::parse($lease->starts_on)->startOfDay();
        abort_if($effectiveOn->lt($startsOn), 422, 'A data de encerramento não pode ser anterior ao início da locação.');

        $metadata = $this->decode($lease->metadata);
        $metadata['termination'] = [
            'effective_on' => $effectiveOn->toDateString(),
            'reason' => $data['reason'] ?? null,
            'recorded_by_user_id' => $actorUserId,
            'recorded_at' => now()->toIso8601String(),
        ];

        DB::transaction(function () use ($appId, $lease, $effectiveOn, $metadata) {
            DB::table('leases')->where('app_id', $appId)->where('id', $lease->id)->update([
                'status' => 'ended',
                'ends_on' => $effectiveOn->toDateString(),
                'ended_at' => now(),
                'metadata' => $this->json($metadata),
                'updated_at' => now(),
            ]);

            $otherCurrentLease = DB::table('leases')
                ->where('app_id', $appId)
                ->where('property_id', $lease->property_id)
                ->where('id', '!=', $lease->id)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->whereDate('starts_on', '<=', today())
                ->whereDate('ends_on', '>=', today())
                ->exists();

            if (! $otherCurrentLease) {
                DB::table('properties')
                    ->where('app_id', $appId)
                    ->where('id', $lease->property_id)
                    ->whereNotIn('status', ['maintenance', 'inactive'])
                    ->update(['status' => 'available', 'updated_at' => now()]);
            }
        });

        return DB::table('leases')->where('app_id', $appId)->where('id', $lease->id)->firstOrFail();
    }

    private function assertNoOverlap(int $appId, int $propertyId, string $startsOn, string $endsOn, ?int $exceptLeaseId = null): void
    {
        $query = DB::table('leases')
            ->where('app_id', $appId)
            ->where('property_id', $propertyId)
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['ended', 'cancelled'])
            ->whereDate('starts_on', '<=', $endsOn)
            ->whereDate('ends_on', '>=', $startsOn);

        if ($exceptLeaseId) {
            $query->where('id', '!=', $exceptLeaseId);
        }

        abort_if($query->exists(), 422, 'Já existe uma locação com período sobreposto para este imóvel.');
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

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
