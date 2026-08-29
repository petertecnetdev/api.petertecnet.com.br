<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Ticket;
use App\Services\EfiPixService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class CutinappPaymentController extends Controller
{
    public function createPix(Request $request, string $salePublicId, EfiPixService $efi)
    {
        $sale = DB::table('cutinapp_sales')->where('public_id', $salePublicId)->first();
        abort_unless($sale, 404, 'Venda não encontrada.');
        abort_unless((int) $sale->buyer_user_id === (int) auth()->id(), 403, 'Esta venda não pertence ao usuário autenticado.');
        abort_if($sale->payment_status === 'paid', 422, 'Esta venda já está paga.');
        abort_if(in_array($sale->payment_status, ['cancelled','refunded'], true), 422, 'Esta venda não pode ser paga.');
        abort_if((float) $sale->total <= 0, 422, 'Esta venda não requer pagamento.');

        if ($sale->payment_reference) {
            $metadata = json_decode($sale->metadata ?: '{}', true) ?: [];
            if (!empty($metadata['pix'])) {
                return response()->json(['sale' => $sale, 'pix' => $metadata['pix']]);
            }
        }

        try {
            $pix = $efi->createImmediateCharge(
                (float) $sale->total,
                $sale->buyer_name,
                $sale->buyer_document,
                'Cutinapp - pedido ' . $sale->public_id
            );
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $metadata = json_decode($sale->metadata ?: '{}', true) ?: [];
        $metadata['pix'] = $pix;

        DB::table('cutinapp_sales')->where('id', $sale->id)->update([
            'payment_method' => 'pix',
            'payment_reference' => $pix['txid'],
            'metadata' => json_encode($metadata),
            'updated_at' => now(),
        ]);

        return response()->json([
            'sale' => DB::table('cutinapp_sales')->find($sale->id),
            'pix' => $pix,
        ], 201);
    }

    public function status(string $salePublicId, EfiPixService $efi)
    {
        $sale = DB::table('cutinapp_sales')->where('public_id', $salePublicId)->first();
        abort_unless($sale, 404, 'Venda não encontrada.');
        abort_unless((int) $sale->buyer_user_id === (int) auth()->id(), 403, 'Esta venda não pertence ao usuário autenticado.');

        if ($sale->payment_status !== 'paid' && $sale->payment_reference) {
            try {
                $charge = $efi->getCharge($sale->payment_reference);
                if (($charge['status'] ?? null) === 'CONCLUIDA') {
                    $this->settleSale((int) $sale->id, $sale->payment_reference);
                    $sale = DB::table('cutinapp_sales')->find($sale->id);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'sale' => $sale,
            'metadata' => json_decode($sale->metadata ?: '{}', true),
        ]);
    }

    public function webhook(Request $request)
    {
        $expected = (string) config('services.efi.webhook_hmac');
        abort_if($expected === '' || !hash_equals($expected, (string) $request->query('hmac')), 401, 'Webhook não autorizado.');

        $notifications = $request->input('pix', []);
        foreach (is_array($notifications) ? $notifications : [] as $notification) {
            $txid = $notification['txid'] ?? null;
            if (!$txid) continue;

            $sale = DB::table('cutinapp_sales')->where('payment_reference', $txid)->first();
            if (!$sale || $sale->payment_status === 'paid') continue;

            $this->settleSale((int) $sale->id, $txid);
        }

        return response()->json(['received' => true]);
    }

    private function settleSale(int $saleId, ?string $reference = null): void
    {
        DB::transaction(function () use ($saleId, $reference) {
            $sale = DB::table('cutinapp_sales')->lockForUpdate()->find($saleId);
            if (!$sale || $sale->payment_status === 'paid') return;
            if (in_array($sale->payment_status, ['cancelled','refunded'], true)) return;

            $items = DB::table('cutinapp_sale_items')->where('sale_id', $saleId)->get();

            foreach ($items as $line) {
                if ($line->item_type === 'ticket') {
                    $ticket = Ticket::lockForUpdate()->findOrFail($line->reference_id);
                    abort_if((int) $ticket->quantity < (int) $line->quantity, 409, 'Ingressos esgotados durante a confirmação.');
                } else {
                    $item = Item::lockForUpdate()->findOrFail($line->reference_id);
                    abort_if($item->stock !== null && (int) $item->stock < (int) $line->quantity, 409, 'Produto sem estoque durante a confirmação.');
                }
            }

            DB::table('cutinapp_sales')->where('id', $saleId)->update([
                'payment_status' => 'paid',
                'payment_reference' => $reference ?: $sale->payment_reference,
                'paid_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($items as $line) {
                if ($line->item_type === 'ticket') {
                    Ticket::where('id', $line->reference_id)->decrement('quantity', $line->quantity);
                    for ($i = 0; $i < $line->quantity; $i++) {
                        DB::table('cutinapp_admissions')->insert([
                            'token' => (string) Str::uuid(),
                            'sale_id' => $saleId,
                            'sale_item_id' => $line->id,
                            'event_id' => $sale->event_id,
                            'ticket_id' => $line->reference_id,
                            'owner_user_id' => $sale->buyer_user_id,
                            'holder_name' => $sale->buyer_name,
                            'holder_email' => $sale->buyer_email,
                            'status' => 'valid',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                } else {
                    $item = Item::find($line->reference_id);
                    if ($item && $item->stock !== null) $item->decrement('stock', $line->quantity);
                }
            }

            if ($sale->promotion_id) {
                DB::table('cutinapp_promotions')->where('id', $sale->promotion_id)->increment('used_count');
            }

            if ($sale->promoter_id && (float) $sale->commission_total > 0) {
                DB::table('cutinapp_commission_ledger')->updateOrInsert(
                    ['promoter_id' => $sale->promoter_id, 'sale_id' => $saleId],
                    [
                        'amount' => $sale->commission_total,
                        'status' => 'available',
                        'available_at' => now(),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        });
    }
}
