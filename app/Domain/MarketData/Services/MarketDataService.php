<?php

namespace App\Domain\MarketData\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class MarketDataService
{
    private const INTERVALS = ['1m', '3m', '5m', '15m', '30m', '1h', '2h', '4h', '6h', '8h', '12h', '1d', '3d', '1w', '1M'];

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
