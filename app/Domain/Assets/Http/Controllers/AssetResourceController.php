<?php

namespace App\Domain\Assets\Http\Controllers;

use App\Domain\Assets\Services\AssetAccessService;
use App\Domain\Assets\Services\AssetIntelligenceService;
use App\Domain\Leasing\Services\LeaseLifecycleService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AssetResourceController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AssetAccessService $access,
        private readonly AssetIntelligenceService $intelligence,
        private readonly LeaseLifecycleService $lifecycle,
    ) {}

    public function show(Request $request, string $assetType, int $assetId)
    {
        $asset = $this->access->assertAccess($request, $assetType, $assetId, 'view');
        $permissions = $this->access->permissions($request, $assetType, $assetId);
        if ($assetType !== 'property') return response()->json(['asset' => (array) $asset, 'permissions' => $permissions]);

        $leases = DB::table('leases')->where('app_id', $this->context->id())->where('property_id', $assetId)->whereNull('deleted_at')->orderByDesc('starts_on')->orderByDesc('id')->get();
        $state = $this->lifecycle->effectivePropertyState($asset, $leases);
        $property = array_merge($this->decodeColumns((array) $asset, ['metadata']), $state);
        $property['stored_status'] = $asset->status;
        $property['status'] = $state['effective_status'];
        $property['permissions'] = $permissions;

        $canSeeLease = $this->allows($permissions, 'edit') || $this->allows($permissions, 'financial') || $this->allows($permissions, 'inspections');
        $leasePayloads = $canSeeLease ? $leases->map(fn ($lease) => array_merge($this->decodeColumns((array) $lease, ['clauses', 'included_expenses', 'tenant_expenses', 'metadata']), $this->lifecycle->evaluate($lease))) : collect();
        $current = $leasePayloads->first(fn ($lease) => (bool) ($lease['is_in_force'] ?? false))
            ?: $leasePayloads->first(fn ($lease) => in_array($lease['vigency_status'] ?? '', ['awaiting_signature', 'future'], true));

        $leaseIds = $leases->pluck('id');
        return response()->json([
            'asset' => $property,
            'property' => $property,
            'permissions' => $permissions,
            'current_lease' => $current,
            'leases' => $leasePayloads->values(),
            'profile' => $this->intelligence->profile($assetType, $assetId),
            'health' => $this->intelligence->health($assetType, $assetId),
            'alerts' => $this->intelligence->alerts($assetType, $assetId),
            'summary' => [
                'leases_total' => $canSeeLease ? $leases->count() : null,
                'inspections_total' => DB::table('property_inspections')->where('app_id', $this->context->id())->where('property_id', $assetId)->count(),
                'files_total' => DB::table('files')->where('app_id', $this->context->id())->where('entity_name', $assetType)->where('entity_id', $assetId)->where('status', 'active')->count(),
                'open_maintenance' => DB::table('lease_operations')->where('app_id', $this->context->id())->where('property_id', $assetId)->where('type', 'maintenance')->whereIn('status', ['open', 'in_progress', 'waiting'])->whereNull('deleted_at')->count(),
                'overdue_amount' => $canSeeLease && ! $leaseIds->isEmpty() ? round((float) DB::table('lease_charges')->where('app_id', $this->context->id())->whereIn('lease_id', $leaseIds)->whereIn('status', ['pending', 'processing'])->whereDate('due_date', '<', today())->sum('amount'), 2) : null,
            ],
        ]);
    }

    public function update(Request $request, string $assetType, int $assetId)
    {
        $asset = $this->access->assertAccess($request, $assetType, $assetId, 'edit');
        abort_unless($assetType === 'property', 422, 'Atualização ainda não registrada para este tipo de ativo.');
        $before = $this->decodeColumns((array) $asset, ['metadata']);
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:160', 'type' => 'sometimes|required|in:house,apartment,commercial,land,other',
            'use_type' => 'sometimes|required|in:residential,commercial,mixed', 'status' => 'sometimes|required|in:available,occupied,maintenance,inactive',
            'postal_code' => 'sometimes|nullable|string|max:12', 'street' => 'sometimes|required|string|max:190', 'number' => 'sometimes|nullable|string|max:40',
            'complement' => 'sometimes|nullable|string|max:120', 'neighborhood' => 'sometimes|nullable|string|max:120', 'city' => 'sometimes|required|string|max:120',
            'state' => 'sometimes|required|string|size:2', 'bedrooms' => 'sometimes|nullable|integer|min:0|max:100', 'bathrooms' => 'sometimes|nullable|integer|min:0|max:100',
            'parking_spaces' => 'sometimes|nullable|integer|min:0|max:100', 'area_m2' => 'sometimes|nullable|numeric|min:0|max:99999999',
            'default_rent_amount' => 'sometimes|nullable|numeric|min:0', 'default_due_day' => 'sometimes|nullable|integer|min:1|max:31', 'metadata' => 'sometimes|nullable|array',
        ]);
        if (isset($data['status'])) {
            $hasCurrentLease = DB::table('leases')->where('app_id', $this->context->id())->where('property_id', $assetId)->where('status', 'active')->whereNull('deleted_at')->whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today())->exists();
            if ($hasCurrentLease) abort_if(in_array($data['status'], ['available', 'inactive'], true), 422, 'Um patrimônio com locação vigente não pode ser marcado como disponível ou inativo.');
            if (! $hasCurrentLease && $data['status'] === 'occupied') abort(422, 'O status ocupado é calculado automaticamente por uma locação vigente.');
        }
        if (array_key_exists('metadata', $data)) $data['metadata'] = $this->json($data['metadata']);
        if (isset($data['state'])) $data['state'] = strtoupper($data['state']);
        $data['updated_at'] = now();
        DB::table('properties')->where('app_id', $this->context->id())->where('id', $assetId)->update($data);
        $afterRow = $this->access->asset($assetType, $assetId); $after = $this->decodeColumns((array) $afterRow, ['metadata']);
        $this->intelligence->audit($assetType, $assetId, (int) $request->user()->id, 'asset_updated', 'Dados do patrimônio atualizados', $this->describeChanges($before, $after), $before, $after);
        return $this->show($request, $assetType, $assetId);
    }

    public function inspections(Request $request, string $assetType, int $assetId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'view');
        abort_unless($assetType === 'property', 422);
        $items = DB::table('property_inspections')->where('app_id', $this->context->id())->where('property_id', $assetId)->orderByDesc('occurred_at')->get()->map(function ($row) {
            $data = (array) $row; $data['items'] = $this->decode($row->items); $data['metadata'] = $this->decode($row->metadata); return $data;
        });
        return response()->json(['items' => $items]);
    }

    public function storeInspection(Request $request, string $assetType, int $assetId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'inspections');
        abort_unless($assetType === 'property', 422);
        $data = $request->validate([
            'lease_id' => 'nullable|integer|min:1', 'type' => 'required|in:entry,periodic,exit', 'occurred_at' => 'required|date',
            'summary' => 'nullable|string|max:10000', 'items' => 'nullable|array|max:500', 'items.*.label' => 'nullable|string|max:190',
            'items.*.space_id' => 'nullable|integer|min:1', 'items.*.space_name' => 'nullable|string|max:120', 'items.*.condition' => 'nullable|string|max:40',
            'items.*.notes' => 'nullable|string|max:2000', 'metadata' => 'nullable|array',
        ]);
        if (! empty($data['lease_id'])) abort_unless(DB::table('leases')->where('app_id', $this->context->id())->where('property_id', $assetId)->where('id', $data['lease_id'])->whereNull('deleted_at')->exists(), 422, 'A locação não pertence a este patrimônio.');
        foreach ($data['items'] ?? [] as $item) {
            if (! empty($item['space_id'])) abort_unless(DB::table('asset_spaces')->where('app_id', $this->context->id())->where('asset_type', $assetType)->where('asset_id', $assetId)->where('id', $item['space_id'])->whereNull('deleted_at')->exists(), 422, 'Um item referencia ambiente inválido.');
        }
        $id = DB::table('property_inspections')->insertGetId([
            'app_id' => $this->context->id(), 'property_id' => $assetId, 'lease_id' => $data['lease_id'] ?? null, 'performed_by_user_id' => $request->user()->id,
            'type' => $data['type'], 'occurred_at' => $data['occurred_at'], 'summary' => $data['summary'] ?? null, 'items' => $this->json($data['items'] ?? []),
            'metadata' => $this->json($data['metadata'] ?? null), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = DB::table('property_inspections')->where('id', $id)->first();
        $this->intelligence->audit($assetType, $assetId, (int) $request->user()->id, 'inspection_created', 'Vistoria registrada', $data['summary'] ?? ucfirst($data['type']), null, ['inspection_id' => $id, 'type' => $data['type'], 'occurred_at' => $data['occurred_at']], ['inspection_id' => $id]);
        $payload = (array) $row; $payload['items'] = $this->decode($row->items); $payload['metadata'] = $this->decode($row->metadata);
        return response()->json(['item' => $payload], 201);
    }

    public function maintenance(Request $request, string $assetType, int $assetId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'view'); abort_unless($assetType === 'property', 422);
        $rows = DB::table('lease_operations')->where('app_id', $this->context->id())->where('property_id', $assetId)->where('type', 'maintenance')->whereNull('deleted_at')
            ->orderByRaw("FIELD(priority, 'urgent', 'high', 'normal', 'low')")->orderByRaw("FIELD(status, 'open', 'in_progress', 'waiting', 'completed', 'cancelled')")->orderByDesc('id')->get()->map(fn ($row) => $this->operationPayload($row));
        return response()->json(['items' => $rows]);
    }

    public function storeMaintenance(Request $request, string $assetType, int $assetId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'maintenance'); abort_unless($assetType === 'property', 422);
        $data = $this->validateMaintenance($request, false); $this->assertOptionalLease($assetId, $data['lease_id'] ?? null);
        if (! empty($data['space_id'])) $this->assertSpace($assetType, $assetId, (int) $data['space_id']);
        $status = $data['status'] ?? 'open'; $meta = array_filter(['vendor' => $data['vendor'] ?? null, 'estimated_cost' => $data['estimated_cost'] ?? null, 'actual_cost' => $data['actual_cost'] ?? null, 'notes' => $data['notes'] ?? null, 'space_id' => $data['space_id'] ?? null]);
        $id = DB::table('lease_operations')->insertGetId([
            'public_id' => (string) Str::uuid(), 'app_id' => $this->context->id(), 'lease_id' => $data['lease_id'] ?? null, 'property_id' => $assetId,
            'actor_user_id' => $request->user()->id, 'type' => 'maintenance', 'status' => $status, 'priority' => $data['priority'] ?? 'normal',
            'title' => $data['title'], 'description' => $data['description'] ?? null, 'due_at' => $data['due_at'] ?? null, 'occurred_at' => now(),
            'completed_at' => $status === 'completed' ? now() : null, 'amount' => $data['actual_cost'] ?? $data['estimated_cost'] ?? null, 'payload' => $this->json($meta),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = DB::table('lease_operations')->where('id', $id)->first(); $payload = $this->operationPayload($row);
        $this->intelligence->audit($assetType, $assetId, (int) $request->user()->id, 'maintenance_created', 'Manutenção registrada', $row->title, null, $payload, ['operation_id' => $id]);
        return response()->json(['item' => $payload], 201);
    }

    public function updateMaintenance(Request $request, string $assetType, int $assetId, int $operationId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'maintenance'); abort_unless($assetType === 'property', 422);
        $row = DB::table('lease_operations')->where('app_id', $this->context->id())->where('property_id', $assetId)->where('id', $operationId)->where('type', 'maintenance')->whereNull('deleted_at')->first();
        abort_unless($row, 404, 'Manutenção não encontrada.'); $before = $this->operationPayload($row); $data = $this->validateMaintenance($request, true);
        if (array_key_exists('lease_id', $data)) $this->assertOptionalLease($assetId, $data['lease_id']);
        if (array_key_exists('space_id', $data) && $data['space_id']) $this->assertSpace($assetType, $assetId, (int) $data['space_id']);
        $update = []; foreach (['lease_id','status','priority','title','description','due_at'] as $field) if (array_key_exists($field, $data)) $update[$field] = $data[$field];
        $meta = array_merge($this->decode($row->payload), array_filter(array_intersect_key($data, array_flip(['vendor','estimated_cost','actual_cost','notes','space_id'])), fn ($value) => $value !== null));
        $update['payload'] = $this->json($meta); if (array_key_exists('actual_cost', $data) || array_key_exists('estimated_cost', $data)) $update['amount'] = $data['actual_cost'] ?? $data['estimated_cost'] ?? $row->amount;
        if (($data['status'] ?? null) === 'completed' && ! $row->completed_at) $update['completed_at'] = now(); if (isset($data['status']) && $data['status'] !== 'completed') $update['completed_at'] = null;
        $update['updated_at'] = now(); DB::table('lease_operations')->where('id', $operationId)->update($update);
        $afterRow = DB::table('lease_operations')->where('id', $operationId)->first(); $after = $this->operationPayload($afterRow);
        $this->intelligence->audit($assetType, $assetId, (int) $request->user()->id, 'maintenance_updated', 'Manutenção atualizada', $afterRow->title, $before, $after, ['operation_id' => $operationId]);
        return response()->json(['item' => $after]);
    }

    public function timeline(Request $request, string $assetType, int $assetId)
    {
        $asset = $this->access->assertAccess($request, $assetType, $assetId, 'view');
        $events = collect([$this->event('asset_created', 'Patrimônio cadastrado', null, $asset->created_at, [])]);
        if ($asset->updated_at && $asset->updated_at !== $asset->created_at) $events->push($this->event('asset_updated', 'Dados do patrimônio atualizados', null, $asset->updated_at, []));

        if ($assetType === 'property') {
            $leases = DB::table('leases')->where('app_id', $this->context->id())->where('property_id', $assetId)->whereNull('deleted_at')->get(); $leaseIds = $leases->pluck('id');
            foreach ($leases as $lease) {
                $events->push($this->event('lease_created','Locação criada','Inquilino: '.$lease->tenant_name,$lease->created_at,['lease_id'=>$lease->id]));
                if ($lease->starts_on) $events->push($this->event('lease_start','Início da locação',$lease->tenant_name,$lease->starts_on,['lease_id'=>$lease->id]));
                if ($lease->ended_at) $events->push($this->event('lease_ended','Locação encerrada',$lease->tenant_name,$lease->ended_at,['lease_id'=>$lease->id]));
            }
            DB::table('property_inspections')->where('app_id',$this->context->id())->where('property_id',$assetId)->get()->each(fn($row)=>$events->push($this->event('inspection','Vistoria '.($row->type ?? ''),$row->summary,$row->occurred_at,['inspection_id'=>$row->id])));
            DB::table('lease_operations')->where('app_id',$this->context->id())->where('property_id',$assetId)->whereNull('deleted_at')->get()->each(function($row)use($events){$events->push($this->event('operation_'.$row->type,$row->title,$row->description,$row->occurred_at?:$row->created_at,['operation_id'=>$row->id,'status'=>$row->status,'amount'=>$row->amount!==null?(float)$row->amount:null]));if($row->completed_at)$events->push($this->event('operation_completed','Concluído: '.$row->title,$row->description,$row->completed_at,['operation_id'=>$row->id]));});
            if(!$leaseIds->isEmpty()) DB::table('lease_charges')->where('app_id',$this->context->id())->whereIn('lease_id',$leaseIds)->whereNotNull('paid_at')->get()->each(fn($row)=>$events->push($this->event('payment_received','Pagamento recebido',$row->description,$row->paid_at,['charge_id'=>$row->id,'amount'=>(float)$row->amount])));
        }
        DB::table('files')->where('app_id',$this->context->id())->where('entity_name',$assetType)->where('entity_id',$assetId)->where('status','active')->get()->each(fn($row)=>$events->push($this->event('file_uploaded','Arquivo adicionado',$row->original_name,$row->created_at,['file_id'=>$row->id])));
        DB::table('asset_financial_entries')->where('app_id',$this->context->id())->where('asset_type',$assetType)->where('asset_id',$assetId)->whereNull('deleted_at')->get()->each(fn($row)=>$events->push($this->event('financial_'.$row->direction,$row->description,$row->category,$row->paid_at?:$row->occurred_on,['entry_id'=>$row->id,'amount'=>(float)$row->amount])));
        DB::table('asset_audit_events')->where('app_id',$this->context->id())->where('asset_type',$assetType)->where('asset_id',$assetId)->get()->each(fn($row)=>$events->push($this->event('audit_'.$row->event_type,$row->title,$row->description,$row->occurred_at,['audit_id'=>$row->id])));
        return response()->json(['events'=>$events->filter(fn($event)=>!empty($event['at']))->sortByDesc('at')->values()]);
    }

    private function validateMaintenance(Request $request, bool $partial): array
    {
        $r=$partial?'sometimes|':''; return $request->validate(['lease_id'=>'sometimes|nullable|integer|min:1','space_id'=>'sometimes|nullable|integer|min:1','title'=>$r.'required|string|max:190','description'=>'sometimes|nullable|string|max:10000','status'=>'sometimes|required|in:open,in_progress,waiting,completed,cancelled','priority'=>'sometimes|required|in:low,normal,high,urgent','due_at'=>'sometimes|nullable|date','vendor'=>'sometimes|nullable|string|max:190','estimated_cost'=>'sometimes|nullable|numeric|min:0|max:999999999','actual_cost'=>'sometimes|nullable|numeric|min:0|max:999999999','notes'=>'sometimes|nullable|string|max:10000']);
    }
    private function assertOptionalLease(int $assetId,mixed $leaseId):void{if($leaseId===null||$leaseId==='')return;abort_unless(DB::table('leases')->where('app_id',$this->context->id())->where('property_id',$assetId)->where('id',(int)$leaseId)->whereNull('deleted_at')->exists(),422,'A locação não pertence a este patrimônio.');}
    private function assertSpace(string $assetType,int $assetId,int $spaceId):void{abort_unless(DB::table('asset_spaces')->where('app_id',$this->context->id())->where('asset_type',$assetType)->where('asset_id',$assetId)->where('id',$spaceId)->whereNull('deleted_at')->exists(),422,'O ambiente não pertence a este patrimônio.');}
    private function operationPayload(object $row):array{$meta=$this->decode($row->payload);return array_merge((array)$row,['payload'=>$meta,'vendor'=>$meta['vendor']??null,'estimated_cost'=>isset($meta['estimated_cost'])?(float)$meta['estimated_cost']:null,'actual_cost'=>isset($meta['actual_cost'])?(float)$meta['actual_cost']:null,'notes'=>$meta['notes']??null,'space_id'=>$meta['space_id']??null,'preventive_plan_id'=>$meta['preventive_plan_id']??null,'amount'=>$row->amount!==null?(float)$row->amount:null]);}
    private function event(string $type,string $title,?string $description,mixed $at,array $data):array{return compact('type','title','description','at','data');}
    private function allows(array $permissions,string $permission):bool{return in_array('*',$permissions,true)||in_array($permission,$permissions,true);}
    private function describeChanges(array $before,array $after):string{$labels=['name'=>'nome','status'=>'status','default_rent_amount'=>'aluguel de referência','street'=>'endereço','city'=>'cidade','bedrooms'=>'quartos','bathrooms'=>'banheiros','parking_spaces'=>'vagas','area_m2'=>'área'];$changes=[];foreach($labels as $key=>$label){if(($before[$key]??null)!==($after[$key]??null))$changes[]=$label.': '.($before[$key]??'—').' → '.($after[$key]??'—');}return $changes?implode('; ',$changes):'Informações patrimoniais atualizadas.';}
    private function decodeColumns(array $data,array $columns):array{foreach($columns as $column){if(!array_key_exists($column,$data)||is_array($data[$column]))continue;$data[$column]=$this->decode($data[$column]);if($column==='metadata'&&$data[$column]===[])$data[$column]=null;}return $data;}
    private function decode(mixed $value):array{if(is_array($value))return $value;if($value===null||$value==='')return[];$d=json_decode((string)$value,true);return is_array($d)?$d:[];}
    private function json(mixed $value):?string{return $value===null?null:json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
}
