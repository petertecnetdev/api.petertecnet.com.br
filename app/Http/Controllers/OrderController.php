<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Item;
use App\Models\Employer;
use App\Models\Establishment;
use App\Models\Interaction;
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

    protected function validateSchedule(
        int $attendantId,
        Carbon $start,
        Carbon $end,
        ?int $ignoreOrderId = null
    ): void {
        if (Order::hasScheduleConflict($attendantId, $start, $end, $ignoreOrderId)) {
            abort(422, 'Conflito de agenda.');
        }
    }

    /* ======================================================
     | STORE
     ====================================================== */
    public function store(Request $request)
    {
        return match ($request->input('mode')) {
            'appointment' => $this->storeAppointment($request),
            'direct' => $this->storeDirect($request),
            default => response()->json(['error' => 'Modo de criação inválido.'], 422),
        };
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

            $duration = $this->calculateDuration($data['items']);
            $end = $start->copy()->addMinutes($duration);

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
                'order' => $order->fresh()->load([
                    'items.item',
                    'client:id,first_name,last_name,user_name,avatar,email',
                    'attendant.user:id,first_name,last_name,user_name,avatar,email',
                ]),
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Order.storeAppointment', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Erro ao criar agendamento.'], 500);
        }
    }

    protected function storeDirect(Request $request)
    {
        DB::beginTransaction();

        try {
            $data = $request->validate([
                'app_id' => 'required|integer|exists:applications,id',
                'entity_name' => 'required|string',
                'entity_id' => 'required|integer',
                'attendant_id' => 'required|integer|exists:employers,id',
                'customer_name' => 'required|string',
                'origin' => 'required|string',
                'fulfillment' => 'required|string',
                'payment_status' => 'required|string',
                'payment_method' => 'required|string',
                'items' => 'required|array|min:1',
                'items.*.item_id' => 'required|integer|exists:items,id',
                'items.*.quantity' => 'required|integer|min:1',
            ], $this->messages());

            $order = Order::create([
                'app_id' => $data['app_id'],
                'entity_name' => $data['entity_name'],
                'entity_id' => $data['entity_id'],
                'order_number' => Order::nextOrderNumber($data['app_id']),
                'order_datetime' => now(),
                'created_by' => $request->user()->id,
                'attendant_id' => $data['attendant_id'],
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
                'order' => $order->fresh()->load('items.item', 'items.modifiers.modifier'),
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Order.storeDirect', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Erro ao criar pedido.'], 500);
        }
    }

    /* ======================================================
     | UPDATE
     ====================================================== */
    public function update(Request $request, int $id)
    {
        DB::beginTransaction();

        try {
            $order = Order::lockForUpdate()->findOrFail($id);

            if (in_array($order->status, ['completed', 'cancelled'])) {
                abort(422, 'Pedido não pode mais ser alterado.');
            }

            $data = $request->validate([
                'customer_name' => 'nullable|string',
                'origin' => 'nullable|string',
                'fulfillment' => 'nullable|string',
                'payment_status' => 'nullable|string',
                'payment_method' => 'nullable|string',
                'notes' => 'nullable|string',
                'order_datetime' => 'nullable|date',
                'attendant_id' => 'nullable|integer|exists:employers,id',
            ]);

            if ($order->type === 'appointment') {
                $start = isset($data['order_datetime'])
                    ? $this->normalizeDate($data['order_datetime'])
                    : $this->normalizeDate($order->order_datetime);

                if ($start->lte(now('America/Sao_Paulo')->startOfMinute())) {
                    abort(422, 'A nova data deve ser futura.');
                }

                $attendantId = $data['attendant_id'] ?? $order->attendant_id;
                $end = $start->copy()->addMinutes((int) $order->total_duration);

                $this->validateSchedule($attendantId, $start, $end, $order->id);

                $order->order_datetime = $start;
                $order->attendant_id = $attendantId;
            }

            foreach ($data as $field => $value) {
                if (!in_array($field, ['order_datetime', 'attendant_id'])) {
                    $order->$field = $value;
                }
            }

            $order->save();

            DB::commit();

            return response()->json([
                'message' => 'Pedido atualizado com sucesso.',
                'order' => $order->fresh(),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Order.update', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Erro ao atualizar pedido.'], 500);
        }
    }

    /* ======================================================
     | STATUS
     ====================================================== */
    public function updateOrderStatus(Request $request, int $id)
    {
        DB::beginTransaction();

        try {
            $data = $request->validate([
                'action' => 'required|in:confirm,cancel,attended,not_attended',
                'reason' => 'nullable|string|max:255',
            ]);

            $order = Order::lockForUpdate()->findOrFail($id);

            if ($order->type !== 'appointment') {
                abort(422, 'Ação permitida apenas para agendamentos.');
            }

            $now = now('America/Sao_Paulo');
            $start = $this->normalizeDate($order->order_datetime);
            $end = $start->copy()->addMinutes((int) $order->total_duration);

            match ($data['action']) {
                'confirm' => $this->confirmAppointment($order, $now, $start),
                'cancel' => $this->cancelAppointment($order, $data),
                'attended' => $this->attendAppointment($order, $now, $end),
                'not_attended' => $this->notAttendAppointment($order, $now, $start),
            };

            $order->save();

            Interaction::create([
                'user_id' => $request->user()->id,
                'entity_id' => $order->id,
                'entity_name' => 'order',
                'interaction_type' => 'AppointmentStatusUpdate',
                'content' => json_encode($data),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Status atualizado com sucesso.',
                'order' => $order->fresh(),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Order.updateOrderStatus', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Erro ao atualizar status.'], 500);
        }
    }

    /* ======================================================
     | STATUS HELPERS
     ====================================================== */
    protected function confirmAppointment(Order $order, Carbon $now, Carbon $start): void
    {
        if ($order->appointment_status !== 'pending' || $now->gte($start)) {
            abort(422, 'Agendamento não pode ser confirmado.');
        }
        $order->appointment_status = 'confirmed';
        $order->status = 'scheduled';
    }

    protected function cancelAppointment(Order $order, array $data): void
    {
        if (!in_array($order->appointment_status, ['pending', 'confirmed'])) {
            abort(422, 'Este agendamento não pode ser cancelado.');
        }
        $order->appointment_status = 'cancelled';
        $order->status = 'cancelled';
        $order->cancelled_reason = $data['reason'] ?? null;
    }

    protected function attendAppointment(Order $order, Carbon $now, Carbon $end): void
    {
        if ($order->appointment_status !== 'confirmed' || $now->lt($end)) {
            abort(422, 'Atendimento ainda não pode ser finalizado.');
        }
        $order->appointment_status = 'attended';
        $order->status = 'completed';
        $order->attended_at = $now;
    }

    protected function notAttendAppointment(Order $order, Carbon $now, Carbon $start): void
    {
        if ($order->appointment_status !== 'confirmed' || $now->lt($start)) {
            abort(422, 'Atendimento ainda não ocorreu.');
        }
        $order->appointment_status = 'not_attended';
        $order->status = 'completed';
        $order->attended_at = $now;
    }

    /* ======================================================
     | LIST & VIEW
     ====================================================== */
    public function listByEntitySlug(string $slug)
    {
        try {
            $establishment = Establishment::with([
                'files' => fn($q) => $q->where('entity_name', 'establishment'),
            ])->where('slug', $slug)->firstOrFail();

            $logo =
                $establishment->files->firstWhere('type', 'logo')?->public_url
                ?? $establishment->logo
                ?? null;

            $background =
                $establishment->files->firstWhere('type', 'background')?->public_url
                ?? $establishment->background
                ?? null;

            $orders = Order::where('entity_name', 'establishment')
                ->where('entity_id', $establishment->id)
                ->with('items.item')
                ->orderBy('order_datetime')
                ->get();

            return response()->json([
                'message' => 'Pedidos listados com sucesso.',
                'establishment' => [
                    'id' => $establishment->id,
                    'name' => $establishment->name,
                    'fantasy' => $establishment->fantasy,
                    'slug' => $establishment->slug,
                    'city' => $establishment->city,
                    'uf' => $establishment->uf,
                    'logo' => $logo,
                    'background' => $background,
                ],
                'orders' => $orders,
            ]);
        } catch (\Throwable $e) {
            Log::error('Order.listByEntitySlug', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Erro ao listar pedidos.'], 500);
        }
    }


    public function listByEmployer(Request $request)
    {
        try {
            $employer = Employer::with([
                'user:id,first_name,last_name,user_name,avatar,email'
            ])
                ->where('user_id', $request->user()->id)
                ->firstOrFail();

            $orders = Order::where('attendant_id', $employer->id)
                ->with([
                    'items.item:id,name',
                    'client:id,first_name,last_name,user_name,avatar,email'
                ])
                ->orderBy('order_datetime')
                ->get()
                ->map(function ($order) {
                    return [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'type' => $order->type,
                        'appointment_status' => $order->appointment_status,
                        'scheduled_start' => $order->scheduled_start,
                        'scheduled_end' => $order->scheduled_end,
                        'created_at' => $order->created_at,
                        'total_price' => $order->total_price,

                        'customer' => $order->client ? [
                            'id' => $order->client->id,
                            'name' => trim($order->client->first_name . ' ' . $order->client->last_name),
                            'user_name' => $order->client->user_name,
                            'avatar' => $order->client->avatar,
                            'email' => $order->client->email,
                        ] : null,

                        'items' => $order->items->map(function ($item) {
                            return [
                                'id' => $item->id,
                                'name' => $item->item?->name,
                                'quantity' => $item->quantity,
                                'unit_price' => $item->unit_price,
                                'subtotal' => $item->subtotal,
                            ];
                        }),
                    ];
                });

            return response()->json([
                'message' => 'Pedidos do colaborador listados com sucesso.',
                'employer' => [
                    'id' => $employer->id,
                    'role' => $employer->role,
                    'user' => $employer->user,
                ],
                'orders' => $orders,
            ]);

        } catch (\Throwable $e) {
            Log::error('Order.listByEmployer', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Erro ao listar pedidos.'], 500);
        }
    }


    public function show(int $id)
    {
        try {
            $order = Order::with([
                'items.item',
                'items.modifiers.modifier',
                'client:id,first_name,last_name,user_name,avatar,email',
                'attendant.user:id,first_name,last_name,user_name,avatar,email',
            ])->findOrFail($id);

            return response()->json([
                'message' => 'Pedido carregado com sucesso.',
                'order' => $order,
            ]);

        } catch (\Throwable $e) {
            Log::error('Order.show', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Pedido não encontrado.'], 404);
        }
    }

    public function view(int $id)
    {
        return $this->show($id);
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
