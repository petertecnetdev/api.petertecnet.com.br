<?php

namespace App\Domain\MarketData\Services;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

final class AirdropOpportunityService
{
    private const BLOCKED_ACTIONS = ['wash_trading', 'fake_volume', 'self_trade', 'leverage_churn'];

    public function campaigns(int $userId): array
    {
        $progress = $this->progress($userId);

        return array_map(function (array $campaign) use ($progress) {
            $completed = $progress[$campaign['slug']] ?? [];
            $campaign['completed_tasks'] = count(array_intersect(array_column($campaign['tasks'], 'id'), array_keys($completed)));
            $campaign['task_count'] = count($campaign['tasks']);
            $campaign['progress_pct'] = $campaign['task_count'] > 0
                ? (int) round(($campaign['completed_tasks'] / $campaign['task_count']) * 100)
                : 0;
            $campaign['eligible_now'] = $campaign['progress_pct'] === 100;

            return $campaign;
        }, $this->catalog());
    }

    public function campaign(int $userId, string $slug): array
    {
        foreach ($this->campaigns($userId) as $campaign) {
            if ($campaign['slug'] === $slug) {
                $completed = $this->progress($userId)[$slug] ?? [];
                $campaign['tasks'] = array_map(function (array $task) use ($completed) {
                    $task['completed'] = isset($completed[$task['id']]);
                    $task['completed_at'] = $completed[$task['id']]['completed_at'] ?? null;
                    $task['reference'] = $completed[$task['id']]['reference'] ?? null;

                    return $task;
                }, $campaign['tasks']);

                return $campaign;
            }
        }

        throw new InvalidArgumentException('Campanha de airdrop não encontrada.');
    }

    public function plan(float $capitalUsdt, string $riskProfile = 'moderado'): array
    {
        $capitalUsdt = max(0, $capitalUsdt);
        $reservePct = match ($riskProfile) {
            'conservador' => 0.45,
            'agressivo' => 0.20,
            default => 0.30,
        };

        $reserve = round($capitalUsdt * $reservePct, 2);
        $deployable = round(max(0, $capitalUsdt - $reserve), 2);
        $feeBudget = round(min($deployable * 0.08, max(2, $capitalUsdt * 0.04)), 2);
        $taskBudget = round(max(0, $deployable - $feeBudget), 2);

        return [
            'capital_usdt' => round($capitalUsdt, 2),
            'risk_profile' => $riskProfile,
            'reserve_usdt' => $reserve,
            'deployable_usdt' => $deployable,
            'fee_budget_usdt' => $feeBudget,
            'task_budget_usdt' => $taskBudget,
            'max_single_task_usdt' => round($taskBudget * ($riskProfile === 'agressivo' ? 0.35 : 0.25), 2),
            'guardrails' => [
                'no_guaranteed_return' => true,
                'explicit_confirmation_required' => true,
                'blocked_actions' => self::BLOCKED_ACTIONS,
            ],
            'disclaimer' => 'Estimativa operacional. Airdrops, preços de lançamento e recompensas não são garantidos.',
        ];
    }

    public function completeTask(int $userId, string $slug, string $taskId, ?string $reference = null): array
    {
        $campaign = $this->campaign($userId, $slug);
        $task = collect($campaign['tasks'])->firstWhere('id', $taskId);

        if (!$task) {
            throw new InvalidArgumentException('Tarefa de airdrop não encontrada.');
        }

        if (in_array($task['action'], self::BLOCKED_ACTIONS, true) || ($task['automation_allowed'] ?? false) === false && ($task['requires_market_manipulation'] ?? false)) {
            throw new InvalidArgumentException('Esta ação não pode ser automatizada porque pode simular atividade, volume ou negociação artificial.');
        }

        $key = $this->progressKey($userId);
        $progress = Cache::get($key, []);
        $progress[$slug][$taskId] = [
            'completed_at' => now()->toIso8601String(),
            'reference' => $reference,
        ];
        Cache::forever($key, $progress);

        return $this->campaign($userId, $slug);
    }

    public function actionPolicy(string $action): array
    {
        $blocked = in_array($action, self::BLOCKED_ACTIONS, true);

        return [
            'action' => $action,
            'allowed' => !$blocked,
            'requires_confirmation' => !$blocked,
            'reason' => $blocked
                ? 'A plataforma bloqueia ações destinadas a criar volume, atividade ou exposição artificial.'
                : 'Ação permitida somente quando fizer parte de uma campanha legítima e for confirmada pelo usuário.',
        ];
    }

    public function intelligence(int $userId, float $capitalUsdt = 280, string $riskProfile = 'moderado'): array
    {
        $campaigns = array_map(function (array $campaign) use ($capitalUsdt) {
            $opportunity = (int) ($campaign['opportunity_score'] ?? 0);
            $risk = (int) ($campaign['risk_score'] ?? 100);
            $fees = (float) ($campaign['estimated_fees_usdt'] ?? 0);
            $capitalFit = $capitalUsdt >= (float) ($campaign['capital_min_usdt'] ?? 0);
            $efficiency = max(0, min(100, round($opportunity - ($risk * .45) - ($fees * .65))));
            $confidence = (int) max(0, min(100, $campaign['confidence_score'] ?? (100 - round($risk * .35))));

            return array_merge($campaign, [
                'capital_fit' => $capitalFit,
                'efficiency_score' => $efficiency,
                'confidence_score' => $confidence,
                'decision' => $opportunity >= 80 && $risk <= 45 ? 'prioritize' : ($opportunity >= 65 ? 'watch' : 'low_priority'),
            ]);
        }, $this->campaigns($userId));

        usort($campaigns, fn (array $a, array $b) => ($b['efficiency_score'] <=> $a['efficiency_score']));
        $compatible = array_values(array_filter($campaigns, fn (array $campaign) => $campaign['capital_fit']));

        return [
            'generated_at' => now()->toIso8601String(),
            'capital_usdt' => round(max(0, $capitalUsdt), 2),
            'risk_profile' => $riskProfile,
            'plan' => $this->plan($capitalUsdt, $riskProfile),
            'best' => $campaigns[0] ?? null,
            'compatible_count' => count($compatible),
            'estimated_fees_usdt' => round(array_sum(array_column($compatible, 'estimated_fees_usdt')), 2),
            'live_count' => count(array_filter($campaigns, fn (array $campaign) => ($campaign['status'] ?? 'demo') !== 'demo')),
            'demo_count' => count(array_filter($campaigns, fn (array $campaign) => ($campaign['status'] ?? null) === 'demo')),
            'campaigns' => $campaigns,
            'guardrails' => [
                'read_only_wallet_default' => true,
                'source_required_for_live_status' => true,
                'blocked_actions' => self::BLOCKED_ACTIONS,
            ],
        ];
    }

    public function walletEligibility(int $userId, string $address): array
    {
        $address = trim($address);
        if ($address === '' || strlen($address) > 255) {
            throw new InvalidArgumentException('Endereço público de carteira inválido.');
        }

        return [
            'address' => $address,
            'mode' => 'read_only',
            'checked_at' => now()->toIso8601String(),
            'provider_status' => 'not_configured',
            'eligible_campaigns' => [],
            'message' => 'Carteira registrada em modo somente leitura. A verificação on-chain automática exige um provedor RPC/indexador configurado; nenhuma elegibilidade foi presumida.',
            'campaigns' => array_map(fn (array $campaign) => [
                'slug' => $campaign['slug'],
                'project' => $campaign['project'],
                'status' => 'unverified',
            ], $this->campaigns($userId)),
        ];
    }

    public function watchlist(int $userId): array
    {
        $slugs = Cache::get($this->watchlistKey($userId), []);
        return array_values(array_filter($this->campaigns($userId), fn (array $campaign) => in_array($campaign['slug'], $slugs, true)));
    }

    public function setWatchlist(int $userId, string $slug, bool $saved): array
    {
        $this->campaign($userId, $slug);
        $key = $this->watchlistKey($userId);
        $slugs = array_values(array_unique(Cache::get($key, [])));
        $slugs = $saved ? array_values(array_unique([...$slugs, $slug])) : array_values(array_filter($slugs, fn (string $item) => $item !== $slug));
        Cache::forever($key, $slugs);

        return $this->watchlist($userId);
    }

    public function calendar(int $userId): array
    {
        return array_values(array_map(fn (array $campaign) => [
            'slug' => $campaign['slug'],
            'project' => $campaign['project'],
            'network' => $campaign['network'],
            'status' => $campaign['status'],
            'snapshot_at' => $campaign['snapshot_at'] ?? null,
            'deadline_at' => $campaign['deadline_at'] ?? null,
            'tge_status' => $campaign['tge_status'] ?? 'unknown',
            'claim_status' => $campaign['claim_status'] ?? 'unknown',
        ], $this->campaigns($userId)));
    }

    private function progress(int $userId): array
    {
        return Cache::get($this->progressKey($userId), []);
    }

    private function progressKey(int $userId): string
    {
        return 'market:airdrops:progress:user:'.$userId;
    }

    private function watchlistKey(int $userId): string
    {
        return 'market:airdrops:watchlist:user:'.$userId;
    }

    private function catalog(): array
    {
        return [
            [
                'slug' => 'starter-onchain-route',
                'project' => 'Rota On-chain',
                'network' => 'Multichain',
                'status' => 'demo',
                'source_url' => null,
                'source_label' => 'Modelo demonstrativo — validar campanhas oficiais antes de executar',
                'opportunity_score' => 72,
                'risk_score' => 38,
                'capital_min_usdt' => 40,
                'capital_max_usdt' => 180,
                'estimated_fees_usdt' => 4.5,
                'tge_status' => 'unknown',
                'tasks' => [
                    ['id' => 'wallet-ready', 'label' => 'Preparar carteira compatível', 'action' => 'manual', 'automation_allowed' => false, 'requires_market_manipulation' => false],
                    ['id' => 'official-bridge', 'label' => 'Realizar bridge oficial com valor controlado', 'action' => 'bridge', 'automation_allowed' => true, 'requires_market_manipulation' => false],
                    ['id' => 'official-swap', 'label' => 'Realizar swap oficial quando exigido pela campanha', 'action' => 'swap', 'automation_allowed' => true, 'requires_market_manipulation' => false],
                    ['id' => 'hold-reserve', 'label' => 'Preservar reserva de liquidez', 'action' => 'manual', 'automation_allowed' => false, 'requires_market_manipulation' => false],
                ],
            ],
            [
                'slug' => 'protocol-engagement-route',
                'project' => 'Engajamento de Protocolo',
                'network' => 'EVM',
                'status' => 'demo',
                'source_url' => null,
                'source_label' => 'Modelo demonstrativo — nenhuma recompensa é presumida',
                'opportunity_score' => 64,
                'risk_score' => 46,
                'capital_min_usdt' => 75,
                'capital_max_usdt' => 250,
                'estimated_fees_usdt' => 8,
                'tge_status' => 'unknown',
                'tasks' => [
                    ['id' => 'official-deposit', 'label' => 'Depositar somente no contrato/protocolo oficial', 'action' => 'deposit', 'automation_allowed' => true, 'requires_market_manipulation' => false],
                    ['id' => 'stake', 'label' => 'Stake quando houver requisito oficial verificável', 'action' => 'stake', 'automation_allowed' => true, 'requires_market_manipulation' => false],
                    ['id' => 'no-fake-volume', 'label' => 'Não gerar volume artificial ou self-trade', 'action' => 'fake_volume', 'automation_allowed' => false, 'requires_market_manipulation' => true],
                ],
            ],
        ];
    }
}
