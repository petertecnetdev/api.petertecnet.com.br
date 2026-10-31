<?php

namespace App\Domain\Leasing\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LeaseRentScheduleService
{
    private const RENT_MONTH_COLUMN = 'rent_schedule_month';

    /**
     * Gera o cronograma uma única vez por locação/mês.
     *
     * O bloqueio da linha da locação serializa chamadas concorrentes do
     * cronograma. A chave mensal gerada e o índice único no banco permanecem
     * como última barreira para qualquer outro caminho de escrita.
     */
    public function ensureForLease(int $appId, int $leaseId): int
    {
        return DB::transaction(function () use ($appId, $leaseId): int {
            $lease = DB::table('leases')
                ->where('app_id', $appId)
                ->where('id', $leaseId)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->firstOrFail();

            $cursor = CarbonImmutable::parse($lease->starts_on)->startOfMonth();
            $end = CarbonImmutable::parse($lease->ends_on)->startOfMonth();
            $created = 0;

            while ($cursor <= $end) {
                $reference = $cursor->toDateString();
                $rentMonth = $cursor->startOfMonth()->toDateString();
                $dueDay = min(max(1, (int) $lease->due_day), $cursor->daysInMonth);
                $due = $cursor->setDay($dueDay);

                $exists = DB::table('lease_charges')
                    ->where('app_id', $appId)
                    ->where('lease_id', $leaseId)
                    ->where('type', 'rent')
                    ->where(self::RENT_MONTH_COLUMN, $rentMonth)
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

            if ((float) $lease->deposit_amount > 0) {
                $depositExists = DB::table('lease_charges')
                    ->where('app_id', $appId)
                    ->where('lease_id', $leaseId)
                    ->where('type', 'deposit')
                    ->exists();

                if (! $depositExists) {
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
            }

            return $created;
        }, 3);
    }
}
