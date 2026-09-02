<?php

namespace App\Domain\Catalog\Services;

use App\Models\Establishment;
use App\Models\Item;
use App\Support\ApplicationContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

final class CatalogService
{
    public function __construct(private readonly ApplicationContext $context) {}

    public function paginate(Request $request): LengthAwarePaginator
    {
        $requiresApproval = in_array($this->context->slug(), config('platform.approval_required_apps', []), true);
        $query = Item::query()
            ->with('files')
            ->where('app_id', $this->context->id())
            ->where('status', true)
            ->where('entity_name', 'establishment')
            ->whereIn('entity_id', function ($subquery) use ($requiresApproval) {
                $subquery->select('id')->from('establishments')
                    ->where('app_id', $this->context->id())
                    ->where('is_cancelled', false)
                    ->where('is_published', true);
                if ($requiresApproval) $subquery->where('is_approved', true);
            });

        if ($request->filled('establishment_id')) $query->where('entity_id', (int) $request->query('establishment_id'));
        if ($request->filled('type')) $query->where('type', $request->query('type'));
        if ($request->filled('q')) {
            $term = '%' . trim((string) $request->query('q')) . '%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('description', 'like', $term)->orWhere('category', 'like', $term));
        }
        $perPage = min(max((int) $request->query('per_page', config('platform.default_page_size', 20)), 1), config('platform.max_page_size', 100));
        return $query->latest('id')->paginate($perPage);
    }

    public function catalog(string $establishmentSlug): array
    {
        $establishment = $this->publicEstablishment($establishmentSlug);
        $items = Item::query()->with('files')
            ->where('app_id', $this->context->id())
            ->where('entity_name', 'establishment')
            ->where('entity_id', $establishment->id)
            ->where('status', true)
            ->orderBy('category')->orderBy('name')->get();
        return [$establishment, $items];
    }

    public function ownedItems(int $establishmentId, int $userId)
    {
        $establishment = $this->ownedEstablishment($establishmentId, $userId);
        return Item::query()->with('files')
            ->where('app_id', $this->context->id())
            ->where('entity_name', 'establishment')
            ->where('entity_id', $establishment->id)
            ->latest('id')->get();
    }

    public function create(array $data, int $userId): Item
    {
        $establishment = $this->ownedEstablishment((int) $data['establishment_id'], $userId);
        unset($data['establishment_id']);
        $data['app_id'] = $this->context->id();
        $data['entity_name'] = 'establishment';
        $data['entity_id'] = $establishment->id;
        $data['user_id'] = $userId;
        $data['created_by'] = $userId;
        $data['updated_by'] = $userId;
        $data['status'] = $data['status'] ?? true;
        $data['is_featured'] = false;
        return Item::create($data)->load('files');
    }

    public function update(int $itemId, array $data, int $userId): Item
    {
        $item = $this->ownedItem($itemId, $userId);
        $data['updated_by'] = $userId;
        $item->fill($data)->save();
        return $item->fresh()->load('files');
    }

    public function delete(int $itemId, int $userId): void
    {
        $item = $this->ownedItem($itemId, $userId);
        $item->update(['status' => false, 'updated_by' => $userId]);
    }

    private function ownedEstablishment(int $id, int $userId): Establishment
    {
        return Establishment::query()->whereKey($id)->where('app_id', $this->context->id())
            ->where('user_id', $userId)->where('is_cancelled', false)->firstOrFail();
    }

    private function ownedItem(int $id, int $userId): Item
    {
        return Item::query()->whereKey($id)->where('app_id', $this->context->id())
            ->where('entity_name', 'establishment')
            ->whereIn('entity_id', function ($query) use ($userId) {
                $query->select('id')->from('establishments')->where('app_id', $this->context->id())
                    ->where('user_id', $userId)->where('is_cancelled', false);
            })->firstOrFail();
    }

    private function publicEstablishment(string $slug): Establishment
    {
        $query = Establishment::query()->where('app_id', $this->context->id())->where('slug', $slug)
            ->where('is_cancelled', false)->where('is_published', true);
        if (in_array($this->context->slug(), config('platform.approval_required_apps', []), true)) $query->where('is_approved', true);
        return $query->firstOrFail();
    }
}
