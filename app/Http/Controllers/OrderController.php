<?php

namespace App\Http\Controllers;

use App\Models\{
    Order,
    EmployerSchedule,
    Item,
    Employer,
    Establishment
};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{
    DB,
    Log,
    Mail
};
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
            'app_id.integer' => 'O ID do aplicativo deve ser um número.',
            'app_id.exists' => 'O ID do aplicativo deve existir.',

            'entity_name.required' => 'O nome da entidade é obrigatório.',
            'entity_name.string' => 'O nome da entidade deve ser um texto.',

            'entity_id.required' => 'O ID da entidade é obrigatório.',
            'entity_id.integer' => 'O ID da entidade deve ser um número.',

            'items.required' => 'A lista de itens é obrigatória.',
            'items.array' => 'A lista de itens deve ser um array.',
            'items.min' => 'A lista de itens deve conter pelo menos 1 item.',

            'items.*.item_id.required' => 'O ID do item é obrigatório.',
            'items.*.item_id.integer' => 'O ID do item deve ser um número.',
            'items.*.item_id.exists' => 'O item informado não existe.',

            'items.*.quantity.required' => 'A quantidade é obrigatória.',
            'items.*.quantity.integer' => 'A quantidade deve ser um número.',
            'items.*.quantity.min' => 'A quantidade deve ser pelo menos 1.',

            'origin.required' => 'A origem é obrigatória.',
            'fulfillment.required' => 'O fulfillment é obrigatório.',
            'payment_status.required' => 'O status do pagamento é obrigatório.',
            'payment_method.required' => 'O método de pagamento é obrigatório.',

            'order_datetime.required' => 'A data/hora do agendamento é obrigatória.',
            'order_datetime.date' => 'A data/hora do agendamento é inválida.',

            'attendant_id.required' => 'O atendente é obrigatório.',
            'attendant_id.integer' => 'O atendente deve ser um número.',
            'attendant_id.exists' => 'O colaborador informado não existe.',

            'notes.string' => 'As observações devem ser um texto.',
        ];
    }

    protected function storeRules(): array
    {
        return [
            'mode' => 'required|string|in:appointment,direct',
        ];
    }

    protected function appointmentRules(): array
    {
        return [
            'app_id' => 'required|integer|exists:applications,id',
            'entity_name' => 'required|string|in:establishment',
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

    protected function directRules(): array
    {
        return [
            'app_id' => 'required|integer|exists:applications,id',
            'entity_name' => 'required|string|in:establishment',
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
        Log::info('[OrderController.normalizeDate] incoming datetime', [
            'raw' => $datetime,
            'server_tz' => config('app.timezone'),
            'php_default_tz' => date_default_timezone_get(),
            'now_sp' => now('America/Sao_Paulo')->format('Y-m-d H:i:sP'),
        ]);

        return Carbon::parse($datetime)
            ->tz('America/Sao_Paulo')
            ->startOfMinute();
    }

    protected function calculateDuration(array $items): int
    {
        $total = 0;

        foreach ($items as $row) {
            $itemId = (int)($row['item_id'] ?? 0);
            $qty = (int)($row['quantity'] ?? 1);

            if ($itemId <= 0) {
                continue;
            }

            $item = Item::find($itemId);
            if (!$item) {
                continue;
            }

            $dur = (int)($item->duration ?? 0);

            if ($dur <= 0) {
                $dur = 30;
            }

            if ($qty <= 0) {
                $qty = 1;
            }

            $total += ($dur * $qty);
        }

        if ($total <= 0) {
            $total = 30;
        }

        return $total;
    }

    protected function calculateTotalPrice(Order $order): float
    {
        // precisa existir: items (pivot com subtotal) + modifiers
        $order->load('items.modifiers');

        $total = 0;

        foreach ($order->items as $item) {
            $total += (float)($item->subtotal ?? 0);

            foreach ($item->modifiers as $modifier) {
                $modifierItem = Item::find($modifier->modifier_id);
                if ($modifierItem) {
                    $q = (int)($modifier->quantity ?: 1);
                    $total += (float)($modifierItem->price ?? 0) * $q;
                }
            }
        }

        return (float)$total;
    }

    protected function validateClientIsNotEmployer(int $clientUserId, Employer $employer): void
    {
        if ((int)$employer->user_id === (int)$clientUserId) {
            abort(422, 'Você não pode agendar um atendimento consigo mesmo.');
        }
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

        // 1) valida expediente
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

        // 2) cabe em algum bloco
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

        // 3) conflitos
        if (method_exists(Order::class, 'hasScheduleConflict') && Order::hasScheduleConflict($attendantId, $start, $end, $ignoreOrderId)) {
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
                    $oe = $os->copy()->addMinutes((int)($o->total_duration ?? 30))->startOfMinute();
                    return $start->lt($oe) && $end->gt($os);
                });

            if ($conflictOrder) {
                $os = Carbon::parse($conflictOrder->order_datetime)->setTimezone($tz)->startOfMinute();
                $oe = $os->copy()->addMinutes((int)($conflictOrder->total_duration ?? 30))->startOfMinute();

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
        } else {
            // fallback se não existir hasScheduleConflict
            $q = Order::query()
                ->where('attendant_id', $attendantId)
                ->where('type', 'appointment')
                ->whereIn('appointment_status', ['pending', 'confirmed'])
                ->whereDate('order_datetime', $date->toDateString())
                ->when($ignoreOrderId, fn($qq) => $qq->where('id', '!=', $ignoreOrderId));

            $existing = $q->get(['id', 'order_datetime', 'total_duration']);

            foreach ($existing as $o) {
                $os = Carbon::parse($o->order_datetime)->setTimezone($tz)->startOfMinute();
                $oe = $os->copy()->addMinutes((int)($o->total_duration ?? 30))->startOfMinute();

                if ($start->lt($oe) && $end->gt($os)) {
                    abort(
                        422,
                        "Conflito de agenda: já existe um agendamento nesse horário.\n" .
                            "Horário solicitado: {$start->format('H:i')} até {$end->format('H:i')}.\n" .
                            "Agendamento em conflito: {$os->format('H:i')} até {$oe->format('H:i')}."
                    );
                }
            }
        }
    }

    /* ======================================================
     | STORE
     ====================================================== */
    public function store(Request $request)
    {
        try {
            // valida mode antes
            $request->validate($this->storeRules(), $this->messages());

            return match ($request->input('mode')) {
                'appointment' => $this->storeAppointment($request),
                'direct' => $this->storeDirect($request),
                default => response()->json([
                    'success' => false,
                    'message' => 'Modo de criação inválido.',
                ], 422),
            };
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro de validação.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Order.store', [
                'exception' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Não foi possível concluir o cadastro da order devido a uma regra de negócio ou erro interno.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * ✅ APPOINTMENT
     * Corrigido para:
     * - sempre retornar JSON
     * - sempre commit/rollback
     * - validar agenda e futuro
     * - criar order + attach items + total_price + total_duration
     * - enviar emails após commit
     */
    protected function storeAppointment(Request $request)
    {
        DB::beginTransaction();

        try {
            $user = $request->user();

            $data = $request->validate($this->appointmentRules(), $this->messages());

            // valida entidade
            if ($data['entity_name'] === 'establishment') {
                $establishment = Establishment::query()->find($data['entity_id']);
                if (!$establishment) {
                    abort(422, 'Estabelecimento informado não foi encontrado.');
                }
            }

            $start = $this->normalizeDate($data['order_datetime']);

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

            // Serializa agendamentos do mesmo profissional dentro da transação.
            // Assim duas requisições simultâneas não conseguem validar o mesmo slot
            // antes de uma delas persistir o agendamento.
            $employer = Employer::with('user')
                ->lockForUpdate()
                ->findOrFail($data['attendant_id']);
            $this->validateClientIsNotEmployer((int)$user->id, $employer);

            $duration = $this->calculateDuration($data['items']);
            $end = $start->copy()->addMinutes($duration)->startOfMinute();

            $this->validateSchedule($employer->id, $start, $end);

            // cria order
            $order = Order::create([
                'app_id' => $data['app_id'],
                'entity_name' => $data['entity_name'],
                'entity_id' => $data['entity_id'],

                'order_number' => method_exists(Order::class, 'nextOrderNumber')
                    ? Order::nextOrderNumber($data['app_id'])
                    : null,

                // importante: salvar no banco SEM timezone bugado
                'order_datetime' => $start->format('Y-m-d H:i:s'),

                'created_by' => $user->id,
                'client_id' => $user->id,
                'attendant_id' => $employer->id,

                'customer_name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->user_name ?? 'Cliente'),

                'origin' => $data['origin'],
                'fulfillment' => $data['fulfillment'],
                'payment_status' => $data['payment_status'],
                'payment_method' => $data['payment_method'],

                'type' => 'appointment',
                'status' => 'pending', // order status
                'appointment_status' => 'pending', // appointment status

                'total_price' => 0,
                'total_duration' => $duration,

                'notes' => $data['notes'] ?? null,
            ]);

            // attach itens
            if (!method_exists($order, 'attachItems')) {
                abort(500, 'Método attachItems não existe no Model Order.');
            }

            $order->attachItems($data['items']);

            // atualiza preço total
            $total = $this->calculateTotalPrice($order);
            $order->update([
                'total_price' => $total,
                'total_duration' => $duration,
            ]);

            DB::commit();

            // emails após commit
            try {
                $this->sendAppointmentEmails($order->fresh(), $employer, $user);
            } catch (\Throwable $e) {
                Log::error('Order.storeAppointment.emails', ['error' => $e->getMessage()]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Agendamento criado com sucesso e aguardando confirmação.',
                'order' => $this->utf8ize(
                    $order->fresh()->load([
                        'items.item',
                        'items.modifiers.modifier',
                        'client',
                        'attendant.user',
                    ])->toArray()
                ),
            ], 201);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erro de validação.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Order.storeAppointment', [
                'exception' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao criar agendamento.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * ✅ DIRECT ORDER
     * Pedido operacional de balcão/atendimento imediato.
     * A regra de autoagendamento pertence exclusivamente ao modo appointment.
     */
    public function storeDirect(Request $request)
    {
        DB::beginTransaction();

        try {
            $data = $request->validate($this->directRules(), $this->messages());

            // valida entidade
            if ($data['entity_name'] === 'establishment') {
                $establishment = Establishment::query()->find($data['entity_id']);
                if (!$establishment) {
                    abort(422, 'Estabelecimento informado não foi encontrado.');
                }
            }

            $employer = Employer::with('user')->findOrFail($data['attendant_id']);

            $order = Order::create([
                'app_id' => $data['app_id'],
                'entity_name' => $data['entity_name'],
                'entity_id' => $data['entity_id'],

                'order_number' => method_exists(Order::class, 'nextOrderNumber')
                    ? Order::nextOrderNumber($data['app_id'])
                    : null,

                'order_datetime' => now('America/Sao_Paulo')->format('Y-m-d H:i:s'),

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

                'notes' => $data['notes'] ?? null,
            ]);

            if (!method_exists($order, 'attachItems')) {
                abort(500, 'Método attachItems não existe no Model Order.');
            }

            $order->attachItems($data['items']);

            $order->update([
                'total_price' => $this->calculateTotalPrice($order),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Pedido criado com sucesso.',
                'order' => $this->utf8ize(
                    $order->fresh()->load([
                        'items.item',
                        'items.modifiers.modifier',
                        'client',
                        'attendant.user',
                    ])->toArray()
                ),
            ], 201);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erro de validação.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Order.storeDirect', [
                'exception' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao criar pedido.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
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
            'success' => true,
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
            'success' => true,
            'message' => 'Pedidos do colaborador listados com sucesso.',
            'orders' => $orders,
        ]);
    }

    public function listByClient(Request $request)
    {
        try {
            $authUserId = $request->user()->id;
            $appId = $request->input('app_id');

            $orders = Order::query()
                ->where('app_id', $appId)
                ->where('client_id', $authUserId)
                ->get([
                    'id',
                    'app_id',
                    'entity_name',
                    'entity_id',
                    'order_number',
                    'order_datetime',
                    'created_by',
                    'attendant_id',
                    'client_id',
                    'customer_name',
                    'customer_phone',
                    'customer_email',
                    'customer_cpf',
                    'access_code',
                    'origin',
                    'fulfillment',
                    'payment_status',
                    'payment_method',
                    'total_price',
                    'total_duration',
                    'status',
                    'notes',
                    'type',
                    'appointment_status',
                    'confirmed_by',
                    'cancelled_by',
                    'cancelled_reason',
                    'attended_at',
                    'created_at',
                    'updated_at'
                ]);

            return response()->json([
                'success' => true,
                'message' => 'Pedidos do cliente listados com sucesso.',
                'orders' => $orders,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Order.listByClient', [
                'auth_user_id' => $request->user()->id ?? null,
                'app_id' => $request->input('app_id') ?? null,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar pedidos do cliente.',
                'error' => config('app.debug') ? $e->getMessage() : null,
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
            'success' => true,
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
            'success' => true,
            'message' => 'Pedido atualizado com sucesso.',
            'order' => $order->fresh(),
        ]);
    }

    public function updateOrderStatus(Request $request, int $id)
    {
        $order = Order::findOrFail($id);

        $request->validate([
            'status' => 'required|string',
        ], [
            'status.required' => 'O status é obrigatório.',
            'status.string' => 'O status deve ser um texto.',
        ]);

        $order->update([
            'status' => $request->status,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Status do pedido atualizado com sucesso.',
            'order' => $order->fresh(),
        ]);
    }

    /* ======================================================
     | EMAILS
     ====================================================== */
    protected function sendAppointmentEmails(Order $order, Employer $employer, $user): void
    {
        try {
            $establishment = Establishment::with('user')->find($order->entity_id);

            if ($user?->email) {
                Mail::to($user->email)->queue(
                    new AppointmentAwaitingConfirmation($order, $establishment, $employer->user)
                );
            }

            if ($employer->user?->email) {
                Mail::to($employer->user->email)->queue(
                    new NewAppointmentNotification($order, $establishment, $user)
                );
            }

            if ($establishment?->user?->email) {
                Mail::to($establishment->user->email)->queue(
                    new OwnerAppointmentNotification(
                        $order,
                        trim(($establishment->user->first_name ?? '') . ' ' . ($establishment->user->last_name ?? '')),
                        $user
                    )
                );
            }
        } catch (\Throwable $e) {
            Log::error('Order.sendAppointmentEmails', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /* ======================================================
     | LIST MY (mantido seu padrão completo)
     ====================================================== */
    public function listMy(Request $request, int $app_id)
    {
        try {
            $authUserId = $request->user()->id;
            $tz = 'America/Sao_Paulo';
            $now = now($tz)->format('Y-m-d H:i:s');

            $latestFileFromCollection = function ($files, string $type) {
                if (!$files || !($files instanceof \Illuminate\Support\Collection)) {
                    return null;
                }

                return $files
                    ->where('type', $type)
                    ->sortByDesc(function ($f) {
                        $createdAt = $f->created_at ? strtotime($f->created_at) : 0;
                        $id = (int)($f->id ?? 0);
                        return ($createdAt * 1000000) + $id;
                    })
                    ->first();
            };

            $mapFilePayload = function ($file) {
                if (!$file) {
                    return null;
                }

                return [
                    'id' => $file->id ?? null,
                    'type' => $file->type ?? null,
                    'path' => $file->path ?? ($file->file_path ?? null),
                    'public_url' => $file->public_url ?? null,
                    'url' => $file->public_url ?? ($file->url ?? null),
                    'created_at' => $file->created_at ?? null,
                ];
            };

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

            $payload = $orders->map(function ($o) use ($establishmentsMap, $latestFileFromCollection, $mapFilePayload, $tz) {
                $employer = $o->attendant;
                $employerUser = $employer?->user;

                $avatarFile = $latestFileFromCollection($employerUser?->files, 'avatar');

                $establishment = null;
                $logoFile = null;
                $bgFile = null;

                if ($o->entity_name === 'establishment' && !empty($o->entity_id)) {
                    $establishment = $establishmentsMap->get((int)$o->entity_id);

                    $logoFile = $latestFileFromCollection($establishment?->files, 'logo');
                    $bgFile = $latestFileFromCollection($establishment?->files, 'background');
                }

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
