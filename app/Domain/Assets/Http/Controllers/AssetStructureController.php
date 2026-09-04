<?php

namespace App\Domain\Assets\Http\Controllers;

use App\Domain\Assets\Services\AssetAccessService;
use App\Domain\Assets\Services\AssetIntelligenceService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AssetStructureController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AssetAccessService $access,
        private readonly AssetIntelligenceService $intelligence,
    ) {}

    public function spaces(Request $request, string $assetType, int $assetId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'view');
        return response()->json(['items' => DB::table('asset_spaces')->where('app_id', $this->context->id())->where('asset_type', $assetType)->where('asset_id', $assetId)->whereNull('deleted_at')->orderBy('sort_order')->orderBy('name')->get()->map(fn ($row) => $this->spacePayload($row))]);
    }

    public function storeSpace(Request $request, string $assetType, int $assetId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'edit');
        $data = $request->validate([
            'parent_id' => 'nullable|integer|min:1',
            'name' => 'required|string|max:120',
            'type' => 'required|string|max:60|regex:/^[A-Za-z0-9_.-]+$/',
            'sort_order' => 'nullable|integer|min:0|max:100000',
            'metadata' => 'nullable|array',
        ]);
        if (! empty($data['parent_id'])) $this->assertSpace($assetType, $assetId, (int) $data['parent_id']);
        $id = DB::table('asset_spaces')->insertGetId([
            'app_id' => $this->context->id(), 'asset_type' => $assetType, 'asset_id' => $assetId,
            'parent_id' => $data['parent_id'] ?? null, 'name' => $data['name'], 'type' => $data['type'],
            'sort_order' => $data['sort_order'] ?? 0, 'metadata' => $this->json($data['metadata'] ?? null),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = $this->assertSpace($assetType, $assetId, $id);
        $this->intelligence->audit($assetType, $assetId, (int) $request->user()->id, 'space_created', 'Ambiente cadastrado', $row->name, null, $this->spacePayload($row), ['space_id' => $id]);
        return response()->json(['item' => $this->spacePayload($row)], 201);
    }

    public function updateSpace(Request $request, string $assetType, int $assetId, int $spaceId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'edit');
        $row = $this->assertSpace($assetType, $assetId, $spaceId);
        $before = $this->spacePayload($row);
        $data = $request->validate([
            'parent_id' => 'sometimes|nullable|integer|min:1', 'name' => 'sometimes|required|string|max:120',
            'type' => 'sometimes|required|string|max:60|regex:/^[A-Za-z0-9_.-]+$/', 'sort_order' => 'sometimes|integer|min:0|max:100000',
            'metadata' => 'sometimes|nullable|array',
        ]);
        if (isset($data['parent_id'])) { abort_if((int) $data['parent_id'] === $spaceId, 422, 'Um ambiente não pode ser pai dele mesmo.'); $this->assertSpace($assetType, $assetId, (int) $data['parent_id']); }
        if (array_key_exists('metadata', $data)) $data['metadata'] = $this->json($data['metadata']);
        $data['updated_at'] = now();
        DB::table('asset_spaces')->where('id', $spaceId)->update($data);
        $afterRow = $this->assertSpace($assetType, $assetId, $spaceId);
        $this->intelligence->audit($assetType, $assetId, (int) $request->user()->id, 'space_updated', 'Ambiente atualizado', $afterRow->name, $before, $this->spacePayload($afterRow), ['space_id' => $spaceId]);
        return response()->json(['item' => $this->spacePayload($afterRow)]);
    }

    public function deleteSpace(Request $request, string $assetType, int $assetId, int $spaceId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'edit');
        $row = $this->assertSpace($assetType, $assetId, $spaceId);
        DB::transaction(function () use ($spaceId) {
            DB::table('asset_inventory_items')->where('space_id', $spaceId)->whereNull('deleted_at')->update(['space_id' => null, 'updated_at' => now()]);
            DB::table('asset_maintenance_plans')->where('space_id', $spaceId)->whereNull('deleted_at')->update(['space_id' => null, 'updated_at' => now()]);
            DB::table('asset_spaces')->where('id', $spaceId)->update(['deleted_at' => now(), 'updated_at' => now()]);
        });
        $this->intelligence->audit($assetType, $assetId, (int) $request->user()->id, 'space_deleted', 'Ambiente removido', $row->name, $this->spacePayload($row), null, ['space_id' => $spaceId]);
        return response()->json(['ok' => true]);
    }

    public function inventory(Request $request, string $assetType, int $assetId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'view');
        $rows = DB::table('asset_inventory_items as i')->leftJoin('asset_spaces as s', 's.id', '=', 'i.space_id')
            ->where('i.app_id', $this->context->id())->where('i.asset_type', $assetType)->where('i.asset_id', $assetId)->whereNull('i.deleted_at')
            ->orderBy('s.sort_order')->orderBy('s.name')->orderBy('i.name')
            ->get(['i.*', 's.name as space_name']);
        return response()->json(['items' => $rows->map(fn ($row) => $this->inventoryPayload($row))]);
    }

    public function storeInventory(Request $request, string $assetType, int $assetId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'inventory');
        $data = $this->validateInventory($request, false);
        if (! empty($data['space_id'])) $this->assertSpace($assetType, $assetId, (int) $data['space_id']);
        $id = DB::table('asset_inventory_items')->insertGetId([
            'public_id' => (string) Str::uuid(), 'app_id' => $this->context->id(), 'asset_type' => $assetType, 'asset_id' => $assetId,
            'space_id' => $data['space_id'] ?? null, 'name' => $data['name'], 'category' => $data['category'] ?? 'other',
            'serial_number' => $data['serial_number'] ?? null, 'condition' => $data['condition'] ?? 'good', 'quantity' => $data['quantity'] ?? 1,
            'acquisition_value' => $data['acquisition_value'] ?? null, 'acquired_on' => $data['acquired_on'] ?? null, 'notes' => $data['notes'] ?? null,
            'metadata' => $this->json($data['metadata'] ?? null), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = $this->inventoryRow($assetType, $assetId, $id);
        $this->intelligence->audit($assetType, $assetId, (int) $request->user()->id, 'inventory_created', 'Item adicionado ao inventário', $row->name, null, $this->inventoryPayload($row), ['inventory_id' => $id]);
        return response()->json(['item' => $this->inventoryPayload($row)], 201);
    }

    public function updateInventory(Request $request, string $assetType, int $assetId, int $itemId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'inventory');
        $row = $this->inventoryRow($assetType, $assetId, $itemId); $before = $this->inventoryPayload($row);
        $data = $this->validateInventory($request, true);
        if (array_key_exists('space_id', $data) && $data['space_id']) $this->assertSpace($assetType, $assetId, (int) $data['space_id']);
        if (array_key_exists('metadata', $data)) $data['metadata'] = $this->json($data['metadata']);
        $data['updated_at'] = now(); DB::table('asset_inventory_items')->where('id', $itemId)->update($data);
        $after = $this->inventoryRow($assetType, $assetId, $itemId);
        $this->intelligence->audit($assetType, $assetId, (int) $request->user()->id, 'inventory_updated', 'Item do inventário atualizado', $after->name, $before, $this->inventoryPayload($after), ['inventory_id' => $itemId]);
        return response()->json(['item' => $this->inventoryPayload($after)]);
    }

    public function deleteInventory(Request $request, string $assetType, int $assetId, int $itemId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'inventory');
        $row = $this->inventoryRow($assetType, $assetId, $itemId);
        DB::table('asset_inventory_items')->where('id', $itemId)->update(['deleted_at' => now(), 'updated_at' => now()]);
        $this->intelligence->audit($assetType, $assetId, (int) $request->user()->id, 'inventory_deleted', 'Item removido do inventário', $row->name, $this->inventoryPayload($row), null, ['inventory_id' => $itemId]);
        return response()->json(['ok' => true]);
    }

    public function preventive(Request $request, string $assetType, int $assetId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'view');
        $this->materializePreventiveTasks($assetType, $assetId, (int) $request->user()->id);
        $rows = DB::table('asset_maintenance_plans as p')->leftJoin('asset_spaces as s', 's.id', '=', 'p.space_id')
            ->where('p.app_id', $this->context->id())->where('p.asset_type', $assetType)->where('p.asset_id', $assetId)->whereNull('p.deleted_at')
            ->orderBy('p.next_due_on')->get(['p.*', 's.name as space_name']);
        return response()->json(['items' => $rows->map(fn ($row) => $this->preventivePayload($row))]);
    }

    public function storePreventive(Request $request, string $assetType, int $assetId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'preventive');
        $data = $this->validatePreventive($request, false);
        if (! empty($data['space_id'])) $this->assertSpace($assetType, $assetId, (int) $data['space_id']);
        $id = DB::table('asset_maintenance_plans')->insertGetId([
            'public_id' => (string) Str::uuid(), 'app_id' => $this->context->id(), 'asset_type' => $assetType, 'asset_id' => $assetId,
            'space_id' => $data['space_id'] ?? null, 'title' => $data['title'], 'description' => $data['description'] ?? null,
            'priority' => $data['priority'] ?? 'normal', 'frequency_months' => $data['frequency_months'], 'last_completed_on' => $data['last_completed_on'] ?? null,
            'next_due_on' => $data['next_due_on'], 'estimated_cost' => $data['estimated_cost'] ?? null, 'is_active' => $data['is_active'] ?? true,
            'metadata' => $this->json($data['metadata'] ?? null), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = $this->preventiveRow($assetType, $assetId, $id);
        $this->intelligence->audit($assetType, $assetId, (int) $request->user()->id, 'preventive_created', 'Plano de manutenção preventiva criado', $row->title, null, $this->preventivePayload($row), ['plan_id' => $id]);
        return response()->json(['item' => $this->preventivePayload($row)], 201);
    }

    public function updatePreventive(Request $request, string $assetType, int $assetId, int $planId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'preventive');
        $row = $this->preventiveRow($assetType, $assetId, $planId); $before = $this->preventivePayload($row);
        $data = $this->validatePreventive($request, true);
        if (array_key_exists('space_id', $data) && $data['space_id']) $this->assertSpace($assetType, $assetId, (int) $data['space_id']);
        if (array_key_exists('metadata', $data)) $data['metadata'] = $this->json($data['metadata']);
        $data['updated_at'] = now(); DB::table('asset_maintenance_plans')->where('id', $planId)->update($data);
        $after = $this->preventiveRow($assetType, $assetId, $planId);
        $this->intelligence->audit($assetType, $assetId, (int) $request->user()->id, 'preventive_updated', 'Plano preventivo atualizado', $after->title, $before, $this->preventivePayload($after), ['plan_id' => $planId]);
        return response()->json(['item' => $this->preventivePayload($after)]);
    }

    public function completePreventive(Request $request, string $assetType, int $assetId, int $planId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'preventive');
        $plan = $this->preventiveRow($assetType, $assetId, $planId);
        $data = $request->validate(['completed_on' => 'nullable|date', 'actual_cost' => 'nullable|numeric|min:0|max:999999999', 'notes' => 'nullable|string|max:5000']);
        $completed = CarbonImmutable::parse($data['completed_on'] ?? today())->startOfDay();
        $next = $completed->addMonths(max(1, (int) $plan->frequency_months));
        DB::transaction(function () use ($request, $assetType, $assetId, $planId, $plan, $completed, $next, $data) {
            DB::table('asset_maintenance_plans')->where('id', $planId)->update(['last_completed_on' => $completed->toDateString(), 'next_due_on' => $next->toDateString(), 'updated_at' => now()]);
            if ($assetType === 'property') {
                $operations = DB::table('lease_operations')->where('app_id', $this->context->id())->where('property_id', $assetId)->where('type', 'maintenance')->whereIn('status', ['open', 'in_progress', 'waiting'])->whereNull('deleted_at')->get();
                foreach ($operations as $operation) {
                    $payload = $this->decode($operation->payload);
                    if ((int) ($payload['preventive_plan_id'] ?? 0) !== $planId) continue;
                    $payload['actual_cost'] = $data['actual_cost'] ?? $operation->amount;
                    $payload['notes'] = $data['notes'] ?? ($payload['notes'] ?? null);
                    DB::table('lease_operations')->where('id', $operation->id)->update(['status' => 'completed', 'completed_at' => now(), 'amount' => $data['actual_cost'] ?? $operation->amount, 'payload' => $this->json($payload), 'updated_at' => now()]);
                }
            }
        });
        $updated = $this->preventiveRow($assetType, $assetId, $planId);
        $this->intelligence->audit($assetType, $assetId, (int) $request->user()->id, 'preventive_completed', 'Manutenção preventiva concluída', $updated->title, $this->preventivePayload($plan), $this->preventivePayload($updated), ['plan_id' => $planId, 'actual_cost' => $data['actual_cost'] ?? null]);
        return response()->json(['item' => $this->preventivePayload($updated)]);
    }

    public function deletePreventive(Request $request, string $assetType, int $assetId, int $planId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'preventive');
        $row = $this->preventiveRow($assetType, $assetId, $planId);
        DB::table('asset_maintenance_plans')->where('id', $planId)->update(['deleted_at' => now(), 'is_active' => false, 'updated_at' => now()]);
        $this->intelligence->audit($assetType, $assetId, (int) $request->user()->id, 'preventive_deleted', 'Plano preventivo removido', $row->title, $this->preventivePayload($row), null, ['plan_id' => $planId]);
        return response()->json(['ok' => true]);
    }

    public function tags(Request $request, string $assetType, int $assetId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'view');
        return response()->json(['items' => DB::table('asset_tags')->where('app_id', $this->context->id())->where('asset_type', $assetType)->where('asset_id', $assetId)->orderBy('tag')->get()]);
    }

    public function setTags(Request $request, string $assetType, int $assetId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'edit');
        $data = $request->validate(['tags' => 'required|array|max:30', 'tags.*' => 'required|string|max:80']);
        $tags = collect($data['tags'])->map(fn ($tag) => trim($tag))->filter()->unique(fn ($tag) => mb_strtolower($tag))->values();
        $before = DB::table('asset_tags')->where('app_id', $this->context->id())->where('asset_type', $assetType)->where('asset_id', $assetId)->pluck('tag')->all();
        DB::transaction(function () use ($assetType, $assetId, $tags) {
            DB::table('asset_tags')->where('app_id', $this->context->id())->where('asset_type', $assetType)->where('asset_id', $assetId)->delete();
            foreach ($tags as $tag) DB::table('asset_tags')->insert(['app_id' => $this->context->id(), 'asset_type' => $assetType, 'asset_id' => $assetId, 'tag' => $tag, 'created_at' => now(), 'updated_at' => now()]);
        });
        $after = $tags->all();
        $this->intelligence->audit($assetType, $assetId, (int) $request->user()->id, 'tags_updated', 'Tags do patrimônio atualizadas', implode(', ', $after), $before, $after);
        return response()->json(['items' => DB::table('asset_tags')->where('app_id', $this->context->id())->where('asset_type', $assetType)->where('asset_id', $assetId)->orderBy('tag')->get()]);
    }

    public function ownerships(Request $request, string $assetType, int $assetId)
    {
        $asset = $this->access->assertAccess($request, $assetType, $assetId, 'owners');
        $this->access->ensurePrimaryOwnership($assetType, $assetId, (int) $asset->owner_user_id);
        $rows = DB::table('asset_ownerships as o')->join('users as u', 'u.id', '=', 'o.user_id')
            ->where('o.app_id', $this->context->id())->where('o.asset_type', $assetType)->where('o.asset_id', $assetId)
            ->orderByDesc('o.is_primary')->orderBy('u.first_name')->get(['o.*', 'u.first_name', 'u.last_name', 'u.email']);
        return response()->json(['items' => $rows->map(fn ($row) => $this->ownershipPayload($row)), 'role_defaults' => $this->access->roleDefaults()]);
    }

    public function storeOwnership(Request $request, string $assetType, int $assetId)
    {
        $asset = $this->access->assertAccess($request, $assetType, $assetId, 'owners');
        $this->access->ensurePrimaryOwnership($assetType, $assetId, (int) $asset->owner_user_id);
        $data = $this->validateOwnership($request, false);
        $user = $this->resolveUser($data);
        abort_if((int) $user->id === (int) $asset->owner_user_id, 422, 'O proprietário principal já está vinculado.');
        $id = DB::table('asset_ownerships')->updateOrInsert([
            'app_id' => $this->context->id(), 'asset_type' => $assetType, 'asset_id' => $assetId, 'user_id' => $user->id,
        ], [
            'role' => $data['role'] ?? 'co_owner', 'share_percent' => $data['share_percent'] ?? 0, 'is_primary' => false,
            'starts_on' => $data['starts_on'] ?? null, 'ends_on' => $data['ends_on'] ?? null,
            'permissions' => isset($data['permissions']) ? $this->json($data['permissions']) : null,
            'metadata' => isset($data['metadata']) ? $this->json($data['metadata']) : null, 'updated_at' => now(), 'created_at' => now(),
        ]);
        $this->rebalancePrimaryShare($assetType, $assetId, (int) $asset->owner_user_id);
        $row = DB::table('asset_ownerships')->where('app_id', $this->context->id())->where('asset_type', $assetType)->where('asset_id', $assetId)->where('user_id', $user->id)->first();
        $this->intelligence->audit($assetType, $assetId, (int) $request->user()->id, 'ownership_created', 'Participação patrimonial adicionada', trim($user->first_name.' '.($user->last_name ?? '')), null, (array) $row, ['user_id' => $user->id]);
        return $this->ownerships($request, $assetType, $assetId);
    }

    public function updateOwnership(Request $request, string $assetType, int $assetId, int $ownershipId)
    {
        $asset = $this->access->assertAccess($request, $assetType, $assetId, 'owners');
        $row = $this->ownershipRow($assetType, $assetId, $ownershipId);
        abort_if((bool) $row->is_primary, 422, 'A participação do proprietário principal deve ser ajustada automaticamente pelos demais percentuais.');
        $before = (array) $row; $data = $this->validateOwnership($request, true);
        unset($data['user_id'], $data['email']);
        if (array_key_exists('permissions', $data)) $data['permissions'] = $this->json($data['permissions']);
        if (array_key_exists('metadata', $data)) $data['metadata'] = $this->json($data['metadata']);
        $data['updated_at'] = now(); DB::table('asset_ownerships')->where('id', $ownershipId)->update($data);
        $this->rebalancePrimaryShare($assetType, $assetId, (int) $asset->owner_user_id);
        $after = $this->ownershipRow($assetType, $assetId, $ownershipId);
        $this->intelligence->audit($assetType, $assetId, (int) $request->user()->id, 'ownership_updated', 'Participação patrimonial atualizada', null, $before, (array) $after, ['ownership_id' => $ownershipId]);
        return $this->ownerships($request, $assetType, $assetId);
    }

    public function deleteOwnership(Request $request, string $assetType, int $assetId, int $ownershipId)
    {
        $asset = $this->access->assertAccess($request, $assetType, $assetId, 'owners');
        $row = $this->ownershipRow($assetType, $assetId, $ownershipId); abort_if((bool) $row->is_primary, 422, 'O proprietário principal não pode ser removido.');
        DB::table('asset_ownerships')->where('id', $ownershipId)->delete(); $this->rebalancePrimaryShare($assetType, $assetId, (int) $asset->owner_user_id);
        $this->intelligence->audit($assetType, $assetId, (int) $request->user()->id, 'ownership_deleted', 'Participação patrimonial removida', null, (array) $row, null, ['ownership_id' => $ownershipId]);
        return $this->ownerships($request, $assetType, $assetId);
    }

    private function materializePreventiveTasks(string $assetType, int $assetId, int $actorId): void
    {
        if ($assetType !== 'property') return;
        $plans = DB::table('asset_maintenance_plans')->where('app_id', $this->context->id())->where('asset_type', $assetType)->where('asset_id', $assetId)->where('is_active', true)->whereNull('deleted_at')->whereDate('next_due_on', '<=', today())->get();
        if ($plans->isEmpty()) return;
        $open = DB::table('lease_operations')->where('app_id', $this->context->id())->where('property_id', $assetId)->where('type', 'maintenance')->whereIn('status', ['open', 'in_progress', 'waiting'])->whereNull('deleted_at')->get();
        foreach ($plans as $plan) {
            $exists = $open->contains(function ($operation) use ($plan) { $payload = $this->decode($operation->payload); return (int) ($payload['preventive_plan_id'] ?? 0) === (int) $plan->id; });
            if ($exists) continue;
            DB::table('lease_operations')->insert([
                'public_id' => (string) Str::uuid(), 'app_id' => $this->context->id(), 'lease_id' => null, 'property_id' => $assetId, 'actor_user_id' => $actorId,
                'type' => 'maintenance', 'status' => 'open', 'priority' => $plan->priority, 'title' => 'Preventiva · '.$plan->title,
                'description' => $plan->description, 'due_at' => CarbonImmutable::parse($plan->next_due_on)->endOfDay(), 'occurred_at' => now(), 'completed_at' => null,
                'amount' => $plan->estimated_cost, 'payload' => $this->json(['preventive_plan_id' => $plan->id, 'preventive' => true, 'estimated_cost' => $plan->estimated_cost]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function validateInventory(Request $request, bool $partial): array
    {
        $r = $partial ? 'sometimes|' : '';
        return $request->validate(['space_id' => 'sometimes|nullable|integer|min:1', 'name' => $r.'required|string|max:160', 'category' => 'sometimes|required|string|max:80', 'serial_number' => 'sometimes|nullable|string|max:120', 'condition' => 'sometimes|required|in:new,excellent,good,fair,poor,damaged,missing', 'quantity' => 'sometimes|required|integer|min:1|max:100000', 'acquisition_value' => 'sometimes|nullable|numeric|min:0|max:999999999', 'acquired_on' => 'sometimes|nullable|date', 'notes' => 'sometimes|nullable|string|max:10000', 'metadata' => 'sometimes|nullable|array']);
    }

    private function validatePreventive(Request $request, bool $partial): array
    {
        $r = $partial ? 'sometimes|' : '';
        return $request->validate(['space_id' => 'sometimes|nullable|integer|min:1', 'title' => $r.'required|string|max:190', 'description' => 'sometimes|nullable|string|max:10000', 'priority' => 'sometimes|required|in:low,normal,high,urgent', 'frequency_months' => $r.'required|integer|min:1|max:120', 'last_completed_on' => 'sometimes|nullable|date', 'next_due_on' => $r.'required|date', 'estimated_cost' => 'sometimes|nullable|numeric|min:0|max:999999999', 'is_active' => 'sometimes|boolean', 'metadata' => 'sometimes|nullable|array']);
    }

    private function validateOwnership(Request $request, bool $partial): array
    {
        $r = $partial ? 'sometimes|' : '';
        return $request->validate(['user_id' => 'sometimes|nullable|integer|exists:users,id', 'email' => 'sometimes|nullable|email|max:190', 'role' => 'sometimes|required|in:co_owner,manager,accountant,lawyer,realtor,provider,tenant,viewer', 'share_percent' => 'sometimes|numeric|min:0|max:100', 'starts_on' => 'sometimes|nullable|date', 'ends_on' => 'sometimes|nullable|date|after_or_equal:starts_on', 'permissions' => 'sometimes|nullable|array|max:30', 'permissions.*' => 'string|max:60', 'metadata' => 'sometimes|nullable|array']);
    }

    private function resolveUser(array $data): object
    {
        $user = ! empty($data['user_id']) ? DB::table('users')->where('id', $data['user_id'])->first() : null;
        if (! $user && ! empty($data['email'])) $user = DB::table('users')->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($data['email']))])->first();
        abort_unless($user, 422, 'O usuário precisa ter uma conta Peter Tecnet antes de virar coproprietário. Para consulta temporária, use Compartilhar.');
        return $user;
    }

    private function rebalancePrimaryShare(string $assetType, int $assetId, int $primaryUserId): void
    {
        $others = (float) DB::table('asset_ownerships')->where('app_id', $this->context->id())->where('asset_type', $assetType)->where('asset_id', $assetId)->where('user_id', '!=', $primaryUserId)->sum('share_percent');
        abort_if($others > 100.0001, 422, 'A soma das participações não pode ultrapassar 100%.');
        DB::table('asset_ownerships')->where('app_id', $this->context->id())->where('asset_type', $assetType)->where('asset_id', $assetId)->where('user_id', $primaryUserId)->update(['share_percent' => max(0, 100 - $others), 'updated_at' => now()]);
    }

    private function assertSpace(string $assetType, int $assetId, int $spaceId): object { $row = DB::table('asset_spaces')->where('app_id', $this->context->id())->where('asset_type', $assetType)->where('asset_id', $assetId)->where('id', $spaceId)->whereNull('deleted_at')->first(); abort_unless($row, 404, 'Ambiente não encontrado.'); return $row; }
    private function inventoryRow(string $assetType, int $assetId, int $id): object { $row = DB::table('asset_inventory_items')->where('app_id', $this->context->id())->where('asset_type', $assetType)->where('asset_id', $assetId)->where('id', $id)->whereNull('deleted_at')->first(); abort_unless($row, 404, 'Item de inventário não encontrado.'); return $row; }
    private function preventiveRow(string $assetType, int $assetId, int $id): object { $row = DB::table('asset_maintenance_plans')->where('app_id', $this->context->id())->where('asset_type', $assetType)->where('asset_id', $assetId)->where('id', $id)->whereNull('deleted_at')->first(); abort_unless($row, 404, 'Plano preventivo não encontrado.'); return $row; }
    private function ownershipRow(string $assetType, int $assetId, int $id): object { $row = DB::table('asset_ownerships')->where('app_id', $this->context->id())->where('asset_type', $assetType)->where('asset_id', $assetId)->where('id', $id)->first(); abort_unless($row, 404, 'Participação não encontrada.'); return $row; }

    private function spacePayload(object $row): array { $d = (array) $row; $d['metadata'] = $this->decode($row->metadata); return $d; }
    private function inventoryPayload(object $row): array { $d = (array) $row; $d['acquisition_value'] = $row->acquisition_value !== null ? (float) $row->acquisition_value : null; $d['metadata'] = $this->decode($row->metadata); return $d; }
    private function preventivePayload(object $row): array { $d = (array) $row; $d['estimated_cost'] = $row->estimated_cost !== null ? (float) $row->estimated_cost : null; $d['is_active'] = (bool) $row->is_active; $d['metadata'] = $this->decode($row->metadata); $d['days_until_due'] = CarbonImmutable::today()->diffInDays(CarbonImmutable::parse($row->next_due_on)->startOfDay(), false); return $d; }
    private function ownershipPayload(object $row): array { $d = (array) $row; $d['share_percent'] = (float) $row->share_percent; $d['is_primary'] = (bool) $row->is_primary; $d['permissions'] = $this->decode($row->permissions); $d['metadata'] = $this->decode($row->metadata); $d['display_name'] = trim(($row->first_name ?? '').' '.($row->last_name ?? '')); return $d; }
    private function decode(mixed $value): array { if (is_array($value)) return $value; if ($value === null || $value === '') return []; $d = json_decode((string) $value, true); return is_array($d) ? $d : []; }
    private function json(mixed $value): ?string { return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
}
