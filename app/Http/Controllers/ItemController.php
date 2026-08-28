<?php

namespace App\Http\Controllers;

use App\Models\Establishment;
use App\Models\File;
use App\Models\Interaction;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ItemController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json(Item::query()->where('status', true)->latest()->paginate($data['per_page'] ?? 20));
    }

    public function listAll(Request $request)
    {
        $this->requirePermission('item_list');
        $data = $request->validate(['per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json(Item::query()->latest()->paginate($data['per_page'] ?? 100));
    }

    public function listByApp(int $app_id)
    {
        return response()->json(Item::query()->where('app_id', $app_id)->where('status', true)->latest()->paginate(20));
    }

    public function listByEntity(string $identifier)
    {
        $establishment = $this->findEstablishment($identifier);
        $items = Item::query()
            ->where('entity_name', 'establishment')
            ->where('entity_id', $establishment->id)
            ->where('status', true)
            ->with(['files' => fn ($q) => $q->where('visibility', 'public')->where('status', 'active')->orderBy('position')])
            ->orderByDesc('updated_at')
            ->get();

        return response()->json(['success' => true, 'message' => 'Itens listados com sucesso.', 'items' => $items]);
    }

    public function listOthers(string $identifier)
    {
        $establishment = $this->findEstablishment($identifier);
        $items = Item::query()
            ->where('entity_name', 'establishment')
            ->where('status', true)
            ->whereHas('establishment', fn ($q) => $q
                ->where('uf', $establishment->uf)
                ->where('city', $establishment->city)
                ->where('id', '!=', $establishment->id))
            ->with(['establishment', 'files' => fn ($q) => $q->where('visibility', 'public')->where('status', 'active')])
            ->latest()
            ->limit(100)
            ->get();

        return response()->json($items);
    }

    public function home(Request $request, $app_id)
    {
        $data = $request->validate([
            'city' => 'nullable|string|max:120',
            'uf' => 'nullable|string|max:2',
            'type' => 'nullable|string|max:100',
        ]);

        $query = Item::query()
            ->where('status', true)
            ->whereHas('establishment', function ($q) use ($app_id, $data) {
                $q->where('app_id', $app_id);
                if (! empty($data['city']) && $data['city'] !== 'Todas') {
                    $q->where('city', $data['city']);
                }
                if (! empty($data['uf']) && $data['uf'] !== 'ALL') {
                    $q->where('uf', strtoupper($data['uf']));
                }
            })
            ->when(! empty($data['type']), fn ($q) => $q->where('type', $data['type']))
            ->with([
                'files' => fn ($q) => $q->where('visibility', 'public')->where('status', 'active')->orderBy('position'),
                'establishment.files' => fn ($q) => $q->where('visibility', 'public')->where('status', 'active')->orderBy('position'),
            ]);

        return response()->json([
            'success' => true,
            'city' => $data['city'] ?? null,
            'uf' => $data['uf'] ?? null,
            'type' => $data['type'] ?? null,
            'items' => $query->get()->shuffle()->values(),
        ]);
    }

    public function show(int $id)
    {
        $item = Item::query()->where('status', true)->with(['files' => fn ($q) => $q->where('visibility', 'public')->where('status', 'active')])->findOrFail($id);
        return response()->json($item);
    }

    public function view(string $identifier)
    {
        $item = Item::query()
            ->where('status', true)
            ->when(is_numeric($identifier), fn ($q) => $q->where('id', (int) $identifier), fn ($q) => $q->where('slug', $identifier))
            ->with(['files' => fn ($q) => $q->where('visibility', 'public')->where('status', 'active')])
            ->firstOrFail();

        Interaction::registerView($item, Auth::user());

        return response()->json([
            'success' => true,
            'message' => 'Item encontrado com sucesso.',
            'item' => $item,
            'establishment' => $item->entity_name === 'establishment' ? Establishment::find($item->entity_id) : null,
        ]);
    }

    public function listServicesByEntity(Request $request)
    {
        $data = $request->validate([
            'entity_id' => 'required|integer|min:1',
            'entity_name' => 'required|string|max:100',
            'app_id' => 'required|integer|exists:applications,id',
        ]);

        return response()->json(['services' => Item::query()
            ->where($data)
            ->where('type', 'service')
            ->where('status', true)
            ->get()]);
    }

    public function store(Request $request)
    {
        $this->requirePermission('item_create');
        $data = $this->validateItem($request, true);
        $this->assertCanManageEntity($data['entity_name'], (int) $data['entity_id']);
        $user = Auth::user();

        $item = DB::transaction(function () use ($request, $data, $user) {
            $item = Item::create(array_merge($data, [
                'slug' => $this->uniqueSlug($data['name'], (int) $data['app_id']),
                'user_id' => $user->id,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]));

            if ($request->hasFile('image')) {
                File::storeOne($request->file('image'), 'item', $item->id, 'image', $item->app_id, $user->id);
            }
            return $item;
        });

        $this->clearCache();
        return response()->json(['message' => 'Item cadastrado com sucesso.', 'item' => $item->load('files')], 201);
    }

    public function storeBulk(Request $request)
    {
        $this->requirePermission('item_create');
        $data = $request->validate([
            'items' => 'required|array|min:1|max:200',
            'items.*.app_id' => 'required|integer|exists:applications,id',
            'items.*.entity_name' => 'required|string|max:100',
            'items.*.entity_id' => 'required|integer|min:1',
            'items.*.name' => 'required|string|max:255',
            'items.*.type' => 'required|string|max:100',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.status' => 'nullable|boolean',
        ]);

        foreach ($data['items'] as $row) {
            $this->assertCanManageEntity($row['entity_name'], (int) $row['entity_id']);
        }

        $user = Auth::user();
        $items = DB::transaction(function () use ($data, $user) {
            $created = [];
            foreach ($data['items'] as $row) {
                $created[] = Item::create(array_merge($row, [
                    'slug' => $this->uniqueSlug($row['name'], (int) $row['app_id']),
                    'status' => $row['status'] ?? true,
                    'user_id' => $user->id,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]));
            }
            return $created;
        });

        $this->clearCache();
        return response()->json(['message' => 'Itens cadastrados com sucesso.', 'items' => $items], 201);
    }

    public function update(Request $request, int $id)
    {
        $this->requirePermission('item_edit');
        $item = Item::with('files')->findOrFail($id);
        $this->assertCanManageItem($item);
        $data = $this->validateItem($request, false);

        if (isset($data['entity_name']) || isset($data['entity_id'])) {
            $entityName = $data['entity_name'] ?? $item->entity_name;
            $entityId = (int) ($data['entity_id'] ?? $item->entity_id);
            $this->assertCanManageEntity($entityName, $entityId);
        }

        DB::transaction(function () use ($request, $item, $data) {
            if (isset($data['name'])) {
                $data['slug'] = $this->uniqueSlug($data['name'], (int) ($data['app_id'] ?? $item->app_id), $item->id);
            }
            $item->fill($data);
            $item->updated_by = Auth::id();
            $item->save();

            if ($request->boolean('remove_image')) {
                $this->deleteItemImages($item);
            }
            if ($request->hasFile('image')) {
                $this->deleteItemImages($item);
                File::storeOne($request->file('image'), 'item', $item->id, 'image', $item->app_id, Auth::id());
            }
        });

        $this->clearCache();
        return response()->json(['message' => 'Item atualizado com sucesso.', 'item' => $item->fresh()->load('files')]);
    }

    public function destroy(int $id)
    {
        $this->requirePermission('item_delete');
        $item = Item::with('files')->findOrFail($id);
        $this->assertCanManageItem($item);

        DB::transaction(function () use ($item) {
            foreach ($item->files as $file) {
                if ($file->path) {
                    Storage::disk('public')->delete($file->path);
                }
                $file->delete();
            }
            $item->delete();
        });

        $this->clearCache();
        return response()->json(['message' => 'Item deletado com sucesso.']);
    }

    public function increasePricesByPercentage(Request $request)
    {
        return $this->changePrices($request, true);
    }

    public function decreasePricesByPercentage(Request $request)
    {
        return $this->changePrices($request, false);
    }

    private function changePrices(Request $request, bool $increase)
    {
        $this->requirePermission('item_edit');
        $data = $request->validate([
            'entity_id' => 'required|integer|min:1',
            'percentage' => 'required|numeric|min:0|max:100',
        ]);
        $this->assertCanManageEntity('establishment', (int) $data['entity_id']);

        $factor = $increase ? 1 + ($data['percentage'] / 100) : 1 - ($data['percentage'] / 100);
        Item::query()
            ->where('entity_id', $data['entity_id'])
            ->where('entity_name', 'establishment')
            ->get()
            ->each(function (Item $item) use ($factor) {
                $item->price = max(0, round((float) $item->price * $factor, 2));
                $item->updated_by = Auth::id();
                $item->save();
            });

        $this->clearCache();
        return response()->json(['message' => $increase ? 'Preços aumentados com sucesso.' : 'Preços reduzidos com sucesso.']);
    }

    private function validateItem(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        return $request->validate([
            'app_id' => "$required|integer|exists:applications,id",
            'entity_name' => "$required|string|max:100",
            'entity_id' => "$required|integer|min:1",
            'name' => "$required|string|max:255",
            'type' => "$required|string|max:100",
            'price' => "$required|numeric|min:0",
            'stock' => 'sometimes|nullable|integer|min:0',
            'status' => 'sometimes|boolean',
            'duration' => 'sometimes|nullable|integer|min:0|max:1440',
            'description' => 'sometimes|nullable|string|max:50000',
            'category' => 'sometimes|nullable|string|max:255',
            'subcategory' => 'sometimes|nullable|string|max:255',
            'brand' => 'sometimes|nullable|string|max:255',
            'availability_start' => 'sometimes|nullable|date',
            'availability_end' => 'sometimes|nullable|date|after_or_equal:availability_start',
            'is_featured' => 'sometimes|boolean',
            'discount' => 'sometimes|nullable|numeric|min:0',
            'expiration_date' => 'sometimes|nullable|date',
            'limited_by_user' => 'sometimes|boolean',
            'notes' => 'sometimes|nullable|string|max:10000',
            'tags' => 'sometimes|nullable|array',
            'image' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'remove_image' => 'sometimes|boolean',
        ]);
    }

    private function assertCanManageItem(Item $item): void
    {
        $user = Auth::user();
        if ($user->hasProfile('Administrador') || (int) $item->created_by === (int) $user->id || (int) $item->user_id === (int) $user->id) {
            return;
        }
        $this->assertCanManageEntity($item->entity_name, (int) $item->entity_id);
    }

    private function assertCanManageEntity(string $entityName, int $entityId): void
    {
        $user = Auth::user();
        if ($user->hasProfile('Administrador')) {
            return;
        }
        if ($entityName === 'establishment') {
            $establishment = Establishment::findOrFail($entityId);
            if ((int) $establishment->user_id === (int) $user->id || (int) $establishment->created_by === (int) $user->id) {
                return;
            }
        }
        abort(403, 'Você não pode alterar itens desta entidade.');
    }

    private function requirePermission(string $permission): void
    {
        $user = Auth::user();
        if (! $user || (! $user->hasProfile('Administrador') && ! $user->hasPermission($permission))) {
            abort(403, 'Permissão negada.');
        }
    }

    private function uniqueSlug(string $name, int $appId, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: Str::random(12);
        $slug = $base;
        $i = 2;
        while (Item::query()->where('app_id', $appId)->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    private function findEstablishment(string $identifier): Establishment
    {
        return Establishment::query()
            ->when(is_numeric($identifier), fn ($q) => $q->where('id', (int) $identifier), fn ($q) => $q->where('slug', $identifier))
            ->firstOrFail();
    }

    private function deleteItemImages(Item $item): void
    {
        foreach ($item->files()->where('type', 'image')->get() as $file) {
            if ($file->path) {
                Storage::disk('public')->delete($file->path);
            }
            $file->delete();
        }
    }

    private function clearCache(): void
    {
        try {
            Cache::tags(['items'])->flush();
        } catch (\Throwable $e) {
            // File/database cache stores do not support tags; avoid flushing unrelated app cache.
        }
    }
}
