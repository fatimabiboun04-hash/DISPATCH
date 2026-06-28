<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Models\Planning;
use App\Models\Pointage;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\HoursCalculatorService;
use App\Services\PauseService;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    use ApiResponse;

    protected HoursCalculatorService $hoursCalculator;

    public function __construct(HoursCalculatorService $hoursCalculator)
    {
        $this->hoursCalculator = $hoursCalculator;
    }

    /**
     * Admin dashboard statistics.
     */
    public function stats()
    {
        return Cache::remember('dashboard.stats', 120, function () {
            $now = Carbon::now();
            $today = $now->toDateString();
            $weekNumber = $now->isoWeek();
            $year = $now->isoWeekYear();

            // Total active employees
            $totalEmployees = User::where('status', 'active')->count();

            // Employees planned for today
            $plannedToday = Planning::where('date', $today)->count();

            // Employee IDs on approved leave today
            $onLeaveToday = LeaveRequest::approved()
                ->where('start_date', '<=', $today)
                ->where('end_date', '>=', $today)
                ->pluck('user_id');

            // Effective planned (exclude those on approved leave)
            $effectivePlanned = Planning::where('date', $today)
                ->whereNotIn('user_id', $onLeaveToday)
                ->count();

            // Employees currently checked in (present now)
            $presentNow = Pointage::whereDate('check_in_at', $today)
                ->whereNull('check_out_at')
                ->count();

            // Employees who checked in today (regardless of check-out status)
            $activeToday = Pointage::whereDate('check_in_at', $today)->count();

            // Coverage percentage based on effective planned (excl. leave)
            $coverage = $effectivePlanned > 0
                ? round(($activeToday / $effectivePlanned) * 100, 1)
                : 0;

            // Delays today
            $delaysToday = Pointage::whereDate('check_in_at', $today)
                ->where('status', 'late')
                ->count();

            // Pending leave requests
            $pendingLeaves = LeaveRequest::pending()->count();

            // Flagged pointages awaiting review
            $flaggedCount = Pointage::where('is_flagged', true)
                ->whereNull('verified_by')
                ->count();

            // Weekly hours aggregation via HoursCalculatorService
            $weeklyHoursData = $this->getWeeklyHoursAggregate($weekNumber, $year);

            return [
                'coverage' => [
                    'percentage' => $coverage,
                    'active' => $activeToday,
                    'present_now' => $presentNow,
                    'total' => $plannedToday,
                    'effective_total' => $effectivePlanned,
                    'on_leave_today' => $onLeaveToday->count(),
                ],
                'total_employees' => $totalEmployees,
                'delays_today' => $delaysToday,
                'pending_leaves' => $pendingLeaves,
                'flagged_pointages' => $flaggedCount,
                'today_assignments' => $plannedToday,
                'current_week' => $weekNumber,
                'current_year' => $year,
                'weekly_hours' => $weeklyHoursData,
                'overtimes' => $weeklyHoursData['overtime_count'],
                'weekly_completion' => $weeklyHoursData['avg_completion'],
            ];
        });
    }

    /**
     * Aggregate weekly hours across all active employees.
     */
    protected function getWeeklyHoursAggregate(int $weekNumber, int $year): array
    {
        $employees = User::where('status', 'active')->get();
        $employeeCount = $employees->count();

        // Batch-load all hours in 4-5 queries regardless of employee count
        $hoursBatch = $this->hoursCalculator->getWeeklyHoursBatch($employees, $weekNumber, $year);

        $totalHours = 0;
        $overtimeCount = 0;
        $underHoursCount = 0;

        foreach ($employees as $emp) {
            $hours = $hoursBatch[$emp->id] ?? 0;
            $totalHours += $hours;
            $limit = $emp->weekly_hours_limit ?? 44;

            if ($hours > $limit) {
                $overtimeCount++;
            }
            if ($hours < 32) {
                $underHoursCount++;
            }
        }

        $avgHours = $employeeCount > 0 ? round($totalHours / $employeeCount, 1) : 0;
        $avgLimit = $employeeCount > 0
            ? round($employees->avg('weekly_hours_limit') ?? 40, 1)
            : 40;
        $avgCompletion = $avgLimit > 0 ? round(($avgHours / $avgLimit) * 100, 1) : 0;

        return [
            'total_hours' => round($totalHours, 1),
            'average_hours' => $avgHours,
            'average_limit' => $avgLimit,
            'avg_completion' => $avgCompletion,
            'overtime_count' => $overtimeCount,
            'under_hours_count' => $underHoursCount,
            'employee_count' => $employeeCount,
        ];
    }

    /**
     * Live activity feed for admin dashboard.
     */
    public function liveFeed()
    {
        $feed = Pointage::with('user')
            ->where('check_in_at', '>=', Carbon::now()->subHours(24))
            ->latest()
            ->limit(20)
            ->get()
            ->map(function ($pointage) {
                return [
                    'type' => $pointage->check_out_at ? 'check_out' : 'check_in',
                    'user_name' => $pointage->user->name,
                    'user_initials' => $pointage->user->initials,
                    'time' => $pointage->check_in_at->format('H:i'),
                    'status' => $pointage->status,
                    'is_flagged' => $pointage->is_flagged,
                ];
            });

        // Add recent leave requests
        $leaveFeed = LeaveRequest::with('user')
            ->where('created_at', '>=', Carbon::now()->subHours(24))
            ->latest()
            ->limit(10)
            ->get()
            ->map(function ($leave) {
                return [
                    'type' => 'leave_request',
                    'user_name' => $leave->user->name,
                    'user_initials' => $leave->user->initials,
                    'time' => $leave->created_at->format('H:i'),
                    'status' => $leave->status,
                    'date_range' => $leave->start_date->format('M d').' - '.$leave->end_date->format('M d'),
                ];
            });

        $merged = $feed->merge($leaveFeed)->sortByDesc('time')->values();

        return $this->successResponse($merged);
    }

    /**
     * Coverage gauge data for the week.
     */
    public function coverageGauge()
    {
        $days = Cache::remember('dashboard.coverage', 120, function () {
            $now = Carbon::now();
            $weekStart = $now->copy()->startOfWeek();
            $weekEnd = $now->copy()->endOfWeek();
            $startStr = $weekStart->toDateString();
            $endStr = $weekEnd->toDateString();

            // ── Batch-load all week data (2 queries instead of 21+) ──────
            // 1. All approved leaves covering any day this week
            $allLeaves = LeaveRequest::approved()
                ->where('start_date', '<=', $endStr)
                ->where('end_date', '>=', $startStr)
                ->get(['id', 'user_id', 'start_date', 'end_date']);

            // 2. Planning counts per date (total planned)
            $planningCounts = Planning::whereBetween('date', [$startStr, $endStr])
                ->groupBy('date')
                ->selectRaw('date, COUNT(*) as total')
                ->pluck('total', 'date');

            // 3. Check-in counts per date
            $checkedInCounts = Pointage::whereDate('check_in_at', '>=', $startStr)
                ->whereDate('check_in_at', '<=', $endStr)
                ->groupByRaw('DATE(check_in_at)')
                ->selectRaw('DATE(check_in_at) as date, COUNT(*) as total')
                ->pluck('total', 'date');
            // ─────────────────────────────────────────────────────────────

            $days = [];

            for ($date = $weekStart->copy(); $date <= $weekEnd; $date->addDay()) {
                $dateStr = $date->toDateString();

                // Compute on-leave user IDs for this date from batch data
                $onLeaveIds = $allLeaves->filter(function ($leave) use ($dateStr) {
                    return $dateStr >= $leave->start_date && $dateStr <= $leave->end_date;
                })->pluck('user_id')->unique();

                $totalPlanned = (int) ($planningCounts[$dateStr] ?? 0);
                $onLeaveCount = $onLeaveIds->count();
                $assigned = max(0, $totalPlanned - $onLeaveCount);
                $checkedIn = (int) ($checkedInCounts[$dateStr] ?? 0);

                $days[] = [
                    'date' => $dateStr,
                    'day_name' => $date->format('D'),
                    'assigned' => $assigned,
                    'checked_in' => $checkedIn,
                    'total_planned' => $totalPlanned,
                    'on_leave' => $onLeaveCount,
                    'coverage' => $assigned > 0 ? round(($checkedIn / $assigned) * 100, 1) : 0,
                ];
            }

            return $days;
        });

        return $this->successResponse($days);
    }

    /**
     * Weekly history for trend charts.
     * Returns snapshots for the last 8 completed weeks plus current week.
     */
    public function weeklyHistory()
    {
        $snapshots = Cache::remember('dashboard.weekly_history', 600, function () {
            return WeeklySnapshot::latest('year')
                ->latest('week_number')
                ->limit(9)
                ->get()
                ->map(function ($snapshot) {
                    return [
                        'week_number' => $snapshot->week_number,
                        'year' => $snapshot->year,
                        'total_employees' => $snapshot->total_employees,
                        'total_planned' => $snapshot->total_planned,
                        'total_checked_in' => $snapshot->total_checked_in,
                        'total_absences' => $snapshot->total_absences,
                        'avg_coverage' => $snapshot->avg_coverage,
                        'total_overtime_hours' => $snapshot->total_overtime_hours,
                        'overtime_employee_count' => $snapshot->overtime_employee_count,
                        'under_hours_employee_count' => $snapshot->under_hours_employee_count,
                        'generated_at' => $snapshot->generated_at?->toIso8601String(),
                    ];
                })
                ->reverse()
                ->values();
        });

        return $this->successResponse($snapshots);
    }

    public function activePauses()
    {
        $pauseService = app(PauseService::class);
        $activePauses = $pauseService->getActiveToday();

        return $this->successResponse([
            'count' => count($activePauses),
            'pauses' => $activePauses,
        ]);
    }
}
