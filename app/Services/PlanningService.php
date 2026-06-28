<?php

namespace App\Services;

use App\Models\LeaveRequest;
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
    protected HoursCalculatorService $hoursCalculator;

    public function __construct(HoursCalculatorService $hoursCalculator)
    {
        $this->hoursCalculator = $hoursCalculator;
    }

    // ─────────────────────────────────────────────────────────
    //  EXISTING METHODS (unchanged, enhanced where noted)
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
            $conflictingShift = $conflicting->shift->name;
            $errors[] = [
                'message' => "Employee already assigned to a shift that overlaps with this time period (conflicts with {$conflictingShift}).",
                'type' => 'overlap',
                'planning_id' => $conflicting->id,
            ];
        }

        // 2. Approved leave
        $onLeave = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->where('start_date', '<=', $date->toDateString())
            ->where('end_date', '>=', $date->toDateString())
            ->exists();

        if ($onLeave) {
            $errors[] = 'Employee is on approved leave for this date.';
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
            if ($restMinutes < 660) {
                $errors[] = "Insufficient rest period ({$restMinutes}min). Minimum 11 hours required between shifts.";
            }
        }

        // 4. Weekly hours limit
        $weekNumber = $date->isoWeek();
        $year = $date->isoWeekYear();

        if ($this->hoursCalculator->wouldExceedLimit($user, $weekNumber, $year, $shift->duration_hours)) {
            $errors[] = 'Assignment would exceed weekly hours limit ('.($user->weekly_hours_limit ?? 44).'h).';
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

        return Cache::remember($cacheKey, $cacheTTL, function () use ($query, $shift, $dateStr, $weekNumber, $year, $date) {
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

            foreach ($employees as $employee) {
                $uid = $employee->id;

                if (isset($assignedSet[$uid])) continue;
                if (isset($onLeaveSet[$uid])) continue;

                $currentHours = $hoursBatch[$uid] ?? 0;
                $hoursLimit = $employee->weekly_hours_limit;
                if ($hoursLimit !== null && ($currentHours + $limit) > $hoursLimit) continue;

                // ── Weighted scoring ─────────────────────────
                $score = 50; // Base

                // 1. Rating (weight: 20)
                $latestRating = $employee->ratings->sortByDesc('created_at')->first();
                if ($latestRating) {
                    $score += $latestRating->type === 'excellent' ? 20 : -20;
                }

                // 2. Hours proximity (weight: 15)
                if ($currentHours >= 32 && $currentHours <= 38) {
                    $score += 15;
                } elseif ($currentHours < 32) {
                    $score += 10;
                } elseif ($currentHours > 38 && $currentHours < 44) {
                    $score += 5; // Near limit but still available
                }

                // 3. Skill match (weight: 25)
                if ($shift->skills->isNotEmpty()) {
                    $employeeSkillIds = $employee->skills->pluck('id')->toArray();
                    $requiredSkillIds = $shift->skills->pluck('id')->toArray();
                    $matchedCount = count(array_intersect($employeeSkillIds, $requiredSkillIds));
                    $ratio = $matchedCount / count($requiredSkillIds);
                    $score += round($ratio * 25);
                }

                // 4. Workload balance — prefer employees with fewer recent assignments (weight: 15)
                $recentCount = $recentAssignments[$uid] ?? 0;
                $avgRecent = $employees->count() > 0
                    ? array_sum($recentAssignments) / max(1, count($recentAssignments))
                    : 0;
                if ($recentCount <= $avgRecent) {
                    $score += 10;
                } elseif ($recentCount <= $avgRecent * 1.5) {
                    $score += 5;
                }

                // 5. Rest period check from previous day (weight: 10)
                $prevDayPlanning = Planning::where('user_id', $uid)
                    ->where('date', Carbon::parse($dateStr)->subDay()->toDateString())
                    ->with('shift')
                    ->first();
                if ($prevDayPlanning && $prevDayPlanning->shift) {
                    $prevEnd = Carbon::parse($prevDayPlanning->date->toDateString().' '.$prevDayPlanning->shift->end_time->format('H:i:s'));
                    if ($prevEnd->lessThan(Carbon::parse($prevDayPlanning->date->toDateString().' '.$prevDayPlanning->shift->start_time->format('H:i:s')))) {
                        $prevEnd->addDay();
                    }
                    $newStart = Carbon::parse($dateStr.' '.$shift->start_time->format('H:i:s'));
                    $restMinutes = $prevEnd->diffInMinutes($newStart);
                    if ($restMinutes < 660) {
                        $score -= 30; // Heavy penalty for rest violation
                    } elseif ($restMinutes < 780) {
                        $score -= 10; // Light penalty (tight but valid)
                    } else {
                        $score += 10; // Bonus for good rest
                    }
                } else {
                    $score += 5; // No previous day assignment = flexible
                }

                // 6. Team compatibility (weight: 5)
                if ($teamId && $employee->teams()->where('teams.id', $teamId)->exists()) {
                    $score += 5;
                }

                $percentage = max(0, min(100, $score));

                $suggestions[] = [
                    'employee' => [
                        'id' => $employee->id,
                        'name' => $employee->name,
                        'initials' => $employee->initials,
                        'avatar_url' => $employee->avatar_url,
                    ],
                    'current_hours' => $currentHours,
                    'weekly_limit' => $employee->weekly_hours_limit,
                    'rating' => $latestRating ? $latestRating->type : null,
                    'match_percentage' => round($percentage),
                    'score_breakdown' => [
                        'rating' => $latestRating ? ($latestRating->type === 'excellent' ? 20 : -20) : 0,
                        'hours_proximity' => $score >= 50 ? ($score - 50) : 0,
                        'skill_match' => $shift->skills->isNotEmpty() ? round(($matchedCount ?? 0) / max(1, count($requiredSkillIds ?? [])) * 25) : 0,
                        'workload_balance' => $recentCount <= $avgRecent ? 10 : 5,
                        'rest_period' => 0,
                        'team_compatibility' => $teamId ? 5 : 0,
                    ],
                ];
            }

            usort($suggestions, fn ($a, $b) => $b['match_percentage'] <=> $a['match_percentage']);

            return array_slice($suggestions, 0, 5);
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

                if ($latestRating) {
                    $score += $latestRating->type === 'excellent' ? 20 : -20;
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
            ];
        }

        // 2. Leave conflict
        $onLeave = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->where('start_date', '<=', $dateStr)
            ->where('end_date', '>=', $dateStr)
            ->exists();

        if ($onLeave) {
            $conflicts[] = [
                'type' => 'leave_conflict',
                'severity' => 'error',
                'message' => "{$user->name} is on approved leave on {$dateStr}.",
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
            if ($restMinutes < 660) {
                $conflicts[] = [
                    'type' => 'rest_period_violation',
                    'severity' => 'error',
                    'message' => "Insufficient rest period ({$restMinutes}min). Minimum 11 hours required.",
                ];
            }
        }

        // 5. Weekly hours exceeded
        $weekNumber = $date->isoWeek();
        $year = $date->isoWeekYear();
        if ($this->hoursCalculator->wouldExceedLimit($user, $weekNumber, $year, $shift->duration_hours)) {
            $conflicts[] = [
                'type' => 'weekly_hours_exceeded',
                'severity' => 'error',
                'message' => 'Would exceed weekly hours limit (' . ($user->weekly_hours_limit ?? 44) . 'h).',
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
