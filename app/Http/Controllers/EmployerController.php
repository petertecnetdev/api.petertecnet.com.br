<?php

namespace App\Http\Controllers;

use App\Models\{Employer, Establishment, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Mail\CreatePasswordMail;
use App\Mail\{NewEmployerCollaborator, OwnerNotifiedNewCollaborator};

class EmployerController extends Controller
{
    protected function getValidationMessages()
    {
        return [
            'first_name.required' => 'O campo nome é obrigatório.',
            'first_name.string' => 'O campo nome deve ser um texto válido.',
            'first_name.max' => 'O campo nome não pode ter mais que 255 caracteres.',
            'email.required' => 'O campo e-mail é obrigatório.',
            'email.email' => 'O e-mail informado não é válido.',
            'email.max' => 'O e-mail não pode ter mais que 255 caracteres.',
            'establishment_id.required' => 'O ID do estabelecimento é obrigatório.',
            'establishment_id.integer' => 'O ID do estabelecimento deve ser um número inteiro.',
            'establishment_id.exists' => 'O estabelecimento informado não existe.',
            'link.required' => 'O campo link é obrigatório.',
            'link.url' => 'O link informado não é uma URL válida.',
            'role.required' => 'O campo função (role) é obrigatório.',
            'role.string' => 'O campo função deve ser um texto válido.',
            'permissions.required' => 'O campo permissões é obrigatório.',
            'permissions.array' => 'O campo permissões deve ser um array de permissões.',
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
    }public function view($user_name)
{
    try {
        Log::info('[' . __METHOD__ . '] Iniciando exibição detalhada de colaborador', ['user_name' => $user_name]);

        $user = Auth::user();

        // 🔹 Busca o colaborador pelo user_name vinculado ao usuário
        $employer = Employer::with([
            'user:id,first_name,last_name,email,phone,avatar,user_name',
            'establishment:id,name,slug,logo,background,address,phone',
            'creator:id,first_name,last_name,email',
            'updater:id,first_name,last_name,email'
        ])
            ->whereHas('user', function ($q) use ($user_name) {
                $q->where('user_name', $user_name);
            })
            ->first();

        if (!$employer) {
            Log::warning('[' . __METHOD__ . '] Colaborador não encontrado', ['user_name' => $user_name]);
            return response()->json(['error' => 'Colaborador não encontrado.'], 404);
        }

        $establishment = $employer->establishment;
        if (!$establishment) {
            Log::warning('[' . __METHOD__ . '] Colaborador sem estabelecimento associado', ['employer_id' => $employer->id]);
            return response()->json(['error' => 'Estabelecimento associado não encontrado.'], 404);
        }

        // =========================
        // REGISTRAR INTERAÇÃO
        // =========================
        try {
            \App\Models\Interaction::create([
                'entity_type' => 'employer',
                'entity_id' => $employer->id,
                'user_id' => $user?->id,
                'interaction_type' => 'view',
                'content' => json_encode([
                    'user_name' => $user_name,
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]),
                'name' => $employer->user?->first_name ?? 'Colaborador',
            ]);
        } catch (\Exception $ex) {
            Log::warning('[' . __METHOD__ . '] Falha ao registrar interação', ['erro' => $ex->getMessage()]);
        }

        // =========================
        // MÉTRICAS DE INTERAÇÕES
        // =========================
        $totalViews = \App\Models\Interaction::where('entity_type', 'employer')
            ->where('entity_id', $employer->id)
            ->count();

        $userInteractions = \App\Models\Interaction::where('entity_type', 'employer')
            ->where('entity_id', $employer->id)
            ->select(
                'user_id',
                \DB::raw('COUNT(*) as total_views'),
                \DB::raw('MIN(created_at) as first_view'),
                \DB::raw('MAX(created_at) as last_view'),
                \DB::raw('MAX(content) as last_content')
            )
            ->groupBy('user_id')
            ->with(['user:id,first_name,last_name,email,avatar,user_name'])
            ->orderByDesc('total_views')
            ->get()
            ->map(function ($interaction) {
                $content = json_decode($interaction->last_content ?? '{}', true);
                return [
                    'user_id' => $interaction->user_id,
                    'user_name' => trim($interaction->user?->first_name . ' ' . $interaction->user?->last_name),
                    'user_email' => $interaction->user?->email,
                    'user_avatar' => $interaction->user?->avatar,
                    'total_views' => (int) $interaction->total_views,
                    'first_view' => $interaction->first_view,
                    'last_view' => $interaction->last_view,
                    'ip' => $content['ip'] ?? null,
                    'user_agent' => $content['user_agent'] ?? null,
                ];
            });

        $distinctUsers = $userInteractions->count();
        $mostActiveUser = $userInteractions->sortByDesc('total_views')->first();
        $lastUser = $userInteractions->sortByDesc('last_view')->first();

        $interactionSummary = [
            'total_views' => $totalViews,
            'unique_users' => $distinctUsers,
            'most_active_user' => $mostActiveUser ? [
                'name' => $mostActiveUser['user_name'],
                'views' => $mostActiveUser['total_views'],
                'last_view' => $mostActiveUser['last_view'],
            ] : null,
            'last_view_user' => $lastUser ? [
                'name' => $lastUser['user_name'],
                'last_view' => $lastUser['last_view'],
            ] : null,
        ];

        // =========================
        // ATENDIMENTOS
        // =========================
        $appointmentsQuery = \App\Models\Order::where('attendant_id', $employer->id)
            ->where('type', 'appointment');

        $appointmentsCount = (clone $appointmentsQuery)->count();
        $attendedCount = (clone $appointmentsQuery)->where('appointment_status', 'attended')->count();
        $cancelledCount = (clone $appointmentsQuery)->where('appointment_status', 'cancelled')->count();
        $totalValue = (clone $appointmentsQuery)->sum('total_price');

        $lastAppointments = (clone $appointmentsQuery)
            ->latest('order_datetime')
            ->take(5)
            ->get(['id', 'order_number', 'customer_name', 'order_datetime', 'appointment_status', 'total_price']);

        // =========================
        // FORMATAÇÃO FINAL
        // =========================
        $employer->views_total = $totalViews;
        $employer->views_unique = $distinctUsers;
        $employer->appointments_total = $appointmentsCount;
        $employer->appointments_attended = $attendedCount;
        $employer->appointments_cancelled = $cancelledCount;
        $employer->appointments_value = number_format($totalValue, 2, '.', '');
        $employer->created_since = $employer->created_at?->diffForHumans();
        $employer->last_updated_at = $employer->updated_at?->format('d/m/Y H:i');

        return response()->json([
            'employer' => $employer,
            'establishment' => $establishment,
            'interaction_summary' => $interactionSummary,
            'user_interactions' => $userInteractions,
            'appointments_recent' => $lastAppointments,
            'metrics' => [
                'total_appointments' => $appointmentsCount,
                'attended' => $attendedCount,
                'cancelled' => $cancelledCount,
                'total_value' => number_format($totalValue, 2, '.', ''),
            ],
            'message' => 'Dados detalhados do colaborador carregados com sucesso.',
        ], 200);

    } catch (\Exception $e) {
        Log::error('[' . __METHOD__ . '] Falha ao exibir colaborador', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return response()->json([
            'error' => 'Ocorreu um erro ao carregar os dados do colaborador.',
            'details' => $e->getMessage(),
        ], 500);
    }
}
}