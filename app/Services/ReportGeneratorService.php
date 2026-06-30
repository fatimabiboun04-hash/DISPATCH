<?php

namespace App\Services;

use App\Models\LeaveRequest;
use App\Models\Pause;
use App\Models\Planning;
use App\Models\Pointage;
use App\Models\Rating;
use App\Models\Report;
use App\Models\Shift;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReportGeneratorService
{
    protected HoursCalculatorService $hoursCalculator;

    public function __construct(HoursCalculatorService $hoursCalculator)
    {
        $this->hoursCalculator = $hoursCalculator;
    }

    public function generate(Report $report): string
    {
        $ext = $report->file_type === 'excel' ? 'xlsx' : 'pdf';
        $filename = sprintf('reports/%s_report_%d_%s.%s', $report->type, $report->id, now()->format('Ymd_His'), $ext);

        if ($report->file_type === 'pdf') {
            return $this->generatePdf($report, $filename);
        }
        return $this->generateExcel($report, $filename);
    }

    protected function generatePdf(Report $report, string $filename): string
    {
        $data = $this->gatherReportData($report);
        $html = view('pdf.report', ['data' => $data, 'report' => $report])->render();
        \Illuminate\Support\Facades\Log::debug('ReportGenerator PDF rendered for report #'.$report->id);
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html);
        $pdf->setPaper('A4', 'landscape');
        $content = $pdf->output();
        Storage::put($filename, $content);
        return $filename;
    }

    protected function generateExcel(Report $report, string $filename): string
    {
        $data = $this->gatherReportData($report);

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;

        // Sheet 1: Assignments
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Assignments');
        $this->writeSheet($sheet, $data['assignments']);

        // Sheet 2: Hours & Overtime
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Hours & Overtime');
        $this->writeKeyValue($sheet2, $data['summary']);

        // Sheet 3: Shift Distribution
        if (!empty($data['shift_distribution'])) {
            $sheet3 = $spreadsheet->createSheet();
            $sheet3->setTitle('Shift Distribution');
            $header = ['Shift', 'Count', 'Percentage'];
            $rows = array_merge([$header], $data['shift_distribution']);
            $this->writeSheet($sheet3, $rows);
        }

        // Sheet 4: Team Coverage
        if (!empty($data['coverage_by_team'])) {
            $sheet4 = $spreadsheet->createSheet();
            $sheet4->setTitle('Team Coverage');
            $header = ['Team', 'Assigned', 'Total', 'Coverage %'];
            $rows = array_merge([$header], $data['coverage_by_team']);
            $this->writeSheet($sheet4, $rows);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $tempPath = tempnam(sys_get_temp_dir(), 'report_').'.xlsx';
        $writer->save($tempPath);
        Storage::put($filename, file_get_contents($tempPath));
        unlink($tempPath);

        return $filename;
    }

    protected function writeSheet($sheet, array $rows, int $startRow = 1): void
    {
        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                $col = chr(65 + $colIndex);
                $sheet->setCellValue($col.$startRow, $value);
            }
            $startRow++;
        }
    }

    protected function writeKeyValue($sheet, array $data, int $startRow = 1): void
    {
        foreach ($data as $key => $value) {
            $sheet->setCellValue('A'.$startRow, $key);
            $sheet->setCellValue('B'.$startRow, $value);
            $startRow++;
        }
    }

    protected function gatherReportData(Report $report): array
    {
        $start = Carbon::parse($report->start_date)->startOfDay();
        $end = Carbon::parse($report->end_date)->endOfDay();

        switch ($report->type) {
            case 'weekly':
                return $this->gatherWeeklyData($start, $end, $report->week_number, $report->year);
            case 'monthly':
                return $this->gatherMonthlyData($start, $end);
            case 'custom':
            default:
                return $this->gatherCustomData($start, $end);
        }
    }

    protected function gatherWeeklyData(Carbon $start, Carbon $end, int $weekNumber, int $year): array
    {
        // ── Core Planning Data ──────────────────────────────────────
        $plannings = Planning::with(['user', 'shift', 'team', 'tasks'])
            ->whereBetween('date', [$start, $end])
            ->where('week_number', $weekNumber)
            ->where('year', $year)
            ->get();

        $totalAssignments = $plannings->count();
        $lockedCount = $plannings->where('is_locked', true)->count();
        $unlockedCount = $totalAssignments - $lockedCount;

        // ── Employee Metrics ────────────────────────────────────────
        $employees = User::employees()->active()->get();
        $employeeCount = $employees->count();
        $assignedEmployeeIds = $plannings->pluck('user_id')->unique()->toArray();
        $plannedEmployeeCount = count($assignedEmployeeIds);
        $missingEmployeeIds = $employees->pluck('id')->diff($assignedEmployeeIds)->values()->toArray();

        // ── Hours ────────────────────────────────────────────────────
        $totalHours = 0;
        $userHours = [];
        foreach ($plannings as $p) {
            $h = $p->shift?->duration_hours ?? 0;
            $totalHours += $h;
            $userHours[$p->user_id] = ($userHours[$p->user_id] ?? 0) + $h;
        }

        $overtimeCount = 0;
        $underHoursCount = 0;
        $overtimeEmployees = [];
        $underUtilized = [];
        foreach ($employees as $emp) {
            $h = $userHours[$emp->id] ?? 0;
            $limit = $emp->weekly_hours_limit ?? 44;
            if ($h > $limit) {
                $overtimeCount++;
                $overtimeEmployees[] = [
                    'name' => $emp->name,
                    'hours' => round($h, 1),
                    'limit' => number_format($limit, 2),
                    'overtime' => round($h - $limit, 1),
                ];
            }
            if ($h < 32) {
                $underHoursCount++;
                $underUtilized[] = [
                    'name' => $emp->name,
                    'hours' => round($h, 1),
                    'limit' => $limit,
                ];
            }
        }

        $avgHours = $employeeCount > 0 ? round($totalHours / $employeeCount, 1) : 0;

        // ── Shift Distribution ──────────────────────────────────────
        $shiftDistribution = Shift::where('is_active', true)->get()->map(function ($shift) use ($plannings, $totalAssignments) {
            $count = $plannings->where('shift_id', $shift->id)->count();
            return [
                'name' => $shift->name,
                'count' => $count,
                'percentage' => $totalAssignments > 0 ? round(($count / $totalAssignments) * 100, 1) : 0,
            ];
        })->filter(fn ($s) => $s['count'] > 0)->values()->toArray();

        // ── Team Coverage ───────────────────────────────────────────
        $teams = \App\Models\Team::all();
        $coverageByTeam = $teams->map(function ($team) use ($plannings, $start, $end) {
            $assigned = $plannings->where('team_id', $team->id)->count();
            $teamUsers = $team->users()->count();
            $days = $start->diffInDays($end) + 1;
            $maxPossible = $teamUsers * $days;
            return [
                'team' => $team->name,
                'assigned' => $assigned,
                'total' => $maxPossible,
                'coverage' => $maxPossible > 0 ? round(($assigned / $maxPossible) * 100, 1) : 0,
            ];
        })->values()->toArray();

        // ── Task Distribution ───────────────────────────────────────
        $allTasks = Task::whereIn('planning_id', $plannings->pluck('id'))->get();
        $taskStatusDist = $allTasks->groupBy('status')->map(fn ($g, $k) => [
            'status' => $k, 'count' => $g->count(),
        ])->values()->toArray();
        $taskPriorityDist = $allTasks->groupBy('priority')->map(fn ($g, $k) => [
            'priority' => $k, 'count' => $g->count(),
        ])->values()->toArray();

        // ── Pause Statistics ────────────────────────────────────────
        $pauses = Pause::whereIn('planning_id', $plannings->pluck('id'))->get();
        $pauseCount = $pauses->count();
        $avgPauseDuration = $pauseCount > 0 ? round($pauses->avg('duration_minutes'), 1) : 0;

        // ── Ratings ─────────────────────────────────────────────────
        $ratings = Rating::where('week_number', $weekNumber)->where('year', $year)->get();
        $avgRating = $ratings->avg('score');
        $ratedCount = $ratings->count();

        // ── Attendance ──────────────────────────────────────────────
        $pointages = Pointage::whereIn('planning_id', $plannings->pluck('id'))->get();
        $onTimeCount = $pointages->where('status', 'on_time')->count();
        $lateCount = $pointages->where('status', 'late')->count();
        $absentCount = $totalAssignments - $pointages->pluck('planning_id')->unique()->count();

        // ── Leave count during period ───────────────────────────────
        $leaveCount = LeaveRequest::approved()
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->count();

        // ── Coverage by Day ─────────────────────────────────────────
        $coverageByDay = [];
        for ($d = $start->copy(); $d <= $end; $d->addDay()) {
            $ds = $d->toDateString();
            $dayPlannings = $plannings->filter(fn ($p) => $p->date->toDateString() === $ds);
            $dayChecks = $pointages->filter(fn ($pt) => $pt->check_in_at && $pt->check_in_at->toDateString() === $ds);
            $assigned = $dayPlannings->count();
            $checkedIn = $dayChecks->count();
            $coverageByDay[] = [
                'date' => $ds,
                'day' => $d->format('D'),
                'assigned' => $assigned,
                'checked_in' => $checkedIn,
                'coverage' => $assigned > 0 ? round(($checkedIn / $assigned) * 100, 1) : 0,
            ];
        }

        // ── Quality Score ──────────────────────────────────────────
        $qualityScore = null;
        try {
            $planningService = app(PlanningService::class);
            $qualityScore = $planningService->getQualityScore($weekNumber, $year);
        } catch (\Throwable $e) {
            $qualityScore = ['score' => 0, 'grade' => 'N/A', 'error' => $e->getMessage()];
        }

        // ── Absent / Missing Employees ──────────────────────────────
        $missingEmployees = User::whereIn('id', $missingEmployeeIds)->get()->map(fn ($u) => [
            'name' => $u->name,
            'team' => $u->teams->first()?->name ?? 'N/A',
        ])->toArray();

        return [
            'summary' => [
                'Period' => $start->format('M d, Y').' - '.$end->format('M d, Y'),
                'Week' => "W{$weekNumber} {$year}",
                'Total Assignments' => (string) $totalAssignments,
                'Locked' => (string) $lockedCount,
                'Unlocked' => (string) $unlockedCount,
                'Total Employees' => (string) $employeeCount,
                'Employees Planned' => (string) $plannedEmployeeCount,
                'Missing Employees' => (string) count($missingEmployeeIds),
                'Total Hours' => (string) round($totalHours, 1),
                'Average Hours/Employee' => (string) $avgHours,
                'Overtime Employees' => (string) $overtimeCount,
                'Under Hours Employees' => (string) $underHoursCount,
                'Task Count' => (string) $allTasks->count(),
                'Pause Count' => (string) $pauseCount,
                'Avg Pause Duration (min)' => (string) $avgPauseDuration,
                'Avg Rating' => $avgRating ? number_format($avgRating, 1) : 'N/A',
                'Rated Employees' => (string) $ratedCount,
                'On-Time Check-ins' => (string) $onTimeCount,
                'Late Check-ins' => (string) $lateCount,
                'Absences' => (string) $absentCount,
                'Leave Requests' => (string) $leaveCount,
                'Coverage Avg (%)' => count($coverageByDay) > 0
                    ? (string) round(collect($coverageByDay)->avg('coverage'), 1)
                    : '0',
                'Quality Score' => $qualityScore ? ($qualityScore['score'].' ('.$qualityScore['grade'].')') : 'N/A',
            ],
            'assignments' => array_merge(
                [['Date', 'Employee', 'Shift', 'Team', 'Status', 'Hours', 'Tasks', 'Locked']],
                $plannings->map(fn ($p) => [
                    $p->date->format('Y-m-d'),
                    $p->user->name,
                    $p->shift->name,
                    $p->team?->name ?? 'N/A',
                    $p->pointages?->first()?->status ?? 'absent',
                    (string) ($p->shift?->duration_hours ?? 0),
                    (string) $p->tasks->count(),
                    $p->is_locked ? 'Yes' : 'No',
                ])->toArray()
            ),
            'shift_distribution' => $shiftDistribution,
            'coverage_by_team' => $coverageByTeam,
            'coverage_by_day' => $coverageByDay,
            'task_status_distribution' => $taskStatusDist,
            'task_priority_distribution' => $taskPriorityDist,
            'overtime_employees' => $overtimeEmployees,
            'under_utilized' => $underUtilized,
            'missing_employees' => $missingEmployees,
            'quality_score' => $qualityScore,
            'report_type' => 'weekly',
        ];
    }

    protected function gatherMonthlyData(Carbon $start, Carbon $end): array
    {
        $plannings = Planning::with(['user', 'shift', 'team', 'tasks', 'pointages'])
            ->whereBetween('date', [$start, $end])
            ->get();

        $totalAssignments = $plannings->count();
        $employees = User::employees()->active()->count();

        $pointages = Pointage::with('user')
            ->whereBetween('check_in_at', [$start, $end])
            ->get();

        $totalHours = $pointages->sum('worked_minutes') / 60;
        $overtimeMinutes = $pointages->sum('overtime_minutes');
        $onTimeCount = $pointages->where('status', 'on_time')->count();
        $lateCount = $pointages->where('status', 'late')->count();

        $leaves = LeaveRequest::approved()
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->count();

        $ratings = Rating::whereBetween('created_at', [$start, $end])->get();
        $avgRating = $ratings->avg('score');
        $ratedCount = $ratings->count();

        $pauseCount = Pause::whereBetween('created_at', [$start, $end])->count();
        $taskCount = Task::whereHas('planning', fn ($q) => $q->whereBetween('date', [$start, $end]))->count();

        return [
            'summary' => [
                'Period' => $start->format('M d, Y').' - '.$end->format('M d, Y'),
                'Total Assignments' => (string) $totalAssignments,
                'Active Employees' => (string) $employees,
                'Total Hours Worked' => round($totalHours, 1),
                'Overtime Hours' => round($overtimeMinutes / 60, 1),
                'On-Time Check-ins' => (string) $onTimeCount,
                'Late Check-ins' => (string) $lateCount,
                'Leave Requests' => (string) $leaves,
                'Tasks Created' => (string) $taskCount,
                'Pauses Taken' => (string) $pauseCount,
                'Average Rating' => $avgRating ? number_format($avgRating, 1) : 'N/A',
                'Employees Rated' => (string) $ratedCount,
            ],
            'assignments' => array_merge(
                [['Date', 'Employee', 'Check In', 'Check Out', 'Worked (min)', 'Status']],
                $pointages->map(fn ($pt) => [
                    $pt->check_in_at->format('Y-m-d'),
                    $pt->user->name,
                    $pt->check_in_at->format('H:i'),
                    $pt->check_out_at?->format('H:i') ?? 'In Progress',
                    (string) ($pt->worked_minutes ?? 0),
                    $pt->status,
                ])->toArray()
            ),
            'report_type' => 'monthly',
        ];
    }

    protected function gatherCustomData(Carbon $start, Carbon $end): array
    {
        // Custom report: detailed breakdown with all data
        $weekly = $this->gatherWeeklyData($start, $end, (int) $start->isoWeek(), (int) $start->isoWeekYear());
        $monthly = $this->gatherMonthlyData($start, $end);

        return [
            'summary' => array_merge($weekly['summary'], $monthly['summary']),
            'assignments' => $weekly['assignments'],
            'shift_distribution' => $weekly['shift_distribution'] ?? [],
            'coverage_by_team' => $weekly['coverage_by_team'] ?? [],
            'coverage_by_day' => $weekly['coverage_by_day'] ?? [],
            'overtime_employees' => $weekly['overtime_employees'] ?? [],
            'missing_employees' => $weekly['missing_employees'] ?? [],
            'quality_score' => $weekly['quality_score'] ?? null,
            'report_type' => 'custom',
        ];
    }
}
