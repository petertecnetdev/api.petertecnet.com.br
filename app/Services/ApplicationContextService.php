<?php

namespace App\Services;

use App\Models\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApplicationContextService
{
    private static array $cache = [];

    public function resolve(?Request $request = null, $entity = null, array $content = []): ?Application
    {
        $request ??= request();

        foreach ([
            $content['app_id'] ?? null,
            $content['application_id'] ?? null,
            $request?->input('app_id'),
            $request?->input('application_id'),
            $request?->header('X-App-ID'),
            $request?->header('X-Application-Id'),
            data_get($entity, 'app_id'),
            data_get($entity, 'application_id'),
        ] as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                $app = $this->findById((int) $candidate);
                if ($app) return $app;
            }
        }

        foreach ([
            $content['app_slug'] ?? null,
            $content['application_slug'] ?? null,
            $request?->header('X-Peter-App'),
            $request?->header('X-App-Slug'),
            $request?->header('X-Application-Slug'),
            $request?->input('app_slug'),
            $request?->input('application_slug'),
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $app = $this->findBySlugOrHost(trim($candidate));
                if ($app) return $app;
            }
        }

        foreach ([
            $request?->headers->get('Origin'),
            $request?->headers->get('Referer'),
            $content['origin'] ?? null,
            $content['referer'] ?? null,
        ] as $url) {
            if (! is_string($url) || trim($url) === '') continue;
            $host = parse_url($url, PHP_URL_HOST) ?: $url;
            $app = $this->findBySlugOrHost($host);
            if ($app) return $app;
        }

        return null;
    }

    public function describe(?Request $request = null, $entity = null, array $content = []): array
    {
        $request ??= request();
        $app = $this->resolve($request, $entity, $content);

        return [
            'application' => $app,
            'origin' => $request?->headers->get('Origin'),
            'referer' => $request?->headers->get('Referer'),
            'declared_app' => $request?->header('X-Peter-App')
                ?: $request?->header('X-App-Slug')
                ?: $request?->header('X-Application-Slug'),
        ];
    }

    private function findById(int $id): ?Application
    {
        $key = 'id:' . $id;
        if (array_key_exists($key, self::$cache)) return self::$cache[$key];
        return self::$cache[$key] = Application::query()->find($id);
    }

    private function findBySlugOrHost(string $value): ?Application
    {
        $normalized = Str::lower(trim($value));
        $normalized = preg_replace('#^https?://#', '', $normalized);
        $normalized = trim((string) $normalized, '/');
        $host = explode('/', $normalized)[0];
        $slug = explode('.', $host)[0];
        $key = 'ctx:' . $normalized;

        if (array_key_exists($key, self::$cache)) return self::$cache[$key];

        $app = Application::query()
            ->whereRaw('LOWER(slug) = ?', [$normalized])
            ->orWhereRaw('LOWER(slug) = ?', [$slug])
            ->orWhere('url', 'like', '%' . $host . '%')
            ->first();

        return self::$cache[$key] = $app;
    }
}
