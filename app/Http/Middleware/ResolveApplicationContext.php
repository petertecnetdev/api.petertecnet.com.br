<?php

namespace App\Http\Middleware;

use App\Models\Application;
use App\Support\ApplicationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveApplicationContext
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $routeValue = $request->route('application');
        $application = $routeValue instanceof Application
            ? $routeValue
            : Application::query()
                ->where(function ($query) use ($routeValue) {
                    $query->where('slug', $routeValue);

                    if (is_numeric($routeValue)) {
                        $query->orWhereKey((int) $routeValue);
                    }
                })
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
        $request->attributes->set('application', $application);
        $request->attributes->set('app_id', $application->id);

        // The application identifier is infrastructure context, not a controller
        // argument. Remove it after resolution so action parameters keep matching
        // their own route variables (slug, item, employer, establishment, etc.).
        $request->route()?->forgetParameter('application');

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
