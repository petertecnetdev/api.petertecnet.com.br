<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Interaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'app_id' => 'required|integer|exists:applications,id',
            'entity_id' => 'required|integer|min:1',
            'entity_name' => 'required|string|max:100|regex:/^[A-Za-z0-9_-]+$/',
            'group' => 'nullable|string|max:100',
            'is_primary' => 'nullable|boolean',
            'position' => 'nullable|integer|min:0',
            'visibility' => 'nullable|in:public,private',
            'file' => 'nullable|required_without:external_url|file|max:20480|mimes:jpg,jpeg,png,webp,gif,pdf,txt,csv,doc,docx,xls,xlsx,zip',
            'external_url' => 'nullable|required_without:file|url:http,https|max:2048',
        ]);

        if (! empty($data['external_url'])) {
            $url = trim($data['external_url']);
            $path = (string) parse_url($url, PHP_URL_PATH);
            $name = basename($path) ?: 'external-image';
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            $file = File::create([
                'uuid' => (string) Str::uuid(),
                'app_id' => $data['app_id'],
                'entity_id' => $data['entity_id'],
                'entity_name' => $data['entity_name'],
                'fileable_id' => $data['entity_id'],
                'fileable_type' => $data['entity_name'],
                'original_name' => mb_substr($name, 0, 255),
                'extension' => $extension ?: null,
                'mime_type' => 'image/external',
                'file_size' => 0,
                'type' => 'image',
                'storage' => 'external',
                'path' => null,
                'storage_path' => null,
                'public_url' => $url,
                'group' => $data['group'] ?? null,
                'position' => $data['position'] ?? 0,
                'is_primary' => $data['is_primary'] ?? true,
                'visibility' => $data['visibility'] ?? 'public',
                'visibility_scope' => 'global',
                'status' => 'active',
                'source' => 'external_url',
                'version' => 1,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
                'meta' => ['external_url' => true],
            ]);

            return response()->json(['message' => 'Imagem externa vinculada com sucesso.', 'file' => $file], 201);
        }

        $uploaded = $request->file('file');
        $extension = strtolower((string) $uploaded->getClientOriginalExtension());
        $uuid = (string) Str::uuid();
        $path = $uploaded->storeAs(
            'uploads/files/' . $data['entity_name'] . '/' . $data['entity_id'],
            $uuid . '.' . $extension,
            'public'
        );

        $file = File::create([
            'uuid' => $uuid,
            'app_id' => $data['app_id'],
            'entity_id' => $data['entity_id'],
            'entity_name' => $data['entity_name'],
            'fileable_id' => $data['entity_id'],
            'fileable_type' => $data['entity_name'],
            'original_name' => basename((string) $uploaded->getClientOriginalName()),
            'extension' => $extension,
            'mime_type' => $uploaded->getMimeType() ?: 'application/octet-stream',
            'file_size' => $uploaded->getSize() ?: 0,
            'content_hash' => hash_file('sha256', Storage::disk('public')->path($path)),
            'type' => str_starts_with((string) $uploaded->getMimeType(), 'image/') ? 'image' : 'file',
            'storage' => 'public',
            'path' => $path,
            'storage_path' => Storage::disk('public')->path($path),
            'public_url' => Storage::disk('public')->url($path),
            'group' => $data['group'] ?? null,
            'position' => $data['position'] ?? 0,
            'is_primary' => $data['is_primary'] ?? false,
            'visibility' => $data['visibility'] ?? 'public',
            'visibility_scope' => 'global',
            'status' => 'active',
            'source' => 'upload',
            'version' => 1,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Arquivo enviado com sucesso.', 'file' => $file], 201);
    }

    public function update(Request $request, $id)
    {
        $file = File::findOrFail($id);
        if (! $this->canManage($file)) {
            return response()->json(['error' => 'Você não tem permissão para alterar este arquivo.'], 403);
        }

        $data = $request->validate([
            'group' => 'sometimes|nullable|string|max:100',
            'tags' => 'sometimes|nullable|array',
            'tags.*' => 'string|max:80',
            'is_primary' => 'sometimes|boolean',
            'position' => 'sometimes|integer|min:0',
            'visibility' => 'sometimes|in:public,private',
            'status' => 'sometimes|in:active,inactive',
        ]);

        $file->fill($data);
        $file->updated_by = Auth::id();
        $file->save();

        return response()->json(['message' => 'Arquivo atualizado com sucesso.', 'file' => $file->fresh()]);
    }

    public function delete($id)
    {
        $file = File::findOrFail($id);
        if (! $this->canManage($file)) {
            return response()->json(['error' => 'Você não tem permissão para excluir este arquivo.'], 403);
        }

        if ($file->path) {
            Storage::disk('public')->delete($file->path);
        }
        $file->delete();

        return response()->json(['message' => 'Arquivo removido com sucesso.']);
    }

    public function listByEntity(Request $request)
    {
        $data = $request->validate([
            'entity_id' => 'required|integer|min:1',
            'entity_name' => 'required|string|max:100',
        ]);

        $files = File::query()
            ->where('entity_id', $data['entity_id'])
            ->where('entity_name', $data['entity_name'])
            ->where('visibility', 'public')
            ->where('status', 'active')
            ->orderBy('position')
            ->get();

        return response()->json(['message' => 'Arquivos carregados com sucesso.', 'files' => $files]);
    }

    public function view($slug)
    {
        $file = File::query()
            ->where('uuid', $slug)
            ->where('visibility', 'public')
            ->where('status', 'active')
            ->firstOrFail();

        Interaction::registerView($file, Auth::user());
        return response()->json(['file' => $file]);
    }

    public function download($id)
    {
        $file = File::findOrFail($id);

        if ($file->visibility !== 'public' && ! $this->canManage($file)) {
            return response()->json(['error' => 'Arquivo privado.'], 403);
        }
        if ($file->status !== 'active' || ! $file->path || ! Storage::disk('public')->exists($file->path)) {
            return response()->json(['error' => 'Arquivo não encontrado no servidor.'], 404);
        }

        $file->incrementDownload();
        return Storage::disk('public')->download($file->path, basename((string) $file->original_name));
    }

    private function canManage(File $file): bool
    {
        $user = Auth::user();
        return $user && (
            $user->hasProfile('Administrador')
            || (int) $file->created_by === (int) $user->id
            || $user->hasPermission('file_manage')
        );
    }
}
