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
            'app_id.required'         => 'O ID do aplicativo é obrigatório.',
            'app_id.exists'           => 'O ID do aplicativo deve existir.',
            'entity_name.required'    => 'O nome da entidade é obrigatório.',
            'entity_name.string'      => 'O nome da entidade deve ser uma string válida.',
            'entity_id.required'      => 'O ID da entidade é obrigatório.',
            'entity_id.integer'       => 'O ID da entidade deve ser um número inteiro.',

            'items.required'          => 'A lista de itens é obrigatória.',
            'items.array'             => 'Os itens devem ser enviados como lista.',
            'items.*.item_id.required'=> 'O ID do item é obrigatório.',
            'items.*.item_id.integer' => 'O ID do item deve ser um número inteiro.',
            'items.*.item_id.exists'  => 'O item informado não existe.',
            'items.*.quantity.required'=> 'A quantidade é obrigatória.',
            'items.*.quantity.integer'=> 'A quantidade deve ser um número inteiro.',
            'items.*.quantity.min'    => 'A quantidade mínima é 1.',
            'items.*.additions.array' => 'Adições devem ser enviadas como lista.',
            'items.*.additions.*.integer'=> 'O ID de adição deve ser inteiro.',
            'items.*.additions.*.exists' => 'O item adicional não existe.',
            'items.*.removals.array'  => 'Remoções devem ser enviadas como lista.',
            'items.*.removals.*.integer' => 'O ID de remoção deve ser inteiro.',
            'items.*.removals.*.exists'=> 'O item para remoção não existe.',

            'customer_name.required'  => 'O nome do cliente é obrigatório.',
            'customer_name.string'    => 'O nome do cliente deve ser uma string válida.',
            'customer_phone.string'   => 'O telefone do cliente deve ser uma string válida.',
            'access_code.required'    => 'O código de acesso é obrigatório.',
            'access_code.string'      => 'O código de acesso deve ser uma string válida.',

            'origin.required'         => 'A origem do pedido é obrigatória.',
            'origin.in'               => 'A origem deve ser WhatsApp, Balcão, Telefone ou App.',
            'fulfillment.required'    => 'O tipo de consumo é obrigatório.',
            'fulfillment.in'          => 'O consumo deve ser dine-in, take-away ou delivery.',
            'payment_status.required' => 'O status de pagamento é obrigatório.',
            'payment_status.in'       => 'O status de pagamento deve ser pending, paid ou failed.',

            'payment_method.required' => 'O método de pagamento é obrigatório.',
            'payment_method.in'       => 'O método de pagamento selecionado não é válido.',
            'notes.string'            => 'As observações devem ser uma string válida.',
        ];
    }

    public function store(Request $request)
    {
        try {
            if (!Auth::check()) {
                Log::warning('Usuário não autenticado tentou criar pedido.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();
            Log::info('Iniciando criação de pedido.', ['user_id' => $user->id]);

            $data = $request->validate([
                'app_id'          => 'required|exists:applications,id',
                'entity_name'     => 'required|string|max:255',
                'entity_id'       => 'required|integer',
                'items'           => 'required|array|min:1',
                'items.*.item_id' => 'required|integer|exists:items,id',
                'items.*.quantity'=> 'required|integer|min:1',
                'items.*.additions' => 'nullable|array',
                'items.*.additions.*' => 'integer|exists:items,id',
                'items.*.removals'  => 'nullable|array',
                'items.*.removals.*'  => 'integer|exists:items,id',
                'customer_name'   => 'required|string|max:255',
                'customer_phone'  => 'nullable|string|max:20',
                'access_code'     => 'required|string|max:20',
                'origin'          => 'required|string|in:WhatsApp,Balcão,Telefone,App',
                'fulfillment'     => 'required|string|in:dine-in,take-away,delivery',
                'payment_status'  => 'required|string|in:pending,paid,failed',
                'payment_method'  => 'required|string|in:Pix,Débito,Crédito,Dinheiro,Fiado,Cortesia,Transferência bancária,Vale-refeição,Cheque,PayPal',
                'notes'           => 'nullable|string|max:500',
            ], $this->getValidationMessages());

            $now    = Carbon::now('America/Sao_Paulo');
            $last   = Order::where('app_id', $data['app_id'])->max('order_number') ?: 0;
            $number = str_pad($last + 1, 3, '0', STR_PAD_LEFT);

            $order = Order::create([
                'app_id'         => $data['app_id'],
                'entity_name'    => $data['entity_name'],
                'entity_id'      => $data['entity_id'],
                'order_number'   => $number,
                'order_datetime' => $now,
                'attendant_id'   => $user->id,
                'client_id'      => null,
                'customer_name'  => $data['customer_name'],
                'customer_phone' => $data['customer_phone'],
                'access_code'    => $data['access_code'],
                'origin'         => $data['origin'],
                'fulfillment'    => $data['fulfillment'],
                'payment_status' => $data['payment_status'],
                'payment_method' => $data['payment_method'],
                'total_price'    => 0,
                'status'         => 'pending',
                'notes'          => $data['notes'] ?? null,
            ]);

            $total = 0;
            foreach ($data['items'] as $entry) {
                $item      = Item::findOrFail($entry['item_id']);
                $qty       = $entry['quantity'];
                $unitPrice = $item->price;
                $subtotal  = $unitPrice * $qty;

                $orderItem = $order->items()->create([
                    'item_id'    => $item->id,
                    'quantity'   => $qty,
                    'unit_price' => $unitPrice,
                    'subtotal'   => $subtotal,
                ]);

                // tratar adicionais e remoções
                if (!empty($entry['additions'])) {
                    foreach ($entry['additions'] as $addId) {
                        $orderItem->modifiers()->create([
                            'modifier_id' => $addId,
                            'type'        => 'addition',
                        ]);
                    }
                }
                if (!empty($entry['removals'])) {
                    foreach ($entry['removals'] as $remId) {
                        $orderItem->modifiers()->create([
                            'modifier_id' => $remId,
                            'type'        => 'removal',
                        ]);
                    }
                }

                $total += $subtotal;
            }

            $order->update(['total_price' => $total, 'status' => 'approved']);
            Log::info('Pedido registrado com sucesso.', ['order_id' => $order->id]);

            // Monta nota com origem, consumo e itens com modifiers
            $est   = Establishment::find($order->entity_id);
            $ename= $est ? $est->name : strtoupper($order->entity_name);
            $lines = [];
            $lines[] = str_repeat('█', 32);
            $lines[] = "        {$ename}";
            $lines[] = str_repeat('█', 32);
            $lines[] = '';
            $lines[] = "Pedido Nº: {$order->order_number}";
            $lines[] = '';
            $lines[] = "Origem: {$order->origin} | Consumo: {$order->fulfillment}";
            $lines[] = $order->order_datetime->format('d/m/Y H:i:s') . ' BRT';
            $lines[] = '';
            $lines[] = "Cliente: {$order->customer_name}";
            $lines[] = '';
            $lines[] = str_repeat('-', 32);
            $lines[] = '        ITENS DO PEDIDO';
            $lines[] = str_repeat('-', 32);
            foreach ($order->items as $oi) {
                $qty  = $oi->quantity . 'x';
                $name = $oi->item->name;
                $sub  = number_format($oi->subtotal,2,',','.');
                $lines[] = "{$qty} {$name} - R$sub";
                foreach ($oi->modifiers as $mod) {
                    $modName = Item::find($mod->modifier_id)->name;
                    $prefix  = $mod->type === 'addition' ? ' + ' : ' - ';
                    $lines[] = "   {$prefix}{$modName}";
                }
            }
            $lines[] = str_repeat('-', 44);
            $tot = number_format($order->total_price,2,',','.');
            $lines[] = str_pad('TOTAL',32,'.') . "R\${$tot}";
            $receipt = implode("\n", $lines);

            return response()->json([
                'message' => 'Pedido registrado com sucesso!',
                'order'   => $order->load('items.item','items.modifiers'),
                'receipt' => $receipt,
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
        if (! Auth::check()) {
            Log::warning('Usuário não autenticado tentou listar pedidos por entidade.');
            return response()->json(['error' => 'Usuário não autenticado.'], 401);
        }

        $user = Auth::user();
        // opcional: checar permissão, ex:
        // if (! $user->hasPermission('order_list')) {
        //     return response()->json(['error' => 'Você não tem permissão para listar pedidos.'], 403);
        // }

        $data = $request->validate([
            'app_id'       => 'required|integer|exists:applications,id',
            'entity_name'  => 'required|string|max:255',
            'entity_id'    => 'required|integer',
        ], $this->getValidationMessages());

        $orders = Order::where('app_id', $data['app_id'])
                       ->where('entity_name', $data['entity_name'])
                       ->where('entity_id', $data['entity_id'])
                       ->orderBy('order_datetime', 'desc')
                       ->with([
                           'items.item',
                           'items.modifiers.modifier'
                       ])
                       ->get();

        if ($orders->isEmpty()) {
            return response()->json(['message' => 'Nenhum pedido encontrado.'], 404);
        }

        return response()->json([
            'message' => 'Pedidos listados com sucesso.',
            'orders'  => $orders
        ], 200);
    } catch (ValidationException $e) {
        Log::warning('Erro de validação ao listar pedidos por entidade.', ['errors' => $e->errors()]);
        return response()->json(['errors' => $e->errors()], 422);
    } catch (\Exception $e) {
        Log::error('Erro ao listar pedidos por entidade: ' . $e->getMessage());
        return response()->json(['error' => 'Ocorreu um erro ao listar os pedidos.'], 500);
    }
}

}