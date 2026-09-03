<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PrimaryFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrimaryFileController extends Controller
{
    public function store(Request $request, PrimaryFileService $files): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor && (
            $actor->hasProfile('Administrador')
            || $actor->hasPermission('file_manage')
            || $actor->hasPermission('catalog_manage')
            || $actor->hasPermission('establishment_manage')
            || $actor->hasPermission('ecosystem_manage')
        ), 403, 'Usuário sem permissão para substituir arquivos principais.');

        $data = $request->validate([
            'app_id' => ['required', 'integer', 'exists:applications,id'],
            'entity_id' => ['required', 'integer', 'min:1'],
            'entity_name' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'group' => ['nullable', 'string', 'max:100'],
            'visibility' => ['nullable', 'in:public,private'],
            'file' => ['required', 'file', 'max:20480', 'mimes:jpg,jpeg,png,webp,gif,pdf,txt,csv,doc,docx,xls,xlsx,zip'],
        ]);

        $file = $files->replace(
            $request->file('file'),
            (int) $data['app_id'],
            $data['entity_name'],
            (int) $data['entity_id'],
            $data['group'] ?? null,
            $data['visibility'] ?? 'public',
            (int) $actor->id,
        );

        return response()->json([
            'message' => 'Arquivo principal substituído com versionamento e consistência.',
            'file' => $file,
        ], 201);
    }
}
