<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePlanningRequest;
use App\Models\Planning;
use App\Models\Shift;
use App\Models\User;
use App\Observers\PlanningObserver;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\PlanningService;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlanningController extends Controller
{
    use ApiResponse;

    protected PlanningService $planningService;

    protected NotificationService $notificationService;

    public function __construct(PlanningService $planningService, NotificationService $notificationService)
    {
        $this->planningService = $planningService;
        $this->notificationService = $notificationService;
    }

    /**
     * List planning with filters.
     */
    public function index(Request $request)
    {
        $query = Planning::with(['user', 'shift', 'team', 'creator']);

        if ($request->has('week_number') && $request->has('year')) {
            $query->where('week_number', $request->week_number)
                ->where('year', $request->year);
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('date')) {
            $query->where('date', $request->date);
        }

        //  team filter for planning grid
        if ($request->has('team_id')) {
            $query->where('team_id', $request->team_id);
        }

        //  shift filter for planning grid
        if ($request->has('shift_id')) {
            $query->where('shift_id', $request->shift_id);
        }

        //  locked status filter for planning history view
        if ($request->has('is_locked')) {
            $query->where('is_locked', filter_var($request->is_locked, FILTER_VALIDATE_BOOLEAN));
        }

        $plannings = $query->paginate(50);

        return $this->paginatedResponse($plannings);
    }

    /**
     * Store new planning assignment.
     */
    public function store(StorePlanningRequest $request)
    {
        $user = User::findOrFail($request->user_id);
        $shift = Shift::findOrFail($request->shift_id);
        $date = Carbon::parse($request->date);

        // Validate business rules
        $validation = $this->planningService->validateAssignment($user, $shift, $date);

        if (! $validation['valid']) {
            return $this->errorResponse('Validation failed', 422, ['planning' => $validation['errors']]);
        }

        $planning = Planning::create([
            'user_id' => $user->id,
            'team_id' => $request->team_id,
            'shift_id' => $shift->id,
            'date' => $date->toDateString(),
            'week_number' => $date->isoWeek(),
            'year' => $date->isoWeekYear(),
            'notes' => $request->notes,
            'created_by' => auth()->id(),
        ]);

        AuditService::log('created', Planning::class, $planning->id);

        $this->notificationService->notifyPlanningCreated($planning);

        return $this->successResponse($planning->load(['user', 'shift', 'team']), 'Planning created', 201);
    }

    public function show(Planning $planning)
    {
        return $this->successResponse($planning->load(['user', 'shift', 'team', 'pointages']));
    }

    public function update(StorePlanningRequest $request, Planning $planning)
    {
        if ($planning->is_locked) {
            return $this->errorResponse('Cannot modify a locked planning record', 423);
        }

        $user = User::findOrFail($request->user_id);
        $shift = Shift::findOrFail($request->shift_id);
        $date = Carbon::parse($request->date);

        $validation = $this->planningService->validateAssignment($user, $shift, $date, $planning->id);

        if (! $validation['valid']) {
            return $this->errorResponse('Validation failed', 422, ['planning' => $validation['errors']]);
        }

        $oldData = $planning->toArray();

        $planning->update([
            'user_id' => $user->id,
            'team_id' => $request->team_id,
            'shift_id' => $shift->id,
            'date' => $date->toDateString(),
            'week_number' => $date->isoWeek(),
            'year' => $date->isoWeekYear(),
            'notes' => $request->notes,
        ]);

        AuditService::log('updated', Planning::class, $planning->id, $oldData, $planning->fresh()->toArray());

        $this->notificationService->notifyPlanningUpdated($planning);

        return $this->successResponse($planning->load(['user', 'shift', 'team']), 'Planning updated');
    }

    public function destroy(Planning $planning)
    {
        if ($planning->is_locked) {
            return $this->errorResponse('Cannot delete a locked planning record', 423);
        }

        $planningId = $planning->id;

        $this->notificationService->notifyPlanningDeleted($planning);

        $planning->delete();

        AuditService::log('deleted', Planning::class, $planningId);

        return response()->noContent();
    }

    /**
     * Get smart suggestions for empty slot.
     */
    public function suggestEmployees(Request $request)
    {
        $validated = $request->validate([
            'shift_id' => 'required|exists:shifts,id',
            'date' => 'required|date',
            'team_id' => 'nullable|exists:teams,id',
        ]);

        $shift = Shift::findOrFail($validated['shift_id']);
        $date = Carbon::parse($validated['date']);

        $suggestions = $this->planningService->getSuggestions($shift, $date, $validated['team_id'] ?? null);

        return $this->successResponse($suggestions, 'Suggestions generated');
    }

    /**
     * Get current user's planning (employee self-service).
     */
    public function myPlanning(Request $request)
    {
        $user = $request->user();

        $plannings = Planning::where('user_id', $user->id)
            ->with('shift')
            ->when($request->has('week_number'), function ($query) use ($request) {
                $query->where('week_number', $request->week_number)
                    ->where('year', $request->year ?? now()->year);
            })
            ->orderBy('date')
            ->get();

        return $this->successResponse($plannings);
    }

    /**
     * Admin override for Friday planning lock.
     */
    public function overrideLock(Request $request)
    {
        $validated = $request->validate([
            'planning_id' => 'required|exists:plannings,id',
        ]);

        $planning = Planning::findOrFail($validated['planning_id']);

        $planning->update(['is_locked' => false]);

        AuditService::log('lock_overridden', Planning::class, $planning->id);

        return $this->successResponse($planning->load(['user', 'shift', 'team']), 'Planning lock overridden');
    }

    /**
     * Lock a single planning assignment (individual lock).
     * Bypasses the planning.locked middleware intentionally.
     */
    public function lockPlanning(Planning $planning)
    {
        if ($planning->is_locked) {
            return $this->successResponse($planning->load(['user', 'shift', 'team']), 'Planning already locked');
        }

        $planning->update(['is_locked' => true]);

        AuditService::log('planning_locked', Planning::class, $planning->id);

        return $this->successResponse($planning->load(['user', 'shift', 'team']), 'Planning locked');
    }

    /**
     * Generate next week's planning automatically (Friday workflow)
     *
     * Implements the prompt requirement:
     * "Every Friday: Lock random changes → Button: 'Generate next week planning'
     *  Smart Distribution: System selects employees based on:
     *  - Under 44h
     *  - Not on leave
     *  - High rating"
     */
    public function generateNextWeek(Request $request)
    {
        DB::beginTransaction();

        try {
            // ── STEP 1: Lock current week first ───────────────────────────────
            $currentWeek = now()->isoWeek();
            $currentYear = now()->isoWeekYear();

            Planning::where('week_number', $currentWeek)
                ->where('year', $currentYear)
                ->where('is_locked', false)
                ->update(['is_locked' => true]);

            AuditService::log('week_locked', Planning::class, 0, null, [
                'week_number' => $currentWeek,
                'year' => $currentYear,
                'triggered_by' => 'generate_next_week',
            ]);

            // ── STEP 2: Generate next week ────────────────────────────────────
            $nextWeek = now()->addWeek();
            $weekNumber = $nextWeek->isoWeek();
            $year = $nextWeek->isoWeekYear();

            $employees = User::employees()->active()->get();

            $generated = [];
            $errors = [];

            $allExistingPlannings = Planning::where('week_number', $weekNumber)
                ->where('year', $year)
                ->get(['user_id', 'date', 'is_locked'])
                ->groupBy('user_id');

            PlanningObserver::$bulkCreating = true;

            try {
                foreach ($employees as $employee) {
                    try {
                        $suggestions = $this->planningService->getSuggestionsForEmployee($employee, $weekNumber, $year);

                        if (empty($suggestions)) {
                            $errors[] = "No suitable shift found for {$employee->name}";
                            continue;
                        }

                        $employeePlannings = $allExistingPlannings->get($employee->id, collect());
                        $assignedDateStrings = $employeePlannings->pluck('date')
                            ->map(fn ($d) => $d instanceof Carbon ? $d->toDateString() : (string) $d)
                            ->toArray();
                        $lockedDateStrings = $employeePlannings->filter(fn ($p) => $p->is_locked)
                            ->pluck('date')
                            ->map(fn ($d) => $d instanceof Carbon ? $d->toDateString() : (string) $d)
                            ->toArray();
                        $assignedSet = array_flip($assignedDateStrings);
                        $lockedSet = array_flip($lockedDateStrings);

                        foreach ($suggestions as $suggestion) {
                            $date = $suggestion['date'];

                            if (isset($assignedSet[$date])) {
                                continue;
                            }

                            if (isset($lockedSet[$date])) {
                                continue;
                            }

                            $planning = Planning::create([
                                'user_id' => $employee->id,
                                'shift_id' => $suggestion['shift_id'],
                                'date' => $date,
                                'week_number' => $weekNumber,
                                'year' => $year,
                                'created_by' => auth()->id(),
                                'notes' => "Auto-generated (match: {$suggestion['match_percentage']}%)",
                            ]);

                            $generated[] = $planning->load(['user', 'shift']);
                            $assignedSet[$date] = true;
                        }
                    } catch (\Throwable $e) {
                        $errors[] = "Error for {$employee->name}: ".$e->getMessage();
                    }
                }
            } finally {
                PlanningObserver::$bulkCreating = false;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            PlanningObserver::$bulkCreating = false;

            return $this->errorResponse('Failed to generate next week planning: '.$e->getMessage(), 500);
        }

        // ── STEP 3: Send notifications ────────────────────────────────────────
        if (! empty($generated)) {
            foreach ($generated as $planning) {
                \App\Jobs\SendPlanningCompletedEmailsJob::dispatch($planning);
            }
            \App\Jobs\SendWeeklyPlanningReminderJob::dispatch($weekNumber, $year);
            \App\Services\PlanningService::bumpSuggestionsVersion();
        }

        return $this->successResponse([
            'generated_count' => count($generated),
            'week_number' => $weekNumber,
            'year' => $year,
            'current_week_locked' => true,
            'planning' => $generated,
            'errors' => $errors,
        ], 'Next week planning generated successfully');
    }

    /**
     * Lock all plannings for current week (Friday lock)
     */
    public function lockCurrentWeek(Request $request)
    {

        $currentWeek = now()->isoWeek();
        $currentYear = now()->isoWeekYear();

        $updated = Planning::where('week_number', $currentWeek)
            ->where('year', $currentYear)
            ->where('is_locked', false)
            ->update(['is_locked' => true]);

        AuditService::log('week_locked', Planning::class, 0, null, [
            'week_number' => $currentWeek,
            'year' => $currentYear,
            'count' => $updated,
        ]);

        return $this->successResponse([
            'week_number' => $currentWeek,
            'year' => $currentYear,
            'locked_count' => $updated,
        ], 'Current week planning locked');
    }

    /**
     * Admin: get a specific employee's planning (for profile tabs).
     * Not subject to planning.locked middleware.
     */
    public function employeePlanning(Request $request, User $employee)
    {
        $plannings = Planning::where('user_id', $employee->id)
            ->with(['shift', 'team'])
            ->when($request->has('week_number'), function ($query) use ($request) {
                $query->where('week_number', $request->week_number)
                    ->where('year', $request->year ?? now()->isoWeekYear());
            })
            ->orderBy('date')
            ->paginate(20);

        return $this->paginatedResponse($plannings);
    }

    // ─────────────────────────────────────────────────────────
    //  BATCH OPERATIONS
    // ─────────────────────────────────────────────────────────

    public function batchDelete(Request $request)
    {
        $validated = $request->validate([
            'planning_ids' => 'required|array',
            'planning_ids.*' => 'exists:plannings,id',
        ]);

        $deleted = $this->planningService->batchDelete($validated['planning_ids']);

        return $this->successResponse(['deleted_count' => $deleted], "{$deleted} planning records deleted");
    }

    public function batchUpdateShift(Request $request)
    {
        $validated = $request->validate([
            'planning_ids' => 'required|array',
            'planning_ids.*' => 'exists:plannings,id',
            'shift_id' => 'required|exists:shifts,id',
        ]);

        $updated = $this->planningService->batchUpdateShift($validated['planning_ids'], $validated['shift_id']);

        return $this->successResponse(['updated_count' => $updated], "{$updated} planning records updated");
    }

    public function batchAssignEmployee(Request $request)
    {
        $validated = $request->validate([
            'planning_ids' => 'required|array',
            'planning_ids.*' => 'exists:plannings,id',
            'user_id' => 'required|exists:users,id',
        ]);

        $updated = $this->planningService->batchAssignEmployee($validated['planning_ids'], $validated['user_id']);

        return $this->successResponse(['updated_count' => $updated], "{$updated} planning records reassigned");
    }

    public function duplicateDay(Request $request)
    {
        $validated = $request->validate([
            'source_date' => 'required|date',
            'target_date' => 'required|date|after:source_date',
        ]);

        $result = $this->planningService->duplicateDay($validated['source_date'], $validated['target_date']);

        return $this->successResponse($result, $result['created_count'] . ' plannings duplicated');
    }

    public function validateBatch(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.user_id' => 'required|exists:users,id',
            'items.*.shift_id' => 'required|exists:shifts,id',
            'items.*.date' => 'required|date',
            'items.*.exclude_planning_id' => 'nullable|exists:plannings,id',
        ]);

        $conflicts = $this->planningService->validateBatch($validated['items']);

        return $this->successResponse([
            'conflicts' => $conflicts,
            'conflict_count' => count($conflicts),
            'valid' => empty($conflicts),
        ], 'Batch validation completed');
    }
}
