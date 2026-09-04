<?php

namespace App\Domain\Leasing\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LeaseChargeScheduleService
{
    public function ensure(int $applicationId, int $leaseId): int
    {
        $lease = DB::table('leases')
            ->where('app_id', $applicationId)
            ->where('id', $leaseId)
            ->firstOrFail();

        $cursor = CarbonImmutable::parse($lease->starts_on)->startOfMonth();
        $end = CarbonImmutable::parse($lease->ends_on)->startOfMonth();
        $created = 0;

        while ($cursor <= $end) {
            $dueDay = min((int) $lease->due_day, $cursor->daysInMonth);
            $due = $cursor->setDay($dueDay);
            $reference = $cursor->toDateString();
            $exists = DB::table('lease_charges')
                ->where('app_id', $applicationId)
                ->where('lease_id', $leaseId)
                ->where('type', 'rent')
                ->whereDate('reference_date', $reference)
                ->exists();

            if (! $exists) {
                DB::table('lease_charges')->insert([
                    'public_id' => (string) Str::uuid(),
                    'app_id' => $applicationId,
                    'lease_id' => $leaseId,
                    'type' => 'rent',
                    'description' => 'Aluguel ' . $cursor->format('m/Y'),
                    'reference_date' => $reference,
                    'due_date' => $due->toDateString(),
                    'amount' => $lease->rent_amount,
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $created++;
            }

            $cursor = $cursor->addMonth();
        }

        if (
            (float) $lease->deposit_amount > 0
            && ! DB::table('lease_charges')
                ->where('app_id', $applicationId)
                ->where('lease_id', $leaseId)
                ->where('type', 'deposit')
                ->exists()
        ) {
            DB::table('lease_charges')->insert([
                'public_id' => (string) Str::uuid(),
                'app_id' => $applicationId,
                'lease_id' => $leaseId,
                'type' => 'deposit',
                'description' => 'Caução / garantia locatícia',
                'reference_date' => $lease->starts_on,
                'due_date' => $lease->starts_on,
                'amount' => $lease->deposit_amount,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $created++;
        }

        return $created;
    }
}
