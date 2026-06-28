<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // GPS office location (Casablanca example — change to your location)
            [
                'key' => 'office_location',
                'value' => json_encode([
                    'lat' => 33.5731,
                    'lng' => -7.5898,
                    'radius_meters' => 200,
                    'address' => 'Casablanca, Morocco',
                ]),
                'group' => 'gps',
            ],
            // Weekly hours limit
            [
                'key' => 'weekly_hours_limit',
                'value' => json_encode(['value' => 44]),
                'group' => 'planning',
            ],
            // Friday lock time
            [
                'key' => 'friday_lock_hour',
                'value' => json_encode(['time' => '17:00']),
                'group' => 'planning',
            ],
            // Pointage options
            [
                'key' => 'pointage_selfie_required',
                'value' => json_encode(['enabled' => false]),
                'group' => 'pointage',
            ],
            [
                'key' => 'pointage_qr_required',
                'value' => json_encode(['enabled' => false]),
                'group' => 'pointage',
            ],
            // Grace period for check-in (minutes)
            [
                'key' => 'check_in_grace_minutes',
                'value' => json_encode(['minutes' => 15]),
                'group' => 'pointage',
            ],
        ];

        foreach ($settings as $setting) {
            Setting::create($setting);
        }
    }
}
