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
    DB::beginTransaction();

    try {
        $user = Auth::user();

        $data = $request->validate([
            'app_id' => 'required|exists:applications,id',
            'entity_name' => 'required|string|max:255',
            'entity_id' => 'required|integer',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer|exists:items,id',
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
        ]);

        // 🕒 Interpreta o horário como sendo do Brasil, não UTC
        $orderDate = Carbon::createFromFormat(
            'Y-m-d\TH:i:s',
            $data['order_datetime'],
            'America/Sao_Paulo'
        );

        // 🔁 Converte uma única vez para UTC (sem duplo deslocamento)
        $orderDateUtc = $orderDate->copy()->timezone('UTC');

        $now = Carbon::now('America/Sao_Paulo');
        $isScheduled = $orderDate->gt($now);
        $type = $isScheduled ? 'appointment' : 'service';
        $appointmentStatus = $isScheduled ? 'pending' : null;

        // 🧮 Calcula duração dos itens
        $totalDuration = 0;
        foreach ($data['items'] as $entry) {
            $item = \App\Models\Item::findOrFail($entry['item_id']);
            $totalDuration += $item->duration ?? 0;
        }

        $orderDateEnd = $orderDate->copy()->addMinutes($totalDuration);

        // 🚫 Conflitos de agendamento (mesmo colaborador e horário)
        $conflict = \App\Models\Order::where('attendant_id', $data['attendant_id'])
            ->where('type', 'appointment')
            ->whereIn('appointment_status', ['pending', 'confirmed'])
            ->whereBetween('order_datetime', [
                Carbon::parse($orderDate)->copy()->subMinutes($totalDuration)->timezone('UTC'),
                Carbon::parse($orderDateEnd)->timezone('UTC'),
            ])
            ->exists();

        if ($conflict) {
            DB::rollBack();
            return response()->json(['error' => 'O colaborador já possui um agendamento neste horário.'], 422);
        }

        // 🔢 Número e código
        $lastNumber = \App\Models\Order::where('app_id', $data['app_id'])->max('order_number') ?: 0;
        $orderNumber = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
        $accessCode = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        // 💾 Cria pedido
        $order = \App\Models\Order::create([
            'app_id' => $data['app_id'],
            'entity_name' => $data['entity_name'],
            'entity_id' => $data['entity_id'],
            'order_number' => $orderNumber,
            'order_datetime' => $orderDateUtc, // ✅ UTC correto (única conversão)
            'created_by' => $user->id ?? null,
            'attendant_id' => $data['attendant_id'],
            'customer_name' => $data['customer_name'],
            'customer_phone' => $data['customer_phone'] ?? null,
            'customer_cpf' => $data['customer_cpf'] ?? null,
            'access_code' => $accessCode,
            'origin' => $data['origin'],
            'fulfillment' => $data['fulfillment'],
            'payment_status' => $data['payment_status'],
            'payment_method' => $data['payment_method'],
            'status' => $isScheduled ? 'scheduled' : 'completed',
            'notes' => $data['notes'] ?? null,
            'type' => $type,
            'appointment_status' => $appointmentStatus,
            'total_price' => 0,
            'total_duration' => $totalDuration,
        ]);

        // 💰 Adiciona itens
        $total = 0;
        foreach ($data['items'] as $entry) {
            $item = \App\Models\Item::findOrFail($entry['item_id']);
            $subtotal = $item->price * $entry['quantity'];

            $order->items()->create([
                'item_id' => $item->id,
                'quantity' => $entry['quantity'],
                'unit_price' => $item->price,
                'subtotal' => $subtotal,
            ]);

            $total += $subtotal;
        }

        $order->update(['total_price' => $total]);

        DB::commit();

        return response()->json([
            'message' => 'Agendamento registrado com sucesso!',
            'order' => $order->load('items.item'),
        ], 201);

    } catch (\Throwable $e) {
        DB::rollBack();
        Log::error('🔥 Erro inesperado ao criar pedido.', [
            'message' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => $e->getFile(),
        ]);
        return response()->json([
            'error' => 'Erro interno ao criar o pedido.',
            'details' => $e->getMessage(),
        ], 500);
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

}