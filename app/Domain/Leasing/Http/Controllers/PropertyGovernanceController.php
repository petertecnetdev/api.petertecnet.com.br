<?php

namespace App\Domain\Leasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class PropertyGovernanceController extends Controller
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function documents(Request $request, int $propertyId)
    {
        $this->assertPropertyAccess($request, $propertyId);
        $rows = DB::table('property_documents')->where('app_id', $this->context->id())->where('property_id', $propertyId)->whereNull('deleted_at')->orderByDesc('id')->get();
        return response()->json($rows->map(fn ($row) => $this->decodeDocument($row)));
    }

    public function uploadDocument(Request $request, int $propertyId)
    {
        $this->assertPropertyManager($request, $propertyId);
        $data = $request->validate([
            'category' => 'required|in:deed,registration,tax,condo,insurance,utility,maintenance,inspection,contract,other',
            'lease_id' => 'nullable|integer',
            'sensitive' => 'nullable|boolean',
            'retention_until' => 'nullable|date',
            'file' => 'required|file|max:20480|mimes:pdf,jpg,jpeg,png,webp,doc,docx',
        ]);
        if (! empty($data['lease_id'])) {
            abort_unless(DB::table('leases')->where('app_id', $this->context->id())->where('property_id', $propertyId)->where('id', $data['lease_id'])->exists(), 422, 'A locação não pertence a este imóvel.');
        }
        $file = $request->file('file');
        $disk = config('filesystems.default', 'local');
        $path = $file->store('property-documents/'.$this->context->id().'/'.$propertyId, $disk);
        $sha = hash_file('sha256', $file->getRealPath());
        $id = DB::table('property_documents')->insertGetId([
            'public_id' => (string) Str::uuid(), 'app_id' => $this->context->id(), 'property_id' => $propertyId,
            'lease_id' => $data['lease_id'] ?? null, 'uploaded_by_user_id' => $request->user()->id,
            'category' => $data['category'], 'name' => $file->getClientOriginalName(), 'disk' => $disk, 'path' => $path,
            'mime_type' => $file->getMimeType(), 'size' => $file->getSize(), 'sha256' => $sha,
            'sensitive' => (bool) ($data['sensitive'] ?? false), 'retention_until' => $data['retention_until'] ?? null,
            'status' => 'active', 'metadata' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit($request, 'property_document', $id, 'uploaded', ['property_id' => $propertyId, 'sha256' => $sha]);
        return response()->json($this->decodeDocument(DB::table('property_documents')->where('id', $id)->first()), 201);
    }

    public function downloadDocument(Request $request, int $propertyId, int $documentId)
    {
        $this->assertPropertyAccess($request, $propertyId);
        $document = DB::table('property_documents')->where('app_id', $this->context->id())->where('property_id', $propertyId)->where('id', $documentId)->whereNull('deleted_at')->firstOrFail();
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404, 'Arquivo não encontrado.');
        $this->audit($request, 'property_document', $documentId, 'downloaded', ['property_id' => $propertyId, 'sensitive' => (bool) $document->sensitive]);
        return Storage::disk($document->disk)->download($document->path, $document->name);
    }

    public function deleteDocument(Request $request, int $propertyId, int $documentId)
    {
        $this->assertPropertyManager($request, $propertyId);
        $document = DB::table('property_documents')->where('app_id', $this->context->id())->where('property_id', $propertyId)->where('id', $documentId)->whereNull('deleted_at')->firstOrFail();
        DB::table('property_documents')->where('id', $documentId)->update(['deleted_at' => now(), 'status' => 'deleted', 'updated_at' => now()]);
        $this->audit($request, 'property_document', $documentId, 'deleted', ['property_id' => $propertyId, 'sha256' => $document->sha256]);
        return response()->json(['ok' => true]);
    }

    public function availability(Request $request, int $propertyId)
    {
        $this->assertPropertyAccess($request, $propertyId);
        return response()->json(DB::table('property_availability_blocks')->where('app_id', $this->context->id())->where('property_id', $propertyId)->orderByDesc('starts_on')->get()->map(fn ($row) => $this->decodeRow($row)));
    }

    public function storeAvailability(Request $request, int $propertyId)
    {
        $this->assertPropertyManager($request, $propertyId);
        $data = $request->validate([
            'type' => 'required|in:maintenance,renovation,reservation,inspection,owner_use,other',
            'status' => 'nullable|in:planned,active,completed,cancelled',
            'starts_on' => 'required|date', 'ends_on' => 'required|date|after_or_equal:starts_on',
            'reason' => 'nullable|string|max:2000', 'lease_id' => 'nullable|integer', 'metadata' => 'nullable|array',
        ]);
        $conflict = DB::table('leases')->where('app_id', $this->context->id())->where('property_id', $propertyId)->whereNull('deleted_at')->whereNotIn('status', ['ended', 'cancelled'])
            ->whereDate('starts_on', '<=', $data['ends_on'])->whereDate('ends_on', '>=', $data['starts_on'])->exists();
        abort_if($conflict && ! in_array($data['type'], ['inspection'], true), 422, 'O período conflita com uma locação existente.');
        $id = DB::table('property_availability_blocks')->insertGetId([
            'public_id' => (string) Str::uuid(), 'app_id' => $this->context->id(), 'property_id' => $propertyId,
            'lease_id' => $data['lease_id'] ?? null, 'created_by_user_id' => $request->user()->id, 'type' => $data['type'],
            'status' => $data['status'] ?? 'planned', 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'],
            'reason' => $data['reason'] ?? null, 'metadata' => $this->json($data['metadata'] ?? null), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json($this->decodeRow(DB::table('property_availability_blocks')->where('id', $id)->first()), 201);
    }

    public function deleteAvailability(Request $request, int $propertyId, int $blockId)
    {
        $this->assertPropertyManager($request, $propertyId);
        $block = DB::table('property_availability_blocks')->where('app_id', $this->context->id())->where('property_id', $propertyId)->where('id', $blockId)->firstOrFail();
        DB::table('property_availability_blocks')->where('id', $block->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
        return response()->json(['ok' => true]);
    }

    public function updateInspection(Request $request, int $propertyId, int $inspectionId)
    {
        $this->assertPropertyManager($request, $propertyId);
        $inspection = $this->inspection($propertyId, $inspectionId);
        abort_if($inspection->status === 'finalized', 422, 'Uma vistoria finalizada é imutável.');
        $data = $request->validate([
            'summary' => 'nullable|string|max:10000', 'checklist' => 'nullable|array|max:300', 'meter_readings' => 'nullable|array|max:30',
            'comparison_inspection_id' => 'nullable|integer', 'items' => 'nullable|array', 'metadata' => 'nullable|array',
        ]);
        foreach (['checklist', 'meter_readings', 'items', 'metadata'] as $field) if (array_key_exists($field, $data)) $data[$field] = $this->json($data[$field]);
        $data['updated_at'] = now();
        DB::table('property_inspections')->where('id', $inspectionId)->update($data);
        return response()->json($this->decodeInspection(DB::table('property_inspections')->where('id', $inspectionId)->first()));
    }

    public function signInspection(Request $request, int $propertyId, int $inspectionId)
    {
        $inspection = $this->inspection($propertyId, $inspectionId);
        $property = $this->assertPropertyAccess($request, $propertyId);
        abort_if($inspection->status === 'finalized', 422, 'A vistoria já foi finalizada.');
        $data = $request->validate(['party' => 'required|in:landlord,tenant', 'accepted' => 'required|accepted']);
        $userId = (int) $request->user()->id;
        if ($data['party'] === 'landlord') abort_unless((int) $property->owner_user_id === $userId || $this->isAdmin($request), 403);
        if ($data['party'] === 'tenant') {
            abort_unless($inspection->lease_id, 422, 'Esta vistoria não está vinculada a uma locação.');
            $lease = DB::table('leases')->where('app_id', $this->context->id())->where('id', $inspection->lease_id)->firstOrFail();
            $tenant = (int) $lease->tenant_user_id === $userId || ($lease->tenant_email && strcasecmp($lease->tenant_email, (string) $request->user()->email) === 0);
            abort_unless($tenant || $this->isAdmin($request), 403);
        }
        $column = $data['party'] === 'landlord' ? 'landlord_signed_at' : 'tenant_signed_at';
        DB::table('property_inspections')->where('id', $inspectionId)->update([$column => now(), 'updated_at' => now()]);
        return response()->json($this->decodeInspection(DB::table('property_inspections')->where('id', $inspectionId)->first()));
    }

    public function finalizeInspection(Request $request, int $propertyId, int $inspectionId)
    {
        $this->assertPropertyManager($request, $propertyId);
        $inspection = $this->inspection($propertyId, $inspectionId);
        abort_if($inspection->status === 'finalized', 422, 'A vistoria já foi finalizada.');
        $evidence = $this->inspectionEvidence($inspection);
        DB::table('property_inspections')->where('id', $inspectionId)->update(['status' => 'finalized', 'evidence_sha256' => hash('sha256', $evidence), 'finalized_at' => now(), 'updated_at' => now()]);
        $this->audit($request, 'property_inspection', $inspectionId, 'finalized', ['property_id' => $propertyId]);
        return response()->json($this->decodeInspection(DB::table('property_inspections')->where('id', $inspectionId)->first()));
    }

    public function compareInspections(Request $request, int $propertyId)
    {
        $this->assertPropertyAccess($request, $propertyId);
        $data = $request->validate(['from' => 'required|integer', 'to' => 'required|integer|different:from']);
        $from = $this->decodeInspection($this->inspection($propertyId, (int) $data['from']));
        $to = $this->decodeInspection($this->inspection($propertyId, (int) $data['to']));
        return response()->json(['from' => $from, 'to' => $to, 'differences' => $this->inspectionDiff($from, $to)]);
    }

    private function inspectionDiff(array $from, array $to): array
    {
        $diff = ['checklist' => [], 'meter_readings' => []];
        $fromChecklist = collect($from['checklist'] ?? [])->keyBy(fn ($item) => $item['key'] ?? $item['name'] ?? Str::uuid()->toString());
        $toChecklist = collect($to['checklist'] ?? [])->keyBy(fn ($item) => $item['key'] ?? $item['name'] ?? Str::uuid()->toString());
        foreach ($toChecklist as $key => $item) {
            $previous = $fromChecklist->get($key);
            if ($previous != $item) $diff['checklist'][] = ['key' => $key, 'before' => $previous, 'after' => $item];
        }
        foreach (($to['meter_readings'] ?? []) as $key => $value) {
            $previous = data_get($from, 'meter_readings.'.$key);
            if ($previous != $value) $diff['meter_readings'][$key] = ['before' => $previous, 'after' => $value];
        }
        return $diff;
    }

    private function inspectionEvidence(object $inspection): string
    {
        $payload = (array) $inspection;
        unset($payload['evidence_sha256'], $payload['updated_at']);
        ksort($payload);
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function inspection(int $propertyId, int $inspectionId): object
    {
        return DB::table('property_inspections')->where('app_id', $this->context->id())->where('property_id', $propertyId)->where('id', $inspectionId)->firstOrFail();
    }

    private function assertPropertyAccess(Request $request, int $propertyId): object
    {
        $property = DB::table('properties')->where('app_id', $this->context->id())->where('id', $propertyId)->whereNull('deleted_at')->firstOrFail();
        $userId = (int) $request->user()->id;
        $leaseAccess = DB::table('leases')->where('app_id', $this->context->id())->where('property_id', $propertyId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('tenant_user_id', $userId)->orWhere('tenant_email', $request->user()->email))->exists();
        $grant = DB::table('leasing_access_grants')->where('app_id', $this->context->id())->where('user_id', $userId)->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->where('property_id', $propertyId)->orWhereIn('lease_id', DB::table('leases')->select('id')->where('app_id', $this->context->id())->where('property_id', $propertyId)))->exists();
        abort_unless((int) $property->owner_user_id === $userId || $leaseAccess || $grant || $this->isAdmin($request), 403);
        return $property;
    }

    private function assertPropertyManager(Request $request, int $propertyId): object
    {
        $property = $this->assertPropertyAccess($request, $propertyId);
        $userId = (int) $request->user()->id;
        $managerGrant = DB::table('leasing_access_grants')->where('app_id', $this->context->id())->where('user_id', $userId)->where('property_id', $propertyId)->whereNull('revoked_at')->whereIn('role', ['owner', 'manager', 'proxy'])->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
        abort_unless((int) $property->owner_user_id === $userId || $managerGrant || $this->isAdmin($request), 403);
        return $property;
    }

    private function audit(Request $request, string $entityType, int $entityId, string $action, array $data = []): void
    {
        if (! DB::getSchemaBuilder()->hasTable('interactions')) return;
        DB::table('interactions')->insert([
            'user_id' => $request->user()->id,
            'app_id' => $this->context->id(),
            'type' => $action,
            'description' => $entityType.' '.$action,
            'metadata' => $this->json(array_merge(['entity_type' => $entityType, 'entity_id' => $entityId], $data)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function decodeDocument(object $row): array { return $this->decodeRow($row); }
    private function decodeInspection(object $row): array { return $this->decodeRow($row); }
    private function decodeRow(object $row): array
    {
        $data = (array) $row;
        foreach (['metadata', 'checklist', 'meter_readings', 'items'] as $field) if (array_key_exists($field, $data)) $data[$field] = $this->decode($data[$field]);
        return $data;
    }
    private function decode($value): array
    {
        if (is_array($value)) return $value; if (is_object($value)) return (array) $value; if (! $value) return [];
        $decoded = json_decode((string) $value, true); return is_array($decoded) ? $decoded : [];
    }
    private function json($value): ?string { return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    private function isAdmin(Request $request): bool { return method_exists($request->user(), 'hasProfile') && $request->user()->hasProfile('Administrador'); }
}
