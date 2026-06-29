<?php

namespace App\Services;

use App\Models\LeaveRequest;
use App\Models\Pause;
use App\Models\Planning;
use App\Models\PlanningAudit;
use App\Models\PlanningSandboxItem;
use App\Models\PlanningTemplate;
use App\Models\PlanningTemplateItem;
use App\Models\Rating;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlanningService
{
    // Scoring weights
    const SCORE_BASE                = 50;
    const SCORE_RATING              = 20;
    const SCORE_HOURS_PROXIMITY     = 15;
    const SCORE_SKILL_MATCH         = 25;
    const SCORE_WORKLOAD_BALANCE    = 10;
    const SCORE_REST_PERIOD         = 10;
    const SCORE_TEAM_COMPATIBILITY  = 5;
    const SCORE_CONSECUTIVE_DAYS    = 5;
    const SCORE_NIGHT_SHIFT_CONSEC  = 5;
    const SCORE_REPLACEMENT_FREQ    = 5;

    // Thresholds
    const REST_MINIMUM_MINUTES      = 660;  // 11h
    const REST_TIGHT_MINUTES        = 780;  // 13h
    const WEEKLY_LIMIT_DEFAULT      = 44;
    const UNDER_HOURS_THRESHOLD     = 32;
    const IDEAL_HOURS_MIN           = 32;
    const IDEAL_HOURS_MAX           = 38;
    const MAX_CONSECUTIVE_DAYS      = 5;
    const MAX_NIGHT_SHIFT_CONSEC    = 2;
    const SUGGESTION_LIMIT          = 5;

    protected HoursCalculatorService $hoursCalculator;

    public function __construct(HoursCalculatorService $hoursCalculator)
    {
        $this->hoursCalculator = $hoursCalculator;
    }

    // ─────────────────────────────────────────────────────────
    //  SCORING ENGINE
    // ─────────────────────────────────────────────────────────

    /**
     * Centralized scoring for employee suggestion ranking.
     * Returns score (0-100) + breakdown + explainable reasons.
     */
    protected function computeSuggestionScore(
        User $employee,
        Shift $shift,
        float $currentHours,
        ?object $latestRating,
        array $recentAssignments,
        float $avgRecent,
        ?int $teamId,
        array $consecutiveData,
        string $dateStr,
        bool $hasLeave,
        ?Planning $prevDayPlanning = null,
    ): array {
        $score = self::SCORE_BASE;
        $breakdown = [];
        $reasons = [];

        // 1. Rating (weight: 20) — proportional to score (1-5)
        $ratingScore = 0;
        if ($latestRating && $latestRating->score) {
            // Map score 1-5 to -20..+20 linearly
            $ratingScore = (int) round((($latestRating->score - 3) / 2) * self::SCORE_RATING);
            $reasons[] = Rating::scoreLabel($latestRating->score) . ' (' . $latestRating->score . '/5)';
        }
        $score += $ratingScore;
        $breakdown['rating'] = $ratingScore;

        // 2. Hours proximity (weight: 15)
        $hoursScore = 0;
        if ($currentHours >= self::IDEAL_HOURS_MIN && $currentHours <= self::IDEAL_HOURS_MAX) {
            $hoursScore = self::SCORE_HOURS_PROXIMITY;
            $reasons[] = sprintf('Ideal hours (%.0fh this week)', $currentHours);
        } elseif ($currentHours < self::UNDER_HOURS_THRESHOLD) {
            $hoursScore = round(self::SCORE_HOURS_PROXIMITY * 0.66);
            $reasons[] = sprintf('Under hours (%.0fh this week)', $currentHours);
        } elseif ($currentHours < self::WEEKLY_LIMIT_DEFAULT) {
            $hoursScore = round(self::SCORE_HOURS_PROXIMITY * 0.33);
            $reasons[] = sprintf('Near limit (%.0fh this week)', $currentHours);
        }
        $score += $hoursScore;
        $breakdown['hours_proximity'] = $hoursScore;

        // 3. Skill match (weight: 25)
        $skillScore = 0;
        if ($shift->skills->isNotEmpty()) {
            $employeeSkillIds = $employee->skills->pluck('id')->toArray();
            $requiredSkillIds = $shift->skills->pluck('id')->toArray();
            $matchedCount = count(array_intersect($employeeSkillIds, $requiredSkillIds));
            $ratio = count($requiredSkillIds) > 0 ? $matchedCount / count($requiredSkillIds) : 0;
            $skillScore = round($ratio * self::SCORE_SKILL_MATCH);
            $score += $skillScore;
            $reasons[] = $ratio >= 1
                ? 'All required skills matched'
                : sprintf('%d/%d required skills', $matchedCount, count($requiredSkillIds));
        }
        $breakdown['skill_match'] = $skillScore;

        // 4. Workload balance (weight: 10)
        $workloadScore = 0;
        $uid = $employee->id;
        $recentCount = $recentAssignments[$uid] ?? 0;
        if ($recentCount <= $avgRecent) {
            $workloadScore = self::SCORE_WORKLOAD_BALANCE;
            $reasons[] = 'Balanced workload';
        } elseif ($recentCount <= $avgRecent * 1.5) {
            $workloadScore = round(self::SCORE_WORKLOAD_BALANCE * 0.5);
            $reasons[] = 'Moderate recent workload';
        } else {
            $reasons[] = 'Heavy recent workload';
        }
        $score += $workloadScore;
        $breakdown['workload_balance'] = $workloadScore;

        // 5. Rest period (weight: 10) — uses pre-loaded data
        $restScore = 0;
        if ($prevDayPlanning && $prevDayPlanning->shift) {
            $prevEnd = Carbon::parse($prevDayPlanning->date->toDateString().' '.$prevDayPlanning->shift->end_time->format('H:i:s'));
            if ($prevEnd->lessThan(Carbon::parse($prevDayPlanning->date->toDateString().' '.$prevDayPlanning->shift->start_time->format('H:i:s')))) {
                $prevEnd->addDay();
            }
            $newStart = Carbon::parse($dateStr.' '.$shift->start_time->format('H:i:s'));
            $restMinutes = $prevEnd->diffInMinutes($newStart);
            if ($restMinutes < self::REST_MINIMUM_MINUTES) {
                $restScore = -(self::SCORE_REST_PERIOD * 3);
                $reasons[] = sprintf('Rest period violation (%dh < 11h minimum)', round($restMinutes / 60));
            } elseif ($restMinutes < self::REST_TIGHT_MINUTES) {
                $restScore = -self::SCORE_REST_PERIOD;
                $reasons[] = sprintf('Rest period tight (%dh)', round($restMinutes / 60));
            } else {
                $restScore = self::SCORE_REST_PERIOD;
                $reasons[] = sprintf('Good rest period (%dh)', round($restMinutes / 60));
            }
        } else {
            $restScore = round(self::SCORE_REST_PERIOD * 0.5);
            $reasons[] = 'No previous day assignment (flexible)';
        }
        $score += $restScore;
        $breakdown['rest_period'] = $restScore;

        // 6. Team compatibility (weight: 5)
        $teamScore = 0;
        if ($teamId && $employee->teams()->where('teams.id', $teamId)->exists()) {
            $teamScore = self::SCORE_TEAM_COMPATIBILITY;
            $reasons[] = 'Same team';
        }
        $score += $teamScore;
        $breakdown['team_compatibility'] = $teamScore;

        // 7. Consecutive working days (weight: 5)
        $consecScore = 0;
        $consecDays = $consecutiveData['days'] ?? 0;
        if ($consecDays > self::MAX_CONSECUTIVE_DAYS) {
            $consecScore = -self::SCORE_CONSECUTIVE_DAYS;
            $reasons[] = sprintf('%d consecutive days (max %d)', $consecDays, self::MAX_CONSECUTIVE_DAYS);
        } elseif ($consecDays <= 2) {
            $consecScore = self::SCORE_CONSECUTIVE_DAYS;
            $reasons[] = 'Low consecutive days';
        }
        $score += $consecScore;
        $breakdown['consecutive_days'] = $consecScore;

        // 8. Consecutive night shifts (weight: 5)
        $nightScore = 0;
        $nightConsec = $consecutiveData['night_shifts'] ?? 0;
        if ($nightConsec > self::MAX_NIGHT_SHIFT_CONSEC) {
            $nightScore = -self::SCORE_NIGHT_SHIFT_CONSEC;
            $reasons[] = sprintf('%d consecutive night shifts (max %d)', $nightConsec, self::MAX_NIGHT_SHIFT_CONSEC);
        } elseif ($nightConsec > 0) {
            $reasons[] = sprintf('%d night shift(s) this week', $nightConsec);
        }
        $score += $nightScore;
        $breakdown['night_shift_consecutive'] = $nightScore;

        // 9. Replacement frequency (weight: 5)
        $replacementScore = 0;
        $totalPlannings = $recentAssignments[$uid] ?? 0;
        if ($totalPlannings > 0 && $avgRecent > 0) {
            $ratio = $totalPlannings / max(1, $avgRecent);
            if ($ratio <= 0.7) {
                $replacementScore = self::SCORE_REPLACEMENT_FREQ;
                $reasons[] = 'Low assignment frequency (available)';
            } elseif ($ratio >= 1.3) {
                $replacementScore = -self::SCORE_REPLACEMENT_FREQ;
                $reasons[] = 'High assignment frequency';
            }
        }
        $score += $replacementScore;
        $breakdown['replacement_frequency'] = $replacementScore;

        // Cap at 0-100
        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'breakdown' => $breakdown,
            'reasons' => $reasons,
        ];
    }

    /**
     * Compute consecutive work data for an employee.
     */
    protected function computeConsecutiveData(Collection $weekPlannings, Shift $targetShift, string $dateStr): array
    {
        $targetDate = Carbon::parse($dateStr);
        $days = 0;
        $nightShifts = 0;

        $checkDate = $targetDate->copy()->subDay();
        while ($weekPlannings->first(fn ($p) => $p->date === $checkDate->toDateString())) {
            $days++;
            $checkDate->subDay();
        }

        $isNight = fn ($shift) => $shift && (
            $shift->end_time >= '22:00' || $shift->start_time <= '06:00'
        );
        $nightShifts = $weekPlannings->filter(fn ($p) => $isNight($p->shift))->count();
        if ($isNight($targetShift)) {
            $nightShifts++;
        }

        return ['days' => $days, 'night_shifts' => $nightShifts];
    }

    // ─────────────────────────────────────────────────────────
    //  COVERAGE
    // ─────────────────────────────────────────────────────────

    public function getCoverage(string $startDate, string $endDate): array
    {
        $shifts = Shift::where('is_active', true)->get();
        $plannings = Planning::whereBetween('date', [$startDate, $endDate])
            ->with('shift')
            ->get()
            ->groupBy(['date', 'shift_id']);

        $coverage = [];
        $period = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        while ($period <= $end) {
            $dateStr = $period->toDateString();
            $dayCoverage = [];

            foreach ($shifts as $shift) {
                $count = isset($plannings[$dateStr][$shift->id])
                    ? count($plannings[$dateStr][$shift->id])
                    : 0;

                $dayCoverage[] = [
                    'shift_id' => $shift->id,
                    'shift_name' => $shift->name,
                    'shift_type' => $shift->type,
                    'start_time' => $shift->start_time,
                    'end_time' => $shift->end_time,
                    'count' => $count,
                    'status' => $count === 0 ? 'empty' : ($count <= 2 ? 'low' : 'adequate'),
                ];
            }

            $coverage[] = [
                'date' => $dateStr,
                'day_name' => $period->locale('fr')->dayName,
                'shifts' => $dayCoverage,
            ];

            $period->addDay();
        }

        return $coverage;
    }

    // ─────────────────────────────────────────────────────────
    //  PLANNING QUALITY SCORE
    // ─────────────────────────────────────────────────────────

    public function getQualityScore(int $weekNumber, int $year): array
    {
        $startOfWeek = now()->setISODate($year, $weekNumber)->startOfWeek();
        $endOfWeek = $startOfWeek->copy()->endOfWeek();

        $plannings = Planning::where('week_number', $weekNumber)
            ->where('year', $year)
            ->with(['shift', 'user'])
            ->get();

        if ($plannings->isEmpty()) {
            return ['score' => 0, 'factors' => [], 'grade' => 'N/A'];
        }

        $totalShifts = $plannings->count();
        $users = $plannings->groupBy('user_id');

        $activeShifts = Shift::where('is_active', true)->count() ?: 1;
        $maxAssignments = $activeShifts * 7;
        $coverageRatio = min(1, $totalShifts / max(1, $maxAssignments));
        $coverageScore = round($coverageRatio * 30);

        $userHours = [];
        foreach ($users as $userId => $userPlannings) {
            $total = 0;
            foreach ($userPlannings as $p) {
                $total += $p->shift?->duration_hours ?? 0;
            }
            $userHours[$userId] = $total;
        }
        $hoursBalanceScore = 0;
        if (!empty($userHours)) {
            $avgHours = array_sum($userHours) / count($userHours);
            $variance = 0;
            foreach ($userHours as $h) {
                $variance += abs($h - $avgHours);
            }
            $avgVariance = $variance / count($userHours);
            $hoursBalanceScore = round(max(0, 25 - ($avgVariance * 2)));
        }

        $overtimeCount = 0;
        foreach ($users as $userId => $userPlannings) {
            $total = 0;
            foreach ($userPlannings as $p) {
                $total += $p->shift?->duration_hours ?? 0;
            }
            if ($total > self::WEEKLY_LIMIT_DEFAULT) $overtimeCount++;
        }
        $overtimeRatio = $users->count() > 0 ? $overtimeCount / $users->count() : 0;
        $overtimeScore = round(max(0, 20 - ($overtimeRatio * 40)));

        $restViolations = 0;
        $restChecks = 0;
        foreach ($users as $userId => $userPlannings) {
            $sorted = $userPlannings->sortBy('date');
            $prev = null;
            foreach ($sorted as $p) {
                if ($prev && $prev->shift) {
                    $prevEnd = Carbon::parse($prev->date->toDateString().' '.$prev->shift->end_time->format('H:i'));
                    $newStart = Carbon::parse($p->date->toDateString().' '.$p->shift->start_time->format('H:i'));
                    if ($prevEnd->greaterThan($newStart)) $prevEnd->addDay();
                    $restMinutes = $prevEnd->diffInMinutes($newStart);
                    $restChecks++;
                    if ($restMinutes < self::REST_MINIMUM_MINUTES) $restViolations++;
                }
                $prev = $p;
            }
        }
        $restComplianceRatio = $restChecks > 0 ? 1 - ($restViolations / $restChecks) : 1;
        $restScore = round($restComplianceRatio * 15);

        $lockedCount = $plannings->where('is_locked', true)->count();
        $conflictScore = round(($lockedCount / max(1, $totalShifts)) * 10);

        $totalScore = $coverageScore + $hoursBalanceScore + $overtimeScore + $restScore + $conflictScore;

        $grade = $totalScore >= 90 ? 'A' : ($totalScore >= 75 ? 'B' : ($totalScore >= 60 ? 'C' : 'D'));

        return [
            'score' => min(100, $totalScore),
            'grade' => $grade,
            'factors' => [
                'coverage' => ['score' => $coverageScore, 'max' => 30, 'label' => 'Couverture'],
                'hours_balance' => ['score' => $hoursBalanceScore, 'max' => 25, 'label' => 'Équilibre des heures'],
                'overtime' => ['score' => $overtimeScore, 'max' => 20, 'label' => 'Heures supplémentaires'],
                'rest_compliance' => ['score' => $restScore, 'max' => 15, 'label' => 'Respect du repos'],
                'conflicts' => ['score' => $conflictScore, 'max' => 10, 'label' => 'Conflits'],
            ],
            'stats' => [
                'total_assignments' => $totalShifts,
                'employees_assigned' => $users->count(),
                'overtime_employees' => $overtimeCount,
                'rest_violations' => $restViolations,
                'locked_count' => $lockedCount,
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────

    public function validateAssignment(User $user, Shift $shift, Carbon $date, ?int $excludePlanningId = null): array
    {
        $errors = [];

        // 1. Overlapping shifts
        $overlappingPlannings = Planning::where('user_id', $user->id)
            ->where('date', $date->toDateString())
            ->when($excludePlanningId, function ($query) use ($excludePlanningId) {
                $query->where('id', '!=', $excludePlanningId);
            })
            ->with('shift')
            ->get()
            ->filter(function ($existing) use ($shift, $date) {
                $existingStart = Carbon::parse($date->toDateString().' '.$existing->shift->start_time->format('H:i:s'));
                $existingEnd = Carbon::parse($date->toDateString().' '.$existing->shift->end_time->format('H:i:s'));
                $newStart = Carbon::parse($date->toDateString().' '.$shift->start_time->format('H:i:s'));
                $newEnd = Carbon::parse($date->toDateString().' '.$shift->end_time->format('H:i:s'));

                if ($existingEnd->lessThan($existingStart)) {
                    $existingEnd->addDay();
                }
                if ($newEnd->lessThan($newStart)) {
                    $newEnd->addDay();
                }

                return $newStart < $existingEnd && $newEnd > $existingStart;
            });

        if ($overlappingPlannings->isNotEmpty()) {
            $conflicting = $overlappingPlannings->first();
            $conflictingShift = $conflicting->shift;
            $errors[] = [
                'type' => 'overlap',
                'severity' => 'error',
                'message' => "Employee already assigned to a shift that overlaps with this time period (conflicts with {$conflictingShift->name}).",
                'suggestion' => "Choisissez un shift qui ne chevauche pas l'assignation existante ({$conflictingShift->name}).",
                'planning_id' => $conflicting->id,
                'conflict_details' => [
                    'planning_id' => $conflicting->id,
                    'shift_name' => $conflictingShift->name,
                    'shift_type' => $conflictingShift->type,
                    'start_time' => $conflictingShift->start_time->format('H:i'),
                    'end_time' => $conflictingShift->end_time->format('H:i'),
                    'date' => $date->toDateString(),
                ],
            ];
        }

        // 2. Approved leave
        $leaveRecord = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->where('start_date', '<=', $date->toDateString())
            ->where('end_date', '>=', $date->toDateString())
            ->first();

        if ($leaveRecord) {
            $errors[] = [
                'type' => 'leave_conflict',
                'severity' => 'error',
                'message' => "Employee is on approved leave ({$leaveRecord->type}) from {$leaveRecord->start_date} to {$leaveRecord->end_date}.",
                'suggestion' => 'Choisissez un autre employé non en congé ou attendez le retour de congés.',
                'conflict_details' => [
                    'leave_id' => $leaveRecord->id,
                    'type' => $leaveRecord->type,
                    'start_date' => $leaveRecord->start_date,
                    'end_date' => $leaveRecord->end_date,
                    'reason' => $leaveRecord->reason,
                ],
            ];
        }

        // 3. Rest period (min 11h)
        $prevShift = Planning::where('user_id', $user->id)
            ->where('date', $date->copy()->subDay()->toDateString())
            ->whereNotNull('shift_id')
            ->with('shift')
            ->first();

        if ($prevShift && $prevShift->shift) {
            $prevEnd = Carbon::parse($prevShift->date->toDateString().' '.$prevShift->shift->end_time->format('H:i:s'));
            if ($prevEnd->lessThan(Carbon::parse($prevShift->date->toDateString().' '.$prevShift->shift->start_time->format('H:i:s')))) {
                $prevEnd->addDay();
            }
            $newStart = Carbon::parse($date->toDateString().' '.$shift->start_time->format('H:i:s'));
            $restMinutes = $prevEnd->diffInMinutes($newStart);
            if ($restMinutes < self::REST_MINIMUM_MINUTES) {
                $errors[] = [
                    'type' => 'rest_period_violation',
                    'severity' => 'error',
                    'message' => "Insufficient rest period ({$restMinutes}min). Minimum 11 hours required between shifts.",
                    'suggestion' => "Décalez le début du shift ou choisissez un shift plus tardif pour respecter les 11h de repos minimum.",
                    'conflict_details' => [
                        'rest_minutes' => $restMinutes,
                        'required_minutes' => self::REST_MINIMUM_MINUTES,
                        'prev_shift_name' => $prevShift->shift->name,
                        'prev_shift_end' => $prevShift->shift->end_time->format('H:i'),
                        'new_shift_start' => $shift->start_time->format('H:i'),
                    ],
                ];
            }
        }

        // 4. Weekly hours limit
        $weekNumber = $date->isoWeek();
        $year = $date->isoWeekYear();

        $currentHours = $this->hoursCalculator->getWeeklyHours($user, $weekNumber, $year);
        $limit = $user->weekly_hours_limit ?? self::WEEKLY_LIMIT_DEFAULT;
        $afterAssignment = $currentHours + $shift->duration_hours;

        if ($this->hoursCalculator->wouldExceedLimit($user, $weekNumber, $year, $shift->duration_hours)) {
            $errors[] = [
                'type' => 'weekly_hours_exceeded',
                'severity' => 'error',
                'message' => 'Assignment would exceed weekly hours limit (' . $limit . 'h).',
                'suggestion' => "Réduisez les heures de la semaine ou augmentez la limite (actuellement {$currentHours}h + {$shift->duration_hours}h = {$afterAssignment}h > {$limit}h).",
                'conflict_details' => [
                    'current_hours' => round($currentHours, 1),
                    'assignment_hours' => $shift->duration_hours,
                    'limit' => $limit,
                    'after_assignment' => round($afterAssignment, 1),
                    'difference' => round($afterAssignment - $limit, 1),
                ],
            ];
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Enhanced suggestion engine with weighted scoring.
     */
    public function getSuggestions(Shift $shift, Carbon $date, ?int $teamId = null): array
    {
        $weekNumber = $date->isoWeek();
        $year = $date->isoWeekYear();
        $dateStr = $date->toDateString();

        $query = User::employees()->active();

        if ($teamId) {
            $query->whereHas('teams', function ($q) use ($teamId) {
                $q->where('teams.id', $teamId);
            });
        }

        $shift->loadMissing('skills');

        $version = Cache::get('suggestions_version', 1);
        $cacheKey = "suggestions:v{$version}:{$shift->id}:{$dateStr}:{$teamId}";
        $cacheTTL = now()->addMinutes(5);

        return Cache::remember($cacheKey, $cacheTTL, function () use ($query, $shift, $dateStr, $weekNumber, $year, $date, $teamId) {
            $employees = $query->with(['skills', 'ratings' => function ($q) use ($weekNumber, $year) {
                $q->where('week_number', $weekNumber)->where('year', $year);
            }])->get();

            if ($employees->isEmpty()) {
                return [];
            }

            $employeeIds = $employees->pluck('id')->toArray();

            // Batch pre-load all constraints
            $assignedIds = Planning::whereIn('user_id', $employeeIds)
                ->where('date', $dateStr)
                ->pluck('user_id')
                ->toArray();
            $assignedSet = array_flip($assignedIds);

            $onLeaveIds = LeaveRequest::approved()
                ->whereIn('user_id', $employeeIds)
                ->where('start_date', '<=', $dateStr)
                ->where('end_date', '>=', $dateStr)
                ->pluck('user_id')
                ->toArray();
            $onLeaveSet = array_flip($onLeaveIds);

            $hoursBatch = $this->hoursCalculator->getWeeklyHoursBatch($employees, $weekNumber, $year);

            // Load recent assignments (last 4 weeks) for workload balance
            $fourWeeksAgo = Carbon::parse($dateStr)->subWeeks(4);
            $recentAssignments = Planning::whereIn('user_id', $employeeIds)
                ->where('date', '>=', $fourWeeksAgo->toDateString())
                ->where('date', '<', $dateStr)
                ->select('user_id', DB::raw('COUNT(*) as count'))
                ->groupBy('user_id')
                ->pluck('count', 'user_id')
                ->toArray();

            $suggestions = [];
            $limit = $shift->duration_hours;
            $avgRecent = $employees->count() > 0
                ? array_sum($recentAssignments) / max(1, count($recentAssignments))
                : 0;

            // Batch pre-load consecutive day data for all employees
            $prevDate = Carbon::parse($dateStr)->subDay()->toDateString();
            $prevPlannings = Planning::whereIn('user_id', $employees->pluck('id'))
                ->where('date', $prevDate)
                ->with('shift')
                ->get()
                ->keyBy('user_id');

            // Pre-load current week assignments for consecutive day tracking
            $weekStart = Carbon::parse($dateStr)->startOfWeek();
            $weekEnd = Carbon::parse($dateStr)->endOfWeek();
            $allWeekPlannings = Planning::whereIn('user_id', $employees->pluck('id'))
                ->whereBetween('date', [$weekStart, $weekEnd])
                ->with('shift')
                ->get()
                ->groupBy('user_id');

            foreach ($employees as $employee) {
                $uid = $employee->id;

                if (isset($assignedSet[$uid])) continue;
                if (isset($onLeaveSet[$uid])) continue;

                $currentHours = $hoursBatch[$uid] ?? 0;
                $hoursLimit = $employee->weekly_hours_limit ?? self::WEEKLY_LIMIT_DEFAULT;
                if ($hoursLimit !== null && ($currentHours + $limit) > $hoursLimit) continue;

                $latestRating = $employee->ratings->sortByDesc('created_at')->first();
                $prevDayPlanning = $prevPlannings->get($uid);
                $employeeWeekPlannings = $allWeekPlannings->get($uid);
                if (!$employeeWeekPlannings) {
                    $employeeWeekPlannings = new Collection();
                }

                // Compute consecutive day data
                $consecutiveData = $this->computeConsecutiveData($employeeWeekPlannings, $shift, $dateStr);

                $result = $this->computeSuggestionScore(
                    employee: $employee,
                    shift: $shift,
                    currentHours: $currentHours,
                    latestRating: $latestRating,
                    recentAssignments: $recentAssignments,
                    avgRecent: $avgRecent,
                    teamId: $teamId,
                    consecutiveData: $consecutiveData,
                    dateStr: $dateStr,
                    hasLeave: isset($onLeaveSet[$uid]),
                    prevDayPlanning: $prevDayPlanning,
                );

                $score = $result['score'];
                $reasons = $result['reasons'];
                $breakdown = $result['breakdown'];

                $suggestions[] = [
                    'employee' => [
                        'id' => $employee->id,
                        'name' => $employee->name,
                        'initials' => $employee->initials,
                        'avatar_url' => $employee->avatar_url,
                    ],
                    'current_hours' => $currentHours,
                    'weekly_limit' => $employee->weekly_hours_limit,
                    'rating' => $latestRating ? $latestRating->score : null,
                    'match_percentage' => $score,
                    'score_breakdown' => $breakdown,
                    'reasons' => $reasons,
                ];
            }

            usort($suggestions, fn ($a, $b) => $b['match_percentage'] <=> $a['match_percentage']);

            return array_slice($suggestions, 0, self::SUGGESTION_LIMIT);
        });
    }

    public function getSuggestionsForEmployee(User $employee, int $weekNumber, int $year): array
    {
        $employee->loadMissing('skills');

        $startOfWeek = now()->setISODate($year, $weekNumber)->startOfWeek();
        $endOfWeek = $startOfWeek->copy()->endOfWeek();
        $uid = $employee->id;

        $assignedDates = Planning::where('user_id', $uid)
            ->where('week_number', $weekNumber)
            ->where('year', $year)
            ->pluck('date')
            ->map(fn ($d) => $d instanceof Carbon ? $d->toDateString() : (string) $d)
            ->toArray();
        $assignedSet = array_flip($assignedDates);

        $leaveRecords = LeaveRequest::where('user_id', $uid)
            ->where('status', 'approved')
            ->where('start_date', '<=', $endOfWeek->toDateString())
            ->where('end_date', '>=', $startOfWeek->toDateString())
            ->get(['start_date', 'end_date']);

        $latestRating = Rating::where('user_id', $uid)
            ->where('week_number', $weekNumber)
            ->where('year', $year)
            ->latest('created_at')
            ->first();

        $currentHours = $this->hoursCalculator->getWeeklyHours($employee, $weekNumber, $year);
        $shifts = Shift::where('is_active', true)->with('skills')->get();

        $shiftSkillIds = [];
        foreach ($shifts as $shift) {
            $shiftSkillIds[$shift->id] = $shift->skills->pluck('id')->toArray();
        }
        $employeeSkillIds = $employee->skills->pluck('id')->toArray();

        $suggestions = [];

        for ($date = $startOfWeek->copy(); $date <= $endOfWeek; $date->addDay()) {
            $dateStr = $date->toDateString();

            if (isset($assignedSet[$dateStr])) continue;

            $onLeave = $leaveRecords->contains(function ($leave) use ($dateStr) {
                return $dateStr >= $leave->start_date && $dateStr <= $leave->end_date;
            });
            if ($onLeave) continue;

            foreach ($shifts as $shift) {
                $hoursLimit = $employee->weekly_hours_limit;
                if ($hoursLimit !== null && ($currentHours + $shift->duration_hours) > $hoursLimit) continue;

                $score = 50;

                if ($latestRating && $latestRating->score) {
                    $score += (int) round((($latestRating->score - 3) / 2) * 20);
                }

                if ($currentHours >= 32 && $currentHours <= 38) {
                    $score += 15;
                } elseif ($currentHours < 32) {
                    $score += 10;
                }

                $requiredSkillIds = $shiftSkillIds[$shift->id] ?? [];
                if (!empty($requiredSkillIds)) {
                    $matchedCount = count(array_intersect($employeeSkillIds, $requiredSkillIds));
                    $ratio = $matchedCount / count($requiredSkillIds);
                    $score += round($ratio * 25);
                }

                $suggestions[] = [
                    'shift_id' => $shift->id,
                    'shift_name' => $shift->name,
                    'date' => $dateStr,
                    'day_name' => $date->format('l'),
                    'match_percentage' => min(100, $score),
                    'current_hours' => $currentHours,
                ];
            }
        }

        usort($suggestions, fn ($a, $b) => $b['match_percentage'] <=> $a['match_percentage']);

        return $suggestions;
    }

    public function getConflictsForLeave(int $userId, string $startDate, string $endDate): array
    {
        return Planning::where('user_id', $userId)
            ->whereBetween('date', [$startDate, $endDate])
            ->with(['shift' => fn ($q) => $q->select('id', 'name'),
                    'team' => fn ($q) => $q->select('id', 'name')])
            ->select('id', 'date', 'shift_id', 'team_id')
            ->get()
            ->map(fn ($p) => [
                'planning_id' => $p->id,
                'date' => $p->date->toDateString(),
                'shift_name' => $p->shift?->name ?? 'N/A',
                'team_name' => $p->team?->name ?? 'N/A',
            ])
            ->toArray();
    }

    public function removeEmployeeFromDateRange(int $userId, string $startDate, string $endDate): int
    {
        return Planning::where('user_id', $userId)
            ->whereBetween('date', [$startDate, $endDate])
            ->delete();
    }

    public static function bumpSuggestionsVersion(): void
    {
        Cache::increment('suggestions_version');
    }

    public function getHoursCalculator(): HoursCalculatorService
    {
        return $this->hoursCalculator;
    }

    // ─────────────────────────────────────────────────────────
    //  NEW: PLANNING TEMPLATES
    // ─────────────────────────────────────────────────────────

    public function createTemplateFromWeek(string $name, ?string $description, int $weekNumber, int $year): PlanningTemplate
    {
        $plannings = Planning::where('week_number', $weekNumber)
            ->where('year', $year)
            ->with(['user', 'shift', 'team'])
            ->get();

        throw_unless($plannings->isNotEmpty(), new \RuntimeException('No planning records found for this week.'));

        $template = DB::transaction(function () use ($name, $description, $weekNumber, $year, $plannings) {
            $template = PlanningTemplate::create([
                'name' => $name,
                'description' => $description,
                'week_number' => $weekNumber,
                'year' => $year,
                'created_by' => auth()->id(),
            ]);

            foreach ($plannings as $planning) {
                $date = $planning->date instanceof Carbon ? $planning->date : Carbon::parse($planning->date);
                PlanningTemplateItem::create([
                    'planning_template_id' => $template->id,
                    'user_id' => $planning->user_id,
                    'shift_id' => $planning->shift_id,
                    'team_id' => $planning->team_id,
                    'day_of_week' => strtolower($date->format('l')),
                    'notes' => $planning->notes,
                ]);
            }

            return $template;
        });

        return $template->load(['items.user', 'items.shift', 'items.team', 'creator']);
    }

    public function loadTemplateIntoWeek(PlanningTemplate $template, int $weekNumber, int $year): array
    {
        $template->loadMissing('items');

        $created = [];
        $errors = [];

        $startOfWeek = now()->setISODate($year, $weekNumber)->startOfWeek();

        DB::beginTransaction();
        try {
            foreach ($template->items as $item) {
                $dayNames = ['monday' => 0, 'tuesday' => 1, 'wednesday' => 2, 'thursday' => 3, 'friday' => 4, 'saturday' => 5, 'sunday' => 6];
                $dayOffset = $dayNames[$item->day_of_week] ?? null;
                if ($dayOffset === null) {
                    $errors[] = "Invalid day_of_week: {$item->day_of_week}";
                    continue;
                }

                $date = $startOfWeek->copy()->addDays($dayOffset);
                $dateStr = $date->toDateString();

                $user = User::find($item->user_id);
                $shift = Shift::find($item->shift_id);

                if (!$user || !$shift) {
                    $errors[] = "User or shift not found for template item #{$item->id}";
                    continue;
                }

                // Check for existing planning on this date
                $existing = Planning::where('user_id', $item->user_id)
                    ->where('date', $dateStr)
                    ->exists();

                if ($existing) {
                    $errors[] = "Employee {$user->name} already assigned on {$dateStr}";
                    continue;
                }

                $validation = $this->validateAssignment($user, $shift, $date);
                if (!$validation['valid']) {
                    $errorMessages = array_map(function ($e) {
                        return is_array($e) ? ($e['message'] ?? json_encode($e)) : $e;
                    }, $validation['errors']);
                    $errors[] = "{$user->name} on {$dateStr}: " . implode('; ', $errorMessages);
                    continue;
                }

                $planning = Planning::create([
                    'user_id' => $item->user_id,
                    'team_id' => $item->team_id,
                    'shift_id' => $item->shift_id,
                    'date' => $dateStr,
                    'week_number' => $weekNumber,
                    'year' => $year,
                    'notes' => $item->notes,
                    'created_by' => auth()->id(),
                ]);

                $created[] = $planning->load(['user', 'shift', 'team']);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        if (!empty($created)) {
            static::bumpSuggestionsVersion();
        }

        return [
            'created' => $created,
            'created_count' => count($created),
            'errors' => $errors,
        ];
    }

    public function duplicateTemplate(PlanningTemplate $template, string $newName): PlanningTemplate
    {
        $template->loadMissing('items');

        $duplicate = DB::transaction(function () use ($template, $newName) {
            $new = PlanningTemplate::create([
                'name' => $newName,
                'description' => $template->description,
                'week_number' => $template->week_number,
                'year' => $template->year,
                'created_by' => auth()->id(),
            ]);

            foreach ($template->items as $item) {
                PlanningTemplateItem::create([
                    'planning_template_id' => $new->id,
                    'user_id' => $item->user_id,
                    'shift_id' => $item->shift_id,
                    'team_id' => $item->team_id,
                    'day_of_week' => $item->day_of_week,
                    'notes' => $item->notes,
                ]);
            }

            return $new;
        });

        return $duplicate->load(['items.user', 'items.shift', 'items.team', 'creator']);
    }

    // ─────────────────────────────────────────────────────────
    //  NEW: PLANNING SANDBOX
    // ─────────────────────────────────────────────────────────

    public function generateSandboxPreview(int $weekNumber, int $year, string $sessionId): array
    {
        // Clear any existing items for this session
        PlanningSandboxItem::where('session_id', $sessionId)->delete();

        $employees = User::employees()->active()->get();

        $generated = [];
        $errors = [];

        foreach ($employees as $employee) {
            try {
                $suggestions = $this->getSuggestionsForEmployee($employee, $weekNumber, $year);

                if (empty($suggestions)) {
                    continue;
                }

                $existingPlannings = Planning::where('user_id', $employee->id)
                    ->where('week_number', $weekNumber)
                    ->where('year', $year)
                    ->pluck('date')
                    ->map(fn ($d) => $d instanceof Carbon ? $d->toDateString() : (string) $d)
                    ->toArray();
                $existingSet = array_flip($existingPlannings);

                foreach ($suggestions as $suggestion) {
                    $dateStr = $suggestion['date'];

                    if (isset($existingSet[$dateStr])) continue;

                    $sandboxItem = PlanningSandboxItem::create([
                        'session_id' => $sessionId,
                        'user_id' => $employee->id,
                        'shift_id' => $suggestion['shift_id'],
                        'date' => $dateStr,
                        'week_number' => $weekNumber,
                        'year' => $year,
                        'created_by' => auth()->id(),
                    ]);

                    $generated[] = $sandboxItem->load(['user', 'shift', 'team']);
                    $existingSet[$dateStr] = true;
                }
            } catch (\Throwable $e) {
                $errors[] = "Error for {$employee->name}: " . $e->getMessage();
            }
        }

        return [
            'session_id' => $sessionId,
            'items' => $generated,
            'generated_count' => count($generated),
            'errors' => $errors,
        ];
    }

    public function acceptSandboxPreview(string $sessionId): array
    {
        $items = PlanningSandboxItem::where('session_id', $sessionId)
            ->with(['user', 'shift', 'team'])
            ->get();

        if ($items->isEmpty()) {
            throw new \RuntimeException('No sandbox items found for this session.');
        }

        $created = [];
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($items as $item) {
                $date = Carbon::parse($item->date);

                // Check for conflicts before creating
                $shift = Shift::find($item->shift_id);
                $user = User::find($item->user_id);

                if (!$shift || !$user) {
                    $errors[] = "Shift or user not found for sandbox item #{$item->id}";
                    continue;
                }

                $existing = Planning::where('user_id', $item->user_id)
                    ->where('date', $date->toDateString())
                    ->exists();

                if ($existing) {
                    $errors[] = "{$user->name} already assigned on {$date->toDateString()} — skipped";
                    continue;
                }

                $validation = $this->validateAssignment($user, $shift, $date);
                if (!$validation['valid']) {
                    $errorMessages = array_map(function ($e) {
                        return is_array($e) ? ($e['message'] ?? json_encode($e)) : $e;
                    }, $validation['errors']);
                    $errors[] = "{$user->name} on {$date->toDateString()}: " . implode('; ', $errorMessages);
                    continue;
                }

                $planning = Planning::create([
                    'user_id' => $item->user_id,
                    'shift_id' => $item->shift_id,
                    'team_id' => $item->team_id,
                    'date' => $date->toDateString(),
                    'week_number' => $item->week_number,
                    'year' => $item->year,
                    'notes' => $item->notes,
                    'created_by' => auth()->id(),
                ]);

                $created[] = $planning->load(['user', 'shift', 'team']);
            }

            // Clean up sandbox items
            PlanningSandboxItem::where('session_id', $sessionId)->delete();

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        if (!empty($created)) {
            static::bumpSuggestionsVersion();
        }

        return [
            'created' => $created,
            'created_count' => count($created),
            'errors' => $errors,
        ];
    }

    public function cancelSandboxPreview(string $sessionId): void
    {
        PlanningSandboxItem::where('session_id', $sessionId)->delete();
    }

    // ─────────────────────────────────────────────────────────
    //  NEW: ADVANCED CONFLICT DETECTION
    // ─────────────────────────────────────────────────────────

    public function validateBatch(array $items): array
    {
        $conflicts = [];

        foreach ($items as $item) {
            $userId = $item['user_id'] ?? null;
            $shiftId = $item['shift_id'] ?? null;
            $dateStr = $item['date'] ?? null;
            $excludeId = $item['exclude_planning_id'] ?? null;

            if (!$userId || !$shiftId || !$dateStr) continue;

            $user = User::find($userId);
            $shift = Shift::find($shiftId);
            $date = Carbon::parse($dateStr);

            if (!$user || !$shift) continue;

            $itemConflicts = $this->detectAllConflicts($user, $shift, $date, $excludeId);
            if (!empty($itemConflicts)) {
                $conflicts[] = [
                    'user' => ['id' => $user->id, 'name' => $user->name],
                    'shift' => ['id' => $shift->id, 'name' => $shift->name],
                    'date' => $dateStr,
                    'conflicts' => $itemConflicts,
                ];
            }
        }

        return $conflicts;
    }

    public function detectAllConflicts(User $user, Shift $shift, Carbon $date, ?int $excludePlanningId = null): array
    {
        $conflicts = [];
        $dateStr = $date->toDateString();

        // 1. Double assignment
        $doubleAssignment = Planning::where('user_id', $user->id)
            ->where('date', $dateStr)
            ->when($excludePlanningId, fn ($q) => $q->where('id', '!=', $excludePlanningId))
            ->exists();

        if ($doubleAssignment) {
            $conflicts[] = [
                'type' => 'double_assignment',
                'severity' => 'error',
                'message' => "{$user->name} is already assigned on {$dateStr}.",
                'suggestion' => "Supprimez l'assignation existante ou choisissez un autre créneau pour {$user->name}.",
            ];
        }

        // 2. Leave conflict
        $leaveRecord = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->where('start_date', '<=', $dateStr)
            ->where('end_date', '>=', $dateStr)
            ->first();

        if ($leaveRecord) {
            $conflicts[] = [
                'type' => 'leave_conflict',
                'severity' => 'error',
                'message' => "{$user->name} is on approved leave ({$leaveRecord->type}) from {$leaveRecord->start_date} to {$leaveRecord->end_date}.",
                'suggestion' => 'Choisissez un autre employé non en congé ou attendez le retour de congés.',
            ];
        }

        // 3. Skill mismatch
        $shift->loadMissing('skills');
        if ($shift->skills->isNotEmpty()) {
            $employeeSkillIds = $user->skills->pluck('id')->toArray();
            $requiredSkillIds = $shift->skills->pluck('id')->toArray();
            $missingSkills = array_diff($requiredSkillIds, $employeeSkillIds);
            if (!empty($missingSkills)) {
                $missingNames = Shift::find($shift->id)->skills()->whereIn('skills.id', $missingSkills)->pluck('name')->toArray();
                $conflicts[] = [
                    'type' => 'skill_mismatch',
                    'severity' => 'warning',
                    'message' => "{$user->name} lacks required skills: " . implode(', ', $missingNames),
                    'suggestion' => 'Affectez un employé possédant les compétences requises ou planifiez une formation pour ' . $user->name . '.',
                ];
            }
        }

        // 4. Rest period violation
        $prevShift = Planning::where('user_id', $user->id)
            ->where('date', $date->copy()->subDay()->toDateString())
            ->whereNotNull('shift_id')
            ->with('shift')
            ->first();

        if ($prevShift && $prevShift->shift) {
            $prevEnd = Carbon::parse($prevShift->date->toDateString() . ' ' . $prevShift->shift->end_time->format('H:i:s'));
            if ($prevEnd->lessThan(Carbon::parse($prevShift->date->toDateString() . ' ' . $prevShift->shift->start_time->format('H:i:s')))) {
                $prevEnd->addDay();
            }
            $newStart = Carbon::parse($dateStr . ' ' . $shift->start_time->format('H:i:s'));
            $restMinutes = $prevEnd->diffInMinutes($newStart);
            if ($restMinutes < self::REST_MINIMUM_MINUTES) {
                $conflicts[] = [
                    'type' => 'rest_period_violation',
                    'severity' => 'error',
                    'message' => "Insufficient rest period ({$restMinutes}min). Minimum 11 hours required.",
                    'suggestion' => "Décalez le début du shift ou choisissez un shift plus tardif pour respecter les {$restMinutes}min de repos.",
                ];
            }
        }

        // 5. Weekly hours exceeded
        $weekNumber = $date->isoWeek();
        $year = $date->isoWeekYear();
        if ($this->hoursCalculator->wouldExceedLimit($user, $weekNumber, $year, $shift->duration_hours)) {
            $currentHours = $this->hoursCalculator->getWeeklyHours($user, $weekNumber, $year);
            $conflicts[] = [
                'type' => 'weekly_hours_exceeded',
                'severity' => 'error',
                'message' => 'Would exceed weekly hours limit (' . ($user->weekly_hours_limit ?? 44) . 'h).',
                'suggestion' => 'Réduisez les heures de la semaine ou augmentez la limite pour ' . $user->name . ' (actuellement ' . round($currentHours, 1) . 'h).',
            ];
        }

        // 6. Duplicate shift (same shift on same day)
        $duplicateShift = Planning::where('user_id', $user->id)
            ->where('date', $dateStr)
            ->where('shift_id', $shift->id)
            ->when($excludePlanningId, fn ($q) => $q->where('id', '!=', $excludePlanningId))
            ->exists();

        if ($duplicateShift) {
            $conflicts[] = [
                'type' => 'duplicate_shift',
                'severity' => 'warning',
                'message' => "{$user->name} already has this shift on {$dateStr}.",
                'suggestion' => 'Supprimez l\'assignation en double ou choisissez un autre shift pour ' . $user->name . '.',
            ];
        }

        // 7. Missing pause (shift >= 6h without a pause)
        $shiftDuration = $shift->getDurationMinutesAttribute() ?? 0;
        if ($shiftDuration >= 360) {
            $pauseExists = Pause::where('planning_id', $excludePlanningId)
                ->whereIn('status', ['scheduled', 'active', 'completed'])
                ->exists();

            if (!$pauseExists) {
                // Also check if any planning for this user+date has a pause
                $existingPlanning = Planning::where('user_id', $user->id)
                    ->where('date', $dateStr)
                    ->when($excludePlanningId, fn ($q) => $q->where('id', '!=', $excludePlanningId))
                    ->first();

                $hasPause = false;
                if ($existingPlanning) {
                    $hasPause = Pause::where('planning_id', $existingPlanning->id)
                        ->whereIn('status', ['scheduled', 'active', 'completed'])
                        ->exists();
                }

                if (!$hasPause) {
                    $conflicts[] = [
                        'type' => 'missing_pause',
                        'severity' => 'warning',
                        'message' => "No pause scheduled for a {$shiftDuration}min shift on {$dateStr}.",
                        'suggestion' => 'Ajoutez une pause programmée d\'au moins 20 minutes pour les shifts de plus de 6 heures.',
                    ];
                }
            }
        }

        // 8. Consecutive night shifts exceeded
        $isNight = $shift->end_time >= '22:00' || $shift->start_time <= '06:00';
        if ($isNight) {
            $nightConsecCount = 1;
            $checkDate = $date->copy()->subDay();
            for ($i = 0; $i < 6; $i++) {
                $prevNight = Planning::where('user_id', $user->id)
                    ->where('date', $checkDate->toDateString())
                    ->whereHas('shift', function ($q) {
                        $q->where('end_time', '>=', '22:00')
                          ->orWhere('start_time', '<=', '06:00');
                    })
                    ->exists();

                if ($prevNight) {
                    $nightConsecCount++;
                    $checkDate->subDay();
                } else {
                    break;
                }
            }

            if ($nightConsecCount > self::MAX_NIGHT_SHIFT_CONSEC) {
                $conflicts[] = [
                    'type' => 'consecutive_nights_exceeded',
                    'severity' => 'warning',
                    'message' => "{$nightConsecCount} consecutive night shifts (max " . self::MAX_NIGHT_SHIFT_CONSEC . ").",
                    'suggestion' => 'Attribuez un shift de jour ou accordez un jour de repos pour respecter la limite de nuits consécutives.',
                ];
            }
        }

        // 9. Employee unavailable (suspended)
        if (!$user->isActive()) {
            $conflicts[] = [
                'type' => 'employee_unavailable',
                'severity' => 'error',
                'message' => "{$user->name} is not active (status: {$user->status}).",
                'suggestion' => 'Choisissez un employé actif disponible ou réactivez le compte de ' . $user->name . '.',
            ];
        }

        return $conflicts;
    }

    // ─────────────────────────────────────────────────────────
    //  NEW: BATCH OPERATIONS
    // ─────────────────────────────────────────────────────────

    public function batchDelete(array $planningIds): int
    {
        $deleted = 0;
        foreach ($planningIds as $id) {
            $planning = Planning::find($id);
            if ($planning && !$planning->is_locked) {
                $planning->delete();
                $deleted++;
            }
        }
        PlanningAudit::create([
            'action' => 'batch_updated',
            'user_id' => auth()->id(),
            'new_values' => ['batch_delete' => $planningIds],
            'reason' => 'Batch delete',
            'created_at' => now(),
        ]);
        return $deleted;
    }

    public function batchUpdateShift(array $planningIds, int $newShiftId): int
    {
        $updated = 0;
        $shift = Shift::findOrFail($newShiftId);
        foreach ($planningIds as $id) {
            $planning = Planning::find($id);
            if ($planning && !$planning->is_locked) {
                $oldShiftId = $planning->shift_id;
                $planning->update(['shift_id' => $newShiftId]);
                PlanningAudit::create([
                    'planning_id' => $planning->id,
                    'user_id' => auth()->id(),
                    'action' => 'updated',
                    'old_values' => ['shift_id' => $oldShiftId],
                    'new_values' => ['shift_id' => $newShiftId],
                    'reason' => 'Batch shift update',
                    'created_at' => now(),
                ]);
                $updated++;
            }
        }
        return $updated;
    }

    public function batchAssignEmployee(array $planningIds, int $newUserId): int
    {
        $updated = 0;
        foreach ($planningIds as $id) {
            $planning = Planning::find($id);
            if ($planning && !$planning->is_locked) {
                $oldUserId = $planning->user_id;
                $planning->update(['user_id' => $newUserId]);
                PlanningAudit::create([
                    'planning_id' => $planning->id,
                    'user_id' => auth()->id(),
                    'action' => 'updated',
                    'old_values' => ['user_id' => $oldUserId],
                    'new_values' => ['user_id' => $newUserId],
                    'reason' => 'Batch employee reassignment',
                    'created_at' => now(),
                ]);
                $updated++;
            }
        }
        return $updated;
    }

    public function duplicateDay(string $sourceDate, string $targetDate): array
    {
        $sourcePlannings = Planning::where('date', $sourceDate)->get();
        $created = [];
        $errors = [];

        $target = Carbon::parse($targetDate);
        $targetWeek = $target->isoWeek();
        $targetYear = $target->isoWeekYear();

        foreach ($sourcePlannings as $source) {
            if ($source->is_locked) {
                $errors[] = "Cannot duplicate locked planning #{$source->id}";
                continue;
            }

            $existing = Planning::where('user_id', $source->user_id)
                ->where('date', $targetDate)
                ->exists();

            if ($existing) {
                $errors[] = "User #{$source->user_id} already assigned on {$targetDate}";
                continue;
            }

            $planning = Planning::create([
                'user_id' => $source->user_id,
                'team_id' => $source->team_id,
                'shift_id' => $source->shift_id,
                'date' => $targetDate,
                'week_number' => $targetWeek,
                'year' => $targetYear,
                'notes' => $source->notes,
                'created_by' => auth()->id(),
            ]);

            $created[] = $planning->load(['user', 'shift', 'team']);
        }

        return [
            'created' => $created,
            'created_count' => count($created),
            'errors' => $errors,
        ];
    }

    // ─────────────────────────────────────────────────────────
    //  NEW: PLANNING STATISTICS
    // ─────────────────────────────────────────────────────────

    public function getStatistics(int $weekNumber, int $year): array
    {
        $employees = User::employees()->active()->get();
        $employeeIds = $employees->pluck('id')->toArray();

        $plannings = Planning::where('week_number', $weekNumber)
            ->where('year', $year)
            ->with(['user', 'shift', 'team'])
            ->get();

        $totalPlannings = $plannings->count();
        $totalEmployees = count($employeeIds);
        $plannedEmployeeIds = $plannings->pluck('user_id')->unique()->toArray();
        $employeesPlanned = count($plannedEmployeeIds);
        $employeesMissing = $totalEmployees - $employeesPlanned;

        // Coverage percentage
        $possibleAssignments = $totalEmployees * 7; // Max possible: each employee each day
        $coveragePercentage = $possibleAssignments > 0
            ? round(($totalPlannings / $possibleAssignments) * 100, 1)
            : 0;

        // Hours calculation
        $totalHours = 0;
        $overtimeEmployees = [];
        $underUtilizedEmployees = [];
        $hoursBatch = $this->hoursCalculator->getWeeklyHoursBatch($employees, $weekNumber, $year);

        foreach ($employees as $employee) {
            $hours = $hoursBatch[$employee->id] ?? 0;
            $totalHours += $hours;
            $limit = $employee->weekly_hours_limit ?? 44;

            if ($hours > $limit) {
                $overtimeEmployees[] = [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'hours' => $hours,
                    'limit' => $limit,
                    'overtime' => round($hours - $limit, 1),
                ];
            }

            if ($hours > 0 && $hours < 32) {
                $underUtilizedEmployees[] = [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'hours' => $hours,
                    'limit' => $limit,
                ];
            }
        }

        // Shift distribution
        $shiftDistribution = $plannings->groupBy('shift_id')->map(function ($group) use ($totalPlannings) {
            $shift = $group->first()->shift;
            return [
                'shift_id' => $group->first()->shift_id,
                'shift_name' => $shift ? $shift->name : 'Unknown',
                'count' => $group->count(),
                'percentage' => $totalPlannings > 0
                    ? round(($group->count() / $totalPlannings) * 100, 1)
                    : 0,
            ];
        })->values()->toArray();

        // Coverage by day
        $coverageByDay = [];
        $startOfWeek = now()->setISODate($year, $weekNumber)->startOfWeek();
        for ($i = 0; $i < 7; $i++) {
            $date = $startOfWeek->copy()->addDays($i);
            $dateStr = $date->toDateString();
            $dayPlannings = $plannings->filter(fn ($p) => ($p->date instanceof Carbon ? $p->date->toDateString() : $p->date) === $dateStr);
            $coverageByDay[] = [
                'date' => $dateStr,
                'day_name' => $date->format('l'),
                'assigned' => $dayPlannings->count(),
                'total_employees' => $totalEmployees,
                'coverage_percentage' => $totalEmployees > 0
                    ? round(($dayPlannings->count() / $totalEmployees) * 100, 1)
                    : 0,
            ];
        }

        // Coverage by team
        $teams = \App\Models\Team::all();
        $coverageByTeam = [];
        foreach ($teams as $team) {
            $teamEmployeeIds = $team->users()->wherePivot('user_id', '!=', null)->pluck('users.id')->toArray();
            $teamPlannings = $plannings->filter(fn ($p) => $p->team_id === $team->id);
            $teamEmployeeCount = count($teamEmployeeIds);
            $coverageByTeam[] = [
                'team_id' => $team->id,
                'team_name' => $team->name,
                'team_color' => $team->color,
                'assigned' => $teamPlannings->count(),
                'total_employees' => $teamEmployeeCount,
                'coverage_percentage' => $teamEmployeeCount > 0
                    ? round(($teamPlannings->count() / ($teamEmployeeCount * 7)) * 100, 1)
                    : 0,
            ];
        }

        // Coverage by shift
        $coverageByShift = [];
        $shifts = Shift::all();
        foreach ($shifts as $shift) {
            $shiftPlannings = $plannings->filter(fn ($p) => $p->shift_id === $shift->id);
            $coverageByShift[] = [
                'shift_id' => $shift->id,
                'shift_name' => $shift->name,
                'start_time' => $shift->start_time,
                'end_time' => $shift->end_time,
                'assigned' => $shiftPlannings->count(),
                'percentage' => $totalPlannings > 0
                    ? round(($shiftPlannings->count() / $totalPlannings) * 100, 1)
                    : 0,
            ];
        }

        return [
            'week_number' => $weekNumber,
            'year' => $year,
            'total_employees' => $totalEmployees,
            'employees_planned' => $employeesPlanned,
            'employees_missing' => $employeesMissing,
            'total_assignments' => $totalPlannings,
            'coverage_percentage' => $coveragePercentage,
            'total_hours' => round($totalHours, 1),
            'locked_count' => $plannings->where('is_locked', true)->count(),
            'unlocked_count' => $plannings->where('is_locked', false)->count(),
            'overtime_forecast' => [
                'count' => count($overtimeEmployees),
                'employees' => $overtimeEmployees,
            ],
            'under_utilized' => [
                'count' => count($underUtilizedEmployees),
                'employees' => $underUtilizedEmployees,
            ],
            'shift_distribution' => $shiftDistribution,
            'coverage_by_day' => $coverageByDay,
            'coverage_by_team' => $coverageByTeam,
            'coverage_by_shift' => $coverageByShift,
        ];
    }
}
