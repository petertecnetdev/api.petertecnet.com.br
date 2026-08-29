<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Event;
use App\Models\Item;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CutinappCheckoutController extends Controller
{
    public function checkout(Request $request, int $eventId)
    {
        $event = Event::query()->with('production')->findOrFail($eventId);
        abort_if($event->is_cancelled || ! $event->is_published, 422, 'Evento indisponível.');

        $data = $request->validate([
            'buyer_name' => 'required|string|max:120',
            'buyer_email' => 'required|email|max:190',
            'buyer_document' => 'nullable|string|max:40',
            'buyer_phone' => 'nullable|string|max:30',
            'payment_method' => ['required', Rule::in(['pix','cash','free','external'])],
            'promoter_code' => 'nullable|string|max:40',
            'promotion_code' => 'nullable|string|max:60',
            'items' => 'required|array|min:1|max:50',
            'items.*.type' => ['required', Rule::in(['ticket','product'])],
            'items.*.id' => 'required|integer|min:1',
            'items.*.quantity' => 'required|integer|min:1|max:20',
        ]);

        $cutinappId = (int) Application::query()->where('slug', 'cutinapp')->value('id');
        abort_unless($cutinappId, 500, 'Aplicação Cutinapp não está cadastrada na API.');

        return DB::transaction(function () use ($data, $eventId, $cutinappId) {
            $subtotal = 0.0;
            $normalized = [];
            $reservationSince = now()->subMinutes(35);

            foreach ($data['items'] as $line) {
                if ($line['type'] === 'ticket') {
                    $ref = Ticket::query()->where('event_id', $eventId)->lockForUpdate()->findOrFail($line['id']);
                    abort_if($ref->limit_date && now()->gt($ref->limit_date), 422, "O lote {$ref->name} encerrou.");

                    $reserved = (int) DB::table('cutinapp_sale_items as si')
                        ->join('cutinapp_sales as s', 's.id', '=', 'si.sale_id')
                        ->where('s.event_id', $eventId)
                        ->where('s.payment_status', 'pending')
                        ->where('s.created_at', '>=', $reservationSince)
                        ->where('si.item_type', 'ticket')
                        ->where('si.reference_id', $ref->id)
                        ->sum('si.quantity');

                    $available = max((int) $ref->quantity - $reserved, 0);
                    abort_if($available < (int) $line['quantity'], 422, "Restam apenas {$available} unidades disponíveis para {$ref->name}.");
                } else {
                    $ref = Item::query()
                        ->where('app_id', $cutinappId)
                        ->where('entity_name', 'event')
                        ->where('entity_id', $eventId)
                        ->where('status', true)
                        ->lockForUpdate()
                        ->findOrFail($line['id']);

                    if ($ref->stock !== null) {
                        $reserved = (int) DB::table('cutinapp_sale_items as si')
                            ->join('cutinapp_sales as s', 's.id', '=', 'si.sale_id')
                            ->where('s.event_id', $eventId)
                            ->where('s.payment_status', 'pending')
                            ->where('s.created_at', '>=', $reservationSince)
                            ->where('si.item_type', 'product')
                            ->where('si.reference_id', $ref->id)
                            ->sum('si.quantity');

                        $available = max((int) $ref->stock - $reserved, 0);
                        abort_if($available < (int) $line['quantity'], 422, "Restam apenas {$available} unidades disponíveis para {$ref->name}.");
                    }
                }

                $unit = (float) $ref->price;
                $total = round($unit * (int) $line['quantity'], 2);
                $subtotal += $total;
                $normalized[] = [
                    'type' => $line['type'], 'id' => $ref->id, 'name' => $ref->name,
                    'quantity' => (int) $line['quantity'], 'unit_price' => $unit, 'total_price' => $total,
                ];
            }

            $promotion = null;
            $discount = 0.0;
            if (! empty($data['promotion_code'])) {
                $promotion = DB::table('cutinapp_promotions')
                    ->where('event_id', $eventId)
                    ->where('code', strtoupper(trim($data['promotion_code'])))
                    ->where('active', true)
                    ->lockForUpdate()
                    ->first();
                abort_unless($promotion, 422, 'Cupom inválido.');
                abort_if($promotion->starts_at && now()->lt($promotion->starts_at), 422, 'Cupom ainda não está ativo.');
                abort_if($promotion->ends_at && now()->gt($promotion->ends_at), 422, 'Cupom expirado.');
                abort_if($promotion->usage_limit && $promotion->used_count >= $promotion->usage_limit, 422, 'Limite do cupom atingido.');
                abort_if($promotion->minimum_amount && $subtotal < (float) $promotion->minimum_amount, 422, 'Valor mínimo do cupom não atingido.');
                $discount = $promotion->discount_type === 'percentage'
                    ? $subtotal * min((float) $promotion->discount_value, 100) / 100
                    : min((float) $promotion->discount_value, $subtotal);
                $discount = round($discount, 2);
            }

            $promoter = null;
            $commission = 0.0;
            if (! empty($data['promoter_code'])) {
                $promoter = DB::table('cutinapp_promoters')
                    ->where('event_id', $eventId)
                    ->where('code', strtoupper(trim($data['promoter_code'])))
                    ->where('active', true)
                    ->first();

                if ($promoter && (! $promoter->starts_at || now()->gte($promoter->starts_at)) && (! $promoter->ends_at || now()->lte($promoter->ends_at))) {
                    $base = max($subtotal - $discount, 0);
                    $commission = $promoter->commission_type === 'percentage'
                        ? $base * min((float) $promoter->commission_value, 100) / 100
                        : min((float) $promoter->commission_value, $base);
                    $commission = round($commission, 2);
                } else {
                    $promoter = null;
                }
            }

            $total = round(max($subtotal - $discount, 0), 2);
            $autoPaid = $total <= 0 || $data['payment_method'] === 'free';

            $saleId = DB::table('cutinapp_sales')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'event_id' => $eventId,
                'buyer_user_id' => auth()->id(),
                'promoter_id' => $promoter->id ?? null,
                'promotion_id' => $promotion->id ?? null,
                'buyer_name' => $data['buyer_name'],
                'buyer_email' => strtolower(trim($data['buyer_email'])),
                'buyer_document' => $data['buyer_document'] ?? null,
                'buyer_phone' => $data['buyer_phone'] ?? null,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'fee' => 0,
                'total' => $total,
                'commission_total' => $commission,
                'payment_method' => $data['payment_method'],
                'payment_status' => $autoPaid ? 'paid' : 'pending',
                'paid_at' => $autoPaid ? now() : null,
                'metadata' => json_encode(['reservation_expires_at' => now()->addMinutes(35)->toIso8601String()]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($normalized as $line) {
                DB::table('cutinapp_sale_items')->insert([
                    'sale_id' => $saleId,
                    'item_type' => $line['type'],
                    'reference_id' => $line['id'],
                    'name' => $line['name'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'total_price' => $line['total_price'],
                    'commission_amount' => $subtotal > 0 ? round($commission * ($line['total_price'] / $subtotal), 2) : 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($autoPaid) {
                $this->fulfillFreeSale($saleId);
            }

            return response()->json([
                'sale' => DB::table('cutinapp_sales')->find($saleId),
                'message' => $autoPaid ? 'Compra confirmada.' : 'Pedido criado. Aguardando confirmação do pagamento.',
            ], 201);
        });
    }

    private function fulfillFreeSale(int $saleId): void
    {
        $sale = DB::table('cutinapp_sales')->lockForUpdate()->find($saleId);
        if (! $sale || $sale->payment_status !== 'paid') return;

        $items = DB::table('cutinapp_sale_items')->where('sale_id', $saleId)->get();
        foreach ($items as $line) {
            if ($line->item_type === 'ticket') {
                $ticket = Ticket::query()->lockForUpdate()->findOrFail($line->reference_id);
                abort_if((int) $ticket->quantity < (int) $line->quantity, 409, 'Ingressos esgotados durante a emissão.');
                $ticket->decrement('quantity', $line->quantity);
                for ($i = 0; $i < $line->quantity; $i++) {
                    DB::table('cutinapp_admissions')->insert([
                        'token' => (string) Str::uuid(), 'sale_id' => $saleId, 'sale_item_id' => $line->id,
                        'event_id' => $sale->event_id, 'ticket_id' => $line->reference_id,
                        'owner_user_id' => $sale->buyer_user_id, 'holder_name' => $sale->buyer_name,
                        'holder_email' => $sale->buyer_email, 'status' => 'valid', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            } else {
                $item = Item::query()->lockForUpdate()->findOrFail($line->reference_id);
                if ($item->stock !== null) {
                    abort_if((int) $item->stock < (int) $line->quantity, 409, 'Produto sem estoque durante a emissão.');
                    $item->decrement('stock', $line->quantity);
                }
            }
        }

        if ($sale->promotion_id) DB::table('cutinapp_promotions')->where('id', $sale->promotion_id)->increment('used_count');
        if ($sale->promoter_id && (float) $sale->commission_total > 0) {
            DB::table('cutinapp_commission_ledger')->updateOrInsert(
                ['promoter_id' => $sale->promoter_id, 'sale_id' => $saleId],
                ['amount' => $sale->commission_total, 'status' => 'available', 'available_at' => now(), 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }
}
