<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CutinappCommissionController extends Controller
{
    private function eventOrFail(int $eventId): Event
    {
        return Event::query()->with('production')->findOrFail($eventId);
    }

    private function guardManage(Event $event): void
    {
        $userId = (int) auth()->id();
        $allowed = (int) optional($event->production)->user_id === $userId
            || DB::table('cutinapp_event_members')
                ->where('event_id', $event->id)
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->whereIn('role', ['producer', 'manager'])
                ->exists();
        abort_unless($allowed, 403, 'Você não pode gerenciar comissões deste evento.');
    }

    public function summary(int $eventId, int $promoterId)
    {
        $event = $this->eventOrFail($eventId);
        $this->guardManage($event);

        $promoter = DB::table('cutinapp_promoters')->where('event_id', $eventId)->find($promoterId);
        abort_unless($promoter, 404, 'Promoter não encontrado.');

        $rows = DB::table('cutinapp_commission_ledger')
            ->where('promoter_id', $promoterId)
            ->selectRaw('status, COUNT(*) entries, COALESCE(SUM(amount),0) total')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return response()->json([
            'promoter' => $promoter,
            'available' => (float) optional($rows->get('available'))->total,
            'paid' => (float) optional($rows->get('paid'))->total,
            'pending' => (float) optional($rows->get('pending'))->total,
            'entries' => DB::table('cutinapp_commission_ledger')->where('promoter_id', $promoterId)->latest()->get(),
        ]);
    }

    public function payout(Request $request, int $eventId, int $promoterId)
    {
        $event = $this->eventOrFail($eventId);
        $this->guardManage($event);

        $promoter = DB::table('cutinapp_promoters')->where('event_id', $eventId)->find($promoterId);
        abort_unless($promoter, 404, 'Promoter não encontrado.');

        $data = $request->validate([
            'payout_reference' => 'nullable|string|max:190',
        ]);

        $result = DB::transaction(function () use ($promoterId, $data) {
            $entries = DB::table('cutinapp_commission_ledger')
                ->where('promoter_id', $promoterId)
                ->where('status', 'available')
                ->lockForUpdate()
                ->get();

            abort_if($entries->isEmpty(), 422, 'Não há comissão disponível para pagamento.');

            $ids = $entries->pluck('id');
            $total = (float) $entries->sum('amount');
            $reference = $data['payout_reference'] ?? ('MANUAL-' . now()->format('YmdHis'));

            DB::table('cutinapp_commission_ledger')
                ->whereIn('id', $ids)
                ->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'payout_reference' => $reference,
                    'updated_at' => now(),
                ]);

            return ['amount' => $total, 'entries' => $ids->count(), 'payout_reference' => $reference];
        });

        return response()->json([
            'message' => 'Comissão registrada como paga.',
            'payout' => $result,
        ]);
    }
}
