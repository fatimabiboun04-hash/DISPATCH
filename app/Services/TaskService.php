<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;

class TaskService
{
    public function list(array $filters = [])
    {
        $query = Task::with(['user', 'planning', 'creator']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (! empty($filters['planning_id'])) {
            $query->where('planning_id', $filters['planning_id']);
        }

        return $query->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function create(array $data): Task
    {
        return Task::create($data);
    }

    public function update(Task $task, array $data): Task
    {
        $task->update($data);

        return $task->fresh();
    }

    public function delete(Task $task): void
    {
        $task->delete();
    }

    public function myTasks(User $user, array $filters = [])
    {
        return Task::with(['planning'])
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }
}
