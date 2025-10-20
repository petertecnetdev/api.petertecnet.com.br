<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Item;
use App\Models\Establishment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

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
        $user = Auth::user(); // pode ser null se não estiver logado
        Log::info('Iniciando criação de pedido.', ['user_id' => $user->id ?? null]);

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
            'attendant_id' => 'nullable|integer|exists:users,id',
        ], $this->getValidationMessages());

        $now = Carbon::now('America/Sao_Paulo');
        $orderDate = isset($data['order_datetime']) ? Carbon::parse($data['order_datetime']) : $now;

        if ($orderDate->lt($now)) {
            return response()->json(['error' => 'A data do pedido deve ser igual ou posterior à data atual.'], 422);
        }

        $lastNumber = Order::where('app_id', $data['app_id'])->max('order_number') ?: 0;
        $orderNumber = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
        $accessCode = str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        // Verifica conflitos de horário se for agendamento
        if ($orderDate->gt($now)) {
            foreach ($data['items'] as $entry) {
                $item = Item::findOrFail($entry['item_id']);
                $duration = $item->duration ?? 0;
                $conflict = Order::where('entity_id', $data['entity_id'])
                    ->where('status', 'scheduled')
                    ->where(function($q) use ($orderDate, $duration) {
                        $q->whereBetween('order_datetime', [$orderDate, $orderDate->copy()->addMinutes($duration)]);
                    })->exists();
                if ($conflict) {
                    return response()->json(['error' => "Conflito de horário para o serviço {$item->name}. Escolha outro horário."], 422);
                }
            }
        }

        $order = Order::create([
            'app_id' => $data['app_id'],
            'entity_name' => $data['entity_name'],
            'entity_id' => $data['entity_id'],
            'order_number' => $orderNumber,
            'order_datetime' => $orderDate,
            'attendant_id' => $data['attendant_id'] ?? $user->id ?? null,
            'client_id' => null,
            'customer_name' => $data['customer_name'],
            'access_code' => $accessCode,
            'origin' => $data['origin'],
            'fulfillment' => $data['fulfillment'],
            'payment_status' => $data['payment_status'],
            'payment_method' => $data['payment_method'],
            'total_price' => 0,
            'status' => $orderDate->gt($now) ? 'scheduled' : 'pending',
            'notes' => $data['notes'] ?? null,
            'customer_phone' => $data['customer_phone'] ?? null,
            'customer_cpf' => $data['customer_cpf'] ?? null,
        ]);

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
                    $addItem = Item::find($addId);
                    $total += $addItem->price * $qty;
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

        $order->update(['total_price' => $total]);
        Log::info('Pedido registrado com sucesso.', ['order_id' => $order->id]);

        return response()->json([
            'message' => 'Pedido registrado com sucesso!',
            'order' => $order->load('items.item', 'items.modifiers'),
        ], 201);

    } catch (ValidationException $e) {
        Log::warning('Erro de validação ao criar pedido.', ['errors' => $e->errors()]);
        return response()->json(['errors' => $e->errors()], 422);

    } catch (\Exception $e) {
        Log::error('Erro ao processar pedido: ' . $e->getMessage());
        return response()->json(['error' => 'Ocorreu um erro ao criar o pedido.'], 500);
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

        // Verifica se o usuário é dono ou colaborador da establishment
        $isOwnerOrStaff = \DB::table('employers')
            ->where('establishment_id', $data['entity_id'])
            ->where('user_id', $user->id)
            ->exists();

        if (!$isOwnerOrStaff) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }

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

        // Filtrar pedidos agendados, se solicitado
        if (!empty($data['include_scheduled'])) {
            $query->whereNotNull('scheduled_at');
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