<?php

namespace App\Http\Controllers;

use App\Services\AiDescriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AiContentController extends Controller
{
    public function __construct(private readonly AiDescriptionService $descriptions)
    {
    }

    public function description(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity_type' => ['required', 'string', 'max:50', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'title' => ['nullable', 'string', 'max:200'],
            'current_description' => ['nullable', 'string', 'max:5000'],
            'context' => ['nullable', 'array', 'max:30'],
            'context.*' => ['nullable', 'string', 'max:500'],
            'locale' => ['nullable', 'string', 'max:10'],
            'tone' => ['nullable', 'string', 'max:160'],
        ]);

        $title = trim((string) ($data['title'] ?? ''));
        $currentDescription = trim((string) ($data['current_description'] ?? ''));
        $context = array_filter(
            (array) ($data['context'] ?? []),
            static fn ($value) => trim((string) $value) !== '',
        );

        if ($title === '' && $currentDescription === '' && $context === []) {
            throw ValidationException::withMessages([
                'title' => ['Informe ao menos um nome, uma descrição atual ou algum contexto para a IA.'],
            ]);
        }

        $data['context'] = $context;

        try {
            $result = $this->descriptions->generateDescription(
                $data,
                $request->user('api')?->getAuthIdentifier(),
            );

            return response()->json([
                'description' => $result['description'],
                'mode' => $result['mode'],
                'meta' => [
                    'model' => $result['model'],
                    'usage' => $result['usage'],
                ],
            ]);
        } catch (RuntimeException $exception) {
            $configurationError = str_contains(
                mb_strtolower($exception->getMessage()),
                'não está configurado',
            );

            Log::warning('Falha no endpoint de descrição com IA.', [
                'user_id' => $request->user('api')?->getAuthIdentifier(),
                'entity_type' => $data['entity_type'],
                'configured' => $this->descriptions->isConfigured(),
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => $configurationError
                    ? 'A geração de descrições com IA está temporariamente indisponível.'
                    : $exception->getMessage(),
                'code' => $configurationError ? 'ai_not_configured' : 'ai_generation_failed',
            ], 503);
        }
    }
}
