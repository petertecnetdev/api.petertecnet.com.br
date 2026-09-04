<?php

namespace App\Domain\Leasing\Services;

use Illuminate\Support\Facades\DB;

final class LeaseLifecycleService
{
    public function update(int $applicationId, object $lease, array $data): void
    {
        DB::transaction(function () use ($applicationId, $lease, $data) {
            DB::table('leases')
                ->where('app_id', $applicationId)
                ->where('id', $lease->id)
                ->update($data);

            if (isset($data['status']) && in_array($data['status'], ['ended', 'cancelled'], true)) {
                DB::table('properties')
                    ->where('app_id', $applicationId)
                    ->where('id', $lease->property_id)
                    ->update(['status' => 'available', 'updated_at' => now()]);
            }
        }, 3);
    }

    public function activate(int $applicationId, object $lease): void
    {
        DB::transaction(function () use ($applicationId, $lease) {
            DB::table('leases')
                ->where('app_id', $applicationId)
                ->where('id', $lease->id)
                ->update([
                    'status' => 'active',
                    'activated_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('properties')
                ->where('app_id', $applicationId)
                ->where('id', $lease->property_id)
                ->update(['status' => 'occupied', 'updated_at' => now()]);
        }, 3);
    }

    public function publishContract(int $applicationId, int $leaseId, string $contract, int $version): void
    {
        DB::transaction(function () use ($applicationId, $leaseId, $contract, $version) {
            DB::table('leases')
                ->where('app_id', $applicationId)
                ->where('id', $leaseId)
                ->update([
                    'contract_text' => $contract,
                    'contract_generated_at' => now(),
                    'contract_version' => $version,
                    'status' => 'awaiting_signature',
                    'updated_at' => now(),
                ]);

            DB::table('lease_signatures')
                ->where('app_id', $applicationId)
                ->where('lease_id', $leaseId)
                ->delete();
        }, 3);
    }
}
