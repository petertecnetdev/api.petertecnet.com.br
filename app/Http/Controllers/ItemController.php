<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
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
            'app_id.required' => 'O campo app_id � obrigat�rio.',
            'app_id.exists' => 'O aplicativo informado n�o existe.',
            'name.required' => 'O nome � obrigat�rio.',
            'type.required' => 'O tipo � obrigat�rio.',
            'price.required' => 'O pre�o � obrigat�rio.',
            'price.numeric' => 'O pre�o deve ser num�rico.',
            'status.boolean' => 'O status deve ser booleano.',
            'entity_id.required' => 'A entidade � obrigat�ria.',
            'entity_name.required' => 'O nome da entidade � obrigat�rio.',
        ];
    }


    private function ensureAuth(string $permission): void
    {
        if (!Auth::check()) {
            abort(401, 'Usu�rio n�o autenticado.');
        }

        if (!Auth::user()->hasPermission($permission)) {
            abort(403, 'Permiss�o negada.');
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
    public function listByEntity(Request $request, string $identifier)
    {
        try {
            \Log::info('listByEntity:start', [
                'identifier' => $identifier,
                'query' => $request->query(),
            ]);

            $type = $request->query('type');

            \Log::info('listByEntity:resolved_type', [
                'type' => $type ?? 'all',
            ]);

            $cacheKey = "items_entity_{$identifier}_" . ($type ?: 'all');

            \Log::info('listByEntity:cache_key', [
                'cache_key' => $cacheKey,
            ]);

            return Cache::remember($cacheKey, 300, function () use ($identifier, $type) {
                \Log::info('listByEntity:cache_miss', [
                    'identifier' => $identifier,
                    'type' => $type ?? 'all',
                ]);

                $establishmentQuery = Establishment::query()
                    ->when(
                        is_numeric($identifier),
                        fn($q) => $q->where('id', (int) $identifier),
                        fn($q) => $q->where('slug', $identifier)
                    );

                \Log::info('listByEntity:establishment_query_built');

                $establishment = $establishmentQuery
                    ->with([
                        'files' => fn($q) =>
                            $q->where('entity_name', 'establishment')
                                ->where('type', 'logo')
                                ->orderBy('position'),

                        'items.files' => fn($q) =>
                            $q->where('entity_name', 'item')
                                ->orderBy('position'),
                    ])
                    ->firstOrFail();

                \Log::info('listByEntity:establishment_loaded', [
                    'establishment_id' => $establishment->id,
                    'slug' => $establishment->slug,
                ]);

                $itemsQuery = $establishment->items();

                if ($type) {
                    \Log::info('listByEntity:filtering_items_by_type', [
                        'type' => $type,
                    ]);
                    $itemsQuery->where('type', $type);
                } else {
                    \Log::info('listByEntity:listing_all_items');
                }

                $items = $itemsQuery->get();

                \Log::info('listByEntity:items_loaded', [
                    'items_count' => $items->count(),
                ]);

                return [
                    'message' => 'Itens listados com sucesso.',
                    'establishment' => json_decode(
                        json_encode($establishment->toArray(), JSON_INVALID_UTF8_SUBSTITUTE),
                        true
                    ),
                    'items' => json_decode(
                        json_encode($items->toArray(), JSON_INVALID_UTF8_SUBSTITUTE),
                        true
                    ),
                ];
            });
        } catch (\Throwable $e) {
            \Log::error('listByEntity:error', [
                'identifier' => $identifier,
                'exception' => $e,
            ]);

            return response()->json([
                'message' => 'Erro ao listar itens.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    public function listOthers(string $identifier)
    {
        try {
            return Cache::remember("items_others_establishment_{$identifier}", 300, function () use ($identifier) {

                $establishment = Establishment::query()
                    ->when(
                        is_numeric($identifier),
                        fn($q) => $q->where('id', (int) $identifier),
                        fn($q) => $q->where('slug', $identifier)
                    )
                    ->firstOrFail();

                $items = Item::query()
                    ->whereHas('establishment', function ($q) use ($establishment) {
                        $q->where('uf', $establishment->uf)
                            ->where('city', $establishment->city)
                            ->where('id', '!=', $establishment->id);
                    })
                    ->with([
                        'files' => fn($q) =>
                            $q->where('entity_name', 'item')
                                ->orderBy('position'),
                        'establishment',
                    ])
                    ->orderByDesc('updated_at')
                    ->get();

                return json_decode(
                    json_encode($items->toArray(), JSON_INVALID_UTF8_SUBSTITUTE),
                    true
                );
            });
        } catch (\Throwable $e) {
            \Log::error('Item.listOthers error', [
                'identifier' => $identifier,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar itens de outros estabelecimentos',
            ], 500);
        }
    }

    public function home(Request $request, $app_id)
{
    \Log::info('Item.home start', [
        'app_id' => $app_id,
        'query' => $request->query(),
        'ip' => $request->ip(),
    ]);

    try {
        $city = $request->query('city');
        $uf = $request->query('uf');
        $type = $request->query('type');
        $showAll = false;

        \Log::info('Item.home initial filters', compact('city', 'uf', 'type'));

        if ($city === 'Todas') {
            $city = null;
            $showAll = true;
        }

        if ($uf === 'ALL') {
            $uf = null;
            $showAll = true;
        }

        if (!$showAll && (!$city || !$uf)) {
            $ip = $request->ip();

            \Log::info('Item.home trying IP location', [
                'ip' => $ip,
            ]);

            if ($ip && $ip !== '127.0.0.1') {
                try {
                    $response = \Illuminate\Support\Facades\Http::timeout(3)
                        ->get("http://ip-api.com/json/{$ip}?fields=status,region,city");

                    \Log::info('Item.home IP API response', [
                        'status' => $response->status(),
                        'body' => $response->json(),
                    ]);

                    if ($response->ok() && $response->json('status') === 'success') {
                        $uf = $uf ?: strtoupper($response->json('region'));
                        $city = $city ?: $response->json('city');

                        \Log::info('Item.home location resolved by IP', compact('city', 'uf'));
                    }
                } catch (\Throwable $e) {
                    \Log::error('Item.home IP lookup error', [
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }

        \Log::info('Item.home final filters', compact('city', 'uf', 'type', 'showAll'));

        $items = Item::whereHas('establishment', function ($q) use ($app_id, $city, $uf, $showAll) {
            $q->where('app_id', $app_id)
                ->when(!$showAll && $city, fn ($qq) =>
                    $qq->whereRaw('LOWER(city) = ?', [mb_strtolower($city)])
                )
                ->when(!$showAll && $uf, fn ($qq) =>
                    $qq->whereRaw('LOWER(uf) = ?', [mb_strtolower($uf)])
                );
        })
            ->when($type, fn ($q) => $q->where('type', $type))
            ->with([
                'files' => fn ($q) =>
                    $q->where('entity_name', 'item')->orderBy('position'),
                'establishment.files' => fn ($q) =>
                    $q->where('entity_name', 'establishment')->orderBy('position'),
            ])
            ->withCount([
                'views as total_views' => fn ($q) =>
                    $q->where('interaction_type', 'view'),
                'views as unique_users' => fn ($q) =>
                    $q->select(\DB::raw('COUNT(DISTINCT user_id)'))
                        ->where('interaction_type', 'view'),
            ])
            ->get();

        \Log::info('Item.home items loaded', [
            'count' => $items->count(),
        ]);

        $grouped = $items->groupBy('establishment_id')->map(fn ($group) =>
            $group->shuffle()->values()
        );

        $final = collect();
        $max = $grouped->max(fn ($g) => $g->count());

        for ($i = 0; $i < $max; $i++) {
            foreach ($grouped as $group) {
                if (isset($group[$i])) {
                    $final->push($group[$i]);
                }
            }
        }

        \Log::info('Item.home items shuffled without repetition', [
            'final_count' => $final->count(),
        ]);

        return response()->json([
            'success' => true,
            'city' => $city,
            'uf' => $uf,
            'type' => $type,
            'items' => $final->values(),
        ]);
    } catch (\Throwable $e) {
        \Log::error('Item.home fatal error', [
            'app_id' => $app_id,
            'city' => $city ?? null,
            'uf' => $uf ?? null,
            'type' => $type ?? null,
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Erro ao carregar itens',
        ], 500);
    }
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
    public function view(string $identifier)
    {
        try {
            $item = Item::query()
                ->when(
                    is_numeric($identifier),
                    fn($q) => $q->where('id', (int) $identifier),
                    fn($q) => $q->where('slug', $identifier)
                )
                ->first();

            if (!$item) {
                \Log::warning('Item.view not found', [
                    'identifier' => $identifier,
                ]);

                return response()->json([
                    'success' => false,
                    'item' => null,
                ], 404);
            }

            Interaction::registerView($item, auth()->user() ?? null);

            $itemArray = json_decode(
                json_encode($item->toArray(), JSON_INVALID_UTF8_SUBSTITUTE),
                true
            );

            return response()->json([
                'success' => true,
                'message' => 'item encontrado com sucesso',
                'item' => $itemArray,
                'establishment' => Establishment::where('id', $item->entity_id)->first(),
            ]);
        } catch (\Throwable $e) {
            \Log::error('Item.view error', [
                'identifier' => $identifier,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar item',
            ], 500);
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
        \Illuminate\Support\Facades\Log::info('[ITEM UPDATE] IN�CIO', [
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
            \Illuminate\Support\Facades\Log::warning('[ITEM UPDATE] FALHA DE VALIDA��O', [
                'errors' => $validator->errors()->toArray(),
            ]);

            return response()->json([
                'error' => 'Erro de valida��o.',
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

        return response()->json(['message' => 'Pre�os aumentados com sucesso.']);
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

        return response()->json(['message' => 'Pre�os reduzidos com sucesso.']);
    }
}
