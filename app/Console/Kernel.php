<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('forecasts:evaluate')->everyFiveMinutes();
        $schedule->command('platform:remind-events')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('platform:reconcile-payments --limit=50')->everyMinute()->withoutOverlapping();
    }

    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
