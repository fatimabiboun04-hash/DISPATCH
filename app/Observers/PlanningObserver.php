<?php

namespace App\Observers;

use App\Jobs\SendPlanningCompletedEmailsJob;
use App\Models\Planning;

class PlanningObserver
{
    /**
     * Handle the Planning "created" event.
     */
    public function created(Planning $planning): void
    {
        SendPlanningCompletedEmailsJob::dispatch($planning);
    }
}