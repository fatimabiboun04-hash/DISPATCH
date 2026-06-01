<?php

namespace App\Jobs;

use App\Mail\WeeklyPlanningReminderMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendWeeklyPlanningReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $weekNumber;
    public int $year;

    public function __construct(int $weekNumber, int $year)
    {
        $this->weekNumber = $weekNumber;
        $this->year = $year;
    }

    public function handle(): void
    {
        $admins = User::admins()->active()->get();

        foreach ($admins as $admin) {
            Mail::to($admin->email)->queue(
                new WeeklyPlanningReminderMail($this->weekNumber, $this->year)
            );
        }
    }
}