<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\{
    Item,
    Establishment,
    File,
    Interaction
};
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{
    Auth,
    DB,
    Cache,
    Storage,
    Validator
};
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;



class ItemController extends Controller
{
    /* =======================================================
     | HELPERS
     ======================================================= */

    protected function validationMessages(): array
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
        try {
            Cache::tags(['items'])->flush();
        } catch (\BadMethodCallException $e) {
            Cache::flush();
        }
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
        return Cache::remember("items_entity_{$identifier}", 300, function () use ($identifier) {

            $establishment = Establishment::query()
                ->when(
                    is_numeric($identifier),
                    fn($q) => $q->where('id', (int) $identifier),
                    fn($q) => $q->where('slug', $identifier)
                )
                ->with([
                    'files' => fn($q) =>
                        $q->where('entity_name', 'establishment')
                            ->where('type', 'logo'),

                    'items' => fn($q) =>
                        $q->where('entity_name', 'establishment')
                            ->with([
                                'files' => fn($fq) =>
                                    $fq->where('entity_name', 'item')
                            ])
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
                    'logo' =>
                        $establishment->files->first()?->public_url
                        ?? $establishment->logo,
                ],
                'items' => $establishment->items->map(fn($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'slug' => $item->slug,
                    'price' => $item->price,
                    'type' => $item->type,
                    'duration' => $item->duration,
                    'image' => $item->image_resolved,
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
                ->with([
                    'files' => fn($q) =>
                        $q->where('entity_name', 'item')->orderBy('position'),
                ])
                ->latest()
                ->limit(20)
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'entity_id' => $item->entity_id,
                        'type' => 'item',
                        'name' => $item->name,
                        'slug' => $item->slug,
                        'price' => $item->price,
                        'item_type' => $item->type,
                        'image' => $item->image_resolved,
                        'updated_at' => $item->updated_at,
                    ];
                })

                ->values();
        });
    }


    /* =======================================================
     | SHOW / VIEW
     ======================================================= */

    public function show(int $id)
    {

        $item = Item::with('files')->findOrFail($id);

        return response()->json([
            'id' => $item->id,
            'name' => $item->name,
            'slug' => $item->slug,
            'type' => $item->type,
            'price' => $item->price,
            'stock' => $item->stock,
            'status' => $item->status,
            'duration' => $item->duration,
            'description' => $item->description,
            'category' => $item->category,
            'subcategory' => $item->subcategory,
            'brand' => $item->brand,
            'availability_start' => $item->availability_start,
            'availability_end' => $item->availability_end,
            'discount' => $item->discount,
            'expiration_date' => $item->expiration_date,
            'limited_by_user' => $item->limited_by_user,
            'is_featured' => $item->is_featured,
            'notes' => $item->notes,

            'image' => $item->image_resolved,

            'files' => $item->files->map(fn($file) => [
                'id' => $file->id,
                'type' => $file->type,
                'public_url' => $file->public_url,
                'is_primary' => $file->is_primary ?? false,
            ])->values(),
        ]);
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

        $validator = Validator::make($request->all(), [
            'app_id' => 'required|exists:applications,id',
            'name' => 'required|string|max:255',
            'type' => 'required|string|max:100',
            'price' => 'required|numeric|min:0',
            'status' => 'required|boolean',
            'entity_id' => 'required|integer',
            'entity_name' => 'required|string|max:100',

            'duration' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:255',
            'subcategory' => 'nullable|string|max:255',
            'brand' => 'nullable|string|max:255',
            'notes' => 'nullable|string',

            'image' => 'nullable|image|max:5120',
        ], $this->validationMessages());

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return DB::transaction(function () use ($request, $user) {

            $item = Item::create([
                ...$request->except(['image']),
                'slug' => $this->generateSlug($request->name),
                'user_id' => $user->id,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            if ($request->hasFile('image')) {
                File::storeOne(
                    $request->file('image'),
                    'item',
                    $item->id,
                    'image',
                    $item->app_id,
                    $user->id
                );
            }

            $this->clearItemCache();

            return response()->json([
                'message' => 'Item cadastrado com sucesso.',
                'item' => $item->load('files'),
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
    public function update(Request $request, int $id)
    {
        $this->ensureAuth('item_update');

        $item = \App\Models\Item::with('files')->findOrFail($id);

        $validator = \Illuminate\Support\Facades\Validator::make(
            $request->all(),
            [
                'name' => 'nullable|string|max:255',
                'type' => 'nullable|string|max:50',
                'price' => 'nullable|numeric|min:0',
                'stock' => 'nullable|integer|min:0',
                'status' => 'nullable|boolean',
                'duration' => 'nullable|integer|min:0',
                'description' => 'nullable|string',
                'category' => 'nullable|string|max:255',
                'subcategory' => 'nullable|string|max:255',
                'brand' => 'nullable|string|max:255',
                'availability_start' => 'nullable|date',
                'availability_end' => 'nullable|date',
                'is_featured' => 'nullable|boolean',
                'discount' => 'nullable|numeric|min:0',
                'expiration_date' => 'nullable|date',
                'limited_by_user' => 'nullable|boolean',
                'notes' => 'nullable|string',
                'image' => 'nullable|image|max:5120',
                'remove_image' => 'nullable|boolean',
            ],
            $this->validationMessages()
        );

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Erro de validação.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $item) {

                $data = $request->only([
                    'name',
                    'type',
                    'price',
                    'stock',
                    'duration',
                    'description',
                    'category',
                    'subcategory',
                    'brand',
                    'availability_start',
                    'availability_end',
                    'discount',
                    'expiration_date',
                    'notes',
                ]);

                if ($request->has('status')) {
                    $parsed = filter_var($request->input('status'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    $data['status'] = $parsed ?? (bool) $item->status;
                }

                if ($request->has('is_featured')) {
                    $parsed = filter_var($request->input('is_featured'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    $data['is_featured'] = $parsed ?? (bool) $item->is_featured;
                }

                if ($request->has('limited_by_user')) {
                    $parsed = filter_var($request->input('limited_by_user'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    $data['limited_by_user'] = $parsed ?? (bool) $item->limited_by_user;
                }

                $item->fill($data);

                if ($request->filled('name')) {
                    $item->slug = $this->generateSlug($request->input('name'), $item->id);
                }

                $item->updated_by = \Illuminate\Support\Facades\Auth::id();
                $item->save();

                $removeImage = filter_var($request->input('remove_image'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;

                if ($removeImage) {
                    $item->files()
                        ->where('entity_name', 'item')
                        ->where('type', 'image')
                        ->delete();

                    $item->image = null;
                    $item->save();
                }

                if ($request->hasFile('image')) {
                    $item->files()
                        ->where('entity_name', 'item')
                        ->where('type', 'image')
                        ->delete();

                    \App\Models\File::storeOne(
                        $request->file('image'),
                        'item',
                        $item->id,
                        'image',
                        $item->app_id,
                        \Illuminate\Support\Facades\Auth::id()
                    );
                }

                $this->clearItemCache();

                return response()->json([
                    'message' => 'Item atualizado com sucesso.',
                    'item' => $item->load('files'),
                ]);
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[ITEM UPDATE] ERRO FINAL', [
                'item_id' => $id,
                'user_id' => \Illuminate\Support\Facades\Auth::id(),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'error' => 'Erro interno.',
            ], 500);
        }
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
