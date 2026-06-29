<?php

namespace App\Services;

use App\Models\Pause;
use App\Models\Planning;
use App\Models\Shift;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PauseService
{
    /**
     * Create pause for a single user with full validation.
     */
    public function createForUser(
        User $user,
        Planning $planning,
        string $start,
        string $end,
        string $type = 'break',
        ?string $reason = null,
        ?int $durationMinutes = null,
    ): Pause {
        [$pauseStart, $pauseEnd] = $this->buildPauseWindow($planning, $start, $end);

        $this->validateWithinShift($planning, $pauseStart, $pauseEnd);
        $this->validateNoOverlap($user->id, $planning->id, $pauseStart, $pauseEnd);
        $this->validateEmployeeHasPlanning($user, $planning);

        $now = now();
        $status = $this->computeInitialStatus($pauseStart, $pauseEnd);

        return Pause::create([
            'user_id' => $user->id,
            'planning_id' => $planning->id,
            'type' => $type,
            'reason' => $reason,
            'status' => $status,
            'pause_start' => $pauseStart,
            'pause_end' => $pauseEnd,
            'duration_minutes' => $durationMinutes,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * Create pause for entire team.
     */
    public function createForTeam(
        Team $team,
        Planning $planning,
        string $start,
        string $end,
        string $type = 'break',
        ?string $reason = null,
    ): array {
        [$pauseStart, $pauseEnd] = $this->buildPauseWindow($planning, $start, $end);
        $this->validateWithinShift($planning, $pauseStart, $pauseEnd);

        $now = now();
        $status = $this->computeInitialStatus($pauseStart, $pauseEnd);

        $users = $team->users()
            ->whereHas('plannings', fn ($q) => $q->where('id', $planning->id))
            ->get();

        $existingOverlaps = Pause::whereIn('user_id', $users->pluck('id'))
            ->where('planning_id', $planning->id)
            ->where(fn ($q) => $q->where('pause_start', '<', $pauseEnd)->where('pause_end', '>', $pauseStart))
            ->pluck('user_id')
            ->toArray();

        $pauses = [];
        foreach ($users as $user) {
            if (in_array($user->id, $existingOverlaps)) continue;
            $pauses[] = Pause::create([
                'user_id' => $user->id,
                'planning_id' => $planning->id,
                'team_id' => $team->id,
                'type' => $type,
                'reason' => $reason,
                'status' => $status,
                'pause_start' => $pauseStart,
                'pause_end' => $pauseEnd,
                'created_by' => auth()->id(),
            ]);
        }

        return $pauses;
    }

    /**
     * Update pause time window and metadata.
     */
    public function update(Pause $pause, array $data): Pause
    {
        $updates = [];

        if (isset($data['pause_start']) && isset($data['pause_end'])) {
            [$pauseStart, $pauseEnd] = $this->buildPauseWindow($pause->planning, $data['pause_start'], $data['pause_end']);
            $this->validateWithinShift($pause->planning, $pauseStart, $pauseEnd);
            $this->validateNoOverlap($pause->user_id, $pause->planning_id, $pauseStart, $pauseEnd, $pause->id);
            $updates['pause_start'] = $pauseStart;
            $updates['pause_end'] = $pauseEnd;
            $updates['status'] = $this->computeInitialStatus($pauseStart, $pauseEnd);
        }

        if (isset($data['type'])) $updates['type'] = $data['type'];
        if (array_key_exists('reason', $data)) $updates['reason'] = $data['reason'];
        if (array_key_exists('duration_minutes', $data)) $updates['duration_minutes'] = $data['duration_minutes'];

        if (!empty($updates)) {
            $pause->update($updates);
        }

        return $pause->fresh()->load(['user', 'planning.shift', 'team', 'canceller', 'creator']);
    }

    /**
     * Cancel a pause (only if scheduled or active).
     */
    public function cancel(Pause $pause): Pause
    {
        if (!in_array($pause->status, ['scheduled', 'active'])) {
            throw new \InvalidArgumentException('Cannot cancel a pause with status: ' . $pause->status);
        }

        $pause->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => auth()->id(),
        ]);

        return $pause->fresh()->load(['user', 'planning.shift', 'team', 'canceller', 'creator']);
    }

    /**
     * Complete (end early) an active pause.
     */
    public function completeEarly(Pause $pause): Pause
    {
        if ($pause->status !== 'active' && $pause->status !== 'scheduled') {
            throw new \InvalidArgumentException('Cannot complete a pause with status: ' . $pause->status);
        }

        $now = now();
        $pause->update([
            'status' => 'completed',
            'pause_end' => $now,
        ]);

        return $pause->fresh()->load(['user', 'planning.shift', 'team', 'canceller', 'creator']);
    }

    /**
     * Get active pauses for today (dashboard widget).
     */
    public function getActiveToday(): array
    {
        $now = now();

        return Pause::where(fn ($q) => $q->where('status', 'active')
            ->orWhere(fn ($q) => $q->where('status', 'scheduled')
                ->where('pause_start', '<=', $now)
                ->where('pause_end', '>', $now)))
            ->whereHas('planning', fn ($q) => $q->whereDate('date', $now->toDateString()))
            ->where('pause_start', '<=', $now)
            ->where('pause_end', '>', $now)
            ->with(['user', 'team', 'planning.shift'])
            ->get()
            ->toArray();
    }

    /**
     * Get pauses by planning ID.
     */
    public function getByPlanning(int $planningId): array
    {
        return Pause::where('planning_id', $planningId)
            ->with(['user', 'team', 'canceller', 'creator'])
            ->get()
            ->toArray();
    }

    /**
     * Get pauses for multiple planning IDs (batch).
     */
    public function getByPlanningBatch(array $planningIds): array
    {
        if (empty($planningIds)) return [];

        $pauses = Pause::whereIn('planning_id', $planningIds)
            ->with(['user', 'team', 'canceller', 'creator'])
            ->get()
            ->groupBy('planning_id')
            ->toArray();

        $result = [];
        foreach ($planningIds as $id) {
            $result[$id] = $pauses[$id] ?? [];
        }

        return $result;
    }

    /**
     * Get total pause minutes for a user in a planning.
     */
    public function getTotalPauseMinutes(int $userId, int $planningId): int
    {
        return (int) Pause::where('user_id', $userId)
            ->where('planning_id', $planningId)
            ->whereIn('status', ['active', 'completed'])
            ->get()
            ->sum(fn (Pause $pause) => $pause->pause_start->diffInMinutes($pause->pause_end));
    }

    /**
     * Get pause statistics.
     */
    public function getStats(): array
    {
        $now = now();
        $todayStr = $now->toDateString();

        $totalPauses = Pause::count();

        $avgDuration = (int) Pause::whereIn('status', ['completed', 'active'])
            ->whereNotNull('pause_start')
            ->whereNotNull('pause_end')
            ->get()
            ->avg(fn ($p) => $p->duration_minutes) ?? 0;

        $longestPause = Pause::whereIn('status', ['completed', 'active'])
            ->whereNotNull('pause_start')
            ->whereNotNull('pause_end')
            ->get()
            ->sortByDesc(fn ($p) => $p->duration_minutes)
            ->first();

        $currentlyActive = Pause::where('status', 'active')
            ->where('pause_start', '<=', $now)
            ->where('pause_end', '>', $now)
            ->count();

        $todayCount = Pause::whereHas('planning', fn ($q) => $q->whereDate('date', $todayStr))
            ->whereDate('pause_start', $todayStr)
            ->count();

        $byType = Pause::selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        $byStatus = Pause::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return [
            'total_pauses' => $totalPauses,
            'avg_duration_minutes' => $avgDuration,
            'longest_pause' => $longestPause ? [
                'id' => $longestPause->id,
                'user_name' => $longestPause->user?->name,
                'duration_minutes' => $longestPause->duration_minutes,
            ] : null,
            'currently_active' => $currentlyActive,
            'today_count' => $todayCount,
            'by_type' => $byType,
            'by_status' => $byStatus,
        ];
    }

    // ── Private helpers ──

    private function buildPauseWindow(Planning $planning, string $start, string $end): array
    {
        $date = $planning->date->toDateString();
        $pauseStart = Carbon::parse($date . ' ' . $start);
        $pauseEnd = Carbon::parse($date . ' ' . $end);

        if ($pauseEnd->lessThanOrEqualTo($pauseStart)) {
            $pauseEnd->addDay();
        }

        return [$pauseStart, $pauseEnd];
    }

    private function validateWithinShift(Planning $planning, Carbon $pauseStart, Carbon $pauseEnd): void
    {
        if (!$planning->shift) {
            throw new \InvalidArgumentException('Planning has no shift assigned');
        }

        $date = $planning->date->toDateString();
        $shiftStart = Carbon::parse($date . ' ' . $planning->shift->start_time->format('H:i'));
        $shiftEnd = Carbon::parse($date . ' ' . $planning->shift->end_time->format('H:i'));

        if ($shiftEnd->lessThan($shiftStart)) {
            $shiftEnd->addDay();
        }

        if ($pauseStart->lessThan($shiftStart) || $pauseEnd->greaterThan($shiftEnd)) {
            throw new \InvalidArgumentException('La pause doit être dans les horaires du shift (' 
                . $planning->shift->start_time->format('H:i') . ' - ' . $planning->shift->end_time->format('H:i') . ')');
        }

        if ($pauseEnd->lessThanOrEqualTo($pauseStart)) {
            throw new \InvalidArgumentException('La fin de la pause doit être après le début');
        }
    }

    private function validateNoOverlap(int $userId, int $planningId, Carbon $start, Carbon $end, ?int $excludeId = null): void
    {
        $query = Pause::where('user_id', $userId)
            ->where('planning_id', $planningId)
            ->whereIn('status', ['scheduled', 'active'])
            ->where(fn ($q) => $q->where('pause_start', '<', $end)->where('pause_end', '>', $start));

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw new \InvalidArgumentException('Une pause existe déjà sur cette période pour cet employé');
        }
    }

    private function validateEmployeeHasPlanning(User $user, Planning $planning): void
    {
        $exists = Planning::where('id', $planning->id)
            ->where('user_id', $user->id)
            ->exists();

        if (!$exists) {
            throw new \InvalidArgumentException('Cet employé n\'a pas de planning sur ce shift');
        }
    }

    private function computeInitialStatus(Carbon $pauseStart, Carbon $pauseEnd): string
    {
        $now = now();

        if ($pauseStart > $now) {
            return 'scheduled';
        }

        if ($pauseStart <= $now && $pauseEnd > $now) {
            return 'active';
        }

        if ($pauseEnd <= $now) {
            return 'completed';
        }

        return 'scheduled';
    }
}
