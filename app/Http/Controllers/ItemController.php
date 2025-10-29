<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\{Item};
use Illuminate\Support\Facades\File;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ItemController extends Controller
{
    protected function getValidationMessages()
    {
        return [
            'name.required' => 'O campo nome é obrigatório.',
            'name.string' => 'O campo nome deve ser uma string.',
            'name.max' => 'O campo nome não pode ter mais que 255 caracteres.',
            'type.required' => 'O campo tipo é obrigatório.',
            'type.string' => 'O campo tipo deve ser uma string.',
            'price.required' => 'O campo preço é obrigatório.',
            'price.numeric' => 'O campo preço deve ser um número.',
            'price.min' => 'O campo preço deve ser pelo menos 0.',
            'stock.required' => 'O campo estoque é obrigatório.',
            'stock.integer' => 'O campo estoque deve ser um número inteiro.',
            'stock.min' => 'O campo estoque deve ser pelo menos 0.',
            'status.required' => 'O campo status é obrigatório.',
            'status.boolean' => 'O campo status deve ser verdadeiro ou falso.',
            'entity_id.required' => 'O campo entidade ID é obrigatório.',
            'entity_id.integer' => 'O campo entidade ID deve ser um número inteiro.',
            'entity_name.required' => 'O campo nome da entidade é obrigatório.',
            'entity_name.string' => 'O campo nome da entidade deve ser uma string.',
            'image.image' => 'O campo imagem deve ser uma imagem válida (jpeg, png, bmp, gif, svg, ou webp).',
            'image.max' => 'O campo imagem não pode ter mais que 2MB.',
            'availability_start.date' => 'O campo data de início de disponibilidade deve ser uma data válida.',
            'availability_end.date' => 'O campo data de término de disponibilidade deve ser uma data válida.',
            'availability_end.after' => 'A data de término de disponibilidade deve ser após a data de início.',
            'app_id.required' => 'O campo app_id é obrigatório.',
            'app_id.exists' => 'O aplicativo especificado não existe.',
            'duration.integer' => 'A duração deve ser um número inteiro.',
            'duration.min' => 'A duração mínima é de 1 minuto.',
            'duration.max' => 'A duração máxima é de 480 minutos (8 horas).',
        ];
    }

    public function store(Request $request)
    {
        try {
            \Log::info('Iniciando a criação de um novo item.');

            // Verificar se o usuário está autenticado
            if (!Auth::check()) {
                \Log::warning('Usuário não autenticado tentou acessar o recurso.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            // Obter o usuário autenticado
            $user = Auth::user();
            \Log::info('Usuário autenticado:', ['id' => $user->id, 'name' => $user->name]);

            // Verificar se o usuário possui permissão para cadastrar itens
            if (!$user->hasPermission('item_create')) {
                \Log::warning('Usuário sem permissão tentou cadastrar item.', ['user_id' => $user->id]);
                return response()->json(['error' => 'Você não tem permissão para cadastrar itens.'], 403);
            }
            if ($request->has('stock') && $request->input('stock') === '') {
                $request->merge(['stock' => null]);
            }
            // Validação dos dados da requisição
            \Log::info('Validando dados da requisição.');
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'type' => 'required|string|max:100',
                'price' => 'required|numeric|min:0',
                'stock' => 'nullable|integer|min:0',
                'status' => 'required|boolean',
                'entity_id' => 'required|integer',
                'entity_name' => 'required|string|max:100',
                'image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
                'availability_start' => 'nullable|date',
                'availability_end' => 'nullable|date|after:availability_start',
                'discount' => 'nullable|numeric|min:0|max:100',
                'expiration_date' => 'nullable|date',
                'app_id' => 'required|exists:applications,id',
                'duration' => 'nullable|integer|min:1|max:480',
            ], $this->getValidationMessages());

            \Log::info('Dados validados com sucesso:', $validatedData);

            // Criação do item
            \Log::info('Iniciando a criação do item no banco de dados.');
            $item = Item::create([
                'name' => $validatedData['name'],
                'type' => $validatedData['type'],
                'price' => $validatedData['price'],
                'stock' => $validatedData['stock'],
                'status' => (int) $validatedData['status'],
                'user_id' => $user->id,
                'entity_id' => $validatedData['entity_id'],
                'entity_name' => $validatedData['entity_name'],
                'description' => $request->input('description'),
                'category' => $request->input('category'),
                'subcategory' => $request->input('subcategory'),
                'brand' => $request->input('brand'),
                'availability_start' => $request->input('availability_start'),
                'availability_end' => $request->input('availability_end'),
                'is_featured' => (bool) $request->input('is_featured', false),
                'discount' => $request->input('discount'),
                'expiration_date' => $request->input('expiration_date'),
                'limited_by_user' => $request->input('limited_by_user', 0),
                'notes' => $request->input('notes'),
                'app_id' => $validatedData['app_id'],
                'duration' => $request->input('duration'),

            ]);

            \Log::info('Item criado no banco de dados.', ['item_id' => $item->id]);

            if ($request->hasFile('image')) {
                \Log::info('Imagem do item fornecida, processando...');

                $destinationPath = '/home/petert03/api.petertecnet.com.br/public/images';
                $imageName = uniqid('item_') . '.' . $request->file('image')->getClientOriginalExtension();

                try {
                    // Salvar imagem temporariamente
                    $request->file('image')->move($destinationPath, $imageName);

                    // Redimensionar para 250x250
                    $imagePath = $destinationPath . '/' . $imageName;
                    $image = Image::make($imagePath)->fit(250, 250);
                    $image->save($imagePath);

                    // Atualizar o caminho no banco
                    $item->image = 'images/' . $imageName;
                    $item->save();

                    \Log::info('Imagem processada e salva com sucesso.', ['image_path' => $item->image]);
                } catch (\Exception $e) {
                    \Log::error('Erro ao salvar a imagem do item.', ['error' => $e->getMessage()]);
                }
            }

            // Gerar slug para o item
            \Log::info('Gerando slug para o item.');
            $slug = Str::slug($validatedData['name']);
            $count = Item::where('slug', $slug)->count();
            if ($count > 0) {
                $slug = $slug . '-' . ($count + 1);
            }
            $item->slug = $slug;
            $item->save();
            \Log::info('Slug gerado com sucesso.', ['slug' => $item->slug]);

            // Retornar sucesso
            \Log::info('Item cadastrado com sucesso.', ['item_id' => $item->id]);
            return response()->json(['message' => 'Item cadastrado com sucesso.', 'item' => $item], 201);

        } catch (ValidationException $e) {
            \Log::warning('Erros de validação ao cadastrar item.', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            \Log::error('Erro ao cadastrar item: ' . $e->getMessage(), ['stack' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Ocorreu um erro ao cadastrar o item.'], 500);
        }
    }

    public function listByEntity(Request $request)
    {
        try {
            \Log::info('Iniciando a busca de itens por entidade.');

            // Validação dos parâmetros
            $validatedData = $request->validate([
                'entity_name' => 'required|string|max:100',
                'entity_id' => 'required|integer',
            ], $this->getValidationMessages());

            \Log::info('Parâmetros validados com sucesso:', $validatedData);

            // Buscar itens com base na entidade fornecida
            $items = Item::where('entity_name', $validatedData['entity_name'])
                ->where('entity_id', $validatedData['entity_id'])
                ->get();

            \Log::info('Itens encontrados.', ['total' => $items->count()]);

            return response()->json($items, 200);

        } catch (ValidationException $e) {
            \Log::warning('Erro de validação na listagem de itens por entidade.', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            \Log::error('Erro inesperado ao buscar itens por entidade.', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Ocorreu um erro ao buscar os itens.'], 500);
        }
    }
    public function view($slug)
    {
        try {
            \Log::info('[' . __METHOD__ . '] Iniciando exibição pelo slug', ['slug' => $slug]);

            // Autenticação
            if (!Auth::check()) {
                \Log::warning('[' . __METHOD__ . '] Usuário não autenticado');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            // Permissão
            $user = Auth::user();
            if (!$user->hasPermission('item_view')) {
                \Log::warning('[' . __METHOD__ . '] Sem permissão para visualizar item', ['user_id' => $user->id]);
                return response()->json(['error' => 'Você não tem permissão para visualizar itens.'], 403);
            }

            // Busca o item com slug e garantindo entity_name
            $item = Item::where('slug', $slug)
                ->where('entity_name', 'barbershop')
                ->with(['barbershop'])
                ->first();

            if (!$item) {
                \Log::warning('[' . __METHOD__ . '] Item não encontrado ou não é de barbershop', ['slug' => $slug]);
                return response()->json(['error' => 'Item não encontrado.'], 404);
            }

            \Log::info('[' . __METHOD__ . '] Item encontrado', ['item_id' => $item->id]);

            return response()->json([
                'item' => $item,
                'barbershop' => $item->barbershop,
            ], 200);

        } catch (\Exception $e) {
            \Log::error('[' . __METHOD__ . '] Erro ao buscar item', [
                'slug' => $slug,
                'message' => $e->getMessage(),
                'stack' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Ocorreu um erro ao buscar o item.'], 500);
        }
    }


    public function show($id)
    {
        try {
            \Log::info('Iniciando a exibição do item com ID: ' . $id);

            // Verificar se o usuário está autenticado
            if (!Auth::check()) {
                \Log::warning('Usuário não autenticado tentou acessar o recurso de exibição de item.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            // Obter o usuário autenticado
            $user = Auth::user();
            \Log::info('Usuário autenticado:', ['id' => $user->id, 'name' => $user->name]);

            // Verificar se o usuário possui permissão para visualizar o item
            if (!$user->hasPermission('item_view')) {
                \Log::warning('Usuário sem permissão tentou visualizar o item.', ['user_id' => $user->id]);
                return response()->json(['error' => 'Você não tem permissão para visualizar itens.'], 403);
            }

            // Buscar o item pelo ID
            $item = Item::find($id);
            if (!$item) {
                \Log::warning('Item não encontrado.', ['item_id' => $id]);
                return response()->json(['error' => 'Item não encontrado.'], 404);
            }

            \Log::info('Item encontrado.', ['item_id' => $item->id]);

            // Retornar o item encontrado
            return response()->json($item, 200);

        } catch (\Exception $e) {
            \Log::error('Erro ao buscar o item com ID: ' . $id, ['exception' => $e->getMessage()]);
            return response()->json(['error' => 'Ocorreu um erro ao buscar o item.'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            \Log::info('Iniciando a atualização de um item.', ['item_id' => $id]);

            // Verificar se o usuário está autenticado
            if (!Auth::check()) {
                \Log::warning('Usuário não autenticado tentou acessar o recurso.', ['item_id' => $id]);
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            // Obter o usuário autenticado
            $user = Auth::user();
            \Log::info('Usuário autenticado:', ['id' => $user->id, 'name' => $user->name]);

            // Verificar se o usuário possui permissão para atualizar itens
            if (!$user->hasPermission('item_update')) {
                \Log::warning('Usuário sem permissão tentou atualizar item.', ['user_id' => $user->id, 'item_id' => $id]);
                return response()->json(['error' => 'Você não tem permissão para atualizar itens.'], 403);
            }

            // Buscar o item pelo ID
            $item = Item::find($id);
            if (!$item) {
                \Log::warning('Item não encontrado.', ['item_id' => $id]);
                return response()->json(['error' => 'Item não encontrado.'], 404);
            }
            if ($request->has('stock') && $request->input('stock') === '') {
                $request->merge(['stock' => null]);
            }

            // Validar os dados da requisição
            \Log::info('Validando dados da requisição.');
            $validatedData = $request->validate([
                'name' => 'nullable|string|max:255',
                'type' => 'nullable|string|max:100',
                'price' => 'nullable|numeric|min:0',
                'stock' => 'nullable|integer|min:0',
                'status' => 'nullable|boolean',
                'image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
                'availability_start' => 'nullable|date',
                'availability_end' => 'nullable|date|after:availability_start',
                'discount' => 'nullable|numeric|min:0|max:100',
                'expiration_date' => 'nullable|date',
                'app_id' => 'nullable|exists:applications,id',
                'duration' => 'nullable|integer|min:1|max:480',
            ], $this->getValidationMessages());

            \Log::info('Dados validados com sucesso:', $validatedData);

            // Atualizar os dados do item apenas com os campos presentes na requisição
            $item->update(array_filter([
                'name' => $validatedData['name'] ?? $item->name,
                'type' => $validatedData['type'] ?? $item->type,
                'price' => $validatedData['price'] ?? $item->price,
                'stock' => $validatedData['stock'] ?? $item->stock,
                'status' => isset($validatedData['status']) ? (int) $validatedData['status'] : $item->status,
                'description' => $request->input('description', $item->description),
                'category' => $request->input('category', $item->category),
                'subcategory' => $request->input('subcategory', $item->subcategory),
                'brand' => $request->input('brand', $item->brand),
                'availability_start' => $request->input('availability_start', $item->availability_start),
                'availability_end' => $request->input('availability_end', $item->availability_end),
                'is_featured' => $request->input('is_featured', $item->is_featured),
                'discount' => $request->input('discount', $item->discount),
                'expiration_date' => $request->input('expiration_date', $item->expiration_date),
                'limited_by_user' => $request->input('limited_by_user', $item->limited_by_user),
                'notes' => $request->input('notes', $item->notes),
                'duration' => $request->input('duration', $item->duration),
            ]));

            \Log::info('Item atualizado no banco de dados.', ['item_id' => $item->id]);

            // Processar e salvar a logo se fornecida
            if ($request->hasFile('image')) {
                Log::info('Imagem do item  fornecida, processando...');

                // Definir o caminho do diretório público para imagens
                $destinationPath = public_path('images');

                // Gerar um nome único para a imagem
                $imageName = uniqid('item_') . '.' . $request->file('image')->getClientOriginalExtension();

                // Mover a imagem para o diretório público "images"
                $request->file('image')->move($destinationPath, $imageName);

                // Redimensionar a imagem para 150x150
                $image = Image::make($destinationPath . '/' . $imageName);
                $image->fit(150, 150);
                $image->save();

                // Atualizar o caminho da logo no banco
                $item->image = 'images/' . $imageName;
                $item->save();
            }
            // Retornar sucesso
            \Log::info('Item atualizado com sucesso.', ['item_id' => $item->id]);
            return response()->json(['message' => 'Item atualizado com sucesso.', 'item' => $item], 200);

        } catch (ValidationException $e) {
            \Log::warning('Erros de validação ao atualizar item.', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            \Log::error('Erro ao atualizar item: ' . $e->getMessage(), ['stack' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Ocorreu um erro ao atualizar o item.'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            \Log::info('Iniciando a exclusão do item com ID: ' . $id);

            if (!Auth::check()) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();
            \Log::info('Usuário autenticado:', ['id' => $user->id, 'name' => $user->name]);

            $item = Item::with('orderItems')->find($id);
            if (!$item) {
                return response()->json(['error' => 'Item não encontrado.'], 404);
            }

            if (!$user->hasPermission('item_delete') && $user->id !== $item->user_id) {
                return response()->json(['error' => 'Você não tem permissão para excluir este item.'], 403);
            }

            if ($item->orderItems()->exists()) {
                $item->orderItems()->delete();
            }

            if ($item->image) {
                $imagePath = storage_path('app/public/items/' . $item->image);
                if (File::exists($imagePath)) {
                    File::delete($imagePath);
                }
            }

            $item->delete();
            return response()->json(['message' => 'Item deletado com sucesso.'], 200);

        } catch (\Exception $e) {
            \Log::error('Erro ao deletar o item: ' . $e->getMessage(), ['stack' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Ocorreu um erro ao deletar o item.'], 500);
        }
    }

    public function listByApp(Request $request)
    {
        try {
            \Log::info('Iniciando a busca de itens por aplicativo.');

            // Validação dos parâmetros
            $validatedData = $request->validate([
                'app_id' => 'required|exists:applications,id', // Verifica se o app_id existe na tabela applications
            ], $this->getValidationMessages());

            \Log::info('Parâmetros validados com sucesso:', $validatedData);

            // Buscar itens com base no app_id fornecido
            $items = Item::where('app_id', $validatedData['app_id'])->get();

            \Log::info('Itens encontrados para o aplicativo.', ['total' => $items->count()]);

            // Retornar a lista de itens
            return response()->json($items, 200);

        } catch (ValidationException $e) {
            \Log::warning('Erro de validação na listagem de itens por aplicativo.', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            \Log::error('Erro inesperado ao buscar itens por aplicativo.', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Ocorreu um erro ao buscar os itens.'], 500);
        }
    }

    public function listAll(Request $request)
    {
        try {
            \Log::info('Iniciando a busca de todos os itens da API.');

            // Buscar todos os itens
            $items = Item::all();

            \Log::info('Itens encontrados:', ['total' => $items->count()]);

            // Retornar a lista de itens
            return response()->json($items, 200);

        } catch (\Exception $e) {
            \Log::error('Erro inesperado ao buscar itens.', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Ocorreu um erro ao buscar os itens.'], 500);
        }
    }
    public function listServicesByEntity(Request $request)
    {
        try {
            \Log::info('Iniciando a busca de serviços por entidade.');

            // Validação dos parâmetros
            $validatedData = $request->validate([
                'entity_name' => 'required|string|max:100',
                'app_id' => 'required|integer',
                'entity_id' => 'required|integer',
            ], $this->getValidationMessages());

            \Log::info('Parâmetros validados com sucesso:', $validatedData);

            // Buscar itens com base na entidade fornecida
            $items = Item::where('entity_name', $validatedData['entity_name'])
                ->where('entity_id', $validatedData['entity_id'])
                ->where('app_id', $validatedData['app_id'])
                ->where('type', 'serviço')  // Filtro adicional para 'service'
                ->get();

            // Verificar se foram encontrados itens
            if ($items->isEmpty()) {
                \Log::info('Nenhum item encontrado para a entidade fornecida neste aplicativo.');
                return response()->json(['message' => 'Nenhum serviço encontrado para a entidade fornecida.'], 404);
            }

            \Log::info('Serviços encontrados:', ['items' => $items]);

            return response()->json(['services' => $items], 200);

        } catch (ValidationException $e) {
            \Log::warning('Erros de validação ao buscar serviços por entidade.', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            \Log::error('Erro ao buscar serviços por entidade: ' . $e->getMessage(), ['stack' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Ocorreu um erro ao buscar os serviços.'], 500);
        }
    }

    public function storeBulk(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Usuário não autenticado.'], 401);
        }

        $user = Auth::user();
        if (!$user->hasPermission('item_create')) {
            return response()->json(['error' => 'Você não tem permissão para cadastrar itens.'], 403);
        }

        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.name' => 'required|string|max:255',
            'items.*.type' => 'required|string|max:100',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.stock' => 'nullable|integer|min:0',
            'items.*.status' => 'required|boolean',
            'items.*.limited_by_user' => 'nullable|boolean',
            'items.*.category' => 'nullable|string|max:100',
            'items.*.subcategory' => 'nullable|string|max:100',
            'items.*.brand' => 'nullable|string|max:100',
            'items.*.description' => 'nullable|string',
            'items.*.availability_start' => 'nullable|date',
            'items.*.availability_end' => 'nullable|date|after:items.*.availability_start',
            'items.*.expiration_date' => 'nullable|date',
            'items.*.discount' => 'nullable|numeric|min:0|max:100',
            'items.*.notes' => 'nullable|string',
            'items.*.is_featured' => 'nullable|boolean',
            'items.*.slug' => 'nullable|string|max:255',
            'items.*.entity_id' => 'required|integer',
            'items.*.entity_name' => 'required|string|max:100',
            'items.*.app_id' => 'required|exists:applications,id',
            'items.*.duration' => 'nullable|integer|min:1|max:480',
        ], $this->getValidationMessages());

        DB::beginTransaction();
        try {
            $created = [];
            foreach ($validated['items'] as $data) {
                $data['user_id'] = $user->id;
                $data['stock'] = $data['stock'] ?? null;
                $item = Item::create([
                    'name' => $data['name'],
                    'type' => $data['type'],
                    'sku' => $data['sku'] ?? null,
                    'description' => $data['description'] ?? null,
                    'price' => $data['price'],
                    'stock' => $data['stock'],
                    'status' => (int) $data['status'],
                    'limited_by_user' => (int) ($data['limited_by_user'] ?? 0),
                    'category' => $data['category'] ?? null,
                    'subcategory' => $data['subcategory'] ?? null,
                    'brand' => $data['brand'] ?? null,
                    'availability_start' => $data['availability_start'] ?? null,
                    'availability_end' => $data['availability_end'] ?? null,
                    'expiration_date' => $data['expiration_date'] ?? null,
                    'discount' => $data['discount'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'is_featured' => (bool) ($data['is_featured'] ?? false),
                    'entity_id' => $data['entity_id'],
                    'entity_name' => $data['entity_name'],
                    'app_id' => $data['app_id'],
                    'duration' => $data['duration'] ?? null,
                ]);

                $slug = Str::slug($data['name']);
                $count = Item::where('slug', $slug)->count();
                if ($count > 0) {
                    $slug .= '-' . ($count + 1);
                }
                $item->slug = $slug;
                $item->save();

                $created[] = $item;
            }
            DB::commit();
            return response()->json(['message' => 'Itens cadastrados com sucesso.', 'items' => $created], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro no cadastro em massa de itens: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao cadastrar os itens.'], 500);
        }
    }
    public function increasePricesByPercentage(Request $request)
    {
        try {
            \Log::info('Iniciando aumento de preços dos itens de um estabelecimento.');

            if (!Auth::check()) {
                \Log::warning('Usuário não autenticado tentou aumentar preços.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();
            if (!$user->hasPermission('item_update')) {
                \Log::warning('Usuário sem permissão tentou aumentar preços.', ['user_id' => $user->id]);
                return response()->json(['error' => 'Você não tem permissão para alterar preços.'], 403);
            }

            $validated = $request->validate([
                'entity_id' => 'required|integer|exists:establishments,id',
                'entity_name' => 'required|string|max:100',
                'percentage' => 'required|numeric|min:0',
            ], [
                'entity_id.required' => 'O campo entity_id é obrigatório.',
                'entity_id.integer' => 'O campo entity_id deve ser um número inteiro.',
                'entity_id.exists' => 'O estabelecimento informado não existe.',
                'entity_name.required' => 'O campo entity_name é obrigatório.',
                'entity_name.string' => 'O campo entity_name deve ser uma string.',
                'percentage.required' => 'O campo porcentagem é obrigatório.',
                'percentage.numeric' => 'O campo porcentagem deve ser um número.',
                'percentage.min' => 'O campo porcentagem deve ser maior ou igual a 0.',
            ]);

            $entityId = $validated['entity_id'];
            $entityName = $validated['entity_name'];
            $percentage = $validated['percentage'];

            // 🔹 Corrigido: verificação de propriedade com model
            $isOwner = \App\Models\Establishment::where('id', $entityId)
                ->where('user_id', $user->id)
                ->exists();

            if (!$isOwner) {
                \Log::warning('Usuário não é o dono do estabelecimento.', ['user_id' => $user->id]);
                return response()->json(['error' => 'Você não é o proprietário deste estabelecimento.'], 403);
            }

            $items = Item::where('entity_id', $entityId)
                ->where('entity_name', $entityName)
                ->get();

            if ($items->isEmpty()) {
                \Log::info('Nenhum item encontrado para o estabelecimento.', ['entity_id' => $entityId]);
                return response()->json(['message' => 'Nenhum item encontrado para o estabelecimento.'], 404);
            }

            foreach ($items as $item) {
                $oldPrice = $item->price;
                $newPrice = round($oldPrice * (1 + ($percentage / 100)), 2);
                $item->update(['price' => $newPrice]);

                \Log::info('Preço atualizado.', [
                    'item_id' => $item->id,
                    'old_price' => $oldPrice,
                    'new_price' => $newPrice
                ]);
            }

            return response()->json([
                'message' => 'Preços atualizados com sucesso.',
                'total_updated' => $items->count(),
                'percentage_applied' => $percentage
            ], 200);

        } catch (ValidationException $e) {
            \Log::warning('Erro de validação ao aumentar preços.', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            \Log::error('Erro ao aumentar preços dos itens: ' . $e->getMessage(), ['stack' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Ocorreu um erro ao aumentar os preços.'], 500);
        }
    }
}