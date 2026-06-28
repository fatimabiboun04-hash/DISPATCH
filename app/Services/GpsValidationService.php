<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class GpsValidationService
{
    /**
     * Calculate distance between two coordinates using Haversine formula.
     * Returns distance in meters.
     */
    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // meters

        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($lonDelta / 2) * sin($lonDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadius * $c, 2);
    }

    /**
     * Validate if given coordinates are within allowed office radius.
     * Returns array with distance and validity.
     */
    public function validate(float $latitude, float $longitude): array
    {
        $officeLocation = Cache::remember('office_location', 300, function () {
            return Setting::get('office_location', []);
        });

        if (empty($officeLocation)) {
            return [
                'valid' => false,
                'distance' => 0,
                'reason' => 'Office location not configured',
            ];
        }

        $officeLat = $officeLocation['lat'] ?? 0;
        $officeLng = $officeLocation['lng'] ?? 0;
        $radius = $officeLocation['radius_meters'] ?? 200;

        $distance = $this->calculateDistance($officeLat, $officeLng, $latitude, $longitude);

        return [
            'valid' => $distance <= $radius,
            'distance' => $distance,
            'office_lat' => $officeLat,
            'office_lng' => $officeLng,
            'allowed_radius' => $radius,
        ];
    }
}
