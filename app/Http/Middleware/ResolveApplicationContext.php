<?php

namespace App\Http\Middleware;

use App\Support\ApplicationContext;
use App\Support\ApplicationResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveApplicationContext
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ApplicationResolver $resolver,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $application = $this->resolver->fromIdentifier($request->route('application'));

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
        $request->attributes->set('app_id', (int) $application->id);
        $request->attributes->set('application_slug', (string) $application->slug);
        $request->attributes->set('peter.application_slug', (string) $application->slug);

        $request->route()?->forgetParameter('application');

        try {
            $response = $next($request);
            $response->headers->set('X-Peter-Application', (string) $application->slug);
            $response->headers->set('X-Peter-Application-Id', (string) $application->id);

            return $response;
        } finally {
            $this->context->clear();
        }
    }
}
