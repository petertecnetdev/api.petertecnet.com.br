<?php

namespace App\Http\Controllers;

use App\Models\{Order, Item, Employer};
use App\Models\Establishment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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
    Log::info('OrderController@store - início', [
        'mode' => $request->input('mode'),
        'user_id' => Auth::id(),
        'payload' => $request->all(),
    ]);

    if (!Auth::check()) {
        Log::warning('OrderController@store - usuário não autenticado', [
            'mode' => $request->input('mode'),
        ]);
        return response()->json(['error' => 'Usuário não autenticado.'], 401);
    }

    if ($request->input('mode') === 'appointment') {
        Log::info('OrderController@store - delegando para storeAppointment', [
            'user_id' => Auth::id(),
        ]);
        return $this->storeAppointment($request);
    }

    if ($request->input('mode') === 'direct') {
        Log::info('OrderController@store - delegando para storeDirect', [
            'user_id' => Auth::id(),
        ]);
        return $this->storeDirect($request);
    }

    Log::warning('OrderController@store - modo inválido', [
        'mode' => $request->input('mode'),
        'user_id' => Auth::id(),
    ]);

    return response()->json(['error' => 'Modo de criação inválido.'], 422);
}

public function storeAppointment(Request $request)
{
    if (!Auth::check()) {
        Log::warning('OrderController@storeAppointment - usuário não autenticado');
        return response()->json(['error' => 'Usuário não autenticado.'], 401);
    }

    DB::beginTransaction();

    try {
        $user = Auth::user();

        Log::info('OrderController@storeAppointment - início criação de agendamento', [
            'user_id' => $user->id,
            'payload' => $request->all(),
        ]);

        $data = $this->validateOrder($request);

        Log::info('OrderController@storeAppointment - payload validado', [
            'user_id' => $user->id,
            'data' => $data,
        ]);

        $orderDate = Carbon::parse($data['order_datetime'])
            ->tz('America/Sao_Paulo')
            ->startOfMinute();

        $now = Carbon::now('America/Sao_Paulo')->startOfMinute();

        Log::info('OrderController@storeAppointment - comparando datas', [
            'order_datetime' => $orderDate->toIso8601String(),
            'now' => $now->toIso8601String(),
        ]);

        if ($orderDate->lte($now)) {
            DB::rollBack();
            Log::warning('OrderController@storeAppointment - data/hora no passado ou igual ao agora', [
                'order_datetime' => $orderDate->toIso8601String(),
                'now' => $now->toIso8601String(),
                'user_id' => $user->id,
            ]);
            return response()->json([
                'error' => 'A data e hora do agendamento devem ser futuras em relação ao horário atual de Brasília.'
            ], 422);
        }

        $isScheduled = true;
        $type = 'appointment';
        $appointmentStatus = 'pending';

        Log::info('OrderController@storeAppointment - buscando colaborador', [
            'attendant_id' => $data['attendant_id'],
            'entity_id' => $data['entity_id'],
        ]);

        $employer = Employer::with('user')->find($data['attendant_id']);

        if (!$employer) {
            DB::rollBack();
            Log::warning('OrderController@storeAppointment - colaborador não encontrado', [
                'attendant_id' => $data['attendant_id'],
                'entity_id' => $data['entity_id'],
            ]);
            return response()->json(['error' => 'O colaborador selecionado não foi encontrado.'], 422);
        }

        if (!empty($employer->establishment_id) && (int)$data['entity_id'] !== (int)$employer->establishment_id) {
            Log::warning('OrderController@storeAppointment - ajustando entity_id para o establishment do colaborador', [
                'attendant_id' => $data['attendant_id'],
                'entity_id_enviado' => $data['entity_id'],
                'entity_id_employer' => $employer->establishment_id,
            ]);
            $data['entity_id'] = (int)$employer->establishment_id;
        }

        $totalDuration = Item::totalDurationForItems($data['items']);
        $orderDateEnd = $orderDate->copy()->addMinutes($totalDuration);

        Log::info('OrderController@storeAppointment - calculando duração e janela de horário', [
            'total_duration' => $totalDuration,
            'order_start' => $orderDate->toIso8601String(),
            'order_end' => $orderDateEnd->toIso8601String(),
            'attendant_id' => $data['attendant_id'],
        ]);

        if (Order::hasScheduleConflict($data['attendant_id'], $orderDate, $orderDateEnd)) {
            DB::rollBack();
            Log::warning('OrderController@storeAppointment - conflito de agenda detectado', [
                'attendant_id' => $data['attendant_id'],
                'start' => $orderDate->toIso8601String(),
                'end' => $orderDateEnd->toIso8601String(),
            ]);
            return response()->json(['error' => 'O colaborador já possui um agendamento neste horário.'], 422);
        }

        $itemIds = collect($data['items'])->flatMap(fn($i) => (array) $i['item_id'])->toArray();
        Log::info('OrderController@storeAppointment - validando itens do estabelecimento', [
            'item_ids' => $itemIds,
            'entity_name' => $data['entity_name'],
            'entity_id' => $data['entity_id'],
        ]);

        $invalidItems = Item::invalidForEntity($itemIds, $data['entity_name'], $data['entity_id']);

        if (!empty($invalidItems)) {
            DB::rollBack();
            Log::warning('OrderController@storeAppointment - itens inválidos para o estabelecimento', [
                'invalid_items' => $invalidItems,
                'entity_name' => $data['entity_name'],
                'entity_id' => $data['entity_id'],
            ]);
            return response()->json([
                'error' => 'Um ou mais itens não pertencem a este estabelecimento.',
                'invalid_items' => $invalidItems,
            ], 422);
        }

        Log::info('OrderController@storeAppointment - criando ordem', [
            'user_id' => $user->id,
            'is_scheduled' => $isScheduled,
            'type' => $type,
            'appointment_status' => $appointmentStatus,
        ]);

        $order = Order::createOrder(
            $data,
            $user,
            $orderDate,
            $totalDuration,
            $isScheduled,
            $type,
            $appointmentStatus
        );

        Log::info('OrderController@storeAppointment - ordem criada, anexando itens', [
            'order_id' => $order->id ?? null,
            'items' => $data['items'],
        ]);

        $order->attachItems($data['items']);

        Log::info('OrderController@storeAppointment - itens anexados, commit da transação', [
            'order_id' => $order->id ?? null,
        ]);

        DB::commit();

        Log::info('OrderController@storeAppointment - enviando e-mails de agendamento', [
            'order_id' => $order->id ?? null,
            'employer_id' => $employer->id,
            'user_id' => $user->id,
        ]);

        $this->sendAppointmentEmails($order, $employer, $user);

        Log::info('OrderController@storeAppointment - agendamento concluído com sucesso', [
            'order_id' => $order->id ?? null,
        ]);

        return response()->json([
            'message' => 'Agendamento registrado com sucesso!',
            'order' => $order->load('items.item'),
        ], 201);

    } catch (\Throwable $e) {
        DB::rollBack();
        Log::error('OrderController@storeAppointment - erro inesperado ao criar agendamento', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'user_id' => Auth::id(),
            'payload' => $request->all(),
        ]);
        return response()->json(['error' => 'Erro interno ao criar o agendamento.'], 500);
    }
}

public function storeDirect(Request $request)
{
    try {
        Log::info('OrderController@storeDirect - início criação de pedido direto', [
            'user_id' => auth()->id(),
            'payload' => $request->all(),
        ]);

        if (!auth()->check()) {
            Log::warning('OrderController@storeDirect - usuário não autenticado');
            return response()->json(['error' => 'Usuário não autenticado.'], 401);
        }

        $validated = $request->validate([
            'app_id' => 'required|integer',
            'entity_name' => 'required|string',
            'entity_id' => 'required|integer',
            'attendant_id' => 'required|integer',
            'customer_name' => 'required|string',
            'origin' => 'required|string',
            'fulfillment' => 'required|string',
            'payment_status' => 'required|string',
            'payment_method' => 'required|string',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.additions' => 'array',
            'items.*.additions.*.id' => 'required|integer',
            'items.*.additions.*.quantity' => 'required|integer|min:1',
            'items.*.removals' => 'array',
            'items.*.removals.*' => 'integer',
        ]);

        Log::info('OrderController@storeDirect - payload validado', [
            'user_id' => auth()->id(),
            'validated' => $validated,
        ]);

        $user = auth()->user();

        $order = \App\Models\Order::create([
            'app_id' => $validated['app_id'],
            'entity_name' => $validated['entity_name'],
            'entity_id' => $validated['entity_id'],
            'order_number' => \App\Models\Order::nextOrderNumber($validated['app_id']),
            'order_datetime' => now(),
            'created_by' => $user->id,
            'attendant_id' => $validated['attendant_id'],
            'customer_name' => $validated['customer_name'],
            'origin' => $validated['origin'],
            'fulfillment' => $validated['fulfillment'],
            'payment_status' => $validated['payment_status'],
            'payment_method' => $validated['payment_method'],
            'notes' => $validated['notes'] ?? null,
            'type' => 'service',
            'appointment_status' => null,
            'total_price' => 0,
            'total_duration' => 0,
            'status' => 'completed'
        ]);

        Log::info('OrderController@storeDirect - ordem criada, anexando itens', [
            'order_id' => $order->id ?? null,
            'items' => $validated['items'],
        ]);

        $order->attachItems($validated['items']);

        $total = 0;

        foreach ($order->items as $it) {
            $total += $it->subtotal;

            foreach ($it->modifiers as $m) {
                $modifierItem = \App\Models\Item::find($m->modifier_id);
                if ($modifierItem) {
                    $mult = $m->quantity ?? 1;
                    $total += ($modifierItem->price * $mult);
                }
            }
        }

        Log::info('OrderController@storeDirect - total calculado', [
            'order_id' => $order->id ?? null,
            'total' => $total,
        ]);

        $order->update(['total_price' => $total]);

        Log::info('OrderController@storeDirect - pedido direto concluído com sucesso', [
            'order_id' => $order->id ?? null,
        ]);

        return response()->json([
            'message' => 'Pedido criado com sucesso.',
            'order' => $order->load('items.item', 'items.modifiers.modifier')
        ], 201);

    } catch (\Throwable $e) {
        \Log::error('OrderController@storeDirect - erro ao criar pedido direto', [
            'err' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'user_id' => auth()->id(),
            'payload' => $request->all(),
        ]);

        return response()->json(['error' => $e->getMessage()], 500);
    }
}

    public function view($id)
    {
        try {
            $authUser = Auth::user();

            // 🔹 Carrega o pedido completo com todas as relações relevantes
            $order = \App\Models\Order::with([
                'items.item:id,name,slug,price,image,type',
                'items.modifiers.modifier:id,name,type',
                'creator:id,first_name,last_name,user_name,avatar,email',
                'client:id,first_name,last_name,user_name,avatar,email',
                'attendant.user:id,first_name,last_name,user_name,avatar,email',
                'entity:id,name,slug,logo,background,app_id'
            ])->findOrFail($id);

            // 🔹 Registra a visualização
            \App\Models\Interaction::registerView($order, $authUser);

            // 🔹 Limpa cache de métricas (se existir)
            Cache::forget("order_{$order->id}_metrics");
            Cache::forget("order_{$order->id}_summary");

            // 🔹 Obtém métricas e interações diretamente do model
            $metrics = $order->metrics();
            $interactionSummary = $order->fullInteractionsSummary();

            // 🔹 Retorno padronizado igual aos outros controllers
            return response()->json([
                'order' => $order,
                'entity' => $order->entity,
                'metrics' => $metrics,
                'interaction_summary' => $interactionSummary,
                'user_interactions' => $order->uniqueViewers()->get()->map(function ($view) {
                    $u = $view->user;
                    return [
                        'user_id' => $u?->id,
                        'user_name' => $u?->user_name,
                        'name' => trim(($u?->first_name ?? '') . ' ' . ($u?->last_name ?? '')),
                        'avatar' => $u?->avatar,
                        'email' => $u?->email,
                    ];
                }),
                'attendant' => $order->attendant?->user,
                'client' => $order->client,
                'establishment' => $order->establishment,
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            \Log::warning('[OrderController::view] Pedido não encontrado', ['order_id' => $id]);
            return response()->json(['error' => 'Pedido não encontrado.'], 404);

        } catch (\Throwable $e) {
            \Log::error('[OrderController::view] Erro ao carregar pedido', [
                'order_id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json(['error' => 'Erro ao carregar pedido.'], 500);
        }
    }

    private function validateOrder(Request $request)
    {
        return $request->validate([
            'app_id' => 'required|exists:applications,id',
            'entity_name' => 'required|string|max:255',
            'entity_id' => 'required|integer',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required',
            'items.*.item_id.*' => 'integer|exists:items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'customer_name' => 'required|string|max:255',
            'origin' => 'required|string|in:WhatsApp,Balcão,Telefone,App',
            'fulfillment' => 'required|string|in:dine-in,take-away,delivery',
            'payment_status' => 'required|string|in:pending,paid,failed',
            'payment_method' => 'required|string|max:255',
            'notes' => 'nullable|string|max:500',
            'customer_phone' => 'nullable|string|max:20',
            'customer_cpf' => 'nullable|string|max:20',
            'order_datetime' => 'required|date',
            'attendant_id' => 'required|integer|exists:employers,id',
            'client_id' => 'nullable|integer|exists:users,id',
        ]);
    }

    private function resolveOrderTiming(array $data)
    {
        $orderDate = Carbon::parse($data['order_datetime'])
            ->tz('America/Sao_Paulo') // força converter para o horário local do servidor
            ->startOfMinute();


        $now = Carbon::now('America/Sao_Paulo')->startOfMinute();

        $isScheduled = $orderDate->gt($now);
        $type = $isScheduled ? 'appointment' : 'service';
        $appointmentStatus = $isScheduled ? 'pending' : null;

        return [$orderDate, $isScheduled, $type, $appointmentStatus];
    }


    private function sendAppointmentEmails($order, $employer, $user)
    {
        try {
            $establishment = Establishment::with('user')->find($order->entity_id);
            $owner = $establishment?->user;
            $attendant = $employer->user;
            $clientEmail = $user?->email;

            if ($clientEmail) {
                Mail::to($clientEmail)
                    ->queue(new AppointmentAwaitingConfirmation($order, $establishment, $attendant));
            }

            if ($attendant?->email) {
                Mail::to($attendant->email)
                    ->queue(new NewAppointmentNotification($order, $establishment, $user));
            }

            if ($owner?->email) {
                $ownerName = trim("{$owner->first_name} {$owner->last_name}");
                Mail::to($owner->email)
                    ->queue(new OwnerAppointmentNotification($order, $ownerName, $user));
            }

            Log::info('📧 E-mails de agendamento enfileirados com sucesso.', [
                'order_id' => $order->id,
                'owner_email' => $owner?->email,
                'attendant_email' => $attendant?->email,
                'client_email' => $clientEmail,
            ]);
        } catch (\Throwable $ex) {
            Log::error('⚠️ Falha ao enviar e-mails de agendamento.', [
                'message' => $ex->getMessage(),
                'file' => $ex->getFile(),
                'line' => $ex->getLine(),
            ]);
        }
    }

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

            $tableName = strtolower($data['entity_name']);
            if (!str_ends_with($tableName, 's')) {
                $tableName .= 's';
            }

            // Verifica se a entidade existe
            $entityExists = \DB::table($tableName)
                ->where('id', $data['entity_id'])
                ->exists();

            if (!$entityExists) {
                return response()->json(['error' => ucfirst($data['entity_name']) . ' não encontrada.'], 404);
            }

            // Verifica se o usuário é dono da entidade
            $isOwner = \DB::table($tableName)
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

            // Query principal dos pedidos
            $query = Order::with([
                'items.item',
                'items.modifiers.modifier',
                'creator:id,first_name,last_name,email,cpf',
                'attendant.user:id,first_name,last_name,email,cpf',
                'client:id,first_name,last_name,email,cpf',
            ])
                ->where('app_id', $data['app_id'])
                ->where('entity_name', $data['entity_name'])
                ->where('entity_id', $data['entity_id']);

            // Filtro opcional: apenas agendados
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

        } catch (\Illuminate\Validation\ValidationException $e) {
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
    public function listByEmployer(Request $request)
    {
        try {
            Log::info('🔍 Iniciando listagem de pedidos por colaborador', [
                'request_data' => $request->all(),
                'user_id' => Auth::id(),
            ]);

            if (!Auth::check()) {
                Log::warning('🚫 Usuário não autenticado tentou listar pedidos do colaborador.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();
            Log::info('👤 Usuário autenticado', ['user' => $user->only(['id', 'first_name', 'email'])]);

            $data = $request->validate([
                'employer_id' => 'required|integer|exists:employers,id',
                'app_id' => 'required|integer|exists:applications,id',
                'include_scheduled' => 'sometimes|boolean',
            ], [
                'employer_id.required' => 'O ID do colaborador é obrigatório.',
                'employer_id.exists' => 'O colaborador informado não existe.',
                'app_id.required' => 'O ID do aplicativo é obrigatório.',
                'app_id.exists' => 'O aplicativo informado não existe.',
            ]);

            Log::info('✅ Dados validados com sucesso', ['data' => $data]);

            $employer = \App\Models\Employer::with('user')->find($data['employer_id']);
            Log::info('💈 Colaborador encontrado', ['employer' => $employer]);

            if (!$employer) {
                Log::warning('❌ Colaborador não encontrado', ['employer_id' => $data['employer_id']]);
                return response()->json(['error' => 'Colaborador não encontrado.'], 404);
            }

            $isOwner = Establishment::where('user_id', $user->id)
                ->where('id', $employer->establishment_id)
                ->exists();

            $isSelf = $user->id === $employer->user_id;

            Log::info('🔒 Verificação de acesso', [
                'is_owner' => $isOwner,
                'is_self' => $isSelf,
                'establishment_id' => $employer->establishment_id,
            ]);

            if (!$isOwner && !$isSelf) {
                Log::warning('🚫 Acesso negado ao listar pedidos do colaborador.', [
                    'auth_user_id' => $user->id,
                    'employer_user_id' => $employer->user_id,
                    'establishment_id' => $employer->establishment_id,
                ]);
                return response()->json(['error' => 'Acesso negado. Você não tem permissão para visualizar os pedidos deste colaborador.'], 403);
            }

            Log::info('🧾 Consultando pedidos do colaborador', [
                'app_id' => $data['app_id'],
                'employer_id' => $data['employer_id'],
            ]);

            $query = Order::with([
                'items.item',
                'items.modifiers.modifier',
                'creator:id,first_name,email,cpf',
                'attendant.user:id,first_name,email,cpf',
                'client:id,first_name,email,cpf',
            ])
                ->where('app_id', $data['app_id'])
                ->where('attendant_id', $data['employer_id']);

            if (!empty($data['include_scheduled']) && $data['include_scheduled'] === true) {
                $query->where('status', 'scheduled');
            }

            $orders = $query->orderBy('order_datetime', 'desc')->get();

            Log::info('📦 Total de pedidos encontrados', ['count' => $orders->count()]);

            if ($orders->isEmpty()) {
                Log::info('ℹ️ Nenhum pedido encontrado para este colaborador.', ['employer_id' => $data['employer_id']]);
                return response()->json(['message' => 'Nenhum pedido encontrado para este colaborador.'], 404);
            }

            $response = [
                'message' => 'Pedidos listados com sucesso.',
                'employer' => [
                    'id' => $employer->id,
                    'name' => $employer->user ? $employer->user->first_name . ' ' . $employer->user->last_name : null,
                    'email' => $employer->user->email ?? null,
                    'role' => $employer->role ?? 'Barbeiro',
                    'establishment_id' => $employer->establishment_id,
                ],
                'orders' => $orders,
            ];

            Log::info('✅ Resposta pronta para envio', [
                'response_summary' => [
                    'employer_id' => $employer->id,
                    'orders_count' => count($orders),
                ]
            ]);

            return response()->json($response, 200);

        } catch (ValidationException $e) {
            Log::warning('⚠️ Erro de validação ao listar pedidos do colaborador.', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('🔥 Erro ao listar pedidos do colaborador', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Ocorreu um erro ao listar os pedidos do colaborador.',
                'details' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }


    public function updateAppointmentStatus(Request $request, $id)
    {
        try {
            if (!Auth::check()) {
                Log::warning('🚫 Tentativa de atualizar agendamento sem autenticação.', ['order_id' => $id]);
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();
            $ip = $request->ip();
            $userAgent = $request->header('User-Agent');

            Log::info('🔧 Iniciando atualização de status de agendamento.', [
                'order_id' => $id,
                'user_id' => $user->id,
                'ip' => $ip,
                'user_agent' => $userAgent,
                'payload' => $request->all(),
            ]);

            $data = $request->validate([
                'action' => 'required|string|in:confirm,cancel,attended,not_attended',
                'reason' => 'nullable|string|max:255',
            ], [
                'action.required' => 'A ação é obrigatória.',
                'action.in' => 'A ação deve ser confirm, cancel, attended ou not_attended.',
            ]);

            $order = Order::with(['attendant', 'creator'])->findOrFail($id);

            Log::info('📋 Pedido localizado.', [
                'order_id' => $order->id,
                'appointment_status' => $order->appointment_status,
                'status' => $order->status,
                'type' => $order->type,
            ]);

            $isOwner = Establishment::where('id', $order->entity_id)
                ->where('user_id', $user->id)
                ->exists();

            $isAttendant = $order->attendant && $order->attendant->user_id === $user->id;
            $isClient = $order->created_by === $user->id;

            Log::info('🔐 Verificando permissões.', [
                'isOwner' => $isOwner,
                'isAttendant' => $isAttendant,
                'isClient' => $isClient,
            ]);

            if (!$isOwner && !$isAttendant && !$isClient) {
                Log::warning('🚫 Acesso negado ao atualizar status de agendamento.', [
                    'user_id' => $user->id,
                    'order_id' => $id,
                    'ip' => $ip,
                ]);
                return response()->json(['error' => 'Acesso negado.'], 403);
            }

            if ($order->type !== 'appointment') {
                Log::warning('⚠️ Tentativa de atualizar um pedido que não é agendamento.', ['order_id' => $id]);
                return response()->json(['error' => 'Somente agendamentos podem ser alterados por este método.'], 422);
            }

            $now = Carbon::now('America/Sao_Paulo');
            $orderDate = Carbon::parse($order->order_datetime);

            if (in_array($data['action'], ['attended', 'not_attended']) && $orderDate->gt($now)) {
                Log::warning('🚫 Tentativa de finalizar agendamento futuro.', [
                    'order_id' => $id,
                    'order_datetime' => $order->order_datetime,
                    'now' => $now,
                ]);
                return response()->json(['error' => 'Não é possível finalizar um atendimento futuro.'], 422);
            }

            switch ($data['action']) {
                case 'confirm':
                    if (!$isOwner && !$isAttendant) {
                        Log::warning('🚫 Cliente tentou confirmar agendamento.', ['order_id' => $id]);
                        return response()->json(['error' => 'Somente o colaborador ou o dono podem confirmar agendamentos.'], 403);
                    }
                    if ($order->appointment_status === 'cancelled') {
                        return response()->json(['error' => 'Não é possível confirmar um agendamento cancelado.'], 422);
                    }
                    $order->appointment_status = 'confirmed';
                    $order->status = 'scheduled';
                    $order->confirmed_by = $user->id;
                    $interactionType = 'ConfirmAppointment';
                    $interactionComment = 'Agendamento confirmado.';
                    break;

                case 'cancel':
                    $order->appointment_status = 'cancelled';
                    $order->status = 'cancelled';
                    $order->cancelled_by = $user->id;
                    $order->cancelled_reason = $data['reason'] ?? null;
                    $interactionType = 'CancelAppointment';
                    $interactionComment = 'Agendamento cancelado. Motivo: ' . ($data['reason'] ?? 'Não informado.');
                    break;

                case 'attended':
                    if ($order->appointment_status !== 'confirmed') {
                        return response()->json(['error' => 'Apenas agendamentos confirmados podem ser finalizados.'], 422);
                    }
                    $order->appointment_status = 'attended';
                    $order->status = 'completed';
                    $order->attended_by = $user->id;
                    $order->attended_at = $now;
                    $interactionType = 'FinishAppointment';
                    $interactionComment = 'Atendimento concluído com sucesso.';
                    break;

                case 'not_attended':
                    if ($order->appointment_status !== 'confirmed') {
                        return response()->json(['error' => 'Apenas agendamentos confirmados podem ser marcados como não atendidos.'], 422);
                    }
                    $order->appointment_status = 'not_attended';
                    $order->status = 'completed';
                    $order->attended_by = $user->id;
                    $order->attended_at = $now;
                    $interactionType = 'NotAttendedAppointment';
                    $interactionComment = 'Agendamento marcado como não atendido.';
                    break;
            }

            $order->save();

            // 🔍 Registrar histórico detalhado na tabela interactions
            try {
                $interaction = new \App\Models\Interaction();
                $interaction->user_id = $user->id;
                $interaction->entity_id = $order->id;
                $interaction->entity_type = 'order';
                $interaction->interaction_type = $interactionType ?? 'AppointmentAction';
                $interaction->comment = $interactionComment ?? null;
                $interaction->name = $user->first_name ?? 'Usuário';
                $interaction->content = json_encode([
                    'action' => $data['action'],
                    'reason' => $data['reason'] ?? null,
                    'previous_status' => $order->getOriginal('appointment_status'),
                    'new_status' => $order->appointment_status,
                    'ip' => $ip,
                    'user_agent' => $userAgent,
                    'timestamp' => now()->toDateTimeString(),
                ], JSON_UNESCAPED_UNICODE);
                $interaction->save();

                Log::info('🗂️ Interação registrada com sucesso.', [
                    'interaction_id' => $interaction->id,
                    'interaction_type' => $interactionType,
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                    'ip' => $ip,
                ]);
            } catch (\Throwable $ex) {
                Log::error('⚠️ Falha ao registrar interação.', [
                    'message' => $ex->getMessage(),
                    'file' => $ex->getFile(),
                    'line' => $ex->getLine(),
                ]);
            }

            return response()->json([
                'message' => 'Status do agendamento atualizado com sucesso.',
                'order' => $order->fresh(['attendant.user', 'creator', 'items.item']),
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('❌ Agendamento não encontrado para atualização.', ['order_id' => $id]);
            return response()->json(['error' => 'Agendamento não encontrado.'], 404);

        } catch (ValidationException $e) {
            Log::warning('⚠️ Erro de validação ao atualizar status de agendamento.', ['errors' => $e->errors()]);
            return response()->json(['errors' => $e->errors()], 422);

        } catch (\Throwable $e) {
            Log::error('🔥 Erro inesperado ao atualizar status de agendamento.', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => 'Erro interno ao atualizar o agendamento.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

public function listByEntitySlug(Request $request, $slug)
{
    try {
        if (!Auth::check()) {
            return response()->json(['error' => 'Usuário não autenticado.'], 401);
        }

        if (!$slug || !is_string($slug)) {
            return response()->json(['error' => 'Slug inválido.'], 422);
        }

        $data = $request->validate([
            'app_id' => 'required|integer|exists:applications,id',
            'include_scheduled' => 'sometimes|boolean',
        ], $this->getValidationMessages());

        $authUser = Auth::user();

        $establishment = Establishment::where('slug', $slug)
            ->with([
                'files' => fn ($q) =>
                    $q->where('entity_name', 'establishment')
                      ->where('type', 'logo'),
            ])
            ->first();

        if (!$establishment) {
            return response()->json(['error' => 'Estabelecimento não encontrado.'], 404);
        }

        $isOwner = $establishment->user_id === $authUser->id;

        $isStaff = Employer::where('establishment_id', $establishment->id)
            ->where('user_id', $authUser->id)
            ->whereIn('role', ['owner', 'gerente', 'Barbeiro', 'Barbeiro / Gerente'])
            ->exists();

        if (!$isOwner && !$isStaff) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }

        $logo =
            $establishment->files->first()?->public_url
            ?: $establishment->logo
            ?: null;

        $mappedEstablishment = [
            'id' => $establishment->id,
            'name' => $establishment->name,
            'fantasy' => $establishment->fantasy,
            'slug' => $establishment->slug,
            'city' => $establishment->city,
            'uf' => $establishment->uf,
            'logo' => $logo,
        ];

        $query = Order::with([
            'items.item:id,name,slug,price,type',
            'items.modifiers.modifier:id,name,type',
            'creator:id,first_name,last_name,user_name,avatar,email',
            'client:id,first_name,last_name,user_name,avatar,email',
            'attendant.user:id,first_name,last_name,user_name,avatar,email',
        ])
            ->where('app_id', $data['app_id'])
            ->where('entity_name', 'establishment')
            ->where('entity_id', $establishment->id);

        if (!empty($data['include_scheduled']) && $data['include_scheduled'] === true) {
            $query->where('status', 'scheduled');
        }

        $orders = $query
            ->orderByDesc('order_datetime')
            ->get()
            ->map(function ($order) {
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'type' => $order->type,
                    'status' => $order->status,
                    'appointment_status' => $order->appointment_status,
                    'order_datetime' => $order->order_datetime,
                    'total_price' => $order->total_price,
                    'origin' => $order->origin,
                    'fulfillment' => $order->fulfillment,
                    'payment_status' => $order->payment_status,
                    'payment_method' => $order->payment_method,
                    'customer_name' => $order->customer_name,
                    'attendant' => $order->attendant?->user ? [
                        'id' => $order->attendant->id,
                        'name' => trim(($order->attendant->user->first_name ?? '') . ' ' . ($order->attendant->user->last_name ?? '')),
                        'slug' => $order->attendant->user->user_name,
                        'avatar' => $order->attendant->user->avatar,
                    ] : null,
                    'items' => $order->items->map(function ($oi) {
                        return [
                            'id' => $oi->item->id,
                            'name' => $oi->item->name,
                            'slug' => $oi->item->slug,
                            'type' => $oi->item->type,
                            'quantity' => $oi->quantity,
                            'unit_price' => $oi->unit_price,
                            'subtotal' => $oi->subtotal,
                            'modifiers' => $oi->modifiers->map(function ($m) {
                                return [
                                    'id' => $m->modifier_id,
                                    'name' => $m->modifier?->name,
                                    'type' => $m->type,
                                    'quantity' => $m->quantity ?? 1,
                                ];
                            })->values(),
                        ];
                    })->values(),
                    'updated_at' => $order->updated_at,
                ];
            })
            ->values();

        if ($orders->isEmpty()) {
            return response()->json(['message' => 'Nenhum pedido encontrado.'], 404);
        }

        return response()->json([
            'message' => 'Pedidos listados com sucesso.',
            'establishment' => $mappedEstablishment,
            'orders' => $orders,
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json(['errors' => $e->errors()], 422);

    } catch (\Throwable $e) {
        \Log::error('[OrderController::listByEntitySlug]', [
            'slug' => $slug,
            'error' => $e->getMessage(),
        ]);

        return response()->json(['error' => 'Erro ao buscar pedidos.'], 500);
    }
}

    

}