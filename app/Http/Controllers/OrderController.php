<?php

namespace App\Http\Controllers;

use App\Models\{Order, EmployerSchedule, Item, Employer, Establishment};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use App\Mail\NewAppointmentNotification;
use App\Mail\OwnerAppointmentNotification;
use App\Mail\AppointmentAwaitingConfirmation;

class OrderController extends ApiController
{
    /* ======================================================
     | VALIDATION
     ====================================================== */
    protected function messages(): array
    {
        return [
            'app_id.required' => 'O ID do aplicativo é obrigatório.',
            'app_id.exists' => 'O ID do aplicativo deve existir.',
            'entity_name.required' => 'O nome da entidade é obrigatório.',
            'entity_id.required' => 'O ID da entidade é obrigatório.',
            'items.required' => 'A lista de itens é obrigatória.',
            'items.*.item_id.required' => 'O ID do item é obrigatório.',
            'items.*.quantity.required' => 'A quantidade é obrigatória.',
        ];

    }

    protected function appointmentRules(): array
    {
        return [
            'app_id' => 'required|integer|exists:applications,id',
            'entity_name' => 'required|string',
            'entity_id' => 'required|integer',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer|exists:items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'origin' => 'required|string',
            'fulfillment' => 'required|string',
            'payment_status' => 'required|string',
            'payment_method' => 'required|string',
            'order_datetime' => 'required|date',
            'attendant_id' => 'required|integer|exists:employers,id',
            'notes' => 'nullable|string',
        ];
    }

    /* ======================================================
     | UTF-8 SAFETY
     ====================================================== */
    protected function utf8ize($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->utf8ize($value);
            }
            return $data;
        }

        if (is_string($data)) {
            return mb_convert_encoding($data, 'UTF-8', 'UTF-8');
        }

        return $data;
    }

    /* ======================================================
     | CORE HELPERS
     ====================================================== */
    protected function normalizeDate(string $datetime): Carbon
    {
        // ✅ Debug completo do que chega
        Log::info('[OrderController.normalizeDate] incoming datetime', [
            'raw' => $datetime,
            'server_tz' => config('app.timezone'),
            'php_default_tz' => date_default_timezone_get(),
            'now_sp' => now('America/Sao_Paulo')->format('Y-m-d H:i:sP'),
        ]);

        // ✅ Converte SEMPRE para SP
        return Carbon::parse($datetime)
            ->tz('America/Sao_Paulo')
            ->startOfMinute();
    }

    protected function calculateDuration(array $items): int
    {
        $total = 0;

        foreach ($items as $row) {
            $itemId = (int) ($row['item_id'] ?? 0);
            $qty = (int) ($row['quantity'] ?? 1);

            if (!$itemId)
                continue;

            $item = Item::find($itemId);
            if (!$item)
                continue;

            $dur = (int) ($item->duration ?? 0);

            // ✅ fallback de segurança
            if ($dur <= 0)
                $dur = 30;

            if ($qty <= 0)
                $qty = 1;

            $total += ($dur * $qty);
        }

        // ✅ fallback final
        if ($total <= 0)
            $total = 30;

        return $total;
    }

    protected function calculateTotalPrice(Order $order): float
    {
        $order->load('items.modifiers');

        $total = 0;
        foreach ($order->items as $item) {
            $total += $item->subtotal;
            foreach ($item->modifiers as $modifier) {
                if ($mod = Item::find($modifier->modifier_id)) {
                    $total += $mod->price * ($modifier->quantity ?: 1);
                }
            }
        }

        return $total;
    }

    protected function validateSchedule(
        int $attendantId,
        Carbon $start,
        Carbon $end,
        ?int $ignoreOrderId = null
    ): void {

        $tz = 'America/Sao_Paulo';

        $start = $start->copy()->setTimezone($tz)->startOfMinute();
        $end = $end->copy()->setTimezone($tz)->startOfMinute();

        // ✅ DEBUG: mostra no log exatamente start/end
        Log::info('[OrderController.validateSchedule] schedule check', [
            'attendant_id' => $attendantId,
            'start' => $start->format('Y-m-d H:i:sP'),
            'end' => $end->format('Y-m-d H:i:sP'),
            'diff_minutes' => $start->diffInMinutes($end, false),
        ]);

        if ($end->lte($start)) {
            abort(
                422,
                "Horário inválido: duração do serviço retornou 0 min.\n" .
                "Início: {$start->format('d/m/Y H:i')}\n" .
                "Fim: {$end->format('d/m/Y H:i')}\n" .
                "Verifique se os serviços possuem duration cadastrado."
            );
        }

        $date = $start->copy()->startOfDay();
        $dayOfWeek = strtolower($start->format('l')); // monday..sunday
        $duration = $start->diffInMinutes($end);

        // ======================================================
        // ✅ 1) Valida se o colaborador tem expediente nesse dia
        // ======================================================

        $workSchedules = EmployerSchedule::query()
            ->where('employer_id', $attendantId)
            ->where('type', 'work')
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get(['start_time', 'end_time']);

        if ($workSchedules->isEmpty()) {
            abort(422, 'Este colaborador não atende neste dia.');
        }

        $workText = $workSchedules
            ->map(fn($s) => "{$s->start_time} às {$s->end_time}")
            ->implode(' / ');

        // ======================================================
        // ✅ 2) Verifica se o agendamento cabe em ALGUM bloco
        // ======================================================

        $fitsInWork = false;
        $closestEnd = null;

        foreach ($workSchedules as $ws) {
            $workStart = Carbon::parse($date->toDateString() . ' ' . $ws->start_time, $tz);
            $workEnd = Carbon::parse($date->toDateString() . ' ' . $ws->end_time, $tz);

            if (!$closestEnd || $workEnd->gt($closestEnd)) {
                $closestEnd = $workEnd;
            }

            if ($start->gte($workStart) && $end->lte($workEnd)) {
                $fitsInWork = true;
                break;
            }
        }

        if (!$fitsInWork) {
            $endsAt = $end->format('H:i');
            $startsAt = $start->format('H:i');

            if ($closestEnd && $end->gt($closestEnd)) {
                abort(
                    422,
                    "Horário inválido: o tempo do serviço estoura o expediente do colaborador.\n" .
                    "Horário escolhido: {$startsAt} até {$endsAt} ({$duration} min).\n" .
                    "Expediente do colaborador: {$workText}."
                );
            }

            abort(
                422,
                "Horário inválido: este colaborador não atende neste horário.\n" .
                "Horário escolhido: {$startsAt} até {$endsAt} ({$duration} min).\n" .
                "Expediente do colaborador: {$workText}."
            );
        }

        // ======================================================
        // ✅ 3) Conflito com outros agendamentos
        // ======================================================

        if (Order::hasScheduleConflict($attendantId, $start, $end, $ignoreOrderId)) {

            $conflictOrder = Order::query()
                ->where('attendant_id', $attendantId)
                ->where('type', 'appointment')
                ->whereIn('appointment_status', ['pending', 'confirmed'])
                ->whereDate('order_datetime', $date->toDateString())
                ->when($ignoreOrderId, fn($q) => $q->where('id', '!=', $ignoreOrderId))
                ->orderBy('order_datetime')
                ->get(['id', 'order_datetime', 'total_duration'])
                ->first(function ($o) use ($start, $end, $tz) {
                    $os = Carbon::parse($o->order_datetime)->setTimezone($tz)->startOfMinute();
                    $oe = $os->copy()->addMinutes((int) ($o->total_duration ?? 30))->startOfMinute();
                    return $start->lt($oe) && $end->gt($os);
                });

            if ($conflictOrder) {
                $os = Carbon::parse($conflictOrder->order_datetime)->setTimezone($tz)->startOfMinute();
                $oe = $os->copy()->addMinutes((int) ($conflictOrder->total_duration ?? 30))->startOfMinute();

                abort(
                    422,
                    "Conflito de agenda: já existe um agendamento nesse horário.\n" .
                    "Horário solicitado: {$start->format('H:i')} até {$end->format('H:i')}.\n" .
                    "Agendamento em conflito: {$os->format('H:i')} até {$oe->format('H:i')}."
                );
            }

            abort(
                422,
                "Conflito de agenda: já existe um atendimento marcado nesse período.\n" .
                "Horário solicitado: {$start->format('H:i')} até {$end->format('H:i')}."
            );
        }
    }
    protected function validateClientIsNotEmployer(int $clientUserId, Employer $employer): void
    {
        if ((int) $employer->user_id === (int) $clientUserId) {
            abort(422, 'Você não pode agendar um atendimento consigo mesmo.');
        }
    }

    /* ======================================================
     | STORE
     ====================================================== */
    public function store(Request $request)
    {
        try {
            $user = auth()->user();

            if ($request->filled('employer_id') && $request->filled('client_id')) {
                $employer = \App\Models\Employer::with('user')->find($request->input('employer_id'));

                if (!$employer) {
                    return response()->json([
                        'message' => 'Colaborador informado não foi encontrado.'
                    ], 422);
                }

                if ((int) $employer->user_id === (int) $request->input('client_id')) {
                    return response()->json([
                        'message' => 'Não é possível realizar um agendamento onde o cliente e o colaborador são a mesma pessoa.'
                    ], 422);
                }
            }

            return match ($request->input('mode')) {
                'appointment' => $this->storeAppointment($request),
                'direct' => $this->storeDirect($request),
                default => response()->json([
                    'message' => 'Modo de cria��o inv�lido.'
                ], 422),
            };
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Erro de valida��o.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Não foi possível concluir o agendamento devido a uma regra de negócio ou erro interno.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }



    protected function storeAppointment(Request $request)
    {
        DB::beginTransaction();

        try {
            $user = $request->user();
            $data = $request->validate($this->appointmentRules(), $this->messages());

            $start = $this->normalizeDate($data['order_datetime']);

            // ✅ log detalhado
            Log::info('[OrderController.storeAppointment] scheduling datetime', [
                'incoming' => $data['order_datetime'],
                'parsed_sp' => $start->format('Y-m-d H:i:sP'),
            ]);

            if ($start->lte(now('America/Sao_Paulo')->startOfMinute())) {
                abort(
                    422,
                    "A data do agendamento deve ser futura.\n" .
                    "Data recebida: {$start->format('d/m/Y H:i')}\n" .
                    "Agora: " . now('America/Sao_Paulo')->format('d/m/Y H:i')
                );
            }

            $employer = Employer::with('user')->findOrFail($data['attendant_id']);
            $this->validateClientIsNotEmployer($user->id, $employer);

            $duration = $this->calculateDuration($data['items']);
            $end = $start->copy()->addMinutes($duration);

            $this->validateSchedule($employer->id, $start, $end);

            // ... (restante do método igual)
            // ⚠️ mantenha exatamente seu código daqui pra baixo

            DB::commit();

        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Order.storeAppointment', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Erro ao criar agendamento.'], 500);
        }
    }

    public function storeDirect(Request $request)
    {
        DB::beginTransaction();

        try {
            $data = $request->validate([
                'app_id' => 'required|integer|exists:applications,id',
                'entity_name' => 'required|string',
                'entity_id' => 'required|integer',
                'attendant_id' => 'required|integer|exists:employers,id',
                'client_id' => 'required|integer|exists:users,id',
                'customer_name' => 'required|string',
                'origin' => 'required|string',
                'fulfillment' => 'required|string',
                'payment_status' => 'required|string',
                'payment_method' => 'required|string',
                'items' => 'required|array|min:1',
                'items.*.item_id' => 'required|integer|exists:items,id',
                'items.*.quantity' => 'required|integer|min:1',
            ], $this->messages());

            $employer = Employer::with('user')->findOrFail($data['attendant_id']);
            $this->validateClientIsNotEmployer($data['client_id'], $employer);

            $order = Order::create([
                'app_id' => $data['app_id'],
                'entity_name' => $data['entity_name'],
                'entity_id' => $data['entity_id'],
                'order_number' => Order::nextOrderNumber($data['app_id']),
                'order_datetime' => now('America/Sao_Paulo'),
                'created_by' => $request->user()->id,
                'client_id' => $data['client_id'],
                'attendant_id' => $employer->id,
                'customer_name' => $data['customer_name'],
                'origin' => $data['origin'],
                'fulfillment' => $data['fulfillment'],
                'payment_status' => $data['payment_status'],
                'payment_method' => $data['payment_method'],
                'type' => 'direct',
                'status' => 'completed',
                'total_price' => 0,
                'total_duration' => 0,
            ]);

            $order->attachItems($data['items']);
            $order->update(['total_price' => $this->calculateTotalPrice($order)]);

            DB::commit();

            return response()->json([
                'message' => 'Pedido criado com sucesso.',
                'order' => $this->utf8ize(
                    $order->fresh()->load([
                        'items.item',
                        'client',
                        'attendant.user',
                    ])->toArray()
                ),
            ], 201);

        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Order.storeDirect', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Erro ao criar pedido.'], 500);
        }
    }

    /* ======================================================
     | LIST & VIEW
     ====================================================== */
    public function listByEntitySlug(string $slug)
    {
        $establishment = Establishment::where('slug', $slug)->firstOrFail();

        $orders = Order::where('entity_name', 'establishment')
            ->where('entity_id', $establishment->id)
            ->with('items.item')
            ->orderBy('order_datetime')
            ->get();

        return response()->json([
            'message' => 'Pedidos listados com sucesso.',
            'orders' => $orders,
        ]);
    }

    public function listByEmployer(Request $request)
    {
        $employer = Employer::where('user_id', $request->user()->id)->firstOrFail();

        $orders = Order::where('attendant_id', $employer->id)
            ->with(['items.item', 'client'])
            ->orderBy('order_datetime')
            ->get();

        return response()->json([
            'message' => 'Pedidos do colaborador listados com sucesso.',
            'orders' => $orders,
        ]);
    }


    public function listByClient(Request $request)
    {
        try {
            $authUserId = $request->user()->id;
            $appId = $request->input('app_id');

            // desativa os appends para não carregar atributos que quebram
            Order::flushEventListeners(); // evita triggers de append
            $orders = Order::where('app_id', $appId)
                ->where('client_id', $authUserId)
                ->get(['id', 'app_id', 'entity_name', 'entity_id', 'order_number', 'order_datetime', 'created_by', 'attendant_id', 'client_id', 'customer_name', 'customer_phone', 'customer_email', 'customer_cpf', 'access_code', 'origin', 'fulfillment', 'payment_status', 'payment_method', 'total_price', 'total_duration', 'status', 'notes', 'type', 'appointment_status', 'confirmed_by', 'cancelled_by', 'cancelled_reason', 'attended_at', 'created_at', 'updated_at']);

            return response()->json($orders);

        } catch (\Exception $e) {
            Log::error('Order.listByClient', [
                'auth_user_id' => $request->user()->id ?? null,
                'app_id' => $request->input('app_id') ?? null,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Erro ao listar pedidos do cliente'
            ], 500);
        }
    }

    public function show(int $id)
    {
        $order = Order::with([
            'items.item',
            'items.modifiers.modifier',
            'client',
            'attendant.user',
        ])->findOrFail($id);

        return response()->json([
            'message' => 'Pedido carregado com sucesso.',
            'order' => $order,
        ]);
    }

    public function view(int $id)
    {
        return $this->show($id);
    }

    /* ======================================================
     | UPDATE
     ====================================================== */
    public function update(Request $request, int $id)
    {
        $order = Order::findOrFail($id);

        $order->update($request->only([
            'notes',
            'payment_status',
            'payment_method',
            'status',
            'appointment_status',
        ]));

        return response()->json([
            'message' => 'Pedido atualizado com sucesso.',
            'order' => $order->fresh(),
        ]);
    }

    public function updateOrderStatus(Request $request, int $id)
    {
        $order = Order::findOrFail($id);

        $request->validate([
            'status' => 'required|string',
        ]);

        $order->update([
            'status' => $request->status,
        ]);

        return response()->json([
            'message' => 'Status do pedido atualizado com sucesso.',
            'order' => $order,
        ]);
    }

    /* ======================================================
     | EMAILS
     ====================================================== */
    protected function sendAppointmentEmails(Order $order, Employer $employer, $user): void
    {
        try {
            $establishment = Establishment::with('user')->find($order->entity_id);

            $user?->email && Mail::to($user->email)
                ->queue(new AppointmentAwaitingConfirmation($order, $establishment, $employer->user));

            $employer->user?->email && Mail::to($employer->user->email)
                ->queue(new NewAppointmentNotification($order, $establishment, $user));

            $establishment?->user?->email && Mail::to($establishment->user->email)
                ->queue(new OwnerAppointmentNotification(
                    $order,
                    trim($establishment->user->first_name . ' ' . $establishment->user->last_name),
                    $user
                ));
        } catch (\Throwable $e) {
            Log::error('Order.sendAppointmentEmails', ['error' => $e->getMessage()]);
        }
    }
    public function listMy(Request $request, int $app_id)
    {
        try {
            $authUserId = $request->user()->id;
            $tz = 'America/Sao_Paulo';
            $now = now($tz)->format('Y-m-d H:i:s');

            // ==========================================================
            // Helpers
            // ==========================================================
            $latestFileFromCollection = function ($files, string $type) {
                if (!$files || !($files instanceof \Illuminate\Support\Collection)) {
                    return null;
                }

                return $files
                    ->where('type', $type)
                    ->sortByDesc(function ($f) {
                        $createdAt = $f->created_at ? strtotime($f->created_at) : 0;
                        $id = (int) ($f->id ?? 0);
                        return ($createdAt * 1000000) + $id;
                    })
                    ->first();
            };

            $mapFilePayload = function ($file) {
                if (!$file)
                    return null;

                return [
                    'id' => $file->id ?? null,
                    'type' => $file->type ?? null,
                    'path' => $file->path ?? ($file->file_path ?? null),

                    // ✅ SEMPRE public_url
                    'public_url' => $file->public_url ?? null,
                    'url' => $file->public_url ?? ($file->url ?? null),

                    'created_at' => $file->created_at ?? null,
                ];
            };

            // ==========================================================
            // Orders - TODOS os pedidos do cliente
            // Ordenação UX PERFEITA:
            // 1) FUTUROS (order_datetime >= agora) primeiro, por ordem do mais próximo (ASC)
            // 2) PASSADOS depois, do mais recente passado (DESC)
            // 3) tipo/direct etc entra junto naturalmente pelo order_datetime
            // ==========================================================
            $orders = Order::query()
                ->where('app_id', $app_id)
                ->where('client_id', $authUserId)
                ->orderByRaw("
                CASE
                    WHEN order_datetime >= ? THEN 0
                    ELSE 1
                END ASC
            ", [$now])
                ->orderByRaw("
                CASE
                    WHEN order_datetime >= ? THEN order_datetime
                    ELSE NULL
                END ASC
            ", [$now])
                ->orderByRaw("
                CASE
                    WHEN order_datetime < ? THEN order_datetime
                    ELSE NULL
                END DESC
            ", [$now])
                ->orderByDesc('id')
                ->with([
                    'attendant' => function ($q) {
                        $q->select([
                            'id',
                            'user_id',
                            'establishment_id',
                            'role',
                            'created_at',
                            'updated_at',
                        ]);
                    },
                    'attendant.user' => function ($q) {
                        $q->select([
                            'id',
                            'first_name',
                            'last_name',
                            'user_name',
                            'email',
                            'phone',
                            'created_at',
                            'updated_at',
                        ]);
                    },
                    'attendant.user.files' => function ($q) {
                        $q->where('type', 'avatar')
                            ->orderByDesc('created_at')
                            ->orderByDesc('id');
                    },
                ])
                ->get([
                    'id',
                    'app_id',
                    'entity_name',
                    'entity_id',
                    'order_number',
                    'order_datetime',
                    'attendant_id',
                    'client_id',
                    'total_price',
                    'total_duration',
                    'status',
                    'notes',
                    'type',
                    'appointment_status',
                    'origin',
                    'fulfillment',
                    'payment_status',
                    'payment_method',
                    'created_at',
                    'updated_at',
                ]);

            // ==========================================================
            // Establishments usados nesses pedidos
            // ==========================================================
            $establishmentIds = $orders
                ->filter(fn($o) => $o->entity_name === 'establishment' && !empty($o->entity_id))
                ->pluck('entity_id')
                ->unique()
                ->values()
                ->all();

            $establishmentsMap = collect();

            if (!empty($establishmentIds)) {
                $establishments = Establishment::query()
                    ->whereIn('id', $establishmentIds)
                    ->with([
                        'files' => function ($q) {
                            $q->whereIn('type', ['logo', 'background'])
                                ->orderByDesc('created_at')
                                ->orderByDesc('id');
                        }
                    ])
                    ->get([
                        'id',
                        'slug',
                        'name',
                        'city',
                        'uf',
                        'created_at',
                        'updated_at'
                    ]);

                $establishmentsMap = $establishments->keyBy('id');
            }

            // ==========================================================
            // Payload
            // ==========================================================
            $payload = $orders->map(function ($o) use ($establishmentsMap, $latestFileFromCollection, $mapFilePayload, $tz) {
                $employer = $o->attendant;
                $employerUser = $employer?->user;

                // ✅ Avatar mais recente do user do employer
                $avatarFile = $latestFileFromCollection($employerUser?->files, 'avatar');

                // ✅ establishment e imagens mais recentes
                $establishment = null;
                $logoFile = null;
                $bgFile = null;

                if ($o->entity_name === 'establishment' && !empty($o->entity_id)) {
                    $establishment = $establishmentsMap->get((int) $o->entity_id);

                    $logoFile = $latestFileFromCollection($establishment?->files, 'logo');
                    $bgFile = $latestFileFromCollection($establishment?->files, 'background');
                }

                // ✅ flag útil pro front
                $isFuture = false;
                if (!empty($o->order_datetime)) {
                    try {
                        $isFuture = Carbon::parse($o->order_datetime)->tz($tz)->gte(now($tz));
                    } catch (\Throwable $e) {
                        $isFuture = false;
                    }
                }

                return [
                    'id' => $o->id,
                    'app_id' => $o->app_id,
                    'order_number' => $o->order_number,
                    'type' => $o->type,
                    'order_datetime' => $o->order_datetime,
                    'total_price' => $o->total_price,
                    'total_duration' => $o->total_duration,
                    'status' => $o->status,
                    'appointment_status' => $o->appointment_status,
                    'notes' => $o->notes,
                    'entity_name' => $o->entity_name,
                    'entity_id' => $o->entity_id,
                    'origin' => $o->origin ?? null,
                    'fulfillment' => $o->fulfillment ?? null,
                    'payment_status' => $o->payment_status ?? null,
                    'payment_method' => $o->payment_method ?? null,
                    'created_at' => $o->created_at,
                    'updated_at' => $o->updated_at,

                    // ✅ ajuda o front a separar "próximos" e "passados"
                    'is_future' => $isFuture,

                    'establishment' => $establishment ? [
                        'id' => $establishment->id,
                        'slug' => $establishment->slug,
                        'name' => $establishment->name,
                        'city' => $establishment->city ?? null,
                        'uf' => $establishment->uf ?? null,
                        'files' => [
                            'logo' => $mapFilePayload($logoFile),
                            'background' => $mapFilePayload($bgFile),
                        ],
                    ] : null,

                    'employer' => $employer ? [
                        'id' => $employer->id,
                        'user_id' => $employer->user_id,
                        'establishment_id' => $employer->establishment_id,
                        'role' => $employer->role ?? null,
                        'user' => $employerUser ? [
                            'id' => $employerUser->id,
                            'user_name' => $employerUser->user_name ?? null,
                            'first_name' => $employerUser->first_name ?? null,
                            'last_name' => $employerUser->last_name ?? null,
                            'files' => [
                                'avatar' => $mapFilePayload($avatarFile),
                            ],
                        ] : null,
                    ] : null,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Pedidos listados com sucesso.',
                'orders' => $payload,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Order.listMy', [
                'auth_user_id' => $request->user()->id ?? null,
                'app_id' => $app_id ?? null,
                'exception' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar seus pedidos.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

}


