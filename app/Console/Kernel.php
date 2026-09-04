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

        $schedule->command('operations:backup-database --retention-days=14')
            ->dailyAt('03:10')
            ->withoutOverlapping(120);

        $schedule->command('forecasts:evaluate')->everyFiveMinutes();
        $schedule->command('platform:remind-events')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('platform:reconcile-payments --limit=50')->everyMinute()->withoutOverlapping();
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
