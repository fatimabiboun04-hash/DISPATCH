<?php

// app/Http/Controllers/PauseController.php

namespace App\Http\Controllers;

use App\Models\Pause;
use App\Models\Planning;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditService;
use App\Services\PauseService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class PauseController extends Controller
{
    use ApiResponse;

    protected PauseService $pauseService;

    public function __construct(PauseService $pauseService)
    {
        $this->pauseService = $pauseService;
    }

    /**
     * Create pause for single user or team
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'planning_id' => 'required|exists:plannings,id',
            'pause_start' => 'required|date_format:H:i',
            'pause_end' => 'required|date_format:H:i|after:pause_start',
            'user_id' => 'required_without:team_id|exists:users,id',
            'team_id' => 'required_without:user_id|exists:teams,id',
        ]);

        $planning = Planning::findOrFail($validated['planning_id']);

        if (!empty($validated['user_id'])) {
            $user = User::findOrFail($validated['user_id']);
            $pause = $this->pauseService->createForUser(
                $user,
                $planning,
                $validated['pause_start'],
                $validated['pause_end']
            );
            
            AuditService::log('pause_created', Pause::class, $pause->id);
            
            return $this->successResponse($pause->load(['user', 'planning.shift']), 'Pause created', 201);
        }

        // Team assignment
        $team = Team::findOrFail($validated['team_id']);
        $pauses = $this->pauseService->createForTeam(
            $team,
            $planning,
            $validated['pause_start'],
            $validated['pause_end']
        );

        AuditService::log('pause_created_team', Team::class, $team->id, null, [
            'planning_id' => $planning->id,
            'count' => count($pauses),
        ]);

        return $this->successResponse($pauses, 'Team pauses created', 201);
    }

    /**
     * Update pause time window
     */
    public function update(Request $request, Pause $pause)
    {
        $validated = $request->validate([
            'pause_start' => 'required|date_format:H:i',
            'pause_end' => 'required|date_format:H:i|after:pause_start',
        ]);

        $updated = $this->pauseService->update(
            $pause,
            $validated['pause_start'],
            $validated['pause_end']
        );

        AuditService::log('pause_updated', Pause::class, $pause->id);

        return $this->successResponse($updated->load(['user', 'planning.shift']), 'Pause updated');
    }

    /**
     * Delete pause
     */
    public function destroy(Pause $pause)
    {
        $pauseId = $pause->id;
        $pause->delete();

        AuditService::log('pause_deleted', Pause::class, $pauseId);

        return $this->successResponse(null, 'Pause deleted', 204);
    }

    /**
     * Get pauses by planning
     */
    public function byPlanning(int $planningId)
    {
        $pauses = $this->pauseService->getByPlanning($planningId);
        return $this->successResponse($pauses);
    }

    /**
     * Get active pauses for today (dashboard widget)
     */
    public function activeToday()
    {
        $pauses = $this->pauseService->getActiveToday();
        return $this->successResponse($pauses);
    }
}