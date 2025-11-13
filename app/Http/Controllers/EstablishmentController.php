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
use Illuminate\Support\Facades\Cache;

class EstablishmentController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api')->except(['view', 'home', 'listByCategory', 'show', 'list', 'generatePdf']);
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
            'app_id' => 'required|integer|exists:applications,id',
            'name' => 'required|string|max:255',
            'fantasy' => 'nullable|string|max:255',
            'cnpj' => 'nullable|string|max:20',
            'type' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'description' => 'nullable|string|max:2500',
            'additional_info' => 'nullable|string|max:2500',
            'city' => 'nullable|string|max:100',
            'uf' => 'nullable|string|max:2',
            'location' => 'nullable|string',
            'cep' => 'nullable|string|max:10',
            'address' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'logo' => 'nullable|image|max:2048',
            'background' => 'nullable|image|max:4096',
            'website_url' => 'nullable|url|max:255',
            'facebook_url' => 'nullable|url|max:255',
            'instagram_url' => 'nullable|url|max:255',
            'twitter_url' => 'nullable|url|max:255',
            'youtube_url' => 'nullable|url|max:255',
            'segments' => 'nullable|array',
            'segments.*' => 'string',
            'is_featured' => 'boolean',
            'is_published' => 'boolean',
            'is_approved' => 'boolean',
            'is_cancelled' => 'boolean',
        ], $this->getValidationMessages());

        DB::beginTransaction();

        // 🔵 Slug único
        $slug = Str::slug($data['fantasy'] ?? $data['name']);
        if (Establishment::where('slug', $slug)->exists()) {
            $slug .= '-' . uniqid();
        }

        // 🔵 Uploads
        if (!empty($data['logo'])) {
            $data['logo'] = $data['logo']->store('logos', 'public');
        }

        if (!empty($data['background'])) {
            $data['background'] = $data['background']->store('backgrounds', 'public');
        }

        // 🔵 Herdar cidade e UF do usuário se não vierem do front
        if (empty($data['city']) && !empty($user->city)) {
            $data['city'] = $user->city;
        }

        if (empty($data['uf']) && !empty($user->uf)) {
            $data['uf'] = $user->uf;
        }

        // 🔵 Localização inteligente
        $latitude = $data['latitude'] ?? null;
        $longitude = $data['longitude'] ?? null;
        $city = $data['city'] ?? null;
        $uf = $data['uf'] ?? null;

        // 🟣 Se latitude/longitude foram enviados → reverse geocode
        if ($latitude && $longitude && (!$city || !$uf)) {
            try {
                $url = "https://nominatim.openstreetmap.org/reverse?format=json&lat={$latitude}&lon={$longitude}&addressdetails=1";
                $geo = json_decode(file_get_contents($url), true);

                $data['city'] = $geo['address']['city']
                    ?? $geo['address']['town']
                    ?? $geo['address']['village']
                    ?? $data['city'];

                $ufText = $geo['address']['state'] ?? null;
                if ($ufText) {
                    $mapping = [
                        'Acre'=>'AC','Alagoas'=>'AL','Amapá'=>'AP','Amazonas'=>'AM',
                        'Bahia'=>'BA','Ceará'=>'CE','Distrito Federal'=>'DF','Espírito Santo'=>'ES',
                        'Goiás'=>'GO','Maranhão'=>'MA','Mato Grosso'=>'MT','Mato Grosso do Sul'=>'MS',
                        'Minas Gerais'=>'MG','Pará'=>'PA','Paraíba'=>'PB','Paraná'=>'PR',
                        'Pernambuco'=>'PE','Piauí'=>'PI','Rio de Janeiro'=>'RJ','Rio Grande do Norte'=>'RN',
                        'Rio Grande do Sul'=>'RS','Rondônia'=>'RO','Roraima'=>'RR','Santa Catarina'=>'SC',
                        'São Paulo'=>'SP','Sergipe'=>'SE','Tocantins'=>'TO'
                    ];

                    $data['uf'] = $mapping[$ufText] ?? $data['uf'];
                }
            } catch (\Throwable $geoError) {
                Log::warning('[EstablishmentController::store] Erro ao tentar reverse geocode', [
                    'error' => $geoError->getMessage(),
                ]);
            }
        }

        // 🟣 Se não temos lat/lng mas temos CEP/endereço → buscar coordenadas
        if ((!$latitude || !$longitude) && (!empty($data['cep']) || !empty($data['address']))) {
            try {
                $query = urlencode($data['address'] . ' ' . $data['cep'] . ' ' . ($data['city'] ?? '') . ' ' . ($data['uf'] ?? ''));
                $url = "https://nominatim.openstreetmap.org/search?format=json&q={$query}&limit=1";

                $search = json_decode(file_get_contents($url), true);

                if (!empty($search[0])) {
                    $latitude = $search[0]['lat'];
                    $longitude = $search[0]['lon'];
                }

            } catch (\Throwable $geoSearchError) {
                Log::warning('[EstablishmentController::store] Erro ao geocodificar endereço', [
                    'error' => $geoSearchError->getMessage(),
                ]);
            }
        }

        // 🔵 Se temos lat/lng → gerar "location" formatado
        if ($latitude && $longitude) {
            $data['location'] = "{$latitude},{$longitude}";
        }

        // 🔵 Criar estabelecimento
        $establishment = Establishment::create([
            'app_id' => $data['app_id'],
            'name' => $data['name'],
            'fantasy' => $data['fantasy'] ?? null,
            'slug' => $slug,
            'cnpj' => $data['cnpj'] ?? null,
            'type' => $data['type'] ?? null,
            'category' => $data['category'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'description' => $data['description'] ?? null,
            'additional_info' => $data['additional_info'] ?? null,
            'city' => $data['city'] ?? null,
            'uf' => $data['uf'] ?? null,
            'location' => $data['location'] ?? null,
            'cep' => $data['cep'] ?? null,
            'address' => $data['address'] ?? null,
            'logo' => $data['logo'] ?? null,
            'background' => $data['background'] ?? null,
            'website_url' => $data['website_url'] ?? null,
            'facebook_url' => $data['facebook_url'] ?? null,
            'instagram_url' => $data['instagram_url'] ?? null,
            'twitter_url' => $data['twitter_url'] ?? null,
            'youtube_url' => $data['youtube_url'] ?? null,
            'segments' => $data['segments'] ?? [],
            'is_featured' => $data['is_featured'] ?? false,
            'is_published' => $data['is_published'] ?? false,
            'is_approved' => $data['is_approved'] ?? false,
            'is_cancelled' => $data['is_cancelled'] ?? false,
            'user_id' => $user->id ?? null,
            'updated_by' => $user->id ?? null,
        ]);

        DB::commit();

        return response()->json([
            'message' => 'Estabelecimento criado com sucesso!',
            'establishment' => $establishment,
        ], 201);

    } catch (\Illuminate\Validation\ValidationException $e) {
        DB::rollBack();
        return response()->json(['errors' => $e->errors()], 422);

    } catch (\Exception $e) {
        DB::rollBack();
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
        $authUser = Auth::user();

        // Carrega o estabelecimento com todas as relações necessárias
        $establishment = Establishment::with([
            'employers.user:id,first_name,last_name,user_name,avatar,email,city,uf',
            'user:id,first_name,last_name,user_name,avatar,email,city,uf',
            'items:id,entity_id,name,slug,price,type,image',
            'orders.client:id,first_name,last_name,user_name,avatar,email',
            'interactions.user:id,first_name,last_name,user_name,avatar,email',
        ])
        ->where('slug', $slug)
        ->firstOrFail();

        // ⭐ Chamada aqui! Ajusta city/uf automaticamente
        $establishment = $this->resolveEstablishmentLocation($establishment);

        // Registrar visualização e limpar cache
        Interaction::registerView($establishment, $authUser);
        Cache::forget("establishment_{$establishment->id}_metrics");
        Cache::forget("establishment_{$establishment->id}_summary");

        // Dados da model
        return response()->json([
            'establishment' => $establishment,
            'items' => $establishment->items ?? [],
            'metrics' => $establishment->metrics,
            'interaction_summary' => $establishment->interactionSummary(),
            'user_interactions' => $establishment->userInteractions(),
            'orders_summary' => $establishment->ordersSummary(),
            'completed_appointments' => $establishment->completedAppointments(),
            'other_establishments' => $establishment->otherEstablishments(),
            'other_employers' => $establishment->otherEmployers(),
            'other_items' => $establishment->otherItems(),
        ], 200);

    } catch (\Throwable $e) {
        \Log::error('[EstablishmentController::view] Erro ao carregar', [
            'slug' => $slug,
            'message' => $e->getMessage(),
        ]);

        return response()->json(['error' => 'Erro ao carregar estabelecimento.'], 500);
    }
}



private function resolveEstablishmentLocation($establishment)
{
    // Se já tem cidade/UF, não precisa mexer
    if ($establishment->city && $establishment->uf) {
        return $establishment;
    }

    $city = null;
    $uf = null;

    // 1️⃣ Tentar pegar do dono (user)
    if ($establishment->user) {
        $city = $establishment->user->city;
        $uf   = $establishment->user->uf;
    }

    // 2️⃣ Se dono não tem → tentar pegar dos colaboradores
    if ((!$city || !$uf) && $establishment->employers->count() > 0) {
        foreach ($establishment->employers as $emp) {
            $u = $emp->user;

            if ($u && ($u->city || $u->uf)) {
                $city = $city ?: $u->city;
                $uf   = $uf   ?: $u->uf;
                break;
            }
        }
    }

    // 3️⃣ Se não achou nada → retorna sem salvar
    if (!$city && !$uf) {
        return $establishment;
    }

    // 4️⃣ Salvar no estabelecimento
    $establishment->update([
        'city' => $establishment->city ?: $city,
        'uf'   => $establishment->uf   ?: $uf,
    ]);

    return $establishment;
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
    }public function home(Request $request, $app_id)
{
    try {
        if (!$app_id || !is_numeric($app_id)) {
            return response()->json(['error' => 'O campo app_id é obrigatório e deve ser numérico.'], 422);
        }

        $city = $request->city;
        $uf   = $request->uf;

        $query = Establishment::where('app_id', $app_id);

        if ($city && $uf) {
            $query->where('city', $city)
                  ->where('uf', $uf);
        }

        $establishments = $query
            ->with(['user:id,first_name,last_name,user_name,avatar,email'])
            ->withCount([
                'views as total_views' => fn($q) => $q->where('interaction_type', 'view'),
                'views as unique_users' => fn($q) =>
                    $q->select(DB::raw('COUNT(DISTINCT user_id)'))->where('interaction_type', 'view')
            ])
            ->orderByDesc('total_views')
            ->limit(6)
            ->get([
                'id',
                'name',
                'slug',
                'logo',
                'background',
                'city',
                'uf',
                'location',
                'address',      // ✅ AQUI
                'category'
            ]);

        return response()->json([
            'message' => 'Estabelecimentos listados com sucesso.',
            'establishments' => $establishments,
        ], 200);

    } catch (\Throwable $e) {
        \Log::error('[EstablishmentController::home] Erro ao listar estabelecimentos', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'error' => 'Erro inesperado ao listar estabelecimentos.'
        ], 500);
    }
}

}
