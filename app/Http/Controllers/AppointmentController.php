<?php

namespace App\Http\Controllers;

use App\Models\{Appointment, Item, Barbershop};
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

            // validação
            $validated = $request->validate([
                'app_id' => 'required|exists:applications,id',
                'entity_name' => 'required|string|max:255',
                'entity_id' => 'required|integer|exists:barbershops,id',
                'scheduled_at' => 'required|date',
                'service_ids' => 'required|array|min:1',
                'service_ids.*' => 'integer|exists:items,id',
                'provider_id' => 'required|integer|exists:users,id',
                'status' => 'required|string|max:50',
                'location' => 'nullable|string|max:255',
                'notes' => 'nullable|string',
                'payment_status' => 'nullable|string|max:50',
                'appointment_type' => 'nullable|string|max:50',
                'duration' => 'required|integer|min:1',
            ], $this->getValidationMessages());

            // determinar client_id e registered_by
            if (Auth::check()) {
                $user = Auth::user();
                $clientId = $request->input('client_id', $user->id);
                $registered = $user->id;

                if ($clientId !== $user->id && !$user->hasPermission('appointment_store')) {
                    return response()
                        ->json(['error' => 'Você não tem permissão para agendar em nome de outro.'], 403);
                }
            } else {
                // sem auth: usa o gerente da barbearia
                $shop = Barbershop::with('user')->find($validated['entity_id']);
                if (!$shop || !$shop->user) {
                    return response()->json([
                        'error' => 'Barbearia ou gerente não encontrado.'
                    ], 404);
                }

                $clientId = $shop->user->id;
                $registered = $shop->user->id;

                Log::warning('Sem usuário autenticado; usando gerente como solicitante.', [
                    'barbershop_id' => $shop->id,
                    'manager_id' => $shop->user->id,
                    'manager_name' => $shop->user->first_name,
                ]);
            }

            // normaliza horário no fuso de SP
            $scheduledAt = Carbon::parse($validated['scheduled_at'])
                ->setTimezone('America/Sao_Paulo');
            if ($scheduledAt->isPast()) {
                return response()->json([
                    'error' => 'A data e horário do agendamento devem ser no futuro.'
                ], 422);
            }

            // checar conflitos
            if (
                Appointment::where('client_id', $clientId)
                    ->where('scheduled_at', $scheduledAt)
                    ->exists()
            ) {
                return response()->json([
                    'error' => 'Cliente já possui um agendamento neste horário.'
                ], 422);
            }
            if (
                Appointment::where('provider_id', $validated['provider_id'])
                    ->where('scheduled_at', $scheduledAt)
                    ->exists()
            ) {
                return response()->json([
                    'error' => 'Prestador já possui um agendamento neste horário.'
                ], 422);
            }

            // cria agendamento
            $appointment = Appointment::create([
                'app_id' => $validated['app_id'],
                'registered_by' => $registered,
                'entity_name' => $validated['entity_name'],
                'entity_id' => $validated['entity_id'],
                'scheduled_at' => $scheduledAt,
                'provider_id' => $validated['provider_id'],
                'client_id' => $clientId,
                'status' => $validated['status'],
                'location' => $validated['location'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'payment_status' => $validated['payment_status'] ?? null,
                'appointment_type' => $validated['appointment_type'] ?? null,
                'duration' => $validated['duration'],
                'service_ids' => json_encode($validated['service_ids']),
            ]);

            Log::info('Agendamento criado com sucesso.', [
                'appointment_id' => $appointment->id
            ]);

            return response()->json([
                'message' => 'Agendamento criado com sucesso!',
                'appointment' => $appointment,
                'client' => [
                    'id' => $clientId,
                    'name' => Auth::check()
                        ? Auth::user()->first_name
                        : $shop->user->first_name,
                ],
            ], 201);


        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            Log::error('Erro ao criar agendamento: ' . $e->getMessage());
            return response()->json([
                'error' => 'Ocorreu um erro ao criar o agendamento.'
            ], 500);
        }
    }public function listMy(Request $request)
{
    Log::info('Iniciando listagem dos meus agendamentos.');

    if (! Auth::check()) {
        Log::warning('Usuário não autenticado tentou acessar listMy.');
        return response()->json(['error' => 'Usuário não autenticado.'], 401);
    }

    $user = Auth::user();

    $appointments = Appointment::with([
            'provider',            // Carrega o User
            'provider.barber',     // Carrega o perfil Barber (pode ser null)
            'provider.doctor',     // Carrega o perfil Doctor (pode ser null)
            'provider.dentist',    // Carrega o perfil Dentist (pode ser null)
            'entity'               // A entidade polimórfica (Barbershop, Hospital, Dentist…)
        ])
        ->where('client_id', $user->id)
        ->orderBy('scheduled_at', 'asc')
        ->get();

    if ($appointments->isEmpty()) {
        return response()->json(['message' => 'Nenhum agendamento encontrado.'], 404);
    }

    $payload = $appointments->map(function (Appointment $appt) {
        $providerUser = $appt->provider;       // sempre um User
        // escolhe o primeiro perfil existente
        $profile = $providerUser?->barber
                 ?? $providerUser?->doctor
                 ?? $providerUser?->dentist
                 // … adicione outros perfis aqui
                 ;

        $shop = $appt->entity;                 // Barbershop, Hospital, Dentist…

        return [
            'id'            => $appt->id,
            'scheduled_at'  => $appt->scheduled_at->toDateTimeString(),
            'status'        => $appt->status,
            'service_names' => $appt->service_names->toArray(),

            'provider' => $providerUser ? [
                'id'         => $providerUser->id,
                'first_name' => $providerUser->first_name,
                'slug'       => $profile?->slug,   // slug do perfil existente
            ] : null,

            'entity' => $shop ? [
                'id'   => $shop->id,
                'name' => $shop->name,
                'slug' => $shop->slug,
            ] : null,
        ];
    });

    return response()->json(['appointments' => $payload], 200);
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
            $appointment->status = 'cancelled';
            $appointment->save();

            Log::info('Agendamento cancelado com sucesso.', ['appointment_id' => $id]);
            return response()->json(['message' => 'Agendamento cancelado com sucesso!'], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao cancelar agendamento: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao cancelar o agendamento.'], 500);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        try {
            Log::info('Iniciando atualização do status do agendamento.', ['appointment_id' => $id]);

            if (!Auth::check()) {
                Log::warning('Usuário não autenticado tentou acessar o recurso.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();
            if (!$user->hasPermission('appointment_update_status')) {
                return response()->json(['error' => 'Você não tem permissão para alterar o status do agendamento.'], 403);
            }

            // Validação inicial do novo status
            $validatedData = $request->validate([
                'status' => 'required|string|max:50'
            ], $this->getValidationMessages());

            $allowedStatuses = ['pending', 'confirmed', 'cancelled', 'completed'];
            $newStatus = strtolower($validatedData['status']);

            if (!in_array($newStatus, $allowedStatuses)) {
                return response()->json(['error' => 'Status inválido.'], 422);
            }

            // Se o novo status for "completed", valida também o attendance_status
            if ($newStatus === 'completed') {
                $request->validate([
                    'attendance_status' => 'required|string|in:attended,not_attended'
                ], $this->getValidationMessages());
                $attendanceStatus = strtolower($request->input('attendance_status'));
            }

            $appointment = Appointment::find($id);
            if (!$appointment) {
                Log::warning('Agendamento não encontrado.', ['appointment_id' => $id]);
                return response()->json(['error' => 'Agendamento não encontrado.'], 404);
            }

            // Atualiza o status e, se aplicável, o attendance_status
            $appointment->status = $newStatus;
            if ($newStatus === 'completed') {
                $appointment->attendance_status = $attendanceStatus;
            }
            $appointment->save();

            Log::info('Status do agendamento atualizado com sucesso.', [
                'appointment_id' => $id,
                'status' => $newStatus,
                'attendance_status' => $newStatus === 'completed' ? $attendanceStatus : null
            ]);
            return response()->json([
                'message' => 'Status do agendamento atualizado com sucesso!',
                'appointment' => $appointment
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Erro de validação ao atualizar status do agendamento: ', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar status do agendamento: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao atualizar o status do agendamento.'], 500);
        }
    }

    public function availability(Request $request)
    {
        $data = $request->validate([
            'provider_id' => 'required|integer|exists:users,id',
            'entity_id' => 'required|integer|exists:barbershops,id',
            'date' => 'required|date_format:Y-m-d',
        ]);

        // gera todos os slots de 08:00 a 19:00
        $slots = [];
        for ($h = 8; $h < 19; $h++) {
            $slots[] = sprintf('%02d:00', $h);
            $slots[] = sprintf('%02d:30', $h);
        }
        $slots[] = '19:00';

        // busca os agendamentos do provider nessa data
        $appointments = Appointment::where('provider_id', $data['provider_id'])
            ->whereDate('scheduled_at', $data['date'])
            ->where('status', '<>', 'cancelled')  // <— ignora os cancelados
            ->get(['scheduled_at', 'duration']);

        $booked = [];

        foreach ($appointments as $appt) {
            $start = Carbon::parse($appt->scheduled_at);

            // Se preferir recalcular pela lista de serviços:
            // $services = is_string($appt->service_ids) ? json_decode($appt->service_ids, true) : $appt->service_ids;
            // $duration = count($services) * 25;

            // Ou use direto a coluna duration:
            $duration = $appt->duration;

            // quantos slots de 30m ocupa
            $slotsNeeded = (int) ceil($duration / 30);

            // marca o início e os próximos slots
            for ($i = 0; $i < $slotsNeeded; $i++) {
                $time = $start->copy()->addMinutes(30 * $i)->format('H:i');
                $booked[] = $time;
            }
        }

        // remove duplicatas e faz diff
        $booked = array_unique($booked);
        $available = array_values(array_diff($slots, $booked));

        return response()->json(['slots' => $available]);
    }
}
