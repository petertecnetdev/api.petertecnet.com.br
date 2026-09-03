<?php

namespace App\Http\Middleware;

use App\Models\Application;
use App\Support\ApplicationContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
            ? ($routeValue->is_active ? $routeValue : null)
            : $this->resolveApplication($routeValue);

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
        $request->attributes->set('application_slug', $application->slug);

        // The application identifier is infrastructure context, not a controller
        // argument. Remove it after resolution so action parameters keep matching
        // their own route variables (slug, item, employer, establishment, etc.).
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

    private function resolveApplication(mixed $routeValue): ?Application
    {
        $identifier = trim((string) $routeValue);
        if ($identifier === '') {
            return null;
        }

        $active = Application::query()->where('is_active', true);

        if (is_numeric($identifier)) {
            return (clone $active)->find((int) $identifier);
        }

        $normalized = Str::lower($identifier);

        $application = (clone $active)
            ->whereRaw('LOWER(slug) = ?', [$normalized])
            ->first();

        if ($application) {
            return $application;
        }

        // During ecosystem migrations an application's canonical URL or display
        // name may already be correct while an older slug is still persisted.
        // Treat those reusable attributes as aliases instead of hard-coding apps.
        return (clone $active)
            ->where(function ($query) {
                $query->whereNotNull('url')
                    ->orWhereNotNull('name');
            })
            ->get()
            ->first(function (Application $candidate) use ($normalized) {
                $nameAlias = Str::slug((string) $candidate->name);
                if ($nameAlias !== '' && Str::lower($nameAlias) === $normalized) {
                    return true;
                }

                $rawUrl = trim((string) $candidate->url);
                if ($rawUrl === '') {
                    return false;
                }

                $host = parse_url(
                    str_contains($rawUrl, '://') ? $rawUrl : 'https://' . $rawUrl,
                    PHP_URL_HOST
                );
                $host = Str::lower(trim((string) $host, '.'));
                if ($host === '') {
                    return false;
                }

                $firstLabel = explode('.', $host)[0] ?? '';

                return $firstLabel === $normalized || $host === $normalized;
            });
    }
}
