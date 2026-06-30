<?php

namespace App\Observers;

use App\Models\Task;
use App\Services\AuditService;

class TaskObserver
{
    public function created(Task $task): void
    {
        AuditService::log('task_created', Task::class, $task->id, null, [
            'planning_id' => $task->planning_id,
            'user_id' => $task->user_id,
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority,
        ]);
    }

    public function updated(Task $task): void
    {
        $old = [];
        $new = [];
        foreach ($task->getDirty() as $field => $value) {
            $old[$field] = $task->getOriginal($field);
            $new[$field] = $value;
        }
        AuditService::log('task_updated', Task::class, $task->id, $old, $new);
    }

    public function deleted(Task $task): void
    {
        AuditService::log('task_deleted', Task::class, $task->id, [
            'planning_id' => $task->planning_id,
            'title' => $task->title,
        ]);
    }
}
