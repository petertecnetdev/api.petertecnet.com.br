<?php

namespace App\Domain\Leasing\Services;

use App\Support\ApplicationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

final class LeaseReadService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly LeaseLifecycleService $lifecycle,
    ) {}

    public function properties(int $userId): Collection
    {
        $appId = $this->context->id();

        $properties = DB::table('properties')
            ->where('app_id', $appId)
            ->where('owner_user_id', $userId)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->get();

        $propertyIds = $properties->pluck('id');
        $leases = $propertyIds->isEmpty() ? collect() : DB::table('leases')
            ->where('app_id', $appId)
            ->whereIn('property_id', $propertyIds)
            ->whereNull('deleted_at')
            ->get();

        // Agrupa uma única vez. Cada imóvel faz então lookup O(1) em vez de
        // percorrer novamente toda a coleção de locações.
        $leasesByProperty = $leases->groupBy(fn ($lease) => (int) $lease->property_id);

        return $properties->map(function ($property) use ($leasesByProperty) {
            $related = $leasesByProperty->get((int) $property->id, collect())->values();
            $state = $this->lifecycle->effectivePropertyState($property, $related);
            $data = $this->decodeJsonColumns((array) $property, ['metadata']);
            $data['stored_status'] = $data['status'] ?? 'available';
            $data['status'] = $state['effective_status'];

            return array_merge($data, $state);
        })->values();
    }

    public function leases(int $userId, string $email, ?string $role): Collection
    {
        $email = mb_strtolower(trim($email));

        $rows = DB::table('leases as l')
            ->join('properties as p', function ($join) {
                $join->on('p.id', '=', 'l.property_id')->on('p.app_id', '=', 'l.app_id');
            })
            ->where('l.app_id', $this->context->id())
            ->whereNull('l.deleted_at')
            ->where(function ($query) use ($userId, $email, $role) {
                if ($role === 'landlord') {
                    $query->where('l.landlord_user_id', $userId);
                    return;
                }

                if ($role === 'tenant') {
                    $query->where('l.tenant_user_id', $userId);
                    if ($email !== '') {
                        $query->orWhere(function ($pending) use ($email) {
                            $pending->whereNull('l.tenant_user_id')
                                ->whereRaw('LOWER(l.tenant_email) = ?', [$email]);
                        });
                    }
                    return;
                }

                $query->where('l.landlord_user_id', $userId)
                    ->orWhere('l.tenant_user_id', $userId);
                if ($email !== '') {
                    $query->orWhereRaw('LOWER(l.tenant_email) = ?', [$email]);
                }
            })
            ->select('l.*', 'p.name as property_name', 'p.city as property_city', 'p.state as property_state')
            ->orderByDesc('l.id')
            ->get();

        return $rows->map(function ($row) {
            $data = $this->decodeJsonColumns((array) $row, ['clauses', 'included_expenses', 'tenant_expenses', 'metadata']);
            return array_merge($data, $this->lifecycle->evaluate($row));
        })->values();
    }

    public function normalizeContextRole(?string $role): ?string
    {
        $role = strtolower(trim((string) $role));
        return in_array($role, ['landlord', 'tenant'], true) ? $role : null;
    }

    private function decodeJsonColumns(array $data, array $columns): array
    {
        foreach ($columns as $column) {
            if (! array_key_exists($column, $data)) continue;
            if (is_array($data[$column])) continue;
            if ($data[$column] === null || $data[$column] === '') {
                $data[$column] = $column === 'metadata' ? null : [];
                continue;
            }
            $decoded = json_decode((string) $data[$column], true);
            $data[$column] = is_array($decoded) ? $decoded : ($column === 'metadata' ? null : []);
        }

        return $data;
    }
}
