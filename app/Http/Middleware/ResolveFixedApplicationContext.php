<?php

namespace App\Http\Middleware;

use App\Models\Application;
use App\Support\ApplicationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveFixedApplicationContext
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function handle(Request $request, Closure $next, string $slug): Response
    {
        $application = Application::query()->where('slug', $slug)->where('is_active', true)->first();

        if (! $application) {
            return response()->json([
                'success' => false,
                'message' => 'Aplicativo não encontrado ou inativo.',
                'code' => 'APPLICATION_NOT_AVAILABLE',
            ], 404);
        }

        $this->context->set($application);
        $request->attributes->set('application', $application);
        $request->attributes->set('app_id', $application->id);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
