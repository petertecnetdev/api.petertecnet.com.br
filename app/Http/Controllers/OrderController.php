<?php

namespace App\Http\Controllers;

use App\Models\{Order, User, Item, Employer, Establishment, EmployerSchedule};
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
        return Carbon::parse($datetime)
            ->tz('America/Sao_Paulo')
            ->startOfMinute();
    }

    protected function calculateDuration(array $items): int
    {
        return Item::totalDurationForItems($items);
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

    /**
     * ✅ Agora valida:
     * - conflito com outros agendamentos
     * - se o horário está dentro do horário de trabalho configurado do employer
     * - se existe reserva do tipo break/holiday bloqueando
     */
    protected function validateSchedule(
        int $attendantId,
        Carbon $start,
        Carbon $end,
        ?int $ignoreOrderId = null
    ): void {

        $tz = 'America/Sao_Paulo';

        $start = $start->copy()->setTimezone($tz)->startOfMinute();
        $end = $end->copy()->setTimezone($tz)->startOfMinute();

        if ($end->lte($start)) {
            abort(422, 'Horário inválido para o agendamento.');
        }

        $date = $start->copy()->startOfDay();
        $dayOfWeek = strtolower($start->format('l')); // monday..sunday

        /* ======================================================
         | 1) Verifica se o employer atende no dia/horário (WORK)
         ====================================================== */

        $workSchedules = EmployerSchedule::query()
            ->where('employer_id', $attendantId)
            ->where('type', 'work')
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->get(['id', 'start_time', 'end_time', 'day_of_week', 'type', 'is_active']);

        if ($workSchedules->isEmpty()) {
            abort(422, 'Este colaborador não atende neste dia.');
        }

        $fitsInSomeWorkSchedule = false;

        foreach ($workSchedules as $ws) {
            $workStart = Carbon::parse($date->toDateString() . ' ' . $ws->start_time, $tz);
            $workEnd = Carbon::parse($date->toDateString() . ' ' . $ws->end_time, $tz);

            // start >= workStart && end <= workEnd
            if ($start->gte($workStart) && $end->lte($workEnd)) {
                $fitsInSomeWorkSchedule = true;
                break;
            }
        }

        if (!$fitsInSomeWorkSchedule) {
            abort(422, 'Horário inválido. Este colaborador não atende neste horário.');
        }

        /* ======================================================
         | 2) Verifica reservas (BREAK / HOLIDAY)
         ====================================================== */

        $reservedSchedules = EmployerSchedule::query()
            ->where('employer_id', $attendantId)
            ->whereIn('type', ['break', 'holiday'])
            ->where(function ($q) use ($dayOfWeek, $date) {
                $q->where('day_of_week', $dayOfWeek)
                  ->orWhereDate('reserved_date', $date->toDateString());
            })
            ->get(['id', 'type', 'reserved_date', 'start_time', 'end_time', 'day_of_week', 'is_active']);

        foreach ($reservedSchedules as $rs) {

            // holiday bloqueia o dia todo
            if ($rs->type === 'holiday') {
                // se reserved_date bater com o dia do agendamento
                if ($rs->reserved_date && Carbon::parse($rs->reserved_date)->toDateString() === $date->toDateString()) {
                    abort(422, 'Não é possível agendar nesta data. O colaborador está indisponível.');
                }

                // se não tiver reserved_date e for por dia_of_week (ex: todo domingo)
                if (!$rs->reserved_date && $rs->day_of_week === $dayOfWeek) {
                    abort(422, 'Não é possível agendar neste dia. O colaborador está indisponível.');
                }
            }

            if ($rs->type === 'break') {
                $breakStart = Carbon::parse($date->toDateString() . ' ' . ($rs->start_time ?? '00:00'), $tz);
                $breakEnd = Carbon::parse($date->toDateString() . ' ' . ($rs->end_time ?? '23:59'), $tz);

                // conflito com break: start < breakEnd && end > breakStart
                if ($start->lt($breakEnd) && $end->gt($breakStart)) {
                    abort(422, 'Não é possível agendar neste horário. O colaborador possui um intervalo/reserva.');
                }
            }
        }

        /* ======================================================
         | 3) Verifica conflito com outros agendamentos
         ====================================================== */

        if (Order::hasScheduleConflict($attendantId, $start, $end, $ignoreOrderId)) {
            abort(422, 'Conflito de agenda.');
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
            if ($start->lte(now('America/Sao_Paulo')->startOfMinute())) {
                abort(422, 'A data do agendamento deve ser futura.');
            }

            $employer = Employer::with('user')->findOrFail($data['attendant_id']);
            $this->validateClientIsNotEmployer($user->id, $employer);

            $duration = $this->calculateDuration($data['items']);
            $end = $start->copy()->addMinutes($duration);

            // ✅ agora valida conflito + expediente + reservas
            $this->validateSchedule($employer->id, $start, $end);

            $order = Order::create([
                'app_id' => $data['app_id'],
                'entity_name' => $data['entity_name'],
                'entity_id' => $data['entity_id'],
                'order_number' => Order::nextOrderNumber($data['app_id']),
                'order_datetime' => $start,
                'created_by' => $user->id,
                'client_id' => $user->id,
                'attendant_id' => $employer->id,
                'customer_name' => trim($user->first_name . ' ' . ($user->last_name ?? '')),
                'origin' => $data['origin'],
                'fulfillment' => $data['fulfillment'],
                'payment_status' => $data['payment_status'],
                'payment_method' => $data['payment_method'],
                'type' => 'appointment',
                'status' => 'scheduled',
                'appointment_status' => 'pending',
                'total_price' => 0,
                'total_duration' => $duration,
                'notes' => $data['notes'] ?? null,
            ]);

            $order->attachItems($data['items']);
            $order->update(['total_price' => $this->calculateTotalPrice($order)]);

            DB::commit();

            $this->sendAppointmentEmails($order, $employer, $user);

            return response()->json([
                'message' => 'Agendamento registrado com sucesso.',
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

            Order::flushEventListeners();

            $orders = Order::where('app_id', $appId)
                ->where('client_id', $authUserId)
                ->get([
                    'id', 'app_id', 'entity_name', 'entity_id', 'order_number', 'order_datetime',
                    'created_by', 'attendant_id', 'client_id', 'customer_name', 'customer_phone',
                    'customer_email', 'customer_cpf', 'access_code', 'origin', 'fulfillment',
                    'payment_status', 'payment_method', 'total_price', 'total_duration', 'status',
                    'notes', 'type', 'appointment_status', 'confirmed_by', 'cancelled_by',
                    'cancelled_reason', 'attended_at', 'created_at', 'updated_at'
                ]);

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
}
