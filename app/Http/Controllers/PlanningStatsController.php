<?php

namespace App\Http\Controllers;

use App\Services\PlanningService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class PlanningStatsController extends Controller
{
    use ApiResponse;

    protected PlanningService $planningService;

    public function __construct(PlanningService $planningService)
    {
        $this->planningService = $planningService;
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'week_number' => 'required|integer|min:1|max:53',
            'year' => 'required|integer|min:2020|max:2099',
        ]);

        $stats = $this->planningService->getStatistics(
            $validated['week_number'],
            $validated['year']
        );

        return $this->successResponse($stats, 'Planning statistics');
    }
}
