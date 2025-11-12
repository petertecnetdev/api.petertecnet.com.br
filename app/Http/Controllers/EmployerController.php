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
use Illuminate\Support\Facades\Cache;

class EmployerController extends Controller
{
    protected function getScheduleValidationMessages()
    {
        return [
            'employer_id.required' => 'O campo employer_id é obrigatório.',
            'employer_id.integer' => 'O campo employer_id deve ser um número inteiro.',
            'employer_id.exists' => 'O colaborador informado não existe.',

            'schedules.required' => 'A lista de horários é obrigatória.',
            'schedules.array' => 'Os horários devem ser enviados em formato de lista.',
            'schedules.min' => 'É necessário informar pelo menos um horário.',

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
            Log::info('Employer.store start', ['user_id' => Auth::id(), 'payload' => $request->all()]);

            if (!Auth::check()) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();

            $validatedData = $request->validate([
                'first_name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'establishment_id' => 'required|integer|exists:establishments,id',
                'link' => 'required|url',
                'role' => 'required|string|max:255',
                'permissions' => 'required|array',
            ], $this->getValidationMessages());

            $establishment = Establishment::find($validatedData['establishment_id']);
            if (!$establishment) {
                return response()->json([
                    'error' => 'O estabelecimento informado não existe ou foi removido.'
                ], 404);
            }

            // ✅ Correção da verificação do dono do estabelecimento
            if ($establishment->user_id !== $user->id) {
                return response()->json([
                    'error' => 'Apenas o dono do estabelecimento pode adicionar novos colaboradores.'
                ], 403);
            }

            $existingUser = User::where('email', $validatedData['email'])->first();

            if (
                $existingUser && Employer::where('user_id', $existingUser->id)
                    ->where('establishment_id', $establishment->id)
                    ->exists()
            ) {
                return response()->json([
                    'error' => 'Este usuário já está vinculado a este estabelecimento.'
                ], 409);
            }

            if (!$existingUser) {
                $username = Str::slug($validatedData['first_name']) . '-' . Str::random(4);
                while (User::where('user_name', $username)->exists()) {
                    $username = Str::slug($validatedData['first_name']) . '-' . Str::random(4);
                }

                $password = Str::random(10);

                $newUser = User::create([
                    'first_name' => $validatedData['first_name'],
                    'name' => $validatedData['first_name'],
                    'email' => $validatedData['email'],
                    'user_name' => $username,
                    'password' => Hash::make($password),
                ]);

                $employer = Employer::create([
                    'user_id' => $newUser->id,
                    'establishment_id' => $establishment->id,
                    'role' => $validatedData['role'],
                    'permissions' => $validatedData['permissions'],
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);

                $createCode = Str::random(8);
                $newUser->reset_password_code = $createCode;
                $newUser->reset_password_expires_at = now()->addMinutes(10);
                $newUser->save();

                Mail::to($newUser->email)->send(new CreatePasswordMail($createCode, $newUser, $validatedData['link']));
                Mail::to($newUser->email)->send(new NewEmployerCollaborator($establishment, $employer));

                if ($establishment->user_id && $establishment->user) {
                    Mail::to($establishment->user->email)->send(new OwnerNotifiedNewCollaborator($establishment, $employer));
                }

                $message = 'Novo colaborador criado com sucesso. Um e-mail foi enviado para o colaborador finalizar o cadastro.';
            } else {
                $employer = Employer::create([
                    'user_id' => $existingUser->id,
                    'establishment_id' => $establishment->id,
                    'role' => $validatedData['role'],
                    'permissions' => $validatedData['permissions'],
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);

                Mail::to($existingUser->email)->send(new NewEmployerCollaborator($establishment, $employer));

                if ($establishment->user_id && $establishment->user) {
                    Mail::to($establishment->user->email)->send(new OwnerNotifiedNewCollaborator($establishment, $employer));
                }

                $message = 'Usuário já existente vinculado como colaborador com sucesso.';
            }

            Log::info('Employer.store success', ['employer_id' => $employer->id]);

            return response()->json([
                'message' => $message,
                'employer' => $employer,
            ], 201);

        } catch (ValidationException $e) {
            Log::warning('Employer.store validation failed', ['errors' => $e->errors()]);
            return response()->json([
                'message' => 'Erro de validação nos dados enviados.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Employer.store failed', ['error' => $e->getMessage(), 'stack' => $e->getTraceAsString()]);
            return response()->json([
                'error' => 'Ocorreu um erro inesperado ao adicionar o colaborador.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function listByEstablishment(Request $request)
    {
        try {
            Log::info('Employer.list start', [
                'user_id' => Auth::id(),
                'payload' => $request->all()
            ]);

            if (!Auth::check()) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $validatedData = $request->validate([
                'establishment_id' => 'required|integer|exists:establishments,id',
            ], [
                'establishment_id.required' => 'O ID do estabelecimento é obrigatório.',
                'establishment_id.integer' => 'O ID do estabelecimento deve ser um número inteiro válido.',
                'establishment_id.exists' => 'O estabelecimento informado não existe.',
            ]);

            $user = Auth::user();
            $establishment = Establishment::with('user')->find($validatedData['establishment_id']);

            if (!$establishment) {
                return response()->json([
                    'error' => 'O estabelecimento informado não existe ou foi removido.'
                ], 404);
            }

            if ($establishment->user_id !== $user->id) {
                return response()->json([
                    'error' => 'Apenas o dono do estabelecimento pode visualizar a lista de colaboradores.'
                ], 403);
            }

            $employers = Employer::with(['user:id,first_name,email,user_name', 'creator:id,first_name,email'])
                ->where('establishment_id', $establishment->id)
                ->orderByDesc('created_at')
                ->get();

            if ($employers->isEmpty()) {
                return response()->json([
                    'message' => 'Nenhum colaborador encontrado para este estabelecimento.'
                ], 200);
            }

            Log::info('Employer.list success', [
                'establishment_id' => $establishment->id,
                'count' => $employers->count()
            ]);

            return response()->json([
                'message' => 'Lista de colaboradores carregada com sucesso.',
                'establishment' => [
                    'id' => $establishment->id,
                    'name' => $establishment->name,
                ],
                'employers' => $employers
            ], 200);

        } catch (ValidationException $e) {
            Log::warning('Employer.list validation failed', ['errors' => $e->errors()]);
            return response()->json([
                'message' => 'Erro de validação nos dados enviados.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Employer.list failed', [
                'error' => $e->getMessage(),
                'stack' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Ocorreu um erro inesperado ao listar os colaboradores.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }


    public function detach(Request $request)
    {
        try {
            Log::info('Employer.detach start', [
                'user_id' => Auth::id(),
                'payload' => $request->all()
            ]);

            if (!Auth::check()) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
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
                return response()->json([
                    'error' => 'O estabelecimento informado não existe.'
                ], 404);
            }

            // Apenas o dono do estabelecimento pode desvincular
            if ($establishment->user_id !== $user->id) {
                return response()->json([
                    'error' => 'Apenas o dono do estabelecimento pode desvincular colaboradores.'
                ], 403);
            }

            $employer = Employer::with('user')
                ->where('id', $validatedData['employer_id'])
                ->where('establishment_id', $establishment->id)
                ->first();

            if (!$employer) {
                // Caso a validação 'exists' falhe por causa do establishment_id, este erro é mais específico
                return response()->json([
                    'error' => 'O colaborador não está vinculado a este estabelecimento.'
                ], 404);
            }

            $collaboratorUser = $employer->user;
            $ownerUser = $establishment->user;

            // Desvincula (deleta o registro Employer)
            $employer->delete();

            // Envio de e-mail ao colaborador desvinculado
            if ($collaboratorUser && !empty($collaboratorUser->email)) {
                try {
                    // É importante garantir que esta classe de Mail está sendo importada corretamente.
                    // No cabeçalho do seu controller, está: use App\Mail\{..., EmployerRemoved, ...};
                    Mail::to($collaboratorUser->email)
                        ->send(new \App\Mail\EmployerRemoved($establishment, $collaboratorUser));
                } catch (\Exception $e) {
                    Log::warning('Failed to send EmployerRemoved email', [
                        'error' => $e->getMessage(),
                        'employer_id' => $validatedData['employer_id']
                    ]);
                }
            }

            // Envio de e-mail ao dono do estabelecimento (proprietário)
            if ($ownerUser && !empty($ownerUser->email)) {
                try {
                    // É importante garantir que esta classe de Mail está sendo importada corretamente.
                    // No cabeçalho do seu controller, está: use App\Mail\{..., OwnerNotifiedEmployerDetached};
                    Mail::to($ownerUser->email)
                        ->send(new \App\Mail\OwnerNotifiedEmployerDetached($establishment, $collaboratorUser ?? null));
                } catch (\Exception $e) {
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

            return response()->json([
                'message' => 'Colaborador desvinculado com sucesso e notificações enviadas.'
            ], 200);

        } catch (ValidationException $e) {
            // Bloco de tratamento de erro de validação (para evitar o erro de UTF-8)
            Log::warning('Employer.detach validation failed', ['errors' => $e->errors()]);

            // Mapeia e sanitiza as mensagens de erro para garantir o UTF-8 correto no JSON
            $sanitizedErrors = array_map(function ($messages) {
                return array_map(function ($message) {
                    // Garante que o string é UTF-8 válido (útil contra o erro que você viu)
                    return mb_convert_encoding($message, 'UTF-8', 'UTF-8');
                }, (array) $messages); // Garante que $messages é um array para o loop
            }, $e->errors());

            return response()->json([
                'message' => 'Erro  nos dados enviados.',
                'errors' => $sanitizedErrors
            ], 422);

        } catch (\Exception $e) {
            Log::error('Employer.detach failed', [
                'error' => $e->getMessage(),
                'stack' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Ocorreu um erro inesperado ao desvincular o colaborador.',
                'details' => $e->getMessage()
            ], 500);
        }
    }
    public function checkUpdates(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
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

            $employer = \App\Models\Employer::with('establishment')->find($data['employer_id']);
            if (!$employer) {
                return response()->json(['error' => 'Colaborador não encontrado.'], 404);
            }

            $isOwner = \App\Models\Establishment::where('user_id', $user->id)
                ->where('id', $employer->establishment_id)
                ->exists();

            $isSelf = $user->id === $employer->user_id;

            if (!$isOwner && !$isSelf) {
                return response()->json(['error' => 'Acesso negado.'], 403);
            }

            $lastCheck = isset($data['last_check'])
                ? \Carbon\Carbon::parse($data['last_check'])
                : now()->subMinutes(10);

            $appointmentsQuery = \App\Models\Order::where('attendant_id', $employer->id)
                ->where('type', 'appointment')
                ->whereIn('appointment_status', ['pending', 'confirmed', 'cancelled', 'attended', 'not_attended']);

            $totalAppointments = (clone $appointmentsQuery)->count();
            $todayAppointments = (clone $appointmentsQuery)
                ->whereDate('order_datetime', now()->toDateString())
                ->count();
            $tomorrowAppointments = (clone $appointmentsQuery)
                ->whereDate('order_datetime', now()->addDay()->toDateString())
                ->count();
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

            // --- Identifica atendimentos finalizados que precisam ser marcados como atendidos ou não ---
            $finalizableAppointments = (clone $appointmentsQuery)
                ->whereIn('appointment_status', ['confirmed'])
                ->get()
                ->filter(function ($appt) {
                    $duration = 0;
                    if ($appt->items && count($appt->items) > 0) {
                        foreach ($appt->items as $item) {
                            $duration += $item->duration ?? 0;
                        }
                    }
                    $duration = $duration > 0 ? $duration : 15;
                    $endTime = \Carbon\Carbon::parse($appt->order_datetime)->addMinutes($duration);
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
                        'message' => "Novo agendamento de {$appt->customer_name} para " .
                            \Carbon\Carbon::parse($appt->order_datetime)->format('d/m H:i'),
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
                foreach ($finalizableAppointments as $appt) {
                    $notifications[] = [
                        'type' => 'finalize',
                        'message' => "O atendimento de {$appt['customer_name']} está finalizado. Marque como atendido ou não atendido.",
                    ];
                }
            }

            return response()->json([
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

        } catch (\Exception $e) {
            \Log::error('Erro ao verificar atualizações do colaborador.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Falha ao verificar atualizações.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function listAppointments(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();

            $data = $request->validate([
                'employer_id' => 'nullable|integer|exists:employers,id',
            ], [
                'employer_id.integer' => 'O campo employer_id deve ser um número inteiro.',
                'employer_id.exists' => 'O colaborador informado não existe.',
            ]);

            $employer = isset($data['employer_id'])
                ? \App\Models\Employer::find($data['employer_id'])
                : \App\Models\Employer::where('user_id', $user->id)->first();

            if (!$employer) {
                return response()->json(['error' => 'Colaborador não encontrado.'], 404);
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

            // 🔹 Garante cálculo do total e estrutura dos serviços solicitados
            foreach ($appointments as $order) {
                if (!$order->total_price || $order->total_price == 0) {
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

            return response()->json([
                'appointments' => $appointments,
                'count' => $appointments->count(),
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Erro ao listar agendamentos do colaborador.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => 'Falha ao listar agendamentos.',
                'details' => $e->getMessage(),
            ], 500);
        }

    }

    public function view($user_name)
    {
        try {
            $authUser = Auth::user();

            $employer = Employer::with([
                'user:id,first_name,last_name,user_name,phone,avatar,about,email',
                'establishment.items:id,entity_id,name,slug,price,type',
                'establishment.orders.client:id,first_name,last_name,user_name,avatar,email',
                'establishment.interactions.user:id,first_name,last_name,user_name,avatar,email',
                'orders.client:id,first_name,last_name,user_name,avatar,email',
                'interactions.user:id,first_name,last_name,user_name,avatar,email',
            ])
                ->whereHas('user', fn($q) => $q->where('user_name', $user_name))
                ->firstOrFail();

            // Usa método da model para registrar view e limpar cache
            $employer->refreshViewMetrics($authUser);

            // Usa os métodos já existentes da model
            $metrics = $employer->metrics;
            $interactionSummary = $employer->interactionSummary();
            $colleaguesData = $employer->colleagues();
            $ordersSummary = $employer->ordersSummary();
            $userInteractions = $employer->userInteractions();
            $topItemAndClient = $employer->topItemAndClient();

            return response()->json([
                'employer' => $employer,
                'establishment' => $employer->establishment,
                'items' => $employer->establishment?->items ?? [],
                'metrics' => $metrics,
                'colleagues' => $colleaguesData['list'] ?? [],
                'average_engagement_score' => $colleaguesData['average_engagement_score'] ?? 0,
                'interaction_summary' => $interactionSummary,
                'user_interactions' => $userInteractions,
                'orders_summary' => $ordersSummary,
                'top_item_and_client' => $topItemAndClient,
                'other_establishments' => $employer->establishment?->otherEstablishments() ?? [],
                'other_employers' => $employer->establishment?->otherEmployers() ?? [],
                'other_items' => $employer->establishment?->otherItems() ?? [],
            ], 200);

        } catch (\Throwable $e) {
            \Log::error('[EmployerController::view] Erro ao carregar colaborador', [
                'user_name' => $user_name,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Erro ao carregar colaborador.'], 500);
        }
    }



    public function listSchedules(Request $request)
    {
        try {
            $data = $request->validate([
                'employer_id' => 'required|integer|exists:employers,id',
            ], $this->getScheduleValidationMessages());

            $schedules = \App\Models\EmployerSchedule::where('employer_id', $data['employer_id'])
                ->orderByRaw("FIELD(day_of_week, 'monday','tuesday','wednesday','thursday','friday','saturday','sunday')")
                ->orderBy('start_time')
                ->get();

            return response()->json($schedules, 200, [], JSON_UNESCAPED_UNICODE);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422, [], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            \Log::error('Employer.listSchedules error', ['exception' => $e]);
            return response()->json(['error' => 'Erro ao listar horários.'], 500, [], JSON_UNESCAPED_UNICODE);
        }
    }
    public function saveSchedules(Request $request)
    {
        try {
            $data = $request->validate([
                'employer_id' => 'required|integer|exists:employers,id',
                'schedules' => 'required|array|min:1',
                'schedules.*.day_of_week' => 'required|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
                'schedules.*.start_time' => 'required|date_format:H:i',
                'schedules.*.end_time' => 'required|date_format:H:i',
            ], $this->getScheduleValidationMessages());

            // 🕒 Validação manual: end_time deve ser maior que start_time
            foreach ($data['schedules'] as $schedule) {
                if (strtotime($schedule['end_time']) <= strtotime($schedule['start_time'])) {
                    return response()->json([
                        'errors' => [
                            'schedules' => ['O horário de término deve ser posterior ao horário de início.']
                        ]
                    ], 422, [], JSON_UNESCAPED_UNICODE);
                }
            }

            foreach ($data['schedules'] as $schedule) {
                \App\Models\EmployerSchedule::updateOrCreate(
                    [
                        'employer_id' => $data['employer_id'],
                        'day_of_week' => $schedule['day_of_week'],
                        'start_time' => $schedule['start_time'],
                        'end_time' => $schedule['end_time'],
                    ],
                    ['is_active' => true, 'type' => 'work']
                );
            }

            return response()->json(['message' => 'Horários cadastrados com sucesso.'], 201, [], JSON_UNESCAPED_UNICODE);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422, [], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            \Log::error('Employer.saveSchedules error', ['exception' => $e]);
            return response()->json(['error' => 'Erro ao salvar horários.'], 500, [], JSON_UNESCAPED_UNICODE);
        }
    }

    public function deleteSchedule($id)
    {
        try {
            $schedule = \App\Models\EmployerSchedule::findOrFail($id);
            $schedule->delete();

            return response()->json(['message' => 'Horário removido com sucesso.'], 200, [], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            \Log::error('Employer.deleteSchedule error', ['exception' => $e]);
            return response()->json(['error' => 'Erro ao remover horário.'], 500, [], JSON_UNESCAPED_UNICODE);
        }
    }
    public function availableTimes(Request $request)
    {
        try {
            $data = $request->validate([
                'employer_id' => 'required|integer|exists:employers,id',
                'date' => 'required', // pode vir com hora, será ignorada
                'duration' => 'required|integer|min:5',
            ]);

            $employerId = (int) $data['employer_id'];
            $duration = (int) $data['duration'];

            // 🕒 Horário atual verdadeiro (do servidor)
            $now = \Carbon\Carbon::now('America/Sao_Paulo');
            $today = $now->format('Y-m-d');

            // 🧹 Extrai apenas o dia e ignora completamente a hora enviada
            $raw = (string) $data['date'];
            $dateStr = preg_replace('/T.*/', '', $raw);
            $date = \Carbon\Carbon::createFromFormat('Y-m-d', $dateStr, 'America/Sao_Paulo');
            $dayOfWeek = strtolower($date->format('l'));

            // 🚫 Se o dia for passado, retorna vazio
            if ($date->lt($now->copy()->startOfDay())) {
                return response()->json(['available_times' => []]);
            }

            // 🔒 Folga ou feriado
            $isHoliday = \App\Models\EmployerSchedule::where('employer_id', $employerId)
                ->where('type', 'holiday')
                ->whereDate('reserved_date', $date->toDateString())
                ->exists();

            if ($isHoliday) {
                return response()->json(['available_times' => []]);
            }

            // 🗓️ Horários de expediente
            $schedules = \App\Models\EmployerSchedule::where('employer_id', $employerId)
                ->where('day_of_week', $dayOfWeek)
                ->where('is_active', true)
                ->where('type', 'work')
                ->get();

            if ($schedules->isEmpty()) {
                return response()->json(['available_times' => []]);
            }

            // 📋 Agendamentos do dia
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
                $start = \Carbon\Carbon::parse($a->order_datetime)->setTimezone('America/Sao_Paulo');
                $end = $start->copy()->addMinutes($a->total_duration ?? 30);
                $occupied[] = [$start, $end];
            }

            // ☕ Pausas
            $breaks = \App\Models\EmployerSchedule::where('employer_id', $employerId)
                ->where('type', 'break')
                ->whereDate('reserved_date', $date->toDateString())
                ->get();

            foreach ($breaks as $b) {
                $start = \Carbon\Carbon::parse("{$date->toDateString()} {$b->start_time}", 'America/Sao_Paulo');
                $end = \Carbon\Carbon::parse("{$date->toDateString()} {$b->end_time}", 'America/Sao_Paulo');
                $occupied[] = [$start, $end];
            }

            usort($occupied, fn($a, $b) => $a[0]->lt($b[0]) ? -1 : 1);

            // ⚙️ Geração de horários disponíveis
            $availableTimes = [];
            $step = 15;
            $limitFuture = $now->copy()->addMinutes(30); // tolerância mínima

            foreach ($schedules as $schedule) {
                $workStart = \Carbon\Carbon::parse("{$date->toDateString()} {$schedule->start_time}", 'America/Sao_Paulo');
                $workEnd = \Carbon\Carbon::parse("{$date->toDateString()} {$schedule->end_time}", 'America/Sao_Paulo');

                $pointer = $workStart->copy();

                while ($pointer->copy()->addMinutes($duration)->lte($workEnd)) {
                    $slotStart = $pointer->copy();
                    $slotEnd = $slotStart->copy()->addMinutes($duration);

                    // 🚫 Se o dia for hoje, só horários depois de agora + 30 min
                    if ($date->isSameDay($now) && $slotStart->lte($limitFuture)) {
                        $pointer->addMinutes($step);
                        continue;
                    }

                    // ⚠️ Verifica conflito
                    $hasConflict = false;
                    foreach ($occupied as [$occStart, $occEnd]) {
                        if ($slotStart->lt($occEnd) && $slotEnd->gt($occStart)) {
                            $hasConflict = true;
                            break;
                        }
                    }

                    // ✅ Adiciona se estiver livre
                    if (!$hasConflict) {
                        $availableTimes[] = $slotStart->format('H:i');
                    }

                    $pointer->addMinutes($step);
                }
            }

            sort($availableTimes);
            return response()->json(['available_times' => $availableTimes]);
        } catch (\Throwable $e) {
            \Log::error('❌ Erro em availableTimes', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);
            return response()->json(['error' => 'Erro ao listar horários disponíveis.'], 500);
        }
    }



    public function reserveSchedule(Request $request)
    {
        try {
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

            $dayOfWeek = strtolower(\Carbon\Carbon::parse($data['date'])->format('l'));

            \App\Models\EmployerSchedule::create([
                'employer_id' => $data['employer_id'],
                'day_of_week' => $dayOfWeek,
                'reserved_date' => $data['date'],
                'start_time' => $data['start_time'] ?? '00:00',
                'end_time' => $data['end_time'] ?? '23:59',
                'is_active' => false,
                'type' => $data['type'],
            ]);

            return response()->json(['message' => 'Horário reservado com sucesso.'], 201, [], JSON_UNESCAPED_UNICODE);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422, [], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            \Log::error('Employer.reserveSchedule error', ['exception' => $e]);
            return response()->json(['error' => 'Erro ao reservar horário.'], 500, [], JSON_UNESCAPED_UNICODE);
        }
    }


}