<?php

namespace App\Http\Controllers;

use App\Models\{Appointment, Application, Item, Barbershop, User};
use App\Mail\AppointmentCreatedProvider;
use App\Mail\AppointmentCreatedClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class AppointmentController extends Controller
{
    protected function getValidationMessages()
    {
        return [
            'app_id.required' => 'O ID do aplicativo é obrigatório.',
            'app_id.exists' => 'O ID do aplicativo deve existir.',
            'entity_name.required' => 'O nome da entidade é obrigatório.',
            'entity_id.required' => 'O ID da entidade é obrigatório.',
            'entity_id.exists' => 'Entidade não encontrada.',
            'scheduled_at.required' => 'A data do agendamento é obrigatória.',
            'service_ids.required' => 'Selecione ao menos um serviço.',
            'provider_id.required' => 'Selecione um prestador.',
            'status.required' => 'Defina um status.',
            'duration.required' => 'A duração é obrigatória.',
            'customer_name.required' => 'Informe o nome do cliente.',
            'customer_cpf.required' => 'Informe o CPF do cliente.',
            'customer_phone.required' => 'Informe o telefone do cliente.',
            'customer_email.required' => 'Informe o email do cliente.',
        ];
    }public function store(Request $request)
{
    try {
        Log::info('Iniciando a criação de um novo agendamento.');

        $validated = $request->validate([
            'app_id' => ['required', 'exists:applications,id'],
            'entity_name' => ['required', 'string', 'max:255'],
            'entity_id' => ['required', 'integer', 'exists:barbershops,id'],
            'scheduled_at' => ['required', 'date'],
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => ['integer', 'exists:items,id'],
            'provider_id' => ['required', 'integer', 'exists:users,id'],
            'status' => ['required', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'payment_status' => ['nullable', 'string', 'max:50'],
            'appointment_type' => ['nullable', 'string', 'max:50'],
            'customer_name' => ['string', 'max:255', Rule::requiredIf(fn() => !Auth::check())],
            'customer_cpf' => ['string', 'max:20', Rule::requiredIf(fn() => !Auth::check())],
            'customer_phone' => ['string', 'max:30', Rule::requiredIf(fn() => !Auth::check())],
            'customer_email' => ['email', 'max:255', Rule::requiredIf(fn() => !Auth::check())],
        ], $this->getValidationMessages());

        $shop = Barbershop::findOrFail($validated['entity_id']);

        if (Auth::check()) {
            $clientId = $request->input('client_id', Auth::id());
            $registeredBy = Auth::id();
        } else {
            $clientId = $shop->user_id;
            $registeredBy = $shop->user_id;
        }

        if (Auth::check() && $clientId === $validated['provider_id']) {
            return response()->json(['reason' => 'Cliente e prestador não podem ser a mesma pessoa.'], 422);
        }

        if (!$shop->barbers()->where('user_id', $validated['provider_id'])->exists()) {
            return response()->json(['reason' => 'Este prestador não atende nesta entidade.'], 422);
        }

        $availableIds = $shop->items()
            ->where('category', 'Serviços')
            ->pluck('id')
            ->toArray();

        $invalid = array_diff($validated['service_ids'], $availableIds);
        if (!empty($invalid)) {
            return response()->json([
                'reason' => 'Serviços inválidos para esta entidade: ' . implode(', ', $invalid)
            ], 422);
        }

        $scheduledAt = Carbon::parse($validated['scheduled_at'])->setTimezone('America/Sao_Paulo');
        if ($scheduledAt->isPast()) {
            return response()->json(['reason' => 'A data e horário devem ser no futuro.'], 422);
        }

        $serviceItems = Item::whereIn('id', $validated['service_ids'])->get();

        $totalDuration = 0;
        $slots = [];
        $offset = 0;

        foreach ($serviceItems as $item) {
            $duration = $item->duration ?? 25;
            $start = $scheduledAt->copy()->addMinutes($offset);
            $end = $start->copy()->addMinutes($duration);
            $slots[] = compact('start', 'end');
            $totalDuration += $duration;
            $offset += $duration;
        }

        $conflict = false;
        foreach ($slots as $slot) {
            $providerConflict = Appointment::where('provider_id', $validated['provider_id'])
                ->whereBetween('scheduled_at', [$slot['start'], $slot['end']->copy()->subSecond()])
                ->exists();

            $clientConflict = Appointment::where('client_id', $clientId)
                ->whereBetween('scheduled_at', [$slot['start'], $slot['end']->copy()->subSecond()])
                ->exists();

            if ($providerConflict || $clientConflict) {
                $conflict = true;
                break;
            }
        }

        if ($conflict) {
            $reason = 'Não foi possível agendar neste horário porque a quantidade de serviços selecionados exige um tempo maior de atendimento consecutivo. Como um ou mais desses horários já estão reservados para outros clientes, seu agendamento não pode ser realizado como solicitado. Quanto mais serviços você seleciona, maior o tempo necessário na agenda, aumentando as chances de conflito com agendamentos já existentes.';

            $nextAvailable = null;
            $searchStart = $scheduledAt->copy();
            $searchEnd = $searchStart->copy()->addDays(7);

            while ($searchStart->lessThan($searchEnd)) {
                $slotFree = true;
                $tempStart = $searchStart->copy();
                foreach ($serviceItems as $item) {
                    $duration = $item->duration ?? 25;
                    $tempEnd = $tempStart->copy()->addMinutes($duration);
                    $conflictCheck = Appointment::where('provider_id', $validated['provider_id'])
                        ->whereBetween('scheduled_at', [$tempStart, $tempEnd->copy()->subSecond()])
                        ->exists();
                    if ($conflictCheck) {
                        $slotFree = false;
                        break;
                    }
                    $tempStart = $tempEnd;
                }
                if ($slotFree) {
                    $nextAvailable = $searchStart->copy();
                    break;
                }
                $searchStart->addMinutes(5);
            }

            $otherProvidersIds = $shop->barbers()->pluck('user_id')->toArray();
            $otherProviders = array_diff($otherProvidersIds, [$validated['provider_id']]);
            $availableProviders = [];

            if (!empty($otherProviders)) {
                $providerNames = User::whereIn('id', $otherProviders)->pluck('first_name', 'id')->toArray();

                foreach ($otherProviders as $otherProviderId) {
                    $free = true;
                    $tempStart = $scheduledAt->copy();
                    foreach ($serviceItems as $item) {
                        $duration = $item->duration ?? 25;
                        $tempEnd = $tempStart->copy()->addMinutes($duration);
                        $conflictCheck = Appointment::where('provider_id', $otherProviderId)
                            ->whereBetween('scheduled_at', [$tempStart, $tempEnd->copy()->subSecond()])
                            ->exists();
                        if ($conflictCheck) {
                            $free = false;
                            break;
                        }
                        $tempStart = $tempEnd;
                    }
                    if ($free && isset($providerNames[$otherProviderId])) {
                        $availableProviders[] = $providerNames[$otherProviderId];
                    }
                }
            }

            $suggestion = '';
            if ($nextAvailable) {
                $suggestion .= 'Próximo horário disponível para este prestador: ' . $nextAvailable->format('d/m/Y H:i') . '. ';
            }
            if (!empty($availableProviders)) {
                $suggestion .= 'Outros prestadores disponíveis neste horário: ' . implode(', ', $availableProviders) . '. ';
            }
            $suggestion .= 'Você também pode tentar: escolher um horário diferente, selecionar um número menor de serviços para agendar juntos ou optar por outro prestador.';

            return response()->json([
                'reason' => $reason,
                'suggestion' => $suggestion
            ], 422);
        }

        $info = null;
        if (!Auth::check()) {
            $info = [
                'name' => $validated['customer_name'],
                'cpf' => $validated['customer_cpf'],
                'phone' => $validated['customer_phone'],
                'email' => $validated['customer_email'],
            ];
        }

        $appointment = Appointment::create([
            'app_id' => $validated['app_id'],
            'registered_by' => $registeredBy,
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
            'duration' => $totalDuration,
            'service_ids' => $validated['service_ids'],
            'info' => $info,
        ]);

        Log::info('Agendamento criado com sucesso.', ['appointment_id' => $appointment->id]);

        $appointment = Appointment::with(['application', 'entity', 'provider', 'client'])
            ->find($appointment->id);

        Mail::to($appointment->provider->email)
            ->send(new AppointmentCreatedProvider($appointment));

        $clientEmail = Auth::check()
            ? $appointment->client->email
            : $appointment->info['email'];

        Mail::to($clientEmail)
            ->send(new AppointmentCreatedClient($appointment));

        return response()->json([
            'message' => 'Agendamento criado com sucesso!',
            'appointment' => $appointment,
        ], 201);
    } catch (ValidationException $e) {
        return response()->json(['errors' => $e->errors()], 422);
    } catch (\Exception $e) {
        Log::error('Erro ao criar agendamento: ' . $e->getMessage());
        return response()->json(['reason' => 'Ocorreu um erro ao criar o agendamento.'], 500);
    }
}
public function listMy()
{
    try {
        if (!Auth::check()) {
            return response()->json(['error' => 'Usuário não autenticado.'], 401);
        }

        $user = Auth::user();

        $appointments = Appointment::with(['provider', 'entity'])
            ->where('client_id', $user->id)
            ->orderBy('scheduled_at', 'desc')
            ->get();

        $appointments->each(function ($appointment) {
            if (!empty($appointment->service_ids)) {
                $serviceIds = is_string($appointment->service_ids)
                    ? json_decode($appointment->service_ids, true)
                    : $appointment->service_ids;

                $appointment->service_names = is_array($serviceIds)
                    ? Item::whereIn('id', $serviceIds)
                        ->where('category', 'Serviços')
                        ->pluck('name')
                        ->toArray()
                    : [];
            } else {
                $appointment->service_names = [];
            }
        });

        return response()->json(['appointments' => $appointments], 200);
    } catch (\Exception $e) {
        Log::error('Erro ao listar agendamentos do usuário: ' . $e->getMessage());
        return response()->json(['error' => 'Erro ao listar agendamentos.'], 500);
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
    public function listByProvider(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Não autenticado'], 401);
        }

        $validated = $request->validate([
            'app_id' => 'nullable|integer|exists:applications,id',
            'entity_name' => 'nullable|string',
            'entity_id' => 'nullable|integer',
        ], $this->getValidationMessages());

        $query = Appointment::with(['provider', 'barber', 'client', 'entity'])
            ->where('provider_id', $user->id);

        if (!empty($validated['app_id'])) {
            $query->where('app_id', $validated['app_id']);
        }
        if (!empty($validated['entity_name'])) {
            $query->where('entity_name', $validated['entity_name']);
        }
        if (!empty($validated['entity_id'])) {
            $query->where('entity_id', $validated['entity_id']);
        }

        $appointments = $query->orderBy('scheduled_at', 'asc')->get();

        if ($appointments->isEmpty()) {
            return response()->json(['message' => 'Nenhum agendamento encontrado.'], 404);
        }

        $payload = $appointments->map(function ($a) {
            return [
                'id' => $a->id,
                'app_id' => $a->app_id,
                'entity_name' => $a->entity_name,
                'entity_id' => $a->entity_id,
                'scheduled_at' => $a->scheduled_at->format('Y-m-d H:i:s'),
                'expected_end_time' => $a->expected_end_time?->format('Y-m-d H:i:s'),
                'service_ids' => $a->service_ids,
                'service_names' => $a->service_names,
                'provider_id' => $a->provider_id,
                'client_id' => $a->client_id,
                'registered_by' => $a->registered_by,
                'status' => $a->status,
                'location' => $a->location,
                'duration' => $a->duration,
                'notes' => $a->notes,
                'payment_status' => $a->payment_status,
                'appointment_type' => $a->appointment_type,
                'attendance_status' => $a->attendance_status,
                'client_confirmation' => $a->client_confirmation,
                'created_at' => $a->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $a->updated_at->format('Y-m-d H:i:s'),

                'provider' => $a->provider ? [
                    'id' => $a->provider->id,
                    'first_name' => $a->provider->first_name,
                    'slug' => $a->provider->user_name,
                ] : null,

                'barber_profile' => $a->barber ? [
                    'id' => $a->barber->id,
                    'slug' => $a->barber->slug,
                ] : null,

                'client' => $a->client ? [
                    'id' => $a->client->id,
                    'first_name' => $a->client->first_name,
                    'phone' => $a->client->phone ?? null,
                    'email' => $a->client->email ?? null,
                ] : null,

                'entity' => $a->entity ? [
                    'id' => $a->entity->id,
                    'name' => $a->entity->name,
                    'slug' => $a->entity->slug,
                ] : null,

                // Passa o campo info como objeto decodificado para frontend acessar dados do cliente anonimo
                'info' => $a->info ? (is_string($a->info) ? json_decode($a->info, true) : $a->info) : null,
            ];
        });

        return response()->json(['appointments' => $payload], 200);
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
