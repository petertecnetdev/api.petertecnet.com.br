<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Services\CatalogService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreItemRequest;
use App\Http\Requests\Api\V1\UpdateItemRequest;
use App\Http\Resources\Api\V1\EstablishmentResource;
use App\Http\Resources\Api\V1\ItemResource;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CatalogService $catalog,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->catalog->paginate($request);
        $result->setCollection($result->getCollection()->map(fn ($item) => (new ItemResource($item))->resolve($request)));
        return response()->json(['success' => true, 'data' => $result]);
    }

    public function catalog(Request $request, string $establishmentSlug): JsonResponse
    {
        [$establishment, $items] = $this->catalog->catalog($establishmentSlug);
        return response()->json([
            'success' => true,
            'data' => [
                'application' => [
                    'id' => $this->context->id(),
                    'slug' => $this->context->slug(),
                    'name' => $this->context->application()->name,
                ],
                'establishment' => (new EstablishmentResource($establishment))->resolve($request),
                'items' => $items->map(fn ($item) => (new ItemResource($item))->resolve($request))->values(),
            ],
        ]);
    }

    public function mine(Request $request, int $establishment): JsonResponse
    {
        $items = $this->catalog->ownedItems($establishment, $request->user()->id);
        return response()->json([
            'success' => true,
            'data' => $items->map(fn ($item) => (new ItemResource($item))->resolve($request))->values(),
        ]);
    }

    public function store(StoreItemRequest $request): JsonResponse
    {
        $item = $this->catalog->create($request->validated(), $request->user()->id);
        return response()->json([
            'success' => true,
            'message' => 'Item criado com sucesso.',
            'data' => (new ItemResource($item))->resolve($request),
        ], 201);
    }

    public function update(UpdateItemRequest $request, int $item): JsonResponse
    {
        $model = $this->catalog->update($item, $request->validated(), $request->user()->id);
        return response()->json([
            'success' => true,
            'message' => 'Item atualizado com sucesso.',
            'data' => (new ItemResource($model))->resolve($request),
        ]);
    }

    public function destroy(Request $request, int $item): JsonResponse
    {
        $this->catalog->delete($item, $request->user()->id);
        return response()->json(['success' => true, 'message' => 'Item desativado com sucesso.']);
    }
}
