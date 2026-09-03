<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('admin:heartbeat')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('admin:probe-applications')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('admin:database-backup')->dailyAt('03:15')->withoutOverlapping();
        $schedule->command('forecast:evaluate-orders')->dailyAt('03:00');
        $schedule->command('platform:remind-events')->everyTenMinutes()->withoutOverlapping();
        $schedule->command('platform:reconcile-payments')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('payments:sync-ecosystem --limit=200')->everyTenMinutes()->withoutOverlapping();
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}
