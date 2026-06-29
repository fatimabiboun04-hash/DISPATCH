<?php

namespace App\Http\Controllers;

use App\Services\PlanningService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class PlanningSandboxController extends Controller
{
    use ApiResponse;

    protected PlanningService $planningService;

    public function __construct(PlanningService $planningService)
    {
        $this->planningService = $planningService;
    }

    public function generate(Request $request)
    {
        $validated = $request->validate([
            'week_number' => 'required|integer|min:1|max:53',
            'year' => 'required|integer|min:2020|max:2099',
            'session_id' => 'required|string|size:36', // UUID
        ]);

        $preview = $this->planningService->generateSandboxPreview(
            $validated['week_number'],
            $validated['year'],
            $validated['session_id']
        );

        return $this->successResponse($preview, 'Sandbox preview generated');
    }

    public function preview(Request $request)
    {
        $validated = $request->validate([
            'session_id' => 'required|string|size:36',
        ]);

        $items = \App\Models\PlanningSandboxItem::where('session_id', $validated['session_id'])
            ->with(['user', 'shift', 'team'])
            ->get();

        return $this->successResponse([
            'session_id' => $validated['session_id'],
            'items' => $items,
            'count' => $items->count(),
        ]);
    }

    public function accept(Request $request)
    {
        $validated = $request->validate([
            'session_id' => 'required|string|size:36',
        ]);

        try {
            $result = $this->planningService->acceptSandboxPreview($validated['session_id']);

            $createdPlannings = collect($result['created']);
            if ($createdPlannings->isNotEmpty()) {
                app(\App\Services\NotificationService::class)
                    ->notifyPlanningBatchCreated($createdPlannings);
            }

            return $this->successResponse($result, 'Sandbox accepted and planning created');
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    public function cancel(Request $request)
    {
        $validated = $request->validate([
            'session_id' => 'required|string|size:36',
        ]);

        $this->planningService->cancelSandboxPreview($validated['session_id']);

        return $this->successResponse(null, 'Sandbox preview cancelled');
    }
}
