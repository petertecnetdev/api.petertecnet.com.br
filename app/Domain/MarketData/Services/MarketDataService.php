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
            'market_data:overview:brl:v1',
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
                ->withHeaders(['User-Agent' => 'PeterTecnet-Kryvion/1.0'])
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
                'market_cap' => (float) ($row['market_cap'] ?? 0),
                'volume' => (float) ($row['total_volume'] ?? 0),
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

        return [
            'assets' => $assets,
            'regime' => $regime,
            'breadth' => round($breadth, 2),
            'provider' => 'coingecko',
            'currency' => 'BRL',
            'fetched_at' => now()->toIso8601String(),
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
}
