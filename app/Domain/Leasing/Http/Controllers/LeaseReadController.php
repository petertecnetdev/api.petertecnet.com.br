<?php

namespace App\Domain\Leasing\Http\Controllers;

use App\Domain\Assets\Services\AssetAccessService;
use App\Domain\Leasing\Services\LeaseLifecycleService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class LeaseReadController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly LeaseLifecycleService $lifecycle,
        private readonly AssetAccessService $assetAccess,
    ) {}

    public function properties(Request $request)
    {
        $appId = $this->context->id();
        $propertyIds = $this->assetAccess->accessibleAssetIds($request, 'property');
        if ($propertyIds === []) return response()->json([]);

        $properties = DB::table('properties')
            ->where('app_id', $appId)
            ->whereIn('id', $propertyIds)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->get();

        $ids = $properties->pluck('id');
        $leases = $ids->isEmpty() ? collect() : DB::table('leases')
            ->where('app_id', $appId)
            ->whereIn('property_id', $ids)
            ->whereNull('deleted_at')
            ->get();

        return response()->json($properties->map(function ($property) use ($leases, $request) {
            $related = $leases->where('property_id', $property->id)->values();
            $state = $this->lifecycle->effectivePropertyState($property, $related);
            $data = $this->decodeJsonColumns((array) $property, ['metadata']);
            $data['stored_status'] = $data['status'] ?? 'available';
            $data['status'] = $state['effective_status'];
            $data['access_permissions'] = $this->assetAccess->permissions($request, 'property', (int) $property->id);
            $data['is_primary_owner'] = (int) $property->owner_user_id === (int) $request->user()->id;

            return array_merge($data, $state);
        })->values());
    }

    public function leases(Request $request)
    {
        $userId = (int) $request->user()->id;
        $email = (string) $request->user()->email;
        $managedPropertyIds = collect($this->assetAccess->accessibleAssetIds($request, 'property'))
            ->filter(function ($propertyId) use ($request) {
                $permissions = $this->assetAccess->permissions($request, 'property', (int) $propertyId);
                return in_array('*', $permissions, true) || in_array('edit', $permissions, true) || in_array('financial', $permissions, true);
            })->values()->all();

        $rows = DB::table('leases as l')
            ->join('properties as p', function ($join) {
                $join->on('p.id', '=', 'l.property_id')->on('p.app_id', '=', 'l.app_id');
            })
            ->where('l.app_id', $this->context->id())
            ->whereNull('l.deleted_at')
            ->where(function ($query) use ($userId, $email, $managedPropertyIds) {
                $query->where('l.landlord_user_id', $userId)
                    ->orWhere('l.tenant_user_id', $userId)
                    ->orWhere('l.tenant_email', $email);
                if ($managedPropertyIds !== []) $query->orWhereIn('l.property_id', $managedPropertyIds);
            })
            ->select('l.*', 'p.name as property_name', 'p.city as property_city', 'p.state as property_state')
            ->orderByDesc('l.id')
            ->get();

        return response()->json($rows->map(function ($row) {
            $data = $this->decodeJsonColumns((array) $row, ['clauses', 'included_expenses', 'tenant_expenses', 'metadata']);
            return array_merge($data, $this->lifecycle->evaluate($row));
        })->values());
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
