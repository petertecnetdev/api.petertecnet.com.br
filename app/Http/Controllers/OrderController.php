<?php

namespace App\Http\Controllers;

use App\Models\{Order, Item, EmployerSchedule};
use App\Models\Establishment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use App\Mail\{NewAppointmentNotification, OwnerAppointmentNotification, AppointmentAwaitingConfirmation};
use Illuminate\Support\Facades\Mail;


class OrderController extends Controller
{
    protected function getValidationMessages()
    {
        return [
            'app_id.required' => 'O ID do aplicativo é obrigatório.',
            'app_id.exists' => 'O ID do aplicativo deve existir.',
            'entity_name.required' => 'O nome da entidade é obrigatório.',
            'entity_name.string' => 'O nome da entidade deve ser uma string válida.',
            'entity_id.required' => 'O ID da entidade é obrigatório.',
            'entity_id.integer' => 'O ID da entidade deve ser um número inteiro.',

            'items.required' => 'A lista de itens é obrigatória.',
            'items.array' => 'Os itens devem ser enviados como lista.',
            'items.*.item_id.required' => 'O ID do item é obrigatório.',
            'items.*.item_id.integer' => 'O ID do item deve ser um número inteiro.',
            'items.*.item_id.exists' => 'O item informado não existe.',
            'items.*.quantity.required' => 'A quantidade é obrigatória.',
            'items.*.quantity.integer' => 'A quantidade deve ser um número inteiro.',
            'items.*.quantity.min' => 'A quantidade mínima é 1.',
            'items.*.additions.array' => 'Adições devem ser enviadas como lista.',
            'items.*.additions.*.integer' => 'O ID de adição deve ser inteiro.',
            'items.*.additions.*.exists' => 'O item adicional não existe.',
            'items.*.removals.array' => 'Remoções devem ser enviadas como lista.',
            'items.*.removals.*.integer' => 'O ID de remoção deve ser inteiro.',
            'items.*.removals.*.exists' => 'O item para remoção não existe.',

            'customer_name.required' => 'O nome do cliente é obrigatório.',
            'customer_name.string' => 'O nome do cliente deve ser uma string válida.',
            'customer_phone.string' => 'O telefone do cliente deve ser uma string válida.',
            'access_code.required' => 'O código de acesso é obrigatório.',
            'access_code.string' => 'O código de acesso deve ser uma string válida.',

            'origin.required' => 'A origem do pedido é obrigatória.',
            'origin.in' => 'A origem deve ser WhatsApp, Balcão, Telefone ou App.',
            'fulfillment.required' => 'O tipo de consumo é obrigatório.',
            'fulfillment.in' => 'O consumo deve ser dine-in, take-away ou delivery.',
            'payment_status.required' => 'O status de pagamento é obrigatório.',
            'payment_status.in' => 'O status de pagamento deve ser pending, paid ou failed.',

            'payment_method.required' => 'O método de pagamento é obrigatório.',
            'payment_method.in' => 'O método de pagamento selecionado não é válido.',
            'notes.string' => 'As observações devem ser uma string válida.',
        ];
    }
   public function store(Request $request)
{
    try {
        $user = Auth::user();
        Log::info('🟢 Iniciando criação de pedido.', ['user_id' => $user->id ?? null, 'payload' => $request->all()]);

        $data = $request->validate([
            'app_id' => 'required|exists:applications,id',
            'entity_name' => 'required|string|max:255',
            'entity_id' => 'required|integer',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer|exists:items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.additions' => 'nullable|array',
            'items.*.additions.*' => 'integer|exists:items,id',
            'items.*.removals' => 'nullable|array',
            'items.*.removals.*' => 'integer|exists:items,id',
            'customer_name' => 'required|string|max:255',
            'origin' => 'required|string|in:WhatsApp,Balcão,Telefone,App',
            'fulfillment' => 'required|string|in:dine-in,take-away,delivery',
            'payment_status' => 'required|string|in:pending,paid,failed',
            'payment_method' => 'required|string|in:Pix,Débito,Crédito,Dinheiro,Fiado,Cortesia,Transferência bancária,Vale-refeição,Cheque,PayPal',
            'notes' => 'nullable|string|max:500',
            'customer_phone' => 'nullable|string|max:20',
            'customer_cpf' => 'nullable|string|max:20',
            'order_datetime' => 'nullable|date',
            'attendant_id' => 'nullable|integer|exists:employers,id',
        ]);

        Log::info('✅ Validação concluída com sucesso.', ['data' => $data]);

        $now = Carbon::now('America/Sao_Paulo');
        $orderDate = isset($data['order_datetime']) ? Carbon::parse($data['order_datetime']) : $now;
        $isScheduled = isset($data['order_datetime']) && $orderDate->gt($now);
        $type = $isScheduled ? 'appointment' : 'service';
        $appointmentStatus = $isScheduled ? 'pending' : null;

        if ($isScheduled && $orderDate->lt($now)) {
            return response()->json(['error' => 'A data do agendamento deve ser futura.'], 422);
        }

        if (!empty($data['attendant_id'])) {
            $isEmployerOfEntity = \App\Models\Employer::where('id', $data['attendant_id'])
                ->where('establishment_id', $data['entity_id'])
                ->exists();

            if (!$isEmployerOfEntity) {
                return response()->json(['error' => 'O colaborador selecionado não pertence a este estabelecimento.'], 422);
            }
        }

        if ($isScheduled && !empty($data['attendant_id'])) {
            $employerId = $data['attendant_id'];
            $date = $orderDate->format('Y-m-d');
            $dayOfWeek = strtolower($orderDate->format('l'));
            $duration = 0;

            foreach ($data['items'] as $entry) {
                $item = \App\Models\Item::findOrFail($entry['item_id']);
                $duration += $item->duration ?? 0;
            }

            $schedules = \App\Models\EmployerSchedule::where('employer_id', $employerId)
                ->where('day_of_week', $dayOfWeek)
                ->where('is_active', true)
                ->get();

            if ($schedules->isEmpty()) {
                return response()->json(['error' => 'O colaborador não possui expediente neste dia.'], 422);
            }

            $appointments = \App\Models\Order::where('attendant_id', $employerId)
                ->whereDate('order_datetime', $date)
                ->whereIn('appointment_status', ['pending', 'confirmed'])
                ->get(['order_datetime', 'total_duration']);

            $occupied = [];
            foreach ($appointments as $a) {
                $start = Carbon::parse($a->order_datetime);
                $end = $start->copy()->addMinutes($a->total_duration ?? 0);
                $occupied[] = [$start, $end];
            }

            $slotStart = $orderDate->copy();
            $slotEnd = $slotStart->copy()->addMinutes($duration);

            $isInsideSchedule = $schedules->contains(function ($s) use ($date, $slotStart, $slotEnd) {
                $workStart = Carbon::parse("{$date} {$s->start_time}");
                $workEnd = Carbon::parse("{$date} {$s->end_time}");
                return $slotStart->gte($workStart) && $slotEnd->lte($workEnd);
            });

            if (!$isInsideSchedule) {
                return response()->json(['error' => 'O colaborador não atende neste horário.'], 422);
            }

            $hasConflict = collect($occupied)->contains(function ($occ) use ($slotStart, $slotEnd) {
                [$occStart, $occEnd] = $occ;
                return $slotStart->lt($occEnd) && $slotEnd->gt($occStart);
            });

            if ($hasConflict) {
                return response()->json(['error' => 'O colaborador já possui um agendamento neste horário.'], 422);
            }
        }

        $itemIds = collect($data['items'])->pluck('item_id');
        $invalidItems = Item::whereIn('id', $itemIds)
            ->where(function ($q) use ($data) {
                $q->where('entity_name', '!=', $data['entity_name'])
                    ->orWhere('entity_id', '!=', $data['entity_id']);
            })
            ->pluck('name')
            ->toArray();

        if (!empty($invalidItems)) {
            return response()->json([
                'error' => 'Um ou mais itens não pertencem a este estabelecimento.',
                'invalid_items' => $invalidItems,
            ], 422);
        }

        if ($isScheduled && !empty($data['attendant_id'])) {
            $totalDuration = 0;
            foreach ($data['items'] as $entry) {
                $item = Item::findOrFail($entry['item_id']);
                $totalDuration += $item->duration ?? 0;
            }

            $appointmentStart = $orderDate->copy();
            $appointmentEnd = $orderDate->copy()->addMinutes($totalDuration);

            $existingAppointments = Order::where('attendant_id', $data['attendant_id'])
                ->where('type', 'appointment')
                ->whereIn('appointment_status', ['pending', 'confirmed'])
                ->whereDate('order_datetime', $appointmentStart->format('Y-m-d'))
                ->orderBy('order_datetime')
                ->get();

            $hasConflict = $existingAppointments->contains(function ($a) use ($appointmentStart, $appointmentEnd) {
                $aStart = Carbon::parse($a->order_datetime);
                $aEnd = $aStart->copy()->addMinutes($a->total_duration ?? 0);
                return $appointmentStart->lt($aEnd) && $appointmentEnd->gt($aStart);
            });

            if ($hasConflict) {
                $dayOfWeek = strtolower($appointmentStart->format('l'));
                $schedules = EmployerSchedule::where('employer_id', $data['attendant_id'])
                    ->where('day_of_week', $dayOfWeek)
                    ->where('is_active', true)
                    ->orderBy('start_time')
                    ->get(['start_time', 'end_time']);

                if ($schedules->isEmpty()) {
                    return response()->json([
                        'error' => 'O colaborador não possui horários de atendimento neste dia.',
                    ], 422);
                }

                $suggestedTime = null;
                $dayDate = $appointmentStart->format('Y-m-d');

                foreach ($schedules as $schedule) {
                    $workStart = Carbon::parse("{$dayDate} {$schedule->start_time}");
                    $workEnd = Carbon::parse("{$dayDate} {$schedule->end_time}");

                    $candidate = $appointmentStart->copy();
                    if ($candidate->lt($workStart)) {
                        $candidate = $workStart->copy();
                    }

                    while ($candidate->lt($workEnd)) {
                        $candidateStart = $candidate->copy();
                        $candidateEnd = $candidateStart->copy()->addMinutes($totalDuration);

                        if ($candidateEnd->gt($workEnd)) {
                            break;
                        }

                        $conflict = $existingAppointments->contains(function ($a) use ($candidateStart, $candidateEnd) {
                            $aStart = Carbon::parse($a->order_datetime);
                            $aEnd = $aStart->copy()->addMinutes($a->total_duration ?? 0);
                            return $candidateStart->lt($aEnd) && $candidateEnd->gt($aStart);
                        });

                        if (!$conflict) {
                            $suggestedTime = $candidateStart->format('H:i');
                            break 2;
                        }

                        $nextConflict = $existingAppointments->filter(function ($a) use ($candidateStart, $candidateEnd) {
                            $aStart = Carbon::parse($a->order_datetime);
                            $aEnd = $aStart->copy()->addMinutes($a->total_duration ?? 0);
                            return $candidateStart->lt($aEnd);
                        })->sortBy('order_datetime')->first();

                        $candidate = $nextConflict
                            ? Carbon::parse($nextConflict->order_datetime)->addMinutes($nextConflict->total_duration + 5)
                            : $candidate->addMinutes(5);

                        if ($candidate->gte($workEnd)) {
                            $candidate = $workEnd->copy();
                            break;
                        }
                    }
                }

                if ($suggestedTime) {
                    return response()->json([
                        'error' => 'Conflito de horário detectado. O colaborador já possui outro agendamento neste intervalo.',
                        'suggestion' => "Horário mais próximo e compatível disponível: {$suggestedTime}.",
                        'tip' => 'Tente selecionar menos serviços ou aceitar o horário sugerido.',
                    ], 422);
                } else {
                    return response()->json([
                        'error' => 'Não há horários disponíveis neste dia compatíveis com a duração total dos serviços.',
                        'tip' => 'Escolha outro dia ou menos serviços.',
                    ], 422);
                }
            }
        }

        $lastNumber = Order::where('app_id', $data['app_id'])->max('order_number') ?: 0;
        $orderNumber = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
        $accessCode = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        $order = Order::create([
            'app_id' => $data['app_id'],
            'entity_name' => $data['entity_name'],
            'entity_id' => $data['entity_id'],
            'order_number' => $orderNumber,
            'order_datetime' => $orderDate,
            'created_by' => $user->id ?? null,
            'attendant_id' => $data['attendant_id'] ?? null,
            'client_id' => null,
            'customer_name' => $data['customer_name'],
            'customer_phone' => $data['customer_phone'] ?? null,
            'customer_cpf' => $data['customer_cpf'] ?? null,
            'access_code' => $accessCode,
            'origin' => $data['origin'],
            'fulfillment' => $data['fulfillment'],
            'payment_status' => $data['payment_status'],
            'payment_method' => $data['payment_method'],
            'total_price' => 0,
            'status' => $isScheduled ? 'scheduled' : 'completed',
            'notes' => $data['notes'] ?? null,
            'type' => $type,
            'appointment_status' => $appointmentStatus,
            'total_duration' => $totalDuration ?? 0,
        ]);

        $total = 0;
        foreach ($data['items'] as $entry) {
            $item = Item::findOrFail($entry['item_id']);
            $qty = $entry['quantity'];
            $unitPrice = $item->price;
            $subtotal = $unitPrice * $qty;

            $order->items()->create([
                'item_id' => $item->id,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'subtotal' => $subtotal,
            ]);

            $total += $subtotal;
        }

        $order->update(['total_price' => $total]);

        if ($isScheduled) {
            $establishment = \App\Models\Establishment::find($data['entity_id']);
            $attendant = \App\Models\Employer::with('user')->find($data['attendant_id']);
            $owner = $establishment ? $establishment->owner : null;
            $application = \App\Models\Application::find($data['app_id']);
            $appUrl = $application ? $application->url : null;

            $emails = [];
            if ($owner && $owner->email) $emails[] = $owner->email;
            if ($attendant && $attendant->user && $attendant->user->email) $emails[] = $attendant->user->email;
            if ($user && $user->email) $emails[] = $user->email;

            foreach (array_unique($emails) as $email) {
                Mail::to($email)->queue(new \App\Mail\NewAppointmentNotification($order, $appUrl));
            }
        }

        return response()->json([
            'message' => $isScheduled
                ? 'Agendamento registrado com sucesso! As notificações foram enviadas.'
                : 'Atendimento registrado com sucesso!',
            'order' => $order->load('items.item', 'items.modifiers'),
        ], 201);

    } catch (ValidationException $e) {
        return response()->json(['errors' => $e->errors()], 422);
    } catch (\Throwable $e) {
        Log::error('🔥 Erro inesperado ao processar pedido.', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'error' => 'Erro interno ao criar o pedido.',
            'details' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ], 500);
    }
}

    /**
     * Lista todos os pedidos de uma entidade (ex.: estabelecimento)
     */

    public function listByEntity(Request $request)
    {
        try {
            if (!Auth::check()) {
                Log::warning('Usuário não autenticado tentou listar pedidos por entidade.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();

            $data = $request->validate([
                'app_id' => 'required|integer|exists:applications,id',
                'entity_name' => 'required|string|max:255',
                'entity_id' => 'required|integer',
                'include_scheduled' => 'sometimes|boolean',
            ], $this->getValidationMessages());

            // Verifica se a entidade realmente existe
            $entityExists = \DB::table($data['entity_name'] . 's')
                ->where('id', $data['entity_id'])
                ->exists();

            if (!$entityExists) {
                return response()->json(['error' => ucfirst($data['entity_name']) . ' não encontrada.'], 404);
            }

            // Verifica se o usuário é dono da entidade
            $isOwner = \DB::table($data['entity_name'] . 's')
                ->where('id', $data['entity_id'])
                ->where('user_id', $user->id)
                ->exists();

            // Verifica se o usuário é colaborador do estabelecimento
            $isStaff = \DB::table('employers')
                ->where('establishment_id', $data['entity_id'])
                ->where('user_id', $user->id)
                ->whereIn('role', ['owner', 'gerente', 'Barbeiro', 'Barbeiro / Gerente'])
                ->exists();

            if (!$isOwner && !$isStaff) {
                $reason = $isOwner ? '' : 'Você não é dono da ' . $data['entity_name'] . ' nem colaborador autorizado.';
                return response()->json(['error' => 'Acesso negado. ' . $reason], 403);
            }

            // Monta a query dos pedidos
            $query = Order::with([
                'items.item',
                'items.modifiers.modifier',
                'creator:id,first_name,email,cpf',
                'attendant:id,first_name,email,cpf',
                'client:id,first_name,email,cpf',
            ])
                ->where('app_id', $data['app_id'])
                ->where('entity_name', $data['entity_name'])
                ->where('entity_id', $data['entity_id']);

            // Inclui apenas agendados, se solicitado
            if (!empty($data['include_scheduled']) && $data['include_scheduled'] === true) {
                $query->where('status', 'scheduled');
            }

            $orders = $query->orderBy('order_datetime', 'desc')->get();

            if ($orders->isEmpty()) {
                return response()->json(['message' => 'Nenhum pedido encontrado.'], 404);
            }

            return response()->json([
                'message' => 'Pedidos listados com sucesso.',
                'orders' => $orders,
            ], 200);

        } catch (ValidationException $e) {
            Log::warning('Erro de validação ao listar pedidos por entidade.', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);

        } catch (\Exception $e) {
            Log::error('Erro ao listar pedidos por entidade: ' . $e->getMessage());
            return response()->json(['error' => 'Ocorreu um erro ao listar os pedidos.'], 500);
        }
    }

    /**
     * Exibe um único pedido para impressão.
     */
    public function show($id)
    {
        try {
            if (!Auth::check()) {
                Log::warning('Usuário não autenticado tentou ver pedido.', ['order_id' => $id]);
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $order = Order::with([
                'items.item',
                'items.modifiers.modifier'
            ])->findOrFail($id);

            // Nome do estabelecimento ou entidade
            $est = Establishment::find($order->entity_id);
            $ename = $est ? $est->name : strtoupper($order->entity_name);

            // Monta receipt igual ao store
            $WIDTH = 42;
            $pad = fn($l, $r) => $l . str_repeat('.', max($WIDTH - (strlen($l) + strlen($r)), 0)) . $r;
            $fmt = fn($v) => 'R$' . number_format($v, 2, ',', '');

            $lines = [];
            $lines[] = str_repeat('█', $WIDTH);
            $lines[] = str_pad($ename, $WIDTH, ' ', STR_PAD_BOTH);
            $lines[] = str_repeat('█', $WIDTH);
            $lines[] = '';
            $lines[] = str_pad('ITENS DO PEDIDO', $WIDTH, ' ', STR_PAD_BOTH);
            $lines[] = str_repeat('-', $WIDTH);

            foreach ($order->items as $oi) {
                $lines[] = $pad($oi->quantity . 'x ' . $oi->item->name, $fmt($oi->subtotal));
                foreach ($oi->modifiers as $mod) {
                    $pref = $mod->type === 'addition' ? '+ ' : '- ';
                    $lines[] = $pref . $mod->modifier->name;
                }
            }

            $lines[] = str_repeat('-', $WIDTH);
            $lines[] = $pad('TOTAL', $fmt($order->total_price));
            $lines[] = '';
            $lines[] = 'Origem: ' . $order->origin . ' | Consumo: ' . $order->fulfillment;
            $lines[] = 'Data: ' . $order->order_datetime->format('d/m/Y H:i:s');
            $receipt = implode("\n", $lines);

            return response()->json([
                'order' => $order,
                'receipt' => $receipt,
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('Pedido não encontrado para impressão.', ['order_id' => $id]);
            return response()->json(['error' => 'Pedido não encontrado.'], 404);

        } catch (\Exception $e) {
            Log::error('Erro ao buscar pedido para impressão: ' . $e->getMessage());
            return response()->json(['error' => 'Erro ao recuperar pedido.'], 500);
        }
    }
    public function update(Request $request, $id)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }
            $user = Auth::user();

            $data = $request->validate([
                'app_id' => 'required|exists:applications,id',
                'entity_name' => 'required|string|max:255',
                'entity_id' => 'required|integer',
                'items' => 'required|array|min:1',
                'items.*.item_id' => 'required|integer|exists:items,id',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.additions' => 'nullable|array',
                'items.*.additions.*' => 'integer|exists:items,id',
                'items.*.removals' => 'nullable|array',
                'items.*.removals.*' => 'integer|exists:items,id',
                'customer_name' => 'required|string|max:255',
                'origin' => 'required|string|in:WhatsApp,Balcão,Telefone,App',
                'fulfillment' => 'required|string|in:dine-in,take-away,delivery',
                'payment_status' => 'required|string|in:pending,paid,failed,cancelled,refunded,partially_refunded',
                'payment_method' => 'required|string|in:Pix,Débito,Crédito,Dinheiro,Fiado,Cortesia,Transferência bancária,Vale-refeição,Cheque,PayPal',
                'notes' => 'nullable|string|max:500',
            ], $this->getValidationMessages());

            $order = Order::where('app_id', $data['app_id'])
                ->where('entity_name', $data['entity_name'])
                ->where('entity_id', $data['entity_id'])
                ->findOrFail($id);

            foreach ($order->items as $oi) {
                $oi->modifiers()->delete();
            }
            $order->items()->delete();

            $total = 0;
            foreach ($data['items'] as $entry) {
                $item = Item::findOrFail($entry['item_id']);
                $qty = $entry['quantity'];
                $unitPrice = $item->price;
                $subtotal = $unitPrice * $qty;

                $orderItem = $order->items()->create([
                    'item_id' => $item->id,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'subtotal' => $subtotal,
                ]);

                if (!empty($entry['additions'])) {
                    foreach ($entry['additions'] as $addId) {
                        $orderItem->modifiers()->create([
                            'modifier_id' => $addId,
                            'type' => 'addition',
                        ]);
                    }
                }
                if (!empty($entry['removals'])) {
                    foreach ($entry['removals'] as $remId) {
                        $orderItem->modifiers()->create([
                            'modifier_id' => $remId,
                            'type' => 'removal',
                        ]);
                    }
                }

                $total += $subtotal;
            }

            $order->update([
                'customer_name' => $data['customer_name'],
                'origin' => $data['origin'],
                'fulfillment' => $data['fulfillment'],
                'payment_status' => $data['payment_status'],
                'payment_method' => $data['payment_method'],
                'notes' => $data['notes'] ?? null,
                'total_price' => $total,
                'status' => 'approved',
            ]);

            $order->load('items.item', 'items.modifiers.modifier');

            return response()->json([
                'message' => 'Pedido atualizado com sucesso!',
                'order' => $order,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Ocorreu um erro ao atualizar o pedido.'], 500);
        }
    }

}