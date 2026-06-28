<?php

// app/Services/HoursCalculatorService.php

namespace App\Services;

use App\Models\Pause;
use App\Models\Planning;
use App\Models\Pointage;
use App\Models\User;
use Carbon\Carbon;

class HoursCalculatorService
{
    protected array $hoursCache = [];

    /**
     * Calculate total worked hours for a user in a given ISO week.
     * NOW EXCLUDES PAUSE TIME from calculation.
     */
    public function getWeeklyHours(User $user, int $weekNumber, int $year): float
    {
        $cacheKey = "{$user->id}:{$weekNumber}:{$year}";
        if (isset($this->hoursCache[$cacheKey])) {
            return $this->hoursCache[$cacheKey];
        }

        $now = Carbon::now();
        $weekStart = $now->copy()->setISODate($year, $weekNumber)->startOfWeek();
        $weekEnd = $weekStart->copy()->endOfWeek();
        $todayStr = $now->toDateString();

        $actualHours = Pointage::where('user_id', $user->id)
            ->whereNotNull('check_out_at')
            ->whereNotNull('worked_minutes')
            ->whereBetween('check_in_at', [$weekStart, $weekEnd])
            ->sum('worked_minutes') / 60;

        $todayRunning = Pointage::where('user_id', $user->id)
            ->whereDate('check_in_at', $todayStr)
            ->whereNull('check_out_at')
            ->first();

        $runningMinutes = 0;
        if ($todayRunning) {
            $runningMinutes = Carbon::parse($todayRunning->check_in_at)
                ->diffInMinutes($now);

            // Subtract active AND completed pause minutes from running count
            $pauseMinutes = Pause::where('user_id', $user->id)
                ->whereDate('pause_start', $todayStr)
                ->get()
                ->sum(function ($pause) {
                    $start = Carbon::parse($pause->pause_start);
                    $end = $pause->pause_end
                        ? Carbon::parse($pause->pause_end)
                        : Carbon::now();
                    return $start->diffInMinutes($end);
                });
            $runningMinutes = max(0, $runningMinutes - $pauseMinutes);
        }

        $futurePlannings = Planning::where('user_id', $user->id)
            ->where('week_number', $weekNumber)
            ->where('year', $year)
            ->where('date', '>', $todayStr)
            ->with('shift')
            ->get();

        // Batch-load all pause totals for future plannings (single query instead of N)
        $planningIds = $futurePlannings->pluck('id');
        $pauseMinutesByPlanning = [];
        if ($planningIds->isNotEmpty()) {
            $pauseMinutesByPlanning = Pause::whereIn('planning_id', $planningIds)
                ->whereNotNull('pause_end')
                ->groupBy('planning_id')
                ->selectRaw('planning_id, SUM(TIMESTAMPDIFF(MINUTE, pause_start, pause_end)) as total_minutes')
                ->pluck('total_minutes', 'planning_id');
        }

        $plannedHours = $futurePlannings->sum(function ($planning) use ($pauseMinutesByPlanning) {
            $shiftHours = $planning->shift ? $planning->shift->duration_hours : 0;
            $pauseMinutes = (int) ($pauseMinutesByPlanning[$planning->id] ?? 0);

            return max(0, $shiftHours - ($pauseMinutes / 60));
        });

        $total = round($actualHours + ($runningMinutes / 60) + $plannedHours, 2);
        $this->hoursCache[$cacheKey] = $total;

        return $total;
    }

    /**
     * Calculate weekly hours for multiple users in batch.
     * Reduces N+1 queries — uses 4-5 queries total regardless of user count.
     */
    public function getWeeklyHoursBatch(\Illuminate\Support\Collection $users, int $weekNumber, int $year): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        $userIds = $users->pluck('id')->toArray();
        $weekStart = Carbon::now()->setISODate($year, $weekNumber)->startOfWeek();
        $weekEnd = $weekStart->copy()->endOfWeek();
        $today = Carbon::now()->toDateString();

        // 1. Completed pointages (past + today with check_out)
        $pointageHours = Pointage::whereIn('user_id', $userIds)
            ->whereNotNull('check_out_at')
            ->whereNotNull('worked_minutes')
            ->whereBetween('check_in_at', [$weekStart, $weekEnd])
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(worked_minutes) / 60 as hours')
            ->pluck('hours', 'user_id');

        // 2. Running pointages for today (checked in, not out)
        $runningPointages = Pointage::whereIn('user_id', $userIds)
            ->whereDate('check_in_at', $today)
            ->whereNull('check_out_at')
            ->get()
            ->keyBy('user_id');

        // 2b. Active + completed pauses today for running pointages
        $todayPauses = Pause::whereIn('user_id', $userIds)
            ->whereDate('pause_start', $today)
            ->get()
            ->groupBy('user_id');

        // 3. Future plannings (dates > today, with shift)
        $futurePlannings = Planning::whereIn('user_id', $userIds)
            ->where('week_number', $weekNumber)
            ->where('year', $year)
            ->where('date', '>', $today)
            ->with('shift')
            ->get()
            ->groupBy('user_id');

        // 4. Pauses for all future plannings (single batch query)
        $allPlanningIds = $futurePlannings->flatten()->pluck('id');
        $pauseByPlanning = [];
        if ($allPlanningIds->isNotEmpty()) {
            $pauseByPlanning = Pause::whereIn('planning_id', $allPlanningIds)
                ->whereNotNull('pause_end')
                ->groupBy('planning_id')
                ->selectRaw('planning_id, SUM(TIMESTAMPDIFF(MINUTE, pause_start, pause_end)) as total_minutes')
                ->pluck('total_minutes', 'planning_id');
        }

        $results = [];
        foreach ($users as $user) {
            $uid = $user->id;

            // Completed hours
            $actual = (float) ($pointageHours[$uid] ?? 0);

            // Running pointage today
            $runningMinutes = 0;
            if (isset($runningPointages[$uid])) {
                $runningMinutes = Carbon::parse($runningPointages[$uid]->check_in_at)
                    ->diffInMinutes(Carbon::now());

                // Subtract active + completed pause minutes
                if (isset($todayPauses[$uid])) {
                    $pauseMins = $todayPauses[$uid]->sum(function ($p) {
                        $s = Carbon::parse($p->pause_start);
                        $e = $p->pause_end ? Carbon::parse($p->pause_end) : Carbon::now();
                        return $s->diffInMinutes($e);
                    });
                    $runningMinutes = max(0, $runningMinutes - $pauseMins);
                }
            }

            // Future planned hours (shift duration minus pauses)
            $planned = 0;
            if (isset($futurePlannings[$uid])) {
                foreach ($futurePlannings[$uid] as $planning) {
                    $shiftHours = $planning->shift ? $planning->shift->duration_hours : 0;
                    $pMins = (int) ($pauseByPlanning[$planning->id] ?? 0);
                    $planned += max(0, $shiftHours - ($pMins / 60));
                }
            }

            $results[$uid] = round($actual + ($runningMinutes / 60) + $planned, 2);

            // Also populate per-request cache so singular getWeeklyHours() hits it
            $cacheKey = "{$uid}:{$weekNumber}:{$year}";
            $this->hoursCache[$cacheKey] = $results[$uid];
        }

        return $results;
    }

    private function getHoursState(User $user, float $hours): string
    {
        $limit = $user->weekly_hours_limit ?? 44;

        if ($hours <= 38) {
            return 'green';
        } elseif ($hours <= $limit) {
            return 'orange';
        } else {
            return 'red';
        }
    }

    public function wouldExceedLimit(User $user, int $weekNumber, int $year, float $additionalHours): bool
    {
        $current = $this->getWeeklyHours($user, $weekNumber, $year);
        $limit = $user->weekly_hours_limit ?? 44;

        return ($current + $additionalHours) > $limit;
    }

    /**
     * Get alert message based on weekly hours
     *
     * Implements the prompt requirement:
     * "At 44 hours: Cell flashes orange → Above 44: Turns red
     *  Alert: 'Alerte: Heures Supplémentaires Détectées'
     *  Under-hours Alert: 'Quota non atteint'"
     */
    private function getAlertMessage(User $user, int $weekNumber, int $year): ?string
    {
        $hours = $this->getWeeklyHours($user, $weekNumber, $year);
        $limit = $user->weekly_hours_limit ?? 44;

        if ($hours > $limit) {
            $overtime = round($hours - $limit, 1);

            return "Alerte: Heures Supplémentaires Détectées ({$hours}h/{$limit}h, +{$overtime}h)";
        }

        if ($hours < 32) { // Under-hours threshold (38h target - 6h tolerance)
            $missing = round(38 - $hours, 1);

            return "Quota non atteint ({$hours}h, objectif 38h, manque {$missing}h)";
        }

        return null;
    }

    /**
     * Get complete hours status with color and message
     */
    public function getHoursStatus(User $user, int $weekNumber, int $year): array
    {
        $hours = $this->getWeeklyHours($user, $weekNumber, $year);
        $limit = $user->weekly_hours_limit ?? 44;

        return [
            'hours' => $hours,
            'limit' => $limit,
            'color' => $this->getHoursState($user, $hours),
            'alert_message' => $this->getAlertMessage($user, $weekNumber, $year),
            'is_overtime' => $hours > $limit,
            'is_under_hours' => $hours < 32,
        ];
    }
}
