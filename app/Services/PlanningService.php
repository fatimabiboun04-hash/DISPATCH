<?php

namespace App\Services;



use App\Models\Rating;


use App\Models\Planning;
use App\Models\User;
use App\Models\Shift;
use App\Models\LeaveRequest;
use Carbon\Carbon;

class PlanningService
{
    protected HoursCalculatorService $hoursCalculator;

    public function __construct(HoursCalculatorService $hoursCalculator)
    {
        $this->hoursCalculator = $hoursCalculator;
    }

    /**
     * Check for conflicts before creating/updating a planning assignment.
     * Returns array: ['valid' => bool, 'errors' => []]
     */
    public function validateAssignment(User $user, Shift $shift, Carbon $date, ?int $excludePlanningId = null): array
    {
        $errors = [];

       // 1. Check for overlapping shift times (not just same date)
$overlappingPlannings = Planning::where('user_id', $user->id)
    ->where('date', $date->toDateString())
    ->when($excludePlanningId, function ($query) use ($excludePlanningId) {
        $query->where('id', '!=', $excludePlanningId);
    })
    ->with('shift')
    ->get()
    ->filter(function ($existing) use ($shift, $date) {
        $existingStart = Carbon::parse($date->toDateString() . ' ' . $existing->shift->start_time);
        $existingEnd = Carbon::parse($date->toDateString() . ' ' . $existing->shift->end_time);
        $newStart = Carbon::parse($date->toDateString() . ' ' . $shift->start_time);
        $newEnd = Carbon::parse($date->toDateString() . ' ' . $shift->end_time);
        
        // Handle night shifts crossing midnight
        if ($existingEnd->lessThan($existingStart)) {
            $existingEnd->addDay();
        }
        if ($newEnd->lessThan($newStart)) {
            $newEnd->addDay();
        }
        
        // Check for time overlap
        return $newStart < $existingEnd && $newEnd > $existingStart;
    });

if ($overlappingPlannings->isNotEmpty()) {
    $conflictingShift = $overlappingPlannings->first()->shift->name;
    $errors[] = "Employee already assigned to a shift that overlaps with this time period (conflicts with {$conflictingShift}).";
}

        // 2. Check if employee is on approved leave
        $onLeave = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->where('start_date', '<=', $date->toDateString())
            ->where('end_date', '>=', $date->toDateString())
            ->exists();

        if ($onLeave) {
            $errors[] = 'Employee is on approved leave for this date.';
        }

        // 3. Check 44-hour weekly limit
        $weekNumber = $date->isoWeek();
        $year = $date->isoWeekYear();
        
        if ($this->hoursCalculator->wouldExceedLimit($user, $weekNumber, $year, $shift->duration_hours)) {
            $errors[] = 'Assignment would exceed weekly hours limit (' . $user->weekly_hours_limit . 'h).';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Get smart suggestions for an empty planning slot.
     * Returns ranked list of employees with match percentage.
     */
    public function getSuggestions(Shift $shift, Carbon $date, ?int $teamId = null): array
    {
        $weekNumber = $date->isoWeek();
        $year = $date->isoWeekYear();

        // Base query: active employees
        $query = User::employees()->active();

        // Filter by team if specified
        if ($teamId) {
            $query->whereHas('teams', function ($q) use ($teamId) {
                $q->where('teams.id', $teamId);
            });
        }

        $employees = $query->with(['skills', 'ratings' => function ($q) use ($weekNumber, $year) {
            $q->where('week_number', $weekNumber)->where('year', $year);
        }])->get();

        $suggestions = [];

        foreach ($employees as $employee) {
            // Skip if already assigned this date
            $alreadyAssigned = Planning::where('user_id', $employee->id)
                ->where('date', $date->toDateString())
                ->exists();
            
            if ($alreadyAssigned) continue;

            // Skip if on approved leave
            $onLeave = LeaveRequest::where('user_id', $employee->id)
                ->where('status', 'approved')
                ->where('start_date', '<=', $date->toDateString())
                ->where('end_date', '>=', $date->toDateString())
                ->exists();
            
            if ($onLeave) continue;

            // Skip if would exceed hours limit
            if ($this->hoursCalculator->wouldExceedLimit($employee, $weekNumber, $year, $shift->duration_hours)) {
                continue;
            }

            // Calculate score
            $score = 0;
            $maxScore = 100;

            // Rating bonus/penalty
            $latestRating = $employee->ratings->first();
            if ($latestRating) {
                $score += $latestRating->type === 'excellent' ? 20 : -20;
            }

            // Hours proximity (optimal around 32-38h)
            $currentHours = $this->hoursCalculator->getWeeklyHours($employee, $weekNumber, $year);
            if ($currentHours >= 32 && $currentHours <= 38) {
                $score += 15;
            } elseif ($currentHours < 32) {
                $score += 10;
            }

            // Skill match (placeholder — would check shift-required skills)
            $score += min($employee->skills->count() * 5, 20);

            // Calculate percentage
            $percentage = max(0, min(100, $score + 50)); // Base 50% + score adjustments

            $suggestions[] = [
                'employee' => [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'initials' => $employee->initials,
                    'avatar' => $employee->avatar,
                ],
                'current_hours' => $currentHours,
                'weekly_limit' => $employee->weekly_hours_limit,
                'rating' => $latestRating ? $latestRating->type : null,
                'match_percentage' => round($percentage),
            ];
        }

        // Sort by match percentage descending
        usort($suggestions, fn($a, $b) => $b['match_percentage'] <=> $a['match_percentage']);

        return array_slice($suggestions, 0, 5);
    }
        /**
     * Get smart suggestions for a single employee (used by Friday workflow)
     * Returns available shifts for next week based on:
     * - Under 44 hours
     * - Not on leave
     * - High rating
     */
    public function getSuggestionsForEmployee(User $employee, int $weekNumber, int $year): array
    {
        // Get all shifts for next week days (Monday to Sunday)
        $startOfWeek = now()->setISODate($year, $weekNumber)->startOfWeek();
        $endOfWeek = $startOfWeek->copy()->endOfWeek();
        
        $suggestions = [];
        
        // Loop through each day of the week
        for ($date = $startOfWeek->copy(); $date <= $endOfWeek; $date->addDay()) {
            // Skip if already assigned for this day
            $alreadyAssigned = Planning::where('user_id', $employee->id)
                ->where('date', $date->toDateString())
                ->exists();
            
            if ($alreadyAssigned) continue;
            
            // Skip if on approved leave
            $onLeave = LeaveRequest::where('user_id', $employee->id)
                ->where('status', 'approved')
                ->where('start_date', '<=', $date->toDateString())
                ->where('end_date', '>=', $date->toDateString())
                ->exists();
            
            if ($onLeave) continue;
            
            // Get available shifts for this day
            $shifts = Shift::where('is_active', true)->get();
            
            foreach ($shifts as $shift) {
                // Skip if would exceed hours limit
                if ($this->hoursCalculator->wouldExceedLimit($employee, $weekNumber, $year, $shift->duration_hours)) {
                    continue;
                }
                
                // Calculate match score
                $score = 50; // Base score
                
                // Rating bonus
                $latestRating = Rating::where('user_id', $employee->id)
                    ->where('week_number', $weekNumber)
                    ->where('year', $year)
                    ->first();
                
                if ($latestRating) {
                    $score += $latestRating->type === 'excellent' ? 20 : -20;
                }
                
                // Hours proximity bonus (optimal: 32-38h)
                $currentHours = $this->hoursCalculator->getWeeklyHours($employee, $weekNumber, $year);
                if ($currentHours >= 32 && $currentHours <= 38) {
                    $score += 15;
                } elseif ($currentHours < 32) {
                    $score += 10;
                }
                
                $suggestions[] = [
                    'shift_id' => $shift->id,
                    'shift_name' => $shift->name,
                    'date' => $date->toDateString(),
                    'day_name' => $date->format('l'),
                    'match_percentage' => min(100, $score),
                    'current_hours' => $currentHours,
                ];
            }
        }
        
        // Sort by match percentage descending
        usort($suggestions, fn($a, $b) => $b['match_percentage'] <=> $a['match_percentage']);
        
        return $suggestions;
    }
}