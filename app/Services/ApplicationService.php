<?php

namespace App\Services;

use App\Models\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ApplicationService
{
    private const CACHE_KEY = 'public.applications.v2';
    private const CACHE_TTL_SECONDS = 600;

    /**
     * Applications that belong to the Peter Tecnet administrative surface and
     * must never be exposed through the public application catalogue/store.
     */
    private const INTERNAL_APPLICATION_SLUGS = [
        'admin-center',
        'admin',
        'peter-tecnet',
        'petertecnet',
    ];

    public function index(): JsonResponse
    {
        try {
            $applications = Cache::remember(
                self::CACHE_KEY,
                self::CACHE_TTL_SECONDS,
                fn () => $this->publicApplicationsQuery()
                    ->select($this->publicFields())
                    ->orderByDesc('is_default')
                    ->orderBy('launcher_order')
                    ->orderBy('name')
                    ->get()
            );

            return response()->json(['applications' => $applications]);
        } catch (\Throwable $exception) {
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
            $application = $this->publicApplicationsQuery()
                ->where('slug', $slug)
                ->select($this->publicFields())
                ->firstOrFail();

            return response()->json(['application' => $application]);
        } catch (ModelNotFoundException $exception) {
            return response()->json(['message' => 'Aplicação não encontrada.'], 404);
        } catch (\Throwable $exception) {
            Log::error('Erro ao carregar aplicação pública.', [
                'slug' => $slug,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Não foi possível carregar a aplicação agora.',
            ], 500);
        }
    }

    private function publicApplicationsQuery(): Builder
    {
        return Application::query()
            ->where('is_active', true)
            ->where('is_visible', true)
            ->whereNotIn('slug', self::INTERNAL_APPLICATION_SLUGS)
            ->where(function (Builder $query) {
                $query
                    ->whereNull('url')
                    ->orWhere(function (Builder $urlQuery) {
                        $urlQuery
                            ->where('url', 'not like', 'https://petertecnet.com.br%')
                            ->where('url', 'not like', 'http://petertecnet.com.br%');
                    });
            });
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
            'category',
            'launcher_order',
            'is_default',
            'operational_status',
            'maintenance_message',
        ];
    }
}
