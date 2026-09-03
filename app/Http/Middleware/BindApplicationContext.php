<?php

namespace App\Http\Middleware;

use App\Models\Application;
use App\Support\ApplicationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class BindApplicationContext
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function handle(Request $request, Closure $next, ?string $applicationSlug = null): Response
    {
        $candidate = $applicationSlug
            ?: $request->route('application')
            ?: $request->header('X-Peter-App');
        $slug = mb_strtolower(trim((string) $candidate));

        if ($slug === '') {
            return response()->json([
                'success' => false,
                'message' => 'Informe o contexto da aplicação.',
                'error' => 'Application context is required.',
                'code' => 'APPLICATION_CONTEXT_REQUIRED',
                'request_id' => $request->attributes->get('request_id'),
            ], 400);
        }

        $application = Application::query()
            ->whereRaw('LOWER(slug) = ?', [$slug])
            ->where('is_active', true)
            ->first();

        if (! $application) {
            return response()->json([
                'success' => false,
                'message' => 'Aplicativo não encontrado ou inativo.',
                'error' => 'Application context could not be resolved.',
                'code' => 'APPLICATION_NOT_AVAILABLE',
                'request_id' => $request->attributes->get('request_id'),
            ], 404);
        }

        $this->context->set($application);
        $request->attributes->set('peter.application_slug', (string) $application->slug);
        $request->attributes->set('application', $application);
        $request->attributes->set('app_id', (int) $application->id);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
