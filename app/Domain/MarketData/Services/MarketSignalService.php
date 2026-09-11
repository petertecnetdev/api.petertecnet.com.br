<?php

namespace App\Domain\MarketData\Services;

use App\Mail\KryvionOpportunityReportMail;
use App\Models\AppNotification;
use App\Models\Application;
use App\Models\User;
use App\Services\AppNotificationService;
use App\Services\ApplicationRuntimeControlService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

final class MarketSignalService
{
    private const APP_SLUG = 'kryvion';
    private const NOTIFICATION_COOLDOWN_MINUTES = 30;
    private const REPORT_EMAIL_COOLDOWN_HOURS = 6;

    public function __construct(
        private readonly CoinMarketCapScannerService $scanner,
        private readonly AppNotificationService $notifications,
        private readonly ApplicationRuntimeControlService $runtime,
    ) {}

    public function current(): array
    {
        if (! $this->runtime->allows(self::APP_SLUG, 'market_scanner_enabled')) {
            throw new RuntimeException('O processamento de mercado da Kryvion está suspenso pelo Admin Center.');
        }

        $scan = $this->scanner->universe();
        $assets = collect($scan['opportunities'] ?? [])->filter(fn ($asset) => is_array($asset))->values();

        $bearish = $assets
            ->map(fn (array $asset): array => $this->withDownsideScore($asset))
            ->sortByDesc('breakdown_score')
            ->values();

        $buy = $assets->first(fn (array $asset): bool =>
            (int) ($asset['breakout_score'] ?? 0) >= 78
            && (int) ($asset['confidence'] ?? 0) >= 68
            && ($asset['timing_signal'] ?? null) === 'confirmacao_forte'
            && (float) ($asset['change_1h'] ?? 0) > 0
            && (float) ($asset['volume_change_24h'] ?? 0) > 0
        );

        $sell = $bearish->first(fn (array $asset): bool =>
            (int) ($asset['breakdown_score'] ?? 0) >= 78
            && (int) ($asset['confidence'] ?? 0) >= 68
            && (float) ($asset['change_1h'] ?? 0) < 0
            && (float) ($asset['change_24h'] ?? 0) < 0
        );

        $up = $assets->first(fn (array $asset): bool => (int) ($asset['breakout_score'] ?? 0) >= 65);
        $down = $bearish->first(fn (array $asset): bool =>
            (int) ($asset['breakdown_score'] ?? 0) >= 65
            && (float) ($asset['change_24h'] ?? 0) < -2
            && ((float) ($asset['change_1h'] ?? 0) < 0 || (float) ($asset['change_7d'] ?? 0) < -5)
        );

        return [
            'generated_at' => now()->toIso8601String(),
            'valid_for_minutes' => 20,
            'buy' => $buy ? $this->signalPayload('buy', $buy, (int) $buy['breakout_score']) : null,
            'sell' => $sell ? $this->signalPayload('sell', $sell, (int) $sell['breakdown_score']) : null,
            'possible_large_rise' => $up ? $this->signalPayload('possible_large_rise', $up, (int) $up['breakout_score']) : null,
            'possible_large_fall' => $down ? $this->signalPayload('possible_large_fall', $down, (int) $down['breakdown_score']) : null,
            'methodology' => [
                'version' => 'market-signals-v2',
                'buy_threshold' => 78,
                'sell_threshold' => 78,
                'watch_threshold' => 65,
                'universe_eligible' => (int) ($scan['total_eligible'] ?? $assets->count()),
                'inputs' => ['momentum_1h', 'momentum_24h', 'momentum_7d', 'volume_expansion', 'turnover', 'liquidity', 'market_depth'],
            ],
            'disclaimer' => 'Sinais técnicos probabilísticos baseados em dados de mercado. Não garantem alta, baixa ou retorno e devem ser combinados com gestão de risco.',
        ];
    }

    public function distribute(): array
    {
        if (! $this->runtime->allowsScheduledMarketProcessing(self::APP_SLUG)
            || ! $this->runtime->allows(self::APP_SLUG, 'notifications_enabled')) {
            return ['sent' => 0, 'skipped' => 0, 'reason' => 'kryvion_runtime_suspended'];
        }

        $application = Application::query()->where('slug', self::APP_SLUG)->where('is_active', true)->first();
        if (! $application) {
            return ['sent' => 0, 'skipped' => 0, 'reason' => 'kryvion_application_not_found'];
        }

        $signals = $this->current();
        $userIds = User::query()
            ->whereHas('applications', fn ($query) => $query
                ->where('applications.id', $application->id)
                ->where('application_user.status', 'active'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $sent = 0;
        $skipped = 0;

        foreach (['buy', 'sell', 'possible_large_rise', 'possible_large_fall'] as $type) {
            $signal = $signals[$type] ?? null;
            if (! is_array($signal)) {
                continue;
            }

            if (in_array($type, ['possible_large_rise', 'possible_large_fall'], true) && (int) ($signal['score'] ?? 0) < 72) {
                continue;
            }

            foreach ($userIds as $userId) {
                if ($this->wasRecentlySent((int) $application->id, $userId, $type, (string) ($signal['symbol'] ?? ''))) {
                    $skipped++;
                    continue;
                }

                $this->notifications->sendToUser((int) $application->id, $userId, [
                    'type' => 'market_signal_'.$type,
                    'title' => $signal['title'],
                    'message' => $signal['message'],
                    'reference_url' => '/?marketSignal='.$type.'&asset='.urlencode((string) $signal['symbol']),
                    'data' => [
                        'signal_type' => $type,
                        'symbol' => $signal['symbol'],
                        'name' => $signal['name'],
                        'score' => $signal['score'],
                        'confidence' => $signal['confidence'],
                        'price_usd' => $signal['price_usd'],
                        'reasons' => $signal['reasons'],
                        'risks' => $signal['risks'],
                        'generated_at' => $signals['generated_at'],
                        'valid_for_minutes' => $signals['valid_for_minutes'],
                    ],
                ]);
                $sent++;
            }
        }

        $emailReports = $this->distributeOpportunityReports($application, $signals);

        return [
            'sent' => $sent,
            'skipped' => $skipped,
            'users' => $userIds->count(),
            'generated_at' => $signals['generated_at'],
            'email_reports' => $emailReports,
        ];
    }

    public function opportunityReport(string $symbol): array
    {
        $symbol = strtoupper(trim($symbol));
        $scan = $this->scanner->universe();
        $asset = collect($scan['opportunities'] ?? [])->first(fn ($row) => strtoupper((string) ($row['symbol'] ?? '')) === $symbol);

        abort_unless(is_array($asset), 404, 'Ativo não encontrado no radar atual da Kryvion.');

        return $this->buildOpportunityReport($asset);
    }

    public function distributeOpportunityReports(?Application $application = null, ?array $signals = null, bool $force = false): array
    {
        if (! $this->runtime->allowsScheduledMarketProcessing(self::APP_SLUG)
            || ! $this->runtime->allows(self::APP_SLUG, 'reports_enabled')
            || ! $this->runtime->allows(self::APP_SLUG, 'emails_enabled')) {
            return ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'reason' => 'kryvion_runtime_suspended'];
        }

        $application ??= Application::query()->where('slug', self::APP_SLUG)->where('is_active', true)->first();
        if (! $application) {
            return ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'reason' => 'kryvion_application_not_found'];
        }

        $signals ??= $this->current();
        $signal = $signals['buy'] ?? null;
        if (! is_array($signal) || (int) ($signal['score'] ?? 0) < 78 || (int) ($signal['confidence'] ?? 0) < 68) {
            return ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'reason' => 'no_strong_buy_signal'];
        }

        $report = $this->opportunityReport((string) $signal['symbol']);
        $users = User::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereHas('applications', fn ($query) => $query
                ->where('applications.id', $application->id)
                ->where('application_user.status', 'active'))
            ->get(['id', 'email', 'first_name', 'last_name', 'user_name']);

        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($users as $user) {
            $cacheKey = 'kryvion:opportunity-email:'.(int) $user->id.':'.strtolower((string) $report['symbol']);
            if (! $force && Cache::has($cacheKey)) {
                $skipped++;
                continue;
            }

            try {
                $name = trim((string) ($user->first_name ?: $user->user_name ?: ''));
                Mail::to((string) $user->email)->send(new KryvionOpportunityReportMail($report, $name));
                Cache::put($cacheKey, true, now()->addHours(self::REPORT_EMAIL_COOLDOWN_HOURS));
                $sent++;
            } catch (\Throwable $e) {
                report($e);
                $failed++;
            }
        }

        return [
            'sent' => $sent,
            'skipped' => $skipped,
            'failed' => $failed,
            'users' => $users->count(),
            'symbol' => $report['symbol'],
        ];
    }

    private function buildOpportunityReport(array $asset): array
    {
        $price = (float) ($asset['price_usd'] ?? 0);
        $upsideMin = (float) ($asset['estimated_upside_min_pct'] ?? 0);
        $upsideMax = (float) ($asset['estimated_upside_max_pct'] ?? 0);
        $window = (string) ($asset['estimated_window'] ?? 'Sem janela confiável');
        $targetMin = $price > 0 ? $price * (1 + ($upsideMin / 100)) : 0;
        $targetMax = $price > 0 ? $price * (1 + ($upsideMax / 100)) : 0;

        return [
            'symbol' => strtoupper((string) ($asset['symbol'] ?? '')),
            'name' => (string) ($asset['name'] ?? ''),
            'score' => (int) ($asset['breakout_score'] ?? 0),
            'confidence' => (int) ($asset['confidence'] ?? 0),
            'classification' => (string) ($asset['classification'] ?? ''),
            'price_usd' => $price,
            'change_1h' => (float) ($asset['change_1h'] ?? 0),
            'change_24h' => (float) ($asset['change_24h'] ?? 0),
            'change_7d' => (float) ($asset['change_7d'] ?? 0),
            'volume_change_24h' => (float) ($asset['volume_change_24h'] ?? 0),
            'entry_window' => $window,
            'exit_window' => $window === '1–6 horas' ? 'Monitorar realização entre 6–24 horas' : ($window === '6–24 horas' ? 'Monitorar realização entre 1–3 dias' : 'Reavaliar após confirmação do alvo'),
            'upside_min_pct' => round($upsideMin, 2),
            'upside_max_pct' => round($upsideMax, 2),
            'target_price_min_usd' => round($targetMin, 8),
            'target_price_max_usd' => round($targetMax, 8),
            'reasons' => array_values($asset['reasons'] ?? []),
            'risks' => array_values($asset['risks'] ?? []),
            'timing_note' => (string) ($asset['timing_note'] ?? ''),
            'panel_url' => 'https://kryvion.petertecnet.com.br/?marketReport='.urlencode(strtoupper((string) ($asset['symbol'] ?? ''))),
            'generated_at' => now()->toIso8601String(),
            'disclaimer' => 'Cenário probabilístico calculado com dados de mercado; não há garantia de preço, prazo ou retorno.',
        ];
    }

    private function wasRecentlySent(int $appId, int $userId, string $signalType, string $symbol): bool
    {
        return AppNotification::query()
            ->where('app_id', $appId)
            ->where('user_id', $userId)
            ->where('type', 'market_signal_'.$signalType)
            ->when($symbol !== '', fn ($query) => $query->where('data->symbol', $symbol))
            ->where('created_at', '>=', now()->subMinutes(self::NOTIFICATION_COOLDOWN_MINUTES))
            ->exists();
    }

    private function withDownsideScore(array $asset): array
    {
        $change1h = (float) ($asset['change_1h'] ?? 0);
        $change24h = (float) ($asset['change_24h'] ?? 0);
        $change7d = (float) ($asset['change_7d'] ?? 0);
        $volumeChange = (float) ($asset['volume_change_24h'] ?? 0);
        $turnover = (float) ($asset['turnover_24h'] ?? 0);
        $volume = max(1.0, (float) ($asset['volume_24h_usd'] ?? 0));
        $marketPairs = max(2, (int) ($asset['market_pairs'] ?? 2));
        $marketCap = max(1.0, (float) ($asset['market_cap_usd'] ?? 0));
        $bearAcceleration = (-$change1h) - ((-$change24h) / 24);

        $raw = ($this->normalize($bearAcceleration, -2.5, 4.5) * 0.23)
            + ($this->normalize(-$change24h, -12, 22) * 0.18)
            + ($this->normalize(-$change7d, -25, 55) * 0.15)
            + ($this->normalize($volumeChange, -40, 180) * 0.18)
            + ($this->normalize($turnover, 0.3, 22) * 0.10)
            + ($this->normalize(log10($volume), 5, 10.5) * 0.09)
            + ($this->normalize(log10($marketPairs), 0.3, 3.6) * 0.07);

        $reboundPenalty = 0.0;
        if ($change1h < -10) $reboundPenalty += min(18, (abs($change1h) - 10) * 1.3);
        if ($change24h < -35) $reboundPenalty += min(20, (abs($change24h) - 35) * 0.45);
        $microCapPenalty = $marketCap < 5_000_000 ? 10 : ($marketCap < 20_000_000 ? 5 : 0);
        $score = (int) round($this->clamp($raw - $reboundPenalty - $microCapPenalty));

        $reasons = [];
        if ($bearAcceleration > 0.8) $reasons[] = sprintf('Aceleração de queda de 1h em %.2f p.p. acima do ritmo médio de 24h.', $bearAcceleration);
        if ($change24h < -3) $reasons[] = sprintf('Momentum de 24h negativo em %.2f%%.', $change24h);
        if ($change7d < -5) $reasons[] = sprintf('Momentum de 7 dias negativo em %.2f%%.', $change7d);
        if ($volumeChange > 20) $reasons[] = sprintf('Volume de 24h crescendo %.1f%% durante a pressão vendedora.', $volumeChange);
        if ($reasons === []) $reasons[] = 'Pressão vendedora ainda sem confluência suficiente para confirmação forte.';

        $risks = [];
        if ($reboundPenalty > 0) $risks[] = 'Queda já esticada aumenta a chance de repique técnico.';
        if ($marketCap < 20_000_000) $risks[] = 'Baixa capitalização aumenta volatilidade, slippage e risco de manipulação.';
        if ($volumeChange < -20) $risks[] = 'Volume em contração reduz a confiabilidade do movimento.';
        if ($risks === []) $risks[] = 'O movimento pode inverter rapidamente mesmo com momentum negativo.';

        return array_merge($asset, [
            'breakdown_score' => $score,
            'breakdown_reasons' => array_slice($reasons, 0, 3),
            'breakdown_risks' => array_slice($risks, 0, 2),
        ]);
    }

    private function signalPayload(string $type, array $asset, int $score): array
    {
        $symbol = strtoupper((string) ($asset['symbol'] ?? '—'));
        $name = (string) ($asset['name'] ?? $symbol);
        $confidence = (int) ($asset['confidence'] ?? 0);
        $bearish = in_array($type, ['sell', 'possible_large_fall'], true);
        $reasons = $bearish ? ($asset['breakdown_reasons'] ?? []) : ($asset['reasons'] ?? []);
        $risks = $bearish ? ($asset['breakdown_risks'] ?? []) : ($asset['risks'] ?? []);

        [$title, $message] = match ($type) {
            'buy' => [
                "Agora é hora de comprar {$symbol} — sinal técnico forte",
                "A Kryvion detectou confluência de momentum e volume em {$name}. Score {$score}/100, confiança {$confidence}%. Sinal probabilístico; valide risco antes de entrar.",
            ],
            'sell' => [
                "Agora é hora de vender {$symbol} — sinal técnico de saída",
                "A Kryvion detectou pressão vendedora forte em {$name}. Score de queda {$score}/100, confiança {$confidence}%. Sinal probabilístico; confirme sua estratégia antes de sair.",
            ],
            'possible_large_rise' => [
                "{$symbol} pode estar perto de uma grande alta",
                "O radar colocou {$name} entre os sinais de aceleração mais fortes agora: {$score}/100 com confiança {$confidence}%.",
            ],
            default => [
                "{$symbol} pode estar perto de uma grande baixa",
                "O radar detectou pressão de baixa relevante em {$name}: {$score}/100 com confiança {$confidence}%.",
            ],
        };

        return [
            'type' => $type,
            'symbol' => $symbol,
            'name' => $name,
            'title' => $title,
            'message' => $message,
            'score' => $score,
            'confidence' => $confidence,
            'price_usd' => (float) ($asset['price_usd'] ?? 0),
            'change_1h' => (float) ($asset['change_1h'] ?? 0),
            'change_24h' => (float) ($asset['change_24h'] ?? 0),
            'change_7d' => (float) ($asset['change_7d'] ?? 0),
            'estimated_window' => $bearish ? $this->downsideWindow($score, $confidence) : ($asset['estimated_window'] ?? 'Sem janela confiável'),
            'reasons' => array_values(array_slice($reasons, 0, 3)),
            'risks' => array_values(array_slice($risks, 0, 2)),
        ];
    }

    private function downsideWindow(int $score, int $confidence): string
    {
        return match (true) {
            $score >= 82 && $confidence >= 70 => '1–6 horas',
            $score >= 72 && $confidence >= 62 => '6–24 horas',
            $score >= 62 => '1–3 dias',
            default => 'Sem janela confiável',
        };
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
