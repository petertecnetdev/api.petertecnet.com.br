<?php

namespace App\Domain\Commerce\Http\Controllers;

use App\Domain\Commerce\Services\CommerceRefundService;
use App\Http\Controllers\Controller;
use App\Models\CommerceOrder;
use App\Models\CommerceRefund;
use App\Models\EventPass;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;

final class CommerceRefundController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CommerceRefundService $refunds,
    ) {}

    public function show(Request $request, string $publicId)
    {
        $order = $this->customerOrder($request, $publicId);
        return response()->json([
            'order' => $order->only(['id', 'public_id', 'status', 'currency', 'total', 'event_id']),
            'refund' => $order->refunds()->latest('id')->first(),
            'eligibility' => $this->eligibility($order),
        ]);
    }

    public function request(Request $request, string $publicId)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:2000']);
        $order = $this->customerOrder($request, $publicId);
        $eligibility = $this->eligibility($order);
        abort_unless($eligibility['eligible'], 422, $eligibility['message']);

        $refund = $this->refunds->requestFullRefund(
            $order,
            $request->user(),
            'customer_lifecycle_request',
            (int) $order->event_id,
            trim((string) ($data['reason'] ?? 'Solicitação do participante após alteração do evento.'))
        );

        return response()->json([
            'message' => $refund->status === 'completed'
                ? 'Reembolso concluído. O ingresso correspondente foi invalidado.'
                : 'Solicitação de reembolso registrada e protegida para acompanhamento.',
            'refund' => $refund,
            'order' => $order->fresh(['refunds']),
        ], $refund->status === 'completed' ? 200 : 202);
    }

    public function retry(Request $request, int $refundId)
    {
        $refund = CommerceRefund::query()
            ->where('app_id', $this->context->id())
            ->with('order.production')
            ->findOrFail($refundId);
        $order = $refund->order;
        $user = $request->user();
        $admin = $user && method_exists($user, 'hasProfile') && $user->hasProfile('Administrador');
        abort_unless($order && $order->production && ($admin || (int) $order->production->user_id === (int) $user->id), 403, 'Você não pode reprocessar este reembolso.');

        $updated = $this->refunds->process($refund);
        return response()->json([
            'message' => $updated->status === 'completed' ? 'Reembolso concluído.' : 'O reembolso continua exigindo acompanhamento.',
            'refund' => $updated,
        ], $updated->status === 'completed' ? 200 : 202);
    }

    private function customerOrder(Request $request, string $publicId): CommerceOrder
    {
        return CommerceOrder::query()
            ->where('app_id', $this->context->id())
            ->where('public_id', $publicId)
            ->where('user_id', $request->user()->id)
            ->with(['event', 'refunds', 'items'])
            ->firstOrFail();
    }

    private function eligibility(CommerceOrder $order): array
    {
        if ($order->status === 'refunded') return ['eligible' => false, 'message' => 'Este pedido já foi reembolsado.'];
        if (! in_array($order->status, ['paid', 'refund_pending'], true)) return ['eligible' => false, 'message' => 'Somente pedidos pagos podem ser reembolsados.'];
        if (! $order->event) return ['eligible' => false, 'message' => 'O evento deste pedido não foi encontrado.'];

        $event = $order->event;
        $lifecycle = $event->is_cancelled ? 'cancelled' : ($event->lifecycle_status ?: 'scheduled');
        if (! in_array($lifecycle, ['cancelled', 'postponed', 'rescheduled'], true)) {
            return ['eligible' => false, 'message' => 'Este evento não possui uma alteração que habilite reembolso por este fluxo.'];
        }

        if ($lifecycle !== 'cancelled') {
            if (! $event->refund_deadline_at) return ['eligible' => false, 'message' => 'A janela de reembolso deste evento não está aberta.'];
            if (now(config('app.timezone'))->gt($event->refund_deadline_at)) return ['eligible' => false, 'message' => 'A janela de solicitação de reembolso deste reagendamento foi encerrada.'];
        }

        $ticketItemIds = $order->items->where('type', 'ticket')->pluck('id');
        if ($ticketItemIds->isNotEmpty() && EventPass::query()->whereIn('commerce_order_item_id', $ticketItemIds)->whereNotNull('checked_in_at')->exists()) {
            return ['eligible' => false, 'message' => 'Ingressos já utilizados na entrada não podem ser reembolsados por este fluxo automático.'];
        }

        return [
            'eligible' => true,
            'message' => 'Este pedido pode solicitar reembolso.',
            'deadline_at' => $event->refund_deadline_at?->toIso8601String(),
            'lifecycle_status' => $lifecycle,
        ];
    }
}
