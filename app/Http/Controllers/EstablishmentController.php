<?php

namespace App\Http\Controllers;

use App\Models\{Establishment, Interaction, Employer};
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
            $user = Auth::user(); // pode ser null se não estiver logado
            Log::info('Iniciando criação de pedido.', ['user_id' => $user->id ?? null]);

            $data = $request->validate([
                'app_id' => 'required|exists:applications,id',
                'entity_name' => 'required|string|max:255',
                'entity_id' => 'required|integer',
                'items' => 'required|array|min:1',
                'items.*.item_id' => 'required|integer|exists:items,id',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.additions' => 'nullable|array',
                'items.*.additions.*' => 'integer|exists:items,id',
                'items.*.removals' => 'nullable|array',
                'items.*.removals.*' => 'integer|exists:items,id',
                'customer_name' => 'required|string|max:255',
                'origin' => 'required|string|in:WhatsApp,Balcão,Telefone,App',
                'fulfillment' => 'required|string|in:dine-in,take-away,delivery',
                'payment_status' => 'required|string|in:pending,paid,failed',
                'payment_method' => 'required|string|in:Pix,Débito,Crédito,Dinheiro,Fiado,Cortesia,Transferência bancária,Vale-refeição,Cheque,PayPal',
                'notes' => 'nullable|string|max:500',
                'customer_phone' => 'nullable|string|max:20',
                'customer_cpf' => 'nullable|string|max:20',
                'order_datetime' => 'nullable|date',
                'attendant_id' => 'nullable|integer|exists:users,id',
            ], $this->getValidationMessages());

            $now = Carbon::now('America/Sao_Paulo');
            $orderDate = isset($data['order_datetime']) ? Carbon::parse($data['order_datetime']) : $now;

            if ($orderDate->lt($now)) {
                return response()->json(['error' => 'A data do pedido deve ser igual ou posterior à data atual.'], 422);
            }

            $lastNumber = Order::where('app_id', $data['app_id'])->max('order_number') ?: 0;
            $orderNumber = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
            $accessCode = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);

            $attendantId = $data['attendant_id'] ?? $user->id ?? null;

            // Verifica conflitos de horário por atendente
            if ($orderDate->gt($now)) {
                foreach ($data['items'] as $entry) {
                    $item = Item::findOrFail($entry['item_id']);
                    $duration = $item->duration ?? 0;

                    $conflict = Order::where('entity_id', $data['entity_id'])
                        ->where('attendant_id', $attendantId)
                        ->where('status', 'scheduled')
                        ->where(function ($q) use ($orderDate, $duration) {
                            $q->whereBetween('order_datetime', [$orderDate, $orderDate->copy()->addMinutes($duration)])
                                ->orWhereBetween(DB::raw('DATE_ADD(order_datetime, INTERVAL duration MINUTE)'), [$orderDate, $orderDate->copy()->addMinutes($duration)]);
                        })->exists();

                    if ($conflict) {
                        return response()->json(['error' => "Conflito de horário para o serviço {$item->name} com o atendente selecionado. Escolha outro horário ou outro atendente."], 422);
                    }
                }
            }
if (!empty($data['attendant_id'])) {
    $employer = \App\Models\Employer::where('user_id', $data['attendant_id'])
        ->orWhere('id', $data['attendant_id'])
        ->first();
    if ($employer) {
        $data['attendant_id'] = $employer->user_id;
    }
}

            $order = Order::create([
    'app_id' => $data['app_id'],
    'entity_name' => $data['entity_name'],
    'entity_id' => $data['entity_id'],
    'order_number' => $orderNumber,
    'order_datetime' => $orderDate,
    'attendant_id' => $attendantId,
    'client_id' => null,
    'customer_name' => $data['customer_name'],
    'access_code' => $accessCode,
    'origin' => $data['origin'],
    'fulfillment' => $data['fulfillment'],
    'payment_status' => $data['payment_status'],
    'payment_method' => $data['payment_method'],
    'total_price' => 0,
    'status' => $orderDate->gt($now) ? 'scheduled' : 'pending',
    'notes' => $data['notes'] ?? null,
    'customer_phone' => $data['customer_phone'] ?? null,
    'customer_cpf' => $data['customer_cpf'] ?? null,
    'type' => 'appointment', // 🟢 ADICIONE ESTA LINHA
    'appointment_status' => $data['appointment_status'] ?? 'pending', // 🟢 GARANTA QUE EXISTE
]);

            $total = 0;
            $totalDuration = 0;
            foreach ($data['items'] as $entry) {
                $item = Item::findOrFail($entry['item_id']);
                $qty = $entry['quantity'];
                $unitPrice = $item->price;
                $subtotal = $unitPrice * $qty;
                  $totalDuration += $duration; 

                $orderItem = $order->items()->create([
                    'item_id' => $item->id,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'subtotal' => $subtotal,
                ]);

                if (!empty($entry['additions'])) {
                    foreach ($entry['additions'] as $addId) {
                        $orderItem->modifiers()->create([
                            'modifier_id' => $addId,
                            'type' => 'addition',
                        ]);
                        $addItem = Item::find($addId);
                        $total += $addItem->price * $qty;
                    }
                }

                if (!empty($entry['removals'])) {
                    foreach ($entry['removals'] as $remId) {
                        $orderItem->modifiers()->create([
                            'modifier_id' => $remId,
                            'type' => 'removal',
                        ]);
                    }
                }

                $total += $subtotal;
            }

            $order->update([
    'total_price' => $total,
    'total_duration' => $totalDuration, // 🟢 adiciona o total_duration
]);
            Log::info('Pedido registrado com sucesso.', ['order_id' => $order->id]);

            return response()->json([
                'message' => 'Pedido registrado com sucesso!',
                'order' => $order->load('items.item', 'items.modifiers'),
            ], 201);

        } catch (ValidationException $e) {
            Log::warning('Erro de validação ao criar pedido.', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            Log::error('Erro ao processar pedido: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao criar o pedido.'], 500);
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
    }public function view($slug)
{
    try {
        Log::info('[' . __METHOD__ . '] Iniciando exibição detalhada de estabelecimento', ['slug' => $slug]);

        $user = Auth::user();

        // =========================
        // BUSCA DO ESTABELECIMENTO
        // =========================
        $establishment = Establishment::with([
            'user:id,first_name,last_name,email,avatar,user_name',
            'items:id,name,slug,price,image,category,type,status,entity_id,entity_name',
            'employers.user:id,first_name,last_name,email,avatar,user_name',
            'creator:id,first_name,last_name,email',
            'updater:id,first_name,last_name,email'
        ])->where('slug', $slug)->first();

        if (!$establishment) {
            Log::warning('[' . __METHOD__ . '] Estabelecimento não encontrado', ['slug' => $slug]);
            return response()->json(['error' => 'Estabelecimento não encontrado.'], 404);
        }

        // =========================
        // REGISTRAR INTERAÇÃO
        // =========================
        try {
            Interaction::create([
                'entity_type' => 'establishment',
                'entity_id' => $establishment->id,
                'user_id' => $user?->id,
                'interaction_type' => 'view',
                'content' => json_encode([
                    'slug' => $slug,
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]),
                'name' => $establishment->name,
            ]);
        } catch (\Exception $ex) {
            Log::warning('[' . __METHOD__ . '] Falha ao registrar interação', ['erro' => $ex->getMessage()]);
        }

        // =========================
        // MÉTRICAS DE INTERAÇÕES
        // =========================
        $totalViews = Interaction::where('entity_type', 'establishment')
            ->where('entity_id', $establishment->id)
            ->count();

        $userInteractions = Interaction::where('entity_type', 'establishment')
            ->where('entity_id', $establishment->id)
            ->select(
                'user_id',
                DB::raw('COUNT(*) as total_views'),
                DB::raw('MIN(created_at) as first_view'),
                DB::raw('MAX(created_at) as last_view'),
                DB::raw('MAX(content) as last_content')
            )
            ->groupBy('user_id')
            ->with(['user:id,first_name,last_name,email,avatar,user_name'])
            ->orderByDesc('total_views')
            ->get()
            ->map(function ($interaction) {
                $content = json_decode($interaction->last_content ?? '{}', true);
                return [
                    'user_id' => $interaction->user_id,
                    'user_name' => trim($interaction->user?->first_name . ' ' . $interaction->user?->last_name),
                    'user_email' => $interaction->user?->email,
                    'user_avatar' => $interaction->user?->avatar,
                    'total_views' => (int) $interaction->total_views,
                    'first_view' => $interaction->first_view,
                    'last_view' => $interaction->last_view,
                    'ip' => $content['ip'] ?? null,
                    'user_agent' => $content['user_agent'] ?? null,
                ];
            });

        $distinctUsers = $userInteractions->count();
        $mostActiveUser = $userInteractions->sortByDesc('total_views')->first();
        $lastUser = $userInteractions->sortByDesc('last_view')->first();

        $interactionSummary = [
            'total_views' => $totalViews,
            'unique_users' => $distinctUsers,
            'most_active_user' => $mostActiveUser ? [
                'name' => $mostActiveUser['user_name'],
                'views' => $mostActiveUser['total_views'],
                'last_view' => $mostActiveUser['last_view'],
            ] : null,
            'last_view_user' => $lastUser ? [
                'name' => $lastUser['user_name'],
                'last_view' => $lastUser['last_view'],
            ] : null,
        ];

        // =========================
        // OUTROS ESTABELECIMENTOS (CORRIGIDO)
        // =========================
      // OUTROS ESTABELECIMENTOS (corrigido)
$otherEstablishments = Establishment::where('slug', '!=', $slug)
    ->where('is_published', true)
    ->where('is_approved', true)
    ->where('is_cancelled', false)
    ->inRandomOrder()
    ->limit(6)
    ->get(['id', 'name', 'slug', 'logo', 'city', 'category']);


        // =========================
        // MÉTRICAS DE ITENS E SERVIÇOS
        // =========================
        $items = $establishment->items ?? collect();
        $services = $items->filter(fn($i) => Str::contains(Str::lower($i->type), 'serv'));
        $products = $items->filter(fn($i) => !Str::contains(Str::lower($i->type), 'serv'));

        $metrics = [
            'total_items' => $items->count(),
            'total_services' => $services->count(),
            'total_products' => $products->count(),
            'total_views' => $totalViews,
            'unique_users' => $distinctUsers,
        ];

        // =========================
        // WHATSAPP URL
        // =========================
        $establishment->whatsapp_url = $establishment->phone
            ? 'https://wa.me/55' . preg_replace('/\D/', '', $establishment->phone)
            . '?text=' . urlencode("Olá! Gostaria de saber mais sobre o estabelecimento \"{$establishment->name}\".")
            : null;

        // =========================
        // CAMPOS FORMATADOS
        // =========================
        $establishment->created_since = $establishment->created_at?->diffForHumans();
        $establishment->last_updated_at = $establishment->updated_at?->format('d/m/Y H:i');

        // =========================
        // RESPOSTA FINAL
        // =========================
        $response = [
            'establishment' => $establishment,
            'items' => $items,
            'services' => $services->values(),
            'products' => $products->values(),
            'collaborators' => $establishment->employers,
            'interaction_summary' => $interactionSummary,
            'user_interactions' => $userInteractions,
            'metrics' => $metrics,
            'other_establishments' => $otherEstablishments,
            'message' => 'Dados detalhados do estabelecimento carregados com sucesso.',
        ];

        Log::info('[' . __METHOD__ . '] Exibição detalhada de estabelecimento concluída com sucesso', [
            'establishment_id' => $establishment->id,
            'views_total' => $totalViews,
            'unique_users' => $distinctUsers,
            'total_items' => $items->count(),
        ]);

        return response()->json($response, 200);

    } catch (\Exception $e) {
        Log::error('[' . __METHOD__ . '] Erro ao buscar estabelecimento detalhado', [
            'slug' => $slug,
            'message' => $e->getMessage(),
            'stack' => $e->getTraceAsString(),
        ]);
        return response()->json(['error' => 'Ocorreu um erro ao buscar os detalhes do estabelecimento.'], 500);
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
