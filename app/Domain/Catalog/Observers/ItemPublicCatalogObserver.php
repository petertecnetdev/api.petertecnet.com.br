<?php

namespace App\Domain\Catalog\Observers;

use App\Domain\Catalog\Support\PublicCatalogCache;
use App\Models\Establishment;
use App\Models\Item;
use Throwable;

final class ItemPublicCatalogObserver
{
    public function __construct(private readonly PublicCatalogCache $cache) {}

    public function saved(Item $item): void
    {
        $this->invalidate($item);
    }

    public function deleted(Item $item): void
    {
        $this->invalidate($item);
    }

    private function invalidate(Item $item): void
    {
        $applicationIds = collect([$item->app_id]);

        if ($item->entity_name === 'establishment' && $item->entity_id) {
            try {
                $establishment = Establishment::query()->find($item->entity_id);
                if ($establishment) {
                    $applicationIds->push($establishment->app_id);
                    $applicationIds = $applicationIds->merge(
                        $establishment->applications()->pluck('applications.id')
                    );
                }
            } catch (Throwable) {
                // Cache invalidation must never make a domain write fail.
            }
        }

        $this->cache->invalidateApplications($applicationIds);
    }
}
