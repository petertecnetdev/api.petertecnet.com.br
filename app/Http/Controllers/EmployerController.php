<?php

namespace App\Http\Controllers;

use App\Models\{Employer, Establishment, Interaction, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Mail\CreatePasswordMail;
use App\Mail\{NewEmployerCollaborator, OwnerNotifiedNewCollaborator};
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class EmployerController extends Controller
{
    private function sanitizeForJson($value)
    {
        if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            if (!mb_check_encoding($value, 'UTF-8')) {
                $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            }
            $value = iconv('UTF-8', 'UTF-8//IGNORE', $value);
            return $value;
        }

        if ($value instanceof \Illuminate\Support\Collection) {
            return $this->sanitizeForJson($value->toArray());
        }

        if ($value instanceof \JsonSerializable) {
            return $this->sanitizeForJson($value->jsonSerialize());
        }

        if ($value instanceof \Illuminate\Database\Eloquent\Model) {
            return $this->sanitizeForJson($value->toArray());
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->sanitizeForJson($v);
            }
            return $out;
        }

        if (is_object($value)) {
            return $this->sanitizeForJson((array) $value);
        }

        return $value;
    }

    private function jsonUtf8($data, int $status = 200, array $headers = [])
    {
        $clean = $this->sanitizeForJson($data);

        return response()->json(
            $clean,
            $status,
            $headers,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    protected function getValidationMessages()
    {
        return [
            'first_name.required' => 'O nome é obrigatório.',
            'first_name.string' => 'O nome deve ser um texto válido.',
            'first_name.max' => 'O nome deve ter no máximo 255 caracteres.',

            'email.required' => 'O e-mail é obrigatório.',
            'email.email' => 'O e-mail informado é inválido.',
            'email.max' => 'O e-mail deve ter no máximo 255 caracteres.',

            'establishment_id.required' => 'O estabelecimento é obrigatório.',
            'establishment_id.integer' => 'O estabelecimento deve ser um número inteiro.',
            'establishment_id.exists' => 'O estabelecimento informado não existe.',

            'link.required' => 'O link é obrigatório.',
            'link.url' => 'O link informado é inválido.',

            'role.required' => 'A função do colaborador é obrigatória.',
            'role.string' => 'A função deve ser um texto válido.',
            'role.max' => 'A função deve ter no máximo 255 caracteres.',

            'permissions.required' => 'As permissões são obrigatórias.',
            'permissions.array' => 'As permissões devem ser uma lista válida.',
        ];
    }

    protected function getScheduleValidationMessages()
    {
        return [
            'employer_id.required' => 'O campo employer_id é obrigatório.',
            'employer_id.integer' => 'O campo employer_id deve ser um número inteiro.',
            'employer_id.exists' => 'O colaborador informado não existe.',

            'schedules.array' => 'Os horários devem ser enviados em formato de lista.',

            'schedules.*.day_of_week.required' => 'O campo dia da semana é obrigatório.',
            'schedules.*.day_of_week.in' => 'O campo dia da semana deve conter um valor válido (monday a sunday).',

            'schedules.*.start_time.required' => 'O campo horário de início é obrigatório.',
            'schedules.*.start_time.date_format' => 'O horário de início deve estar no formato HH:mm.',

            'schedules.*.end_time.required' => 'O campo horário de término é obrigatório.',
            'schedules.*.end_time.date_format' => 'O horário de término deve estar no formato HH:mm.',
            'schedules.*.end_time.after' => 'O horário de término deve ser posterior ao horário de início.',
        ];
    }


    public function store(Request $request)
{
    try {
        Log::info('Employer.store start', [
            'auth_user_id' => Auth::id(),
            'payload' => $request->all(),
        ]);

        if (!Auth::check()) {
            return $this->jsonUtf8(['error' => 'Usuário não autenticado.'], 401);
        }

        $authUser = Auth::user();

        $validatedData = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'establishment_id' => 'required|integer|exists:establishments,id',
            'role' => 'required|string|max:255',
            'permissions' => 'nullable|array',
        ], $this->getValidationMessages());

        $establishment = Establishment::with('user')->find($validatedData['establishment_id']);
        if (!$establishment) {
            return $this->jsonUtf8([
                'error' => 'O estabelecimento informado não existe ou foi removido.',
            ], 404);
        }

        if ((int) $establishment->user_id !== (int) $authUser->id) {
            return $this->jsonUtf8([
                'error' => 'Apenas o dono do estabelecimento pode adicionar colaboradores.',
            ], 403);
        }

        if (
            Employer::where('user_id', $validatedData['user_id'])
                ->where('establishment_id', $validatedData['establishment_id'])
                ->exists()
        ) {
            return $this->jsonUtf8([
                'error' => 'Este usuário já está vinculado a este estabelecimento.',
            ], 409);
        }

        $employer = Employer::create([
            'user_id' => $validatedData['user_id'],
            'establishment_id' => $validatedData['establishment_id'],
            'role' => $validatedData['role'],
            'permissions' => $validatedData['permissions'] ?? [],
            'created_by' => $authUser->id,
            'updated_by' => $authUser->id,
        ]);

        Mail::to($establishment->user->email)->send(
            new OwnerNotifiedNewCollaborator($establishment, $employer)
        );

        Mail::to($employer->user->email)->send(
            new NewEmployerCollaborator($establishment, $employer)
        );

        Log::info('Employer.store success', ['employer_id' => $employer->id]);

        return $this->jsonUtf8([
            'message' => 'Colaborador vinculado ao estabelecimento com sucesso.',
            'employer' => $employer,
        ], 201);

    } catch (ValidationException $e) {
        Log::warning('Employer.store validation failed', [
            'errors' => $e->errors(),
        ]);

        return $this->jsonUtf8([
            'message' => 'Erro de validação nos dados enviados.',
            'errors' => $e->errors(),
        ], 422);

    } catch (\Throwable $e) {
        Log::error('Employer.store failed', [
            'error' => $e->getMessage(),
            'stack' => $e->getTraceAsString(),
        ]);

        return $this->jsonUtf8([
            'error' => 'Ocorreu um erro inesperado ao adicionar o colaborador.',
            'details' => $e->getMessage(),
        ], 500);
    }
}




    public function detach(Request $request)
    {
        try {
            Log::info('Employer.detach start', ['user_id' => Auth::id(), 'payload' => $request->all()]);

            if (!Auth::check()) {
                return $this->jsonUtf8(['error' => 'Usuário não autenticado.'], 401);
            }

            $validatedData = $request->validate([
                'employer_id' => 'required|integer|exists:employers,id',
                'establishment_id' => 'required|integer|exists:establishments,id',
            ], [
                'employer_id.required' => 'O ID do colaborador é obrigatório.',
                'employer_id.integer' => 'O ID do colaborador deve ser um número inteiro.',
                'employer_id.exists' => 'O colaborador informado não existe.',
                'establishment_id.required' => 'O ID do estabelecimento é obrigatório.',
                'establishment_id.integer' => 'O ID do estabelecimento deve ser um número inteiro.',
                'establishment_id.exists' => 'O estabelecimento informado não existe.',
            ]);

            $user = Auth::user();
            $establishment = Establishment::with('user')->find($validatedData['establishment_id']);

            if (!$establishment) {
                return $this->jsonUtf8(['error' => 'O estabelecimento informado não existe.'], 404);
            }

            if ((int) $establishment->user_id !== (int) $user->id) {
                return $this->jsonUtf8(['error' => 'Apenas o dono do estabelecimento pode desvincular colaboradores.'], 403);
            }

            $employer = Employer::with('user')
                ->where('id', $validatedData['employer_id'])
                ->where('establishment_id', $establishment->id)
                ->first();

            if (!$employer) {
                return $this->jsonUtf8(['error' => 'O colaborador não está vinculado a este estabelecimento.'], 404);
            }

            $collaboratorUser = $employer->user;
            $ownerUser = $establishment->user;

            $employer->delete();

            if ($collaboratorUser && !empty($collaboratorUser->email)) {
                try {
                    Mail::to($collaboratorUser->email)
                        ->send(new \App\Mail\EmployerRemoved($establishment, $collaboratorUser));
                } catch (\Throwable $e) {
                    Log::warning('Failed to send EmployerRemoved email', [
                        'error' => $e->getMessage(),
                        'employer_id' => $validatedData['employer_id']
                    ]);
                }
            }

            if ($ownerUser && !empty($ownerUser->email)) {
                try {
                    Mail::to($ownerUser->email)
                        ->send(new \App\Mail\OwnerNotifiedEmployerDetached($establishment, $collaboratorUser ?? null));
                } catch (\Throwable $e) {
                    Log::warning('Failed to send OwnerNotifiedEmployerDetached email', [
                        'error' => $e->getMessage(),
                        'employer_id' => $validatedData['employer_id']
                    ]);
                }
            }

            Log::info('Employer.detach success', [
                'employer_id' => $validatedData['employer_id'],
                'establishment_id' => $establishment->id
            ]);

            return $this->jsonUtf8(['message' => 'Colaborador desvinculado com sucesso e notificações enviadas.'], 200);

        } catch (ValidationException $e) {
            Log::warning('Employer.detach validation failed', ['errors' => $e->errors()]);
            return $this->jsonUtf8([
                'message' => 'Erro nos dados enviados.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Throwable $e) {
            Log::error('Employer.detach failed', ['error' => $e->getMessage(), 'stack' => $e->getTraceAsString()]);
            return $this->jsonUtf8([
                'error' => 'Ocorreu um erro inesperado ao desvincular o colaborador.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    public function checkUpdates(Request $request)
    {
        try {
            if (!Auth::check()) {
                return $this->jsonUtf8(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();

            $data = $request->validate([
                'employer_id' => 'required|integer|exists:employers,id',
                'last_check' => 'nullable|date',
            ], [
                'employer_id.required' => 'O campo employer_id é obrigatório.',
                'employer_id.exists' => 'O colaborador informado não existe.',
                'last_check.date' => 'O campo last_check deve ser uma data válida.',
            ]);

            $employer = Employer::with('establishment')->find($data['employer_id']);
            if (!$employer) {
                return $this->jsonUtf8(['error' => 'Colaborador não encontrado.'], 404);
            }

            $isOwner = Establishment::where('user_id', $user->id)
                ->where('id', $employer->establishment_id)
                ->exists();

            $isSelf = (int) $user->id === (int) $employer->user_id;

            if (!$isOwner && !$isSelf) {
                return $this->jsonUtf8(['error' => 'Acesso negado.'], 403);
            }

            $lastCheck = isset($data['last_check'])
                ? Carbon::parse($data['last_check'])
                : now()->subMinutes(10);

            $appointmentsQuery = \App\Models\Order::where('attendant_id', $employer->id)
                ->where('type', 'appointment')
                ->whereIn('appointment_status', ['pending', 'confirmed', 'cancelled', 'attended', 'not_attended']);

            $totalAppointments = (clone $appointmentsQuery)->count();
            $todayAppointments = (clone $appointmentsQuery)->whereDate('order_datetime', now()->toDateString())->count();
            $tomorrowAppointments = (clone $appointmentsQuery)->whereDate('order_datetime', now()->addDay()->toDateString())->count();

            $totalValue = (clone $appointmentsQuery)
                ->whereIn('appointment_status', ['confirmed', 'attended'])
                ->sum('total_price');

            $newAppointments = (clone $appointmentsQuery)
                ->where('created_at', '>', $lastCheck)
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get(['id', 'order_number', 'customer_name', 'order_datetime', 'appointment_status', 'total_price']);

            $updatedAppointments = (clone $appointmentsQuery)
                ->where('updated_at', '>', $lastCheck)
                ->where('created_at', '<', $lastCheck)
                ->orderBy('updated_at', 'desc')
                ->take(5)
                ->get(['id', 'order_number', 'customer_name', 'order_datetime', 'appointment_status', 'total_price']);

            $cancelledAppointments = (clone $appointmentsQuery)
                ->where('appointment_status', 'cancelled')
                ->where('updated_at', '>', $lastCheck)
                ->orderBy('updated_at', 'desc')
                ->take(5)
                ->get(['id', 'order_number', 'customer_name', 'order_datetime', 'appointment_status', 'total_price']);

            $nextAppointment = (clone $appointmentsQuery)
                ->whereIn('appointment_status', ['pending', 'confirmed'])
                ->where('order_datetime', '>=', now())
                ->orderBy('order_datetime', 'asc')
                ->first(['id', 'order_number', 'customer_name', 'order_datetime', 'appointment_status', 'total_price']);

            $lastAppointment = (clone $appointmentsQuery)
                ->where('order_datetime', '<', now())
                ->orderBy('order_datetime', 'desc')
                ->first(['id', 'order_number', 'customer_name', 'order_datetime', 'appointment_status', 'total_price']);

            $finalizableAppointments = (clone $appointmentsQuery)
                ->whereIn('appointment_status', ['confirmed'])
                ->with(['items'])
                ->get()
                ->filter(function ($appt) {
                    $duration = 0;
                    if ($appt->items && count($appt->items) > 0) {
                        foreach ($appt->items as $item) {
                            $duration += $item->duration ?? 0;
                        }
                    }
                    $duration = $duration > 0 ? $duration : 15;
                    $endTime = Carbon::parse($appt->order_datetime)->addMinutes($duration);
                    return now()->greaterThanOrEqualTo($endTime);
                })
                ->sortBy('order_datetime')
                ->values()
                ->map(function ($appt) {
                    return [
                        'id' => $appt->id,
                        'order_number' => $appt->order_number,
                        'customer_name' => $appt->customer_name,
                        'order_datetime' => $appt->order_datetime,
                        'appointment_status' => $appt->appointment_status,
                        'total_price' => $appt->total_price,
                    ];
                })
                ->take(1);

            $notifications = [];

            if ($newAppointments->isNotEmpty()) {
                foreach ($newAppointments as $appt) {
                    $notifications[] = [
                        'type' => 'new',
                        'message' => "Novo agendamento de {$appt->customer_name} para " . Carbon::parse($appt->order_datetime)->format('d/m H:i'),
                    ];
                }
            }

            if ($updatedAppointments->isNotEmpty()) {
                foreach ($updatedAppointments as $appt) {
                    $notifications[] = [
                        'type' => 'update',
                        'message' => "Agendamento de {$appt->customer_name} foi atualizado. Status: {$appt->appointment_status}.",
                    ];
                }
            }

            if ($cancelledAppointments->isNotEmpty()) {
                foreach ($cancelledAppointments as $appt) {
                    $notifications[] = [
                        'type' => 'cancel',
                        'message' => "Agendamento de {$appt->customer_name} foi cancelado.",
                    ];
                }
            }

            if ($finalizableAppointments->isNotEmpty()) {
                $appt = $finalizableAppointments->first();
                $notifications[] = [
                    'type' => 'finalize',
                    'message' => "O atendimento de {$appt['customer_name']} está finalizado. Marque como atendido ou não atendido.",
                ];
            }

            return $this->jsonUtf8([
                'checked_at' => now()->toDateTimeString(),
                'kpis' => [
                    'total' => $totalAppointments,
                    'today' => $todayAppointments,
                    'tomorrow' => $tomorrowAppointments,
                    'value' => $totalValue,
                ],
                'new_appointments' => $newAppointments,
                'updated_appointments' => $updatedAppointments,
                'cancelled_appointments' => $cancelledAppointments,
                'next_appointment' => $nextAppointment,
                'last_appointment' => $lastAppointment,
                'finalizable_appointment' => $finalizableAppointments->first(),
                'notifications' => $notifications,
            ], 200);

        } catch (ValidationException $e) {
            return $this->jsonUtf8([
                'message' => 'Erro de validação nos dados enviados.',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {
            Log::error('Erro ao verificar atualizações do colaborador.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->jsonUtf8([
                'error' => 'Falha ao verificar atualizações.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function listAppointments(Request $request)
    {
        try {
            if (!Auth::check()) {
                return $this->jsonUtf8(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();

            $data = $request->validate([
                'employer_id' => 'nullable|integer|exists:employers,id',
            ], [
                'employer_id.integer' => 'O campo employer_id deve ser um número inteiro.',
                'employer_id.exists' => 'O colaborador informado não existe.',
            ]);

            $employer = isset($data['employer_id'])
                ? Employer::find($data['employer_id'])
                : Employer::where('user_id', $user->id)->first();

            if (!$employer) {
                return $this->jsonUtf8(['error' => 'Colaborador não encontrado.'], 404);
            }

            $appointments = \App\Models\Order::with([
                'items.item:id,name,price,duration',
                'items.modifiers.modifier:id,name,type',
                'client:id,first_name,last_name,email,phone',
                'attendant.user:id,first_name,last_name,email'
            ])
                ->where('type', 'appointment')
                ->where('attendant_id', $employer->id)
                ->whereIn('appointment_status', [
                    'pending',
                    'confirmed',
                    'attended',
                    'not_attended',
                    'cancelled'
                ])
                ->orderBy('order_datetime', 'desc')
                ->get();

            foreach ($appointments as $order) {
                if (!$order->total_price || (float) $order->total_price == 0.0) {
                    $order->total_price = $order->items->sum(function ($item) {
                        return ($item->unit_price ?? $item->item->price ?? 0) * ($item->quantity ?? 1);
                    });
                }

                $order->services = $order->items->map(function ($item) {
                    return [
                        'name' => $item->item->name ?? 'Serviço não identificado',
                        'price' => $item->unit_price ?? $item->item->price ?? 0,
                        'quantity' => $item->quantity ?? 1,
                        'subtotal' => ($item->unit_price ?? $item->item->price ?? 0) * ($item->quantity ?? 1),
                        'duration' => $item->item->duration ?? 0,
                        'modifiers' => $item->modifiers->map(function ($mod) {
                            return [
                                'name' => $mod->modifier->name ?? '',
                                'type' => $mod->type ?? ''
                            ];
                        }),
                    ];
                });
            }

            return $this->jsonUtf8([
                'appointments' => $appointments,
                'count' => $appointments->count(),
            ], 200);

        } catch (ValidationException $e) {
            return $this->jsonUtf8([
                'message' => 'Erro de validação nos dados enviados.',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {
            Log::error('Erro ao listar agendamentos do colaborador.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->jsonUtf8([
                'error' => 'Falha ao listar agendamentos.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function listSchedules(Request $request)
    {
        try {
            if (!Auth::check()) {
                return $this->jsonUtf8(['error' => 'Usuário não autenticado.'], 401);
            }

            $data = $request->validate([
                'employer_id' => 'required|integer|exists:employers,id',
            ], $this->getScheduleValidationMessages());

            $schedules = \App\Models\EmployerSchedule::where('employer_id', $data['employer_id'])
                ->orderByRaw("FIELD(day_of_week, 'monday','tuesday','wednesday','thursday','friday','saturday','sunday')")
                ->orderBy('start_time')
                ->get();

            return $this->jsonUtf8($schedules, 200);
        } catch (ValidationException $e) {
            return $this->jsonUtf8(['errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            Log::error('Employer.listSchedules error', ['exception' => $e]);
            return $this->jsonUtf8(['error' => 'Erro ao listar horários.'], 500);
        }
    }

    public function saveSchedules(Request $request)
    {
        try {
            if (!Auth::check()) {
                return $this->jsonUtf8(['error' => 'Usuário não autenticado.'], 401);
            }

            $data = $request->validate([
                'employer_id' => 'required|integer|exists:employers,id',
                'schedules' => 'nullable|array',
                'schedules.*.day_of_week' => 'required_with:schedules|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
                'schedules.*.start_time' => 'required_with:schedules|date_format:H:i',
                'schedules.*.end_time' => 'required_with:schedules|date_format:H:i|after:schedules.*.start_time',
            ], $this->getScheduleValidationMessages());

            $employerId = (int) $data['employer_id'];
            $schedules = $data['schedules'] ?? [];

            \App\Models\EmployerSchedule::where('employer_id', $employerId)
                ->where('type', 'work')
                ->delete();

            if (empty($schedules)) {
                return $this->jsonUtf8(['message' => 'Horários removidos com sucesso.'], 200);
            }

            foreach ($schedules as $schedule) {
                if (strtotime($schedule['end_time']) <= strtotime($schedule['start_time'])) {
                    return $this->jsonUtf8([
                        'errors' => [
                            'schedules' => ['O horário de término deve ser posterior ao horário de início.']
                        ]
                    ], 422);
                }

                \App\Models\EmployerSchedule::create([
                    'employer_id' => $employerId,
                    'day_of_week' => $schedule['day_of_week'],
                    'start_time' => $schedule['start_time'],
                    'end_time' => $schedule['end_time'],
                    'is_active' => true,
                    'type' => 'work',
                ]);
            }

            return $this->jsonUtf8(['message' => 'Horários salvos com sucesso.'], 201);

        } catch (ValidationException $e) {
            return $this->jsonUtf8(['errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            Log::error('Employer.saveSchedules error', ['exception' => $e]);
            return $this->jsonUtf8(['error' => 'Erro ao salvar horários.'], 500);
        }
    }

    public function deleteSchedule($id)
    {
        try {
            if (!Auth::check()) {
                return $this->jsonUtf8(['error' => 'Usuário não autenticado.'], 401);
            }

            $schedule = \App\Models\EmployerSchedule::findOrFail($id);
            $schedule->delete();

            return $this->jsonUtf8(['message' => 'Horário removido com sucesso.'], 200);
        } catch (\Throwable $e) {
            Log::error('Employer.deleteSchedule error', ['exception' => $e]);
            return $this->jsonUtf8(['error' => 'Erro ao remover horário.'], 500);
        }
    }

    public function availableTimes(Request $request)
    {
        try {
            $data = $request->validate([
                'employer_id' => 'required|integer|exists:employers,id',
                'date' => 'required',
                'duration' => 'required|integer|min:5',
            ]);

            $employerId = (int) $data['employer_id'];
            $duration = (int) $data['duration'];

            $now = Carbon::now('America/Sao_Paulo');

            $raw = (string) $data['date'];
            $dateStr = preg_replace('/T.*/', '', $raw);
            $date = Carbon::createFromFormat('Y-m-d', $dateStr, 'America/Sao_Paulo');
            $dayOfWeek = strtolower($date->format('l'));

            if ($date->lt($now->copy()->startOfDay())) {
                return $this->jsonUtf8(['available_times' => []], 200);
            }

            $isHoliday = \App\Models\EmployerSchedule::where('employer_id', $employerId)
                ->where('type', 'holiday')
                ->whereDate('reserved_date', $date->toDateString())
                ->exists();

            if ($isHoliday) {
                return $this->jsonUtf8(['available_times' => []], 200);
            }

            $schedules = \App\Models\EmployerSchedule::where('employer_id', $employerId)
                ->where('day_of_week', $dayOfWeek)
                ->where('is_active', true)
                ->where('type', 'work')
                ->get();

            if ($schedules->isEmpty()) {
                return $this->jsonUtf8(['available_times' => []], 200);
            }

            $appointments = \App\Models\Order::where('attendant_id', $employerId)
                ->where('type', 'appointment')
                ->whereBetween('order_datetime', [
                    $date->copy()->startOfDay()->setTimezone('UTC'),
                    $date->copy()->endOfDay()->setTimezone('UTC'),
                ])
                ->whereIn('appointment_status', ['pending', 'confirmed'])
                ->get(['order_datetime', 'total_duration']);

            $occupied = [];
            foreach ($appointments as $a) {
                $start = Carbon::parse($a->order_datetime)->setTimezone('America/Sao_Paulo');
                $end = $start->copy()->addMinutes($a->total_duration ?? 30);
                $occupied[] = [$start, $end];
            }

            $breaks = \App\Models\EmployerSchedule::where('employer_id', $employerId)
                ->where('type', 'break')
                ->whereDate('reserved_date', $date->toDateString())
                ->get();

            foreach ($breaks as $b) {
                $start = Carbon::parse("{$date->toDateString()} {$b->start_time}", 'America/Sao_Paulo');
                $end = Carbon::parse("{$date->toDateString()} {$b->end_time}", 'America/Sao_Paulo');
                $occupied[] = [$start, $end];
            }

            usort($occupied, fn($a, $b) => $a[0]->lt($b[0]) ? -1 : 1);

            $availableTimes = [];
            $step = 15;
            $limitFuture = $now->copy()->addMinutes(30);

            foreach ($schedules as $schedule) {
                $workStart = Carbon::parse("{$date->toDateString()} {$schedule->start_time}", 'America/Sao_Paulo');
                $workEnd = Carbon::parse("{$date->toDateString()} {$schedule->end_time}", 'America/Sao_Paulo');

                $pointer = $workStart->copy();

                while ($pointer->copy()->addMinutes($duration)->lte($workEnd)) {
                    $slotStart = $pointer->copy();
                    $slotEnd = $slotStart->copy()->addMinutes($duration);

                    if ($date->isSameDay($now) && $slotStart->lte($limitFuture)) {
                        $pointer->addMinutes($step);
                        continue;
                    }

                    $hasConflict = false;
                    foreach ($occupied as [$occStart, $occEnd]) {
                        if ($slotStart->lt($occEnd) && $slotEnd->gt($occStart)) {
                            $hasConflict = true;
                            break;
                        }
                    }

                    if (!$hasConflict) {
                        $availableTimes[] = $slotStart->format('H:i');
                    }

                    $pointer->addMinutes($step);
                }
            }

            sort($availableTimes);
            return $this->jsonUtf8(['available_times' => $availableTimes], 200);

        } catch (ValidationException $e) {
            return $this->jsonUtf8(['errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            Log::error('? Erro em availableTimes', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->jsonUtf8(['error' => 'Erro ao listar horários disponíveis.'], 500);
        }
    }

    public function reserveSchedule(Request $request)
    {
        try {
            if (!Auth::check()) {
                return $this->jsonUtf8(['error' => 'Usuário não autenticado.'], 401);
            }

            $data = $request->validate([
                'employer_id' => 'required|integer|exists:employers,id',
                'date' => 'required|date',
                'type' => 'required|in:break,holiday',
                'start_time' => 'nullable|date_format:H:i|required_if:type,break',
                'end_time' => 'nullable|date_format:H:i|after:start_time|required_if:type,break',
            ], [
                'employer_id.required' => 'O campo employer_id é obrigatório.',
                'date.required' => 'O campo data é obrigatório.',
                'type.required' => 'O campo tipo é obrigatório.',
                'type.in' => 'O tipo deve ser break (pausa) ou holiday (feriado).',
                'start_time.required_if' => 'O campo horário de início é obrigatório para pausas.',
                'end_time.required_if' => 'O campo horário de término é obrigatório para pausas.',
            ]);

            $dayOfWeek = strtolower(Carbon::parse($data['date'])->format('l'));

            \App\Models\EmployerSchedule::create([
                'employer_id' => $data['employer_id'],
                'day_of_week' => $dayOfWeek,
                'reserved_date' => $data['date'],
                'start_time' => $data['start_time'] ?? '00:00',
                'end_time' => $data['end_time'] ?? '23:59',
                'is_active' => false,
                'type' => $data['type'],
            ]);

            return $this->jsonUtf8(['message' => 'Horário reservado com sucesso.'], 201);

        } catch (ValidationException $e) {
            return $this->jsonUtf8(['errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            Log::error('Employer.reserveSchedule error', ['exception' => $e]);
            return $this->jsonUtf8(['error' => 'Erro ao reservar horário.'], 500);
        }
    }

    public function view($user_name)
    {
        $authUser = Auth::user();

        $employer = Employer::with([
            'user:id,first_name,last_name,user_name,phone,avatar,about,email,city,uf',
            'establishment.items:id,entity_id,name,slug,price,type,image',
            'establishment.interactions.user:id,first_name,last_name,user_name,avatar,email',
            'establishment.orders.client:id,first_name,last_name,user_name,avatar,email',
            'orders.client:id,first_name,last_name,user_name,avatar,email',
            'interactions.user:id,first_name,last_name,user_name,avatar,email',
            'files' => fn($q) => $q->where('entity_name', 'employer'),
        ])
            ->whereHas('user', fn($q) => $q->where('user_name', $user_name))
            ->firstOrFail();

        try {
            if (method_exists($employer, 'refreshViewMetrics')) {
                $employer->refreshViewMetrics($authUser);
            }
        } catch (\Throwable $e) {
            Log::warning('Employer.view refreshViewMetrics failed', ['error' => $e->getMessage()]);
        }

        $u = $employer->user;

        $avatar = $employer->files->firstWhere('type', 'avatar')?->public_url ?? ($u->avatar ?? null);
        $gallery = $employer->files->whereNotIn('type', ['avatar'])->pluck('public_url')->values();

        return $this->jsonUtf8([
            'employer' => [
                'id' => $employer->id,
                'type' => 'employer',
                'name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
                'slug' => $u->user_name,
                'about' => $u->about,
                'city' => $u->city,
                'uf' => $u->uf,
                'images' => [
                    'avatar' => $avatar,
                    'gallery' => $gallery
                ]
            ],
            'establishment' => $employer->establishment,
            'items' => $employer->establishment?->items ?? [],
            'metrics' => $employer->metrics ?? null,
            'interaction_summary' => method_exists($employer, 'interactionSummary') ? $employer->interactionSummary() : null,
            'user_interactions' => method_exists($employer, 'userInteractions') ? $employer->userInteractions() : null,
            'orders_summary' => method_exists($employer, 'ordersSummary') ? $employer->ordersSummary() : null,
            'colleagues' => method_exists($employer, 'colleagues') ? ($employer->colleagues()['list'] ?? []) : [],
            'average_engagement_score' => method_exists($employer, 'colleagues') ? ($employer->colleagues()['average_engagement_score'] ?? 0) : 0,
            'top_item_and_client' => method_exists($employer, 'topItemAndClient') ? $employer->topItemAndClient() : null,
            'other_establishments' => $employer->establishment && method_exists($employer->establishment, 'otherEstablishments') ? ($employer->establishment->otherEstablishments() ?? []) : [],
            'other_employers' => $employer->establishment && method_exists($employer->establishment, 'otherEmployers') ? ($employer->establishment->otherEmployers() ?? []) : [],
            'other_items' => $employer->establishment && method_exists($employer->establishment, 'otherItems') ? ($employer->establishment->otherItems() ?? []) : [],
        ]);
    }

    public function home(Request $request, $app_id)
    {
        $city = $request->query('city');
        $uf = $request->query('uf');

        $establishmentIds = Establishment::where('app_id', $app_id)
            ->when($city && $uf, fn($q) => $q->where('city', $city)->where('uf', $uf))
            ->pluck('id');

        $employers = Employer::whereIn('establishment_id', $establishmentIds)
            ->with([
                'user:id,first_name,last_name,user_name,avatar,email,city,uf',
                'establishment:id,name,slug,city,uf',
                'files' => fn($q) => $q->where('entity_name', 'employer'),
            ])
            ->withCount([
                'views as total_views' => fn($q) => $q->where('interaction_type', 'view'),
                'views as unique_users' => fn($q) => $q->select(DB::raw('COUNT(DISTINCT user_id)'))->where('interaction_type', 'view'),
                'orders as completed_appointments' => fn($q) => $q->whereIn('appointment_status', ['confirmed', 'attended']),
            ])
            ->orderByDesc('completed_appointments')
            ->get()
            ->map(function ($emp) {
                $u = $emp->user;

                $avatar = $emp->files->firstWhere('type', 'avatar')?->public_url ?? ($u->avatar ?? null);
                $gallery = $emp->files->whereNotIn('type', ['avatar'])->pluck('public_url')->values();

                return [
                    'id' => $emp->id,
                    'type' => 'employer',
                    'name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
                    'slug' => $u->user_name,
                    'images' => [
                        'avatar' => $avatar,
                        'gallery' => $gallery,
                    ],
                    'city' => $emp->establishment?->city,
                    'uf' => $emp->establishment?->uf,
                    'total_views' => $emp->total_views,
                    'unique_users' => $emp->unique_users,
                    'total_completed_appointments' => $emp->completed_appointments,
                    'establishment' => [
                        'name' => $emp->establishment?->name,
                        'slug' => $emp->establishment?->slug,
                    ],
                ];
            });

        return $this->jsonUtf8(['employers' => $employers], 200);
    }


    public function listMyOrders(Request $request)
{
    try {
        if (!Auth::check()) {
            return $this->jsonUtf8(['error' => 'Usuário não autenticado.'], 401);
        }

        $user = Auth::user();

        $employer = Employer::where('user_id', $user->id)->first();

        if (!$employer) {
            return $this->jsonUtf8([
                'error' => 'Colaborador não encontrado ou não vinculado.'
            ], 404);
        }

        $orders = \App\Models\Order::with([
            'items.item:id,name,type,price,duration',
            'items.modifiers.modifier:id,name,type',
            'client:id,first_name,last_name,user_name,avatar,email',
        ])
            ->where('attendant_id', $employer->id)
            ->orderByDesc('order_datetime')
            ->get();

        $orders = $orders->map(function ($order) {

            $start = $order->order_datetime;
            $end = $order->type === 'appointment' && $order->total_duration
                ? $start->copy()->addMinutes($order->total_duration)
                : null;

            $items = $order->items->map(function ($oi) {
                $price = $oi->unit_price ?? $oi->item?->price ?? 0;
                $qty = $oi->quantity ?? 1;

                return [
                    'id' => $oi->item_id,
                    'name' => $oi->item?->name,
                    'type' => $oi->item?->type,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'subtotal' => $price * $qty,
                    'duration' => $oi->item?->duration ?? 0,
                    'modifiers' => $oi->modifiers->map(function ($m) {
                        return [
                            'name' => $m->modifier?->name,
                            'type' => $m->type,
                        ];
                    })->values(),
                ];
            })->values();

            $totalPrice = $order->total_price && $order->total_price > 0
                ? $order->total_price
                : $items->sum('subtotal');

            return [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'type' => $order->type,
                'appointment_status' => $order->appointment_status,
                'payment_status' => $order->payment_status,

                'scheduled_start' => $start?->timezone('America/Sao_Paulo')->format('Y-m-d H:i'),
                'scheduled_end' => $end?->timezone('America/Sao_Paulo')->format('Y-m-d H:i'),

                'customer' => [
                    'id' => $order->client?->id,
                    'name' => $order->customer_name
                        ?? trim(($order->client?->first_name ?? '') . ' ' . ($order->client?->last_name ?? '')),
                    'user_name' => $order->client?->user_name,
                    'avatar' => $order->client?->avatar,
                    'profile_link' => $order->client?->user_name
                        ? url("/user/{$order->client->user_name}")
                        : null,
                ],

                'items' => $items,
                'total_items' => $items->sum('quantity'),
                'total_duration' => $order->total_duration,
                'total_price' => $totalPrice,

                'created_at' => $order->created_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
                'notes' => $order->notes,
            ];
        });

        return $this->jsonUtf8([
            'message' => 'Pedidos do colaborador listados com sucesso.',
            'employer' => [
                'id' => $employer->id,
                'user_id' => $employer->user_id,
                'role' => $employer->role,
            ],
            'orders' => $orders,
            'count' => $orders->count(),
        ], 200);

    } catch (\Throwable $e) {
        Log::error('Employer.listMyOrders error', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return $this->jsonUtf8([
            'error' => 'Erro ao listar os pedidos do colaborador.',
            'details' => $e->getMessage(),
        ], 500);
    }
}
public function listByEntity(string $identifier)
{
    try {
        $establishment = Establishment::query()
            ->when(
                is_numeric($identifier),
                fn ($q) => $q->where('id', (int) $identifier),
                fn ($q) => $q->where('slug', $identifier)
            )
            ->with([
                'employers.user.files' => fn ($q) =>
                    $q->where('entity_name', 'user'),
            ])
            ->first();

        if (!$establishment) {
            return $this->jsonUtf8([
                'error' => 'Estabelecimento não encontrado.',
            ], 404);
        }

        $employers = $establishment->employers
            ->filter(fn ($emp) => $emp->user)
            ->map(function ($emp) {
                $user = $emp->user;

                $avatar =
                    $user->files->firstWhere('type', 'avatar')?->public_url
                    ?? $user->avatar
                    ?? null;

                $gallery = $user->files
                    ->whereNotIn('type', ['avatar'])
                    ->pluck('public_url')
                    ->values();

                return [
                    'id' => $emp->id,
                    'role' => $emp->role,
                    'permissions' => $emp->permissions,
                    'status' => $emp->status,
                    'metrics' => $emp->metrics,

                    'user' => [
                        'id' => $user->id,
                        'user_name' => $user->user_name,
                        'first_name' => $user->first_name,
                        'last_name' => $user->last_name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'avatar' => $avatar,
                    ],

                    'images' => [
                        'avatar' => $avatar,
                        'gallery' => $gallery,
                    ],
                ];
            })
            ->values();

        return $this->jsonUtf8([
            'message' => 'Colaboradores listados com sucesso.',
            'establishment' => [
                'id' => $establishment->id,
                'name' => $establishment->name,
                'fantasy' => $establishment->fantasy,
                'slug' => $establishment->slug,
                'city' => $establishment->city,
                'uf' => $establishment->uf,
            ],
            'total' => $employers->count(),
            'employers' => $employers,
        ], 200);

    } catch (\Throwable $e) {
        Log::error('Employer.listByEntity error', [
            'identifier' => $identifier,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return $this->jsonUtf8([
            'error' => 'Erro ao listar colaboradores do estabelecimento.',
            'details' => $e->getMessage(),
        ], 500);
    }
}


}