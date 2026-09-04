<?php

namespace App\Console\Commands;

use App\Domain\MarketData\Services\MarketAlertEvaluationService;
use Illuminate\Console\Command;
use RuntimeException;

final class EvaluateMarketAlerts extends Command
{
    protected $signature = 'market:alerts:evaluate {--limit=1000 : Maximum active alerts to evaluate in this run}';

    protected $description = 'Evaluate active market alerts and notify users only when conditions transition to triggered.';

    public function handle(MarketAlertEvaluationService $alerts): int
    {
        $limit = max(1, min((int) $this->option('limit'), 5000));

        try {
            $summary = $alerts->evaluate($limit);
        } catch (RuntimeException $exception) {
            $this->warn('Market alerts skipped: '.$exception->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Market alerts: %d evaluated, %d triggered, %d rearmed, %d unchanged, %d unsupported, %d errors.',
            $summary['evaluated'],
            $summary['triggered'],
            $summary['rearmed'],
            $summary['unchanged'],
            $summary['unsupported'],
            $summary['notification_errors'],
        ));

        return $summary['notification_errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
