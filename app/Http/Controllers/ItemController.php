<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\{Item, Establishment, OrderItem, Interaction};
use Illuminate\Support\Facades\File;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;


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
        if (!Auth::check()) {
            return response()->json(['error' => 'Usuário não autenticado.'], 401);
        }

        $user = Auth::user();

        if (!$user->hasPermission('item_create')) {
            return response()->json(['error' => 'Você não tem permissão para cadastrar itens.'], 403);
        }

        if ($request->has('stock') && $request->input('stock') === '') {
            $request->merge(['stock' => null]);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|max:100',
            'price' => 'required|numeric|min:0',
            'stock' => 'nullable|integer|min:0',
            'status' => 'required|boolean',
            'entity_id' => 'required|integer',
            'entity_name' => 'required|string|max:100',
            'images' => 'nullable|array',
            'images.*' => 'image|mimes:jpeg,png,jpg,webp|max:8192',
            'primary_image_index' => 'nullable|integer|min:0',
            'availability_start' => 'nullable|date',
            'availability_end' => 'nullable|date|after:availability_start',
            'discount' => 'nullable|numeric|min:0|max:100',
            'expiration_date' => 'nullable|date',
            'app_id' => 'required|exists:applications,id',
            'duration' => 'nullable|integer|min:1|max:480',
        ], $this->getValidationMessages());

        DB::beginTransaction();

        $item = Item::create([
            'name' => $data['name'],
            'type' => $data['type'],
            'price' => $data['price'],
            'stock' => $data['stock'],
            'status' => (int) $data['status'],
            'user_id' => $user->id,
            'entity_id' => $data['entity_id'],
            'entity_name' => $data['entity_name'],
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
            'app_id' => $data['app_id'],
            'duration' => $request->input('duration'),
        ]);

        $baseSlug = Str::slug($item->name);
        $slug = $baseSlug;
        if (Item::where('slug', $slug)->exists()) {
            $slug .= '-' . uniqid();
        }
        $item->update(['slug' => $slug]);

        if ($request->hasFile('images')) {
            $primaryIndex = $request->input('primary_image_index', 0);

            foreach ($request->file('images') as $index => $file) {
                $stored = File::storeOne(
                    file: $file,
                    entityName: 'item',
                    entityId: $item->id,
                    type: 'avatar',
                    appId: $data['app_id'],
                    createdBy: $user->id
                );

                if ((int) $index === (int) $primaryIndex) {
                    $stored->update(['is_primary' => true]);
                    $item->update(['image' => $stored->public_url]);
                }
            }
        }

        DB::commit();

        return response()->json([
            'message' => 'Item cadastrado com sucesso.',
            'item' => $item->refresh()->load('files'),
        ], 201);

    } catch (\Illuminate\Validation\ValidationException $e) {
        DB::rollBack();
        return response()->json(['errors' => $e->errors()], 422);

    } catch (\Exception $e) {
        DB::rollBack();
        \Log::error('[ItemController::store]', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json(['error' => 'Ocorreu um erro ao cadastrar o item.'], 500);
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
            if (!Auth::check()) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();
            $item = Item::find($id);

            if (!$item) {
                return response()->json(['error' => 'Item não encontrado.'], 404);
            }

            // Permissão (igual ao padrão do Establishment)
            if (!$user->hasPermission('item_update')) {
                return response()->json(['error' => 'Acesso negado.'], 403);
            }

            // ============================
            // 🔍 VALIDAÇÃO
            // ============================
            $validated = $request->validate([
                'name' => 'nullable|string|max:255',
                'type' => 'nullable|string|max:100',
                'description' => 'nullable|string|max:2500',
                'price' => 'nullable|numeric|min:0',
                'stock' => 'nullable|integer|min:0',
                'status' => 'nullable|boolean',
                'limited_by_user' => 'nullable|boolean',
                'category' => 'nullable|string|max:255',
                'subcategory' => 'nullable|string|max:255',
                'brand' => 'nullable|string|max:255',
                'availability_start' => 'nullable|date',
                'availability_end' => 'nullable|date|after:availability_start',
                'tags' => 'nullable|string',
                'discount' => 'nullable|numeric|min:0|max:100',
                'expiration_date' => 'nullable|date',
                'notes' => 'nullable|string|max:2500',
                'is_featured' => 'nullable|boolean',

                // imagens
                'image' => 'nullable|image|max:4096',
                'remove_image' => 'nullable|integer|in:0,1',
            ]);

            // CAPTURA DADOS ANTES DA ALTERAÇÃO
            $oldData = $item->getOriginal();
            $changes = [];

            foreach ($validated as $key => $value) {
                if (($oldData[$key] ?? null) != $value && $key !== "image") {
                    $changes[$key] = [
                        'old' => $oldData[$key] ?? null,
                        'new' => $value
                    ];
                }
            }

            // ============================
            // 🔥 ✔ REMOVER IMAGEM
            // ============================
            if ($request->remove_image == 1 && $item->image) {

                $item->deleteImage($item->image); // ← Usa o trait HandlesImages
                $changes['image'] = [
                    'old' => $item->image,
                    'new' => null
                ];

                $item->image = null;
            }

            // ============================
            // 🔥 ✔ UPLOAD NOVA IMAGEM
            // ============================
            if ($request->hasFile('image')) {

                // Remove imagem antiga
                if ($item->image) {
                    $item->deleteImage($item->image);
                }

                // Upload pelo trait HandlesImages
                $newPath = $item->uploadImage($request->file('image'), 'item_', 250);

                $changes['image'] = [
                    'old' => $oldData['image'] ?? null,
                    'new' => $newPath
                ];

                $validated['image'] = $newPath;
            }

            // ============================
            // 🔠 SLUG SE NOME MUDAR
            // ============================
            if (!empty($validated['name']) && $validated['name'] !== $oldData['name']) {
                $base = \Illuminate\Support\Str::slug($validated['name']);
                $count = Item::where('slug', 'LIKE', "$base%")
                    ->where('id', '!=', $item->id)
                    ->count();

                $newSlug = $count ? "{$base}-" . ($count + 1) : $base;

                $changes['slug'] = [
                    'old' => $item->slug,
                    'new' => $newSlug
                ];

                $validated['slug'] = $newSlug;
            }

            // ============================
            // 💾 SALVAR ALTERAÇÕES
            // ============================
            $item->fill($validated);
            $item->updated_by = $user->id;
            $item->save();

            // SALVA INTERAÇÃO SE TIVER CHANGES
            if (!empty($changes)) {
                Interaction::registerUpdate($item, $user, $changes);
            }

            return response()->json([
                'message' => 'Item atualizado com sucesso.',
                'item' => $item,
                'changes' => $changes
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            \Log::error('Erro ao atualizar item', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['error' => 'Erro ao atualizar item.'], 500);
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
            Log::info('🟡 Iniciando aumento de preços dos itens de um estabelecimento...');

            if (!Auth::check()) {
                Log::warning('🔴 Usuário não autenticado tentou aumentar preços.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();
            Log::info('👤 Usuário autenticado.', ['user_id' => $user->id, 'email' => $user->email]);

            if (!$user->hasPermission('item_update')) {
                Log::warning('🚫 Usuário sem permissão tentou aumentar preços.', ['user_id' => $user->id]);
                return response()->json(['error' => 'Você não tem permissão para alterar preços.'], 403);
            }

            $validated = $request->validate([
                'entity_id' => 'required|integer|exists:establishments,id',
                'percentage' => 'required|numeric|min:0',
            ], [
                'entity_id.required' => 'O campo entity_id é obrigatório.',
                'entity_id.integer' => 'O campo entity_id deve ser um número inteiro.',
                'entity_id.exists' => 'O estabelecimento informado não existe.',
                'percentage.required' => 'O campo porcentagem é obrigatório.',
                'percentage.numeric' => 'O campo porcentagem deve ser um número.',
                'percentage.min' => 'O campo porcentagem deve ser maior ou igual a 0.',
            ]);

            $entityId = (int) $validated['entity_id'];
            $percentage = (float) $validated['percentage'];

            Log::info('📋 Dados recebidos para aumento de preço.', [
                'entity_id' => $entityId,
                'percentage' => $percentage,
                'user_id' => $user->id,
            ]);

            $establishment = \App\Models\Establishment::find($entityId);
            if (!$establishment) {
                Log::error('❌ Estabelecimento não encontrado.', ['entity_id' => $entityId]);
                return response()->json(['error' => 'Estabelecimento não encontrado.'], 404);
            }

            if ($establishment->user_id !== $user->id) {
                Log::warning('⚠️ Usuário não é o dono do estabelecimento.', [
                    'user_id' => $user->id,
                    'establishment_user_id' => $establishment->user_id,
                    'entity_id' => $entityId
                ]);
                return response()->json(['error' => 'Você não é o proprietário deste estabelecimento.'], 403);
            }

            Log::info('✅ Usuário é o proprietário. Buscando itens...');

            $items = Item::where('entity_id', $entityId)
                ->whereRaw('LOWER(entity_name) = ?', ['establishment'])
                ->get();

            Log::info('📦 Resultado da busca de itens:', [
                'entity_id' => $entityId,
                'total_items' => $items->count(),
                'entity_names_encontrados' => $items->pluck('entity_name')->unique()->values(),
            ]);

            if ($items->isEmpty()) {
                Log::warning('⚠️ Nenhum item encontrado para este estabelecimento.', [
                    'entity_id' => $entityId
                ]);
                return response()->json(['message' => 'Nenhum item encontrado para o estabelecimento.'], 404);
            }

            $totalUpdated = 0;
            foreach ($items as $item) {
                $oldPrice = (float) $item->price;
                $newPrice = round($oldPrice * (1 + ($percentage / 100)), 2);

                Log::debug('💲 Atualizando preço do item...', [
                    'item_id' => $item->id,
                    'item_name' => $item->name,
                    'old_price' => $oldPrice,
                    'new_price' => $newPrice,
                ]);

                $item->price = $newPrice;
                $item->updated_by = $user->id;
                $item->save();
                $totalUpdated++;
            }

            Log::info('✅ Aumento de preços concluído.', [
                'entity_id' => $entityId,
                'percentage' => $percentage,
                'total_updated' => $totalUpdated,
            ]);

            return response()->json([
                'message' => "Preços aumentados em {$percentage}% com sucesso.",
                'total_updated' => $totalUpdated,
                'percentage_applied' => $percentage,
            ], 200);

        } catch (ValidationException $e) {
            Log::warning('⚠️ Erro de validação ao aumentar preços.', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            Log::error('💥 Erro inesperado ao aumentar preços dos itens.', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Ocorreu um erro ao aumentar os preços.'], 500);
        }
    }

    public function decreasePricesByPercentage(Request $request)
    {
        try {
            Log::info('🟡 Iniciando redução de preços dos itens de um estabelecimento...');

            if (!Auth::check()) {
                Log::warning('🔴 Usuário não autenticado tentou reduzir preços.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();
            Log::info('👤 Usuário autenticado.', ['user_id' => $user->id, 'email' => $user->email]);

            if (!$user->hasPermission('item_update')) {
                Log::warning('🚫 Usuário sem permissão tentou reduzir preços.', ['user_id' => $user->id]);
                return response()->json(['error' => 'Você não tem permissão para alterar preços.'], 403);
            }

            $validated = $request->validate([
                'entity_id' => 'required|integer|exists:establishments,id',
                'percentage' => 'required|numeric|min:0|max:100',
            ], [
                'entity_id.required' => 'O campo entity_id é obrigatório.',
                'entity_id.integer' => 'O campo entity_id deve ser um número inteiro.',
                'entity_id.exists' => 'O estabelecimento informado não existe.',
                'percentage.required' => 'O campo porcentagem é obrigatório.',
                'percentage.numeric' => 'O campo porcentagem deve ser um número.',
                'percentage.min' => 'O campo porcentagem deve ser maior ou igual a 0.',
                'percentage.max' => 'O campo porcentagem não pode ultrapassar 100%.',
            ]);

            $entityId = (int) $validated['entity_id'];
            $percentage = (float) $validated['percentage'];

            Log::info('📋 Dados recebidos para redução de preço.', [
                'entity_id' => $entityId,
                'percentage' => $percentage,
                'user_id' => $user->id,
            ]);

            $establishment = \App\Models\Establishment::find($entityId);
            if (!$establishment) {
                Log::error('❌ Estabelecimento não encontrado.', ['entity_id' => $entityId]);
                return response()->json(['error' => 'Estabelecimento não encontrado.'], 404);
            }

            if ($establishment->user_id !== $user->id) {
                Log::warning('⚠️ Usuário não é o dono do estabelecimento.', [
                    'user_id' => $user->id,
                    'establishment_user_id' => $establishment->user_id,
                    'entity_id' => $entityId
                ]);
                return response()->json(['error' => 'Você não é o proprietário deste estabelecimento.'], 403);
            }

            Log::info('✅ Usuário é o proprietário. Buscando itens...');

            $items = Item::where('entity_id', $entityId)
                ->whereRaw('LOWER(entity_name) = ?', ['establishment'])
                ->get();

            Log::info('📦 Resultado da busca de itens:', [
                'entity_id' => $entityId,
                'total_items' => $items->count(),
                'entity_names_encontrados' => $items->pluck('entity_name')->unique()->values(),
            ]);

            if ($items->isEmpty()) {
                Log::warning('⚠️ Nenhum item encontrado para este estabelecimento.', [
                    'entity_id' => $entityId
                ]);
                return response()->json(['message' => 'Nenhum item encontrado para o estabelecimento.'], 404);
            }

            $totalUpdated = 0;
            foreach ($items as $item) {
                $oldPrice = (float) $item->price;
                $newPrice = round($oldPrice * (1 - ($percentage / 100)), 2);

                // 🔹 Garante que o preço nunca fique negativo
                if ($newPrice < 0) {
                    $newPrice = 0;
                }

                Log::debug('💲 Atualizando preço do item...', [
                    'item_id' => $item->id,
                    'item_name' => $item->name,
                    'old_price' => $oldPrice,
                    'new_price' => $newPrice,
                ]);

                $item->price = $newPrice;
                $item->updated_by = $user->id;
                $item->save();
                $totalUpdated++;
            }

            Log::info('✅ Redução de preços concluída.', [
                'entity_id' => $entityId,
                'percentage' => $percentage,
                'total_updated' => $totalUpdated,
            ]);

            return response()->json([
                'message' => "Preços reduzidos em {$percentage}% com sucesso.",
                'total_updated' => $totalUpdated,
                'percentage_applied' => $percentage,
            ], 200);

        } catch (ValidationException $e) {
            Log::warning('⚠️ Erro de validação ao reduzir preços.', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            Log::error('💥 Erro inesperado ao reduzir preços dos itens.', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Ocorreu um erro ao reduzir os preços.'], 500);
        }
    }


    public function view($slug)
    {
        $authUser = Auth::user();

        $item = Item::with([
            'entity:id,name,slug,logo,background,app_id',
            'orderItems.order.client:id,first_name,last_name,user_name,avatar,email',
            'interactions.user:id,first_name,last_name,user_name,avatar,email',
            'files' => fn($q) => $q->where('entity_name', 'item'),
        ])
            ->where('slug', $slug)
            ->firstOrFail();

        Interaction::registerView($item, $authUser);
        Cache::forget("item_{$item->id}_metrics");
        Cache::forget("item_{$item->id}_summary");
        Cache::forget("item_{$item->id}_orders_summary");

        $image = $item->files->firstWhere('type', 'image')?->public_url ?? $item->image;
        $gallery = $item->files->whereNotIn('type', ['image'])->pluck('public_url')->values();

        return response()->json([
            'item' => [
                'id' => $item->id,
                'type' => 'item',
                'name' => $item->name,
                'slug' => $item->slug,
                'price' => $item->price,
                'images' => [
                    'avatar' => $image,
                    'gallery' => $gallery
                ]
            ],
            'entity' => $item->entity,
            'metrics' => $item->metrics,
            'interaction_summary' => $item->interactionSummary(),
            'user_interactions' => $item->userInteractions(),
            'orders_summary' => $item->ordersSummary(),
            'top_employer' => $item->topEmployer(),
            'other_establishments' => $item->otherEstablishments() ?? [],
            'other_employers' => $item->otherEmployers() ?? [],
            'other_items' => $item->otherItems() ?? [],
        ]);
    }
public function home(Request $request, $app_id)
{
    $city = $request->query('city');
    $uf   = $request->query('uf');

    $establishmentIds = Establishment::where('app_id', $app_id)
        ->when($city && $uf, fn($q) =>
            $q->where('city', $city)->where('uf', $uf)
        )
        ->pluck('id');

    $items = Item::whereIn('entity_id', $establishmentIds)
        ->where('entity_name', 'establishment')
        ->with([
            'establishment:id,name,slug,city,uf',
            'files' => fn($q) => $q->where('entity_name', 'item'),
        ])
        ->withCount([
            'views as total_views' => fn($q) =>
                $q->where('interaction_type', 'view'),
            'views as unique_users' => fn($q) =>
                $q->select(\DB::raw('COUNT(DISTINCT user_id)'))->where('interaction_type', 'view'),
            'orderItems as total_completed_appointments' => fn($q) =>
                $q->whereHas(
                    'order',
                    fn($o) =>
                    $o->whereIn('appointment_status', ['confirmed', 'attended'])
                ),
        ])
        ->orderByDesc('total_completed_appointments')
        ->get()
        ->map(function ($item) {

            // avatar principal
            $avatar = $item->files
                ->firstWhere('type', 'image')
                ?->public_url;

            // fallback caso não tenha imagem
            $avatar = $avatar ?: asset('images/logo.png');

            // gallery
            $gallery = $item->files
                ->whereNotIn('type', ['image'])
                ->pluck('public_url')
                ->values();

            // clientes únicos atendidos
            $uniqueClients = OrderItem::where('item_id', $item->id)
                ->whereHas(
                    'order',
                    fn($o) =>
                    $o->whereIn('appointment_status', ['confirmed', 'attended'])
                )
                ->with('order:id,client_id')
                ->get()
                ->pluck('order.client_id')
                ->filter()
                ->unique()
                ->count();

            return [
                'id'   => $item->id,

                // *** AQUI ESTÁ A CORREÇÃO PRINCIPAL ***
                // agora respeita exatamente o que está no banco:
                // service, product, addon, etc.
                'type' => $item->type,

                'name' => $item->name,
                'slug' => $item->slug,
                'price' => $item->price,

                'images' => [
                    'avatar'  => $avatar,
                    'gallery' => $gallery,
                ],

                'city' => $item->establishment?->city,
                'uf'   => $item->establishment?->uf,

                'total_views' => $item->total_views,
                'unique_users' => $item->unique_users,
                'unique_clients_attended' => $uniqueClients,
                'total_completed_appointments' => $item->total_completed_appointments,

                'establishment' => [
                    'name' => $item->establishment?->name,
                    'slug' => $item->establishment?->slug,
                ],
            ];
        });

    return response()->json(['items' => $items]);
}

}