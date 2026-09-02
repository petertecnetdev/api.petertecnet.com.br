<?php

namespace App\Domain\Catalog\Services;

use App\Models\Establishment;
use App\Models\Item;
use App\Support\ApplicationContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class CatalogService
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function paginate(Request $request): LengthAwarePaginator
    {
        $query = Item::query()->where('app_id', $this->context->id())->where('status', true);
        if ($request->filled('establishment_id')) {
            $query->where('entity_name', 'establishment')->where('entity_id', (int) $request->query('establishment_id'));
        }
        if ($request->filled('q')) {
            $term = '%' . trim((string) $request->query('q')) . '%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('description', 'like', $term));
        }
        $perPage = min(max((int) $request->query('per_page', config('platform.default_page_size', 20)), 1), config('platform.max_page_size', 100));
        return $query->latest('id')->paginate($perPage);
    }

    public function catalog(string $establishmentSlug): array
    {
        $establishment = Establishment::query()
            ->where('app_id', $this->context->id())
            ->where('slug', $establishmentSlug)
            ->where('is_cancelled', false)
            ->where('is_published', true)
            ->firstOrFail();

        $items = Item::query()
            ->where('app_id', $this->context->id())
            ->where('entity_name', 'establishment')
            ->where('entity_id', $establishment->id)
            ->where('status', true)
            ->orderBy('name')
            ->get();

        return [$establishment, $items];
    }

    public function ownedItems(int $establishmentId, int $userId)
    {
        $establishment = $this->ownedEstablishment($establishmentId, $userId);
        return Item::query()
            ->where('app_id', $this->context->id())
            ->where('entity_name', 'establishment')
            ->where('entity_id', $establishment->id)
            ->latest('id')
            ->get();
    }

    public function create(array $data, int $userId): Item
    {
        $establishment = $this->ownedEstablishment((int) $data['establishment_id'], $userId);
        unset($data['establishment_id']);
        return Item::create(array_merge($data, [
            'app_id' => $this->context->id(),
            'entity_name' => 'establishment',
            'entity_id' => $establishment->id,
            'user_id' => $userId,
            'slug' => $data['slug'] ?? $this->uniqueSlug($data['name']),
            'status' => $data['status'] ?? true,
        ]));
    }

    public function update(int $itemId, array $data, int $userId): Item
    {
        $item = $this->ownedItem($itemId, $userId);
        if (isset($data['establishment_id'])) {
            $establishment = $this->ownedEstablishment((int) $data['establishment_id'], $userId);
            $item->entity_name = 'establishment';
            $item->entity_id = $establishment->id;
            unset($data['establishment_id']);
        }
        $item->fill($data)->save();
        return $item->fresh();
    }

    public function delete(int $itemId, int $userId): void
    {
        $item = $this->ownedItem($itemId, $userId);
        $item->update(['status' => false]);
    }

    private function ownedEstablishment(int $id, int $userId): Establishment
    {
        return Establishment::query()
            ->whereKey($id)
            ->where('app_id', $this->context->id())
            ->where('user_id', $userId)
            ->where('is_cancelled', false)
            ->firstOrFail();
    }

    private function ownedItem(int $id, int $userId): Item
    {
        return Item::query()
            ->whereKey($id)
            ->where('app_id', $this->context->id())
            ->where('user_id', $userId)
            ->firstOrFail();
    }

    private function uniqueSlug(string $value): string
    {
        $base = Str::slug($value) ?: 'item';
        $slug = $base;
        $counter = 2;
        while (Item::where('app_id', $this->context->id())->where('slug', $slug)->exists()) $slug = $base . '-' . $counter++;
        return $slug;
    }
}
