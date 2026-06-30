<?php

namespace App\Http\Controllers;

use App\Models\Pause;
use App\Models\Planning;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditService;
use App\Services\PauseService;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
     * Paginated list with advanced filters.
     * GET /v1/pauses?search=&user_id=&team_id=&status=&type=&date_from=&date_to=&per_page=
     */
    public function index(Request $request): JsonResponse
    {
        $query = Pause::with([
            'user:id,name,email,avatar',
            'planning.shift:id,name,start_time,end_time,type,color',
            'planning:id,date,shift_id,user_id',
            'team:id,name,color',
            'canceller:id,name',
            'creator:id,name',
        ]);

        if ($search = $request->get('search')) {
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', "%{$search}%"));
        }

        if ($userId = $request->get('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($teamId = $request->get('team_id')) {
            $query->where('team_id', $teamId);
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($type = $request->get('type')) {
            $query->where('type', $type);
        }

        if ($dateFrom = $request->get('date_from')) {
            $query->where('pause_start', '>=', Carbon::parse($dateFrom)->startOfDay());
        }

        if ($dateTo = $request->get('date_to')) {
            $query->where('pause_end', '<=', Carbon::parse($dateTo)->endOfDay());
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $pauses = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return $this->successResponse($pauses);
    }

    /**
     * GET /v1/pauses/{pause}
     */
    public function show(Pause $pause): JsonResponse
    {
        return $this->successResponse(
            $pause->load(['user', 'planning.shift', 'team', 'canceller', 'creator'])
        );
    }

    /**
     * POST /v1/pauses
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'planning_id' => 'required|exists:plannings,id',
            'pause_start' => 'required|date_format:H:i',
            'pause_end' => 'required|date_format:H:i',
            'type' => 'sometimes|in:' . implode(',', array_keys(Pause::TYPES)),
            'reason' => 'nullable|string|max:500',
            'duration_minutes' => 'nullable|integer|min:1|max:1440',
            'user_id' => 'required_without:team_id|exists:users,id',
            'team_id' => 'required_without:user_id|exists:teams,id',
        ]);

        $planning = Planning::findOrFail($validated['planning_id']);

        try {
            if (!empty($validated['user_id'])) {
                $user = User::findOrFail($validated['user_id']);
                $pause = $this->pauseService->createForUser(
                    $user,
                    $planning,
                    $validated['pause_start'],
                    $validated['pause_end'],
                    $validated['type'] ?? 'break',
                    $validated['reason'] ?? null,
                    $validated['duration_minutes'] ?? null,
                );

                AuditService::log('pause_created', Pause::class, $pause->id, null, [
                    'type' => $pause->type,
                    'status' => $pause->status,
                    'user_id' => $user->id,
                    'planning_id' => $planning->id,
                ]);

                Cache::forget('dashboard.stats');
                Cache::forget('dashboard.active-pauses');

                return $this->successResponse(
                    $pause->load(['user', 'planning.shift', 'team', 'canceller', 'creator']),
                    'Pause créée',
                    201
                );
            }

            $team = Team::findOrFail($validated['team_id']);
            $pauses = $this->pauseService->createForTeam(
                $team,
                $planning,
                $validated['pause_start'],
                $validated['pause_end'],
                $validated['type'] ?? 'break',
                $validated['reason'] ?? null,
            );

            AuditService::log('pause_created_team', Team::class, $team->id, null, [
                'planning_id' => $planning->id,
                'count' => count($pauses),
                'type' => $validated['type'] ?? 'break',
            ]);

            Cache::forget('dashboard.stats');
            Cache::forget('dashboard.active-pauses');

            return $this->successResponse($pauses, 'Pauses créées pour l\'équipe', 201);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    /**
     * PUT /v1/pauses/{pause}
     */
    public function update(Request $request, Pause $pause): JsonResponse
    {
        if (!$pause->isEditable()) {
            return $this->errorResponse('Impossible de modifier une pause avec le statut: ' . $pause->statusLabel, 422);
        }

        $validated = $request->validate([
            'pause_start' => 'sometimes|required|date_format:H:i',
            'pause_end' => 'sometimes|required|date_format:H:i',
            'type' => 'sometimes|in:' . implode(',', array_keys(Pause::TYPES)),
            'reason' => 'nullable|string|max:500',
            'duration_minutes' => 'nullable|integer|min:1|max:1440',
        ]);

        try {
            $updated = $this->pauseService->update($pause, $validated);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        AuditService::log('pause_updated', Pause::class, $pause->id);

        Cache::forget('dashboard.stats');
        Cache::forget('dashboard.active-pauses');

        return $this->successResponse($updated, 'Pause mise à jour');
    }

    /**
     * POST /v1/pauses/{pause}/cancel
     */
    public function cancel(Pause $pause): JsonResponse
    {
        try {
            $updated = $this->pauseService->cancel($pause);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        AuditService::log('pause_cancelled', Pause::class, $pause->id);

        Cache::forget('dashboard.stats');
        Cache::forget('dashboard.active-pauses');

        return $this->successResponse($updated, 'Pause annulée');
    }

    /**
     * POST /v1/pauses/{pause}/complete
     */
    public function complete(Pause $pause): JsonResponse
    {
        try {
            $updated = $this->pauseService->completeEarly($pause);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        AuditService::log('pause_completed_early', Pause::class, $pause->id);

        Cache::forget('dashboard.stats');
        Cache::forget('dashboard.active-pauses');

        return $this->successResponse($updated, 'Pause terminée');
    }

    /**
     * DELETE /v1/pauses/{pause}
     */
    public function destroy(Pause $pause)
    {
        $pauseId = $pause->id;
        $pause->delete();

        AuditService::log('pause_deleted', Pause::class, $pauseId);

        Cache::forget('dashboard.stats');
        Cache::forget('dashboard.active-pauses');

        return response()->noContent();
    }

    /**
     * GET /v1/pauses/stats
     */
    public function stats(): JsonResponse
    {
        $stats = $this->pauseService->getStats();

        return $this->successResponse($stats);
    }

    /**
     * GET /v1/pauses/planning/{planningId}
     */
    public function byPlanning(int $planningId): JsonResponse
    {
        $pauses = $this->pauseService->getByPlanning($planningId);

        return $this->successResponse($pauses);
    }

    /**
     * GET /v1/pauses/batch?planning_ids=1,2,3
     */
    public function batchByPlannings(Request $request): JsonResponse
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
     * GET /v1/pauses/active-today
     */
    public function activeToday(): JsonResponse
    {
        $pauses = $this->pauseService->getActiveToday();

        return $this->successResponse($pauses);
    }

    /**
     * POST /v1/me/pauses/start
     */
    public function startMyPause(Request $request): JsonResponse
    {
        $user = $request->user();

        $planning = Planning::where('user_id', $user->id)
            ->whereDate('date', now()->toDateString())
            ->first();

        if (!$planning) {
            return $this->errorResponse('Aucun planning pour aujourd\'hui', 404);
        }

        $activePause = Pause::where('user_id', $user->id)
            ->where('planning_id', $planning->id)
            ->whereIn('status', ['scheduled', 'active'])
            ->where('pause_end', '>', now())
            ->first();

        if ($activePause) {
            return $this->errorResponse('Une pause est déjà en cours', 422);
        }

        $now = now()->format('H:i');
        $end = now()->addMinutes(30)->format('H:i');

        try {
            $pause = $this->pauseService->createForUser($user, $planning, $now, $end, 'break');

            return $this->successResponse(
                $pause->load(['user', 'planning.shift', 'team', 'canceller', 'creator']),
                'Pause démarrée',
                201
            );
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    /**
     * POST /v1/me/pauses/stop
     */
    public function stopMyPause(Request $request): JsonResponse
    {
        $user = $request->user();

        $planning = Planning::where('user_id', $user->id)
            ->whereDate('date', now()->toDateString())
            ->first();

        if (!$planning) {
            return $this->errorResponse('Aucun planning pour aujourd\'hui', 404);
        }

        $activePause = Pause::where('user_id', $user->id)
            ->where('planning_id', $planning->id)
            ->whereIn('status', ['scheduled', 'active'])
            ->where('pause_end', '>', now())
            ->first();

        if (!$activePause) {
            return $this->errorResponse('Aucune pause active', 404);
        }

        try {
            $updated = $this->pauseService->completeEarly($activePause);

            return $this->successResponse(
                $updated->load(['user', 'planning.shift', 'team', 'canceller', 'creator']),
                'Pause terminée'
            );
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }
}
