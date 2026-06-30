<?php

namespace App\Observers;

use App\Models\Pause;
use App\Services\AuditService;

class PauseObserver
{
    public function created(Pause $pause): void
    {
        AuditService::log('pause_created', Pause::class, $pause->id, null, [
            'planning_id' => $pause->planning_id,
            'user_id' => $pause->user_id,
            'type' => $pause->type,
            'status' => $pause->status,
            'pause_start' => $pause->pause_start?->toIso8601String(),
            'pause_end' => $pause->pause_end?->toIso8601String(),
            'duration_minutes' => $pause->duration_minutes,
        ]);
    }

    public function updated(Pause $pause): void
    {
        $old = [];
        $new = [];
        foreach ($pause->getDirty() as $field => $value) {
            $old[$field] = $pause->getOriginal($field);
            $new[$field] = $value;
        }
        AuditService::log('pause_updated', Pause::class, $pause->id, $old, $new);
    }

    public function deleted(Pause $pause): void
    {
        AuditService::log('pause_deleted', Pause::class, $pause->id, [
            'planning_id' => $pause->planning_id,
            'type' => $pause->type,
            'pause_start' => $pause->pause_start?->toIso8601String(),
        ]);
    }
}
