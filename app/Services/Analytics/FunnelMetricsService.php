<?php

namespace App\Services\Analytics;

use App\Models\Interaction;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FunnelMetricsService
{
    private const STAGES = [
        'visita' => ['frontend_session_start', 'frontend_page_view', 'frontend_screen_view', 'session_start'],
        'cadastro' => ['frontend_signup', 'frontend_register', 'register'],
        'ativacao' => ['frontend_activation', 'frontend_first_value', 'activation'],
        'transacao' => ['frontend_purchase', 'frontend_checkout_complete', 'frontend_order_created', 'frontend_booking_created', 'purchase', 'order_created', 'booking_created'],
    ];

    private const CHECKOUT_STARTED = [
        'frontend_checkout_start',
        'frontend_checkout_started',
        'checkout_start',
        'checkout_started',
    ];

    private const CHECKOUT_COMPLETED = [
        'frontend_checkout_complete',
        'frontend_purchase',
        'frontend_order_created',
        'checkout_complete',
        'purchase',
        'order_created',
    ];

    public function summarize(int $appId, Carbon $from, Carbon $to): array
    {
        $base = Interaction::query()
            ->where('app_id', $appId)
            ->whereBetween('created_at', [$from, $to]);

        $stages = [];
        foreach (self::STAGES as $stage => $types) {
            $stages[$stage] = (clone $base)
                ->whereIn('interaction_type', $types)
                ->whereNotNull('session_key')
                ->distinct('session_key')
                ->count('session_key');
        }

        $repeatUsers = (clone $base)
            ->whereIn('interaction_type', self::STAGES['transacao'])
            ->whereNotNull('user_id')
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        $events = (clone $base)
            ->select('interaction_type', DB::raw('COUNT(*) as total'))
            ->groupBy('interaction_type')
            ->orderByDesc('total')
            ->pluck('total', 'interaction_type')
            ->map(fn ($total) => (int) $total);

        $stages['recorrencia'] = $repeatUsers;

        return [
            'application_id' => $appId,
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'definitions' => [
                'visita' => 'sessão única com session_start/page_view/screen_view',
                'cadastro' => 'sessão única com signup/register',
                'ativacao' => 'sessão única com activation/first_value',
                'transacao' => 'sessão única com purchase/order_created/booking_created/checkout_complete',
                'recorrencia' => 'usuários autenticados com mais de uma transação no período',
                'paid_order' => 'pedido da aplicação no período com payment_status=paid',
                'pending_order' => 'pedido da aplicação no período com payment_status=pending ou status=pending',
                'failed_payment' => 'pedido da aplicação no período com status de pagamento failed/error/declined/rejected',
                'checkout_abandonment' => 'sessões com checkout iniciado e sem evento de checkout concluído na mesma janela',
                'gmv' => 'soma de total_price dos pedidos pagos no período',
                'aov' => 'GMV dividido pela quantidade de pedidos pagos',
                'deduplication' => 'app_id + período + session_key nas etapas; user_id na recorrência; order id nos agregados financeiros; session_key no abandono',
            ],
            'funnel' => [
                'stages' => $stages,
                'rates' => [
                    'cadastro_from_visita' => $this->rate($stages['cadastro'], $stages['visita']),
                    'ativacao_from_cadastro' => $this->rate($stages['ativacao'], $stages['cadastro']),
                    'transacao_from_ativacao' => $this->rate($stages['transacao'], $stages['ativacao']),
                    'recorrencia_from_transacao' => $this->rate($stages['recorrencia'], $stages['transacao']),
                ],
            ],
            'revenue' => $this->revenue($appId, $from, $to),
            'event_totals' => $events,
        ];
    }

    private function revenue(int $appId, Carbon $from, Carbon $to): array
    {
        $orders = Order::query()
            ->where('app_id', $appId)
            ->whereBetween(DB::raw('COALESCE(order_datetime, created_at)'), [$from, $to]);

        $paid = (clone $orders)->where('payment_status', 'paid');
        $paidOrders = (clone $paid)->count();
        $gmv = (float) ((clone $paid)->sum('total_price') ?? 0);

        $pendingOrders = (clone $orders)
            ->where(function ($query) {
                $query->where('payment_status', 'pending')->orWhere('status', 'pending');
            })
            ->count();

        $failedPayments = (clone $orders)
            ->whereIn('payment_status', ['failed', 'error', 'declined', 'rejected'])
            ->count();

        $startedSessions = (clone $orders->getModel() ? Interaction::query() : Interaction::query())
            ->where('app_id', $appId)
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('interaction_type', self::CHECKOUT_STARTED)
            ->whereNotNull('session_key')
            ->distinct('session_key')
            ->count('session_key');

        $completedSessions = Interaction::query()
            ->where('app_id', $appId)
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('interaction_type', self::CHECKOUT_COMPLETED)
            ->whereNotNull('session_key')
            ->distinct('session_key')
            ->count('session_key');

        return [
            'paid_orders' => $paidOrders,
            'pending_orders' => $pendingOrders,
            'failed_payments' => $failedPayments,
            'checkout_started_sessions' => $startedSessions,
            'checkout_completed_sessions' => $completedSessions,
            'checkout_abandoned_sessions' => max(0, $startedSessions - $completedSessions),
            'gmv' => round($gmv, 2),
            'aov' => $paidOrders > 0 ? round($gmv / $paidOrders, 2) : 0.0,
        ];
    }

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round($numerator / $denominator, 4) : 0.0;
    }
}
