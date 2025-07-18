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

   public function store(Request $request)
{
    try {
        Log::info('Iniciando a criação de um novo agendamento.');

        // 1) Validação básica — adicionamos os campos do cliente anônimo como opcionais
        $validated = $request->validate([
            'app_id'           => 'required|exists:applications,id',
            'entity_name'      => 'required|string|max:255',
            'entity_id'        => 'required|integer|exists:barbershops,id',
            'scheduled_at'     => 'required|date',
            'service_ids'      => 'required|array|min:1',
            'service_ids.*'    => 'integer|exists:items,id',
            'provider_id'      => 'required|integer|exists:users,id',
            'status'           => 'required|string|max:50',
            'location'         => 'nullable|string|max:255',
            'notes'            => 'nullable|string',
            'payment_status'   => 'nullable|string|max:50',
            'appointment_type' => 'nullable|string|max:50',
            'duration'         => 'required|integer|min:1',

            // campos para cliente não autenticado
            'customer_name'  => 'required_without:token|string|max:255',
            'customer_cpf'   => 'required_without:token|string|max:20',
            'customer_phone' => 'required_without:token|string|max:30',
            'customer_email' => 'required_without:token|email|max:255',
        ], $this->getValidationMessages());

        // 2) Busca a barbearia
        $shop = Barbershop::findOrFail($validated['entity_id']);

        // 3) Determina client_id / registered_by
        if (Auth::check()) {
            $authUser = Auth::user();
            $clientId  = $request->input('client_id', $authUser->id);
            $registeredBy = $authUser->id;
        } else {
            // cliente anônimo: utilizamos o gerente da barbearia
            $clientId    = $shop->user_id;
            $registeredBy = $shop->user_id;
        }

        // 4) Não permitir agendar com si mesmo
        if ($clientId == $validated['provider_id']) {
            return response()->json([
                'error' => 'Cliente e prestador não podem ser a mesma pessoa.'
            ], 422);
        }

        // 5) Verifica associação do provider à barbearia
        if (! $shop->barbers()->where('user_id', $validated['provider_id'])->exists()) {
            return response()->json([
                'error' => 'Este prestador não atende nesta entidade.'
            ], 422);
        }

        // 6) Verifica serviços válidos
        $availableIds = $shop->items()->where('category','Serviços')->pluck('id')->toArray();
        $invalid = array_diff($validated['service_ids'], $availableIds);
        if (! empty($invalid)) {
            return response()->json([
                'error' => 'Serviços inválidos para esta entidade: ' . implode(', ', $invalid)
            ], 422);
        }

        // 7) Normaliza horário e checa futuro
        $scheduledAt = Carbon::parse($validated['scheduled_at'])->setTimezone('America/Sao_Paulo');
        if ($scheduledAt->isPast()) {
            return response()->json([
                'error' => 'A data e horário do agendamento devem ser no futuro.'
            ], 422);
        }

        // 8) Conflitos de horário
        if (Appointment::where('client_id',$clientId)->where('scheduled_at',$scheduledAt)->exists()
            || Appointment::where('provider_id',$validated['provider_id'])->where('scheduled_at',$scheduledAt)->exists()
        ) {
            return response()->json([
                'error' => 'Conflito de horário para cliente ou prestador.'
            ], 422);
        }

        // 9) Monta o JSON de info para cliente anônimo
        $info = null;
        if (! Auth::check()) {
            $info = [
                'name'  => $validated['customer_name'],
                'cpf'   => $validated['customer_cpf'],
                'phone' => $validated['customer_phone'],
                'email' => $validated['customer_email'],
            ];
        }

        // 10) Cria o agendamento
        $appointment = Appointment::create([
            'app_id'           => $validated['app_id'],
            'registered_by'    => $registeredBy,
            'entity_name'      => $validated['entity_name'],
            'entity_id'        => $validated['entity_id'],
            'scheduled_at'     => $scheduledAt,
            'provider_id'      => $validated['provider_id'],
            'client_id'        => $clientId,
            'status'           => $validated['status'],
            'location'         => $validated['location'] ?? null,
            'notes'            => $validated['notes'] ?? null,
            'payment_status'   => $validated['payment_status'] ?? null,
            'appointment_type' => $validated['appointment_type'] ?? null,
            'duration'         => $validated['duration'],
            'service_ids'      => $validated['service_ids'],
            'info'             => $info,    // aqui gravamos o JSON ou null
        ]);

        Log::info('Agendamento criado com sucesso.', ['appointment_id' => $appointment->id]);

        return response()->json([
            'message'     => 'Agendamento criado com sucesso!',
            'appointment' => $appointment,
        ], 201);

    } catch (ValidationException $e) {
        return response()->json(['errors' => $e->errors()], 422);
    } catch (\Exception $e) {
        Log::error('Erro ao criar agendamento: ' . $e->getMessage());
        return response()->json(['error' => 'Ocorreu um erro ao criar o agendamento.'], 500);
    }
}


    // App\Http\Controllers\AppointmentController.php

    public function listMy(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Não autenticado'], 401);
        }

        // Eager‐load de provider (User), barber (caso exista) e entity (Barbershop, etc)
        $appointments = Appointment::with(['provider', 'barber', 'entity'])
            ->where('client_id', $user->id)
            ->orderBy('scheduled_at', 'asc')
            ->get();

        if ($appointments->isEmpty()) {
            return response()->json(['message' => 'Nenhum agendamento encontrado.'], 404);
        }

        $payload = $appointments->map(function (Appointment $appt) {
            return [
                // **Todos** os campos da tabela appointments
                'id' => $appt->id,
                'app_id' => $appt->app_id,
                'entity_name' => $appt->entity_name,
                'entity_id' => $appt->entity_id,
                'scheduled_at' => $appt->scheduled_at->format('Y-m-d H:i:s'),
                'expected_end_time' => $appt->expected_end_time?->format('Y-m-d H:i:s'),
                'service_ids' => $appt->service_ids,
                'service_names' => $appt->service_names,  // accessor já retorna array
                'provider_id' => $appt->provider_id,
                'client_id' => $appt->client_id,
                'registered_by' => $appt->registered_by,
                'status' => $appt->status,
                'location' => $appt->location,
                'duration' => $appt->duration,
                'notes' => $appt->notes,
                'payment_status' => $appt->payment_status,
                'appointment_type' => $appt->appointment_type,
                'attendance_status' => $appt->attendance_status,
                'client_confirmation' => $appt->client_confirmation,
                'created_at' => $appt->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $appt->updated_at->format('Y-m-d H:i:s'),

                // Relação provider (User)
                'provider' => $appt->provider ? [
                    'id' => $appt->provider->id,
                    'first_name' => $appt->provider->first_name,
                    'slug' => $appt->provider->user_name,
                ] : null,

                // Relação barber (perfil extra, opcional)
                'barber_profile' => $appt->barber ? [
                    'id' => $appt->barber->id,
                    'slug' => $appt->barber->slug,
                    // outros campos de Barber se desejar...
                ] : null,

                // Relação entity (Barbershop, etc)
                'entity' => $appt->entity ? [
                    'id' => $appt->entity->id,
                    'name' => $appt->entity->name,
                    'slug' => $appt->entity->slug,
                    // outros campos de Barbershop se desejar...
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
public function listByProvider(Request $request)
{
    $user = auth()->user();
    if (!$user) {
        return response()->json(['error' => 'Não autenticado'], 401);
    }

    $validated = $request->validate([
        'app_id'       => 'nullable|integer|exists:applications,id',
        'entity_name'  => 'nullable|string',
        'entity_id'    => 'nullable|integer',
    ], $this->getValidationMessages());

    $query = Appointment::with(['provider','barber','client','entity'])
                        ->where('provider_id', $user->id);

    if (! empty($validated['app_id'])) {
        $query->where('app_id', $validated['app_id']);
    }
    if (! empty($validated['entity_name'])) {
        $query->where('entity_name', $validated['entity_name']);
    }
    if (! empty($validated['entity_id'])) {
        $query->where('entity_id', $validated['entity_id']);
    }

    $appointments = $query->orderBy('scheduled_at','asc')->get();

    if ($appointments->isEmpty()) {
        return response()->json(['message'=>'Nenhum agendamento encontrado.'], 404);
    }

    $payload = $appointments->map(fn($a) => [
        'id'                  => $a->id,
        'app_id'              => $a->app_id,
        'entity_name'         => $a->entity_name,
        'entity_id'           => $a->entity_id,
        'scheduled_at'        => $a->scheduled_at->format('Y-m-d H:i:s'),
        'expected_end_time'   => $a->expected_end_time?->format('Y-m-d H:i:s'),
        'service_ids'         => $a->service_ids,
        'service_names'       => $a->service_names,
        'provider_id'         => $a->provider_id,
        'client_id'           => $a->client_id,
        'registered_by'       => $a->registered_by,
        'status'              => $a->status,
        'location'            => $a->location,
        'duration'            => $a->duration,
        'notes'               => $a->notes,
        'payment_status'      => $a->payment_status,
        'appointment_type'    => $a->appointment_type,
        'attendance_status'   => $a->attendance_status,
        'client_confirmation' => $a->client_confirmation,
        'created_at'          => $a->created_at->format('Y-m-d H:i:s'),
        'updated_at'          => $a->updated_at->format('Y-m-d H:i:s'),

        'provider' => $a->provider ? [
            'id'         => $a->provider->id,
            'first_name' => $a->provider->first_name,
            'slug'       => $a->provider->user_name,
        ] : null,

        'barber_profile' => $a->barber ? [
            'id'   => $a->barber->id,
            'slug' => $a->barber->slug,
        ] : null,

        'client' => $a->client ? [
            'id'         => $a->client->id,
            'first_name' => $a->client->first_name,
        ] : null,

        'entity' => $a->entity ? [
            'id'   => $a->entity->id,
            'name' => $a->entity->name,
            'slug' => $a->entity->slug,
        ] : null,
    ]);

    return response()->json(['appointments'=>$payload], 200);
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
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();

            // validação de payload
            $validated = $request->validate([
                'status' => 'required|string|in:pending,confirmed,cancelled,completed',
                // só exigimos attendance_status se completed
                'attendance_status' => 'sometimes|required_if:status,completed|in:attended,not_attended',
            ], $this->getValidationMessages());

            $appointment = Appointment::with('entity')->find($id);
            if (!$appointment) {
                return response()->json(['error' => 'Agendamento não encontrado.'], 404);
            }

            $newStatus = $validated['status'];

            // regra de cancelamento
            if ($newStatus === 'cancelled') {
                $nowSP = \Carbon\Carbon::now('America/Sao_Paulo');
                $scheduled = $appointment->scheduled_at->setTimezone('America/Sao_Paulo');
                $minutesDiff = $scheduled->diffInMinutes($nowSP, false); // negativo se está no futuro

                // 1) se for o próprio cliente
                if ($appointment->client_id === $user->id) {
                    if ($minutesDiff > -20) {
                        // por exemplo: scheduled 15min à frente → diff = -15 > -20 → impede
                        return response()->json([
                            'error' => 'Você só pode cancelar até 20 minutos antes do horário agendado.'
                        ], 422);
                    }
                }
                // 2) se for outro cliente, só o provider ou dono da entidade podem
                elseif (
                    !in_array($user->id, [
                        $appointment->provider_id,
                        // no morphTo, $appointment->entity retorna a entidade correta
                        optional($appointment->entity)->user_id,
                    ])
                ) {
                    return response()->json([
                        'error' => 'Somente o prestador de serviço ou o proprietário  pode cancelar este agendamento.'
                    ], 403);
                }
            }

            // atualiza status/attendance
            $appointment->status = $newStatus;
            if ($newStatus === 'completed') {
                $appointment->attendance_status = $validated['attendance_status'];
            }
            $appointment->save();

            return response()->json([
                'message' => 'Status atualizado com sucesso!',
                'appointment' => $appointment,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar status do agendamento: ' . $e->getMessage());
            return response()->json(['error' => 'Erro ao atualizar status do agendamento.'], 500);
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
