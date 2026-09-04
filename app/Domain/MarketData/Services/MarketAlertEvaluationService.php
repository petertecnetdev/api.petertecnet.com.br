<?php

namespace App\Domain\MarketData\Services;

use App\Services\AppNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class MarketAlertEvaluationService
{
    public function __construct(
        private readonly MarketDataService $marketData,
        private readonly AppNotificationService $notifications,
    ) {}

    public function evaluate(int $limit = 1000): array
    {
        $limit = max(1, min($limit, 5000));
        $overview = $this->marketData->overview();
        $assets = collect($overview['assets'] ?? [])
            ->keyBy(fn (array $asset) => strtolower((string) ($asset['id'] ?? '')))
            ->all();

        if ($assets === []) {
            throw new RuntimeException('Nenhum ativo de mercado disponível para avaliar alertas.');
        }

        $alerts = DB::table('market_alerts')
            ->where('active', true)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $summary = [
            'evaluated' => 0,
            'triggered' => 0,
            'rearmed' => 0,
            'unchanged' => 0,
            'unsupported' => 0,
            'notification_errors' => 0,
        ];

        foreach ($alerts as $alert) {
            $asset = $assets[strtolower((string) $alert->asset_id)] ?? null;
            if (! $asset) {
                DB::table('market_alerts')->where('id', $alert->id)->update([
                    'last_evaluated_at' => now(),
                    'updated_at' => now(),
                ]);
                $summary['unsupported']++;
                continue;
            }

            $currentValue = $this->metricValue($asset, (string) $alert->metric);
            if ($currentValue === null) {
                $summary['unsupported']++;
                continue;
            }

            $summary['evaluated']++;
            $triggered = $this->compare($currentValue, (string) $alert->operator, (float) $alert->threshold);

            try {
                $transition = DB::transaction(function () use ($alert, $asset, $currentValue, $triggered): string {
                    $locked = DB::table('market_alerts')->where('id', $alert->id)->lockForUpdate()->first();
                    if (! $locked || ! $locked->active) {
                        return 'unchanged';
                    }

                    $wasTriggered = (bool) ($locked->is_triggered ?? false);
                    $update = [
                        'is_triggered' => $triggered,
                        'last_value' => $currentValue,
                        'last_evaluated_at' => now(),
                        'updated_at' => now(),
                    ];

                    if ($triggered && ! $wasTriggered) {
                        $update['last_triggered_at'] = now();
                        $update['trigger_count'] = ((int) ($locked->trigger_count ?? 0)) + 1;
                        DB::table('market_alerts')->where('id', $locked->id)->update($update);

                        $this->notifications->sendToUser((int) $locked->app_id, (int) $locked->user_id, [
                            'type' => 'market_alert_triggered',
                            'title' => sprintf('%s: alerta de mercado atingido', (string) $locked->symbol),
                            'message' => $this->notificationMessage($locked, $currentValue),
                            'reference_type' => 'market_alert',
                            'reference_id' => (int) $locked->id,
                            'reference_url' => '/alerts',
                            'data' => [
                                'alert_id' => (int) $locked->id,
                                'asset_id' => (string) $locked->asset_id,
                                'symbol' => (string) $locked->symbol,
                                'metric' => (string) $locked->metric,
                                'operator' => (string) $locked->operator,
                                'threshold' => (float) $locked->threshold,
                                'current_value' => $currentValue,
                                'asset_name' => $asset['name'] ?? null,
                                'evaluated_at' => now()->toIso8601String(),
                            ],
                        ]);

                        return 'triggered';
                    }

                    DB::table('market_alerts')->where('id', $locked->id)->update($update);

                    if (! $triggered && $wasTriggered) {
                        return 'rearmed';
                    }

                    return 'unchanged';
                }, 3);

                $summary[$transition]++;
            } catch (Throwable $exception) {
                $summary['notification_errors']++;
                Log::warning('Market alert evaluation failed for one alert.', [
                    'alert_id' => (int) $alert->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    private function metricValue(array $asset, string $metric): ?float
    {
        return match ($metric) {
            'price' => isset($asset['price']) ? (float) $asset['price'] : null,
            'change_24h' => isset($asset['change_24h']) ? (float) $asset['change_24h'] : null,
            'change_7d' => isset($asset['change_7d']) ? (float) $asset['change_7d'] : null,
            'score' => isset($asset['score']) ? (float) $asset['score'] : null,
            'confidence' => isset($asset['confidence']) ? (float) $asset['confidence'] : null,
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

    private function notificationMessage(object $alert, float $currentValue): string
    {
        $metric = match ((string) $alert->metric) {
            'price' => 'Preço',
            'change_24h' => 'Variação 24h',
            'change_7d' => 'Variação 7d',
            'score' => 'Opportunity Score',
            'confidence' => 'Confiança',
            default => (string) $alert->metric,
        };

        return sprintf(
            '%s %s %s %s foi atingido. Valor observado: %s.',
            (string) $alert->symbol,
            $metric,
            (string) $alert->operator,
            $this->formatNumber((float) $alert->threshold),
            $this->formatNumber($currentValue),
        );
    }

    private function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');
    }
}
