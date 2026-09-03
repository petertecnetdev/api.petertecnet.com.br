<?php

namespace App\Domain\Catalog\Support;

use App\Models\Establishment;
use App\Models\Item;

final class CatalogPublicPayload
{
    public function establishment(Establishment $establishment): Establishment
    {
        // Discovery/catalog are public read paths. Administrative analytics must not
        // make these responses depend on cache writes, order metrics or interaction summaries.
        $establishment->makeHidden(['metrics']);

        if ($establishment->relationLoaded('files')) {
            $establishment->files->each(
                fn ($file) => $file->makeHidden(['metrics', 'interaction_summary'])
            );
        }

        return $establishment;
    }

    public function item(Item $item): Item
    {
        if ($item->relationLoaded('files')) {
            $item->files->each(
                fn ($file) => $file->makeHidden(['metrics', 'interaction_summary'])
            );
        }

        if ($item->relationLoaded('establishment') && $item->establishment) {
            $this->establishment($item->establishment);
        }

        return $item;
    }
}
