<?php

namespace App\Domain\Catalog\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class PublicCatalogCache
{
    public function remember(int $applicationId, string $namespace, array $context, Closure $resolver): mixed
    {
        $ttl = max(0, (int) config('public_catalog.cache_ttl_seconds', 60));

        if ($ttl === 0) {
            return $resolver();
        }

        ksort($context);
        $generation = (string) Cache::get($this->generationKey($applicationId), '0');
        $fingerprint = sha1((string) json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $key = "public-catalog:v3:app:{$applicationId}:generation:{$generation}:{$namespace}:{$fingerprint}";

        return Cache::remember($key, now()->addSeconds($ttl), $resolver);
    }

    public function invalidateApplication(int $applicationId): void
    {
        if ($applicationId <= 0) {
            return;
        }

        Cache::forever($this->generationKey($applicationId), (string) Str::uuid());
    }

    public function invalidateApplications(iterable $applicationIds): void
    {
        collect($applicationIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->each(fn ($id) => $this->invalidateApplication($id));
    }

    private function generationKey(int $applicationId): string
    {
        return "public-catalog:generation:app:{$applicationId}";
    }
}
