<?php

namespace App\Domain\PropertyManagement\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaseAgreementService
{
    public function validateGuarantee(array $data): array
    {
        $type = $data['guarantee_type'] ?? 'none';
        $multiplier = (int) ($data['security_rent_multiplier'] ?? 0);

        if ($type === 'cash_deposit' && ($multiplier < 1 || $multiplier > 3)) {
            throw ValidationException::withMessages([
                'security_rent_multiplier' => 'A caução em dinheiro deve corresponder a 1, 2 ou no máximo 3 meses de aluguel.',
            ]);
        }

        if ($type !== 'cash_deposit') {
            $multiplier = 0;
        }

        $data['security_rent_multiplier'] = $multiplier;
        $data['security_amount'] = $type === 'cash_deposit'
            ? round((float) $data['rent_amount'] * $multiplier, 2)
            : 0;

        return $data;
    }

    public function syncDefaultObligations(int $agreementId, array $includedCharges): void
    {
        foreach (['water', 'electricity', 'iptu', 'internet', 'condominium'] as $type) {
            DB::table('lease_obligations')->updateOrInsert(
                ['agreement_id' => $agreementId, 'type' => $type],
                [
                    'responsible_party' => in_array($type, $includedCharges, true) ? 'landlord' : 'tenant',
                    'included_in_rent' => in_array($type, $includedCharges, true),
                    'active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    public function generateRentReceivables(object $agreement): int
    {
        $start = CarbonImmutable::parse($agreement->start_date)->startOfMonth();
        $end = CarbonImmutable::parse($agreement->end_date)->startOfMonth();
        $count = 0;

        for ($period = $start; $period->lessThanOrEqualTo($end); $period = $period->addMonth()) {
            $dueDay = min((int) $agreement->due_day, $period->daysInMonth);
            $dueDate = $period->setDay($dueDay);

            DB::table('lease_receivables')->updateOrInsert(
                [
                    'agreement_id' => $agreement->id,
                    'type' => 'rent',
                    'period_start' => $period->toDateString(),
                ],
                [
                    'application_slug' => $agreement->application_slug,
                    'description' => 'Aluguel ' . $period->format('m/Y'),
                    'due_date' => $dueDate->toDateString(),
                    'amount' => $agreement->rent_amount,
                    'status' => $dueDate->isPast() ? 'overdue' : 'pending',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $count++;
        }

        return $count;
    }
}
