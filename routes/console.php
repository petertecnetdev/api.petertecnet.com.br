<?php

use App\Domain\Commerce\Services\AutomatedCheckoutRecoveryService;
use App\Domain\Discovery\Services\DiscoveryLearningService;
use App\Domain\Discovery\Services\DiscoverySearchIndexService;
use App\Domain\Discovery\Services\SearchPerformanceSyncService;
use App\Domain\Events\Services\EventAgendaMaintenanceService;
use App\Domain\Finance\Services\AutomatedSubscriptionIntentRecoveryService;
use App\Domain\MarketData\Services\MarketSignalService;
use App\Jobs\DispatchNotificationCampaign;
use App\Models\NotificationCampaign;
use App\Services\ApplicationRuntimeControlService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('discovery:rebuild-index', function () {
    $total = app(DiscoverySearchIndexService::class)->rebuild();
    $this->info("Discovery search index rebuilt with {$total} documents.");
})->purpose('Rebuild the generic Discovery search index');

Artisan::command('discovery:sync-search-performance {provider?}', function (?string $provider = null) {
    $result = app(SearchPerformanceSyncService::class)->sync($provider, 28);
    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
})->purpose('Synchronize configured search-engine performance providers');

Artisan::command('discovery:monitor-public {--limit=80}', function () {
    if (! DB::table('discovery_search_documents')->exists()) {
        app(DiscoverySearchIndexService::class)->rebuild();
    }
    $checked = app(DiscoveryLearningService::class)->monitorPublicPages((int) $this->option('limit'));
    $this->info("Checked {$checked} public pages.");
})->purpose('Validate public pages, canonicals, schema and images');

Artisan::command('kryvion:market-signal-notifications', function () {
    $runtime = app(ApplicationRuntimeControlService::class);
    if (! $runtime->allowsScheduledMarketProcessing('kryvion') || ! $runtime->allows('kryvion', 'notifications_enabled')) {
        $this->line(json_encode(['sent' => 0, 'reason' => 'kryvion_runtime_suspended'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return;
    }

    $result = app(MarketSignalService::class)->distribute();
    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
})->purpose('Generate Kryvion buy, sell, breakout and breakdown notifications');

Artisan::command('kryvion:market-opportunity-reports {--force}', function () {
    $runtime = app(ApplicationRuntimeControlService::class);
    if (! $runtime->allowsScheduledMarketProcessing('kryvion')
        || ! $runtime->allows('kryvion', 'reports_enabled')
        || ! $runtime->allows('kryvion', 'emails_enabled')) {
        $this->line(json_encode(['sent' => 0, 'reason' => 'kryvion_runtime_suspended'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return;
    }

    $result = app(MarketSignalService::class)->distributeOpportunityReports(force: (bool) $this->option('force'));
    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
})->purpose('Send Kryvion HTML opportunity reports to active users');

Artisan::command('ecosystem:dispatch-scheduled-notifications', function () {
    $dispatched = 0;

    NotificationCampaign::query()
        ->where('status', 'scheduled')
        ->whereNotNull('scheduled_at')
        ->where('scheduled_at', '<=', now())
        ->orderBy('id')
        ->chunkById(100, function ($campaigns) use (&$dispatched) {
            foreach ($campaigns as $campaign) {
                $campaign->forceFill(['status' => 'queued'])->save();
                DispatchNotificationCampaign::dispatch($campaign->id);
                $dispatched++;
            }
        });

    $this->info("{$dispatched} campanha(s) agendada(s) despachada(s).");
})->purpose('Dispatch notification campaigns whose scheduled time has arrived');

Artisan::command('events:replenish-weekly-agendas', function () {
    $result = app(EventAgendaMaintenanceService::class)->replenishAll();
    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
})->purpose('Keep each recurring weekly agenda filled only to its configured 1-3 week horizon');

Artisan::command('commerce:recover-pending-checkouts {--limit=}', function () {
    $limit = $this->option('limit');
    $result = app(AutomatedCheckoutRecoveryService::class)->run(
        $limit !== null && $limit !== '' ? (int) $limit : null,
    );

    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
})->purpose('Create one zero-cost in-app reminder for eligible pending PIX checkouts');

Artisan::command('finance:recover-pending-subscriptions {--limit=}', function () {
    $limit = $this->option('limit');
    $result = app(AutomatedSubscriptionIntentRecoveryService::class)->run(
        $limit !== null && $limit !== '' ? (int) $limit : null,
    );

    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
})->purpose('Create one zero-cost in-app reminder for eligible pending subscription PIX checkouts');

Schedule::command('discovery:rebuild-index')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('discovery:sync-search-performance')->dailyAt('04:20')->withoutOverlapping();
Schedule::command('discovery:monitor-public --limit=100')->hourly()->withoutOverlapping();
Schedule::command('kryvion:market-signal-notifications')
    ->everyMinute()
    ->when(function (): bool {
        $runtime = app(ApplicationRuntimeControlService::class);
        return $runtime->shouldRunScheduledMarketScan('kryvion')
            && $runtime->allows('kryvion', 'notifications_enabled');
    })
    ->withoutOverlapping(20)
    ->onOneServer();
Schedule::command('ecosystem:dispatch-scheduled-notifications')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();
Schedule::command('events:replenish-weekly-agendas')
    ->dailyAt('00:10')
    ->withoutOverlapping(30)
    ->onOneServer();
Schedule::command('commerce:recover-pending-checkouts')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();
Schedule::command('finance:recover-pending-subscriptions')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();
