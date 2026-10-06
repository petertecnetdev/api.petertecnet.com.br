<?php

use App\Domain\Commerce\Services\AutomatedCheckoutRecoveryService;
use App\Domain\Commerce\Services\AutomatedPaidTicketFulfillmentRecoveryService;
use App\Domain\Commerce\Services\PaymentHealthSnapshotService;
use App\Domain\Discovery\Services\DiscoveryLearningService;
use App\Domain\Discovery\Services\DiscoverySearchIndexService;
use App\Domain\Discovery\Services\SearchPerformanceSyncService;
use App\Domain\Events\Services\EventAgendaMaintenanceService;
use App\Domain\Finance\Services\AutomatedSubscriptionIntentRecoveryService;
use App\Domain\Finance\Services\AutomatedSubscriptionRenewalRecoveryService;
use App\Domain\Finance\Services\AutomatedSubscriptionRenewalReminderService;
use App\Domain\Messaging\Services\MessageEngagementService;
use App\Domain\People\Services\ArtistInvitationWorkflowService;
use App\Jobs\DispatchNotificationCampaign;
use App\Models\Application;
use App\Models\NotificationCampaign;
use App\Services\ApplicationRuntimeControlService;
use App\Support\ApplicationContext;
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

Artisan::command('commerce:recover-paid-ticket-fulfillment {--limit=25} {--sla-minutes=10} {--retry-after-minutes=30}', function () {
    $result = app(AutomatedPaidTicketFulfillmentRecoveryService::class)->run(
        (int) $this->option('limit'),
        (int) $this->option('sla-minutes'),
        (int) $this->option('retry-after-minutes'),
    );

    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    if (($result['failed'] ?? 0) > 0) {
        $this->warn("{$result['failed']} fulfillment recovery attempt(s) still require attention.");
    }
})->purpose('Revalidate paid orders beyond SLA and idempotently issue only missing event passes');

Artisan::command('commerce:capture-payment-health', function () {
    $captured = 0;

    Application::query()
        ->where('is_active', true)
        ->orderBy('id')
        ->each(function (Application $application) use (&$captured) {
            app(PaymentHealthSnapshotService::class)->captureForApplication((int) $application->id);
            $captured++;
        });

    $this->info("{$captured} payment health snapshot(s) captured.");
})->purpose('Capture generic payment health snapshots for anomaly detection');

Artisan::command('finance:recover-pending-subscriptions {--limit=}', function () {
    $limit = $this->option('limit');
    $result = app(AutomatedSubscriptionIntentRecoveryService::class)->run(
        $limit !== null && $limit !== '' ? (int) $limit : null,
    );

    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
})->purpose('Create one zero-cost in-app reminder for eligible pending subscription PIX checkouts');

Artisan::command('finance:remind-renewing-subscriptions {--limit=}', function () {
    $limit = $this->option('limit');
    $result = app(AutomatedSubscriptionRenewalReminderService::class)->run(
        $limit !== null && $limit !== '' ? (int) $limit : null,
    );

    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
})->purpose('Remind paying subscribers before their current billing period expires');

Artisan::command('finance:recover-expired-subscriptions {--limit=}', function () {
    $limit = $this->option('limit');
    $result = app(AutomatedSubscriptionRenewalRecoveryService::class)->run(
        $limit !== null && $limit !== '' ? (int) $limit : null,
    );

    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
})->purpose('Recover paid subscriptions whose billing period expired without renewal');

Artisan::command('artists:remind-pending-invitations {--limit=100}', function () {
    $result = app(ArtistInvitationWorkflowService::class)->dispatchDueReminders(
        (int) $this->option('limit')
    );

    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
})->purpose('Remind pending artist invitations without duplicating accepted or declined responses');

Artisan::command('messaging:dispatch-engagement', function () {
    $dispatched = 0;
    $context = app(ApplicationContext::class);

    Application::query()
        ->where('is_active', true)
        ->orderBy('id')
        ->each(function (Application $application) use (&$dispatched, $context) {
            $context->set($application);
            $dispatched += app(MessageEngagementService::class)->prepareAndDispatchDue();
        });

    $this->info("{$dispatched} usuário(s) com e-mail de mensagem agendado(s).");
})->purpose('Dispatch grouped message emails and unread reminders');

Schedule::command('discovery:rebuild-index')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('discovery:sync-search-performance')->dailyAt('04:20')->withoutOverlapping();
Schedule::command('discovery:monitor-public --limit=100')->hourly()->withoutOverlapping();
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
Schedule::command('platform:reconcile-payments')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();
Schedule::command('commerce:recover-paid-ticket-fulfillment')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();
Schedule::command('commerce:capture-payment-health')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();
Schedule::command('finance:recover-pending-subscriptions')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();
Schedule::command('finance:remind-renewing-subscriptions')
    ->hourly()
    ->withoutOverlapping(10)
    ->onOneServer();
Schedule::command('finance:recover-expired-subscriptions')
    ->everyThirtyMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();
Schedule::command('messaging:dispatch-engagement')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('artists:remind-pending-invitations --limit=150')
    ->hourly()
    ->withoutOverlapping(20)
    ->onOneServer();


Artisan::command('forecasting:resolve-due {--limit=50}', function () {
    $result=app(\App\Services\ForecastEngineService::class)->markDue((int)$this->option('limit'));
    foreach($result['forecast_ids'] as $forecastId){
        \App\Jobs\ProposeForecastResolution::dispatch($forecastId);
    }
    $this->line(json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
})->purpose('Move due forecasts to resolving state and enqueue evidence-based proposals');

Schedule::command('forecasting:resolve-due --limit=100')->hourly()->withoutOverlapping(20)->onOneServer();
