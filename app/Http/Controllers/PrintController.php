<?php

namespace App\Http\Controllers;

use App\Models\Planning;
use App\Models\Team;
use App\Models\User;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

class PrintController extends Controller
{
    use ApiResponse;

    /**
     * Weekly Planning — full team overview for printing.
     */
    public function weeklyPlanning(Request $request)
    {
        $validated = $request->validate([
            'week_number' => 'required|integer|between:1,53',
            'year' => 'required|integer|min:2020',
        ]);

        $weekNumber = $validated['week_number'];
        $year = $validated['year'];
        $startOfWeek = Carbon::now()->setISODate($year, $weekNumber)->startOfWeek();
        $endOfWeek = $startOfWeek->copy()->endOfWeek();

        $plannings = Planning::with(['user', 'shift', 'team', 'tasks' => fn ($q) => $q->select('id', 'planning_id', 'title', 'status', 'priority')])
            ->where('week_number', $weekNumber)
            ->where('year', $year)
            ->orderBy('date')
            ->orderBy('shift_id')
            ->get();

        $teams = Team::with(['users' => fn ($q) => $q->select('users.id', 'users.name')])->get();

        $days = [];
        for ($d = $startOfWeek->copy(); $d <= $endOfWeek; $d->addDay()) {
            $days[] = [
                'date' => $d->toDateString(),
                'day_name' => $d->locale('fr')->dayName,
                'day_name_en' => $d->format('D'),
            ];
        }

        $grouped = $plannings->groupBy('team_id');

        $html = View::make('print.weekly-planning', [
            'plannings' => $plannings,
            'grouped' => $grouped,
            'teams' => $teams,
            'days' => $days,
            'weekNumber' => $weekNumber,
            'year' => $year,
            'startOfWeek' => $startOfWeek,
            'endOfWeek' => $endOfWeek,
        ])->render();

        return response()->json([
            'success' => true,
            'data' => [
                'html' => $html,
                'week_number' => $weekNumber,
                'year' => $year,
                'total_assignments' => $plannings->count(),
                'total_employees' => $plannings->pluck('user_id')->unique()->count(),
            ],
            'message' => 'Weekly planning print generated',
        ]);
    }

    /**
     * Employee Planning — single employee's week for printing.
     */
    public function employeePlanning(Request $request, User $employee)
    {
        $validated = $request->validate([
            'week_number' => 'required|integer|between:1,53',
            'year' => 'required|integer|min:2020',
        ]);

        $weekNumber = $validated['week_number'];
        $year = $validated['year'];

        $plannings = Planning::with(['shift', 'team', 'tasks', 'pauses'])
            ->where('user_id', $employee->id)
            ->where('week_number', $weekNumber)
            ->where('year', $year)
            ->orderBy('date')
            ->orderBy('shift_id')
            ->get();

        $totalHours = 0;
        foreach ($plannings as $p) {
            $totalHours += $p->shift?->duration_hours ?? 0;
        }

        $html = View::make('print.employee-planning', [
            'employee' => $employee,
            'plannings' => $plannings,
            'weekNumber' => $weekNumber,
            'year' => $year,
            'totalHours' => round($totalHours, 1),
        ])->render();

        return response()->json([
            'success' => true,
            'data' => [
                'html' => $html,
                'employee' => ['id' => $employee->id, 'name' => $employee->name],
                'week_number' => $weekNumber,
                'year' => $year,
                'total_assignments' => $plannings->count(),
                'total_hours' => round($totalHours, 1),
            ],
            'message' => 'Employee planning print generated',
        ]);
    }

    /**
     * Team Planning — all members of a team for printing.
     */
    public function teamPlanning(Request $request, Team $team)
    {
        $validated = $request->validate([
            'week_number' => 'required|integer|between:1,53',
            'year' => 'required|integer|min:2020',
        ]);

        $weekNumber = $validated['week_number'];
        $year = $validated['year'];
        $startOfWeek = Carbon::now()->setISODate($year, $weekNumber)->startOfWeek();
        $endOfWeek = $startOfWeek->copy()->endOfWeek();

        $plannings = Planning::with(['user', 'shift'])
            ->where('team_id', $team->id)
            ->where('week_number', $weekNumber)
            ->where('year', $year)
            ->orderBy('date')
            ->orderBy('shift_id')
            ->get();

        $employees = $team->users()->select('users.id', 'users.name')->get();

        $days = [];
        for ($d = $startOfWeek->copy(); $d <= $endOfWeek; $d->addDay()) {
            $days[] = $d->format('D');
        }

        $html = View::make('print.team-planning', [
            'team' => $team,
            'plannings' => $plannings,
            'employees' => $employees,
            'days' => $days,
            'weekNumber' => $weekNumber,
            'year' => $year,
            'startOfWeek' => $startOfWeek,
            'endOfWeek' => $endOfWeek,
        ])->render();

        return response()->json([
            'success' => true,
            'data' => [
                'html' => $html,
                'team' => ['id' => $team->id, 'name' => $team->name],
                'week_number' => $weekNumber,
                'year' => $year,
                'total_assignments' => $plannings->count(),
            ],
            'message' => 'Team planning print generated',
        ]);
    }

    /**
     * Daily Planning — single day for printing.
     */
    public function dailyPlanning(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date',
        ]);

        $date = Carbon::parse($validated['date']);
        $weekNumber = $date->isoWeek();
        $year = $date->isoWeekYear();

        $plannings = Planning::with(['user', 'shift', 'team', 'tasks' => fn ($q) => $q->select('id', 'planning_id', 'title', 'status', 'priority')])
            ->where('date', $date->toDateString())
            ->orderBy('shift_id')
            ->orderBy('team_id')
            ->get();

        $html = View::make('print.daily-planning', [
            'plannings' => $plannings,
            'date' => $date,
            'weekNumber' => $weekNumber,
            'year' => $year,
        ])->render();

        return response()->json([
            'success' => true,
            'data' => [
                'html' => $html,
                'date' => $date->toDateString(),
                'week_number' => $weekNumber,
                'year' => $year,
                'total_assignments' => $plannings->count(),
            ],
            'message' => 'Daily planning print generated',
        ]);
    }
}
