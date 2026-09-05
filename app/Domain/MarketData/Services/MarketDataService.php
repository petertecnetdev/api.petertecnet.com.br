<?php

namespace App\Domain\MarketData\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class MarketDataService
{
    private const INTERVALS = ['1m', '3m', '5m', '15m', '30m', '1h', '2h', '4h', '6h', '8h', '12h', '1d', '3d', '1w', '1M'];

    private const OVERVIEW_ASSETS = [
        'bitcoin' => ['symbol' => 'BTC', 'name' => 'Bitcoin'],
        'ethereum' => ['symbol' => 'ETH', 'name' => 'Ethereum'],
        'solana' => ['symbol' => 'SOL', 'name' => 'Solana'],
        'chainlink' => ['symbol' => 'LINK', 'name' => 'Chainlink'],
        'avalanche-2' => ['symbol' => 'AVAX', 'name' => 'Avalanche'],
    ];

    public function overview(): array
    {
        return Cache::remember(
            'market_data:overview:brl:v3',
            now()->addSeconds(60),
            fn () => $this->fetchCoinGeckoOverview(),
        );
    }

    public function candles(string $asset, string $quote = 'USDT', string $interval = '1h', int $limit = 200): array
    {
        $base = $this->normalizeSymbol($asset);
        $quote = $this->normalizeSymbol($quote);
        $interval = in_array($interval, self::INTERVALS, true) ? $interval : '1h';
        $limit = max(20, min($limit, 500));
        $pair = $base.$quote;

        return Cache::remember(
            sprintf('market_data:candles:%s:%s:%d', $pair, $interval, $limit),
            now()->addSeconds($this->cacheSeconds($interval)),
            fn () => $this->fetchBinanceCandles($pair, $base, $quote, $interval, $limit),
        );
    }

    private function fetchCoinGeckoOverview(): array
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders(['User-Agent' => 'PeterTecnet-Kryvion/1.1'])
                ->connectTimeout(4)
                ->timeout(10)
                ->retry(2, 250, throw: false)
                ->get('https://api.coingecko.com/api/v3/coins/markets', [
                    'vs_currency' => 'brl',
                    'ids' => implode(',', array_keys(self::OVERVIEW_ASSETS)),
                    'price_change_percentage' => '24h,7d',
                    'sparkline' => 'true',
                    'precision' => 'full',
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Provedor de visão geral do mercado indisponível.', previous: $exception);
        }

        $payload = $response->json();
        if (! $response->successful() || ! is_array($payload)) {
            throw new RuntimeException('Falha ao consultar a visão geral do mercado.');
        }

        $assets = [];
        foreach ($payload as $row) {
            if (! is_array($row) || ! isset($row['id']) || ! isset(self::OVERVIEW_ASSETS[$row['id']])) {
                continue;
            }

            $meta = self::OVERVIEW_ASSETS[$row['id']];
            $change24h = (float) ($row['price_change_percentage_24h_in_currency'] ?? $row['price_change_percentage_24h'] ?? 0);
            $change7d = (float) ($row['price_change_percentage_7d_in_currency'] ?? 0);
            $rank = max(1, (int) ($row['market_cap_rank'] ?? 100));
            $marketCap = (float) ($row['market_cap'] ?? 0);
            $volume = (float) ($row['total_volume'] ?? 0);
            $risk = $this->classifyRisk($change24h, $change7d);
            $score = $this->opportunityScore($change24h, $change7d, $rank);
            $confidence = max(55, min(92, 94 - min(39, $rank)));
            $spark = is_array($row['sparkline_in_7d']['price'] ?? null)
                ? $this->sampleSeries($row['sparkline_in_7d']['price'], 36)
                : [];

            $assets[] = [
                'id' => $row['id'],
                'symbol' => $meta['symbol'],
                'name' => $meta['name'],
                'price' => (float) ($row['current_price'] ?? 0),
                'change_24h' => round($change24h, 4),
                'change_7d' => round($change7d, 4),
                'market_cap' => $marketCap,
                'volume' => $volume,
                'turnover_24h' => $marketCap > 0 ? round(($volume / $marketCap) * 100, 4) : 0.0,
                'market_cap_rank' => $rank,
                'score' => $score,
                'risk' => $risk,
                'confidence' => $confidence,
                'spark' => $spark,
                'last_updated' => $row['last_updated'] ?? null,
            ];
        }

        if ($assets === []) {
            throw new RuntimeException('O provedor não retornou ativos válidos para a visão geral.');
        }

        usort($assets, static fn (array $a, array $b): int => $a['market_cap_rank'] <=> $b['market_cap_rank']);

        $positive = count(array_filter($assets, static fn (array $asset): bool => $asset['change_24h'] >= 0));
        $breadth = ($positive / count($assets)) * 100;
        $avg24h = array_sum(array_column($assets, 'change_24h')) / count($assets);
        $avg7d = array_sum(array_column($assets, 'change_7d')) / count($assets);
        $momentum = ($avg24h * 0.65) + (($avg7d / 7) * 0.35);

        if ($breadth >= 60 && $momentum > 0.15) {
            $regime = ['label' => 'Mercado construtivo', 'code' => 'bullish'];
        } elseif ($breadth <= 40 && $momentum < -0.15) {
            $regime = ['label' => 'Mercado defensivo', 'code' => 'bearish'];
        } else {
            $regime = ['label' => 'Mercado seletivo', 'code' => 'neutral'];
        }

        $regime['confidence'] = (int) round(max(52, min(92, 56 + abs($breadth - 50) * 0.45 + min(20, abs($momentum) * 5))));
        $sentiment = $this->marketSentiment();

        foreach ($assets as &$asset) {
            $intelligence = $this->marketIntelligence($asset, $regime, $sentiment);
            $asset['intelligence_score'] = $intelligence['score'];
            $asset['intelligence_confidence'] = $intelligence['confidence'];
            $asset['intelligence_label'] = $intelligence['label'];
            $asset['intelligence_factors'] = $intelligence['factors'];
            $asset['intelligence_reasons'] = $intelligence['reasons'];
            $asset['intelligence_risks'] = $intelligence['risks'];
        }
        unset($asset);

        return [
            'assets' => $assets,
            'regime' => $regime,
            'breadth' => round($breadth, 2),
            'market_intelligence' => $this->buildMarketIntelligence($assets, $regime, $sentiment),
            'sentiment' => $sentiment,
            'provider' => 'coingecko',
            'currency' => 'BRL',
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    private function marketIntelligence(array $asset, array $regime, array $sentiment): array
    {
        $change24h = (float) ($asset['change_24h'] ?? 0);
        $change7d = (float) ($asset['change_7d'] ?? 0);
        $turnover = (float) ($asset['turnover_24h'] ?? 0);
        $rank = max(1, (int) ($asset['market_cap_rank'] ?? 100));
        $risk = (string) ($asset['risk'] ?? 'moderado');
        $sentimentValue = (int) ($sentiment['value'] ?? 50);

        $factors = [
            'momentum_7d' => round($this->clamp(50 + ($change7d * 2.7)), 2),
            'momentum_24h' => round($this->clamp(50 + ($change24h * 5.2)), 2),
            'liquidity' => round($this->clamp(32 + ($turnover * 6.2)), 2),
            'market_quality' => round($this->clamp(100 - (($rank - 1) * 4.5)), 2),
            'resilience' => $risk === 'baixo' ? 86.0 : ($risk === 'alto' ? 42.0 : 68.0),
            'market_regime' => match ($regime['code'] ?? 'neutral') {
                'bullish' => 76.0,
                'bearish' => 38.0,
                default => 58.0,
            },
            'sentiment_balance' => round($this->clamp(100 - (abs($sentimentValue - 50) * 1.25)), 2),
        ];

        $rawScore = ($factors['momentum_7d'] * 0.28)
            + ($factors['momentum_24h'] * 0.20)
            + ($factors['liquidity'] * 0.18)
            + ($factors['market_quality'] * 0.10)
            + ($factors['resilience'] * 0.10)
            + ($factors['market_regime'] * 0.08)
            + ($factors['sentiment_balance'] * 0.06);

        $sentimentPenalty = $sentimentValue >= 80 ? 7 : ($sentimentValue >= 70 ? 4 : ($sentimentValue <= 20 ? 6 : ($sentimentValue <= 30 ? 3 : 0)));
        $score = (int) round($this->clamp($rawScore - $sentimentPenalty));

        $label = match (true) {
            $score >= 75 => 'Força relativa elevada',
            $score >= 64 => 'Força relativa construtiva',
            $score >= 55 => 'Equilíbrio relativo',
            $score >= 45 => 'Fragilidade relativa',
            default => 'Fragilidade relativa elevada',
        };

        $confidence = 62;
        $confidence += ($asset['market_cap'] ?? 0) > 0 ? 6 : 0;
        $confidence += ($asset['volume'] ?? 0) > 0 ? 6 : 0;
        $confidence += ($asset['spark'] ?? []) !== [] ? 6 : 0;
        $confidence += ($sentiment['available'] ?? false) ? 6 : 0;
        $confidence = (int) round($this->clamp($confidence, 50, 92));

        $reasons = [];
        if ($change7d >= 5) {
            $reasons[] = sprintf('Momentum de 7 dias positivo em %.2f%%.', $change7d);
        } elseif ($change7d <= -5) {
            $reasons[] = sprintf('Momentum de 7 dias negativo em %.2f%%.', $change7d);
        } else {
            $reasons[] = sprintf('Momentum de 7 dias moderado em %.2f%%.', $change7d);
        }
        $reasons[] = sprintf('Giro de 24h equivalente a %.2f%% da capitalização.', $turnover);

        $risks = [];
        if ($sentimentValue >= 70) {
            $risks[] = sprintf('Sentimento em ganância (%d/100) aumenta risco de FOMO e reversão.', $sentimentValue);
        } elseif ($sentimentValue <= 30) {
            $risks[] = sprintf('Sentimento em medo (%d/100) indica ambiente de maior estresse.', $sentimentValue);
        }
        if ($risk === 'alto') {
            $risks[] = 'Volatilidade recente classificada como alta.';
        }
        if (($regime['code'] ?? 'neutral') === 'bearish') {
            $risks[] = 'Regime agregado do mercado está defensivo.';
        }
        if ($risks === []) {
            $risks[] = 'O score é comparativo e não elimina risco de perda ou reversão.';
        }

        return [
            'score' => $score,
            'confidence' => $confidence,
            'label' => $label,
            'factors' => $factors,
            'reasons' => $reasons,
            'risks' => $risks,
        ];
    }

    private function buildMarketIntelligence(array $assets, array $regime, array $sentiment): array
    {
        $ranking = $assets;
        usort($ranking, static fn (array $a, array $b): int => ($b['intelligence_score'] ?? 0) <=> ($a['intelligence_score'] ?? 0));
        $leader = $ranking[0];
        $weakest = $ranking[count($ranking) - 1];

        return [
            'version' => 'market-intelligence-v2',
            'generated_at' => now()->toIso8601String(),
            'relative_strength_leader' => $this->intelligenceSnapshot($leader),
            'relative_weakness' => $this->intelligenceSnapshot($weakest),
            'context' => [
                'regime' => $regime,
                'sentiment' => $sentiment,
            ],
            'ranking' => array_map(fn (array $asset): array => [
                'id' => $asset['id'],
                'symbol' => $asset['symbol'],
                'name' => $asset['name'],
                'score' => $asset['intelligence_score'],
                'confidence' => $asset['intelligence_confidence'],
                'label' => $asset['intelligence_label'],
                'change_24h' => $asset['change_24h'],
                'change_7d' => $asset['change_7d'],
                'turnover_24h' => $asset['turnover_24h'],
                'risk' => $asset['risk'],
            ], $ranking),
            'methodology' => [
                'weights' => [
                    'momentum_7d' => 28,
                    'momentum_24h' => 20,
                    'liquidity' => 18,
                    'market_quality' => 10,
                    'resilience' => 10,
                    'market_regime' => 8,
                    'sentiment_balance' => 6,
                ],
                'principle' => 'Ranking comparativo de força, liquidez e risco. Extremos de medo ou ganância reduzem a leitura de qualidade do contexto.',
                'planned_data_factors' => [
                    'fluxo líquido de ETFs spot de BTC e ETH',
                    'funding rate e open interest de derivativos',
                    'dominância do Bitcoin e breadth ampliado',
                    'fluxo on-chain para/de exchanges',
                    'DXY, juros dos Treasuries e calendário do Federal Reserve',
                    'sentimento de notícias com detecção de eventos',
                ],
            ],
            'sources' => [
                ['name' => 'CoinGecko', 'role' => 'preço, market cap, volume, 24h, 7d e série de 7 dias'],
                ['name' => 'Alternative.me Fear & Greed Index', 'role' => 'sentimento agregado do mercado'],
            ],
            'disclaimer' => 'Esta leitura compara condições de mercado e não constitui ordem, recomendação individual ou promessa de retorno.',
        ];
    }

    private function intelligenceSnapshot(array $asset): array
    {
        return [
            'id' => $asset['id'],
            'symbol' => $asset['symbol'],
            'name' => $asset['name'],
            'price' => $asset['price'],
            'score' => $asset['intelligence_score'],
            'confidence' => $asset['intelligence_confidence'],
            'label' => $asset['intelligence_label'],
            'change_24h' => $asset['change_24h'],
            'change_7d' => $asset['change_7d'],
            'turnover_24h' => $asset['turnover_24h'],
            'risk' => $asset['risk'],
            'factors' => $asset['intelligence_factors'],
            'reasons' => $asset['intelligence_reasons'],
            'risks' => $asset['intelligence_risks'],
        ];
    }

    private function marketSentiment(): array
    {
        return Cache::remember('market_data:sentiment:fear_greed:v1', now()->addMinutes(10), function (): array {
            try {
                $response = Http::acceptJson()
                    ->withHeaders(['User-Agent' => 'PeterTecnet-Kryvion/1.1'])
                    ->connectTimeout(3)
                    ->timeout(6)
                    ->retry(1, 180, throw: false)
                    ->get('https://api.alternative.me/fng/', ['limit' => 2, 'format' => 'json']);
            } catch (ConnectionException) {
                return $this->neutralSentiment();
            }

            $payload = $response->json();
            $current = is_array($payload['data'][0] ?? null) ? $payload['data'][0] : null;
            if (! $response->successful() || ! $current) {
                return $this->neutralSentiment();
            }

            return [
                'value' => max(0, min(100, (int) ($current['value'] ?? 50))),
                'classification' => (string) ($current['value_classification'] ?? 'Neutral'),
                'provider' => 'Alternative.me Fear & Greed Index',
                'available' => true,
                'timestamp' => isset($current['timestamp']) ? (int) $current['timestamp'] : null,
            ];
        });
    }

    private function neutralSentiment(): array
    {
        return [
            'value' => 50,
            'classification' => 'Neutral (fallback)',
            'provider' => 'Alternative.me Fear & Greed Index',
            'available' => false,
            'timestamp' => null,
        ];
    }

    private function opportunityScore(float $change24h, float $change7d, int $rank): int
    {
        $rankQuality = max(0, 18 - min(18, ($rank - 1) * 0.75));
        $momentum = max(-25, min(25, ($change24h * 2.2) + ($change7d * 0.8)));

        return (int) round(max(0, min(100, 55 + $rankQuality + $momentum)));
    }

    private function classifyRisk(float $change24h, float $change7d): string
    {
        if (abs($change24h) >= 7 || abs($change7d) >= 22) {
            return 'alto';
        }

        if (abs($change24h) <= 2.5 && abs($change7d) <= 9) {
            return 'baixo';
        }

        return 'moderado';
    }

    private function sampleSeries(array $values, int $target): array
    {
        $numeric = array_values(array_map('floatval', $values));
        $count = count($numeric);
        if ($count <= $target || $target < 2) {
            return $numeric;
        }

        $sampled = [];
        for ($index = 0; $index < $target; $index++) {
            $sourceIndex = (int) round(($index / ($target - 1)) * ($count - 1));
            $sampled[] = $numeric[$sourceIndex];
        }

        return $sampled;
    }

    private function fetchBinanceCandles(string $pair, string $base, string $quote, string $interval, int $limit): array
    {
        try {
            $response = Http::acceptJson()
                ->connectTimeout(4)
                ->timeout(8)
                ->retry(2, 180, throw: false)
                ->get('https://api.binance.com/api/v3/klines', [
                    'symbol' => $pair,
                    'interval' => $interval,
                    'limit' => $limit,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Provedor de dados de mercado indisponível.', previous: $exception);
        }

        if (! $response->successful() || ! is_array($response->json())) {
            $providerMessage = trim((string) $response->json('msg'));
            throw new RuntimeException($providerMessage !== '' ? $providerMessage : 'Falha ao consultar dados de mercado.');
        }

        $candles = array_values(array_filter(array_map(static function ($row): ?array {
            if (! is_array($row) || count($row) < 11) {
                return null;
            }

            return [
                'time' => (int) $row[0],
                'open' => (float) $row[1],
                'high' => (float) $row[2],
                'low' => (float) $row[3],
                'close' => (float) $row[4],
                'volume' => (float) $row[5],
                'close_time' => (int) $row[6],
                'quote_volume' => (float) $row[7],
                'trades' => (int) $row[8],
                'taker_buy_volume' => (float) $row[9],
                'taker_buy_quote_volume' => (float) $row[10],
            ];
        }, $response->json())));

        if ($candles === []) {
            throw new RuntimeException('O provedor não retornou candles válidos para este par.');
        }

        return [
            'provider' => 'binance_spot',
            'pair' => $pair,
            'base' => $base,
            'quote' => $quote,
            'interval' => $interval,
            'candles' => $candles,
            'count' => count($candles),
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    private function normalizeSymbol(string $symbol): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($symbol)) ?? '');

        if ($normalized === '' || strlen($normalized) > 16) {
            throw new RuntimeException('Símbolo de mercado inválido.');
        }

        return $normalized;
    }

    private function cacheSeconds(string $interval): int
    {
        return match ($interval) {
            '1m', '3m', '5m' => 15,
            '15m', '30m' => 30,
            '1h', '2h', '4h' => 60,
            default => 180,
        };
    }

    private function clamp(float $value, float $min = 0, float $max = 100): float
    {
        return max($min, min($max, $value));
    }
}
