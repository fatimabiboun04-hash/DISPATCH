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
use Illuminate\Support\Facades\Cache;
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
        $query = Planning::with(['user', 'shift.skills', 'team', 'creator'])->withCount('tasks');


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

        //  skill filter — planning where shift has this skill
        if ($request->has('skill_id')) {
            $query->whereHas('shift.skills', fn ($q) => $q->where('skills.id', $request->skill_id));
        }

        //  text search across user name and shift name
        if ($search = $request->search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('shift', fn ($q) => $q->where('name', 'like', "%{$search}%"));
            });
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

        Cache::forget('dashboard.stats');
        Cache::forget('dashboard.coverage');

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

        Cache::forget('dashboard.stats');
        Cache::forget('dashboard.coverage');

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

        Cache::forget('dashboard.stats');
        Cache::forget('dashboard.coverage');

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
     * Single source of truth — reads directly from Planning table.
     */
    public function myPlanning(Request $request)
    {
        $user = $request->user();

        $plannings = Planning::where('user_id', $user->id)
            ->with([
                'shift.skills',
                'team',
                'pauses',
                'tasks',
                'pointages',
            ])
            ->withCount('tasks')
            ->when($request->has('week_number'), function ($query) use ($request) {
                $query->where('week_number', $request->week_number)
                    ->where('year', $request->year ?? now()->year);
            })
            ->orderBy('date')
            ->orderBy('shift_id')
            ->get()
            ->map(function ($planning) {
                $shift = $planning->shift;
                $totalMinutes = 0;
                $pauseMinutes = 0;

                if ($shift) {
                    $start = \Carbon\Carbon::parse($shift->start_time);
                    $end   = \Carbon\Carbon::parse($shift->end_time);
                    $totalMinutes = $start->diffInMinutes($end) - ($shift->break_minutes ?? 0);
                    $pauseMinutes = $shift->break_minutes ?? 0;
                }

                $planning->setAttribute('duration_hours', round($totalMinutes / 60, 1));
                $planning->setAttribute('break_minutes', $pauseMinutes);

                // Week-level lock: if all plannings for this user/week are locked
                $planning->setAttribute('week_locked', $planning->is_locked);

                return $planning;
            });

        return $this->successResponse($plannings);
    }

    /**
     * Employee dashboard — today's shift, next shift, weekly stats.
     */
    public function myDashboard(Request $request)
    {
        $user = $request->user();
        $today = now()->format('Y-m-d');
        $weekNum = now()->weekOfYear;
        $year = now()->year;

        $todayPlanning = Planning::where('user_id', $user->id)
            ->where('date', $today)
            ->with(['shift.skills', 'team', 'pauses', 'tasks'])
            ->withCount('tasks')
            ->first();

        // Next upcoming shift (from tomorrow onwards, ordered by date)
        $nextPlanning = Planning::where('user_id', $user->id)
            ->where('date', '>', $today)
            ->with(['shift', 'team'])
            ->orderBy('date')
            ->orderBy('shift_id')
            ->first();

        // Weekly stats from the planning records
        $weekPlannings = Planning::where('user_id', $user->id)
            ->where('week_number', $weekNum)
            ->where('year', $year)
            ->with('shift')
            ->get();

        $weeklyHours = 0;
        $shiftCount = $weekPlannings->count();
        $completedShifts = 0;

        foreach ($weekPlannings as $p) {
            if ($p->shift) {
                $start = \Carbon\Carbon::parse($p->shift->start_time);
                $end   = \Carbon\Carbon::parse($p->shift->end_time);
                $mins  = $start->diffInMinutes($end) - ($p->shift->break_minutes ?? 0);
                $weeklyHours += round($mins / 60, 1);
            }
            // Count plannings before today as "completed"
            if ($p->date < $today) {
                $completedShifts++;
            }
        }

        $overtime = max(0, $weeklyHours - 44);

        // Current active pause
        $activePause = \App\Models\Pause::where('user_id', $user->id)
            ->whereIn('status', ['active', 'scheduled'])
            ->whereDate('pause_start', $today)
            ->first();

        // Today's pointage (check-in status)
        $todayPointage = \App\Models\Pointage::where('user_id', $user->id)
            ->whereDate('check_in_at', $today)
            ->first();

        return $this->successResponse([
            'today'           => $todayPlanning,
            'next'            => $nextPlanning,
            'weekly_hours'    => round($weeklyHours, 1),
            'weekly_overtime' => round($overtime, 1),
            'shifts_count'    => $shiftCount,
            'completed_shifts' => $completedShifts,
            'remaining_shifts' => max(0, $shiftCount - $completedShifts),
            'current_week'    => ['week_number' => $weekNum, 'year' => $year],
            'active_pause'    => $activePause,
            'is_checked_in'   => $todayPointage && !$todayPointage->check_out,
            'today_pointage'  => $todayPointage,
        ]);
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
            $generatedCollection = collect($generated);
            $this->notificationService->notifyPlanningBatchCreated($generatedCollection);

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
     * Get employee context info for planning (weekly hours, rating, skills, leave, conflicts).
     * Used by the frontend EmployeeInfoPanel.
     */
    public function employeeInfo(Request $request, User $employee)
    {
        $weekNumber = $request->week_number ?? now()->isoWeek();
        $year = $request->year ?? now()->isoWeekYear();

        $employee->loadMissing(['skills', 'teams']);

        // Current hours
        $currentHours = $this->planningService->getHoursCalculator()->getWeeklyHours($employee, $weekNumber, $year);
        $limit = $employee->weekly_hours_limit ?? 44;

        // Latest rating
        $latestRating = $employee->ratings()->latest('created_at')->first();

        // Current week assignments
        $assignments = Planning::where('user_id', $employee->id)
            ->where('week_number', $weekNumber)
            ->where('year', $year)
            ->with(['shift', 'team'])
            ->orderBy('date')
            ->get();

        // Leave status for the week
        $weekStart = now()->setISODate($year, $weekNumber)->startOfWeek();
        $weekEnd = $weekStart->copy()->endOfWeek();
        $leave = $employee->leaveRequests()
            ->where('status', 'approved')
            ->where('start_date', '<=', $weekEnd->toDateString())
            ->where('end_date', '>=', $weekStart->toDateString())
            ->get(['id', 'type', 'start_date', 'end_date', 'reason']);

        return $this->successResponse([
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'initials' => $employee->initials,
                'avatar_url' => $employee->avatar_url,
            ],
            'weekly_hours' => [
                'current' => round($currentHours, 1),
                'limit' => $limit,
                'remaining' => round(max(0, $limit - $currentHours), 1),
                'is_overtime' => $currentHours > $limit,
                'is_under_hours' => $currentHours < 32,
            ],
            'rating' => $latestRating ? [
                'score' => $latestRating->score,
                'label' => $latestRating->score ? \App\Models\Rating::scoreLabel($latestRating->score) : null,
            ] : null,
            'skills' => $employee->skills->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'level' => $s->pivot->level ?? null,
            ]),
            'teams' => $employee->teams->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'color' => $t->color,
            ]),
            'assignments' => $assignments->map(fn ($p) => [
                'id' => $p->id,
                'date' => $p->date->toDateString(),
                'shift_name' => $p->shift?->name,
                'shift_type' => $p->shift?->type,
                'start_time' => $p->shift?->start_time?->format('H:i'),
                'end_time' => $p->shift?->end_time?->format('H:i'),
                'team_name' => $p->team?->name,
                'is_locked' => $p->is_locked,
            ]),
            'leave' => $leave->map(fn ($l) => [
                'id' => $l->id,
                'type' => $l->type,
                'start_date' => $l->start_date,
                'end_date' => $l->end_date,
                'reason' => $l->reason,
            ]),
        ], 'Employee info retrieved');
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

        $plannings = Planning::with('user')->whereIn('id', $validated['planning_ids'])->get();
        $deleted = $this->planningService->batchDelete($validated['planning_ids']);
        $this->notificationService->notifyPlanningBatchDeleted($plannings);

        AuditService::log('batch_deleted', Planning::class, 0, null, [
            'planning_ids' => $validated['planning_ids'],
            'deleted_count' => $deleted,
            'employee_count' => $plannings->pluck('user_id')->unique()->count(),
        ]);

        Cache::forget('dashboard.stats');
        Cache::forget('dashboard.coverage');

        return $this->successResponse(['deleted_count' => $deleted], "{$deleted} planning records deleted");
    }

    public function batchUpdateShift(Request $request)
    {
        $validated = $request->validate([
            'planning_ids' => 'required|array',
            'planning_ids.*' => 'exists:plannings,id',
            'shift_id' => 'required|exists:shifts,id',
        ]);

        $plannings = Planning::with('user')->whereIn('id', $validated['planning_ids'])->get();
        $updated = $this->planningService->batchUpdateShift($validated['planning_ids'], $validated['shift_id']);
        $this->notificationService->notifyPlanningBatchShiftUpdated($plannings);

        AuditService::log('batch_shift_updated', Planning::class, 0, null, [
            'planning_ids' => $validated['planning_ids'],
            'new_shift_id' => $validated['shift_id'],
            'updated_count' => $updated,
        ]);

        Cache::forget('dashboard.stats');
        Cache::forget('dashboard.coverage');

        return $this->successResponse(['updated_count' => $updated], "{$updated} planning records updated");
    }

    public function batchAssignEmployee(Request $request)
    {
        $validated = $request->validate([
            'planning_ids' => 'required|array',
            'planning_ids.*' => 'exists:plannings,id',
            'user_id' => 'required|exists:users,id',
        ]);

        $newUser = User::find($validated['user_id']);
        $plannings = Planning::with('user')->whereIn('id', $validated['planning_ids'])->get();
        $updated = $this->planningService->batchAssignEmployee($validated['planning_ids'], $validated['user_id']);

        foreach ($plannings as $p) {
            $this->notificationService->notifyPlanningReassigned($p, $p->user, $newUser);
        }

        AuditService::log('batch_employee_moved', Planning::class, 0, null, [
            'planning_ids' => $validated['planning_ids'],
            'old_user_id' => $plannings->first()?->user_id,
            'new_user_id' => $validated['user_id'],
            'new_employee' => $newUser->name,
            'updated_count' => $updated,
        ]);

        Cache::forget('dashboard.stats');
        Cache::forget('dashboard.coverage');

        return $this->successResponse(['updated_count' => $updated], "{$updated} planning records reassigned");
    }

    public function duplicateDay(Request $request)
    {
        $validated = $request->validate([
            'source_date' => 'required|date',
            'target_date' => 'required|date|after:source_date',
        ]);

        $result = $this->planningService->duplicateDay($validated['source_date'], $validated['target_date']);

        $createdPlannings = collect($result['created']);
        $this->notificationService->notifyPlanningBatchCreated($createdPlannings);

        AuditService::log('day_duplicated', Planning::class, 0, null, [
            'source_date' => $validated['source_date'],
            'target_date' => $validated['target_date'],
            'created_count' => $result['created_count'],
        ]);

        Cache::forget('dashboard.stats');
        Cache::forget('dashboard.coverage');

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

    public function coverage(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $coverage = $this->planningService->getCoverage($validated['start_date'], $validated['end_date']);

        return $this->successResponse($coverage, 'Coverage retrieved');
    }

    public function quality(Request $request)
    {
        $validated = $request->validate([
            'week_number' => 'required|integer|between:1,53',
            'year' => 'required|integer|min:2020',
        ]);

        $quality = $this->planningService->getQualityScore($validated['week_number'], $validated['year']);

        return $this->successResponse($quality, 'Quality score computed');
    }
}
