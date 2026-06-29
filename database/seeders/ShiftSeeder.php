<?php

namespace Database\Seeders;

use App\Models\Shift;
use Illuminate\Database\Seeder;

class ShiftSeeder extends Seeder
{
    public function run(): void
    {
        $shifts = [
            ['name' => 'Matin',        'type' => 'day',        'start_time' => '06:00', 'end_time' => '14:00', 'break_minutes' => 30, 'color' => '#FBBF24', 'is_active' => true],
            ['name' => 'Après-midi',   'type' => 'day',        'start_time' => '14:00', 'end_time' => '22:00', 'break_minutes' => 30, 'color' => '#F97316', 'is_active' => true],
            ['name' => 'Nuit',         'type' => 'night',      'start_time' => '22:00', 'end_time' => '06:00', 'break_minutes' => 45, 'color' => '#1E293B', 'is_active' => true],
            ['name' => 'Journée',      'type' => 'day',        'start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 60, 'color' => '#FCD34D', 'is_active' => true],
            ['name' => 'Weekend Jour', 'type' => 'day',        'start_time' => '08:00', 'end_time' => '18:00', 'break_minutes' => 45, 'color' => '#34D399', 'is_active' => true],
            ['name' => 'Weekend Nuit', 'type' => 'night',      'start_time' => '18:00', 'end_time' => '06:00', 'break_minutes' => 60, 'color' => '#6B7280', 'is_active' => true],
            ['name' => 'Urgence',      'type' => 'emergency',  'start_time' => '00:00', 'end_time' => '23:59', 'break_minutes' => 0,  'color' => '#EF4444', 'is_active' => true],
            ['name' => 'Congé',        'type' => 'conge',      'start_time' => '00:00', 'end_time' => '23:59', 'break_minutes' => 0,  'color' => '#10B981', 'is_active' => true],
            ['name' => 'Absence',      'type' => 'absence',    'start_time' => '00:00', 'end_time' => '23:59', 'break_minutes' => 0,  'color' => '#DC2626', 'is_active' => true],
        ];

        foreach ($shifts as $data) {
            Shift::firstOrCreate(
                ['name' => $data['name'], 'type' => $data['type']],
                $data
            );
        }
    }
}
