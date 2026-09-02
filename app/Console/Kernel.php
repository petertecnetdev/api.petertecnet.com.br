<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('admin:heartbeat scheduler')->everyMinute()->withoutOverlapping();
        $schedule->command('admin:probe-apps')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('admin:backup-database --retention=7')->dailyAt('03:10')->withoutOverlapping();
        $schedule->command('forecasts:evaluate')->everyFiveMinutes();
        $schedule->command('cutinapp:remind-events')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('cutinapp:reconcile-payments --limit=50')->everyMinute()->withoutOverlapping();
        $schedule->command('ecosystem:sync-payments')->everyMinute()->withoutOverlapping();
    }

    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
