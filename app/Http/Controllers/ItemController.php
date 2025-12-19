<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\{
    Item,
    Establishment,
    Employer,
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

            $establishment = $establishment = Establishment::query()
                ->when(
                    is_numeric($identifier),
                    fn($q) => $q->where('id', (int) $identifier),
                    fn($q) => $q->where('slug', $identifier)
                )
                ->with([
                    'files' => fn($q) =>
                        $q->where('entity_name', 'establishment')
                            ->where('type', 'logo')
                            ->orderBy('position'),

                    'items' => fn($q) =>
                        $q->with([
                            'files' => fn($fq) =>
                                $fq->where('entity_name', 'item')
                                    ->orderBy('position'),
                        ])->orderByDesc('updated_at'),
                ])
                ->firstOrFail();

            // Map para o formato igual à home
            $items = $establishment->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'entity_id' => $item->entity_id,
                    'type' => 'item',
                    'name' => $item->name,
                    'slug' => $item->slug,
                    'price' => $item->price,
                    'item_type' => $item->type,
                    'image' => $item->files->first()?->path_resolved ?? null,
                    'updated_at' => $item->updated_at,
                ];
            })->values();

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
                    'image' => $item->image_url, // usa o accessor da model
                    'updated_at' => $item->updated_at,
                ])->values(),
            ];
        });
    }



    public function home(int $app_id)
    {
        return Cache::remember("items:home:{$app_id}", 300, function () use ($app_id) {
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
        $cacheKey = "view_item_{$slug}";
        $ttl = 300;

        $remember = function () use ($slug) {
            $item = Item::with([
                'entity:id,name,slug,logo,city,uf,background',
                'files' => fn($q) => $q->where('entity_name', 'item')->orderBy('position'),
            ])->where('slug', $slug)->firstOrFail();

            Interaction::registerView($item, Auth::user());

            $metrics = $item->metrics ?? [];

            $interactionSummary = [
                'views' => $metrics['views'] ?? 0,
                'likes' => $metrics['likes'] ?? 0,
                'favorites' => $metrics['favorites'] ?? 0,
            ];

            $userInteractions = Auth::check()
                ? Interaction::where('user_id', Auth::id())
                    ->where('entity_type', 'Item')
                    ->where('entity_id', $item->id)
                    ->get()
                : collect();

            $ordersSummary = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->join('items', 'items.id', '=', 'order_items.item_id')
                ->where('order_items.item_id', $item->id)
                ->selectRaw('COUNT(DISTINCT orders.id) as total_orders, COALESCE(SUM(items.price * order_items.quantity), 0) as total_amount')
                ->first() ?? (object) ['total_orders' => 0, 'total_amount' => 0];

            $otherItems = Item::where('entity_id', $item->entity_id)
                ->where('id', '!=', $item->id)
                ->with(['files' => fn($q) => $q->where('entity_name', 'item')->orderBy('position')])
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn($i) => [
                    'id' => $i->id,
                    'entity_id' => $i->entity_id,
                    'type' => 'item',
                    'name' => $i->name,
                    'slug' => $i->slug,
                    'price' => $i->price,
                    'item_type' => $i->type,
                    'image' => $i->files->first()?->path_resolved ?? null,
                    'updated_at' => $i->updated_at,
                ])
                ->values();

            $otherEstablishments = Establishment::where('id', '!=', $item->entity_id)
                ->where('city', $item->entity->city)
                ->where('uf', $item->entity->uf)
                ->with(['files' => fn($q) => $q->where('entity_name', 'establishment')->where('type', 'logo')->orderBy('position')])
                ->latest()
                ->limit(6)
                ->get()
                ->map(fn($est) => [
                    'id' => $est->id,
                    'name' => $est->name,
                    'slug' => $est->slug,
                    'logo' => $est->files->first()?->path_resolved ?? $est->logo ?? null,
                    'city' => $est->city,
                    'uf' => $est->uf,
                    'updated_at' => $est->updated_at,
                ]);

            $itemEmployersQuery = Employer::with([
                'user.files' => fn($q) => $q->where('entity_name', 'user')->where('type', 'avatar')
            ])->where('establishment_id', $item->entity_id);

            if ($item->type === 'service') {
                $itemEmployersQuery->whereHas('services', fn($q) => $q->where('item_id', $item->id));
            }

            $otherEmployers = $itemEmployersQuery->limit(6)
                ->get()
                ->map(fn($employer) => [
                    'employer_id' => $employer->id,
                    'user_id' => $employer->user->id ?? null,
                    'first_name' => $employer->user->first_name ?? null,
                    'last_name' => $employer->user->last_name ?? null,
                    'user_name' => $employer->user->user_name ?? null,
                    'email' => $employer->user->email ?? null,
                    'avatar' => $employer->user->files->first()?->path_resolved ?? $employer->user->avatar ?? null,
                ]);

            return response()->json([
                'item' => $item,
                'establishment' => $item->entity,
                'metrics' => $metrics,
                'interaction_summary' => $interactionSummary,
                'user_interactions' => $userInteractions,
                'orders_summary' => $ordersSummary,
                'other_establishments' => $otherEstablishments,
                'other_employers' => $otherEmployers,
                'other_items' => $otherItems,
                'top_employer' => null,
            ]);
        };

        try {
            return Cache::tags(['items'])->remember($cacheKey, $ttl, $remember);
        } catch (\BadMethodCallException $e) {
            return Cache::remember($cacheKey, $ttl, $remember);
        }
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
    public function update(\Illuminate\Http\Request $request, int $id)
    {
        \Illuminate\Support\Facades\Log::info('[ITEM UPDATE] INÍCIO', [
            'item_id' => $id,
            'user_id' => \Illuminate\Support\Facades\Auth::id(),
            'payload_keys' => array_keys($request->all()),
            'has_image' => $request->hasFile('image'),
            'remove_image' => $request->input('remove_image'),
        ]);

        $this->ensureAuth('item_update');

        $item = \App\Models\Item::with('files')->findOrFail($id);

        \Illuminate\Support\Facades\Log::info('[ITEM UPDATE] ITEM CARREGADO', [
            'item_id' => $item->id,
            'app_id' => $item->app_id,
        ]);

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
            \Illuminate\Support\Facades\Log::warning('[ITEM UPDATE] FALHA DE VALIDAÇÃO', [
                'errors' => $validator->errors()->toArray(),
            ]);

            return response()->json([
                'error' => 'Erro de validação.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $toMysqlDateTime = function ($value) {
            if ($value === null)
                return null;
            $s = trim((string) $value);
            if ($s === '')
                return null;
            $s = str_replace('T', ' ', $s);
            if (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}$/', $s)) {
                $s .= ':00';
            }
            return $s;
        };

        try {
            return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $item, $toMysqlDateTime, $id) {

                \Illuminate\Support\Facades\Log::info('[ITEM UPDATE] TRANSACTION INICIADA', [
                    'item_id' => $id,
                ]);

                static $allowedColumns = null;
                if ($allowedColumns === null) {
                    $cols = \Illuminate\Support\Facades\Schema::getColumnListing('items');
                    $allowedColumns = array_fill_keys($cols, true);

                    \Illuminate\Support\Facades\Log::info('[ITEM UPDATE] COLUNAS PERMITIDAS CARREGADAS', [
                        'columns' => $cols,
                    ]);
                }

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

                $data = array_intersect_key($data, $allowedColumns);

                \Illuminate\Support\Facades\Log::info('[ITEM UPDATE] DADOS FILTRADOS', [
                    'data' => $data,
                ]);

                if (isset($data['availability_start'])) {
                    $data['availability_start'] = $toMysqlDateTime($data['availability_start']);
                }
                if (isset($data['availability_end'])) {
                    $data['availability_end'] = $toMysqlDateTime($data['availability_end']);
                }

                if (isset($allowedColumns['status']) && $request->has('status')) {
                    $data['status'] = $request->boolean('status', (bool) $item->status);
                }
                if (isset($allowedColumns['is_featured']) && $request->has('is_featured')) {
                    $data['is_featured'] = $request->boolean('is_featured', (bool) $item->is_featured);
                }
                if (isset($allowedColumns['limited_by_user']) && $request->has('limited_by_user')) {
                    $data['limited_by_user'] = $request->boolean('limited_by_user', (bool) $item->limited_by_user);
                }

                \Illuminate\Support\Facades\Log::info('[ITEM UPDATE] DADOS FINAIS PARA FILL', [
                    'data' => $data,
                ]);

                $item->fill($data);

                if (isset($allowedColumns['slug']) && $request->filled('name')) {
                    $item->slug = $this->generateSlug($request->input('name'), $item->id);

                    \Illuminate\Support\Facades\Log::info('[ITEM UPDATE] SLUG GERADO', [
                        'slug' => $item->slug,
                    ]);
                }

                if (isset($allowedColumns['updated_by'])) {
                    $item->updated_by = \Illuminate\Support\Facades\Auth::id();
                }

                $item->save();

                \Illuminate\Support\Facades\Log::info('[ITEM UPDATE] ITEM SALVO', [
                    'item_id' => $item->id,
                ]);

                $removeImage = $request->boolean('remove_image', false);

                if ($removeImage) {
                    $deleted = $item->files()
                        ->where('entity_name', 'item')
                        ->where('type', 'image')
                        ->delete();

                    \Illuminate\Support\Facades\Log::info('[ITEM UPDATE] IMAGEM REMOVIDA', [
                        'deleted_count' => $deleted,
                    ]);
                }

                if ($request->hasFile('image')) {
                    $item->files()
                        ->where('entity_name', 'item')
                        ->where('type', 'image')
                        ->delete();

                    \Illuminate\Support\Facades\Log::info('[ITEM UPDATE] NOVA IMAGEM RECEBIDA', [
                        'original_name' => $request->file('image')->getClientOriginalName(),
                        'size' => $request->file('image')->getSize(),
                    ]);

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

                \Illuminate\Support\Facades\Log::info('[ITEM UPDATE] CACHE LIMPO');

                return response()->json([
                    'message' => 'Item atualizado com sucesso.',
                    'item' => $item->fresh()->load('files'),
                ]);
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[ITEM UPDATE] ERRO FINAL', [
                'item_id' => $id,
                'user_id' => \Illuminate\Support\Facades\Auth::id(),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
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
