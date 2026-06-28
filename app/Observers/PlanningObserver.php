<?php

namespace App\Observers;

use App\Jobs\SendPlanningCompletedEmailsJob;
use App\Models\Planning;
use App\Models\PlanningAudit;
use App\Services\PlanningService;

class PlanningObserver
{
    public static bool $bulkCreating = false;

    public function created(Planning $planning): void
    {
        // Audit log
        PlanningAudit::create([
            'planning_id' => $planning->id,
            'user_id' => auth()->id() ?? $planning->created_by,
            'action' => 'created',
            'new_values' => $planning->toArray(),
            'created_at' => now(),
        ]);

        if (static::$bulkCreating) {
            return;
        }

        SendPlanningCompletedEmailsJob::dispatch($planning);
        PlanningService::bumpSuggestionsVersion();
    }

    public function updated(Planning $planning): void
    {
        $oldValues = [];
        $newValues = [];

        foreach ($planning->getDirty() as $field => $newValue) {
            $oldValues[$field] = $planning->getOriginal($field);
            $newValues[$field] = $newValue;
        }

        PlanningAudit::create([
            'planning_id' => $planning->id,
            'user_id' => auth()->id(),
            'action' => 'updated',
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'created_at' => now(),
        ]);

        if (static::$bulkCreating) {
            return;
        }

        PlanningService::bumpSuggestionsVersion();
    }

    public function deleted(Planning $planning): void
    {
        PlanningAudit::create([
            'planning_id' => $planning->id,
            'user_id' => auth()->id(),
            'action' => 'deleted',
            'old_values' => $planning->toArray(),
            'reason' => 'Planning record deleted',
            'created_at' => now(),
        ]);

        if (static::$bulkCreating) {
            return;
        }

        PlanningService::bumpSuggestionsVersion();
    }
}
