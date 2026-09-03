<?php

namespace App\Services;

use App\Models\Application;
use App\Support\ApplicationResolver;
use Illuminate\Http\Request;

/**
 * Analytics/audit facade for resolving source and target applications.
 *
 * Resolution rules live exclusively in ApplicationResolver so HTTP middleware,
 * telemetry and historical repair can never disagree about the current app.
 */
class ApplicationContextService
{
    public function __construct(private readonly ApplicationResolver $resolver)
    {
    }

    public function resolve(?Request $request = null, $entity = null, array $content = []): ?Application
    {
        $request ??= request();

        return $this->resolver->target($request, $entity, $content);
    }

    public function resolveSource(?Request $request = null, array $content = []): ?Application
    {
        $request ??= request();

        return $this->resolver->source($request, $content);
    }

    public function resolveStoredContext(array $content): ?Application
    {
        return $this->resolver->stored($content);
    }

    public function describe(?Request $request = null, $entity = null, array $content = []): array
    {
        $request ??= request();
        $source = $this->resolveSource($request, $content);
        $application = $source ?: $this->resolve($request, $entity, $content);

        return [
            'application' => $application,
            'source_application' => $source,
            'origin' => $request?->headers->get('Origin'),
            'referer' => $request?->headers->get('Referer'),
            'declared_app' => $request?->attributes->get('peter.application_slug')
                ?: $request?->attributes->get('application_slug')
                ?: $request?->header('X-Peter-App')
                ?: $request?->header('X-App-Slug')
                ?: $request?->header('X-Application-Slug'),
            'resolution' => $source ? 'source' : ($application ? 'target_fallback' : 'unresolved'),
        ];
    }
}
