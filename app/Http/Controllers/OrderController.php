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
}
