<?php

namespace App\Domain\Catalog\Observers;

use App\Domain\Catalog\Support\PublicCatalogCache;
use App\Models\Establishment;
use Throwable;

final class EstablishmentPublicCatalogObserver
{
    public function __construct(private readonly PublicCatalogCache $cache) {}

    public function saved(Establishment $establishment): void
    {
        $this->invalidate($establishment);
    }

    public function deleted(Establishment $establishment): void
    {
        $this->invalidate($establishment);
    }

    public function restored(Establishment $establishment): void
    {
        $this->invalidate($establishment);
    }

    private function invalidate(Establishment $establishment): void
    {
        $applicationIds = collect([$establishment->app_id]);

        try {
            if ($establishment->exists) {
                $applicationIds = $applicationIds->merge(
                    $establishment->applications()->pluck('applications.id')
                );
            }
        } catch (Throwable) {
            // Cache invalidation must never make a domain write fail.
        }

        $this->cache->invalidateApplications($applicationIds);
    }
}
