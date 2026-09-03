<?php

namespace App\Domain\Catalog\Observers;

use App\Domain\Catalog\Support\PublicCatalogCache;
use App\Models\Establishment;
use Throwable;

final class EstablishmentPublicCatalogObserver
{
    public function __construct(private readonly PublicCatalogCache $cache) {}

    public function retrieved(Establishment $establishment): void
    {
        $this->disableImplicitMetrics($establishment);
    }

    public function creating(Establishment $establishment): void
    {
        $this->disableImplicitMetrics($establishment);
    }

    public function saved(Establishment $establishment): void
    {
        $this->disableImplicitMetrics($establishment);
        $this->invalidate($establishment);
    }

    public function deleted(Establishment $establishment): void
    {
        $this->invalidate($establishment);
    }

    public function restored(Establishment $establishment): void
    {
        $this->disableImplicitMetrics($establishment);
        $this->invalidate($establishment);
    }

    private function disableImplicitMetrics(Establishment $establishment): void
    {
        $establishment->setAppends(
            collect($establishment->getAppends())
                ->reject(fn ($append) => $append === 'metrics')
                ->values()
                ->all()
        );
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
