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
use Carbon\Carbon;
use Illuminate\Http\Request;
use InvalidArgumentException;

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
            'pause_end' => 'required|date_format:H:i',
            'user_id' => 'required_without:team_id|exists:users,id',
            'team_id' => 'required_without:user_id|exists:teams,id',
        ]);

        $planning = Planning::findOrFail($validated['planning_id']);

        try {
            if (! empty($validated['user_id'])) {
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
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    /**
     * Update pause time window
     */
    public function update(Request $request, Pause $pause)
    {
        $validated = $request->validate([
            'pause_start' => 'required|date_format:H:i',
            'pause_end' => 'required|date_format:H:i',
        ]);

        try {
            $updated = $this->pauseService->update(
                $pause,
                $validated['pause_start'],
                $validated['pause_end']
            );
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

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

        return response()->noContent();
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
     * Get pauses for multiple planning IDs in a single query.
     * Expects: GET /v1/pauses/batch?planning_ids=1,2,3
     * Returns: { [planningId]: [...pauses] }
     */
    public function batchByPlannings(Request $request)
    {
        $validated = $request->validate([
            'planning_ids' => 'required|string',
        ]);

        $ids = array_map('intval', explode(',', $validated['planning_ids']));
        $ids = array_filter($ids, fn ($id) => $id > 0);

        if (empty($ids)) {
            return $this->errorResponse('No valid planning IDs provided', 422);
        }

        $grouped = $this->pauseService->getByPlanningBatch($ids);

        return $this->successResponse($grouped);
    }

    /**
     * Get active pauses for today (dashboard widget)
     */
    public function activeToday()
    {
        $pauses = $this->pauseService->getActiveToday();

        return $this->successResponse($pauses);
    }

    /**
     * Start self-pause for the authenticated employee
     */
    public function startMyPause(Request $request)
    {
        $user = $request->user();

        $planning = Planning::where('user_id', $user->id)
            ->whereDate('date', now()->toDateString())
            ->first();

        if (! $planning) {
            return $this->errorResponse('Aucun planning pour aujourd\'hui', 404);
        }

        $activePause = Pause::where('user_id', $user->id)
            ->where('planning_id', $planning->id)
            ->where('pause_end', '>', now())
            ->first();

        if ($activePause) {
            return $this->errorResponse('Une pause est déjà en cours', 422);
        }

        $now = now()->format('H:i');
        $end = now()->addMinutes(30)->format('H:i');

        try {
            $pause = $this->pauseService->createForUser($user, $planning, $now, $end);

            return $this->successResponse($pause->load(['user', 'planning.shift']), 'Pause démarrée', 201);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    /**
     * Stop the current self-pause for the authenticated employee
     */
    public function stopMyPause(Request $request)
    {
        $user = $request->user();

        $planning = Planning::where('user_id', $user->id)
            ->whereDate('date', now()->toDateString())
            ->first();

        if (! $planning) {
            return $this->errorResponse('Aucun planning pour aujourd\'hui', 404);
        }

        $activePause = Pause::where('user_id', $user->id)
            ->where('planning_id', $planning->id)
            ->where('pause_end', '>', now())
            ->first();

        if (! $activePause) {
            return $this->errorResponse('Aucune pause active', 404);
        }

        $now = now()->format('H:i');

        try {
            $updated = $this->pauseService->update($activePause, Carbon::parse($activePause->pause_start)->format('H:i'), $now);

            return $this->successResponse($updated->load(['user', 'planning.shift']), 'Pause terminée');
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }
}
