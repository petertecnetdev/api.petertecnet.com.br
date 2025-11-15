<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Interaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class FileController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api')->except(['view', 'show', 'public']);
    }

    protected function validationMessages()
    {
        return [
            'app_id.required' => 'O campo app_id é obrigatório.',
            'app_id.integer' => 'O campo app_id deve ser um número inteiro.',
            'app_id.exists' => 'O app_id informado não existe.',
            'file.required' => 'O arquivo é obrigatório.',
            'file.file' => 'O arquivo enviado é inválido.',
            'file.max' => 'O arquivo deve ter no máximo 20MB.',
            'entity_id.required' => 'O entity_id é obrigatório.',
            'entity_name.required' => 'O entity_name é obrigatório.',
        ];
    }

    public function store(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();

            $data = $request->validate([
                'app_id' => 'required|integer|exists:applications,id',
                'entity_id' => 'required|integer',
                'entity_name' => 'required|string|max:255',
                'group' => 'nullable|string|max:255',
                'is_primary' => 'nullable|boolean',
                'file' => 'required|file|max:20480',
                'position' => 'nullable|integer',
            ], $this->validationMessages());

            $uploaded = $request->file('file');
            $original = $uploaded->getClientOriginalName();
            $extension = strtolower($uploaded->getClientOriginalExtension());
            $mimeType = $uploaded->getMimeType() ?? 'application/octet-stream';
            $size = $uploaded->getSize() ?? 0;

            $uuid = (string) Str::uuid();
            $filename = $uuid . '.' . $extension;

            $storagePath = public_path("uploads/files/{$data['entity_name']}/{$data['entity_id']}");
            if (!is_dir($storagePath)) {
                mkdir($storagePath, 0777, true);
            }

            $uploaded->move($storagePath, $filename);

            $width = null;
            $height = null;

            if (str_starts_with($mimeType, 'image')) {
                try {
                    $img = Image::make($storagePath . '/' . $filename);
                    $width = $img->width();
                    $height = $img->height();
                } catch (\Throwable $e) {}
            }

            $publicUrl = "uploads/files/{$data['entity_name']}/{$data['entity_id']}/{$filename}";

            $file = File::create([
                'uuid' => $uuid,
                'app_id' => $data['app_id'],
                'entity_id' => $data['entity_id'],
                'entity_name' => $data['entity_name'],
                'fileable_id' => $data['entity_id'],
                'fileable_type' => $data['entity_name'],
                'original_name' => $original,
                'extension' => $extension,
                'mime_type' => $mimeType,
                'file_size' => $size,
                'content_hash' => hash_file('sha256', $storagePath . '/' . $filename),
                'type' => str_starts_with($mimeType, 'image') ? 'image' : 'file',
                'storage' => 'public',
                'path' => $publicUrl,
                'storage_path' => $storagePath . '/' . $filename,
                'public_url' => $publicUrl,
                'width' => $width,
                'height' => $height,
                'group' => $data['group'] ?? null,
                'position' => $data['position'] ?? 0,
                'is_primary' => $data['is_primary'] ?? false,
                'processed' => false,
                'visibility' => 'public',
                'visibility_scope' => 'global',
                'status' => 'active',
                'locked' => false,
                'usage_count' => 0,
                'source' => 'upload',
                'version' => 1,
                'checksum' => hash('sha256', $uuid . $original),
                'download_count' => 0,
                'compressed' => false,
                'compression_ratio' => null,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            return response()->json([
                'message' => 'Arquivo enviado com sucesso.',
                'file' => $file,
            ], 201);

        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);

        } catch (\Throwable $e) {
            Log::error('[FileController::store] Erro ao enviar arquivo', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Erro ao enviar arquivo.'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $file = File::find($id);
            if (!$file) {
                return response()->json(['error' => 'Arquivo não encontrado.'], 404);
            }

            $data = $request->validate([
                'group' => 'nullable|string|max:255',
                'tags' => 'nullable|array',
                'is_primary' => 'nullable|boolean',
                'position' => 'nullable|integer',
                'visibility' => 'nullable|string|in:public,private',
                'status' => 'nullable|string|in:active,inactive,deleted',
            ]);

            $file->update($data);
            $file->updated_by = Auth::user()->id;
            $file->save();

            Cache::forget("file_{$file->id}_metrics");
            Cache::forget("file_{$file->id}_summary");

            return response()->json([
                'message' => 'Arquivo atualizado com sucesso.',
                'file' => $file
            ], 200);

        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);

        } catch (\Throwable $e) {
            Log::error('[FileController::update] Erro ao atualizar arquivo: ' . $e->getMessage());
            return response()->json(['error' => 'Erro ao atualizar arquivo.'], 500);
        }
    }

    public function delete($id)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $file = File::find($id);
            if (!$file) {
                return response()->json(['error' => 'Arquivo não encontrado.'], 404);
            }

            if (file_exists($file->storage_path)) {
                unlink($file->storage_path);
            }

            $file->delete();

            return response()->json(['message' => 'Arquivo removido com sucesso.'], 200);

        } catch (\Throwable $e) {
            Log::error('[FileController::delete] Erro ao deletar arquivo: ' . $e->getMessage());
            return response()->json(['error' => 'Erro ao deletar arquivo.'], 500);
        }
    }

    public function listByEntity(Request $request)
    {
        try {
            $data = $request->validate([
                'entity_id' => 'required|integer',
                'entity_name' => 'required|string|max:255',
            ]);

            $files = File::where('entity_id', $data['entity_id'])
                ->where('entity_name', $data['entity_name'])
                ->orderBy('position')
                ->get();

            return response()->json([
                'message' => 'Arquivos carregados com sucesso.',
                'files' => $files
            ], 200);

        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }

    public function view($slug)
    {
        try {
            $file = File::where('slug', $slug)->first();

            if (!$file) {
                return response()->json(['error' => 'Arquivo não encontrado.'], 404);
            }

            Interaction::create([
                'user_id' => Auth::id(),
                'entity_id' => $file->id,
                'entity_type' => 'File',
                'interaction_type' => 'view',
            ]);

            Cache::forget("file_{$file->id}_metrics");
            Cache::forget("file_{$file->id}_summary");

            return response()->json(['file' => $file], 200);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Erro ao carregar arquivo.'], 500);
        }
    }

    public function download($id)
    {
        try {
            $file = File::find($id);

            if (!$file) {
                return response()->json(['error' => 'Arquivo não encontrado.'], 404);
            }

            $file->incrementDownload();

            if (file_exists($file->storage_path)) {
                return response()->download($file->storage_path, $file->original_name);
            }

            return response()->json(['error' => 'Arquivo não encontrado no servidor.'], 404);

        } catch (\Throwable $e) {
            return response()->json(['error' => 'Erro ao realizar download.'], 500);
        }
    }
}
