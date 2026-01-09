<?php

namespace App\Http\Controllers;

use App\Models\{Order, User, Item, Employer, Establishment};
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
            $authUser = $request->user();

            if (!$authUser) {
                return response()->json([
                    'message' => 'Usuário não autenticado.',
                ], 401);
            }

            $clientId = $authUser->id;

            if ($request->filled('client_id')) {
                if ((int) $request->client_id !== (int) $authUser->id) {
                    return response()->json([
                        'message' => 'Você não tem permissão para visualizar pedidos de outro cliente.',
                    ], 403);
                }

                $clientId = (int) $request->client_id;
            }

            if ($request->filled('user_name')) {
                if ($request->user_name !== $authUser->user_name) {
                    return response()->json([
                        'message' => 'Você não tem permissão para visualizar pedidos de outro cliente.',
                    ], 403);
                }

                $client = User::where('user_name', $request->user_name)->firstOrFail();
                $clientId = $client->id;
            }

            $query = Order::where('client_id', $clientId);

            if ($request->filled('start_date')) {
                $query->where(
                    'order_datetime',
                    '>=',
                    Carbon::parse($request->start_date)->startOfDay()
                );
            }

            if ($request->filled('end_date')) {
                $query->where(
                    'order_datetime',
                    '<=',
                    Carbon::parse($request->end_date)->endOfDay()
                );
            }

            if ($request->filled('establishment_id')) {
                $query->where('entity_name', 'establishment')
                    ->where('entity_id', (int) $request->establishment_id);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('appointment_status')) {
                $query->where('appointment_status', $request->appointment_status);
            }

            if ($request->filled('type')) {
                $query->where('type', $request->type);
            }

            if ($request->filled('payment_status')) {
                $query->where('payment_status', $request->payment_status);
            }

            if ($request->filled('attendant_id')) {
                $query->where('attendant_id', (int) $request->attendant_id);
            }

            $orders = $query
                ->with([
                    'items.item.files',
                    'items.modifiers.modifier',
                    'attendant.user.files',
                    'client.files',
                ])
                ->orderByDesc('order_datetime')
                ->get();

            return response()->json([
                'message' => 'Pedidos do cliente listados com sucesso.',
                'orders' => $orders,
            ]);
        } catch (\Throwable $e) {
            Log::error('Order.listByClient', [
                'auth_user_id' => $request->user()?->id,
                'params' => $request->all(),
                'exception' => $e,
            ]);

            return response()->json([
                'message' => 'Erro ao listar os pedidos do cliente.',
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
