<?php

// app/Services/PauseService.php

namespace App\Services;

use App\Models\Pause;
use App\Models\Planning;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;

class PauseService
{
    /**
     * Create pause for a single user
     * Validates: no overlap, within shift hours
     */
    public function createForUser(User $user, Planning $planning, string $start, string $end): Pause
    {
        [$pauseStart, $pauseEnd] = $this->buildPauseWindow($planning, $start, $end);

        // Validate pause is within planning shift hours
        $this->validateWithinShift($planning, $pauseStart, $pauseEnd);

        // Validate no overlapping pauses for this user
        $this->validateNoOverlap($user->id, $planning->id, $pauseStart, $pauseEnd);

        return Pause::create([
            'user_id' => $user->id,
            'planning_id' => $planning->id,
            'pause_start' => $pauseStart,
            'pause_end' => $pauseEnd,
        ]);
    }

    /**
     * Create pause for entire team
     * Expands to all team members with planning on this shift
     */
    public function createForTeam(Team $team, Planning $planning, string $start, string $end): array
    {
        [$pauseStart, $pauseEnd] = $this->buildPauseWindow($planning, $start, $end);
        $this->validateWithinShift($planning, $pauseStart, $pauseEnd);

        $pauses = [];

        $users = $team->users()
            ->whereHas('plannings', fn ($q) => $q->where('id', $planning->id))
            ->get();

        $userIds = $users->pluck('id')->toArray();

        $existingOverlaps = Pause::whereIn('user_id', $userIds)
            ->where('planning_id', $planning->id)
            ->where(function ($q) use ($pauseStart, $pauseEnd) {
                $q->where('pause_start', '<', $pauseEnd)
                  ->where('pause_end', '>', $pauseStart);
            })
            ->pluck('user_id')
->toArray();

        foreach ($users as $user) {
            if (in_array($user->id, $existingOverlaps)) {
                continue;
            }
            $pauses[] = Pause::create([
                'user_id' => $user->id,
                'planning_id' => $planning->id,
                'pause_start' => $pauseStart,
                'pause_end' => $pauseEnd,
            ]);
        }

        return $pauses;
    }

    /**
     * Update pause (only time window, not user/planning)
     */
    public function update(Pause $pause, string $start, string $end): Pause
    {
        [$pauseStart, $pauseEnd] = $this->buildPauseWindow($pause->planning, $start, $end);

        $this->validateWithinShift($pause->planning, $pauseStart, $pauseEnd);
        $this->validateNoOverlap($pause->user_id, $pause->planning_id, $pauseStart, $pauseEnd, $pause->id);

        $pause->update([
            'pause_start' => $pauseStart,
            'pause_end' => $pauseEnd,
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
            ->where('pause_end', '>', $now)
            ->where('pause_start', '<=', $now)
            ->with(['user', 'team', 'planning.shift'])
            ->get()
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
     * Get pauses for multiple planning IDs in a single query.
     * Returns array grouped by planning_id.
     */
    public function getByPlanningBatch(array $planningIds): array
    {
        if (empty($planningIds)) {
            return [];
        }

        $pauses = Pause::whereIn('planning_id', $planningIds)
            ->with(['user', 'team'])
            ->get()
            ->groupBy('planning_id')
            ->toArray();

        // Ensure every requested planning_id has an entry
        $result = [];
        foreach ($planningIds as $id) {
            $result[$id] = $pauses[$id] ?? [];
        }

        return $result;
    }

    /**
     * Calculate total pause minutes for a user in a planning
     * Used by HoursCalculatorService to exclude from working hours
     */
    public function getTotalPauseMinutes(int $userId, int $planningId): int
    {
        return (int) Pause::where('user_id', $userId)
            ->where('planning_id', $planningId)
            ->whereNotNull('pause_end')
            ->get()
            ->sum(fn (Pause $pause) => $pause->pause_start->diffInMinutes($pause->pause_end));
    }

    /**
     * Build concrete datetimes from planning date and HH:mm inputs.
     */
    private function buildPauseWindow(Planning $planning, string $start, string $end): array
    {
        $date = $planning->date->toDateString();
        $pauseStart = Carbon::parse($date.' '.$start);
        $pauseEnd = Carbon::parse($date.' '.$end);

        if ($pauseEnd->lessThanOrEqualTo($pauseStart)) {
            $pauseEnd->addDay();
        }

        return [$pauseStart, $pauseEnd];
    }

    /**
     * Validate pause time is within planning shift hours
     */
    private function validateWithinShift(Planning $planning, Carbon $pauseStart, Carbon $pauseEnd): void
    {
        $date = $planning->date->toDateString();
        $shiftStart = Carbon::parse($date.' '.$planning->shift->start_time);
        $shiftEnd = Carbon::parse($date.' '.$planning->shift->end_time);

        // Handle night shifts crossing midnight
        if ($shiftEnd->lessThan($shiftStart)) {
            $shiftEnd->addDay();
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
    private function validateNoOverlap(int $userId, int $planningId, Carbon $start, Carbon $end, ?int $excludeId = null): void
    {
        $query = Pause::where('user_id', $userId)
            ->where('planning_id', $planningId)
            ->where(function ($q) use ($start, $end) {
                $q->where('pause_start', '<', $end)
                    ->where('pause_end', '>', $start);
            });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw new \InvalidArgumentException('Overlapping pause exists for this user');
        }
    }
}
