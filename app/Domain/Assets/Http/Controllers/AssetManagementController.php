<?php

namespace App\Domain\Assets\Http\Controllers;

use App\Domain\Assets\Services\AssetAccessService;
use App\Domain\Assets\Services\AssetIntelligenceService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AssetManagementController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AssetAccessService $access,
        private readonly AssetIntelligenceService $intelligence,
    ) {}

    public function portfolio(Request $request, string $assetType)
    {
        abort_unless($assetType === 'property', 422, 'Tipo de ativo ainda não disponível para o portfólio.');
        $ids = $this->access->accessibleAssetIds($request, $assetType);
        if ($ids === []) return response()->json(['items' => [], 'summary' => ['total' => 0]]);

        $query = DB::table('properties')
            ->where('app_id', $this->context->id())
            ->whereIn('id', $ids)
            ->whereNull('deleted_at');

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where('name', 'like', $like)
                    ->orWhere('street', 'like', $like)
                    ->orWhere('neighborhood', 'like', $like)
                    ->orWhere('city', 'like', $like)
                    ->orWhere('postal_code', 'like', $like);
            });
        }
        foreach (['status', 'use_type', 'city', 'neighborhood'] as $field) {
            $value = trim((string) $request->query($field, ''));
            if ($value !== '') $query->where($field, $value);
        }
        if ($request->filled('min_rent')) $query->where('default_rent_amount', '>=', (float) $request->query('min_rent'));
        if ($request->filled('max_rent')) $query->where('default_rent_amount', '<=', (float) $request->query('max_rent'));
        if ($request->filled('tag')) {
            $tag = trim((string) $request->query('tag'));
            $query->whereExists(function ($sub) use ($tag) {
                $sub->selectRaw('1')->from('asset_tags as at')
                    ->whereColumn('at.asset_id', 'properties.id')
                    ->where('at.app_id', $this->context->id())
                    ->where('at.asset_type', 'property')
                    ->where('at.tag', $tag);
            });
        }

        $rows = $query->orderByDesc('id')->limit(150)->get();
        $items = $rows->map(function ($property) use ($request) {
            $profile = $this->intelligence->profile('property', (int) $property->id);
            $financial = $this->intelligence->financial('property', (int) $property->id);
            $alerts = $this->intelligence->alerts('property', (int) $property->id);
            $health = $this->intelligence->health('property', (int) $property->id, $financial);
            $tags = DB::table('asset_tags')->where('app_id', $this->context->id())->where('asset_type', 'property')->where('asset_id', $property->id)->orderBy('tag')->pluck('tag')->all();
            $openMaintenance = DB::table('lease_operations')->where('app_id', $this->context->id())->where('property_id', $property->id)->where('type', 'maintenance')->whereIn('status', ['open', 'in_progress', 'waiting'])->whereNull('deleted_at')->count();
            return array_merge((array) $property, [
                'profile' => $profile,
                'tags' => $tags,
                'health' => $health,
                'alert_count' => count($alerts),
                'critical_alert_count' => collect($alerts)->where('severity', 'critical')->count(),
                'overdue_amount' => $financial['summary']['overdue'] ?? 0,
                'net_result_12m' => $financial['summary']['net_result_12m'] ?? 0,
                'gross_yield_12m' => $financial['summary']['gross_yield_12m'] ?? null,
                'open_maintenance' => $openMaintenance,
                'permissions' => $this->access->permissions($request, 'property', (int) $property->id),
            ]);
        });

        if ($request->boolean('has_overdue')) $items = $items->filter(fn ($item) => (float) $item['overdue_amount'] > 0);
        if ($request->boolean('has_maintenance')) $items = $items->filter(fn ($item) => (int) $item['open_maintenance'] > 0);
        if ($request->filled('min_health')) $items = $items->filter(fn ($item) => (int) ($item['health']['score'] ?? 0) >= (int) $request->query('min_health'));
        if ($request->filled('max_health')) $items = $items->filter(fn ($item) => (int) ($item['health']['score'] ?? 100) <= (int) $request->query('max_health'));

        $items = $items->values();
        return response()->json([
            'items' => $items,
            'summary' => [
                'total' => $items->count(),
                'critical' => $items->where('critical_alert_count', '>', 0)->count(),
                'with_overdue' => $items->filter(fn ($item) => (float) $item['overdue_amount'] > 0)->count(),
                'with_maintenance' => $items->filter(fn ($item) => (int) $item['open_maintenance'] > 0)->count(),
                'net_result_12m' => round((float) $items->sum('net_result_12m'), 2),
            ],
        ]);
    }

    public function profile(Request $request, string $assetType, int $assetId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'view');
        return response()->json(['profile' => $this->intelligence->profile($assetType, $assetId)]);
    }

    public function updateProfile(Request $request, string $assetType, int $assetId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'edit');
        $data = $request->validate([
            'acquisition_value' => 'sometimes|nullable|numeric|min:0|max:999999999999',
            'market_value' => 'sometimes|nullable|numeric|min:0|max:999999999999',
            'acquired_on' => 'sometimes|nullable|date',
            'insurance_expires_on' => 'sometimes|nullable|date',
            'document_expires_on' => 'sometimes|nullable|date',
            'registry_reference' => 'sometimes|nullable|string|max:190',
            'metadata' => 'sometimes|nullable|array',
        ]);
        $before = $this->intelligence->profile($assetType, $assetId);
        $payload = $data;
        if (array_key_exists('metadata', $payload)) $payload['metadata'] = $payload['metadata'] === null ? null : json_encode($payload['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payload['updated_at'] = now();

        $exists = DB::table('asset_profiles')->where('app_id', $this->context->id())->where('asset_type', $assetType)->where('asset_id', $assetId)->exists();
        if ($exists) {
            DB::table('asset_profiles')->where('app_id', $this->context->id())->where('asset_type', $assetType)->where('asset_id', $assetId)->update($payload);
        } else {
            DB::table('asset_profiles')->insert(array_merge($payload, [
                'app_id' => $this->context->id(), 'asset_type' => $assetType, 'asset_id' => $assetId, 'created_at' => now(),
            ]));
        }
        $after = $this->intelligence->profile($assetType, $assetId);
        $this->intelligence->audit($assetType, $assetId, (int) $request->user()->id, 'profile_updated', 'Perfil patrimonial atualizado', 'Valores e referências do patrimônio foram alterados.', $before, $after);
        return response()->json(['profile' => $after]);
    }

    public function analytics(Request $request, string $assetType, int $assetId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'financial');
        return response()->json($this->intelligence->analytics($assetType, $assetId));
    }

    public function health(Request $request, string $assetType, int $assetId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'view');
        return response()->json($this->intelligence->health($assetType, $assetId));
    }

    public function alerts(Request $request, string $assetType, int $assetId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'view');
        return response()->json(['items' => $this->intelligence->alerts($assetType, $assetId)]);
    }

    public function financial(Request $request, string $assetType, int $assetId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'financial');
        return response()->json($this->intelligence->financial($assetType, $assetId));
    }

    public function storeFinancialEntry(Request $request, string $assetType, int $assetId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'financial');
        $data = $this->validateFinancialEntry($request, false);
        $id = DB::table('asset_financial_entries')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'app_id' => $this->context->id(),
            'asset_type' => $assetType,
            'asset_id' => $assetId,
            'lease_id' => $data['lease_id'] ?? null,
            'created_by_user_id' => $request->user()->id,
            'direction' => $data['direction'],
            'category' => $data['category'],
            'description' => $data['description'],
            'amount' => $data['amount'],
            'occurred_on' => $data['occurred_on'],
            'due_on' => $data['due_on'] ?? null,
            'status' => $data['status'] ?? 'paid',
            'paid_at' => ($data['status'] ?? 'paid') === 'paid' ? ($data['paid_at'] ?? now()) : null,
            'recurring' => (bool) ($data['recurring'] ?? false),
            'recurrence_months' => $data['recurrence_months'] ?? null,
            'document_reference' => $data['document_reference'] ?? null,
            'metadata' => isset($data['metadata']) ? json_encode($data['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = $this->financialEntry($assetType, $assetId, $id);
        $this->intelligence->audit($assetType, $assetId, (int) $request->user()->id, 'financial_entry_created', 'Lançamento financeiro registrado', $row->description, null, (array) $row, ['entry_id' => $id]);
        return response()->json(['item' => $this->entryPayload($row)], 201);
    }

    public function updateFinancialEntry(Request $request, string $assetType, int $assetId, int $entryId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'financial');
        $row = $this->financialEntry($assetType, $assetId, $entryId);
        $before = $this->entryPayload($row);
        $data = $this->validateFinancialEntry($request, true);
        if (array_key_exists('metadata', $data)) $data['metadata'] = $data['metadata'] === null ? null : json_encode($data['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (array_key_exists('status', $data)) {
            if ($data['status'] === 'paid' && empty($data['paid_at'])) $data['paid_at'] = now();
            if ($data['status'] !== 'paid') $data['paid_at'] = null;
        }
        $data['updated_at'] = now();
        DB::table('asset_financial_entries')->where('id', $entryId)->update($data);
        $afterRow = $this->financialEntry($assetType, $assetId, $entryId);
        $after = $this->entryPayload($afterRow);
        $this->intelligence->audit($assetType, $assetId, (int) $request->user()->id, 'financial_entry_updated', 'Lançamento financeiro atualizado', $afterRow->description, $before, $after, ['entry_id' => $entryId]);
        return response()->json(['item' => $after]);
    }

    public function deleteFinancialEntry(Request $request, string $assetType, int $assetId, int $entryId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'financial');
        $row = $this->financialEntry($assetType, $assetId, $entryId);
        DB::table('asset_financial_entries')->where('id', $entryId)->update(['deleted_at' => now(), 'updated_at' => now()]);
        $this->intelligence->audit($assetType, $assetId, (int) $request->user()->id, 'financial_entry_deleted', 'Lançamento financeiro removido', $row->description, $this->entryPayload($row), null, ['entry_id' => $entryId]);
        return response()->json(['ok' => true]);
    }

    public function audit(Request $request, string $assetType, int $assetId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'audit');
        return response()->json(['items' => $this->intelligence->auditEvents($assetType, $assetId, (int) $request->query('limit', 200))]);
    }

    private function validateFinancialEntry(Request $request, bool $partial): array
    {
        $required = $partial ? 'sometimes|' : '';
        return $request->validate([
            'lease_id' => 'sometimes|nullable|integer|min:1',
            'direction' => $required.'required|in:income,expense',
            'category' => $required.'required|string|max:80|regex:/^[A-Za-z0-9_.-]+$/',
            'description' => $required.'required|string|max:190',
            'amount' => $required.'required|numeric|min:0.01|max:999999999999',
            'occurred_on' => $required.'required|date',
            'due_on' => 'sometimes|nullable|date',
            'status' => 'sometimes|required|in:planned,pending,paid,cancelled',
            'paid_at' => 'sometimes|nullable|date',
            'recurring' => 'sometimes|boolean',
            'recurrence_months' => 'sometimes|nullable|integer|min:1|max:120',
            'document_reference' => 'sometimes|nullable|string|max:190',
            'metadata' => 'sometimes|nullable|array',
        ]);
    }

    private function financialEntry(string $assetType, int $assetId, int $entryId): object
    {
        $row = DB::table('asset_financial_entries')->where('app_id', $this->context->id())->where('asset_type', $assetType)->where('asset_id', $assetId)->where('id', $entryId)->whereNull('deleted_at')->first();
        abort_unless($row, 404, 'Lançamento financeiro não encontrado.');
        return $row;
    }

    private function entryPayload(object $row): array
    {
        $data = (array) $row;
        $data['amount'] = (float) $row->amount;
        $data['recurring'] = (bool) $row->recurring;
        $data['metadata'] = $row->metadata ? (json_decode((string) $row->metadata, true) ?: []) : [];
        return $data;
    }
}
