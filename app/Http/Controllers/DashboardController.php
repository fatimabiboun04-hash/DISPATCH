<?php

namespace App\Http\Controllers;

use App\Models\Pointage;
use App\Models\LeaveRequest;
use App\Models\Planning;
use App\Models\User;
use App\Services\HoursCalculatorService;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use App\Services\PauseService;

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
    $now = Carbon::now();
    $today = $now->toDateString();
    $weekNumber = $now->isoWeek();
    $year = $now->isoWeekYear();

    // Employees planned for today
    $plannedToday = Planning::where('date', $today)->count();

    // Employees currently checked in (checked in but not checked out yet)
    $activeToday = Pointage::whereDate('check_in_at', $today)
        ->whereNull('check_out_at')
        ->count();

    // Coverage percentage based on today's planning
    // Example: 4 checked in out of 5 planned = 80%
    $coverage = $plannedToday > 0
        ? round(($activeToday / $plannedToday) * 100, 1)
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

    // Today's planning assignments
    $todayAssignments = $plannedToday;

    return $this->successResponse([
        'coverage' => [
            'percentage' => $coverage,
            'active' => $activeToday,
            'total' => $plannedToday, // total employees expected today
        ],
        'delays_today' => $delaysToday,
        'pending_leaves' => $pendingLeaves,
        'flagged_pointages' => $flaggedCount,
        'today_assignments' => $todayAssignments,
        'current_week' => $weekNumber,
        'current_year' => $year,
    ]);
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
                    'date_range' => $leave->start_date->format('M d') . ' - ' . $leave->end_date->format('M d'),
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
        $now = Carbon::now();
        $weekStart = $now->copy()->startOfWeek();
        $weekEnd = $now->copy()->endOfWeek();

        $days = [];
       
for ($date = $weekStart->copy(); $date <= $weekEnd; $date->addDay()) {
    $dateStr = $date->toDateString();
    $isToday = $date->isToday();

    $assigned = Planning::where('date', $dateStr)->count();

    // For today: count only those still checked in (no checkout yet)
    // For past days: count anyone who checked in at all (regardless of checkout)
    $checkedInQuery = Pointage::whereDate('check_in_at', $dateStr);
    if ($isToday) {
        $checkedInQuery->whereNull('check_out_at');
    }
    $checkedIn = $checkedInQuery->count();

    $days[] = [
        'date'       => $dateStr,
        'day_name'   => $date->format('D'),
        'assigned'   => $assigned,
        'checked_in' => $checkedIn,
        'coverage'   => $assigned > 0 ? round(($checkedIn / $assigned) * 100, 1) : 0,
    ];
}
        

        return $this->successResponse($days);
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
