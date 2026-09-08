<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('operations:monitor')
            ->everyMinute()
            ->withoutOverlapping(10);

        $schedule->command('operations:monitor-production-onboarding')
            ->everyFiveMinutes()
            ->withoutOverlapping(10);

        // Database backups are created by the verified encrypted backup workflow.
        // The workflow streams the dump off-host, restores it in an isolated database,
        // encrypts it before retention, and stores an off-VPS copy. Do not schedule the
        // legacy plaintext operations:backup-database command in production.

        $schedule->command('forecasts:evaluate')->everyFiveMinutes();
        $schedule->command('platform:remind-events')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('platform:reconcile-payments --limit=50')->everyMinute()->withoutOverlapping();
        $schedule->command('profitability:monitor --hours=6')
            ->everySixHours()
            ->withoutOverlapping(30);
        $schedule->command('market:alerts:evaluate --limit=1000')
            ->everyMinute()
            ->withoutOverlapping(5);
    }

    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
