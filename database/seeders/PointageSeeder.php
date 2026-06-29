<?php

namespace Database\Seeders;

use App\Models\Pointage;
use App\Models\Planning;
use App\Models\Pause;
use App\Models\User;
use App\Models\Team;
use Illuminate\Database\Seeder;

class PointageSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();

        // Generate pointages only for past weeks (not current or future)
        $existingPlanningIds = Pointage::whereNotNull('planning_id')->pluck('planning_id')->toArray();

        $plannings = Planning::where('is_locked', true)
            ->where('date', '<', now()->startOfWeek()->format('Y-m-d'))
            ->whereNotIn('id', $existingPlanningIds)
            ->get();

        $statuses = ['on_time', 'late', 'no_show', 'on_time', 'on_time', 'late', 'flagged'];

        foreach ($plannings as $planning) {
            // 20% chance of no pointage (employee didn't check in despite being planned)
            if (rand(0, 100) < 20) continue;

            $planning->load('shift');

            $dateStr = $planning->date instanceof \Carbon\Carbon
                ? $planning->date->format('Y-m-d')
                : $planning->date;

            $startTime = $planning->shift->start_time instanceof \Carbon\Carbon
                ? $planning->shift->start_time->format('H:i')
                : $planning->shift->start_time;
            $endTime = $planning->shift->end_time instanceof \Carbon\Carbon
                ? $planning->shift->end_time->format('H:i')
                : $planning->shift->end_time;

            $scheduledStart = $dateStr . ' ' . $startTime;
            $scheduledEnd = $dateStr . ' ' . $endTime;

            // Handle night shifts (end time < start time means next day)
            if ($endTime < $startTime) {
                $scheduledEnd = \Carbon\Carbon::parse($dateStr)->addDay()->format('Y-m-d') . ' ' . $endTime;
            }

            // 15% chance of no_show
            if (rand(0, 100) < 15) {
                Pointage::create([
                    'user_id' => $planning->user_id,
                    'planning_id' => $planning->id,
                    'check_in_at' => null,
                    'check_out_at' => null,
                    'scheduled_start' => $scheduledStart,
                    'scheduled_end' => $scheduledEnd,
                    'status' => 'no_show',
                    'worked_minutes' => 0,
                    'delay_minutes' => 0,
                    'early_leave_minutes' => 0,
                    'overtime_minutes' => 0,
                    'is_flagged' => true,
                    'flag_reason' => 'Absence non justifiée',
                ]);
                continue;
            }

            $status = $statuses[array_rand($statuses)];
            $checkIn = \Carbon\Carbon::parse($scheduledStart);
            $checkOut = \Carbon\Carbon::parse($scheduledEnd);
            $delayMinutes = 0;
            $earlyLeaveMinutes = 0;
            $overtimeMinutes = 0;

            switch ($status) {
                case 'late':
                    $delayMinutes = rand(5, 45);
                    $checkIn->addMinutes($delayMinutes);
                    $checkOut->subMinutes(rand(0, 15));
                    break;
                case 'flagged':
                    $delayMinutes = rand(10, 60);
                    $checkIn->addMinutes($delayMinutes);
                    $overtimeMinutes = rand(10, 60);
                    $checkOut->addMinutes($overtimeMinutes);
                    break;
                default:
                    // on_time — slight variance
                    $checkIn->addMinutes(rand(-5, 5));
                    $checkOut->subMinutes(rand(0, 10));
                    break;
            }

            $workedMinutes = (int) $checkIn->diffInMinutes($checkOut) - $planning->shift->break_minutes;
            if ($workedMinutes < 0) $workedMinutes = 0;

            $pointage = Pointage::create([
                'user_id' => $planning->user_id,
                'planning_id' => $planning->id,
                'check_in_at' => $checkIn,
                'check_out_at' => $checkOut,
                'scheduled_start' => $scheduledStart,
                'scheduled_end' => $scheduledEnd,
                'status' => $status,
                'worked_minutes' => $workedMinutes,
                'delay_minutes' => $delayMinutes,
                'early_leave_minutes' => $earlyLeaveMinutes,
                'overtime_minutes' => $overtimeMinutes,
                'is_flagged' => ($status === 'flagged'),
                'flag_reason' => ($status === 'flagged') ? 'Retard important et heures supplémentaires non approuvées' : null,
                'verified_by' => ($status === 'flagged') ? $admin->id : null,
            ]);

            // 10% of on_time pointages get verified
            if ($status === 'on_time' && rand(0, 100) < 10) {
                $pointage->update(['verified_by' => $admin->id]);
            }

            // Add pauses for on_time pointages (40% chance)
            if ($status !== 'no_show' && rand(0, 100) < 40) {
                $pauseStart = (clone $checkIn)->addHours(rand(2, 4));
                $pauseDuration = rand(15, 45);
                $pauseEnd = (clone $pauseStart)->addMinutes($pauseDuration);

                if ($pauseEnd < $checkOut) {
                    Pause::create([
                        'user_id' => $planning->user_id,
                        'team_id' => $planning->team_id,
                        'planning_id' => $planning->id,
                        'pause_start' => $pauseStart,
                        'pause_end' => $pauseEnd,
                    ]);
                }
            }

            // For some late pointages, also add flagged GPS
            if ($status === 'flagged') {
                $pointage->gpsLog()->create([
                    'latitude' => 33.5731 + (rand(-100, 100) / 10000),
                    'longitude' => -7.5898 + (rand(-100, 100) / 10000),
                    'accuracy_meters' => rand(5, 50),
                    'distance_from_office' => rand(5, 500),
                    'is_valid' => rand(0, 1),
                ]);
            }
        }
    }
}
