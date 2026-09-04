<?php

namespace App\Domain\Leasing\Services;

use Illuminate\Support\Facades\DB;

final class LeaseDetailService
{
    public function payload(int $appId, int $leaseId): array
    {
        $lease = DB::table('leases')
            ->where('app_id', $appId)
            ->where('id', $leaseId)
            ->whereNull('deleted_at')
            ->firstOrFail();
        $property = DB::table('properties')
            ->where('app_id', $appId)
            ->where('id', $lease->property_id)
            ->first();

        return [
            'lease' => $this->decode($lease, ['clauses', 'included_expenses', 'tenant_expenses', 'metadata']),
            'property' => $this->decode($property, ['metadata']),
            'signatures' => DB::table('lease_signatures')->where('app_id', $appId)->where('lease_id', $leaseId)->orderBy('id')->get()->map(fn ($row) => $this->decode($row, ['metadata'])),
            'documents' => DB::table('lease_documents')->where('app_id', $appId)->where('lease_id', $leaseId)->orderByDesc('id')->get()->map(fn ($row) => $this->decode($row, ['metadata'])),
            'charges' => DB::table('lease_charges')->where('app_id', $appId)->where('lease_id', $leaseId)->orderBy('due_date')->get()->map(fn ($row) => $this->decode($row, ['metadata'])),
        ];
    }

    private function decode(?object $row, array $columns): ?object
    {
        if (! $row) {
            return null;
        }

        foreach ($columns as $column) {
            if (! property_exists($row, $column)) {
                continue;
            }
            if (is_array($row->{$column})) {
                continue;
            }
            $row->{$column} = $row->{$column} ? json_decode($row->{$column}, true) : [];
        }

        return $row;
    }
}
