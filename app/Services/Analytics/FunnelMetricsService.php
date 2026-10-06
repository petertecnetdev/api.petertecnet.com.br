<?php

namespace App\Services\Analytics;

use App\Models\Interaction;
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
                'deduplication' => 'app_id + período + session_key nas etapas; user_id na recorrência',
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
            'event_totals' => $events,
        ];
    }

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round($numerator / $denominator, 4) : 0.0;
    }
}
