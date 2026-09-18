<?php

namespace App\Domain\Catalog\Services;

use App\Models\Establishment;
use App\Models\Item;
use App\Support\ApplicationContext;
use Illuminate\Pagination\LengthAwarePaginator;

final class OwnedItemPaginationService
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function paginate(int $userId, int $establishmentId, array $filters = []): LengthAwarePaginator
    {
        $establishment = Establishment::query()
            ->whereKey($establishmentId)
            ->forApplication($this->context->id())
            ->where('user_id', $userId)
            ->where('is_cancelled', false)
            ->firstOrFail();

        $query = Item::query()
            ->with('files')
            ->where('entity_name', 'establishment')
            ->where('entity_id', $establishment->id)
            ->where('status', true)
            ->latest('id');

        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $like = '%'.$term.'%';
            $query->where(function ($itemQuery) use ($like) {
                $itemQuery
                    ->where('name', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('category', 'like', $like);
            });
        }

        $pageSize = min(
            max((int) ($filters['per_page'] ?? config('platform.default_page_size', 20)), 1),
            config('platform.max_page_size', 100)
        );

        $items = $query->paginate($pageSize);
        $items->getCollection()->each(fn (Item $item) => $item->setAppends(['image_url']));

        return $items;
    }
}
