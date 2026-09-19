<?php

namespace App\Http\Controllers;

use App\Services\AiDescriptionService;
use App\Services\EventDescriptionPipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AiContentController extends Controller
{
    public function __construct(
        private readonly AiDescriptionService $descriptions,
        private readonly EventDescriptionPipelineService $eventPipeline,
    ) {}

    public function description(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity_type' => ['required', 'string', 'max:50', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'title' => ['nullable', 'string', 'max:200'],
            'current_description' => ['nullable', 'string', 'max:5000'],
            'context' => ['nullable', 'array', 'max:30'],
            'context.*' => ['nullable', 'string', 'max:1200'],
            'locale' => ['nullable', 'string', 'max:10'],
            'tone' => ['nullable', 'string', 'max:220'],
            'action' => ['nullable', 'in:improve,rewrite,enrich'],
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
        $data['action'] = $data['action'] ?? 'improve';

        try {
            $user = $request->user('api') ?? $request->user();
            $pipelineFallback = false;

            if (($data['entity_type'] ?? '') === 'event' && $user) {
                try {
                    $result = $this->eventPipeline->generate($data, $user);
                } catch (Throwable $pipelineException) {
                    $pipelineFallback = true;

                    try {
                        Log::warning('Pipeline editorial de evento indisponível; usando geração direta.', [
                            'user_id' => $user->getAuthIdentifier(),
                            'entity_type' => 'event',
                            'exception' => $pipelineException::class,
                            'message' => $pipelineException->getMessage(),
                        ]);
                    } catch (Throwable) {
                        // Logging must never turn the fallback path into another 5xx.
                    }

                    $result = $this->descriptions->generateDescription(
                        $data,
                        $user->getAuthIdentifier(),
                    );
                }
            } else {
                $result = $this->descriptions->generateDescription(
                    $data,
                    $user?->getAuthIdentifier(),
                );
            }

            return response()->json([
                'description' => $result['description'],
                'mode' => $result['mode'],
                'generation_id' => $result['generation_id'] ?? null,
                'meta' => [
                    'model' => $result['model'] ?? null,
                    'usage' => $result['usage'] ?? [],
                    'prompt_version' => $result['prompt_version'] ?? null,
                    'candidate_count' => $result['candidate_count'] ?? 1,
                    'quality' => $result['quality'] ?? null,
                    'event_pipeline_fallback' => $pipelineFallback,
                ],
            ]);
        } catch (RuntimeException $exception) {
            $configurationError = str_contains(
                mb_strtolower($exception->getMessage()),
                'não está configurado',
            );

            try {
                Log::warning('Falha no endpoint de descrição com IA.', [
                    'user_id' => ($request->user('api') ?? $request->user())?->getAuthIdentifier(),
                    'entity_type' => $data['entity_type'],
                    'configured' => $this->descriptions->isConfigured(),
                    'message' => $exception->getMessage(),
                ]);
            } catch (Throwable) {
            }

            return response()->json([
                'message' => $configurationError
                    ? 'A geração de descrições com IA está temporariamente indisponível.'
                    : $exception->getMessage(),
                'code' => $configurationError ? 'ai_not_configured' : 'ai_generation_failed',
            ], 503);
        } catch (Throwable $exception) {
            try {
                Log::error('Erro inesperado no endpoint de descrição com IA.', [
                    'user_id' => ($request->user('api') ?? $request->user())?->getAuthIdentifier(),
                    'entity_type' => $data['entity_type'] ?? null,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            } catch (Throwable) {
            }

            return response()->json([
                'message' => 'Não foi possível gerar a descrição agora. Tente novamente em instantes.',
                'code' => 'ai_generation_failed',
            ], 503);
        }
    }
}
