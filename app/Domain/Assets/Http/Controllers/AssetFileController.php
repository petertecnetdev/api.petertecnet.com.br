<?php

namespace App\Domain\Assets\Http\Controllers;

use App\Domain\Assets\Services\AssetAccessService;
use App\Domain\Assets\Services\AssetIntelligenceService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class AssetFileController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AssetAccessService $access,
        private readonly AssetIntelligenceService $intelligence,
    ) {}

    public function index(Request $request, string $assetType, int $assetId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'view');
        $query = DB::table('files')->where('app_id', $this->context->id())->where('entity_name', $assetType)->where('entity_id', $assetId)->where('status', 'active');
        if ($request->filled('group')) $query->where('group', $request->query('group'));
        $rows = $query->orderByDesc('is_primary')->orderBy('position')->orderByDesc('id')->get()->map(fn ($row) => $this->payload($row));
        if ($request->filled('context')) $rows = $rows->filter(fn ($row) => ($row['context'] ?? null) === $request->query('context'))->values();
        if ($request->filled('inspection_id')) $rows = $rows->filter(fn ($row) => (int) ($row['inspection_id'] ?? 0) === (int) $request->query('inspection_id'))->values();
        if ($request->filled('maintenance_id')) $rows = $rows->filter(fn ($row) => (int) ($row['maintenance_id'] ?? 0) === (int) $request->query('maintenance_id'))->values();
        if ($request->filled('space_id')) $rows = $rows->filter(fn ($row) => (int) ($row['space_id'] ?? 0) === (int) $request->query('space_id'))->values();

        $groups = $rows->groupBy(fn ($row) => $row['context'] ?: ($row['group'] ?: 'other'))->map(fn ($items) => $items->values())->all();
        return response()->json(['items' => $rows, 'groups' => $groups]);
    }

    public function store(Request $request, string $assetType, int $assetId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'files');
        $data = $request->validate([
            'file' => 'required|file|max:25600|mimes:jpg,jpeg,png,webp,gif,pdf,txt,csv,doc,docx,xls,xlsx,zip',
            'group' => 'nullable|in:photos,documents,other',
            'context' => 'nullable|string|max:80|regex:/^[A-Za-z0-9_.-]+$/',
            'occurred_on' => 'nullable|date',
            'inspection_id' => 'nullable|integer|min:1',
            'maintenance_id' => 'nullable|integer|min:1',
            'space_id' => 'nullable|integer|min:1',
            'caption' => 'nullable|string|max:500',
            'document_type' => 'nullable|string|max:80',
            'before_after' => 'nullable|in:before,after',
            'is_primary' => 'nullable|boolean',
            'position' => 'nullable|integer|min:0|max:100000',
            'tags' => 'nullable|array|max:30',
            'tags.*' => 'string|max:80',
        ]);
        if (! empty($data['inspection_id']) && $assetType === 'property') {
            abort_unless(DB::table('property_inspections')->where('app_id', $this->context->id())->where('property_id', $assetId)->where('id', $data['inspection_id'])->exists(), 422, 'A vistoria não pertence a este patrimônio.');
        }
        if (! empty($data['maintenance_id']) && $assetType === 'property') {
            abort_unless(DB::table('lease_operations')->where('app_id', $this->context->id())->where('property_id', $assetId)->where('id', $data['maintenance_id'])->where('type', 'maintenance')->whereNull('deleted_at')->exists(), 422, 'A manutenção não pertence a este patrimônio.');
        }
        if (! empty($data['space_id'])) {
            abort_unless(DB::table('asset_spaces')->where('app_id', $this->context->id())->where('asset_type', $assetType)->where('asset_id', $assetId)->where('id', $data['space_id'])->whereNull('deleted_at')->exists(), 422, 'O ambiente não pertence a este patrimônio.');
        }

        $uploaded = $request->file('file');
        $mime = (string) ($uploaded->getMimeType() ?: 'application/octet-stream');
        $extension = strtolower((string) $uploaded->getClientOriginalExtension());
        $uuid = (string) Str::uuid();
        $directory = "assets/{$this->context->id()}/{$assetType}/{$assetId}";
        $storedName = $uuid.($extension ? '.'.$extension : '');
        $path = $uploaded->storeAs($directory, $storedName, 'local');
        $isImage = str_starts_with($mime, 'image/');
        $isPrimary = (bool) ($data['is_primary'] ?? false);
        if ($isPrimary) $this->clearPrimary($assetType, $assetId);

        $meta = array_filter([
            'context' => $data['context'] ?? ($isImage ? 'general' : 'documents'),
            'occurred_on' => $data['occurred_on'] ?? now()->toDateString(),
            'inspection_id' => $data['inspection_id'] ?? null,
            'maintenance_id' => $data['maintenance_id'] ?? null,
            'space_id' => $data['space_id'] ?? null,
            'caption' => $data['caption'] ?? null,
            'document_type' => $data['document_type'] ?? null,
            'before_after' => $data['before_after'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        $id = DB::table('files')->insertGetId([
            'uuid' => $uuid, 'app_id' => $this->context->id(), 'entity_id' => $assetId, 'entity_name' => $assetType,
            'fileable_id' => $assetId, 'fileable_type' => $assetType, 'original_name' => mb_substr(basename((string) $uploaded->getClientOriginalName()), 0, 255),
            'extension' => $extension ?: null, 'mime_type' => $mime, 'file_size' => $uploaded->getSize() ?: 0,
            'content_hash' => hash_file('sha256', Storage::disk('local')->path($path)), 'type' => $isImage ? 'image' : 'file',
            'storage' => 'local', 'path' => $path, 'storage_path' => null, 'public_url' => null,
            'group' => $data['group'] ?? ($isImage ? 'photos' : 'documents'), 'tags' => isset($data['tags']) ? $this->json($data['tags']) : null,
            'sort_order' => $data['position'] ?? 0, 'position' => $data['position'] ?? 0, 'is_primary' => $isPrimary, 'processed' => true,
            'meta' => $this->json($meta), 'visibility' => 'private', 'visibility_scope' => 'asset_access', 'status' => 'active', 'source' => 'upload', 'version' => '1',
            'created_by' => $request->user()->id, 'updated_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $file = $this->file($assetType, $assetId, $id);
        $this->intelligence->audit($assetType, $assetId, (int) $request->user()->id, 'file_uploaded', 'Arquivo adicionado ao patrimônio', $file->original_name, null, $this->payload($file), ['file_id' => $id]);
        return response()->json(['item' => $this->payload($file)], 201);
    }

    public function update(Request $request, string $assetType, int $assetId, int $fileId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'files');
        $file = $this->file($assetType, $assetId, $fileId); $before = $this->payload($file);
        $data = $request->validate([
            'group' => 'sometimes|required|in:photos,documents,other', 'context' => 'sometimes|nullable|string|max:80|regex:/^[A-Za-z0-9_.-]+$/',
            'occurred_on' => 'sometimes|nullable|date', 'inspection_id' => 'sometimes|nullable|integer|min:1', 'maintenance_id' => 'sometimes|nullable|integer|min:1',
            'space_id' => 'sometimes|nullable|integer|min:1', 'caption' => 'sometimes|nullable|string|max:500', 'document_type' => 'sometimes|nullable|string|max:80',
            'before_after' => 'sometimes|nullable|in:before,after', 'is_primary' => 'sometimes|boolean', 'position' => 'sometimes|integer|min:0|max:100000',
            'tags' => 'sometimes|nullable|array|max:30', 'tags.*' => 'string|max:80',
        ]);
        if (($data['is_primary'] ?? false) === true) $this->clearPrimary($assetType, $assetId);
        $meta = array_merge($this->decode($file->meta), array_intersect_key($data, array_flip(['context', 'occurred_on', 'inspection_id', 'maintenance_id', 'space_id', 'caption', 'document_type', 'before_after'])));
        $update = [];
        foreach (['group', 'is_primary', 'position'] as $field) if (array_key_exists($field, $data)) $update[$field] = $data[$field];
        if (array_key_exists('position', $data)) $update['sort_order'] = $data['position'];
        if (array_key_exists('tags', $data)) $update['tags'] = $this->json($data['tags']);
        $update['meta'] = $this->json($meta); $update['updated_by'] = $request->user()->id; $update['updated_at'] = now();
        DB::table('files')->where('id', $fileId)->update($update);
        $afterFile = $this->file($assetType, $assetId, $fileId); $after = $this->payload($afterFile);
        $this->intelligence->audit($assetType, $assetId, (int) $request->user()->id, 'file_updated', 'Metadados do arquivo atualizados', $afterFile->original_name, $before, $after, ['file_id' => $fileId]);
        return response()->json(['item' => $after]);
    }

    public function download(Request $request, string $assetType, int $assetId, int $fileId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'files');
        $file = $this->file($assetType, $assetId, $fileId); abort_if($file->storage === 'external', 422, 'Arquivo externo não está disponível para download protegido.');
        $disk = $file->storage ?: 'local'; abort_unless(Storage::disk($disk)->exists($file->path), 404, 'Arquivo não encontrado no armazenamento.');
        DB::table('files')->where('id', $fileId)->update(['download_count' => DB::raw('download_count + 1'), 'last_downloaded_at' => now(), 'updated_at' => now()]);
        return Storage::disk($disk)->download($file->path, basename((string) ($file->original_name ?: 'arquivo')));
    }

    public function destroy(Request $request, string $assetType, int $assetId, int $fileId)
    {
        $this->access->assertAccess($request, $assetType, $assetId, 'files');
        $file = $this->file($assetType, $assetId, $fileId); $before = $this->payload($file);
        if ($file->storage !== 'external' && $file->path) Storage::disk($file->storage ?: 'local')->delete($file->path);
        DB::table('files')->where('id', $fileId)->delete();
        $this->intelligence->audit($assetType, $assetId, (int) $request->user()->id, 'file_deleted', 'Arquivo removido do patrimônio', $file->original_name, $before, null, ['file_id' => $fileId]);
        return response()->json(['ok' => true]);
    }

    private function file(string $assetType, int $assetId, int $fileId): object
    {
        $file = DB::table('files')->where('app_id', $this->context->id())->where('entity_name', $assetType)->where('entity_id', $assetId)->where('id', $fileId)->where('status', 'active')->first();
        abort_unless($file, 404, 'Arquivo não encontrado.'); return $file;
    }

    private function clearPrimary(string $assetType, int $assetId): void
    {
        DB::table('files')->where('app_id', $this->context->id())->where('entity_name', $assetType)->where('entity_id', $assetId)->update(['is_primary' => false, 'updated_at' => now()]);
    }

    private function payload(object $file): array
    {
        $meta = $this->decode($file->meta); return [
            'id' => $file->id, 'uuid' => $file->uuid, 'original_name' => $file->original_name, 'extension' => $file->extension, 'mime_type' => $file->mime_type,
            'file_size' => (int) ($file->file_size ?? 0), 'type' => $file->type, 'group' => $file->group, 'position' => (int) ($file->position ?? 0),
            'is_primary' => (bool) $file->is_primary, 'visibility' => $file->visibility, 'created_at' => $file->created_at,
            'tags' => $this->decode($file->tags), 'context' => $meta['context'] ?? null, 'occurred_on' => $meta['occurred_on'] ?? null,
            'inspection_id' => $meta['inspection_id'] ?? null, 'maintenance_id' => $meta['maintenance_id'] ?? null, 'space_id' => $meta['space_id'] ?? null,
            'caption' => $meta['caption'] ?? null, 'document_type' => $meta['document_type'] ?? null, 'before_after' => $meta['before_after'] ?? null,
        ];
    }

    private function decode(mixed $value): array { if (is_array($value)) return $value; if ($value === null || $value === '') return []; $d = json_decode((string) $value, true); return is_array($d) ? $d : []; }
    private function json(mixed $value): ?string { return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
}
