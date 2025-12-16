<?php

namespace App\Http\Controllers;

use App\Models\{
    Item,
    Establishment,
    File,
    Interaction
};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{
    Auth,
    DB,
    Cache,
    Storage
};
use Illuminate\Support\Str;

class ItemController extends Controller
{
    /* =======================================================
     | HELPERS
     ======================================================= */

    protected function messages(): array
    {
        return [
            'app_id.required' => 'O campo app_id é obrigatório.',
            'app_id.exists' => 'O aplicativo informado não existe.',
            'name.required' => 'O nome é obrigatório.',
            'type.required' => 'O tipo é obrigatório.',
            'price.required' => 'O preço é obrigatório.',
            'price.numeric' => 'O preço deve ser numérico.',
            'status.boolean' => 'O status deve ser booleano.',
            'entity_id.required' => 'A entidade é obrigatória.',
            'entity_name.required' => 'O nome da entidade é obrigatório.',
        ];
    }

    private function ensureAuth(string $permission): void
    {
        if (!Auth::check()) {
            abort(401, 'Usuário não autenticado.');
        }

        if (!Auth::user()->hasPermission($permission)) {
            abort(403, 'Permissão negada.');
        }
    }

    private function generateSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 1;

        while (
            Item::where('slug', $slug)
                ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    private function clearItemCache(): void
    {
        Cache::tags(['items'])->flush();
    }

    /* =======================================================
     | INDEX / LISTS
     ======================================================= */

    public function index()
    {
        return response()->json(
            Item::latest()->paginate(20)
        );
    }

    public function listAll()
    {
        $this->ensureAuth('item_view');

        return response()->json(
            Item::latest()->paginate(100)
        );
    }

    public function listByApp(int $app_id)
    {
        return response()->json(
            Item::where('app_id', $app_id)
                ->latest()
                ->paginate(20)
        );
    }

    public function listByEntitySlug(string $slug)
    {
        return $this->listByEntity($slug);
    }

    public function listByEntity(string $identifier)
    {
        return Cache::tags(['items'])->remember("entity_{$identifier}", 300, function () use ($identifier) {

            $establishment = Establishment::query()
                ->when(
                    is_numeric($identifier),
                    fn($q) => $q->where('id', (int) $identifier),
                    fn($q) => $q->where('slug', $identifier)
                )
                ->with([
                    'files' => fn($q) =>
                        $q->where('entity_name', 'establishment')->where('type', 'logo'),
                    'items' => fn($q) =>
                        $q->where('entity_name', 'establishment')
                            ->with(['files' => fn($fq) => $fq->where('entity_name', 'item')])
                            ->orderByDesc('updated_at'),
                ])
                ->firstOrFail();

            return [
                'message' => 'Itens listados com sucesso.',
                'establishment' => [
                    'id' => $establishment->id,
                    'name' => $establishment->name,
                    'fantasy' => $establishment->fantasy,
                    'slug' => $establishment->slug,
                    'city' => $establishment->city,
                    'uf' => $establishment->uf,
                    'logo' => $establishment->files->first()?->public_url ?? $establishment->logo,
                ],
                'items' => $establishment->items->map(fn($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'slug' => $item->slug,
                    'price' => $item->price,
                    'type' => $item->type,
                    'duration' => $item->duration,
                    'image' =>
                        $item->files->firstWhere('is_primary', true)?->public_url
                        ?? $item->files->first()?->public_url
                        ?? $item->image,
                    'updated_at' => $item->updated_at,
                ])->values(),
            ];
        });
    }

    public function home(int $app_id)
    {
        return Cache::tags(['items'])->remember("home_{$app_id}", 300, function () use ($app_id) {
            return Item::where('app_id', $app_id)
                ->where('entity_name', 'establishment')
                ->latest()
                ->limit(20)
                ->get();
        });
    }

    /* =======================================================
     | SHOW / VIEW
     ======================================================= */

    public function show(int $id)
    {
        $this->ensureAuth('item_view');

        return response()->json(
            Item::with('files')->findOrFail($id)
        );
    }

    public function view(string $slug)
    {
        return Cache::tags(['items'])->remember("view_{$slug}", 300, function () use ($slug) {

            $item = Item::with([
                'entity:id,name,slug,logo',
                'files' => fn($q) => $q->where('entity_name', 'item'),
            ])->where('slug', $slug)->firstOrFail();

            Interaction::registerView($item, Auth::user());

            return [
                'item' => $item,
                'entity' => $item->entity,
                'metrics' => $item->metrics,
            ];
        });
    }

    /* =======================================================
     | SERVICES
     ======================================================= */

    public function listServicesByEntity(Request $request)
    {
        $data = $request->validate([
            'entity_id' => 'required|integer',
            'entity_name' => 'required|string',
            'app_id' => 'required|integer',
        ]);

        return response()->json([
            'services' => Item::where('entity_id', $data['entity_id'])
                ->where('entity_name', $data['entity_name'])
                ->where('app_id', $data['app_id'])
                ->where('type', 'service')
                ->get(),
        ]);
    }

    /* =======================================================
     | STORE
     ======================================================= */

    public function store(Request $request)
    {
        $this->ensureAuth('item_create');
        $user = Auth::user();

        $data = $request->validate([
            'app_id' => 'required|exists:applications,id',
            'name' => 'required|string|max:255',
            'type' => 'required|string|max:100',
            'price' => 'required|numeric|min:0',
            'status' => 'required|boolean',
            'entity_id' => 'required|integer',
            'entity_name' => 'required|string|max:100',
        ], $this->messages());

        return DB::transaction(function () use ($data, $user) {

            $item = Item::create([
                ...$data,
                'slug' => $this->generateSlug($data['name']),
                'user_id' => $user->id,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $this->clearItemCache();

            return response()->json([
                'message' => 'Item cadastrado com sucesso.',
                'item' => $item,
            ], 201);
        });
    }

    public function storeBulk(Request $request)
    {
        $this->ensureAuth('item_create');
        $user = Auth::user();

        $data = $request->validate([
            'items' => 'required|array',
            'items.*.name' => 'required|string|max:255',
            'items.*.type' => 'required|string|max:100',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.entity_id' => 'required|integer',
            'items.*.entity_name' => 'required|string|max:100',
            'items.*.app_id' => 'required|integer',
        ]);

        return DB::transaction(function () use ($data, $user) {

            $created = [];

            foreach ($data['items'] as $itemData) {
                $created[] = Item::create([
                    ...$itemData,
                    'slug' => $this->generateSlug($itemData['name']),
                    'user_id' => $user->id,
                ]);
            }

            $this->clearItemCache();

            return response()->json([
                'message' => 'Itens cadastrados com sucesso.',
                'items' => $created,
            ], 201);
        });
    }

    /* =======================================================
     | UPDATE / DELETE
     ======================================================= */

    public function update(Request $request, int $id)
    {
        $this->ensureAuth('item_update');
        $item = Item::findOrFail($id);

        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'status' => 'nullable|boolean',
        ]);

        if (!empty($data['name'])) {
            $data['slug'] = $this->generateSlug($data['name'], $item->id);
        }

        $item->update($data);

        Interaction::registerUpdate($item, Auth::user(), $data);
        $this->clearItemCache();

        return response()->json([
            'message' => 'Item atualizado com sucesso.',
            'item' => $item,
        ]);
    }

    public function destroy(int $id)
    {
        $this->ensureAuth('item_delete');
        $item = Item::with('files')->findOrFail($id);

        return DB::transaction(function () use ($item) {

            foreach ($item->files as $file) {
                if ($file->storage === 'public' && $file->path) {
                    Storage::disk('public')->delete($file->path);
                }
                $file->delete();
            }

            $item->delete();
            $this->clearItemCache();

            return response()->json(['message' => 'Item deletado com sucesso.']);
        });
    }

    /* =======================================================
     | MASS PRICE UPDATE
     ======================================================= */

    public function increasePricesByPercentage(Request $request)
    {
        $this->ensureAuth('item_update');

        $data = $request->validate([
            'entity_id' => 'required|integer',
            'percentage' => 'required|numeric|min:0',
        ]);

        Item::where('entity_id', $data['entity_id'])
            ->where('entity_name', 'establishment')
            ->update([
                'price' => DB::raw("ROUND(price * (1 + {$data['percentage']} / 100), 2)")
            ]);

        $this->clearItemCache();

        return response()->json(['message' => 'Preços aumentados com sucesso.']);
    }

    public function decreasePricesByPercentage(Request $request)
    {
        $this->ensureAuth('item_update');

        $data = $request->validate([
            'entity_id' => 'required|integer',
            'percentage' => 'required|numeric|min:0|max:100',
        ]);

        Item::where('entity_id', $data['entity_id'])
            ->where('entity_name', 'establishment')
            ->update([
                'price' => DB::raw("GREATEST(0, ROUND(price * (1 - {$data['percentage']} / 100), 2))")
            ]);

        $this->clearItemCache();

        return response()->json(['message' => 'Preços reduzidos com sucesso.']);
    }
}
