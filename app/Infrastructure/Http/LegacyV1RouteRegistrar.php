<?php

namespace App\Infrastructure\Http;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

final class LegacyV1RouteRegistrar
{
    /**
     * Mirrors product-specific legacy routes below /api/v1/apps/{slug} without
     * changing the original controllers. These are migration adapters only;
     * canonical v1 routes registered before this method always win.
     */
    public function register(array $applicationSlugs): void
    {
        $snapshot = collect(Route::getRoutes()->getRoutes());
        $reserved = $snapshot
            ->filter(fn (LaravelRoute $route) => str_starts_with($route->uri(), 'api/v1/'))
            ->flatMap(fn (LaravelRoute $route) => collect($route->methods())
                ->map(fn (string $method) => $method . ' ' . $route->uri()))
            ->flip();

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

    private function mirror(LaravelRoute $source, string $target, string $slug, $reserved): void
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
    }
}
