<?php

namespace Database\Seeders;

use App\Models\Planning;
use App\Models\Shift;
use App\Models\User;
use App\Models\Team;
use Illuminate\Database\Seeder;

class PlanningSeeder extends Seeder
{
    private array $dayNames = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();
        $employees = User::where('role', 'employee')->get();
        $shifts = Shift::all()->keyBy('name');
        $teams = Team::all()->keyBy('name');

        $now = now();
        $currentWeekNumber = (int) $now->weekOfYear;
        $currentYear = (int) $now->year;

        // Weeks to generate: week-4, week-3, week-2, week-1, current, week+1, week+2
        $weekOffsets = [-4, -3, -2, -1, 0, 1, 2];

        $dayShifts = ['Matin', 'Après-midi', 'Journée'];
        $nightShifts = ['Nuit', 'Weekend Nuit'];
        $weekendDayShift = 'Weekend Jour';

        // Track which employees have planning on which dates to detect conflicts
        $assignedDates = [];

        foreach ($weekOffsets as $offset) {
            $weekNumber = $currentWeekNumber + $offset;
            $year = $currentYear;

            // Handle year boundary
            if ($weekNumber < 1) {
                $weekNumber = 52 + $weekNumber;
                $year--;
            } elseif ($weekNumber > 52) {
                $weekNumber = $weekNumber - 52;
                $year++;
            }

            $startOfWeek = $now->copy()->setISODate($year, $weekNumber)->startOfWeek();
            $isPast = $offset < 0;
            $isCurrent = $offset === 0;
            $isFuture = $offset > 0;
            $isLocked = ($isPast || ($isCurrent && $now->dayOfWeek >= 5)); // Locked if past, or current and after Friday

            foreach ($employees as $employee) {
                // Skip suspended employees for future weeks
                if ($employee->status !== 'active' && ($isCurrent || $isFuture)) {
                    continue;
                }

                $employeeTeams = $employee->teams()->pluck('teams.id')->toArray();
                if (empty($employeeTeams)) continue;

                // Employee's main team (first one)
                $teamId = $employeeTeams[0];

                // Each employee gets 4-6 days of planning per week
                $daysToAssign = [];
                $numDays = rand(4, 6);
                $availableDays = range(0, 6);
                shuffle($availableDays);
                $daysToAssign = array_slice($availableDays, 0, $numDays);
                sort($daysToAssign);

                foreach ($daysToAssign as $dayIndex) {
                    $date = $startOfWeek->copy()->addDays($dayIndex);
                    $dateStr = $date->format('Y-m-d');

                    // Determine shift based on day
                    $isWeekend = ($dayIndex >= 5);
                    if ($isWeekend) {
                        $shiftName = rand(0, 1) ? $weekendDayShift : $nightShifts[array_rand($nightShifts)];
                        $shift = $shifts[$shiftName];
                    } else {
                        $randChoice = rand(0, 2);
                        if ($randChoice === 0) {
                            $shiftName = $dayShifts[array_rand($dayShifts)];
                        } elseif (rand(0, 3) === 0) {
                            $shiftName = $nightShifts[array_rand($nightShifts)];
                        } else {
                            $shiftName = $dayShifts[array_rand($dayShifts)];
                        }
                        $shift = $shifts[$shiftName] ?? $shifts['Journée'];
                    }

                    $planning = Planning::create([
                        'user_id' => $employee->id,
                        'team_id' => $teamId,
                        'shift_id' => $shift->id,
                        'date' => $dateStr,
                        'week_number' => $weekNumber,
                        'year' => $year,
                        'notes' => $isLocked ? 'Planning verrouillé' : null,
                        'created_by' => $admin->id,
                        'is_locked' => $isLocked,
                    ]);

                    $assignedDates[$employee->id][] = [
                        'date' => $dateStr,
                        'planning_id' => $planning->id,
                    ];
                }
            }
        }

        // Create 2 planning conflicts: assign an employee to 2 shifts on the same day in a past week
        $conflictEmployee = $employees->where('status', 'active')->random();
        $conflictDate = $now->copy()->subWeeks(2)->startOfWeek()->addDays(3); // Thursday, 2 weeks ago
        $existingShift = $shifts['Matin'] ?? $shifts->first();
        $conflictShift = $shifts['Après-midi'] ?? $shifts->skip(1)->first();
        $conflictTeamId = $conflictEmployee->teams()->pluck('teams.id')->first();

        // Check if a planning already exists for this employee on this date
        $existing = Planning::where('user_id', $conflictEmployee->id)
            ->where('date', $conflictDate->format('Y-m-d'))
            ->first();

        if (!$existing) {
            Planning::create([
                'user_id' => $conflictEmployee->id,
                'team_id' => $conflictTeamId,
                'shift_id' => $existingShift->id,
                'date' => $conflictDate->format('Y-m-d'),
                'week_number' => (int) $conflictDate->weekOfYear,
                'year' => (int) $conflictDate->year,
                'notes' => 'Conflit intentionnel — chevauchement de planning',
                'created_by' => $admin->id,
                'is_locked' => true,
            ]);
        }

        // Second conflict: same employee on a different day
        $conflictDate2 = $now->copy()->subWeeks(3)->startOfWeek()->addDays(2);
        $existing2 = Planning::where('user_id', $conflictEmployee->id)
            ->where('date', $conflictDate2->format('Y-m-d'))
            ->first();

        if (!$existing2) {
            Planning::create([
                'user_id' => $conflictEmployee->id,
                'team_id' => $conflictTeamId,
                'shift_id' => $conflictShift->id,
                'date' => $conflictDate2->format('Y-m-d'),
                'week_number' => (int) $conflictDate2->weekOfYear,
                'year' => (int) $conflictDate2->year,
                'notes' => 'Conflit intentionnel — double affectation',
                'created_by' => $admin->id,
                'is_locked' => true,
            ]);
        }
    }
}
