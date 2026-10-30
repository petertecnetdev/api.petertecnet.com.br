<?php

namespace App\Domain\Catalog\Services;

use App\Models\Interaction;
use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Auth\Authenticatable;

final class CatalogEngagementService
{
    public function record(int $appId, string $identifier, array $data, ?Authenticatable $actor): void
    {
        $item = Item::query()
            ->where('entity_name', 'establishment')
            ->where('status', true)
            ->whereNull('archived_at')
            ->whereHas('establishment', fn (Builder $builder) => $builder->forApplication($appId))
            ->when(
                is_numeric($identifier),
                fn (Builder $builder) => $builder->whereKey((int) $identifier),
                fn (Builder $builder) => $builder->where('slug', $identifier)
            )
            ->firstOrFail();

        $metadata = collect($data['metadata'] ?? [])
            ->take(30)
            ->map(function ($value) {
                if (is_scalar($value) || $value === null) {
                    return is_string($value) ? mb_substr($value, 0, 1000) : $value;
                }

                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            })
            ->all();

        Interaction::register(
            $data['type'],
            $item,
            $actor,
            array_merge($metadata, [
                'catalog_item_slug' => $item->slug,
                'catalog_establishment_id' => $item->entity_id,
            ]),
            $data['label'] ?? $item->name
        );
    }
}
