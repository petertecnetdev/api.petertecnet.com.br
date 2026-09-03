<?php

namespace App\Services\Payments;

use Illuminate\Support\Collection;

class PaymentRevenueRecognitionService
{
    public const REALIZED_STATUSES = ['paid', 'approved'];
    public const OPEN_STATUSES = ['pending', 'in_process', 'authorized'];
    public const FAILED_STATUSES = ['failed', 'rejected', 'cancelled'];
    public const REVERSED_STATUSES = ['refunded', 'charged_back'];

    public function normalize(array $row): array
    {
        $status = strtolower(trim((string) ($row['status'] ?? 'pending')));
        if ($status === 'approved') {
            $status = 'paid';
        }

        $row['status'] = $status;
        $row['is_realized'] = $this->isRealizedStatus($status);
        $row['financial_at'] = $this->financialAt($row, $status);

        return $row;
    }

    public function isRealizedStatus(?string $status): bool
    {
        return in_array(strtolower((string) $status), self::REALIZED_STATUSES, true);
    }

    public function realized(Collection $rows): Collection
    {
        return $rows->filter(fn (array $row) => $this->isRealizedStatus($row['status'] ?? null))->values();
    }

    public function open(Collection $rows): Collection
    {
        return $rows->filter(fn (array $row) => in_array((string) ($row['status'] ?? ''), self::OPEN_STATUSES, true))->values();
    }

    public function failed(Collection $rows): Collection
    {
        return $rows->filter(fn (array $row) => in_array((string) ($row['status'] ?? ''), self::FAILED_STATUSES, true))->values();
    }

    public function reversed(Collection $rows): Collection
    {
        return $rows->filter(fn (array $row) => in_array((string) ($row['status'] ?? ''), self::REVERSED_STATUSES, true))->values();
    }

    public function bucket(Collection $rows): object
    {
        return (object) [
            'count' => $rows->count(),
            'amount' => $this->sum($rows, 'gross_amount'),
            'platform_fees' => $this->sum($rows, 'platform_fee'),
            'provider_fees' => $this->sum($rows, 'provider_fee'),
            'seller_net' => $this->sum($rows, 'seller_net'),
        ];
    }

    public function totals(Collection $rows): object
    {
        $realized = $this->realized($rows);
        $open = $this->open($rows);
        $reversed = $this->reversed($rows);

        return (object) [
            'transactions' => $rows->count(),
            'gross' => $this->sum($realized, 'gross_amount'),
            'platform_fees' => $this->sum($realized, 'platform_fee'),
            'provider_fees' => $this->sum($realized, 'provider_fee'),
            'seller_net' => $this->sum($realized, 'seller_net'),
            'attempted_gross' => $this->sum($rows, 'gross_amount'),
            'open_gross' => $this->sum($open, 'gross_amount'),
            'open_platform_fees' => $this->sum($open, 'platform_fee'),
            'reversed_gross' => $this->sum($reversed, 'gross_amount'),
            'reversed_platform_fees' => $this->sum($reversed, 'platform_fee'),
        ];
    }

    private function financialAt(array $row, string $status): mixed
    {
        if ($this->isRealizedStatus($status)) {
            return $row['paid_at'] ?? $row['updated_at'] ?? $row['created_at'] ?? null;
        }

        if (in_array($status, self::REVERSED_STATUSES, true)) {
            return $row['refunded_at'] ?? $row['updated_at'] ?? $row['created_at'] ?? null;
        }

        if (in_array($status, self::FAILED_STATUSES, true)) {
            return $row['failed_at'] ?? $row['updated_at'] ?? $row['created_at'] ?? null;
        }

        return $row['created_at'] ?? $row['updated_at'] ?? null;
    }

    private function sum(Collection $rows, string $field): float
    {
        return round((float) $rows->sum(fn (array $row) => (float) ($row[$field] ?? 0)), 2);
    }
}
