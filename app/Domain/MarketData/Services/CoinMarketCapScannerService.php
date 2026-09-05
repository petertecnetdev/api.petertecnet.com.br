<?php

namespace App\Domain\MarketData\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class CoinMarketCapScannerService
{
    private const ENDPOINT = 'https://api.coinmarketcap.com/data-api/v3/cryptocurrency/listing';
    private const PAGE_SIZE = 1000;
    private const CACHE_KEY = 'market_data:cmc_scanner:v2';

    public function scan(int $limit = 50): array
    {
        $snapshot = $this->universe();
        $limit = max(10, min(100, $limit));
        $snapshot['opportunities'] = array_slice($snapshot['opportunities'], 0, $limit);

        return $snapshot;
    }

    public function universe(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(5),
            fn (): array => $this->buildSnapshot(),
        );
    }

    private function buildSnapshot(): array
    {
        $firstPage = $this->fetchPage(1, self::PAGE_SIZE);
        $total = max(0, (int) ($firstPage['data']['totalCount'] ?? 0));
        $rows = $this->extractRows($firstPage);

        for ($start = self::PAGE_SIZE + 1; $start <= $total; $start += self::PAGE_SIZE) {
            $rows = array_merge($rows, $this->extractRows($this->fetchPage($start, self::PAGE_SIZE)));
        }

        $candidates = [];
        $excluded = 0;
        foreach ($rows as $row) {
            $candidate = $this->candidate($row);
            if ($candidate === null) {
                $excluded++;
                continue;
            }
            $candidates[] = $candidate;
        }

        usort($candidates, static fn (array $a, array $b): int => $b['breakout_score'] <=> $a['breakout_score']);

        return [
            'provider' => 'CoinMarketCap',
            'provider_mode' => 'public-market-listing',
            'total_listed' => $total,
            'total_received' => count($rows),
            'total_eligible' => count($candidates),
            'total_excluded' => $excluded,
            'scanned_at' => now()->toIso8601String(),
            'opportunities' => $candidates,
            'methodology' => [
                'version' => 'breakout-timing-radar-v2',
                'signals' => [
                    'acceleration_1h_vs_24h',
                    'momentum_24h',
                    'momentum_7d',
                    'volume_change_24h',
                    'turnover',
                    'liquidity',
                    'market_pair_depth',
                    'overheating_penalty',
                    'estimated_move_window',
                    'estimated_upside_range',
                ],
                'eligibility' => [
                    'active_only' => true,
                    'minimum_market_cap_usd' => 1000000,
                    'minimum_volume_24h_usd' => 100000,
                    'minimum_market_pairs' => 2,
                ],
            ],
            'disclaimer' => 'Estimativas probabilísticas baseadas em momentum, volume e liquidez. Não preveem o futuro, não garantem alta e não constituem recomendação individual de investimento.',
        ];
    }

    private function fetchPage(int $start, int $limit): array
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders(['User-Agent' => 'PeterTecnet-Kryvion/1.3'])
                ->connectTimeout(5)
                ->timeout(18)
                ->retry(2, 300, throw: false)
                ->get(self::ENDPOINT, [
                    'start' => $start,
                    'limit' => $limit,
                    'sortBy' => 'market_cap',
                    'sortType' => 'desc',
                    'convert' => 'USD',
                    'cryptoType' => 'all',
                    'tagType' => 'all',
                    'audited' => 'false',
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('CoinMarketCap temporariamente indisponível.', previous: $exception);
        }

        $payload = $response->json();
        if (! $response->successful() || ! is_array($payload)) {
            throw new RuntimeException('Falha ao consultar o universo da CoinMarketCap.');
        }

        return $payload;
    }

    private function extractRows(array $payload): array
    {
        $rows = $payload['data']['cryptoCurrencyList'] ?? [];
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    private function candidate(array $row): ?array
    {
        if ((int) ($row['isActive'] ?? 0) !== 1) {
            return null;
        }

        $quote = is_array($row['quotes'][0] ?? null) ? $row['quotes'][0] : [];
        $marketCap = (float) ($quote['marketCap'] ?? 0);
        $volume = (float) ($quote['volume24h'] ?? 0);
        $marketPairs = (int) ($row['marketPairCount'] ?? 0);
        if ($marketCap < 1_000_000 || $volume < 100_000 || $marketPairs < 2) {
            return null;
        }

        $change1h = (float) ($quote['percentChange1h'] ?? 0);
        $change24h = (float) ($quote['percentChange24h'] ?? 0);
        $change7d = (float) ($quote['percentChange7d'] ?? 0);
        $volumeChange = (float) ($quote['volumePercentChange'] ?? 0);
        $turnoverPct = (float) ($quote['turnover'] ?? ($marketCap > 0 ? $volume / $marketCap : 0)) * 100;
        $rank = max(1, (int) ($row['cmcRank'] ?? 100000));
        $hourlyBaseline = $change24h / 24;
        $acceleration = $change1h - $hourlyBaseline;

        $factors = [
            'acceleration' => $this->normalize($acceleration, -2.5, 4.5),
            'momentum_24h' => $this->normalize($change24h, -12, 22),
            'momentum_7d' => $this->normalize($change7d, -25, 55),
            'volume_expansion' => $this->normalize($volumeChange, -40, 180),
            'turnover' => $this->normalize($turnoverPct, 0.3, 22),
            'liquidity' => $this->normalize(log10(max(1, $volume)), 5, 10.5),
            'market_depth' => $this->normalize(log10(max(1, $marketPairs)), 0.3, 3.6),
        ];

        $raw = ($factors['acceleration'] * 0.22)
            + ($factors['momentum_24h'] * 0.16)
            + ($factors['momentum_7d'] * 0.15)
            + ($factors['volume_expansion'] * 0.18)
            + ($factors['turnover'] * 0.12)
            + ($factors['liquidity'] * 0.10)
            + ($factors['market_depth'] * 0.07);

        $overheatingPenalty = 0.0;
        if ($change1h > 10) {
            $overheatingPenalty += min(18, ($change1h - 10) * 1.3);
        }
        if ($change24h > 35) {
            $overheatingPenalty += min(20, ($change24h - 35) * 0.45);
        }
        if ($turnoverPct > 80) {
            $overheatingPenalty += min(12, ($turnoverPct - 80) * 0.12);
        }

        $microCapPenalty = $marketCap < 5_000_000 ? 10 : ($marketCap < 20_000_000 ? 5 : 0);
        $score = (int) round($this->clamp($raw - $overheatingPenalty - $microCapPenalty));

        $confidence = 52
            + min(16, log10(max(1, $volume / 100000)) * 4)
            + min(12, log10(max(2, $marketPairs)) * 3)
            + ($marketCap >= 50_000_000 ? 8 : 0);
        $confidence = (int) round($this->clamp($confidence, 45, 92));

        $classification = match (true) {
            $score >= 80 && $confidence >= 68 => 'Aceleração forte',
            $score >= 70 => 'Aceleração relevante',
            $score >= 60 => 'Em observação',
            default => 'Sinal fraco',
        };

        $timing = $this->estimateTiming(
            $score,
            $confidence,
            $acceleration,
            $change1h,
            $change24h,
            $change7d,
            $volumeChange,
            $overheatingPenalty,
        );

        $reasons = [];
        if ($acceleration > 0.8) $reasons[] = sprintf('Aceleração de 1h acima do ritmo médio de 24h em %.2f p.p.', $acceleration);
        if ($volumeChange > 20) $reasons[] = sprintf('Volume de 24h crescendo %.1f%%.', $volumeChange);
        if ($change7d > 5) $reasons[] = sprintf('Momentum de 7 dias positivo em %.2f%%.', $change7d);
        if ($turnoverPct > 4) $reasons[] = sprintf('Giro de 24h em %.2f%% da capitalização.', $turnoverPct);
        if ($reasons === []) $reasons[] = 'Confluência ainda limitada; acompanhar confirmação de preço e volume.';

        $risks = [];
        if ($overheatingPenalty > 0) $risks[] = 'Movimento já esticado; risco maior de reversão após aceleração.';
        if ($marketCap < 20_000_000) $risks[] = 'Capitalização reduzida aumenta risco de manipulação e slippage.';
        if ($volumeChange < -20) $risks[] = 'Volume está contraindo, reduzindo qualidade do sinal.';
        if ($risks === []) $risks[] = 'Mesmo com liquidez e momentum, o sinal pode falhar ou inverter rapidamente.';

        return [
            'id' => (int) ($row['id'] ?? 0),
            'slug' => (string) ($row['slug'] ?? ''),
            'symbol' => (string) ($row['symbol'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'cmc_rank' => $rank,
            'price_usd' => (float) ($quote['price'] ?? 0),
            'market_cap_usd' => $marketCap,
            'volume_24h_usd' => $volume,
            'market_pairs' => $marketPairs,
            'change_1h' => round($change1h, 4),
            'change_24h' => round($change24h, 4),
            'change_7d' => round($change7d, 4),
            'volume_change_24h' => round($volumeChange, 4),
            'turnover_24h' => round($turnoverPct, 4),
            'breakout_score' => $score,
            'confidence' => $confidence,
            'classification' => $classification,
            'estimated_window' => $timing['window'],
            'estimated_window_hours' => $timing['window_hours'],
            'estimated_upside_min_pct' => $timing['upside_min_pct'],
            'estimated_upside_max_pct' => $timing['upside_max_pct'],
            'timing_signal' => $timing['signal'],
            'timing_note' => $timing['note'],
            'factors' => array_map(fn (float $value): int => (int) round($value), $factors),
            'reasons' => array_slice($reasons, 0, 3),
            'risks' => array_slice($risks, 0, 2),
            'last_updated' => $row['lastUpdated'] ?? $quote['lastUpdated'] ?? null,
        ];
    }

    private function estimateTiming(
        int $score,
        int $confidence,
        float $acceleration,
        float $change1h,
        float $change24h,
        float $change7d,
        float $volumeChange,
        float $overheatingPenalty,
    ): array {
        $volatilityBase = max(
            abs($change1h) * 4,
            abs($change24h) * 0.75,
            abs($change7d) / 7,
            1.5,
        );
        $strength = $this->clamp(($score * 0.65) + ($confidence * 0.35));
        $upsideMin = $this->clamp(($volatilityBase * 0.55) + (($strength - 50) * 0.07), 1.0, 18.0);
        $upsideMax = $this->clamp(($volatilityBase * 1.35) + (($strength - 50) * 0.18), $upsideMin + 1.0, 45.0);

        if ($score >= 82 && $confidence >= 70 && $acceleration > 1.2 && $volumeChange > 10) {
            $window = '1–6 horas';
            $hours = [1, 6];
        } elseif ($score >= 72 && $confidence >= 62) {
            $window = '6–24 horas';
            $hours = [6, 24];
        } elseif ($score >= 62) {
            $window = '1–3 dias';
            $hours = [24, 72];
        } else {
            $window = 'Sem janela confiável';
            $hours = null;
        }

        if ($overheatingPenalty >= 8 || $change1h > 8 || $change24h > 28) {
            $signal = 'aguardar_recuo';
            $note = 'Movimento já acelerado; prefira aguardar recuo ou nova confirmação de volume antes de considerar entrada.';
        } elseif ($score >= 78 && $confidence >= 68 && $acceleration > 0.8 && $volumeChange > 0) {
            $signal = 'confirmacao_forte';
            $note = 'Confluência forte de aceleração e volume; acompanhar confirmação do rompimento e invalidar se o volume perder força.';
        } elseif ($score >= 65) {
            $signal = 'observar_confirmacao';
            $note = 'Sinal em formação; aguarde confirmação simultânea de preço e volume antes de considerar entrada.';
        } else {
            $signal = 'sem_entrada';
            $note = 'Não há evidência suficiente para definir uma janela de alta com confiança.';
        }

        return [
            'window' => $window,
            'window_hours' => $hours,
            'upside_min_pct' => round($upsideMin, 1),
            'upside_max_pct' => round($upsideMax, 1),
            'signal' => $signal,
            'note' => $note,
        ];
    }

    private function normalize(float $value, float $min, float $max): float
    {
        if ($max <= $min) return 50.0;
        return $this->clamp((($value - $min) / ($max - $min)) * 100);
    }

    private function clamp(float $value, float $min = 0, float $max = 100): float
    {
        return max($min, min($max, $value));
    }
}
