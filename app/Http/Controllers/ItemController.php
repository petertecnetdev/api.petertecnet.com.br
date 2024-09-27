<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\Item;
use App\Models\App; // Model do aplicativo
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
        ];
    }

    public function store(Request $request)
    {
        try {
            // Verificar se o usuário está autenticado
            if (!Auth::check()) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }
    
            // Obter o usuário autenticado
            $user = Auth::user();
    
            // Verificar se o usuário possui permissão para cadastrar itens
            if (!$user->hasPermission('item_create')) {
                return response()->json(['error' => 'Você não tem permissão para cadastrar itens.'], 403);
            }
    
            // Validação dos dados da requisição
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'type' => 'required|string|max:100',
                'price' => 'required|numeric|min:0',
                'stock' => 'required|integer|min:0',
                'status' => 'required|boolean',
                'entity_id' => 'required|integer',
                'entity_name' => 'required|string|max:100',
                'image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
                'availability_start' => 'nullable|date',
                'availability_end' => 'nullable|date|after:availability_start',
                'discount' => 'nullable|numeric|min:0|max:100',
                'expiration_date' => 'nullable|date',
                'app_id' => 'required|exists:applications,id',
            ], $this->getValidationMessages());
    
            // Console log para mostrar os dados recebidos
            \Log::info('Dados recebidos para criação de item:', $validatedData);
    
            // Criação do item
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
            ]);
    
            // Processar e salvar a imagem, se fornecida
            if ($request->hasFile('image')) {
                $imagePath = $request->file('image')->store('public/items');
                $image = Image::make(storage_path('app/' . $imagePath));
                $image->fit(250, 250);
                $image->save();
    
                // Salvar o caminho da imagem no item
                $item->image = str_replace('public/', '', $imagePath);
                $item->save();
            }
    
            // Gerar slug para o item
            $slug = Str::slug($validatedData['name']);
            $count = Item::where('slug', $slug)->count();
            if ($count > 0) {
                $slug = $slug . '-' . ($count + 1);
            }
            $item->slug = $slug;
            $item->save();
    
            // Retornar sucesso
            return response()->json(['message' => 'Item cadastrado com sucesso.', 'item' => $item], 201);
    
        } catch (ValidationException $e) {
            // Captura erros de validação e retorna como resposta JSON
            return response()->json([
                'errors' => $e->errors(),
            ], 422);
    
        } catch (\Exception $e) {
            // Log do erro e retorno de mensagem genérica
            Log::error('Erro ao cadastrar item: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao cadastrar o item.'], 500);
        }
    }
    public function list(Request $request)
{
    try {
        // Verificar se o usuário está autenticado
        if (!Auth::check()) {
            return response()->json(['error' => 'Usuário não autenticado.'], 401);
        }

        // Obter o usuário autenticado
        $user = Auth::user();

        // Verificar se o usuário possui permissão para listar itens
        if (!$user->hasPermission('item_list')) {
            return response()->json(['error' => 'Você não tem permissão para listar itens.'], 403);
        }

        // Obter todos os itens com paginação
        $items = Item::paginate(10); // Defina a quantidade de itens por página

        // Retornar a lista de itens
        return response()->json([
            'message' => 'Itens listados com sucesso.',
            'items' => $items,
        ], 200);
        
    } catch (\Exception $e) {
        // Log do erro e retorno de mensagem genérica
        Log::error('Erro ao listar itens: ' . $e->getMessage());
        return response()->json(['error' => 'Ocorreu um erro ao listar os itens.'], 500);
    }
}

}    