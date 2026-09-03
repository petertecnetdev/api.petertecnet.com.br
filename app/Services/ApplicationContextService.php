<?php

namespace App\Services;

use App\Models\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApplicationContextService
{
    private static array $cache = [];

    private const SLUG_ALIASES = [
        'petertecnet' => 'peter-tecnet',
        'petertecnet.com.br' => 'peter-tecnet',
        'www.petertecnet.com.br' => 'peter-tecnet',
    ];

    public function resolve(?Request $request = null, $entity = null, array $content = []): ?Application
    {
        $request ??= request();

        // The source application is authoritative for product analytics. Entity
        // and payload application IDs describe the target of the action.
        $source = $this->resolveSource($request, $content);
        if ($source) return $source;

        foreach ([
            data_get($entity, 'app_id'),
            data_get($entity, 'application_id'),
            $content['target_app_id'] ?? null,
            $content['app_id'] ?? null,
            $content['application_id'] ?? null,
            $request?->input('app_id'),
            $request?->input('application_id'),
        ] as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                $app = $this->findById((int) $candidate);
                if ($app) return $app;
            }
        }

        return null;
    }

    public function resolveSource(?Request $request = null, array $content = []): ?Application
    {
        $request ??= request();

        // A route may explicitly bind an application context. This is the generic
        // compatibility bridge used by old product-prefixed URLs. Domain code does
        // not need to know which product supplied the context.
        $boundSlug = $request?->attributes->get('peter.application_slug');
        if (is_string($boundSlug) && trim($boundSlug) !== '') {
            $app = $this->findBySlug($boundSlug);
            if ($app) return $app;
        }

        // Exact browser origin is the strongest source signal. Headers remain
        // authoritative for native/mobile clients whose origin is local.
        foreach ([
            $request?->headers->get('Origin'),
            $request?->headers->get('Referer'),
            $request?->header('X-Frontend-Page'),
            $content['origin'] ?? null,
            $content['referer'] ?? null,
            $content['frontend_page'] ?? null,
        ] as $url) {
            $app = $this->findByUrl($url);
            if ($app) return $app;
        }

        foreach ([
            $request?->header('X-Peter-App'),
            $request?->header('X-App-Slug'),
            $request?->header('X-Application-Slug'),
            $content['declared_app'] ?? null,
            $content['app_slug'] ?? null,
            $content['application_slug'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $app = $this->findBySlug(trim($candidate));
                if ($app) return $app;
            }
        }

        foreach ([$request?->header('X-App-ID'), $request?->header('X-Application-Id')] as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                $app = $this->findById((int) $candidate);
                if ($app) return $app;
            }
        }

        return null;
    }

    public function resolveStoredContext(array $content): ?Application
    {
        // For historical repair, an exact browser origin is stronger evidence
        // than a legacy declared slug, which may have been hard-coded wrongly.
        foreach ([
            $content['origin'] ?? null,
            $content['referer'] ?? null,
            $content['frontend_page'] ?? null,
        ] as $url) {
            $app = $this->findByUrl($url);
            if ($app) return $app;
        }

        foreach ([
            $content['declared_app'] ?? null,
            $content['app_slug'] ?? null,
            $content['application_slug'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $app = $this->findBySlug($candidate);
                if ($app) return $app;
            }
        }

        return null;
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
                ?: $request?->header('X-Peter-App')
                ?: $request?->header('X-App-Slug')
                ?: $request?->header('X-Application-Slug'),
            'resolution' => $source ? 'source' : ($application ? 'target_fallback' : 'unresolved'),
        ];
    }

    private function findById(int $id): ?Application
    {
        $key = 'id:' . $id;
        if (array_key_exists($key, self::$cache)) return self::$cache[$key];
        return self::$cache[$key] = Application::query()->find($id);
    }

    private function findBySlug(string $value): ?Application
    {
        $normalized = Str::lower(trim($value));
        $normalized = preg_replace('#^https?://#', '', $normalized);
        $normalized = trim((string) $normalized, '/');
        $normalized = self::SLUG_ALIASES[$normalized] ?? $normalized;
        $key = 'slug:' . $normalized;

        if (array_key_exists($key, self::$cache)) return self::$cache[$key];

        return self::$cache[$key] = Application::query()
            ->whereRaw('LOWER(slug) = ?', [$normalized])
            ->first();
    }

    private function findByUrl($value): ?Application
    {
        if (! is_string($value) || trim($value) === '') return null;

        $candidate = trim($value);
        $host = parse_url($candidate, PHP_URL_HOST);
        if (! $host && ! str_contains($candidate, '://')) {
            $host = parse_url('https://' . ltrim($candidate, '/'), PHP_URL_HOST);
        }
        $host = Str::lower(preg_replace('/^www\./i', '', (string) $host));
        if ($host === '') return null;

        $key = 'host:' . $host;
        if (array_key_exists($key, self::$cache)) return self::$cache[$key];

        $applications = Application::query()->whereNotNull('url')->get();
        $match = $applications->first(function (Application $application) use ($host) {
            $applicationHost = parse_url((string) $application->url, PHP_URL_HOST);
            $applicationHost = Str::lower(preg_replace('/^www\./i', '', (string) $applicationHost));
            return $applicationHost !== '' && hash_equals($applicationHost, $host);
        });

        return self::$cache[$key] = $match;
    }
}
