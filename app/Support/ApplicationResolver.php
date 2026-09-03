<?php

namespace App\Support;

use App\Models\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ApplicationResolver
{
    /** @var array<string, Application|null> */
    private array $cache = [];

    private const SLUG_ALIASES = [
        'petertecnet' => 'peter-tecnet',
        'petertecnet.com.br' => 'peter-tecnet',
        'www.petertecnet.com.br' => 'peter-tecnet',
    ];

    public function fromIdentifier(mixed $value, bool $activeOnly = true): ?Application
    {
        if ($value instanceof Application) {
            return ! $activeOnly || $value->is_active ? $value : null;
        }

        $identifier = trim((string) $value);
        if ($identifier === '') {
            return null;
        }

        if (is_numeric($identifier) && (int) $identifier > 0) {
            return $this->byId((int) $identifier, $activeOnly);
        }

        $normalized = $this->normalizeSlug($identifier);
        $cacheKey = sprintf('identifier:%s:%d', $normalized, $activeOnly ? 1 : 0);
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $query = Application::query();
        if ($activeOnly) {
            $query->where('is_active', true);
        }

        $application = (clone $query)
            ->whereRaw('LOWER(slug) = ?', [$normalized])
            ->first();

        if ($application) {
            return $this->cache[$cacheKey] = $application;
        }

        // Name and canonical URL are reusable aliases during slug migrations.
        // The resolver stays application-agnostic: no product slug is hard-coded.
        $application = (clone $query)
            ->where(function ($builder) {
                $builder->whereNotNull('url')->orWhereNotNull('name');
            })
            ->get()
            ->first(function (Application $candidate) use ($normalized) {
                $nameAlias = Str::lower(Str::slug((string) $candidate->name));
                if ($nameAlias !== '' && hash_equals($nameAlias, $normalized)) {
                    return true;
                }

                $host = $this->host((string) $candidate->url);
                if ($host === '') {
                    return false;
                }

                $firstLabel = explode('.', $host)[0] ?? '';

                return hash_equals($host, $normalized) || hash_equals($firstLabel, $normalized);
            });

        return $this->cache[$cacheKey] = $application;
    }

    public function source(Request $request, array $content = []): ?Application
    {
        if ($request->attributes->get('application') instanceof Application) {
            return $request->attributes->get('application');
        }

        foreach ([
            $request->attributes->get('peter.application_slug'),
            $request->attributes->get('application_slug'),
        ] as $boundIdentifier) {
            $application = $this->fromIdentifier($boundIdentifier);
            if ($application) {
                return $application;
            }
        }

        foreach ([
            $request->headers->get('Origin'),
            $request->headers->get('Referer'),
            $request->header('X-Frontend-Page'),
            $content['origin'] ?? null,
            $content['referer'] ?? null,
            $content['frontend_page'] ?? null,
        ] as $url) {
            $application = $this->fromUrl($url);
            if ($application) {
                return $application;
            }
        }

        foreach ([
            $request->header('X-Peter-App'),
            $request->header('X-App-Slug'),
            $request->header('X-Application-Slug'),
            $content['declared_app'] ?? null,
            $content['app_slug'] ?? null,
            $content['application_slug'] ?? null,
        ] as $identifier) {
            $application = $this->fromIdentifier($identifier);
            if ($application) {
                return $application;
            }
        }

        foreach ([
            $request->header('X-App-ID'),
            $request->header('X-Application-Id'),
        ] as $identifier) {
            $application = $this->fromIdentifier($identifier);
            if ($application) {
                return $application;
            }
        }

        return null;
    }

    public function target(Request $request, mixed $entity = null, array $content = []): ?Application
    {
        if ($source = $this->source($request, $content)) {
            return $source;
        }

        foreach ([
            data_get($entity, 'app_id'),
            data_get($entity, 'application_id'),
            $content['target_app_id'] ?? null,
            $content['app_id'] ?? null,
            $content['application_id'] ?? null,
            $request->input('app_id'),
            $request->input('application_id'),
        ] as $identifier) {
            $application = $this->fromIdentifier($identifier, false);
            if ($application) {
                return $application;
            }
        }

        return null;
    }

    public function stored(array $content): ?Application
    {
        foreach ([
            $content['origin'] ?? null,
            $content['referer'] ?? null,
            $content['frontend_page'] ?? null,
        ] as $url) {
            $application = $this->fromUrl($url, false);
            if ($application) {
                return $application;
            }
        }

        foreach ([
            $content['declared_app'] ?? null,
            $content['app_slug'] ?? null,
            $content['application_slug'] ?? null,
        ] as $identifier) {
            $application = $this->fromIdentifier($identifier, false);
            if ($application) {
                return $application;
            }
        }

        return null;
    }

    public function fromUrl(mixed $value, bool $activeOnly = true): ?Application
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $host = $this->host($value);
        if ($host === '') {
            return null;
        }

        $cacheKey = sprintf('host:%s:%d', $host, $activeOnly ? 1 : 0);
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $query = Application::query()->whereNotNull('url');
        if ($activeOnly) {
            $query->where('is_active', true);
        }

        $match = $query->get()->first(function (Application $application) use ($host) {
            $applicationHost = $this->host((string) $application->url);

            return $applicationHost !== '' && hash_equals($applicationHost, $host);
        });

        return $this->cache[$cacheKey] = $match;
    }

    private function byId(int $id, bool $activeOnly): ?Application
    {
        $cacheKey = sprintf('id:%d:%d', $id, $activeOnly ? 1 : 0);
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $query = Application::query();
        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $this->cache[$cacheKey] = $query->find($id);
    }

    private function normalizeSlug(string $value): string
    {
        $normalized = Str::lower(trim($value));
        $normalized = preg_replace('#^https?://#', '', $normalized);
        $normalized = trim((string) $normalized, '/');

        return self::SLUG_ALIASES[$normalized] ?? $normalized;
    }

    private function host(string $value): string
    {
        $candidate = trim($value);
        if ($candidate === '') {
            return '';
        }

        $host = parse_url($candidate, PHP_URL_HOST);
        if (! $host && ! str_contains($candidate, '://')) {
            $host = parse_url('https://'.ltrim($candidate, '/'), PHP_URL_HOST);
        }

        return Str::lower(preg_replace('/^www\./i', '', trim((string) $host, '.')));
    }
}
