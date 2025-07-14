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
            'app_id.required'        => 'O ID do aplicativo é obrigatório.',
            'app_id.exists'          => 'O ID do aplicativo deve existir.',
            'entity_name.required'   => 'O nome da entidade é obrigatório.',
            'entity_name.string'     => 'O nome da entidade deve ser uma string válida.',
            'entity_id.required'     => 'O ID da entidade é obrigatório.',
            'entity_id.integer'      => 'O ID da entidade deve ser um número inteiro.',
            'service_ids.required'   => 'A lista de itens é obrigatória.',
            'service_ids.array'      => 'Os itens devem ser enviados como lista.',
            'service_ids.*.integer'  => 'Cada ID de item deve ser um número inteiro.',
            'service_ids.*.exists'   => 'Um ou mais itens não existem.',
            'customer_name.required' => 'O nome do cliente é obrigatório.',
            'customer_name.string'   => 'O nome do cliente deve ser uma string válida.',
            'customer_phone.string'  => 'O telefone do cliente deve ser uma string válida.',
            'access_code.required'   => 'O código de acesso é obrigatório.',
            'access_code.string'     => 'O código de acesso deve ser uma string válida.',
            'payment_method.required'=> 'O método de pagamento é obrigatório.',
            'payment_method.in'      => 'O método de pagamento selecionado não é válido.',
            'notes.string'           => 'As observações devem ser uma string válida.',
        ];
    }

    public function store(Request $request)
    {
        try {
            if (!Auth::check()) {
                Log::warning('Tentativa sem autenticação de criar pedido.');
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $user = Auth::user();
            Log::info('Criando novo pedido por usuário autenticado.', ['user_id' => $user->id]);

            $data = $request->validate([
                'app_id'        => 'required|exists:applications,id',
                'entity_name'   => 'required|string|max:255',
                'entity_id'     => 'required|integer',
                'service_ids'   => 'required|array|min:1',
                'service_ids.*' => 'integer|exists:items,id',
                'customer_name' => 'required|string|max:255',
                'customer_phone'=> 'nullable|string|max:20',
                'access_code'   => 'required|string|max:20',
                'payment_method'=> 'required|string|in:Pix,Débito,Crédito,Dinheiro,Fiado,Cortesia,Transferência bancária,Vale-refeição,Cheque,PayPal',
                'notes'         => 'nullable|string|max:500',
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
                'payment_method' => $data['payment_method'],
                'total_price'    => 0,
                'status'         => 'pending',
                'notes'          => $data['notes'] ?? null,
            ]);

            $total = 0;
            foreach ($data['service_ids'] as $itemId) {
                $item     = Item::findOrFail($itemId);
                $subtotal = $item->price;
                $order->items()->create([
                    'item_id'    => $item->id,
                    'quantity'   => 1,
                    'unit_price' => $item->price,
                    'subtotal'   => $subtotal,
                ]);
                $total += $subtotal;
            }

            $order->update(['total_price' => $total, 'status' => 'approved']);
            Log::info('Pedido registrado com sucesso.', ['order_id' => $order->id]);

            // Monta nota dinâmica
            $est = Establishment::find($order->entity_id);
            $ename = $est ? $est->name : strtoupper($order->entity_name);
            $lines = [];
            $lines[] = str_repeat('█', 32);
            $lines[] = "        {$ename}";
            $lines[] = str_repeat('█', 32);
            $lines[] = '';
            $lines[] = "Pedido Nº: {$order->order_number}";
            $lines[] = '';
            $lines[] = "{$order->access_code} - Local";
            $lines[] = $order->order_datetime->format('d/m/Y H:i:s') . ' BRT';
            $lines[] = '';
            $lines[] = "Cliente: {$order->customer_name}";
            $lines[] = '';
            $lines[] = str_repeat('-', 32);
            $lines[] = '        ITENS DO PEDIDO';
            $lines[] = str_repeat('-', 32);
            foreach ($order->items as $oi) {
                $qty = $oi->quantity . 'x';
                $name = $oi->item->name;
                $sub  = number_format($oi->subtotal, 2, ',', '.');
                $combo = Str::contains(strtolower($oi->item->notes ?? ''), 'combo') ? ' (Combo)' : '';
                $lines[] = "{$qty} {$name}{$combo}";
                if (!empty($oi->item->notes) && !$combo) {
                    $lines[] = "  [{$oi->item->notes}]";
                }
            }
            $lines[] = str_repeat('-', 44);
            $tot = number_format($order->total_price, 2, ',', '.');
            $lines[] = str_pad('TOTAL', 32, '.') . "R\${$tot}";
            $receipt = implode("\n", $lines);

            return response()->json([
                'message' => 'Pedido registrado com sucesso!',
                'order'   => $order->load('items.item'),
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
}