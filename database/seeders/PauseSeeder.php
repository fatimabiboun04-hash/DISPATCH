<?php

namespace Database\Seeders;

use App\Models\Pause;
use App\Models\Planning;
use App\Models\User;
use Illuminate\Database\Seeder;

class PauseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();

        // Only past locked plannings with pointages (actual worked shifts)
        $plannings = Planning::where('is_locked', true)
            ->where('date', '<', now()->startOfWeek()->format('Y-m-d'))
            ->whereHas('pointages', function ($q) {
                $q->whereNotNull('check_in_at')->where('status', '!=', 'no_show');
            })
            ->get();

        $types = ['break', 'lunch', 'medical', 'technical'];
        $statuses = ['completed'];

        foreach ($plannings as $planning) {
            // 50% chance of having at least one pause per shift
            if (rand(0, 100) > 50) continue;

            $planning->load('shift');
            $pointage = $planning->pointages()->whereNotNull('check_in_at')->first();
            if (!$pointage) continue;

            $checkIn = $pointage->check_in_at instanceof \Carbon\Carbon
                ? $pointage->check_in_at
                : \Carbon\Carbon::parse($pointage->check_in_at);
            $checkOut = $pointage->check_out_at instanceof \Carbon\Carbon
                ? $pointage->check_out_at
                : \Carbon\Carbon::parse($pointage->check_out_at);

            // Main lunch break (60-90 min, 2-4h after shift start)
            $lunchStart = (clone $checkIn)->addMinutes(rand(120, 240));
            $lunchDuration = rand(45, 90);
            $lunchEnd = (clone $lunchStart)->addMinutes($lunchDuration);

            if ($lunchEnd < $checkOut) {
                Pause::create([
                    'user_id' => $planning->user_id,
                    'team_id' => $planning->team_id,
                    'planning_id' => $planning->id,
                    'type' => 'lunch',
                    'reason' => null,
                    'status' => 'completed',
                    'pause_start' => $lunchStart,
                    'pause_end' => $lunchEnd,
                    'duration_minutes' => $lunchDuration,
                ]);
            }

            // 30% chance of a second short break
            if (rand(0, 100) < 30) {
                $breakStart = (clone $checkOut)->subMinutes(rand(60, 120));
                $breakDuration = rand(10, 20);
                $breakEnd = (clone $breakStart)->addMinutes($breakDuration);

                if ($breakEnd < $checkOut && $breakStart > $lunchEnd) {
                    Pause::create([
                        'user_id' => $planning->user_id,
                        'team_id' => $planning->team_id,
                        'planning_id' => $planning->id,
                        'type' => $types[array_rand($types)],
                        'reason' => 'Pause courte',
                        'status' => 'completed',
                        'pause_start' => $breakStart,
                        'pause_end' => $breakEnd,
                        'duration_minutes' => $breakDuration,
                    ]);
                }
            }
        }
    }
}
