<?php

namespace App\Domain\Leasing\Http\Controllers;

use App\Domain\Leasing\Services\LeaseLifecycleService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class PropertyWorkspaceController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly LeaseLifecycleService $lifecycle,
    ) {}

    public function show(Request $request, int $propertyId)
    {
        $property = $this->assertPropertyOwner($request, $propertyId);
        $leases = $this->propertyLeases($propertyId);
        $augmentedLeases = $leases->map(fn ($lease) => array_merge(
            $this->decodeJsonColumns((array) $lease, ['clauses', 'included_expenses', 'tenant_expenses', 'metadata']),
            $this->lifecycle->evaluate($lease),
        ));

        $currentLease = $augmentedLeases->first(fn (array $lease) => (bool) ($lease['is_in_force'] ?? false));
        if (! $currentLease) {
            $currentLease = $augmentedLeases->first(fn (array $lease) => in_array($lease['vigency_status'] ?? '', ['awaiting_signature', 'future'], true));
        }

        $state = $this->lifecycle->effectivePropertyState($property, $leases);
        $propertyData = array_merge(
            $this->decodeJsonColumns((array) $property, ['metadata']),
            $state,
        );
        $propertyData['stored_status'] = $property->status;
        $propertyData['status'] = $state['effective_status'];

        $leaseIds = $leases->pluck('id');
        $summary = [
            'leases_total' => $leases->count(),
            'inspections_total' => DB::table('property_inspections')
                ->where('app_id', $this->context->id())
                ->where('property_id', $propertyId)
                ->count(),
            'assets_total' => DB::table('files')
                ->where('app_id', $this->context->id())
                ->where('entity_name', 'property')
                ->where('entity_id', $propertyId)
                ->where('status', 'active')
                ->count(),
            'open_maintenance' => DB::table('lease_operations')
                ->where('app_id', $this->context->id())
                ->where('property_id', $propertyId)
                ->where('type', 'maintenance')
                ->whereIn('status', ['open', 'in_progress', 'waiting'])
                ->whereNull('deleted_at')
                ->count(),
            'pending_amount' => $leaseIds->isEmpty() ? 0.0 : round((float) DB::table('lease_charges')
                ->where('app_id', $this->context->id())
                ->whereIn('lease_id', $leaseIds)
                ->whereIn('status', ['pending', 'processing'])
                ->sum('amount'), 2),
            'overdue_amount' => $leaseIds->isEmpty() ? 0.0 : round((float) DB::table('lease_charges')
                ->where('app_id', $this->context->id())
                ->whereIn('lease_id', $leaseIds)
                ->whereIn('status', ['pending', 'processing'])
                ->whereDate('due_date', '<', today())
                ->sum('amount'), 2),
        ];

        return response()->json([
            'property' => $propertyData,
            'current_lease' => $currentLease,
            'leases' => $augmentedLeases->values(),
            'summary' => $summary,
        ]);
    }

    public function financial(Request $request, int $propertyId)
    {
        $this->assertPropertyOwner($request, $propertyId);
        $leaseIds = $this->propertyLeases($propertyId)->pluck('id');

        if ($leaseIds->isEmpty()) {
            return response()->json([
                'summary' => [
                    'pending' => 0.0,
                    'overdue' => 0.0,
                    'paid_total' => 0.0,
                    'paid_this_year' => 0.0,
                    'scheduled_total' => 0.0,
                    'next_due' => null,
                ],
                'charges' => [],
            ]);
        }

        $base = DB::table('lease_charges as c')
            ->join('leases as l', function ($join) {
                $join->on('l.id', '=', 'c.lease_id')->on('l.app_id', '=', 'c.app_id');
            })
            ->where('c.app_id', $this->context->id())
            ->whereIn('c.lease_id', $leaseIds);

        $pending = (clone $base)->whereIn('c.status', ['pending', 'processing'])->sum('c.amount');
        $overdue = (clone $base)->whereIn('c.status', ['pending', 'processing'])->whereDate('c.due_date', '<', today())->sum('c.amount');
        $paidTotal = (clone $base)->where('c.status', 'paid')->sum('c.amount');
        $paidThisYear = (clone $base)->where('c.status', 'paid')->whereYear('c.paid_at', now()->year)->sum('c.amount');
        $scheduledTotal = (clone $base)->sum('c.amount');
        $nextDue = (clone $base)->whereIn('c.status', ['pending', 'processing'])->orderBy('c.due_date')->first([
            'c.id', 'c.lease_id', 'c.description', 'c.due_date', 'c.amount', 'c.status', 'l.tenant_name',
        ]);

        $charges = (clone $base)
            ->orderByDesc('c.due_date')
            ->limit(180)
            ->get([
                'c.id', 'c.public_id', 'c.lease_id', 'c.type', 'c.description', 'c.reference_date',
                'c.due_date', 'c.amount', 'c.status', 'c.payment_method', 'c.paid_at', 'l.tenant_name',
            ]);

        return response()->json([
            'summary' => [
                'pending' => round((float) $pending, 2),
                'overdue' => round((float) $overdue, 2),
                'paid_total' => round((float) $paidTotal, 2),
                'paid_this_year' => round((float) $paidThisYear, 2),
                'scheduled_total' => round((float) $scheduledTotal, 2),
                'next_due' => $nextDue,
            ],
            'charges' => $charges,
        ]);
    }

    public function maintenance(Request $request, int $propertyId)
    {
        $this->assertPropertyOwner($request, $propertyId);

        $items = DB::table('lease_operations')
            ->where('app_id', $this->context->id())
            ->where('property_id', $propertyId)
            ->where('type', 'maintenance')
            ->whereNull('deleted_at')
            ->orderByRaw("FIELD(priority, 'urgent', 'high', 'normal', 'low')")
            ->orderByRaw("FIELD(status, 'open', 'in_progress', 'waiting', 'completed', 'cancelled')")
            ->orderByDesc('id')
            ->get()
            ->map(fn ($row) => $this->operationPayload($row));

        return response()->json(['items' => $items]);
    }

    public function storeMaintenance(Request $request, int $propertyId)
    {
        $this->assertPropertyOwner($request, $propertyId);
        $data = $this->validateMaintenance($request, false);
        $leaseId = $this->validateOptionalLease($propertyId, $data['lease_id'] ?? null);
        $status = $data['status'] ?? 'open';
        $payload = $this->maintenanceMeta($data);

        $id = DB::table('lease_operations')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'app_id' => $this->context->id(),
            'lease_id' => $leaseId,
            'property_id' => $propertyId,
            'actor_user_id' => $request->user()->id,
            'type' => 'maintenance',
            'status' => $status,
            'priority' => $data['priority'] ?? 'normal',
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'due_at' => $data['due_at'] ?? null,
            'occurred_at' => now(),
            'completed_at' => $status === 'completed' ? now() : null,
            'amount' => $data['actual_cost'] ?? $data['estimated_cost'] ?? null,
            'payload' => $this->json($payload),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('lease_operations')->where('app_id', $this->context->id())->where('id', $id)->first();
        return response()->json(['item' => $this->operationPayload($row)], 201);
    }

    public function updateMaintenance(Request $request, int $propertyId, int $operationId)
    {
        $this->assertPropertyOwner($request, $propertyId);
        $operation = DB::table('lease_operations')
            ->where('app_id', $this->context->id())
            ->where('property_id', $propertyId)
            ->where('id', $operationId)
            ->where('type', 'maintenance')
            ->whereNull('deleted_at')
            ->first();
        abort_unless($operation, 404, 'Manutenção não encontrada.');

        $data = $this->validateMaintenance($request, true);
        $update = [];
        foreach (['status', 'priority', 'title', 'description', 'due_at'] as $field) {
            if (array_key_exists($field, $data)) $update[$field] = $data[$field];
        }
        if (array_key_exists('lease_id', $data)) $update['lease_id'] = $this->validateOptionalLease($propertyId, $data['lease_id']);

        $meta = array_merge($this->decode($operation->payload), $this->maintenanceMeta($data));
        $update['payload'] = $this->json($meta);
        if (array_key_exists('actual_cost', $data) || array_key_exists('estimated_cost', $data)) {
            $update['amount'] = $data['actual_cost'] ?? $data['estimated_cost'] ?? $operation->amount;
        }
        if (($data['status'] ?? null) === 'completed' && ! $operation->completed_at) $update['completed_at'] = now();
        if (isset($data['status']) && $data['status'] !== 'completed') $update['completed_at'] = null;
        $update['updated_at'] = now();

        DB::table('lease_operations')
            ->where('app_id', $this->context->id())
            ->where('id', $operationId)
            ->update($update);

        $row = DB::table('lease_operations')->where('app_id', $this->context->id())->where('id', $operationId)->first();
        return response()->json(['item' => $this->operationPayload($row)]);
    }

    public function assets(Request $request, int $propertyId)
    {
        $this->assertPropertyOwner($request, $propertyId);
        $items = DB::table('files')
            ->where('app_id', $this->context->id())
            ->where('entity_name', 'property')
            ->where('entity_id', $propertyId)
            ->where('status', 'active')
            ->orderByDesc('is_primary')
            ->orderBy('position')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($file) => $this->assetPayload($file));

        return response()->json(['items' => $items]);
    }

    public function storeAsset(Request $request, int $propertyId)
    {
        $this->assertPropertyOwner($request, $propertyId);
        $data = $request->validate([
            'file' => 'required|file|max:20480|mimes:jpg,jpeg,png,webp,gif,pdf,txt,csv,doc,docx,xls,xlsx,zip',
            'group' => 'nullable|in:photos,documents,other',
            'is_primary' => 'nullable|boolean',
            'position' => 'nullable|integer|min:0|max:100000',
        ]);

        $uploaded = $request->file('file');
        $mime = (string) ($uploaded->getMimeType() ?: 'application/octet-stream');
        $extension = strtolower((string) $uploaded->getClientOriginalExtension());
        $uuid = (string) Str::uuid();
        $appId = $this->context->id();
        $directory = "leasing/properties/{$appId}/{$propertyId}";
        $storedName = $uuid.($extension !== '' ? '.'.$extension : '');
        $path = $uploaded->storeAs($directory, $storedName, 'local');
        $isImage = str_starts_with($mime, 'image/');
        $isPrimary = (bool) ($data['is_primary'] ?? false);

        if ($isPrimary) $this->clearPrimaryAsset($propertyId);

        $id = DB::table('files')->insertGetId([
            'uuid' => $uuid,
            'app_id' => $appId,
            'entity_id' => $propertyId,
            'entity_name' => 'property',
            'fileable_id' => $propertyId,
            'fileable_type' => 'property',
            'original_name' => mb_substr(basename((string) $uploaded->getClientOriginalName()), 0, 255),
            'extension' => $extension ?: null,
            'mime_type' => $mime,
            'file_size' => $uploaded->getSize() ?: 0,
            'content_hash' => hash_file('sha256', Storage::disk('local')->path($path)),
            'type' => $isImage ? 'image' : 'file',
            'storage' => 'local',
            'path' => $path,
            'storage_path' => null,
            'public_url' => null,
            'group' => $data['group'] ?? ($isImage ? 'photos' : 'documents'),
            'sort_order' => $data['position'] ?? 0,
            'position' => $data['position'] ?? 0,
            'is_primary' => $isPrimary,
            'processed' => true,
            'visibility' => 'private',
            'visibility_scope' => 'owner',
            'status' => 'active',
            'source' => 'upload',
            'version' => '1',
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $file = DB::table('files')->where('app_id', $appId)->where('id', $id)->first();
        return response()->json(['item' => $this->assetPayload($file)], 201);
    }

    public function updateAsset(Request $request, int $propertyId, int $assetId)
    {
        $this->assertPropertyOwner($request, $propertyId);
        $file = $this->assertPropertyAsset($propertyId, $assetId);
        $data = $request->validate([
            'group' => 'sometimes|required|in:photos,documents,other',
            'is_primary' => 'sometimes|boolean',
            'position' => 'sometimes|integer|min:0|max:100000',
        ]);

        if (($data['is_primary'] ?? false) === true) $this->clearPrimaryAsset($propertyId);
        $update = $data;
        if (array_key_exists('position', $update)) $update['sort_order'] = $update['position'];
        $update['updated_by'] = $request->user()->id;
        $update['updated_at'] = now();
        DB::table('files')->where('app_id', $this->context->id())->where('id', $file->id)->update($update);

        $updated = DB::table('files')->where('app_id', $this->context->id())->where('id', $file->id)->first();
        return response()->json(['item' => $this->assetPayload($updated)]);
    }

    public function downloadAsset(Request $request, int $propertyId, int $assetId)
    {
        $this->assertPropertyOwner($request, $propertyId);
        $file = $this->assertPropertyAsset($propertyId, $assetId);
        abort_if($file->storage === 'external', 422, 'Arquivo externo não está disponível para download protegido.');
        $disk = $file->storage ?: 'local';
        abort_unless(Storage::disk($disk)->exists($file->path), 404, 'Arquivo não encontrado no armazenamento.');

        DB::table('files')->where('app_id', $this->context->id())->where('id', $file->id)->update([
            'download_count' => DB::raw('download_count + 1'),
            'last_downloaded_at' => now(),
            'updated_at' => now(),
        ]);

        return Storage::disk($disk)->download($file->path, basename((string) ($file->original_name ?: 'arquivo')));
    }

    public function deleteAsset(Request $request, int $propertyId, int $assetId)
    {
        $this->assertPropertyOwner($request, $propertyId);
        $file = $this->assertPropertyAsset($propertyId, $assetId);
        if ($file->storage !== 'external' && $file->path) {
            Storage::disk($file->storage ?: 'local')->delete($file->path);
        }
        DB::table('files')->where('app_id', $this->context->id())->where('id', $file->id)->delete();

        return response()->json(['ok' => true]);
    }

    public function timeline(Request $request, int $propertyId)
    {
        $property = $this->assertPropertyOwner($request, $propertyId);
        $appId = $this->context->id();
        $leases = $this->propertyLeases($propertyId);
        $leaseIds = $leases->pluck('id');
        $events = collect();

        $events->push($this->event('property_created', 'Imóvel cadastrado', 'O imóvel entrou na carteira.', $property->created_at));
        if ($property->updated_at && $property->updated_at !== $property->created_at) {
            $events->push($this->event('property_updated', 'Dados do imóvel atualizados', 'Informações patrimoniais foram alteradas.', $property->updated_at));
        }

        foreach ($leases as $lease) {
            $events->push($this->event('lease_created', 'Locação criada', 'Inquilino: '.$lease->tenant_name, $lease->created_at, ['lease_id' => $lease->id]));
            if ($lease->starts_on) $events->push($this->event('lease_start', 'Início da locação', 'Vigência iniciada para '.$lease->tenant_name, $lease->starts_on, ['lease_id' => $lease->id]));
            if ($lease->ends_on) $events->push($this->event('lease_end', 'Fim previsto da locação', 'Data contratual de encerramento.', $lease->ends_on, ['lease_id' => $lease->id]));
            if ($lease->activated_at) $events->push($this->event('lease_activated', 'Locação ativada', 'Contrato e onboarding concluídos.', $lease->activated_at, ['lease_id' => $lease->id]));
            if ($lease->ended_at) $events->push($this->event('lease_ended', 'Locação encerrada', 'Vigência encerrada.', $lease->ended_at, ['lease_id' => $lease->id]));
        }

        DB::table('property_inspections')
            ->where('app_id', $appId)
            ->where('property_id', $propertyId)
            ->orderByDesc('occurred_at')
            ->get()
            ->each(function ($inspection) use ($events) {
                $label = match ($inspection->type) {
                    'entry' => 'Vistoria de entrada',
                    'exit' => 'Vistoria de saída',
                    default => 'Vistoria periódica',
                };
                $events->push($this->event('inspection', $label, $inspection->summary ?: 'Vistoria registrada.', $inspection->occurred_at, ['inspection_id' => $inspection->id, 'lease_id' => $inspection->lease_id]));
            });

        DB::table('lease_operations')
            ->where('app_id', $appId)
            ->where('property_id', $propertyId)
            ->whereNull('deleted_at')
            ->get()
            ->each(function ($operation) use ($events) {
                $events->push($this->event('operation_'.$operation->type, $operation->title, $operation->description ?: ucfirst(str_replace('_', ' ', $operation->type)), $operation->occurred_at ?: $operation->created_at, ['operation_id' => $operation->id, 'lease_id' => $operation->lease_id, 'status' => $operation->status]));
                if ($operation->completed_at) $events->push($this->event('operation_completed', 'Concluído: '.$operation->title, $operation->description ?: null, $operation->completed_at, ['operation_id' => $operation->id]));
            });

        DB::table('files')
            ->where('app_id', $appId)
            ->where('entity_name', 'property')
            ->where('entity_id', $propertyId)
            ->where('status', 'active')
            ->get()
            ->each(fn ($file) => $events->push($this->event('asset_uploaded', 'Arquivo adicionado', $file->original_name ?: 'Arquivo do imóvel', $file->created_at, ['asset_id' => $file->id, 'group' => $file->group])));

        if (! $leaseIds->isEmpty()) {
            DB::table('lease_signatures')->where('app_id', $appId)->whereIn('lease_id', $leaseIds)->get()->each(function ($signature) use ($events) {
                $events->push($this->event('signature', 'Contrato assinado', ($signature->party === 'landlord' ? 'Locador' : 'Inquilino').' · '.$signature->signer_name, $signature->signed_at, ['lease_id' => $signature->lease_id]));
            });
            DB::table('lease_charges')->where('app_id', $appId)->whereIn('lease_id', $leaseIds)->whereNotNull('paid_at')->get()->each(function ($charge) use ($events) {
                $events->push($this->event('payment_received', 'Pagamento recebido', $charge->description, $charge->paid_at, ['lease_id' => $charge->lease_id, 'charge_id' => $charge->id, 'amount' => (float) $charge->amount]));
            });
        }

        return response()->json(['events' => $events->filter(fn ($event) => ! empty($event['at']))->sortByDesc('at')->values()]);
    }

    private function assertPropertyOwner(Request $request, int $propertyId): object
    {
        $property = DB::table('properties')
            ->where('app_id', $this->context->id())
            ->where('id', $propertyId)
            ->where('owner_user_id', $request->user()->id)
            ->whereNull('deleted_at')
            ->first();
        abort_unless($property, 404, 'Imóvel não encontrado.');
        return $property;
    }

    private function propertyLeases(int $propertyId)
    {
        return DB::table('leases')
            ->where('app_id', $this->context->id())
            ->where('property_id', $propertyId)
            ->whereNull('deleted_at')
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get();
    }

    private function validateMaintenance(Request $request, bool $partial): array
    {
        $prefix = $partial ? 'sometimes|' : '';
        return $request->validate([
            'lease_id' => 'sometimes|nullable|integer|min:1',
            'title' => $prefix.'required|string|max:190',
            'description' => 'sometimes|nullable|string|max:10000',
            'status' => 'sometimes|required|in:open,in_progress,waiting,completed,cancelled',
            'priority' => 'sometimes|required|in:low,normal,high,urgent',
            'due_at' => 'sometimes|nullable|date',
            'vendor' => 'sometimes|nullable|string|max:190',
            'estimated_cost' => 'sometimes|nullable|numeric|min:0|max:999999999',
            'actual_cost' => 'sometimes|nullable|numeric|min:0|max:999999999',
            'notes' => 'sometimes|nullable|string|max:10000',
        ]);
    }

    private function validateOptionalLease(int $propertyId, mixed $leaseId): ?int
    {
        if ($leaseId === null || $leaseId === '') return null;
        $leaseId = (int) $leaseId;
        abort_unless(DB::table('leases')
            ->where('app_id', $this->context->id())
            ->where('property_id', $propertyId)
            ->where('id', $leaseId)
            ->whereNull('deleted_at')
            ->exists(), 422, 'A locação informada não pertence a este imóvel.');
        return $leaseId;
    }

    private function maintenanceMeta(array $data): array
    {
        $meta = [];
        foreach (['vendor', 'estimated_cost', 'actual_cost', 'notes'] as $field) {
            if (array_key_exists($field, $data)) $meta[$field] = $data[$field];
        }
        return $meta;
    }

    private function operationPayload(object $row): array
    {
        $payload = $this->decode($row->payload ?? null);
        return array_merge((array) $row, [
            'payload' => $payload,
            'vendor' => $payload['vendor'] ?? null,
            'estimated_cost' => isset($payload['estimated_cost']) ? (float) $payload['estimated_cost'] : null,
            'actual_cost' => isset($payload['actual_cost']) ? (float) $payload['actual_cost'] : null,
            'notes' => $payload['notes'] ?? null,
            'amount' => $row->amount !== null ? (float) $row->amount : null,
        ]);
    }

    private function assetPayload(object $file): array
    {
        return [
            'id' => $file->id,
            'uuid' => $file->uuid,
            'original_name' => $file->original_name,
            'extension' => $file->extension,
            'mime_type' => $file->mime_type,
            'file_size' => (int) ($file->file_size ?? 0),
            'type' => $file->type,
            'group' => $file->group,
            'position' => (int) ($file->position ?? 0),
            'is_primary' => (bool) $file->is_primary,
            'visibility' => $file->visibility,
            'created_at' => $file->created_at,
            'download_url' => "/properties/{$file->entity_id}/assets/{$file->id}",
        ];
    }

    private function assertPropertyAsset(int $propertyId, int $assetId): object
    {
        $file = DB::table('files')
            ->where('app_id', $this->context->id())
            ->where('entity_name', 'property')
            ->where('entity_id', $propertyId)
            ->where('id', $assetId)
            ->where('status', 'active')
            ->first();
        abort_unless($file, 404, 'Arquivo não encontrado.');
        return $file;
    }

    private function clearPrimaryAsset(int $propertyId): void
    {
        DB::table('files')
            ->where('app_id', $this->context->id())
            ->where('entity_name', 'property')
            ->where('entity_id', $propertyId)
            ->update(['is_primary' => false, 'updated_at' => now()]);
    }

    private function event(string $type, string $title, ?string $description, mixed $at, array $data = []): array
    {
        return compact('type', 'title', 'description', 'at', 'data');
    }

    private function json(mixed $value): ?string
    {
        if ($value === null) return null;
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function decode(mixed $value): array
    {
        if (is_array($value)) return $value;
        if ($value === null || $value === '') return [];
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
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
