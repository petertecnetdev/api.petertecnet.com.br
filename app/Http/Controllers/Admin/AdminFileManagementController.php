<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\File;
use App\Services\AdminFileInventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class AdminFileManagementController extends Controller
{
    public function __construct(private readonly AdminFileInventoryService $inventory)
    {
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:200',
            'state' => 'nullable|string|max:40',
            'kind' => 'nullable|string|max:40',
            'app' => 'nullable|string|max:80',
            'cleanup_candidate' => 'nullable|boolean',
            'protected' => 'nullable|boolean',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:20|max:200',
        ]);

        return response()->json($this->inventory->snapshot($filters));
    }

    public function cleanup(Request $request)
    {
        $data = $request->validate([
            'paths' => 'nullable|array|max:500',
            'paths.*' => 'string|max:1024',
            'all_candidates' => 'nullable|boolean',
        ]);

        $all = (bool) ($data['all_candidates'] ?? false);
        $paths = $data['paths'] ?? [];
        abort_unless($all || $paths !== [], 422, 'Selecione arquivos candidatos ou solicite a limpeza de todos os candidatos.');

        $result = $this->inventory->cleanup($paths, $all);

        Log::info('admin.files.cleanup', [
            'admin_user_id' => $request->user()?->id,
            'deleted_count' => $result['deleted_count'],
            'freed_bytes' => $result['freed_bytes'],
            'all_candidates' => $all,
            'failed_count' => count($result['failed']),
        ]);

        return response()->json([
            'message' => $result['deleted_count'] > 0 ? 'Limpeza de arquivos concluída.' : 'Nenhum arquivo elegível foi removido.',
            ...$result,
        ]);
    }

    public function destroy(Request $request, File $file)
    {
        $fileId = $file->id;
        $fileUuid = $file->uuid;
        $filePath = $file->path;
        $result = $this->inventory->deleteRegistered($file);

        if (! $result['allowed']) {
            return response()->json(['message' => $result['reason']], 422);
        }

        Log::warning('admin.files.registered_deleted', [
            'admin_user_id' => $request->user()?->id,
            'file_id' => $fileId,
            'file_uuid' => $fileUuid,
            'path' => $filePath,
            'freed_bytes' => $result['freed_bytes'],
        ]);

        return response()->json([
            'message' => 'Arquivo registrado removido com sucesso.',
            'freed_bytes' => $result['freed_bytes'],
        ]);
    }
}
