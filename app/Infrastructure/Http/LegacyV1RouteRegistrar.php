<?php

namespace App\Infrastructure\Http;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

final class LegacyV1RouteRegistrar
{
    /**
     * Mirrors product-prefixed legacy routes below /api/v1/apps/{slug}.
     * Canonical v1 routes registered before this method always win.
     */
    public function register(array $applicationSlugs): void
    {
        $snapshot = collect(Route::getRoutes()->getRoutes());
        $reserved = $this->reservedV1($snapshot);

        foreach ($snapshot as $source) {
            $uri = $source->uri();

            foreach ($applicationSlugs as $slug) {
                $legacyPrefix = 'api/' . $slug;
                if ($uri !== $legacyPrefix && ! str_starts_with($uri, $legacyPrefix . '/')) {
                    continue;
                }

                $suffix = ltrim(substr($uri, strlen($legacyPrefix)), '/');
                $canonical = 'api/v1/apps/' . $slug . ($suffix !== '' ? '/' . $suffix : '');
                $compatibility = 'api/v1/apps/' . $slug . '/' . $slug . ($suffix !== '' ? '/' . $suffix : '');

                $this->mirror($source, $canonical, $slug, $reserved);
                $this->mirror($source, $compatibility, $slug, $reserved);
            }
        }
    }

    /**
     * Mirrors explicitly-approved shared legacy route families for first-party
     * applications. This is what allows a frontend to switch its Axios base to
     * /v1/apps/{slug} without a flag-day rewrite of every historical path.
     *
     * The fixed application middleware overwrites app_id and rejects conflicting
     * route/input app identifiers, so these adapters cannot be used to escape the
     * application's isolation boundary.
     */
    public function registerShared(array $prefixesByApplication): void
    {
        $snapshot = collect(Route::getRoutes()->getRoutes());
        $reserved = $this->reservedV1($snapshot);

        foreach ($snapshot as $source) {
            $uri = $source->uri();
            if (! str_starts_with($uri, 'api/') || str_starts_with($uri, 'api/v1/')) {
                continue;
            }

            $suffix = substr($uri, 4);
            $family = explode('/', $suffix, 2)[0] ?? '';
            if ($family === '') {
                continue;
            }

            foreach ($prefixesByApplication as $slug => $families) {
                if (! in_array($family, $families, true)) {
                    continue;
                }

                $this->mirror($source, 'api/v1/apps/' . $slug . '/' . $suffix, $slug, $reserved);
            }
        }
    }

    private function reservedV1(Collection $routes): Collection
    {
        return $routes
            ->filter(fn (LaravelRoute $route) => str_starts_with($route->uri(), 'api/v1/'))
            ->flatMap(fn (LaravelRoute $route) => collect($route->methods())
                ->map(fn (string $method) => $method . ' ' . $route->uri()))
            ->flip();
    }

    private function mirror(LaravelRoute $source, string $target, string $slug, Collection $reserved): void
    {
        $methods = array_values(array_diff($source->methods(), ['HEAD']));
        if ($methods === []) {
            return;
        }

        foreach ($methods as $method) {
            if ($reserved->has($method . ' ' . $target)) {
                return;
            }
        }

        $uses = $source->getAction('uses');
        if ($uses === null) {
            return;
        }

        $alias = Route::match($methods, $target, $uses);

        foreach ($source->wheres as $parameter => $expression) {
            $alias->where($parameter, $expression);
        }

        $middleware = (array) ($source->getAction('middleware') ?? []);
        $middleware = array_values(array_filter(
            $middleware,
            fn ($entry) => $entry !== 'legacy.deprecated'
        ));
        array_unshift($middleware, 'app.fixed:' . $slug);

        $alias->middleware(array_values(array_unique($middleware)));
        $alias->defaults('_peter_v1_adapter', true);

        foreach ($methods as $method) {
            $reserved->put($method . ' ' . $target, true);
        }
    }
}
