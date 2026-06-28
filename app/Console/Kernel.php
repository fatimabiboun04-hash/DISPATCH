<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // Send weekly planning reminders every Friday at 9:00 AM
        $schedule->command('planning:send-weekly-reminders')
            ->fridays()
            ->at('09:00');

        // Generate weekly snapshot every Sunday at 11:59 PM
        $schedule->command('snapshots:generate')
            ->sundays()
            ->at('23:59');
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
