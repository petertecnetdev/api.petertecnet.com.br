<?php

namespace App\\Http\\Controllers;

use App\\Models\\Application;
use Illuminate\\Database\\Eloquent\\ModelNotFoundException;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Support\\Facades\\Cache;
use Illuminate\\Support\\Facades\\Log;

class ApplicationController extends Controller
{
    private const CACHE_KEY = 'public.applications.v1';
    private const CACHE_TTL_SECONDS = 600;

    public function index(): JsonResponse
    {
        try {
            $applications = Cache::remember(
                self::CACHE_KEY,
                self::CACHE_TTL_SECONDS,
                fn () => Application::query()
                    ->where('is_active', true)
                    ->select($this->publicFields())
                    ->orderByRaw('release_date IS NULL')
                    ->orderByDesc('release_date')
                    ->orderBy('name')
                    ->get()
            );

            return response()->json(['applications' => $applications]);
        } catch (\\Throwable $exception) {
            Log::error('Erro ao listar aplicações públicas.', [
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Não foi possível carregar as aplicações agora.',
            ], 500);
        }
    }

    public function show(string $slug): JsonResponse
    {
        try {
            $application = Application::query()
                ->where('is_active', true)
                ->where('slug', $slug)
                ->select($this->publicFields())
                ->firstOrFail();

            return response()->json(['application' => $application]);
        } catch (ModelNotFoundException $exception) {
            return response()->json(['message' => 'Aplicação não encontrada.'], 404);
        } catch (\\Throwable $exception) {
            Log::error('Erro ao carregar aplicação pública.', [
                'slug' => $slug,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Não foi possível carregar a aplicação agora.',
            ], 500);
        }
    }

    private function publicFields(): array
    {
        return [
            'id',
            'name',
            'description',
            'slug',
            'url',
            'logo',
            'version',
            'author',
            'release_date',
        ];
    }
}
