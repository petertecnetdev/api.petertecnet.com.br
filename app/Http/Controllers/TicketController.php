<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\{Ticket, Production};
use App\Models\Event;
use Illuminate\Support\Facades\Validator;

class TicketController extends Controller
{
    protected function getValidationMessages()
    {
        return [
            'event_id.required' => 'O campo ID do evento é obrigatório.',
            'event_id.integer' => 'O campo ID do evento deve ser um número inteiro.',
            'ticket_type.required' => 'O campo tipo de ingresso é obrigatório.',
            'ticket_type.string' => 'O campo tipo de ingresso deve ser uma string.',
            'price.required' => 'O campo preço é obrigatório.',
            'price.numeric' => 'O campo preço deve ser um número.',
            'quantity.required' => 'O campo quantidade é obrigatório.',
            'quantity.integer' => 'O campo quantidade deve ser um número inteiro.',
            'status.required' => 'O campo status é obrigatório.',
            'status.boolean' => 'O campo status deve ser um valor booleano.',
            'name.required' => 'O campo nome é obrigatório.',
            'name.string' => 'O campo nome deve ser uma string.',
            'name.max' => 'O campo nome não pode ter mais de 255 caracteres.',
        ];
    }

    public function list()
    {
        try {
            $tickets = Ticket::all();
            return response()->json(['tickets' => $tickets], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao listar ingressos: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao listar os ingressos.'], 500);
        }
    }

    public function show($id)
    {
        try {
            $ticket = Ticket::with('event')->findOrFail($id);
            return response()->json(['ticket' => $ticket], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao exibir ingresso: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao exibir o ingresso.'], 500);
        }
    }
    

    public function store(Request $request)
    {
        try {
            Log::info('Iniciando validação dos dados do ingresso.');
            $validator = Validator::make($request->all(), [
                'event_id' => 'required|integer|exists:events,id',
                'name' => 'required|string|max:255',
                'ticket_type' => 'required|string|max:255',
                'price' => 'required|numeric',
                'quantity' => 'required|integer',
                'description' => 'nullable|string',
            ], $this->getValidationMessages());

            if ($validator->fails()) {
                Log::warning('Erro de validação ao criar ingresso.', $validator->errors()->toArray());
                return response()->json(['message' => 'Erro de validação.', 'errors' => $validator->errors()], 422);
            }

            Log::info('Validação dos dados do ingresso concluída com sucesso.');

            $event = Event::findOrFail($request->input('event_id'));
            Log::info('Evento encontrado:', ['event_id' => $event->id]);

            if ($event->production->user_id !== auth()->user()->id) {
                Log::info('Verificando permissões do usuário.');
                if (!auth()->user()->hasPermission('ticket_create')) {
                    Log::error('Usuário não tem permissão para criar ingressos neste evento.');
                    return response()->json(['error' => 'Você não tem permissão para criar ingressos neste evento.'], 403);
                }
            }

            $ticket = new Ticket();
            $ticket->event_id = $request->input('event_id');
            $ticket->name = $request->input('name');
            $ticket->ticket_type = $request->input('ticket_type');
            $ticket->price = $request->input('price');
            $ticket->quantity = $request->input('quantity');
            $ticket->description = $request->input('description', '');

            $ticket->save();

            Log::info('Ingresso criado com sucesso.', ['ticket_id' => $ticket->id]);

            return response()->json(['message' => 'Ingresso criado com sucesso!', 'ticket' => $ticket], 201);
        } catch (\Exception $e) {
            Log::error('Erro ao criar ingresso: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['error' => 'Ocorreu um erro ao criar o ingresso.'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            Log::info('Iniciando validação dos dados do ingresso para atualização.');

            // Definir regras de validação apenas para campos presentes no corpo da solicitação
            $rules = [];
            if ($request->has('event_id')) {
                $rules['event_id'] = 'integer|exists:events,id';
            }
            if ($request->has('name')) {
                $rules['name'] = 'string|max:255';
            }
            if ($request->has('ticket_type')) {
                $rules['ticket_type'] = 'string|max:255';
            }
            if ($request->has('price')) {
                $rules['price'] = 'numeric';
            }
            if ($request->has('quantity')) {
                $rules['quantity'] = 'integer';
            }
            if ($request->has('description')) {
                $rules['description'] = 'nullable|string';
            }

            Log::info('Regras de validação aplicadas:', ['rules' => $rules]);

            $validator = Validator::make($request->all(), $rules, $this->getValidationMessages());

            if ($validator->fails()) {
                Log::warning('Erro de validação ao atualizar ingresso.', $validator->errors()->toArray());
                return response()->json(['message' => 'Erro de validação.', 'errors' => $validator->errors()], 422);
            }

            Log::info('Validação dos dados do ingresso concluída com sucesso.');

            $ticket = Ticket::findOrFail($id);

            Log::info('Ingresso encontrado:', ['ticket_id' => $ticket->id]);

            if ($request->has('event_id')) {
                $event = Event::findOrFail($request->input('event_id'));
                Log::info('Evento encontrado:', ['event_id' => $event->id]);

                if ($event->production->user_id !== auth()->user()->id) {
                    Log::info('Verificando permissões do usuário.');
                    if (!auth()->user()->hasPermission('ticket_update')) {
                        Log::error('Usuário não tem permissão para atualizar ingressos neste evento.');
                        return response()->json(['error' => 'Você não tem permissão para atualizar ingressos neste evento.'], 403);
                    }
                }

                $ticket->event_id = $request->input('event_id');
            }

            if ($request->has('name')) {
                $ticket->name = $request->input('name');
            }
            if ($request->has('ticket_type')) {
                $ticket->ticket_type = $request->input('ticket_type');
            }
            if ($request->has('price')) {
                $ticket->price = $request->input('price');
            }
            if ($request->has('quantity')) {
                $ticket->quantity = $request->input('quantity');
            }
            if ($request->has('description')) {
                $ticket->description = $request->input('description', '');
            }

            $ticket->save();

            Log::info('Ingresso atualizado com sucesso.', ['ticket_id' => $ticket->id]);

            return response()->json(['message' => 'Ingresso atualizado com sucesso!', 'ticket' => $ticket], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar ingresso: ' . $e->getMessage(), ['exception' => $e, 'ticket_id' => $id, 'request_data' => $request->all()]);
            return response()->json(['error' => 'Ocorreu um erro ao atualizar o ingresso.'], 500);
        }
    }


    public function destroy($id)
    {
        try {
            $ticket = Ticket::findOrFail($id);

            if ($ticket->event->production->user_id !== auth()->user()->id) {
                Log::info('Verificando permissões do usuário.');
                if (!auth()->user()->hasPermission('ticket_delete')) {
                    Log::error('Usuário não tem permissão para deletar ingressos neste evento.');
                    return response()->json(['error' => 'Você não tem permissão para deletar ingressos neste evento.'], 403);
                }
            }

            $ticket->delete();

            Log::info('Ingresso deletado com sucesso.', ['ticket_id' => $ticket->id]);

            return response()->json(['message' => 'Ingresso deletado com sucesso!'], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao deletar ingresso: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['error' => 'Ocorreu um erro ao deletar o ingresso.'], 500);
        }
    }

    public function listByEvent($eventId)
    {
        try {
            // Validar o ID do evento
            $validator = Validator::make(['event_id' => $eventId], [
                'event_id' => 'required|integer|exists:events,id',
            ], $this->getValidationMessages());

            if ($validator->fails()) {
                Log::warning('Erro de validação ao listar ingressos por evento.', $validator->errors()->toArray());
                return response()->json(['message' => 'Erro de validação.', 'errors' => $validator->errors()], 422);
            }

            // Encontrar o evento
            $event = Event::findOrFail($eventId);
            Log::info('Evento encontrado:', ['event_id' => $event->id]);

            // Verificar permissões do usuário
            if ($event->production->user_id !== auth()->user()->id) {
                Log::info('Verificando permissões do usuário.');
                if (!auth()->user()->hasPermission('ticket_view')) {
                    Log::error('Usuário não tem permissão para visualizar ingressos deste evento.');
                    return response()->json(['error' => 'Você não tem permissão para visualizar ingressos deste evento.'], 403);
                }
            }

            // Listar ingressos do evento
            $tickets = Ticket::where('event_id', $eventId)->get();

            Log::info('Ingressos listados com sucesso para o evento.', ['event_id' => $eventId]);

            return response()->json(['tickets' => $tickets], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao listar ingressos por evento: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['error' => 'Ocorreu um erro ao listar os ingressos deste evento.'], 500);
        }
    }

    public function listByUser()
    {
        try {
            // Verificar se o usuário está autenticado
            $user = auth()->user();
            if (!$user) {
                Log::warning('Usuário não autenticado.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            // Encontrar todos os eventos do usuário
            $events = Event::where('production_id', $user->production_id)->pluck('id');

            if ($events->isEmpty()) {
                Log::info('Nenhum evento encontrado para o usuário.');
                return response()->json(['tickets' => []], 200);
            }

            // Encontrar todos os ingressos associados aos eventos do usuário
            $tickets = Ticket::whereIn('event_id', $events)->get();

            Log::info('Ingressos listados com sucesso para o usuário.', ['user_id' => $user->id]);

            return response()->json(['tickets' => $tickets], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao listar ingressos do usuário: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['error' => 'Ocorreu um erro ao listar os ingressos do usuário.'], 500);
        }
    }

    public function listByProduction($productionId)
    {
        try {
            // Verificar se o usuário está autenticado
            $user = auth()->user();
            if (!$user) {
                Log::warning('Usuário não autenticado.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            // Encontrar a produção do usuário
            $production = Production::findOrFail($productionId);

            // Verificar se a produção pertence ao usuário
            if ($production->user_id !== $user->id) {
                Log::warning('Usuário não tem permissão para acessar esta produção.', ['user_id' => $user->id, 'production_id' => $productionId]);
                return response()->json(['error' => 'Você não tem permissão para acessar esta produção.'], 403);
            }

            // Encontrar todos os eventos associados à produção
            $events = Event::where('production_id', $productionId)->pluck('id');

            if ($events->isEmpty()) {
                Log::info('Nenhum evento encontrado para a produção.', ['production_id' => $productionId]);
                return response()->json(['tickets' => []], 200);
            }

            // Encontrar todos os ingressos associados aos eventos da produção
            $tickets = Ticket::whereIn('event_id', $events)->get();

            Log::info('Ingressos listados com sucesso para a produção.', ['production_id' => $productionId]);

            return response()->json(['tickets' => $tickets], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao listar ingressos da produção: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['error' => 'Ocorreu um erro ao listar os ingressos da produção.'], 500);
        }
    }

}