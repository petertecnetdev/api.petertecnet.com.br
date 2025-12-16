<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Item;
use App\Models\Employer;
use App\Models\Establishment;
use App\Models\Interaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use App\Mail\NewAppointmentNotification;
use App\Mail\OwnerAppointmentNotification;
use App\Mail\AppointmentAwaitingConfirmation;

class OrderController extends Controller
{
    protected function getValidationMessages()
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

    public function store(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Usuário não autenticado.'], 401);
        }

        $mode = $request->input('mode');

        if ($mode === 'appointment') {
            return $this->storeAppointment($request);
        }

        if ($mode === 'direct') {
            return $this->storeDirect($request);
        }

        return response()->json(['error' => 'Modo de criação inválido.'], 422);
    }

    public function storeAppointment(Request $request)
<<<<<<< HEAD
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Usuário não autenticado.'], 401);
        }

        DB::beginTransaction();

        try {
            $user = Auth::user();
            $data = $this->validateOrder($request);

            $orderDate = Carbon::parse($data['order_datetime'])
                ->tz('America/Sao_Paulo')
                ->startOfMinute();

            if ($orderDate->lte(Carbon::now('America/Sao_Paulo')->startOfMinute())) {
                DB::rollBack();
                return response()->json(['error' => 'A data do agendamento deve ser futura.'], 422);
            }

            $employer = Employer::with('user')->find($data['attendant_id']);
            if (!$employer) {
                DB::rollBack();
                return response()->json(['error' => 'Colaborador não encontrado.'], 422);
            }

            $totalDuration = Item::totalDurationForItems($data['items']);
            $orderEnd = (clone $orderDate)->addMinutes($totalDuration);

            if (Order::hasScheduleConflict($data['attendant_id'], $orderDate, $orderEnd)) {
                DB::rollBack();
                return response()->json(['error' => 'Conflito de agenda.'], 422);
            }

            $order = Order::createOrder(
                $data,
                $user,
                $orderDate,
                $totalDuration,
                true,
                'appointment',
                'pending'
            );

            $order->attachItems($data['items']);

            DB::commit();

            $this->sendAppointmentEmails($order, $employer, $user);

            return response()->json([
                'message' => 'Agendamento registrado com sucesso!',
                'order' => $order->load('items.item'),
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Erro ao criar agendamento', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Erro interno ao criar o agendamento.'], 500);
        }
=======
{
    if (!Auth::check()) {
        return response()->json(['error' => 'UsuÃ¡rio nÃ£o autenticado.'], 401);
>>>>>>> develop
    }

    DB::beginTransaction();

    try {
        $user = Auth::user();

        $data = $this->validateOrder($request);

        $orderDate = Carbon::parse($data['order_datetime'])
            ->tz('America/Sao_Paulo')
            ->startOfMinute();

        if ($orderDate->lte(Carbon::now('America/Sao_Paulo')->startOfMinute())) {
            DB::rollBack();
            return response()->json(['error' => 'A data do agendamento deve ser futura.'], 422);
        }

        $employer = Employer::with('user')->find($data['attendant_id']);
        if (!$employer) {
            DB::rollBack();
            return response()->json(['error' => 'Colaborador nÃ£o encontrado.'], 422);
        }

        $totalDuration = Item::totalDurationForItems($data['items']);
        $orderEnd = (clone $orderDate)->addMinutes($totalDuration);

        if (Order::hasScheduleConflict($data['attendant_id'], $orderDate, $orderEnd)) {
            DB::rollBack();
            return response()->json(['error' => 'Conflito de agenda.'], 422);
        }

        $order = Order::create([
            'app_id' => $data['app_id'],
            'entity_name' => $data['entity_name'],
            'entity_id' => $data['entity_id'],
            'order_number' => Order::nextOrderNumber($data['app_id']),
            'order_datetime' => $orderDate,
            'created_by' => $user->id,
            'client_id' => $user->id,
            'attendant_id' => $data['attendant_id'],
            'customer_name' => trim($user->first_name . ' ' . ($user->last_name ?? '')),
            'origin' => $data['origin'],
            'fulfillment' => $data['fulfillment'],
            'payment_status' => $data['payment_status'],
            'payment_method' => $data['payment_method'],
            'status' => 'scheduled',
            'type' => 'appointment',
            'appointment_status' => 'pending',
            'total_price' => 0,
            'total_duration' => $totalDuration,
            'notes' => $data['notes'] ?? null,
        ]);

        $order->attachItems($data['items']);

        DB::commit();

        $this->sendAppointmentEmails($order, $employer, $user);

        return response()->json([
            'message' => 'Agendamento registrado com sucesso!',
            'order' => $order->load([
                'items.item',
                'client:id,first_name,last_name,user_name,avatar,email',
                'attendant.user:id,first_name,last_name,user_name,avatar,email',
            ]),
        ], 201);

    } catch (\Throwable $e) {
        DB::rollBack();

        Log::error('Erro ao criar agendamento', [
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'error' => 'Erro interno ao criar o agendamento.',
        ], 500);
    }
}

    public function storeDirect(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Usuário não autenticado.'], 401);
        }

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
            ]);

            $order = Order::create([
                'app_id' => $data['app_id'],
                'entity_name' => $data['entity_name'],
                'entity_id' => $data['entity_id'],
                'order_number' => Order::nextOrderNumber($data['app_id']),
                'order_datetime' => now(),
                'created_by' => Auth::id(),
                'attendant_id' => $data['attendant_id'],
                'customer_name' => $data['customer_name'],
                'origin' => $data['origin'],
                'fulfillment' => $data['fulfillment'],
                'payment_status' => $data['payment_status'],
                'payment_method' => $data['payment_method'],
                'type' => 'service',
                'status' => 'completed',
                'total_price' => 0,
                'total_duration' => 0,
            ]);

            $order->attachItems($data['items']);

            $total = 0;
            $order->load('items.modifiers');

            foreach ($order->items as $item) {
                $total += $item->subtotal;
                foreach ($item->modifiers as $modifier) {
                    $modifierItem = Item::find($modifier->modifier_id);
                    if ($modifierItem) {
                        $total += $modifierItem->price * ($modifier->quantity ?: 1);
                    }
                }
            }

            $order->update(['total_price' => $total]);

            DB::commit();

            return response()->json([
                'message' => 'Pedido criado com sucesso.',
                'order' => $order->load('items.item', 'items.modifiers.modifier'),
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Erro ao criar pedido direto', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Erro interno ao criar o pedido.'], 500);
        }
    }

    public function updateAppointmentStatus(Request $request, $id)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Usuário não autenticado.'], 401);
        }

        try {
            $data = $request->validate([
                'action' => 'required|in:confirm,cancel,attended,not_attended',
                'reason' => 'nullable|string|max:255',
            ]);

            $order = Order::findOrFail($id);
            $now = Carbon::now('America/Sao_Paulo');

            if ($order->type !== 'appointment') {
                return response()->json(['error' => 'Pedido não é um agendamento.'], 422);
            }

            switch ($data['action']) {
                case 'confirm':
                    $order->appointment_status = 'confirmed';
                    $order->status = 'scheduled';
                    break;

                case 'cancel':
                    $order->appointment_status = 'cancelled';
                    $order->status = 'cancelled';
                    $order->cancelled_reason = isset($data['reason']) ? $data['reason'] : null;
                    break;

                case 'attended':
                    $order->appointment_status = 'attended';
                    $order->status = 'completed';
                    $order->attended_at = $now;
                    break;

                case 'not_attended':
                    $order->appointment_status = 'not_attended';
                    $order->status = 'completed';
                    $order->attended_at = $now;
                    break;
            }

            $order->save();

            Interaction::create([
                'user_id' => Auth::id(),
                'entity_id' => $order->id,
                'entity_type' => 'order',
                'interaction_type' => 'AppointmentAction',
                'content' => json_encode($data),
            ]);

            return response()->json([
                'message' => 'Status do agendamento atualizado com sucesso.',
                'order' => $order,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Erro ao atualizar status do agendamento', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Erro interno.'], 500);
        }
    }

    private function validateOrder(Request $request)
    {
        return $request->validate([
            'app_id' => 'required|integer|exists:applications,id',
            'entity_name' => 'required|string',
            'entity_id' => 'required|integer',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer|exists:items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'customer_name' => 'required|string',
            'origin' => 'required|string',
            'fulfillment' => 'required|string',
            'payment_status' => 'required|string',
            'payment_method' => 'required|string',
            'order_datetime' => 'required|date',
            'attendant_id' => 'required|integer|exists:employers,id',
        ], $this->getValidationMessages());
    }

    private function sendAppointmentEmails($order, $employer, $user)
    {
        try {
            $establishment = Establishment::with('user')->find($order->entity_id);

            if ($user && $user->email) {
                Mail::to($user->email)
                    ->queue(new AppointmentAwaitingConfirmation($order, $establishment, $employer->user));
            }

            if ($employer && $employer->user && $employer->user->email) {
                Mail::to($employer->user->email)
                    ->queue(new NewAppointmentNotification($order, $establishment, $user));
            }

            if ($establishment && $establishment->user && $establishment->user->email) {
                Mail::to($establishment->user->email)
                    ->queue(new OwnerAppointmentNotification(
                        $order,
                        trim($establishment->user->first_name . ' ' . $establishment->user->last_name),
                        $user
                    ));
            }

        } catch (\Throwable $e) {
            Log::error('Erro ao enviar e-mails de agendamento', ['error' => $e->getMessage()]);
        }
    }
    
    
    
    
    
    public function listByEntitySlug(string $slug)
{
    try {
        $establishment = Establishment::where('slug', $slug)->first();

        if (!$establishment) {
            return response()->json(['error' => 'Estabelecimento não encontrado.'], 404);
        }

     $orders = Order::where('entity_name', 'establishment')
    ->where('entity_id', $establishment->id)
    ->with([
        'items.item',
    ])
    ->orderByDesc('order_datetime')
    ->get();



        return response()->json([
            'message' => 'Pedidos listados com sucesso.',
            'establishment' => [
                'id' => $establishment->id,
                'name' => $establishment->name,
                'fantasy' => $establishment->fantasy,
                'city' => $establishment->city,
                'uf' => $establishment->uf,
            ],
            'orders' => $orders,
        ]);

    } catch (\Throwable $e) {
        Log::error('Order.listByEntitySlug', [
            'slug' => $slug,
            'error' => $e->getMessage(),
        ]);

        return response()->json(['error' => 'Erro ao listar pedidos.'], 500);
    }
}

public function updateOrderStatus(Request $request, int $id)
{
    if (!Auth::check()) {
        return response()->json(['error' => 'UsuÃ¡rio nÃ£o autenticado.'], 401);
    }

    $data = $request->validate([
        'action' => 'required|in:confirm,cancel,attended,not_attended',
        'reason' => 'nullable|string|max:255',
    ]);

    DB::beginTransaction();

    try {
        $order = Order::lockForUpdate()->findOrFail($id);

        if ($order->type !== 'appointment') {
            DB::rollBack();
            return response()->json(['error' => 'AÃ§Ã£o permitida apenas para agendamentos.'], 422);
        }

        $now = Carbon::now('America/Sao_Paulo');
        $start = Carbon::parse($order->order_datetime)->tz('America/Sao_Paulo');
        $end = $start->copy()->addMinutes((int) $order->total_duration);

        switch ($data['action']) {

            case 'confirm':
                if ($order->appointment_status !== 'pending') {
                    DB::rollBack();
                    return response()->json(['error' => 'Apenas agendamentos pendentes podem ser confirmados.'], 422);
                }

                if ($now->gte($start)) {
                    DB::rollBack();
                    return response()->json([
                        'error' => 'NÃ£o Ã© possÃ­vel confirmar um agendamento apÃ³s o horÃ¡rio de inÃ­cio.'
                    ], 422);
                }

                $order->appointment_status = 'confirmed';
                $order->status = 'scheduled';
                break;

            case 'cancel':
                if (!in_array($order->appointment_status, ['pending', 'confirmed'])) {
                    DB::rollBack();
                    return response()->json([
                        'error' => 'Este agendamento nÃ£o pode mais ser cancelado.'
                    ], 422);
                }

                $order->appointment_status = 'cancelled';
                $order->status = 'cancelled';
                $order->cancelled_reason = $data['reason'] ?? null;
                break;

            case 'attended':
                if ($order->appointment_status !== 'confirmed') {
                    DB::rollBack();
                    return response()->json([
                        'error' => 'Somente agendamentos confirmados podem ser finalizados.'
                    ], 422);
                }

                if ($now->lt($end)) {
                    DB::rollBack();
                    return response()->json([
                        'error' => 'O atendimento sÃ³ pode ser finalizado apÃ³s o horÃ¡rio de tÃ©rmino.'
                    ], 422);
                }

                $order->appointment_status = 'attended';
                $order->status = 'completed';
                $order->attended_at = $now;
                break;

            case 'not_attended':
                if ($order->appointment_status !== 'confirmed') {
                    DB::rollBack();
                    return response()->json([
                        'error' => 'Somente agendamentos confirmados podem ser finalizados.'
                    ], 422);
                }

                if ($now->lt($start)) {
                    DB::rollBack();
                    return response()->json([
                        'error' => 'NÃ£o Ã© possÃ­vel finalizar um atendimento antes do horÃ¡rio agendado.'
                    ], 422);
                }

                $order->appointment_status = 'not_attended';
                $order->status = 'completed';
                $order->attended_at = $now;
                break;
        }

        $order->save();

        Interaction::create([
            'user_id' => Auth::id(),
            'entity_id' => $order->id,
            'entity_type' => 'order',
            'interaction_type' => 'AppointmentStatusUpdate',
            'content' => json_encode([
                'action' => $data['action'],
                'reason' => $data['reason'] ?? null,
            ]),
        ]);

        DB::commit();

        return response()->json([
            'message' => 'Status do pedido atualizado com sucesso.',
            'order' => $order->fresh(),
        ], 200);

    } catch (\Throwable $e) {
        DB::rollBack();

        Log::error('Order.updateOrderStatus error', [
            'order_id' => $id,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'error' => 'Erro ao atualizar o status do pedido.',
        ], 500);
    }
}

}