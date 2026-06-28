<?php

namespace App\Jobs;

use App\Mail\PlanningCompletedMail;
use App\Models\Planning;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendPlanningCompletedEmailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Planning $planning;

    public function __construct(Planning $planning)
    {
        $this->planning = $planning;
    }

    public function handle(): void
    {
        // Send to admin
        $admins = User::admins()->active()->get();
        foreach ($admins as $admin) {
            Mail::to($admin->email)->queue(
                new PlanningCompletedMail($this->planning, 'admin')
            );
        }

        // Send to employee
        if ($this->planning->user && $this->planning->user->isActive()) {
            Mail::to($this->planning->user->email)->queue(
                new PlanningCompletedMail($this->planning, 'employee')
            );
        }
    }
}
