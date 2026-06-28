<?php

namespace App\Console\Commands;

use App\Models\LeaveRequest;
use App\Models\Planning;
use App\Models\Pointage;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\HoursCalculatorService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateWeeklySnapshots extends Command
{
    protected $signature = 'snapshots:generate {--week=} {--year=}';

    protected $description = 'Generate weekly snapshots for dashboard history';

    public function handle(HoursCalculatorService $hoursCalculator): int
    {
        $now = Carbon::now();
        $weekNumber = (int) ($this->option('week') ?? $now->isoWeek());
        $year = (int) ($this->option('year') ?? $now->isoWeekYear());

        $this->generateSnapshot($weekNumber, $year, $hoursCalculator);

        $this->info("Weekly snapshot generated for week {$weekNumber}/{$year}");

        return Command::SUCCESS;
    }

    public function generateSnapshot(int $weekNumber, int $year, HoursCalculatorService $hoursCalculator): WeeklySnapshot
    {
        $now = Carbon::now();
        $weekStart = $now->copy()->setISODate($year, $weekNumber)->startOfWeek();
        $weekEnd = $weekStart->copy()->endOfWeek();

        $totalEmployees = User::where('status', 'active')->count();

        $totalPlanned = Planning::where('week_number', $weekNumber)
            ->where('year', $year)
            ->count();

        $totalCheckedIn = Pointage::whereBetween('check_in_at', [$weekStart, $weekEnd])
            ->whereNotNull('check_out_at')
            ->distinct('user_id')
            ->count('user_id');

        $plannedUserIds = Planning::where('week_number', $weekNumber)
            ->where('year', $year)
            ->distinct('user_id')
            ->pluck('user_id');

        $checkedInUserIds = Pointage::whereBetween('check_in_at', [$weekStart, $weekEnd])
            ->whereNotNull('check_out_at')
            ->distinct('user_id')
            ->pluck('user_id');

        $totalAbsences = $plannedUserIds->diff($checkedInUserIds)->count();

        $overtimeCount = 0;
        $underHoursCount = 0;
        $totalOvertimeHours = 0;

        $employees = User::where('status', 'active')->get();
        $hoursBatch = $hoursCalculator->getWeeklyHoursBatch($employees, $weekNumber, $year);

        foreach ($employees as $emp) {
            $hours = $hoursBatch[$emp->id] ?? 0;
            $limit = $emp->weekly_hours_limit;
            if ($hours > $limit) {
                $overtimeCount++;
                $totalOvertimeHours += max(0, $hours - $limit);
            }
            if ($hours < 32) {
                $underHoursCount++;
            }
        }

        $dailyCoverages = $this->computeDailyCoverage($weekStart, $weekEnd);

        $avgCoverage = count($dailyCoverages) > 0
            ? round(array_sum($dailyCoverages) / count($dailyCoverages), 1)
            : 0;

        return WeeklySnapshot::updateOrCreate(
            ['week_number' => $weekNumber, 'year' => $year],
            [
                'total_employees' => $totalEmployees,
                'total_planned' => $totalPlanned,
                'total_checked_in' => $totalCheckedIn,
                'total_absences' => $totalAbsences,
                'avg_coverage' => $avgCoverage,
                'total_overtime_hours' => round($totalOvertimeHours, 1),
                'overtime_employee_count' => $overtimeCount,
                'under_hours_employee_count' => $underHoursCount,
                'generated_at' => Carbon::now(),
            ]
        );
    }

    private function computeDailyCoverage(Carbon $weekStart, Carbon $weekEnd): array
    {
        $allLeaves = LeaveRequest::approved()
            ->where('start_date', '<=', $weekEnd->toDateString())
            ->where('end_date', '>=', $weekStart->toDateString())
            ->get(['user_id', 'start_date', 'end_date']);

        $assignedCounts = Planning::whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->groupBy('date')
            ->selectRaw('date, COUNT(*) as total')
            ->pluck('total', 'date');

        $checkedInCounts = Pointage::whereDate('check_in_at', '>=', $weekStart->toDateString())
            ->whereDate('check_in_at', '<=', $weekEnd->toDateString())
            ->groupByRaw('DATE(check_in_at)')
            ->selectRaw('DATE(check_in_at) as date, COUNT(DISTINCT user_id) as total')
            ->pluck('total', 'date');

        $dailyCoverages = [];
        for ($date = $weekStart->copy(); $date <= $weekEnd; $date->addDay()) {
            $dateStr = $date->toDateString();

            $onLeaveIds = $allLeaves->filter(fn ($l) => $l->start_date <= $dateStr && $l->end_date >= $dateStr)
                ->pluck('user_id');

            $assigned = ($assignedCounts[$dateStr] ?? 0) - $onLeaveIds->count();
            $assigned = max(0, $assigned);

            $checkedIn = $checkedInCounts[$dateStr] ?? 0;

            $dailyCoverages[] = $assigned > 0 ? ($checkedIn / $assigned) * 100 : 0;
        }

        return $dailyCoverages;
    }
}
