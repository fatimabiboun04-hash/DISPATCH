<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Models\Pause;
use App\Models\Planning;
use App\Models\Pointage;
use App\Models\Rating;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\HoursCalculatorService;
use App\Services\PauseService;
use App\Services\PlanningService;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    use ApiResponse;

    protected HoursCalculatorService $hoursCalculator;
    protected PlanningService $planningService;

    public function __construct(
        HoursCalculatorService $hoursCalculator,
        PlanningService $planningService,
    ) {
        $this->hoursCalculator = $hoursCalculator;
        $this->planningService = $planningService;
    }

    /**
     * Comprehensive admin dashboard — ALL real data in a single response.
     *
     * Query params:
     *   week_number (int, optional, default=current)
     *   year        (int, optional, default=current)
     */
    public function stats(Request $request)
    {
        $now = Carbon::now();
        $today = $now->toDateString();

        $weekNumber = (int) ($request->query('week_number', $now->isoWeek()));
        $year = (int) ($request->query('year', $now->isoWeekYear()));
        $isCurrentWeek = ($weekNumber === (int) $now->isoWeek() && $year === (int) $now->isoWeekYear());

        // Week boundaries
        $weekStart = Carbon::now()->setISODate($year, $weekNumber)->startOfWeek();
        $weekEnd = $weekStart->copy()->endOfWeek();
        $startStr = $weekStart->toDateString();
        $endStr = $weekEnd->toDateString();

        // ── 1. BASE COUNTS (single queries) ──
        $totalActiveEmployees = User::where('status', 'active')->count();

        // ── 2. TODAY's DATA (always real-time) ──
        $todayData = $isCurrentWeek
            ? $this->getTodayData($today)
            : $this->getHistoricDayData($today);

        // ── 3. WEEKLY PLANNING DATA ──
        $plannings = Planning::where('week_number', $weekNumber)
            ->where('year', $year)
            ->with(['user', 'shift', 'team', 'pointages', 'tasks'])
            ->get();

        $totalAssignments = $plannings->count();
        $lockedCount = $plannings->where('is_locked', true)->count();
        $unlockedCount = $plannings->where('is_locked', false)->count();
        $plannedEmployeeIds = $plannings->pluck('user_id')->unique()->toArray();
        $employeesPlanned = count($plannedEmployeeIds);
        $employeesMissing = max(0, $totalActiveEmployees - $employeesPlanned);

        // Employee IDs on approved leave this week
        $onLeaveThisWeek = $this->getOnLeaveIds($startStr, $endStr);

        // ── 4. WEEKLY HOURS ──
        $employees = User::where('status', 'active')->get();
        $hoursBatch = $this->hoursCalculator->getWeeklyHoursBatch($employees, $weekNumber, $year);

        $totalWorkedHours = 0;
        $totalPlannedHours = 0;
        $overtimeEmployees = [];
        $underHoursEmployees = [];
        $overtimeTotalHours = 0;

        foreach ($employees as $emp) {
            $hours = $hoursBatch[$emp->id] ?? 0;
            $totalWorkedHours += $hours;
            $limit = $emp->weekly_hours_limit ?? 44;

            if ($hours > $limit) {
                $overtimeEmployees[] = [
                    'id' => $emp->id,
                    'name' => $emp->name,
                    'hours' => $hours,
                    'limit' => $limit,
                    'overtime' => round($hours - $limit, 1),
                ];
                $overtimeTotalHours += ($hours - $limit);
            }
            if ($hours > 0 && $hours < 32) {
                $underHoursEmployees[] = [
                    'id' => $emp->id,
                    'name' => $emp->name,
                    'hours' => $hours,
                    'limit' => $limit,
                ];
            }
        }

        // Planned hours from shifts
        foreach ($plannings as $p) {
            if ($p->shift) {
                $totalPlannedHours += $p->shift->duration_hours ?? 0;
            }
        }

        $avgHours = $totalActiveEmployees > 0 ? round($totalWorkedHours / $totalActiveEmployees, 1) : 0;
        $avgLimit = $totalActiveEmployees > 0
            ? round($employees->avg('weekly_hours_limit') ?? 40, 1)
            : 40;
        $avgCompletion = $avgLimit > 0 ? round(($avgHours / $avgLimit) * 100, 1) : 0;

        // ── 5. TASKS (real data) ──
        $tasks = Task::whereIn('planning_id', $plannings->pluck('id'))->get();
        $completedTasks = $tasks->where('status', 'completed')->count();
        $pendingTasks = $tasks->whereIn('status', ['pending', 'in_progress'])->count();
        $taskCountByStatus = $tasks->groupBy('status')->map(fn ($g) => $g->count())->toArray();
        $taskCountByPriority = $tasks->groupBy('priority')->map(fn ($g) => $g->count())->toArray();

        // ── 6. PAUSE DATA (real) ──
        $pauseService = app(PauseService::class);
        $activePausesToday = $pauseService->getActiveToday();
        $pauseStats = $pauseService->getStats();

        // Pause distribution for this week's plannings
        $thisWeekPauses = Pause::whereIn('planning_id', $plannings->pluck('id'))
            ->whereIn('status', ['active', 'completed'])
            ->get();
        $pauseDistByType = $thisWeekPauses->groupBy('type')->map(fn ($g) => $g->count())->toArray();
        $avgPauseMinutes = $thisWeekPauses->count() > 0
            ? (int) round($thisWeekPauses->avg('duration_minutes'))
            : 0;

        // ── 7. POINTAGE / ATTENDANCE DATA ──
        $todayStr = $now->toDateString();
        $todayPointages = Pointage::whereDate('check_in_at', $todayStr)->get();
        $lateToday = $todayPointages->where('status', 'late')->count();
        $absentToday = $totalAssignments > 0
            ? $plannings->where('date', $todayStr)->count() - $todayPointages->count()
            : 0;
        $onTimeToday = $todayPointages->where('status', 'present')->count();
        $onTimePct = $todayPointages->count() > 0
            ? round(($onTimeToday / $todayPointages->count()) * 100, 1)
            : 100;

        // Attendance this week (per day)
        $attendanceWeek = [];
        for ($d = $weekStart->copy(); $d <= $weekEnd; $d->addDay()) {
            $ds = $d->toDateString();
            $dayPlan = $plannings->filter(fn ($p) => ($p->date instanceof Carbon ? $p->date->toDateString() : $p->date) === $ds);
            $dayPoint = Pointage::whereDate('check_in_at', $ds)->count();
            $scheduled = $dayPlan->count();
            $attendanceWeek[] = [
                'date' => $ds,
                'day_name' => $d->format('D'),
                'scheduled' => $scheduled,
                'present' => $dayPoint,
                'absent' => max(0, $scheduled - $dayPoint),
                'late' => Pointage::whereDate('check_in_at', $ds)->where('status', 'late')->count(),
            ];
        }

        // ── 8. RATINGS DATA ──
        $currentRatings = Rating::where('week_number', $weekNumber)
            ->where('year', $year)
            ->get();
        $ratedCount = $currentRatings->count();
        $avgScore = $currentRatings->avg('score');
        $avgScoreRounded = $avgScore ? round($avgScore, 1) : null;
        $fiveStarCount = $currentRatings->where('score', 5)->count();
        $needsImprovementCount = $currentRatings->where('score', '<=', 2)->count();

        // Ratings evolution (last 8 weeks)
        $ratingsEvolution = [];
        foreach (range(0, 7) as $offset) {
            $w = $weekNumber - $offset;
            $y = $year;
            if ($w < 1) { $w += 52; $y--; }
            $r = Rating::where('week_number', $w)->where('year', $y);
            $count = $r->count();
            $ratingsEvolution[] = [
                'week_number' => $w,
                'year' => $y,
                'total_rated' => $count,
                'average_score' => $count > 0 ? round($r->avg('score'), 1) : 0,
                'five_star_count' => $count > 0 ? $r->where('score', 5)->count() : 0,
            ];
        }

        // ── 9. COVERAGE DATA (per day this week) ──
        $allLeaves = LeaveRequest::approved()
            ->where('start_date', '<=', $endStr)
            ->where('end_date', '>=', $startStr)
            ->get(['id', 'user_id', 'start_date', 'end_date']);

        $planningCounts = Planning::whereBetween('date', [$startStr, $endStr])
            ->groupBy('date')
            ->selectRaw('date, COUNT(*) as total')
            ->pluck('total', 'date');

        $checkedInCounts = Pointage::whereDate('check_in_at', '>=', $startStr)
            ->whereDate('check_in_at', '<=', $endStr)
            ->groupByRaw('DATE(check_in_at)')
            ->selectRaw('DATE(check_in_at) as date, COUNT(*) as total')
            ->pluck('total', 'date');

        $coverageDays = [];
        $totalCoveragePct = 0;
        for ($d = $weekStart->copy(); $d <= $weekEnd; $d->addDay()) {
            $ds = $d->toDateString();
            $onLeaveIds = $allLeaves->filter(fn ($l) => $ds >= $l->start_date && $ds <= $l->end_date)
                ->pluck('user_id')->unique();
            $totalPlanned = (int) ($planningCounts[$ds] ?? 0);
            $onLeaveCount = $onLeaveIds->count();
            $assigned = max(0, $totalPlanned - $onLeaveCount);
            $checkedIn = (int) ($checkedInCounts[$ds] ?? 0);
            $cov = $assigned > 0 ? round(($checkedIn / $assigned) * 100, 1) : 0;
            $totalCoveragePct += $cov;
            $coverageDays[] = [
                'date' => $ds,
                'day_name' => $d->format('D'),
                'assigned' => $assigned,
                'checked_in' => $checkedIn,
                'total_planned' => $totalPlanned,
                'on_leave' => $onLeaveCount,
                'coverage' => $cov,
            ];
        }
        $avgCoveragePct = count($coverageDays) > 0
            ? round($totalCoveragePct / count($coverageDays), 1)
            : 0;

        // ── 10. SHIFT DISTRIBUTION ──
        $shiftDistribution = $plannings->groupBy('shift_id')->map(function ($group) use ($totalAssignments) {
            $shift = $group->first()->shift;
            return [
                'shift_id' => $group->first()->shift_id,
                'shift_name' => $shift ? $shift->name : 'Unknown',
                'shift_start' => $shift ? $shift->start_time : null,
                'shift_end' => $shift ? $shift->end_time : null,
                'count' => $group->count(),
                'percentage' => $totalAssignments > 0
                    ? round(($group->count() / $totalAssignments) * 100, 1) : 0,
            ];
        })->values();

        // ── 11. TEAM COVERAGE ──
        $teams = Team::all();
        $teamCoverage = [];
        foreach ($teams as $team) {
            $teamUserIds = $team->users->pluck('id')->toArray();
            $teamPlannings = $plannings->filter(fn ($p) => $p->team_id === $team->id);
            $teamEmployeeCount = count($teamUserIds);
            $teamCoverage[] = [
                'team_id' => $team->id,
                'team_name' => $team->name,
                'team_color' => $team->color,
                'assigned' => $teamPlannings->count(),
                'total_employees' => $teamEmployeeCount,
                'coverage_percentage' => $teamEmployeeCount > 0
                    ? round(($teamPlannings->count() / ($teamEmployeeCount * 7)) * 100, 1)
                    : 0,
            ];
        }

        // ── 12. WEEKLY HISTORY (trend chart) ──
        $weeklyHistory = WeeklySnapshot::latest('year')
            ->latest('week_number')
            ->limit(9)
            ->get()
            ->map(fn ($s) => [
                'week_number' => $s->week_number,
                'year' => $s->year,
                'total_employees' => $s->total_employees,
                'total_planned' => $s->total_planned,
                'total_checked_in' => $s->total_checked_in,
                'total_absences' => $s->total_absences,
                'avg_coverage' => $s->avg_coverage,
                'total_overtime_hours' => $s->total_overtime_hours,
                'overtime_employee_count' => $s->overtime_employee_count,
                'under_hours_employee_count' => $s->under_hours_employee_count,
            ])
            ->reverse()
            ->values();

        // ── 13. WEEKLY COMPARISON (this week vs last week) ──
        $prevWeekNumber = $weekNumber > 1 ? $weekNumber - 1 : 52;
        $prevYear = $weekNumber > 1 ? $year : $year - 1;
        $prevPlannings = Planning::where('week_number', $prevWeekNumber)
            ->where('year', $prevYear)->count();
        $prevHours = 0;
        foreach ($employees as $emp) {
            $prevHours += $this->hoursCalculator->getWeeklyHours($emp, $prevWeekNumber, $prevYear);
        }
        $weeklyComparison = [
            'current_week' => $weekNumber,
            'current_assignments' => $totalAssignments,
            'previous_week' => $prevWeekNumber,
            'previous_assignments' => $prevPlannings,
            'assignments_change' => $prevPlannings > 0
                ? round((($totalAssignments - $prevPlannings) / $prevPlannings) * 100, 1) : 0,
            'current_hours' => round($totalWorkedHours, 1),
            'previous_hours' => round($prevHours, 1),
            'hours_change' => $prevHours > 0
                ? round((($totalWorkedHours - $prevHours) / $prevHours) * 100, 1) : 0,
        ];

        // ── 14. KPI ENGINE ──
        $productivity = $totalAssignments > 0
            ? round(($completedTasks / max(1, $tasks->count())) * 100, 1)
            : 0;
        $utilization = $totalPlannedHours > 0
            ? round(($totalWorkedHours / $totalPlannedHours) * 100, 1)
            : 0;
        $absenceRate = $totalAssignments > 0
            ? round(($absentToday / max(1, $plannings->where('date', $todayStr)->count())) * 100, 1)
            : 0;

        // Planning quality score
        $qualityScore = $this->planningService->getQualityScore($weekNumber, $year);

        // ── 15. ALERTS ──
        $flaggedPointages = Pointage::where('is_flagged', true)->whereNull('verified_by')->count();
        $pendingLeaves = LeaveRequest::pending()->count();
        $coverageAlerts = [];
        foreach ($coverageDays as $day) {
            if ($day['coverage'] < 60 && $day['assigned'] > 0) {
                $coverageAlerts[] = $day;
            }
        }

        // ── 16. QUICK ACTIONS ──
        $quickActions = [
            'has_pending_leaves' => $pendingLeaves > 0,
            'has_flagged_pointages' => $flaggedPointages > 0,
            'has_unlocked_weeks' => $unlockedCount > 0,
            'can_generate_report' => true,
            'can_print_planning' => $totalAssignments > 0,
            'has_week_data' => $totalAssignments > 0,
        ];

        // ── 17. NAVIGATION ──
        $prevW = $weekNumber > 1 ? $weekNumber - 1 : 52;
        $prevY = $weekNumber > 1 ? $year : $year - 1;
        $nextW = $weekNumber < 52 ? $weekNumber + 1 : 1;
        $nextY = $weekNumber < 52 ? $year : $year + 1;

        // ── 18. NOTIFICATIONS COUNT ──
        $unreadNotifications = auth()->user()->unreadNotifications->count();

        // ── ASSEMBLE RESPONSE ──
        return $this->successResponse([
            // Navigation
            'current_week' => $weekNumber,
            'current_year' => $year,
            'is_current_week' => $isCurrentWeek,
            'navigation' => [
                'prev_week' => $prevW,
                'prev_year' => $prevY,
                'next_week' => $nextW,
                'next_year' => $nextY,
                'week_start' => $startStr,
                'week_end' => $endStr,
            ],

            // Coverage (backward compatible with existing frontend)
            'coverage' => [
                'percentage' => $todayData['coverage_pct'],
                'active' => $todayData['active_today'],
                'present_now' => $todayData['present_now'],
                'total' => $todayData['planned_today'],
                'effective_total' => $todayData['effective_planned'],
                'on_leave_today' => $todayData['on_leave_count'],
            ],

            // KPI Cards
            'cards' => [
                'employees_scheduled_today' => $todayData['planned_today'],
                'employees_working_now' => $todayData['present_now'],
                'employees_absent' => $todayData['absent_today'],
                'employees_on_leave' => $todayData['on_leave_count'],
                'employees_on_pause' => count($activePausesToday),
                'current_coverage' => $todayData['coverage_pct'],
                'weekly_worked_hours' => round($totalWorkedHours, 1),
                'weekly_planned_hours' => round($totalPlannedHours, 1),
                'overtime_hours' => round($overtimeTotalHours, 1),
                'missing_assignments' => $employeesMissing,
                'completed_tasks' => $completedTasks,
                'pending_tasks' => $pendingTasks,
                'locked_weeks' => $lockedCount,
                'open_weeks' => $unlockedCount,
                'average_rating' => $avgScoreRounded,
                'unread_notifications' => $unreadNotifications,
            ],

            // Legacy flat fields (backward compat for OverTimeAlertsPanel etc.)
            'total_employees' => $totalActiveEmployees,
            'delays_today' => $lateToday,
            'pending_leaves' => $pendingLeaves,
            'flagged_pointages' => $flaggedPointages,
            'today_assignments' => $todayData['planned_today'],
            'weekly_hours' => [
                'total_hours' => round($totalWorkedHours, 1),
                'average_hours' => $avgHours,
                'average_limit' => $avgLimit,
                'avg_completion' => $avgCompletion,
                'overtime_count' => count($overtimeEmployees),
                'under_hours_count' => count($underHoursEmployees),
                'employee_count' => $totalActiveEmployees,
            ],
            'overtimes' => count($overtimeEmployees),
            'weekly_completion' => $avgCompletion,

            // Ratings (backward compat)
            'ratings' => [
                'total_rated' => $ratedCount,
                'average_score' => $avgScoreRounded,
                'five_star_count' => $fiveStarCount,
                'needs_improvement_count' => $needsImprovementCount,
            ],

            // Charts data
            'charts' => [
                'coverage_days' => $coverageDays,
                'attendance_week' => $attendanceWeek,
                'shift_distribution' => $shiftDistribution,
                'team_coverage' => $teamCoverage,
                'task_by_status' => $taskCountByStatus,
                'task_by_priority' => $taskCountByPriority,
                'pause_distribution' => $pauseDistByType,
                'ratings_evolution' => $ratingsEvolution,
                'weekly_history' => $weeklyHistory,
                'weekly_comparison' => $weeklyComparison,
            ],

            // KPI Engine
            'kpis' => [
                'productivity' => $productivity,
                'utilization' => $utilization,
                'absence_rate' => $absenceRate,
                'average_pause_minutes' => $avgPauseMinutes,
                'on_time_percentage' => $onTimePct,
                'planning_quality' => $qualityScore,
            ],

            // Alerts
            'alerts' => [
                'flagged_pointages' => $flaggedPointages,
                'delays_today' => $lateToday,
                'pending_leaves' => $pendingLeaves,
                'overtime_employees' => count($overtimeEmployees),
                'coverage_alerts' => $coverageAlerts,
                'under_hours_employees' => count($underHoursEmployees),
            ],

            // Quick actions
            'quick_actions' => $quickActions,

            // Overtime employees list
            'overtime_employees' => array_slice($overtimeEmployees, 0, 10),
            'under_hours_employees' => array_slice($underHoursEmployees, 0, 10),
        ]);
    }

    /**
     * Live activity feed.
     */
    public function liveFeed()
    {
        $feed = Pointage::with('user')
            ->where('check_in_at', '>=', Carbon::now()->subHours(24))
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn ($pointage) => [
                'type' => $pointage->check_out_at ? 'check_out' : 'check_in',
                'user_name' => $pointage->user->name,
                'user_initials' => $pointage->user->initials,
                'time' => $pointage->check_in_at->format('H:i'),
                'status' => $pointage->status,
                'is_flagged' => $pointage->is_flagged,
            ]);

        $leaveFeed = LeaveRequest::with('user')
            ->where('created_at', '>=', Carbon::now()->subHours(24))
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn ($leave) => [
                'type' => 'leave_request',
                'user_name' => $leave->user->name,
                'user_initials' => $leave->user->initials,
                'time' => $leave->created_at->format('H:i'),
                'status' => $leave->status,
                'date_range' => $leave->start_date->format('M d') . ' - ' . $leave->end_date->format('M d'),
            ]);

        $merged = $feed->merge($leaveFeed)->sortByDesc('time')->values();

        return $this->successResponse($merged);
    }

    /**
     * Coverage gauge — daily coverage for a given week.
     * Query params: week_number, year (optional)
     */
    public function coverageGauge(Request $request)
    {
        $now = Carbon::now();
        $weekNumber = (int) ($request->query('week_number', $now->isoWeek()));
        $year = (int) ($request->query('year', $now->isoWeekYear()));
        $weekStart = Carbon::now()->setISODate($year, $weekNumber)->startOfWeek();
        $weekEnd = $weekStart->copy()->endOfWeek();
        $startStr = $weekStart->toDateString();
        $endStr = $weekEnd->toDateString();

        $allLeaves = LeaveRequest::approved()
            ->where('start_date', '<=', $endStr)
            ->where('end_date', '>=', $startStr)
            ->get(['id', 'user_id', 'start_date', 'end_date']);

        $planningCounts = Planning::whereBetween('date', [$startStr, $endStr])
            ->groupBy('date')
            ->selectRaw('date, COUNT(*) as total')
            ->pluck('total', 'date');

        $checkedInCounts = Pointage::whereDate('check_in_at', '>=', $startStr)
            ->whereDate('check_in_at', '<=', $endStr)
            ->groupByRaw('DATE(check_in_at)')
            ->selectRaw('DATE(check_in_at) as date, COUNT(*) as total')
            ->pluck('total', 'date');

        $days = [];
        for ($d = $weekStart->copy(); $d <= $weekEnd; $d->addDay()) {
            $ds = $d->toDateString();
            $onLeaveIds = $allLeaves->filter(fn ($l) => $ds >= $l->start_date && $ds <= $l->end_date)
                ->pluck('user_id')->unique();
            $totalPlanned = (int) ($planningCounts[$ds] ?? 0);
            $onLeaveCount = $onLeaveIds->count();
            $assigned = max(0, $totalPlanned - $onLeaveCount);
            $checkedIn = (int) ($checkedInCounts[$ds] ?? 0);
            $days[] = [
                'date' => $ds,
                'day_name' => $d->format('D'),
                'assigned' => $assigned,
                'checked_in' => $checkedIn,
                'total_planned' => $totalPlanned,
                'on_leave' => $onLeaveCount,
                'coverage' => $assigned > 0 ? round(($checkedIn / $assigned) * 100, 1) : 0,
            ];
        }

        return $this->successResponse($days);
    }

    /**
     * Weekly history for trend charts.
     */
    public function weeklyHistory()
    {
        $snapshots = WeeklySnapshot::latest('year')
            ->latest('week_number')
            ->limit(9)
            ->get()
            ->map(fn ($s) => [
                'week_number' => $s->week_number,
                'year' => $s->year,
                'total_employees' => $s->total_employees,
                'total_planned' => $s->total_planned,
                'total_checked_in' => $s->total_checked_in,
                'total_absences' => $s->total_absences,
                'avg_coverage' => $s->avg_coverage,
                'total_overtime_hours' => $s->total_overtime_hours,
                'overtime_employee_count' => $s->overtime_employee_count,
                'under_hours_employee_count' => $s->under_hours_employee_count,
                'generated_at' => $s->generated_at?->toIso8601String(),
            ])
            ->reverse()
            ->values();

        return $this->successResponse($snapshots);
    }

    /**
     * Active pauses widget data.
     */
    public function activePauses()
    {
        $pauseService = app(PauseService::class);
        $activePauses = $pauseService->getActiveToday();

        return $this->successResponse([
            'count' => count($activePauses),
            'pauses' => $activePauses,
        ]);
    }

    // ── Private helpers ──

    /**
     * Get today's real-time data (check-ins, pointages, etc.).
     */
    private function getTodayData(string $today): array
    {
        $plannedToday = Planning::where('date', $today)->count();

        $onLeaveToday = LeaveRequest::approved()
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->pluck('user_id');

        $onLeaveCount = $onLeaveToday->count();
        $effectivePlanned = Planning::where('date', $today)
            ->whereNotIn('user_id', $onLeaveToday)
            ->count();

        $presentNow = Pointage::whereDate('check_in_at', $today)
            ->whereNull('check_out_at')
            ->count();

        $activeToday = Pointage::whereDate('check_in_at', $today)->count();

        $absentToday = max(0, $effectivePlanned - $activeToday);

        $coveragePct = $effectivePlanned > 0
            ? round(($activeToday / $effectivePlanned) * 100, 1)
            : 0;

        return [
            'planned_today' => $plannedToday,
            'on_leave_count' => $onLeaveCount,
            'effective_planned' => $effectivePlanned,
            'present_now' => $presentNow,
            'active_today' => $activeToday,
            'absent_today' => $absentToday,
            'coverage_pct' => $coveragePct,
        ];
    }

    /**
     * Get historic day data (no live check-ins, estimates from pointages).
     */
    private function getHistoricDayData(string $date): array
    {
        $plannedToday = Planning::where('date', $date)->count();

        $onLeaveToday = LeaveRequest::approved()
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->pluck('user_id');

        $onLeaveCount = $onLeaveToday->count();
        $effectivePlanned = Planning::where('date', $date)
            ->whereNotIn('user_id', $onLeaveToday)
            ->count();

        $checkedIn = Pointage::whereDate('check_in_at', $date)->count();
        $absentToday = max(0, $effectivePlanned - $checkedIn);

        $coveragePct = $effectivePlanned > 0
            ? round(($checkedIn / $effectivePlanned) * 100, 1)
            : 0;

        return [
            'planned_today' => $plannedToday,
            'on_leave_count' => $onLeaveCount,
            'effective_planned' => $effectivePlanned,
            'present_now' => 0,
            'active_today' => $checkedIn,
            'absent_today' => $absentToday,
            'coverage_pct' => $coveragePct,
        ];
    }

    /**
     * Get user IDs on approved leave during a date range.
     */
    private function getOnLeaveIds(string $startStr, string $endStr): array
    {
        return LeaveRequest::approved()
            ->where('start_date', '<=', $endStr)
            ->where('end_date', '>=', $startStr)
            ->pluck('user_id')
            ->unique()
            ->toArray();
    }
}
