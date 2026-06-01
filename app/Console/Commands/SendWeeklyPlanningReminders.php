<?php

namespace App\Console\Commands;

use App\Jobs\SendWeeklyPlanningReminderJob;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendWeeklyPlanningReminders extends Command
{
    protected $signature = 'planning:send-weekly-reminders';
    protected $description = 'Send weekly planning review reminders to admins every Friday';

    public function handle(): int
    {
        $now = Carbon::now();

        if (!$now->isFriday()) {
            $this->info('This command should only run on Fridays. Skipping.');
            return self::SUCCESS;
        }

        $weekNumber = $now->isoWeek();
        $year = $now->isoWeekYear();

        SendWeeklyPlanningReminderJob::dispatch($weekNumber, $year);

        $this->info("Weekly planning reminder dispatched for week {$weekNumber}, {$year}.");

        return self::SUCCESS;
    }
}