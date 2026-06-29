<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Services\TaskService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    use ApiResponse;

    protected TaskService $taskService;

    public function __construct(TaskService $taskService)
    {
        $this->taskService = $taskService;
    }

    public function index(Request $request)
    {
        $tasks = $this->taskService->list($request->only(['status', 'priority', 'user_id', 'planning_id', 'per_page']));

        return $this->paginatedResponse($tasks);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'planning_id' => ['required', 'exists:plannings,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:pending,in_progress,completed,cancelled'],
            'priority' => ['sometimes', 'in:low,medium,high,critical'],
            'due_date' => ['nullable', 'date'],
        ]);

        $validated['created_by'] = $request->user()->id;

        $task = $this->taskService->create($validated);

        return $this->successResponse($task->load(['user', 'planning', 'creator']), 'Task created', 201);
    }

    public function show(Task $task)
    {
        return $this->successResponse($task->load(['user', 'planning', 'creator']));
    }

    public function update(Request $request, Task $task)
    {
        $validated = $request->validate([
            'user_id' => ['sometimes', 'exists:users,id'],
            'planning_id' => ['sometimes', 'exists:plannings,id'],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:pending,in_progress,completed,cancelled'],
            'priority' => ['sometimes', 'in:low,medium,high,critical'],
            'due_date' => ['nullable', 'date'],
        ]);

        $task = $this->taskService->update($task, $validated);

        return $this->successResponse($task->load(['user', 'planning', 'creator']), 'Task updated');
    }

    public function destroy(Task $task)
    {
        $this->taskService->delete($task);

        return $this->successResponse(null, 'Task deleted');
    }

    public function myTasks(Request $request)
    {
        $tasks = $this->taskService->myTasks($request->user(), $request->only(['per_page']));

        return $this->paginatedResponse($tasks);
    }
}
