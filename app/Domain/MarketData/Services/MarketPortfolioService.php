<?php

namespace App\Domain\MarketData\Services;

use App\Support\ApplicationContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class MarketPortfolioService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly MarketDataService $marketData,
    ) {}

    public function portfolio(int $userId): array
    {
        $assets = $this->assetIndex();
        $positions = DB::table('market_positions')
            ->where('app_id', $this->context->id())
            ->where('user_id', $userId)
            ->orderBy('created_at')
            ->get();

        $rows = $positions->map(function (object $position) use ($assets): array {
            $asset = $assets[$position->asset_id] ?? null;
            $currentPrice = (float) ($asset['price'] ?? 0);
            $quantity = (float) $position->quantity;
            $averagePrice = (float) $position->average_price;
            $currentValue = $quantity * $currentPrice;
            $costBasis = $quantity * $averagePrice;
            $pnlAmount = $currentValue - $costBasis;
            $pnlPercent = $costBasis > 0 ? ($pnlAmount / $costBasis) * 100 : 0;

            return [
                'id' => (int) $position->id,
                'asset_id' => $position->asset_id,
                'symbol' => $position->symbol,
                'name' => $asset['name'] ?? $position->symbol,
                'quantity' => $quantity,
                'average_price' => $averagePrice,
                'current_price' => $currentPrice,
                'cost_basis' => round($costBasis, 2),
                'current_value' => round($currentValue, 2),
                'pnl_amount' => round($pnlAmount, 2),
                'pnl_percent' => round($pnlPercent, 4),
                'currency' => $position->currency,
                'label' => $position->label,
                'market_available' => $asset !== null,
            ];
        })->values()->all();

        $totals = [
            'cost_basis' => round(array_sum(array_column($rows, 'cost_basis')), 2),
            'current_value' => round(array_sum(array_column($rows, 'current_value')), 2),
        ];
        $totals['pnl_amount'] = round($totals['current_value'] - $totals['cost_basis'], 2);
        $totals['pnl_percent'] = $totals['cost_basis'] > 0
            ? round(($totals['pnl_amount'] / $totals['cost_basis']) * 100, 4)
            : 0;

        return [
            'positions' => $rows,
            'totals' => $totals,
            'currency' => 'BRL',
            'priced_at' => now()->toIso8601String(),
        ];
    }

    public function addPosition(int $userId, array $data): array
    {
        $asset = $this->asset($data['asset_id']);

        $id = DB::table('market_positions')->insertGetId([
            'app_id' => $this->context->id(),
            'user_id' => $userId,
            'asset_id' => $asset['id'],
            'symbol' => $asset['symbol'],
            'quantity' => $data['quantity'],
            'average_price' => $data['average_price'],
            'currency' => 'BRL',
            'label' => $data['label'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (array) DB::table('market_positions')
            ->where('app_id', $this->context->id())
            ->where('user_id', $userId)
            ->where('id', $id)
            ->firstOrFail();
    }

    public function removePosition(int $userId, int $positionId): void
    {
        $deleted = DB::table('market_positions')
            ->where('app_id', $this->context->id())
            ->where('user_id', $userId)
            ->where('id', $positionId)
            ->delete();

        abort_unless($deleted > 0, 404, 'Posição não encontrada neste contexto.');
    }

    public function riskProfile(int $userId): array
    {
        $record = DB::table('market_risk_profiles')
            ->where('app_id', $this->context->id())
            ->where('user_id', $userId)
            ->first();

        if (! $record) {
            return $this->defaultRiskProfile();
        }

        return $this->serializeRiskProfile($record);
    }

    public function saveRiskProfile(int $userId, array $data): array
    {
        $payload = [
            'risk_profile' => $data['risk_profile'],
            'max_asset_exposure' => $data['max_asset_exposure'],
            'max_scenario_loss' => $data['max_scenario_loss'],
            'min_liquidity_reserve' => $data['min_liquidity_reserve'] ?? $this->defaultReserveFor($data['risk_profile']),
            'updated_at' => now(),
        ];

        DB::table('market_risk_profiles')->updateOrInsert(
            ['app_id' => $this->context->id(), 'user_id' => $userId],
            $payload + ['created_at' => now()],
        );

        return $this->riskProfile($userId);
    }

    public function alerts(int $userId): array
    {
        $assets = $this->assetIndex();

        return DB::table('market_alerts')
            ->where('app_id', $this->context->id())
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (object $alert) use ($assets): array {
                $asset = $assets[$alert->asset_id] ?? null;
                $currentValue = $asset ? $this->metricValue($asset, $alert->metric) : null;
                $triggered = $currentValue !== null && $alert->active
                    ? $this->compare($currentValue, $alert->operator, (float) $alert->threshold)
                    : false;

                return [
                    'id' => (int) $alert->id,
                    'asset_id' => $alert->asset_id,
                    'asset' => $alert->symbol,
                    'asset_symbol' => $alert->symbol,
                    'metric' => $alert->metric,
                    'operator' => $alert->operator,
                    'threshold' => (float) $alert->threshold,
                    'condition' => $this->conditionLabel($alert->metric, $alert->operator, (float) $alert->threshold),
                    'active' => (bool) $alert->active,
                    'current_value' => $currentValue,
                    'triggered' => $triggered,
                    'last_triggered_at' => $alert->last_triggered_at,
                ];
            })
            ->values()
            ->all();
    }

    public function addAlert(int $userId, array $data): array
    {
        $asset = $this->asset($data['asset_id']);

        $id = DB::table('market_alerts')->insertGetId([
            'app_id' => $this->context->id(),
            'user_id' => $userId,
            'asset_id' => $asset['id'],
            'symbol' => $asset['symbol'],
            'metric' => $data['metric'],
            'operator' => $data['operator'],
            'threshold' => $data['threshold'],
            'active' => $data['active'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return collect($this->alerts($userId))->firstWhere('id', $id)
            ?? throw new RuntimeException('O alerta foi criado, mas não pôde ser recarregado.');
    }

    public function removeAlert(int $userId, int $alertId): void
    {
        $deleted = DB::table('market_alerts')
            ->where('app_id', $this->context->id())
            ->where('user_id', $userId)
            ->where('id', $alertId)
            ->delete();

        abort_unless($deleted > 0, 404, 'Alerta não encontrado neste contexto.');
    }

    public function analyze(int $userId, array $data): array
    {
        $overview = $this->marketData->overview();
        $requested = collect($data['asset_ids'] ?? [])
            ->map(fn ($id) => strtolower(trim((string) $id)))
            ->filter()
            ->unique()
            ->values();

        $assets = collect($overview['assets'] ?? [])
            ->filter(fn (array $asset) => $requested->isEmpty() || $requested->contains(strtolower($asset['id'])))
            ->sortByDesc(fn (array $asset) => ((float) ($asset['score'] ?? 0) * 0.7) + ((float) ($asset['confidence'] ?? 0) * 0.3))
            ->values();

        if ($assets->isEmpty()) {
            abort(422, 'Nenhum ativo elegível foi encontrado para esta análise.');
        }

        $risk = $this->riskProfile($userId);
        $profile = $data['risk_profile'] ?? $risk['risk_profile'];
        $reservePercent = max(
            (float) $risk['min_liquidity_reserve'],
            $this->defaultReserveFor($profile),
        );
        $maxAssets = match ($profile) {
            'conservador' => 2,
            'agressivo' => 4,
            default => 3,
        };
        $selected = $assets->take($maxAssets);
        $capital = (float) $data['amount'];
        $reserve = round($capital * ($reservePercent / 100), 2);
        $deployable = max(0, $capital - $reserve);

        $weights = $selected->map(fn (array $asset) => max(1, (float) ($asset['score'] ?? 50) * ((float) ($asset['confidence'] ?? 60) / 100)));
        $weightTotal = max(1, (float) $weights->sum());
        $maxExposure = max(5, min(100, (float) $risk['max_asset_exposure'])) / 100;

        $allocation = [];
        $allocated = 0.0;
        foreach ($selected as $index => $asset) {
            $rawShare = ((float) $weights->get($index) / $weightTotal);
            $share = min($rawShare, $maxExposure);
            $amount = round($deployable * $share, 2);
            $allocated += $amount;
            $allocation[] = [
                'asset' => $asset['symbol'],
                'asset_id' => $asset['id'],
                'name' => $asset['name'],
                'amount' => $amount,
                'score' => (int) $asset['score'],
                'confidence' => (int) $asset['confidence'],
                'action' => (int) $asset['score'] >= 75 ? 'Prioridade alta' : 'Monitorar',
                'rationale' => [
                    'change_24h' => (float) $asset['change_24h'],
                    'change_7d' => (float) $asset['change_7d'],
                    'risk' => $asset['risk'],
                ],
            ];
        }

        $unallocated = round(max(0, $deployable - $allocated), 2);
        $reserve = round($reserve + $unallocated, 2);

        return [
            'allocation' => $allocation,
            'reserve' => $reserve,
            'reserve_percent' => round(($reserve / max(1, $capital)) * 100, 2),
            'risk_profile' => $profile,
            'horizon' => $data['horizon'] ?? 'swing',
            'regime' => $overview['regime'] ?? null,
            'source' => $overview['provider'] ?? null,
            'analyzed_at' => now()->toIso8601String(),
            'disclaimer' => 'Cenário educacional de apoio à decisão. Não constitui recomendação financeira nem executa ordens.',
        ];
    }

    public function simulate(int $userId, array $data): array
    {
        $portfolio = $this->portfolio($userId);
        $move = (float) $data['market_move_pct'];
        $current = (float) data_get($portfolio, 'totals.current_value', 0);
        $projected = max(0, $current * (1 + ($move / 100)));

        return [
            'market_move_pct' => $move,
            'current_value' => round($current, 2),
            'projected_value' => round($projected, 2),
            'impact' => round($projected - $current, 2),
            'currency' => 'BRL',
            'method' => 'proportional_stress_test',
            'disclaimer' => 'Stress test simplificado; não modela correlação, liquidez ou execução.',
        ];
    }

    private function assetIndex(): array
    {
        return collect($this->marketData->overview()['assets'] ?? [])
            ->keyBy(fn (array $asset) => $asset['id'])
            ->all();
    }

    private function asset(string $assetId): array
    {
        $assetId = strtolower(trim($assetId));
        $asset = $this->assetIndex()[$assetId] ?? null;

        if (! $asset) {
            abort(422, 'Ativo não suportado pelo provedor de mercado atual.');
        }

        return $asset;
    }

    private function defaultRiskProfile(): array
    {
        return [
            'risk_profile' => 'moderado',
            'max_asset_exposure' => 25.0,
            'max_scenario_loss' => 8.0,
            'min_liquidity_reserve' => 15.0,
            'persisted' => false,
        ];
    }

    private function serializeRiskProfile(object $record): array
    {
        return [
            'risk_profile' => $record->risk_profile,
            'max_asset_exposure' => (float) $record->max_asset_exposure,
            'max_scenario_loss' => (float) $record->max_scenario_loss,
            'min_liquidity_reserve' => (float) $record->min_liquidity_reserve,
            'persisted' => true,
            'updated_at' => $record->updated_at,
        ];
    }

    private function defaultReserveFor(string $profile): float
    {
        return match ($profile) {
            'conservador' => 30.0,
            'agressivo' => 10.0,
            default => 15.0,
        };
    }

    private function metricValue(array $asset, string $metric): ?float
    {
        return match ($metric) {
            'price' => (float) ($asset['price'] ?? 0),
            'change_24h' => (float) ($asset['change_24h'] ?? 0),
            'change_7d' => (float) ($asset['change_7d'] ?? 0),
            'score' => (float) ($asset['score'] ?? 0),
            'confidence' => (float) ($asset['confidence'] ?? 0),
            default => null,
        };
    }

    private function compare(float $value, string $operator, float $threshold): bool
    {
        return match ($operator) {
            '>' => $value > $threshold,
            '>=' => $value >= $threshold,
            '<' => $value < $threshold,
            '<=' => $value <= $threshold,
            default => false,
        };
    }

    private function conditionLabel(string $metric, string $operator, float $threshold): string
    {
        $label = match ($metric) {
            'price' => 'Preço',
            'change_24h' => 'Variação 24h',
            'change_7d' => 'Variação 7d',
            'score' => 'Opportunity Score',
            'confidence' => 'Confiança',
            default => $metric,
        };

        return sprintf('%s %s %s', $label, $operator, rtrim(rtrim(number_format($threshold, 4, '.', ''), '0'), '.'));
    }
}
