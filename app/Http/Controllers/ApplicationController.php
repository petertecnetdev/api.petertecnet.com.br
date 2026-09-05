<?php

namespace App\Http\Controllers;

use App\Services\PublicApplicationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ApplicationController extends Controller
{
    public function index(PublicApplicationService $service): JsonResponse
    {
        try {
            return response()->json(['applications' => $service->index()]);
        } catch (\Throwable $exception) {
            Log::error('Erro ao listar aplicações públicas.', [
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Não foi possível carregar as aplicações agora.',
            ], 500);
        }
    }

    public function show(string $slug, PublicApplicationService $service): JsonResponse
    {
        try {
            return response()->json(['application' => $service->findBySlug($slug)]);
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
}
