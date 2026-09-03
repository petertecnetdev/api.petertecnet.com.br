<?php

use App\Domain\Discovery\Services\DiscoveryLearningService;
use App\Domain\Discovery\Services\DiscoverySearchIndexService;
use App\Domain\Discovery\Services\SearchPerformanceSyncService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
    if (! \Illuminate\Support\Facades\DB::table('discovery_search_documents')->exists()) {
        app(DiscoverySearchIndexService::class)->rebuild();
    }
    $checked = app(DiscoveryLearningService::class)->monitorPublicPages((int) $this->option('limit'));
    $this->info("Checked {$checked} public pages.");
})->purpose('Validate public pages, canonicals, schema and images');

Schedule::command('discovery:rebuild-index')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('discovery:sync-search-performance')->dailyAt('04:20')->withoutOverlapping();
Schedule::command('discovery:monitor-public --limit=100')->hourly()->withoutOverlapping();
