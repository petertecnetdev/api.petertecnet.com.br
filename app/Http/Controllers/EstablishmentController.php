<?php

namespace App\Http\Controllers;

use App\Models\{Establishment, Interaction, User, Employer};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class EstablishmentController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api')->except(['view', 'listByCategory', 'show', 'list', 'generatePdf']);
    }

   protected function getValidationMessages()
{
    return [
        'app_id.required' => 'O campo app_id é obrigatório.',
        'app_id.integer' => 'O campo app_id deve ser um número inteiro válido.',
        'app_id.exists' => 'O aplicativo selecionado não é válido.',
        'name.required' => 'O nome do estabelecimento é obrigatório.',
        'name.string' => 'O nome deve ser uma string válida.',
        'name.max' => 'O nome deve ter no máximo 255 caracteres.',
        'email.email' => 'O email fornecido não é válido.',
        'email.max' => 'O email deve ter no máximo 255 caracteres.',
        'phone.string' => 'O telefone deve ser uma string válida.',
        'phone.max' => 'O telefone deve ter no máximo 20 caracteres.',
        'description.string' => 'A descrição deve ser uma string válida.',
        'description.max' => 'A descrição deve ter no máximo 2500 caracteres.',
        'address.string' => 'O endereço deve ser uma string válida.',
        'address.max' => 'O endereço deve ter no máximo 255 caracteres.',
        'city.string' => 'A cidade deve ser uma string válida.',
        'city.max' => 'A cidade deve ter no máximo 100 caracteres.',
        'cep.string' => 'O CEP deve ser uma string válida.',
        'cep.max' => 'O CEP deve ter no máximo 10 caracteres.',
        'website_url.url' => 'O website deve ser um URL válido.',
        'location.string' => 'A localização deve ser uma string válida.',
        'instagram_url.url' => 'O link do Instagram deve ser um URL válido.',
        'facebook_url.url' => 'O link do Facebook deve ser um URL válido.',
        'twitter_url.url' => 'O link do Twitter deve ser um URL válido.',
        'youtube_url.url' => 'O link do YouTube deve ser um URL válido.',
        'segments.array' => 'Os segmentos devem ser enviados como array.',
        'segments.*.string' => 'Cada segmento deve ser uma string.',
        'logo.required' => 'A logo é obrigatória.',
        'logo.image' => 'A logo deve ser uma imagem válida.',
        'logo.max' => 'A logo deve ter no máximo 2048 KB.',
        'background.image' => 'A imagem de fundo deve ser uma imagem válida.',
    ];
}
public function store(Request $request)
{
    try {
        $user = Auth::user();
        Log::info('[EstablishmentController::store] Iniciando criação de estabelecimento.', [
            'user_id' => $user->id ?? null,
            'payload' => $request->all()
        ]);

        $data = $request->validate([
            'app_id'         => 'required|integer|exists:applications,id',
            'name'           => 'required|string|max:255',
            'fantasy'        => 'nullable|string|max:255',
            'cnpj'           => 'nullable|string|max:20',
            'type'           => 'nullable|string|max:100',
            'category'       => 'nullable|string|max:100',
            'phone'          => 'nullable|string|max:20',
            'email'          => 'nullable|email|max:255',
            'description'    => 'nullable|string|max:2500',
            'additional_info'=> 'nullable|string|max:2500',
            'city'           => 'nullable|string|max:100',
            'location'       => 'nullable|string',
            'cep'            => 'nullable|string|max:10',
            'address'        => 'nullable|string|max:255',
            'logo'           => 'nullable|image|max:2048',
            'background'     => 'nullable|image|max:4096',
            'website_url'    => 'nullable|url|max:255',
            'facebook_url'   => 'nullable|url|max:255',
            'instagram_url'  => 'nullable|url|max:255',
            'twitter_url'    => 'nullable|url|max:255',
            'youtube_url'    => 'nullable|url|max:255',
            'segments'       => 'nullable|array',
            'segments.*'     => 'string',
            'is_featured'    => 'boolean',
            'is_published'   => 'boolean',
            'is_approved'    => 'boolean',
            'is_cancelled'   => 'boolean',
        ], $this->getValidationMessages());

        DB::beginTransaction();

        $slug = Str::slug($data['fantasy'] ?? $data['name']);
        $slugExists = Establishment::where('slug', $slug)->exists();

        if ($slugExists) {
            $slug .= '-' . uniqid();
        }

        if (!empty($data['logo'])) {
            $logoPath = $data['logo']->store('logos', 'public');
            $data['logo'] = $logoPath;
        }

        if (!empty($data['background'])) {
            $backgroundPath = $data['background']->store('backgrounds', 'public');
            $data['background'] = $backgroundPath;
        }

        $establishment = Establishment::create([
            'app_id'         => $data['app_id'],
            'name'           => $data['name'],
            'fantasy'        => $data['fantasy'] ?? null,
            'slug'           => $slug,
            'cnpj'           => $data['cnpj'] ?? null,
            'type'           => $data['type'] ?? null,
            'category'       => $data['category'] ?? null,
            'phone'          => $data['phone'] ?? null,
            'email'          => $data['email'] ?? null,
            'description'    => $data['description'] ?? null,
            'additional_info'=> $data['additional_info'] ?? null,
            'city'           => $data['city'] ?? null,
            'location'       => $data['location'] ?? null,
            'cep'            => $data['cep'] ?? null,
            'address'        => $data['address'] ?? null,
            'logo'           => $data['logo'] ?? null,
            'background'     => $data['background'] ?? null,
            'website_url'    => $data['website_url'] ?? null,
            'facebook_url'   => $data['facebook_url'] ?? null,
            'instagram_url'  => $data['instagram_url'] ?? null,
            'twitter_url'    => $data['twitter_url'] ?? null,
            'youtube_url'    => $data['youtube_url'] ?? null,
            'segments'       => $data['segments'] ?? [],
            'is_featured'    => $data['is_featured'] ?? false,
            'is_published'   => $data['is_published'] ?? false,
            'is_approved'    => $data['is_approved'] ?? false,
            'is_cancelled'   => $data['is_cancelled'] ?? false,
            'user_id'        => $user->id ?? null,
            'updated_by'     => $user->id ?? null,
        ]);

        DB::commit();

        Log::info('[EstablishmentController::store] Estabelecimento criado com sucesso.', [
            'establishment_id' => $establishment->id,
            'slug' => $establishment->slug
        ]);

        return response()->json([
            'message' => 'Estabelecimento criado com sucesso!',
            'establishment' => $establishment,
        ], 201);

    } catch (\Illuminate\Validation\ValidationException $e) {
        DB::rollBack();
        Log::warning('[EstablishmentController::store] Erro de validação.', ['errors' => $e->errors()]);
        return response()->json(['errors' => $e->errors()], 422);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('[EstablishmentController::store] Erro ao criar estabelecimento.', [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json(['error' => 'Ocorreu um erro ao criar o estabelecimento.'], 500);
    }
}




    public function update(Request $request, $id)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['error' => 'Usu�rio n�o autenticado.'], 401);
            }

            $user = Auth::user();

            try {
                $establishment = Establishment::findOrFail($id);
            } catch (ModelNotFoundException $e) {
                return response()->json(['error' => 'Estabelecimento n�o encontrado com o ID fornecido.'], 404);
            }

            if ($establishment->user_id !== $user->id) {
                return response()->json(['error' => 'Acesso negado.'], 403);
            }

            $validatedData = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:20',
                'description' => 'nullable|string|max:2500',
                'address' => 'nullable|string|max:255',
                'city' => 'nullable|string|max:100',
                'cep' => 'nullable|string|max:10',
                'website_url' => 'nullable|string',
                'location' => 'nullable|string',
                'instagram_url' => 'nullable|string',
                'logo' => 'nullable|image',
                'background' => 'nullable|image',
            ], $this->getValidationMessages());

            $establishment->fill($validatedData);
            $establishment->updated_by = $user->id;

            if ($request->hasFile('logo')) {
                $dest = public_path('images');
                $name = uniqid('logo_') . '.' . $request->file('logo')->getClientOriginalExtension();
                $request->file('logo')->move($dest, $name);
                Image::make("$dest/$name")->fit(150, 150)->save();
                $establishment->logo = "images/$name";
            }

            if ($request->hasFile('background')) {
                $dest = public_path('images');
                $name = uniqid('background_') . '.' . $request->file('background')->getClientOriginalExtension();
                $request->file('background')->move($dest, $name);
                Image::make("$dest/$name")->fit(1920, 600)->save();
                $establishment->background = "images/$name";
            }

            if ($request->filled('name')) {
                $slugBase = Str::slug($request->input('name'));
                $count = Establishment::where('slug', $slugBase)
                    ->where('id', '!=', $establishment->id)
                    ->count();
                $establishment->slug = $count
                    ? "{$slugBase}-" . ($count + 1)
                    : $slugBase;
            }

            $establishment->save();

            $interaction = new Interaction();
            $interaction->user_id = $user->id;
            $interaction->interaction_type = 'Update';
            $interaction->entity_type = 'establishment';
            $interaction->entity_id = $establishment->id;
            $interaction->content = "O usuário {$user->first_name} atualizou o estabelecimento {$establishment->name}.";
            $interaction->save();

            return response()->json([
                'message' => 'Estabelecimento atualizado com sucesso.',
                'establishment' => $establishment,
            ], 200);
        } catch (ValidationException $e) {
            Log::error('Erro de valida��o ao atualizar o estabelecimento.', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar estabelecimento: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao atualizar o estabelecimento.'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['error' => 'Usu�rio n�o autenticado.'], 401);
            }

            $user = Auth::user();

            if (!$user->hasPermission('establishment_destroy')) {
                return response()->json(['error' => 'Voc� n�o tem permiss�o para deletar estabelecimentos.'], 403);
            }

            $establishment = Establishment::findOrFail($id);

            $interaction = new Interaction();
            $interaction->user_id = $user->id;
            $interaction->interaction_type = 'Destroy';
            $interaction->entity_id = $establishment->id;
            $interaction->content = "O usu�rio " . $user->first_name . " deletou o estabelecimento " . $establishment->name . ".";
            $interaction->entity_type = 'establishment';
            $interaction->save();

            $establishment->delete();

            return response()->json(['message' => 'Estabelecimento exclu�do com sucesso.'], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Estabelecimento n�o encontrado.'], 404);

        } catch (\Exception $e) {
            Log::error('Erro ao excluir estabelecimento: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao excluir o estabelecimento.'], 500);
        }
    }

    public function listByUser(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['error' => 'Usu�rio n�o autenticado.'], 401);
            }

            $user = Auth::user();
            $query = Establishment::where('user_id', $user->id);

            // Filtra por categoria se informada
            if ($request->has('category')) {
                $query->where('category', $request->category);
            }

            $establishments = $query->paginate(10);

            return response()->json([
                'message' => 'Estabelecimentos listados com sucesso.',
                'establishments' => $establishments,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao listar estabelecimentos do usu�rio: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao listar seus estabelecimentos.'], 500);
        }
    }
public function view($slug)
    {
        try {
            Log::info('[EstablishmentController::view] Exibindo estabelecimento completo', ['slug' => $slug]);
            $authUser = Auth::user();

            $establishment = Establishment::with([
                'user:id,first_name,last_name,user_name,email,avatar',
                'app:id,name,slug',
                'items:id,name,slug,price,image,category,type,status,entity_id,entity_name,user_id,created_at,duration,description',
                'items.user:id,first_name,last_name,user_name,email,avatar',
                'items.interactions.user:id,first_name,last_name,user_name,email,avatar',
                'employers.user:id,first_name,last_name,user_name,email,avatar',
                'employers.interactions.user:id,first_name,last_name,user_name,email,avatar',
                'creator:id,first_name,last_name,user_name,email',
                'updater:id,first_name,last_name,user_name,email',
            ])
                ->where('slug', $slug)
                ->firstOrFail();

            Interaction::registerView($establishment, $authUser);

            $estViews = $establishment->views()
                ->with('user:id,first_name,last_name,user_name,avatar,email')
                ->get();

            $interactionSummary = [
                'total_views' => $estViews->count(),
                'unique_users' => $estViews->pluck('user_id')->unique()->count(),
                'most_active_user' => $estViews->groupBy('user_id')->map(function ($g) {
                    $u = $g->first()->user;
                    return [
                        'user_id' => $u?->id,
                        'user_name' => $u?->user_name,
                        'name' => trim(($u?->first_name ?? '') . ' ' . ($u?->last_name ?? '')),
                        'avatar' => $u?->avatar,
                        'email' => $u?->email,
                        'total' => $g->count(),
                        'last_view' => $g->max('created_at'),
                    ];
                })->sortByDesc('total')->first(),
                'last_view_user' => $estViews->sortByDesc('created_at')->first()?->user,
            ];

            $items = $establishment->items->map(function ($item) {
                $views = $item->views()
                    ->with('user:id,first_name,last_name,user_name,email,avatar')
                    ->get();

                $groupedUsers = $views->groupBy('user_id')->map(function ($g) {
                    $u = $g->first()->user;
                    return [
                        'user_id' => $u?->id,
                        'user_name' => $u?->user_name,
                        'name' => trim(($u?->first_name ?? '') . ' ' . ($u?->last_name ?? '')),
                        'email' => $u?->email,
                        'avatar' => $u?->avatar,
                        'total_views' => $g->count(),
                        'first_view' => $g->min('created_at'),
                        'last_view' => $g->max('created_at'),
                        'profile_link' => $u?->user_name ? url("/user/view/{$u->user_name}") : null,
                    ];
                })->values();

                $mostActive = $groupedUsers->sortByDesc('total_views')->first();

                return [
                    'id' => $item->id,
                    'slug' => $item->slug,
                    'name' => $item->name,
                    'type' => $item->type,
                    'category' => $item->category,
                    'description' => $item->description,
                    'price' => $item->price,
                    'image' => $item->image,
                    'status' => $item->status,
                    'duration' => $item->duration,
                    'created_at' => $item->created_at,
                    'created_by' => $item->user ? [
                        'id' => $item->user->id,
                        'user_name' => $item->user->user_name,
                        'name' => trim(($item->user->first_name ?? '') . ' ' . ($item->user->last_name ?? '')),
                        'avatar' => $item->user->avatar,
                        'profile_link' => $item->user->user_name ? url("/user/view/{$item->user->user_name}") : null,
                    ] : null,
                    'metrics' => [
                        'total_views' => $views->count(),
                        'unique_users' => $views->pluck('user_id')->unique()->count(),
                        'most_active_user' => $mostActive,
                    ],
                    'viewers' => $groupedUsers,
                ];
            });

            $employers = $establishment->employers->map(function ($emp) {
                $views = $emp->views()
                    ->with('user:id,first_name,last_name,user_name,email,avatar')
                    ->get();

                $groupedUsers = $views->groupBy('user_id')->map(function ($g) {
                    $u = $g->first()->user;
                    return [
                        'user_id' => $u?->id,
                        'user_name' => $u?->user_name,
                        'name' => trim(($u?->first_name ?? '') . ' ' . ($u?->last_name ?? '')),
                        'email' => $u?->email,
                        'avatar' => $u?->avatar,
                        'total_views' => $g->count(),
                        'first_view' => $g->min('created_at'),
                        'last_view' => $g->max('created_at'),
                        'profile_link' => $u?->user_name ? url("/user/view/{$u->user_name}") : null,
                    ];
                })->values();

                $mostActive = $groupedUsers->sortByDesc('total_views')->first();

                return [
                    'id' => $emp->id,
                    'role' => $emp->role,
                    'user' => $emp->user ? [
                        'id' => $emp->user->id,
                        'user_name' => $emp->user->user_name,
                        'name' => trim(($emp->user->first_name ?? '') . ' ' . ($emp->user->last_name ?? '')),
                        'avatar' => $emp->user->avatar,
                        'email' => $emp->user->email,
                        'profile_link' => $emp->user->user_name ? url("/user/view/{$emp->user->user_name}") : null,
                    ] : null,
                    'metrics' => [
                        'total_views' => $views->count(),
                        'unique_users' => $views->pluck('user_id')->unique()->count(),
                        'most_active_user' => $mostActive,
                    ],
                    'viewers' => $groupedUsers,
                ];
            });

            $itemsInteractions = $items->map(function ($it) {
                $metrics = $it['metrics'] ?? [];
                $top = $metrics['most_active_user'] ?? null;
                $topUser = $top['user'] ?? null;

                return [
                    'item_id' => $it['id'],
                    'total_views' => $metrics['total_views'] ?? 0,
                    'unique_users' => $metrics['unique_users'] ?? 0,
                    'most_active_user' => $topUser ? [
                        'user_id' => $topUser->id,
                        'user_name' => $topUser->user_name,
                        'name' => trim(($topUser->first_name ?? '') . ' ' . ($topUser->last_name ?? '')),
                        'avatar' => $topUser->avatar,
                        'profile_link' => $topUser->user_name ? url("/user/view/{$topUser->user_name}") : null,
                    ] : null,
                ];
            });

            $userIds = collect()
                ->merge($estViews->pluck('user_id'))
                ->merge($items->flatMap(fn($i) => $i['viewers']->pluck('user_id')))
                ->merge($employers->flatMap(fn($e) => $e['viewers']->pluck('user_id')))
                ->filter()
                ->unique()
                ->values();

            $userInteractions = User::whereIn('id', $userIds)
                ->get(['id', 'first_name', 'last_name', 'user_name', 'avatar', 'email'])
                ->map(function ($u) {
                    return [
                        'user_id' => $u->id,
                        'user_name' => $u->user_name,
                        'name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
                        'avatar' => $u->avatar,
                        'email' => $u->email,
                        'profile_link' => $u->user_name ? url("/user/view/{$u->user_name}") : null,
                    ];
                });

            $otherEstablishments = Establishment::where('app_id', $establishment->app_id)
                ->where('id', '!=', $establishment->id)
                ->withCount(['views as total_views'])
                ->limit(6)
                ->get(['id', 'name', 'slug', 'logo', 'city', 'category']);

            $metrics = [
                'total_items' => $items->count(),
                'total_employers' => $employers->count(),
                'total_views' => ($interactionSummary['total_views'] ?? 0) + $itemsInteractions->sum('total_views'),
                'unique_users' => $userInteractions->count(),
            ];

            return response()->json([
                'establishment' => $establishment,
                'items' => $items,
                'employers' => $employers,
                'items_interactions' => $itemsInteractions,
                'interaction_summary' => $interactionSummary,
                'user_interactions' => $userInteractions,
                'other_establishments' => $otherEstablishments,
                'metrics' => $metrics,
                'message' => 'Dados completos do estabelecimento carregados com sucesso.',
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[EstablishmentController::view] Erro ao carregar', [
                'slug' => $slug,
                'message' => $e->getMessage(),
                'stack' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Erro ao carregar estabelecimento.'], 500);
        }
    }

    public function show($id)
    {
        try {
            $user = Auth::user();

            $establishment = Establishment::find($id);

            if (!$establishment) {
                return response()->json(['error' => 'Estabelecimento n�o encontrado.'], 404);
            }

            Interaction::create([
                'user_id' => $user?->id,
                'entity_id' => $establishment->id,
                'entity_type' => 'establishment',
                'interaction_type' => 'View',
                'content' => 'O usu�rio ' . ($user?->first_name ?? 'an�nimo') . ' acessou o estabelecimento.',
            ]);

            return response()->json([
                'message' => 'Estabelecimento encontrado com sucesso.',
                'establishment' => $establishment,
                'owner' => $establishment->user,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao buscar o estabelecimento: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao buscar o estabelecimento.'], 500);
        }
    }
    public function list(Request $request)
    {
        try {
            $establishments = Establishment::paginate(10);

            return response()->json([
                'message' => 'Estabelecimentos listados com sucesso.',
                'establishments' => $establishments,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao listar estabelecimentos: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao listar os estabelecimentos.'], 500);
        }
    }
    public function listByCategory($category)
    {
        $query = Establishment::query();
        if ($category) {
            $query->where('category', $category);
        }
        $establishments = $query->paginate(10);
        return response()->json([
            'message' => 'Estabelecimentos listados por categoria com sucesso.',
            'establishments' => $establishments
        ], 200);
    }
    public function listMyByCategory(Request $request, $category)
    {
        try {
            if (!Auth::check()) {
                Log::warning('listMyByCategory: usuário não autenticado', [
                    'route_category' => $category,
                    'query' => $request->all(),
                ]);
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();

            // Normaliza e aceita múltiplas categorias via "barbershop,beauty"
            $cats = collect(explode(',', (string) $category))
                ->map(fn($c) => trim($c))
                ->filter()
                ->map(fn($c) => mb_strtolower($c, 'UTF-8'))
                ->unique()
                ->values()
                ->all();

            $perPage = (int) $request->query('per_page', 10);
            $search = (string) $request->query('q', '');
            $sort = (string) $request->query('sort', 'name'); // name|city|created_at
            $direction = strtolower((string) $request->query('dir', 'asc')) === 'desc' ? 'desc' : 'asc';
            $allowedSorts = ['name', 'city', 'created_at'];
            if (!in_array($sort, $allowedSorts, true))
                $sort = 'name';

            Log::info('listMyByCategory: iniciando consulta', [
                'user_id' => $user->id,
                'cats' => $cats,
                'per_page' => $perPage,
                'search' => $search,
                'sort' => $sort,
                'direction' => $direction,
                'route_category' => $category,
                'query' => $request->all(),
            ]);

            if (empty($cats)) {
                Log::warning('listMyByCategory: categoria inválida', [
                    'user_id' => $user->id,
                    'route_category' => $category,
                ]);
                return response()->json(['error' => 'Categoria inválida.'], 422);
            }

            $q = Establishment::query()
                ->select(['id', 'name', 'fantasy', 'slug', 'category', 'city', 'logo', 'created_at'])
                ->where('user_id', $user->id)
                ->where(function ($qq) use ($cats) {
                    foreach ($cats as $c) {
                        $qq->orWhereRaw('LOWER(category) = ?', [$c]);
                    }
                });

            if ($search !== '') {
                $like = "%{$search}%";
                $q->where(function ($qq) use ($like) {
                    $qq->where('name', 'like', $like)
                        ->orWhere('fantasy', 'like', $like)
                        ->orWhere('city', 'like', $like);
                });
            }

            $establishments = $q->orderBy($sort, $direction)->paginate($perPage);

            // Sanitiza strings com bytes inválidos para evitar "Malformed UTF-8"
            $invalidFields = [];
            $establishments->getCollection()->transform(function ($model) use (&$invalidFields) {
                foreach ($model->getAttributes() as $k => $v) {
                    if (is_string($v) && !mb_check_encoding($v, 'UTF-8')) {
                        $invalidFields[] = ['id' => $model->id, 'field' => $k];
                        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $v);
                        $model->setAttribute($k, $clean !== false ? $clean : $v);
                    }
                }
                return $model;
            });
            if (!empty($invalidFields)) {
                Log::warning('listMyByCategory: atributos com UTF-8 inválido sanitizados', [
                    'user_id' => $user->id,
                    'fields' => $invalidFields,
                ]);
            }

            Log::info('listMyByCategory: consulta concluída', [
                'user_id' => $user->id,
                'cats' => $cats,
                'total' => $establishments->total(),
                'current_page' => $establishments->currentPage(),
                'last_page' => $establishments->lastPage(),
            ]);

            return response()->json([
                'message' => 'Estabelecimentos do usuário listados por categoria com sucesso.',
                'establishments' => $establishments,
            ], 200, [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\Exception $e) {
            Log::error('listMyByCategory: erro ao listar estabelecimentos por categoria', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id(),
                'category_param' => $category,
                'query' => $request->all(),
            ]);
            return response()->json(['error' => 'Ocorreu um erro ao listar seus estabelecimentos por categoria.'], 500);
        }
    }



    public function generatePdf($slug)
    {
        try {
            $establishment = Establishment::with('items')->where('slug', $slug)->first();

            if (!$establishment) {
                return response()->json(['error' => 'Estabelecimento não encontrado.'], 404);
            }

            $category = strtolower($establishment->category ?? '');
            $tipo = in_array($category, ['barbershop', 'beauty', 'salon'])
                ? 'Tabela de Preços'
                : 'Cardápio';

            $items = $establishment->items()
                ->where('is_published', true)
                ->where('is_approved', true)
                ->where('is_cancelled', false)

                ->get()
                ->groupBy(fn($i) => $i->category ?: 'Outros');

            $grouped = $items->sortByDesc(fn($group) => $group->max('price'))
                ->map(fn($group) => $group->sortByDesc('price'));

            $logoPath = $establishment->logo
                ? public_path($establishment->logo)
                : public_path('images/default-logo.png');

            // Converte imagens dos itens em Base64
            $itemsBase64 = [];
            foreach ($grouped as $catName => $catItems) {
                $itemsBase64[$catName] = [];
                foreach ($catItems as $item) {
                    $imgBase64 = null;
                    if ($item->image && file_exists(public_path($item->image))) {
                        $path = public_path($item->image);
                        $mime = mime_content_type($path);
                        $imgBase64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
                    }
                    $itemsBase64[$catName][$item->id] = $imgBase64;
                }
            }

            $pdf = \PDF::loadView('pdf.establishment-menu', [
                'establishment' => $establishment,
                'grouped' => $grouped,
                'tipo' => $tipo,
                'logoPath' => $logoPath,
                'itemsBase64' => $itemsBase64,
            ])->setPaper('a4');

            $fileName = Str::slug($establishment->name . '-' . $tipo) . '.pdf';
            return $pdf->download($fileName);
        } catch (\Exception $e) {
            \Log::error('Erro ao gerar PDF: ' . $e->getMessage());
            return response()->json(['error' => 'Erro ao gerar o PDF.'], 500);
        }
    }
    public function myEstablishments()
    {
        try {
            if (!Auth::check()) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();

            $establishments = Establishment::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'message' => 'Estabelecimentos do usuário listados com sucesso.',
                'establishments' => $establishments,
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Erro ao listar estabelecimentos do usuário: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao listar seus estabelecimentos.'], 500);
        }
    }



}
