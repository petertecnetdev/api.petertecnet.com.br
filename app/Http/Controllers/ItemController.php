<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\{Item, Event};
use Illuminate\Support\Facades\Validator;

class ItemController extends Controller
{
    protected function getValidationMessages()
    {
        return [
            'event_id.required' => 'O campo ID do evento é obrigatório.',
            'event_id.integer' => 'O campo ID do evento deve ser um número inteiro.',
            'name.required' => 'O campo nome é obrigatório.',
            'name.string' => 'O campo nome deve ser uma string.',
            'name.max' => 'O campo nome não pode ter mais de 255 caracteres.',
            'type.required' => 'O campo tipo é obrigatório.',
            'type.string' => 'O campo tipo deve ser uma string.',
            'price.required' => 'O campo preço é obrigatório.',
            'price.numeric' => 'O campo preço deve ser um número.',
            'stock.required' => 'O campo estoque é obrigatório.',
            'stock.integer' => 'O campo estoque deve ser um número inteiro.',
            'status.required' => 'O campo status é obrigatório.',
            'status.boolean' => 'O campo status deve ser um valor booleano.',
            'limited_by_user.required' => 'O campo limite por usuário é obrigatório.',
            'limited_by_user.boolean' => 'O campo limite por usuário deve ser um valor booleano.',
            'category.required' => 'O campo categoria é obrigatório.',
            'category.string' => 'O campo categoria deve ser uma string.',
            'availability_start.required' => 'O campo data de início de disponibilidade é obrigatório.',
            'availability_start.date' => 'O campo data de início de disponibilidade deve ser uma data.',
            'availability_end.required' => 'O campo data de término de disponibilidade é obrigatório.',
            'availability_end.date' => 'O campo data de término de disponibilidade deve ser uma data.',
            'image.url' => 'O campo imagem deve ser uma URL válida.',
            'is_featured.boolean' => 'O campo destaque deve ser um valor booleano.',
        ];
    }

    public function list()
    {
        try {
            $items = Item::all();
            return response()->json(['items' => $items], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao listar itens: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao listar os itens.'], 500);
        }
    }

    public function show($id)
    {
        try {
            $item = Item::with('event', 'user')->findOrFail($id);
            return response()->json(['item' => $item], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao exibir item: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao exibir o item.'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            Log::info('Iniciando validação dos dados do item.');
            $validator = Validator::make($request->all(), [
                'event_id' => 'required|integer|exists:events,id',
                'name' => 'required|string|max:255',
                'type' => 'required|string|max:255',
                'price' => 'required|numeric',
                'stock' => 'required|integer',
                'status' => 'required|boolean',
                'limited_by_user' => 'required|boolean',
                'category' => 'required|string',
                'availability_start' => 'required|date',
                'availability_end' => 'required|date',
                'image' => 'nullable|url',
                'is_featured' => 'nullable|boolean',
            ], $this->getValidationMessages());

            if ($validator->fails()) {
                Log::warning('Erro de validação ao criar item.', $validator->errors()->toArray());
                return response()->json(['message' => 'Erro de validação.', 'errors' => $validator->errors()], 422);
            }

            Log::info('Validação dos dados do item concluída com sucesso.');

            $event = Event::findOrFail($request->input('event_id'));
            Log::info('Evento encontrado:', ['event_id' => $event->id]);

            if ($event->production->user_id !== auth()->user()->id) {
                Log::info('Verificando permissões do usuário.');
                if (!auth()->user()->hasPermission('item_create')) {
                    Log::error('Usuário não tem permissão para criar itens neste evento.');
                    return response()->json(['error' => 'Você não tem permissão para criar itens neste evento.'], 403);
                }
            }

            $item = new Item();
            $item->event_id = $request->input('event_id');
            $item->user_id = auth()->user()->id;
            $item->name = $request->input('name');
            $item->type = $request->input('type');
            $item->price = $request->input('price');
            $item->stock = $request->input('stock');
            $item->status = $request->input('status');
            $item->limited_by_user = $request->input('limited_by_user');
            $item->category = $request->input('category');
            $item->availability_start = $request->input('availability_start');
            $item->availability_end = $request->input('availability_end');
            $item->image = $request->input('image', '');
            $item->is_featured = $request->input('is_featured', false);

            $item->save();

            Log::info('Item criado com sucesso.', ['item_id' => $item->id]);

            return response()->json(['message' => 'Item criado com sucesso!', 'item' => $item], 201);
        } catch (\Exception $e) {
            Log::error('Erro ao criar item: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['error' => 'Ocorreu um erro ao criar o item.'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            Log::info('Iniciando validação dos dados do item para atualização.');

            // Definir regras de validação apenas para campos presentes no corpo da solicitação
            $rules = [];
            if ($request->has('event_id')) {
                $rules['event_id'] = 'integer|exists:events,id';
            }
            if ($request->has('name')) {
                $rules['name'] = 'string|max:255';
            }
            if ($request->has('type')) {
                $rules['type'] = 'string|max:255';
            }
            if ($request->has('price')) {
                $rules['price'] = 'numeric';
            }
            if ($request->has('stock')) {
                $rules['stock'] = 'integer';
            }
            if ($request->has('status')) {
                $rules['status'] = 'boolean';
            }
            if ($request->has('limited_by_user')) {
                $rules['limited_by_user'] = 'boolean';
            }
            if ($request->has('category')) {
                $rules['category'] = 'string';
            }
            if ($request->has('availability_start')) {
                $rules['availability_start'] = 'date';
            }
            if ($request->has('availability_end')) {
                $rules['availability_end'] = 'date';
            }
            if ($request->has('image')) {
                $rules['image'] = 'url';
            }
            if ($request->has('is_featured')) {
                $rules['is_featured'] = 'boolean';
            }

            Log::info('Regras de validação aplicadas:', ['rules' => $rules]);

            $validator = Validator::make($request->all(), $rules, $this->getValidationMessages());

            if ($validator->fails()) {
                Log::warning('Erro de validação ao atualizar item.', $validator->errors()->toArray());
                return response()->json(['message' => 'Erro de validação.', 'errors' => $validator->errors()], 422);
            }

            Log::info('Validação dos dados do item concluída com sucesso.');

            $item = Item::findOrFail($id);

            Log::info('Item encontrado:', ['item_id' => $item->id]);

            if ($request->has('event_id')) {
                $event = Event::findOrFail($request->input('event_id'));
                Log::info('Evento encontrado:', ['event_id' => $event->id]);

                if ($event->production->user_id !== auth()->user()->id) {
                    Log::info('Verificando permissões do usuário.');
                    if (!auth()->user()->hasPermission('item_update')) {
                        Log::error('Usuário não tem permissão para atualizar itens neste evento.');
                        return response()->json(['error' => 'Você não tem permissão para atualizar itens neste evento.'], 403);
                    }
                }

                $item->event_id = $request->input('event_id');
            }

            if ($request->has('name')) {
                $item->name = $request->input('name');
            }
            if ($request->has('type')) {
                $item->type = $request->input('type');
            }
            if ($request->has('price')) {
                $item->price = $request->input('price');
            }
            if ($request->has('stock')) {
                $item->stock = $request->input('stock');
            }
            if ($request->has('status')) {
                $item->status = $request->input('status');
            }
            if ($request->has('limited_by_user')) {
                $item->limited_by_user = $request->input('limited_by_user');
            }
            if ($request->has('category')) {
                $item->category = $request->input('category');
            }
            if ($request->has('availability_start')) {
                $item->availability_start = $request->input('availability_start');
            }
            if ($request->has('availability_end')) {
                $item->availability_end = $request->input('availability_end');
            }
            if ($request->has('image')) {
                $item->image = $request->input('image');
            }
            if ($request->has('is_featured')) {
                $item->is_featured = $request->input('is_featured');
            }

            $item->save();

            Log::info('Item atualizado com sucesso.', ['item_id' => $item->id]);

            return response()->json(['message' => 'Item atualizado com sucesso!', 'item' => $item], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar item: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['error' => 'Ocorreu um erro ao atualizar o item.'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $item = Item::findOrFail($id);

            Log::info('Item encontrado para exclusão:', ['item_id' => $item->id]);

            if ($item->event->production->user_id !== auth()->user()->id) {
                Log::info('Verificando permissões do usuário.');
                if (!auth()->user()->hasPermission('item_delete')) {
                    Log::error('Usuário não tem permissão para excluir itens deste evento.');
                    return response()->json(['error' => 'Você não tem permissão para excluir itens deste evento.'], 403);
                }
            }

            $item->delete();

            Log::info('Item excluído com sucesso.', ['item_id' => $id]);

            return response()->json(['message' => 'Item excluído com sucesso!'], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao excluir item: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['error' => 'Ocorreu um erro ao excluir o item.'], 500);
        }
    }
}
