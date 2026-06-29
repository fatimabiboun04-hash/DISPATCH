<?php

namespace App\Http\Controllers;

use App\Models\PlanningTemplate;
use App\Services\PlanningService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class PlanningTemplateController extends Controller
{
    use ApiResponse;

    protected PlanningService $planningService;

    public function __construct(PlanningService $planningService)
    {
        $this->planningService = $planningService;
    }

    public function index()
    {
        $templates = PlanningTemplate::with(['items.user', 'items.shift', 'items.team', 'creator'])
            ->latest()
            ->paginate(20);

        return $this->paginatedResponse($templates);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'week_number' => 'required|integer|min:1|max:53',
            'year' => 'required|integer|min:2020|max:2099',
        ]);

        try {
            $template = $this->planningService->createTemplateFromWeek(
                $validated['name'],
                $validated['description'] ?? null,
                $validated['week_number'],
                $validated['year']
            );

            return $this->successResponse($template, 'Template created', 201);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    public function show(PlanningTemplate $planningTemplate)
    {
        return $this->successResponse(
            $planningTemplate->load(['items.user', 'items.shift', 'items.team', 'creator'])
        );
    }

    public function update(Request $request, PlanningTemplate $planningTemplate)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $planningTemplate->update($validated);

        return $this->successResponse(
            $planningTemplate->load(['items.user', 'items.shift', 'items.team', 'creator']),
            'Template updated'
        );
    }

    public function destroy(PlanningTemplate $planningTemplate)
    {
        $planningTemplate->delete();

        return response()->noContent();
    }

    public function duplicate(Request $request, PlanningTemplate $planningTemplate)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $duplicate = $this->planningService->duplicateTemplate($planningTemplate, $validated['name']);

        return $this->successResponse($duplicate, 'Template duplicated', 201);
    }

    public function load(Request $request, PlanningTemplate $planningTemplate)
    {
        $validated = $request->validate([
            'week_number' => 'required|integer|min:1|max:53',
            'year' => 'required|integer|min:2020|max:2099',
        ]);

        $result = $this->planningService->loadTemplateIntoWeek(
            $planningTemplate,
            $validated['week_number'],
            $validated['year']
        );

        $createdPlannings = collect($result['created']);
        if ($createdPlannings->isNotEmpty()) {
            app(\App\Services\NotificationService::class)
                ->notifyPlanningBatchCreated($createdPlannings);
        }

        return $this->successResponse($result, 'Template loaded into week');
    }
}
