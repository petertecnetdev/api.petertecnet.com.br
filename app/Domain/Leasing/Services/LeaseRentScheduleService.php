<?php

namespace App\Domain\Leasing\Services;

use App\Support\ApplicationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LeaseRentScheduleService
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function ensureForLease(int $leaseId): int
    {
        return DB::transaction(function () use ($leaseId): int {
            $appId = $this->context->id();
            $lease = DB::table('leases')
                ->where('app_id', $appId)
                ->where('id', $leaseId)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            abort_unless($lease, 404);

            $start = CarbonImmutable::parse($lease->starts_on);
            $cursor = $start->startOfMonth();
            $end = CarbonImmutable::parse($lease->ends_on)->startOfMonth();
            $firstMonth = $cursor->toDateString();
            $created = 0;

            while ($cursor <= $end) {
                $reference = $cursor->toDateString();
                $dueDay = max(1, min((int) $lease->due_day, $cursor->daysInMonth));
                // O primeiro aluguel vence no início efetivo da locação; os seguintes seguem o dia contratual.
                $due = $reference === $firstMonth ? $start : $cursor->setDay($dueDay);

                $exists = DB::table('lease_charges')
                    ->where('app_id', $appId)
                    ->where('lease_id', $leaseId)
                    ->where('type', 'rent')
                    ->where('rent_schedule_month', $reference)
                    ->exists();

                if (! $exists) {
                    DB::table('lease_charges')->insert([
                        'public_id' => (string) Str::uuid(),
                        'app_id' => $appId,
                        'lease_id' => $leaseId,
                        'type' => 'rent',
                        'description' => 'Aluguel '.$cursor->format('m/Y'),
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

            if ((float) $lease->deposit_amount > 0
                && ! DB::table('lease_charges')
                    ->where('app_id', $appId)
                    ->where('lease_id', $leaseId)
                    ->where('type', 'deposit')
                    ->exists()) {
                DB::table('lease_charges')->insert([
                    'public_id' => (string) Str::uuid(),
                    'app_id' => $appId,
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
        }, 3);
    }

    public static function referenceMonthForDueDate(string $dueDate): string
    {
        return CarbonImmutable::parse($dueDate)->startOfMonth()->toDateString();
    }
}
