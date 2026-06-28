<?php

namespace Database\Seeders;

use App\Models\Shift;
use Illuminate\Database\Seeder;

class ShiftSeeder extends Seeder
{
    public function run(): void
    {
        $shifts = [
            [
                'name' => 'Day Shift',
                'type' => 'day',
                'start_time' => '08:00:00',
                'end_time' => '17:00:00',
                'break_minutes' => 60,
                'color' => '#FCD34D', // Yellow
            ],
            [
                'name' => 'Night Shift',
                'type' => 'night',
                'start_time' => '20:00:00',
                'end_time' => '05:00:00',
                'break_minutes' => 60,
                'color' => '#1F2937', // Dark
            ],
            [
                'name' => 'Congé',
                'type' => 'conge',
                'start_time' => '00:00:00',
                'end_time' => '23:59:59',
                'break_minutes' => 0,
                'color' => '#10B981', // Green
            ],
            [
                'name' => 'Absence',
                'type' => 'absence',
                'start_time' => '00:00:00',
                'end_time' => '23:59:59',
                'break_minutes' => 0,
                'color' => '#EF4444', // Red
            ],
            [
                'name' => 'Emergency Leave',
                'type' => 'emergency',
                'start_time' => '00:00:00',
                'end_time' => '23:59:59',
                'break_minutes' => 0,
                'color' => '#3B82F6', // Blue
            ],
        ];

        foreach ($shifts as $shift) {
            Shift::create($shift);
        }
    }
}
