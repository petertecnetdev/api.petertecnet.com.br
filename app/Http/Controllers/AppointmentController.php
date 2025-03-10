<?php

namespace App\Http\Controllers;

use App\Models\{Appointment, Item, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AppointmentController extends Controller
{
    protected function getValidationMessages()
    {
        return [
            'app_id.required' => 'O ID do aplicativo é obrigatório.',
            'app_id.exists' => 'O ID do aplicativo deve existir na tabela de aplicações.',
            'entity_name.required' => 'O nome da entidade é obrigatório.',
            'entity_name.string' => 'O nome da entidade deve ser uma string válida.',
            'entity_id.required' => 'O ID da entidade é obrigatório.',
            'entity_id.integer' => 'O ID da entidade deve ser um número inteiro.',
            'scheduled_at.required' => 'A data do agendamento é obrigatória.',
            'scheduled_at.date' => 'A data do agendamento deve ser uma data válida.',
            'scheduled_at.after' => 'A data do agendamento deve ser posterior à data atual.',
            'service_ids.required' => 'A lista de serviços é obrigatória.',
            'service_ids.array' => 'Os serviços devem ser enviados como uma lista.',
            'service_ids.*.integer' => 'Cada ID de serviço deve ser um número inteiro válido.',
            'service_ids.*.exists' => 'Um ou mais serviços selecionados não existem na tabela de itens.',
            'provider_id.required' => 'O prestador de serviço é obrigatório.',
            'provider_id.integer' => 'O ID do prestador de serviço deve ser um número inteiro.',
            'client_id.required' => 'O cliente é obrigatório.',
            'client_id.integer' => 'O ID do cliente deve ser um número inteiro.',
            'status.required' => 'O status do agendamento é obrigatório.',
            'status.string' => 'O status deve ser uma string válida.',
            'location.string' => 'A localização deve ser uma string válida.',
            'notes.string' => 'As observações devem ser uma string válida.',
            'payment_status.string' => 'O status do pagamento deve ser uma string válida.',
            'appointment_type.string' => 'O tipo de agendamento deve ser uma string válida.',
            'duration.required' => 'A duração do serviço é obrigatória.',
            'duration.integer' => 'A duração deve ser um número inteiro válido.',
            'duration.min' => 'A duração deve ser maior que zero.',
        ];
    }

    // Criação de um agendamento
    public function store(Request $request)
    {
        try {
            Log::info('Iniciando a criação de um novo agendamento.');

            if (!Auth::check()) {
                Log::warning('Usuário não autenticado tentou acessar o recurso.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();
            if (!$user->hasPermission('appointment_store')) {
                return response()->json(['error' => 'Você não tem permissão para realizar agendamentos.'], 403);
            }
            Log::info('Usuário autenticado:', ['id' => $user->id, 'name' => $user->name]);

            $validatedData = $request->validate([
                'app_id' => 'required|exists:applications,id',
                'entity_name' => 'required|string|max:255',
                'entity_id' => 'required|integer',
                'scheduled_at' => 'required|date',
                'service_ids' => 'required|array|min:1',
                'service_ids.*' => 'integer|exists:items,id',
                'provider_id' => 'required|integer',
                'client_id' => 'required|integer',
                'status' => 'required|string|max:50',
                'location' => 'nullable|string|max:255',
                'notes' => 'nullable|string',
                'payment_status' => 'nullable|string|max:50',
                'appointment_type' => 'nullable|string|max:50',
                'duration' => 'required|integer|min:1',
            ], $this->getValidationMessages());

            Log::info('Dados validados com sucesso:', $validatedData);

            $scheduledAt = Carbon::parse($validatedData['scheduled_at'])->setTimezone('America/Sao_Paulo');
            if ($scheduledAt->isPast()) {
                return response()->json(['error' => 'A data e o horário do agendamento devem ser no futuro.'], 422);
            }

            $provider = User::find($validatedData['provider_id']);
            if (!$provider) {
                Log::warning('Prestador de serviço não encontrado.', ['provider_id' => $validatedData['provider_id']]);
                return response()->json(['error' => 'Prestador de serviço não encontrado.'], 404);
            }

            $client = User::find($validatedData['client_id']);
            if (!$client) {
                Log::warning('Cliente não encontrado.', ['client_id' => $validatedData['client_id']]);
                return response()->json(['error' => 'Cliente não encontrado.'], 404);
            }

            $existingClientAppointment = Appointment::where('client_id', $validatedData['client_id'])
                ->where('scheduled_at', $scheduledAt)
                ->exists();
            if ($existingClientAppointment) {
                return response()->json(['error' => 'O cliente já possui um agendamento neste horário.'], 422);
            }

            $existingProviderAppointment = Appointment::where('provider_id', $validatedData['provider_id'])
                ->where('scheduled_at', $scheduledAt)
                ->exists();
            if ($existingProviderAppointment) {
                return response()->json(['error' => 'O prestador já possui um agendamento neste horário.'], 422);
            }

            $appointment = Appointment::create([
                'app_id' => $validatedData['app_id'],
                'registered_by' => $user->id,
                'entity_name' => $validatedData['entity_name'],
                'entity_id' => $validatedData['entity_id'],
                'scheduled_at' => $scheduledAt,
                'provider_id' => $validatedData['provider_id'],
                'client_id' => $validatedData['client_id'],
                'status' => $validatedData['status'],
                'location' => $validatedData['location'] ?? null,
                'notes' => $validatedData['notes'] ?? null,
                'payment_status' => $validatedData['payment_status'] ?? null,
                'appointment_type' => $validatedData['appointment_type'] ?? null,
                'duration' => $validatedData['duration'],
                'service_ids' => json_encode($validatedData['service_ids']),
            ]);

            Log::info('Agendamento criado com sucesso.', ['appointment_id' => $appointment->id]);

            return response()->json(['message' => 'Agendamento criado com sucesso!', 'appointment' => $appointment], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Erro ao criar agendamento: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao criar o agendamento.'], 500);
        }
    }

    // Lista os agendamentos do usuário autenticado (listMy)
    public function listMy(Request $request)
    {
        try {
            Log::info('Iniciando listagem dos agendamentos do usuário autenticado.');

            if (!Auth::check()) {
                Log::warning('Usuário não autenticado tentou acessar o recurso.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();
            $appointments = Appointment::where('client_id', $user->id)
                ->orderBy('scheduled_at', 'asc')
                ->get();

            if ($appointments->isEmpty()) {
                Log::warning('Nenhum agendamento encontrado para o usuário autenticado.', ['user_id' => $user->id]);
                return response()->json(['message' => 'Nenhum agendamento encontrado.'], 404);
            }

            $appointments->each(function ($appointment) {
                if (!empty($appointment->service_ids)) {
                    $serviceIds = is_string($appointment->service_ids)
                        ? json_decode($appointment->service_ids, true)
                        : $appointment->service_ids;
                    if (is_array($serviceIds)) {
                        $appointment->service_names = Item::whereIn('id', $serviceIds)
                            ->where('category', 'Serviços')
                            ->pluck('name')
                            ->toArray();
                    } else {
                        $appointment->service_names = [];
                    }
                } else {
                    $appointment->service_names = [];
                }
            });

            return response()->json(['appointments' => $appointments], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao listar os agendamentos do usuário: ', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Erro ao listar os agendamentos do usuário.'], 500);
        }
    }

    // Lista os agendamentos de um cliente específico (listByClient)
    public function listByClient(Request $request)
    {
        try {
            Log::info('Iniciando listagem de agendamentos por cliente e entidade.');

            if (!Auth::check()) {
                Log::warning('Usuário não autenticado tentou acessar o recurso.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();
            if (!$user->hasPermission('appointment_list')) {
                return response()->json(['error' => 'Você não tem permissão para listar agendamentos.'], 403);
            }

            $validatedData = $request->validate([
                'client_id' => 'required|integer',
                'entity_name' => 'required|string',
                'entity_id' => 'required|integer',
                'app_id' => 'required|integer',
            ], $this->getValidationMessages());

            $appointments = Appointment::where('client_id', $validatedData['client_id'])
                ->where('entity_name', $validatedData['entity_name'])
                ->where('entity_id', $validatedData['entity_id'])
                ->where('app_id', $validatedData['app_id'])
                ->orderBy('scheduled_at', 'asc')
                ->get();

            if ($appointments->isEmpty()) {
                Log::warning('Nenhum agendamento encontrado para o cliente e entidade especificados.', [
                    'client_id' => $validatedData['client_id'],
                    'entity_name' => $validatedData['entity_name'],
                    'entity_id' => $validatedData['entity_id'],
                    'app_id' => $validatedData['app_id'],
                ]);
                return response()->json(['message' => 'Nenhum agendamento encontrado.'], 404);
            }

            $appointments->each(function ($appointment) {
                if (!empty($appointment->service_ids)) {
                    $serviceIds = is_string($appointment->service_ids)
                        ? json_decode($appointment->service_ids, true)
                        : $appointment->service_ids;
                    if (is_array($serviceIds)) {
                        $appointment->service_names = Item::whereIn('id', $serviceIds)
                            ->where('category', 'Serviços')
                            ->pluck('name')
                            ->toArray();
                    } else {
                        $appointment->service_names = [];
                    }
                } else {
                    $appointment->service_names = [];
                }
            });

            return response()->json(['appointments' => $appointments], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Erro de validação ao listar agendamentos: ', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Erro ao listar agendamentos: ', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Erro ao listar agendamentos.'], 500);
        }
    }

    // Lista todos os agendamentos de uma entidade (listByEntity)
    public function listByEntity(Request $request)
    {
        try {
            Log::info('Iniciando a listagem de agendamentos por entidade.');

            if (!Auth::check()) {
                Log::warning('Usuário não autenticado tentou acessar o recurso.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();
            if (!$user->hasPermission('appointment_list')) {
                return response()->json(['error' => 'Você não tem permissão para listar agendamentos.'], 403);
            }

            $validatedData = $request->validate([
                'entity_id' => 'required|integer',
                'entity_name' => 'required|string|max:255',
            ], $this->getValidationMessages());

            $appointments = Appointment::where('entity_id', $validatedData['entity_id'])
                ->where('entity_name', $validatedData['entity_name'])
                ->orderBy('scheduled_at', 'asc')
                ->get();

            if ($appointments->isEmpty()) {
                Log::warning('Nenhum agendamento encontrado para a entidade.', [
                    'entity_id' => $validatedData['entity_id'],
                    'entity_name' => $validatedData['entity_name'],
                ]);
                return response()->json(['message' => 'Nenhum agendamento encontrado.'], 404);
            }

            $appointments->each(function ($appointment) {
                if (!empty($appointment->service_ids)) {
                    $serviceIds = is_string($appointment->service_ids)
                        ? json_decode($appointment->service_ids, true)
                        : $appointment->service_ids;
                    if (is_array($serviceIds)) {
                        $appointment->service_names = Item::whereIn('id', $serviceIds)
                            ->where('category', 'Serviços')
                            ->pluck('name')
                            ->toArray();
                    } else {
                        $appointment->service_names = [];
                    }
                } else {
                    $appointment->service_names = [];
                }
            });

            return response()->json(['appointments' => $appointments], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Erro de validação ao listar agendamentos: ', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Erro ao listar agendamentos: ', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Erro ao listar agendamentos.'], 500);
        }
    }

    // Lista os agendamentos do provedor (listByProvider)
    public function listByProvider(Request $request)
    {
        try {
            Log::info('Iniciando listagem de agendamentos por provedor.');

            if (!Auth::check()) {
                Log::warning('Usuário não autenticado tentou acessar o recurso.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();
            if (!$user->hasPermission('appointment_list')) {
                return response()->json(['error' => 'Você não tem permissão para listar agendamentos.'], 403);
            }

            $validatedData = $request->validate([
                'provider_id' => 'required|integer|exists:users,id',
                'app_id' => 'required|integer|exists:applications,id',
                'entity_name' => 'required|string|max:255',
                'entity_id' => 'required|integer',
            ], $this->getValidationMessages());

            $appointments = Appointment::where('provider_id', $validatedData['provider_id'])
                ->where('app_id', $validatedData['app_id'])
                ->where('entity_name', $validatedData['entity_name'])
                ->where('entity_id', $validatedData['entity_id'])
                ->orderBy('scheduled_at', 'asc')
                ->get();

            if ($appointments->isEmpty()) {
                Log::warning('Nenhum agendamento encontrado para o provedor especificado.', [
                    'provider_id' => $validatedData['provider_id'],
                    'app_id' => $validatedData['app_id'],
                    'entity_name' => $validatedData['entity_name'],
                    'entity_id' => $validatedData['entity_id'],
                ]);
                return response()->json(['message' => 'Nenhum agendamento encontrado.'], 404);
            }

            return response()->json(['appointments' => $appointments], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Erro de validação ao listar agendamentos: ', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Erro ao listar agendamentos.', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Erro ao buscar os agendamentos.'], 500);
        }
    }

    // Cancelamento de um agendamento
    public function destroy($id)
    {
        try {
            Log::info('Iniciando o processo de cancelamento de agendamento.', ['appointment_id' => $id]);

            if (!Auth::check()) {
                Log::warning('Usuário não autenticado tentou acessar o recurso.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();
            if (!$user->hasPermission('appointment_destroy') && !$user->hasPermission('appointment_destroy_all')) {
                return response()->json(['error' => 'Você não tem permissão para cancelar agendamentos.'], 403);
            }

            $appointment = Appointment::find($id);
            if (!$appointment) {
                Log::warning('Agendamento não encontrado.', ['appointment_id' => $id]);
                return response()->json(['error' => 'Agendamento não encontrado.'], 404);
            }

            // Em vez de deletar, atualiza o status para "cancelado"
            $appointment->status = 'cancelado';
            $appointment->save();

            Log::info('Agendamento cancelado com sucesso.', ['appointment_id' => $id]);
            return response()->json(['message' => 'Agendamento cancelado com sucesso!'], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao cancelar agendamento: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao cancelar o agendamento.'], 500);
        }
    }
}
