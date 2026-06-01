<?php

// app/Services/PauseService.php

namespace App\Services;

use App\Models\Pause;
use App\Models\Planning;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PauseService
{
    /**
     * Create pause for a single user
     * Validates: no overlap, within shift hours
     */
    public function createForUser(User $user, Planning $planning, string $start, string $end): Pause
    {
        // Validate pause is within planning shift hours
        $this->validateWithinShift($planning, $start, $end);
        
        // Validate no overlapping pauses for this user
        $this->validateNoOverlap($user->id, $planning->id, $start, $end);
        
        return Pause::create([
            'user_id' => $user->id,
            'planning_id' => $planning->id,
            'pause_start' => $start,
            'pause_end' => $end,
        ]);
    }

    /**
     * Create pause for entire team
     * Expands to all team members with planning on this shift
     */
    public function createForTeam(Team $team, Planning $planning, string $start, string $end): array
    {
        $this->validateWithinShift($planning, $start, $end);
        
        $pauses = [];
        
        // Get all users assigned to this planning via team
        $users = $team->users()
            ->whereHas('plannings', fn($q) => $q->where('id', $planning->id))
            ->get();
        
        foreach ($users as $user) {
            try {
                $pauses[] = $this->createForUser($user, $planning, $start, $end);
            } catch (\Exception $e) {
                // Skip users with conflicts, log if needed
                continue;
            }
        }
        
        return $pauses;
    }

    /**
     * Update pause (only time window, not user/planning)
     */
    public function update(Pause $pause, string $start, string $end): Pause
    {
        $this->validateWithinShift($pause->planning, $start, $end);
        $this->validateNoOverlap($pause->user_id, $pause->planning_id, $start, $end, $pause->id);
        
        $pause->update([
            'pause_start' => $start,
            'pause_end' => $end,
        ]);
        
        return $pause->fresh();
    }

    /**
     * Get active pauses for today (for dashboard)
     */
    public function getActiveToday(): array
    {
        $now = now();
        
        return Pause::whereHas('planning', function ($query) use ($now) {
                $query->whereDate('date', $now->toDateString());
            })
            ->with(['user', 'team', 'planning.shift'])
            ->get()
            ->filter(fn($pause) => $pause->is_active)
            ->values()
            ->toArray();
    }

    /**
     * Get pauses by planning ID
     */
    public function getByPlanning(int $planningId): array
    {
        return Pause::where('planning_id', $planningId)
            ->with(['user', 'team'])
            ->get()
            ->toArray();
    }

    /**
     * Calculate total pause minutes for a user in a planning
     * Used by HoursCalculatorService to exclude from working hours
     */
    public function getTotalPauseMinutes(int $userId, int $planningId): int
{
    return Pause::where('user_id', $userId)
        ->where('planning_id', $planningId)
        ->get()
        ->sum(function ($pause) {
            return Carbon::parse($pause->pause_start)
                ->diffInMinutes(Carbon::parse($pause->pause_end));
        });
}

    /**
     * Validate pause time is within planning shift hours
     */
    private function validateWithinShift(Planning $planning, string $start, string $end): void
    {
        $shiftStart = Carbon::parse($planning->shift->start_time);
        $shiftEnd = Carbon::parse($planning->shift->end_time);
        $pauseStart = Carbon::parse($start);
        $pauseEnd = Carbon::parse($end);
        
        // Handle night shifts crossing midnight
        if ($shiftEnd->lessThan($shiftStart)) {
            $shiftEnd->addDay();
            if ($pauseEnd->lessThan($pauseStart)) {
                $pauseEnd->addDay();
            }
        }
        
        if ($pauseStart->lessThan($shiftStart) || $pauseEnd->greaterThan($shiftEnd)) {
            throw new \InvalidArgumentException('Pause must be within shift hours');
        }
        
        if ($pauseEnd->lessThanOrEqualTo($pauseStart)) {
            throw new \InvalidArgumentException('Pause end must be after start');
        }
    }

    /**
     * Validate no overlapping pauses for same user in same planning
     */
    private function validateNoOverlap(int $userId, int $planningId, string $start, string $end, ?int $excludeId = null): void
    {
        $query = Pause::where('user_id', $userId)
            ->where('planning_id', $planningId)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('pause_start', [$start, $end])
                  ->orWhereBetween('pause_end', [$start, $end])
                  ->orWhere(function ($sq) use ($start, $end) {
                      $sq->where('pause_start', '<=', $start)
                         ->where('pause_end', '>=', $end);
                  });
            });
        
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }
        
        if ($query->exists()) {
            throw new \InvalidArgumentException('Overlapping pause exists for this user');
        }
    }
}