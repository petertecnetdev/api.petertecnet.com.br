<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreItemRequest;
use App\Http\Requests\Api\V1\UpdateItemRequest;
use App\Models\Establishment;
use App\Models\Item;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemController extends Controller
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Item::query()
            ->with('files')
            ->where('app_id', $this->context->id())
            ->where('status', true)
            ->where('entity_name', 'establishment');

        if ($request->filled('establishment_id')) {
            $query->where('entity_id', (int) $request->query('establishment_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('q')) {
            $term = '%' . trim((string) $request->query('q')) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('category', 'like', $term);
            });
        }

        $pageSize = min(
            max((int) $request->query('per_page', config('platform.default_page_size', 20)), 1),
            config('platform.max_page_size', 100)
        );

        $result = $query->latest('id')->paginate($pageSize);
        $result->getCollection()->each(fn (Item $item) => $item->setAppends(['image_url']));

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function catalog(string $establishmentSlug): JsonResponse
    {
        $establishment = $this->publicEstablishment($establishmentSlug);

        $items = Item::query()
            ->with('files')
            ->where('app_id', $this->context->id())
            ->where('entity_name', 'establishment')
            ->where('entity_id', $establishment->id)
            ->where('status', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        $items->each(fn (Item $item) => $item->setAppends(['image_url']));
        $establishment->setAppends([]);

        return response()->json([
            'success' => true,
            'data' => [
                'application' => [
                    'id' => $this->context->id(),
                    'slug' => $this->context->slug(),
                    'name' => $this->context->application()->name,
                ],
                'establishment' => $establishment,
                'items' => $items,
            ],
        ]);
    }

    public function mine(Request $request, int $establishment): JsonResponse
    {
        $owned = $this->ownedEstablishment($request, $establishment);

        $items = Item::query()
            ->with('files')
            ->where('app_id', $this->context->id())
            ->where('entity_name', 'establishment')
            ->where('entity_id', $owned->id)
            ->latest('id')
            ->get();

        $items->each(fn (Item $item) => $item->setAppends(['image_url']));

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function store(StoreItemRequest $request): JsonResponse
    {
        $establishment = $this->ownedEstablishment($request, (int) $request->validated('establishment_id'));
        $data = $request->safe()->except('establishment_id');
        $data['app_id'] = $this->context->id();
        $data['entity_name'] = 'establishment';
        $data['entity_id'] = $establishment->id;
        $data['user_id'] = $request->user()->id;
        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;
        $data['status'] = $data['status'] ?? true;
        $data['is_featured'] = false;

        $item = Item::create($data);
        $item->load('files')->setAppends(['image_url']);

        return response()->json([
            'success' => true,
            'message' => 'Item criado com sucesso.',
            'data' => $item,
        ], 201);
    }

    public function update(UpdateItemRequest $request, int $item): JsonResponse
    {
        $model = $this->ownedItem($request, $item);
        $data = $request->validated();
        $data['updated_by'] = $request->user()->id;
        $model->fill($data)->save();
        $model->load('files')->setAppends(['image_url']);

        return response()->json([
            'success' => true,
            'message' => 'Item atualizado com sucesso.',
            'data' => $model,
        ]);
    }

    public function destroy(Request $request, int $item): JsonResponse
    {
        $model = $this->ownedItem($request, $item);
        $model->update([
            'status' => false,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Item desativado com sucesso.',
        ]);
    }

    private function ownedEstablishment(Request $request, int $id): Establishment
    {
        return Establishment::query()
            ->whereKey($id)
            ->where('app_id', $this->context->id())
            ->where('user_id', $request->user()->id)
            ->where('is_cancelled', false)
            ->firstOrFail();
    }

    private function ownedItem(Request $request, int $id): Item
    {
        return Item::query()
            ->whereKey($id)
            ->where('app_id', $this->context->id())
            ->where('entity_name', 'establishment')
            ->whereIn('entity_id', function ($query) use ($request) {
                $query->select('id')
                    ->from('establishments')
                    ->where('app_id', $this->context->id())
                    ->where('user_id', $request->user()->id)
                    ->where('is_cancelled', false);
            })
            ->firstOrFail();
    }

    private function publicEstablishment(string $slug): Establishment
    {
        $query = Establishment::query()
            ->where('app_id', $this->context->id())
            ->where('slug', $slug)
            ->where('is_cancelled', false)
            ->where('is_published', true);

        if (in_array($this->context->slug(), config('platform.approval_required_apps', []), true)) {
            $query->where('is_approved', true);
        }

        return $query->firstOrFail();
    }
}
