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
        $requiresApproval = in_array(
            $this->context->slug(),
            config('platform.approval_required_apps', []),
            true
        );

        $query = Item::query()
            ->with('files')
            ->where('status', true)
            ->where('entity_name', 'establishment')
            ->whereIn('entity_id', function ($subquery) use ($requiresApproval) {
                $subquery->select('establishments.id')
                    ->from('establishments')
                    ->where('is_cancelled', false)
                    ->where('is_published', true)
                    ->where(function ($query) {
                        $query->where('establishments.app_id', $this->context->id())
                            ->orWhereExists(function ($pivot) {
                                $pivot->selectRaw('1')
                                    ->from('application_establishment')
                                    ->whereColumn('application_establishment.establishment_id', 'establishments.id')
                                    ->where('application_establishment.application_id', $this->context->id());
                            });
                    });

                if ($requiresApproval) {
                    $subquery->where('is_approved', true);
                }
            });

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

        $result = $query
            ->orderByDesc('is_featured')
            ->orderBy('display_order')
            ->latest('id')
            ->paginate($pageSize);
        $result->getCollection()->each(fn (Item $item) => $item->setAppends(['image_url']));

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function catalog(string $establishmentSlug): JsonResponse
    {
        $establishment = $this->publicEstablishment($establishmentSlug);

        $items = Item::query()
            ->with('files')
            ->where('entity_name', 'establishment')
            ->where('entity_id', $establishment->id)
            ->where('status', true)
            ->orderByDesc('is_featured')
            ->orderBy('display_order')
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        $items->each(fn (Item $item) => $item->setAppends(['image_url']));
        $establishment->load(['files', 'app:id,name,slug', 'applications:id,name,slug']);
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
            ->where('entity_name', 'establishment')
            ->where('entity_id', $owned->id)
            ->orderByDesc('is_featured')
            ->orderBy('display_order')
            ->latest('id')
            ->get();

        $items->each(fn (Item $item) => $item->setAppends(['image_url']));

        return response()->json(['success' => true, 'data' => $items]);
    }

    public function store(StoreItemRequest $request): JsonResponse
    {
        $establishment = $this->ownedEstablishment($request, (int) $request->validated('establishment_id'));
        $data = $request->safe()->except('establishment_id');
        $displayOrder = (int) ($data['display_order'] ?? 0);
        unset($data['display_order']);
        $data['app_id'] = $establishment->app_id ?: $this->context->id();
        $data['entity_name'] = 'establishment';
        $data['entity_id'] = $establishment->id;
        $data['user_id'] = $request->user()->id;
        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;
        $data['status'] = $data['status'] ?? true;
        $data['is_featured'] = $data['is_featured'] ?? false;

        $item = Item::create($data);
        $item->forceFill(['display_order' => $displayOrder])->save();
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
        $displayOrder = array_key_exists('display_order', $data) ? (int) $data['display_order'] : null;
        unset($data['display_order']);
        $data['updated_by'] = $request->user()->id;
        $model->fill($data);
        if ($displayOrder !== null) {
            $model->forceFill(['display_order' => $displayOrder]);
        }
        $model->save();
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
            ->where('user_id', $request->user()->id)
            ->where('is_cancelled', false)
            ->where(function ($query) {
                $query->where('app_id', $this->context->id())
                    ->orWhereHas('applications', fn ($apps) => $apps->where('applications.id', $this->context->id()));
            })
            ->firstOrFail();
    }

    private function ownedItem(Request $request, int $id): Item
    {
        return Item::query()
            ->whereKey($id)
            ->where('entity_name', 'establishment')
            ->whereIn('entity_id', function ($query) use ($request) {
                $query->select('establishments.id')
                    ->from('establishments')
                    ->where('user_id', $request->user()->id)
                    ->where('is_cancelled', false)
                    ->where(function ($scope) {
                        $scope->where('establishments.app_id', $this->context->id())
                            ->orWhereExists(function ($pivot) {
                                $pivot->selectRaw('1')
                                    ->from('application_establishment')
                                    ->whereColumn('application_establishment.establishment_id', 'establishments.id')
                                    ->where('application_establishment.application_id', $this->context->id());
                            });
                    });
            })
            ->firstOrFail();
    }

    private function publicEstablishment(string $slug): Establishment
    {
        $query = Establishment::query()
            ->where('slug', $slug)
            ->where('is_cancelled', false)
            ->where(function ($scope) {
                $scope->where('app_id', $this->context->id())
                    ->orWhereHas('applications', fn ($apps) => $apps->where('applications.id', $this->context->id()));
            });

        if (in_array($this->context->slug(), config('platform.approval_required_apps', []), true)) {
            $query->where('is_approved', true);
        }

        return $query->firstOrFail();
    }
}
