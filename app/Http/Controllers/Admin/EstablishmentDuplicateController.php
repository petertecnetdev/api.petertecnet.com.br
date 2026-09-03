<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\EstablishmentDuplicateDetectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstablishmentDuplicateController extends Controller
{
    public function __invoke(Request $request, EstablishmentDuplicateDetectionService $detector): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor && (
            $actor->hasProfile('Administrador')
            || $actor->hasPermission('establishment_manage')
            || $actor->hasPermission('ecosystem_manage')
        ), 403, 'Usuário sem permissão para consultar qualidade cadastral de estabelecimentos.');

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'fantasy' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'cnpj' => ['nullable', 'string', 'max:64'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'exclude_id' => ['nullable', 'integer', 'exists:establishments,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $matches = $detector->detect(
            $data,
            isset($data['exclude_id']) ? (int) $data['exclude_id'] : null,
            (int) ($data['limit'] ?? 8),
        );

        return response()->json([
            'matches' => $matches,
            'has_exact_match' => $matches->contains(fn ($match) => $match['score'] === 100),
        ]);
    }
}
