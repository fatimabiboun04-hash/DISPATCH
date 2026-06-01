<?php

// app/Services/HoursCalculatorService.php

namespace App\Services;

use App\Models\Planning;
use App\Models\Pointage;
use App\Models\User;
use Carbon\Carbon;

class HoursCalculatorService
{
    protected PauseService $pauseService;

    public function __construct(PauseService $pauseService)
    {
        $this->pauseService = $pauseService;
    }

    /**
     * Calculate total worked hours for a user in a given ISO week.
     * NOW EXCLUDES PAUSE TIME from calculation.
     */
    public function getWeeklyHours(User $user, int $weekNumber, int $year): float
    {
        // Get actual worked hours from pointages
        $actualHours = Pointage::where('user_id', $user->id)
            ->whereNotNull('check_out_at')
            ->whereNotNull('worked_minutes')
            ->whereRaw('YEARWEEK(check_in_at, 1) = ?', [$year * 100 + $weekNumber])
            ->sum('worked_minutes') / 60;

        // Get planned hours for future dates in this week (minus pauses)
        $now = Carbon::now();
        $plannedHours = Planning::where('user_id', $user->id)
    ->where('week_number', $weekNumber)
    ->where('year', $year)
    ->where('date', '>', $now->toDateString())
    ->with('shift')
    ->get()
    ->sum(function ($planning) use ($user) {
        $shiftHours = $planning->shift ? $planning->shift->duration_hours : 0;

        $pauseHours = $this->pauseService
            ->getTotalPauseMinutes($user->id, $planning->id) / 60;

        return max(0, $shiftHours - $pauseHours);
    });

        return round($actualHours + $plannedHours, 2);
    }

    /**
     * Calculate worked hours for a specific pointage, excluding pause time
     */
    public function calculateWorkedMinutes(Pointage $pointage): int
    {
        $rawMinutes = Carbon::parse($pointage->check_in_at)
            ->diffInMinutes(Carbon::parse($pointage->check_out_at));
        
        // Subtract pause time if linked to planning
        if ($pointage->planning_id) {
            $pauseMinutes = $this->pauseService->getTotalPauseMinutes(
                $pointage->user_id,
                $pointage->planning_id
            );
            return max(0, $rawMinutes - $pauseMinutes);
        }
        
        return $rawMinutes;
    }

    // ... rest of existing methods unchanged ...
    
    public function getHoursState(User $user, float $hours): string
    {
        $limit = $user->weekly_hours_limit;

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
        return ($current + $additionalHours) > $user->weekly_hours_limit;
    }
        /**
     * Get alert message based on weekly hours
     * 
     * Implements the prompt requirement:
     * "At 44 hours: Cell flashes orange → Above 44: Turns red
     *  Alert: 'Alerte: Heures Supplémentaires Détectées'
     *  Under-hours Alert: 'Quota non atteint'"
     */
    public function getAlertMessage(User $user, int $weekNumber, int $year): ?string
    {
        $hours = $this->getWeeklyHours($user, $weekNumber, $year);
        $limit = $user->weekly_hours_limit;
        
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
        
        return [
            'hours' => $hours,
            'limit' => $user->weekly_hours_limit,
            'color' => $this->getHoursState($user, $hours),
            'alert_message' => $this->getAlertMessage($user, $weekNumber, $year),
            'is_overtime' => $hours > $user->weekly_hours_limit,
            'is_under_hours' => $hours < 32,
        ];
    }
}