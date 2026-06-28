<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\AuditService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    use ApiResponse;

    /**
     * List all settings grouped by category.
     */
    public function index()
    {
        $settings = Setting::all()->groupBy('group');

        return $this->successResponse($settings);
    }

    /**
     * Allowed setting keys — prevents arbitrary key creation.
     */
    protected array $allowedKeys = [
        'friday_lock_hour',
        'check_in_grace_minutes',
        'gps_max_accuracy',
        'office_location',
        'max_early_check_in_minutes',
        'weekly_hours_limit_default',
        'absence_alert_threshold',
    ];

    /**
     * Update settings (batch).
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'settings' => 'required|array',
            'settings.*.key' => 'required|string',
            'settings.*.value' => 'required',
            'settings.*.group' => 'nullable|string',
        ]);

        foreach ($validated['settings'] as $settingData) {
            // Only allow predefined keys
            if (! in_array($settingData['key'], $this->allowedKeys, true)) {
                continue;
            }

            Setting::updateOrCreate(
                ['key' => $settingData['key']],
                [
                    'value' => is_array($settingData['value'])
                        ? $settingData['value']
                        : ['value' => $settingData['value']],
                    'group' => $settingData['group'] ?? 'general',
                ]
            );
        }

        AuditService::log('updated', Setting::class, 0);

        return $this->successResponse(Setting::all()->groupBy('group'), 'Settings updated');
    }
}
