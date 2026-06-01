<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckInRequest;
use App\Models\GpsLog;
use App\Models\Planning;
use App\Models\Pointage;
use App\Models\Setting;
use App\Services\AuditService;
use App\Models\User;
use App\Services\GpsValidationService;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Services\PauseService;
use App\Services\PlanningService;
use Illuminate\Support\Facades\Mail;

class PointageController extends Controller
{
    use ApiResponse;

    protected GpsValidationService $gpsService;
    protected PlanningService $planningService;

    public function __construct(GpsValidationService $gpsService, PlanningService $planningService) 
    {
        $this->gpsService = $gpsService;
        $this->planningService = $planningService;
    }

    /**
     * Employee check-in with multi-verification.
     * Runs GPS, time, and device checks. Flags suspicious activity.
     */
    public function checkIn(CheckInRequest $request)
    {
        $user = $request->user();
        $now = Carbon::now();

        // Find expected planning for today
        $planning = Planning::where('user_id', $user->id)
            ->where('date', $now->toDateString())
            ->with('shift')
            ->first();

        if (!$planning) {
            return $this->errorResponse('No planning found for today', 422);
        }

        $scheduledStart = Carbon::parse($planning->date->toDateString() . ' ' . $planning->shift->start_time);
        $scheduledEnd = Carbon::parse($planning->date->toDateString() . ' ' . $planning->shift->end_time);

        // Handle night shifts crossing midnight
        if ($scheduledEnd->lessThan($scheduledStart)) {
            $scheduledEnd->addDay();
        }

        // Calculate delay against grace period
        $graceMinutes = Setting::get('check_in_grace_minutes', ['minutes' => 15])['minutes'] ?? 15;
        $graceEnd = $scheduledStart->copy()->addMinutes($graceMinutes);

        $status = 'on_time';
        $delayMinutes = 0;

        if ($now->greaterThan($graceEnd)) {
            $status = 'late';
            $delayMinutes = (int) $now->diffInMinutes($scheduledStart);
        }

        // GPS validation
        $gpsValidation = $this->gpsService->validate(
            $request->latitude,
            $request->longitude
        );

        // Build verification data payload
        $verificationData = [
            'gps' => [
                'lat' => $request->latitude,
                'lng' => $request->longitude,
                'distance_meters' => $gpsValidation['distance'],
                'valid' => $gpsValidation['valid'],
            ],
            'device_fingerprint' => $request->device_fingerprint,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'selfie_path' => null,
            'qr_scanned' => false,
        ];

        // Handle optional selfie upload
        if ($request->hasFile('selfie')) {
            $path = $request->file('selfie')->store('pointages/selfies', 'local');
            $verificationData['selfie_path'] = $path;
        }

        // Determine if flagged
        $isFlagged = false;
        $flagReasons = [];

        if (!$gpsValidation['valid']) {
            $isFlagged = true;
            $flagReasons[] = "GPS outside allowed zone: {$gpsValidation['distance']}m from office";
        }

        // Check for unusually early check-in (> 30 min before scheduled start)
        if ($now->lessThan($scheduledStart->copy()->subMinutes(30))) {
            $isFlagged = true;
            $flagReasons[] = 'Unusually early check-in';
        }

        // Check device trust status
        $device = $user->devices()->where('fingerprint', $request->device_fingerprint)->first();
        if (!$device) {
            $isFlagged = true;
            $flagReasons[] = 'Unknown device detected';
            // Register new device as untrusted
            $user->devices()->create([
                'fingerprint' => $request->device_fingerprint,
                'name' => $request->header('X-Device-Name', 'Unknown Device'),
                'is_trusted' => false,
                'last_used_at' => $now,
            ]);
        } else {
            $device->update(['last_used_at' => $now]);
            if (!$device->is_trusted) {
                $isFlagged = true;
                $flagReasons[] = 'Device not trusted';
            }
        }

        // Create pointage record
        $pointage = Pointage::create([
            'user_id' => $user->id,
            'planning_id' => $planning->id,
            'check_in_at' => $now,
            'scheduled_start' => $scheduledStart,
            'scheduled_end' => $scheduledEnd,
            'status' => $isFlagged ? 'flagged' : $status,
            'delay_minutes' => $delayMinutes,
            'verification_data' => $verificationData,
            'is_flagged' => $isFlagged,
            'flag_reason' => $isFlagged ? implode(' | ', $flagReasons) : null,
        ]);

        // Create GPS log record
        GpsLog::create([
            'pointage_id' => $pointage->id,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'accuracy_meters' => $request->accuracy_meters ?? null,
            'distance_from_office' => $gpsValidation['distance'],
            'is_valid' => $gpsValidation['valid'],
        ]);

        AuditService::log('checked_in', Pointage::class, $pointage->id);

        return $this->successResponse([
            'pointage' => $pointage->load('gpsLog'),
            'status' => $pointage->status,
            'delay_minutes' => $delayMinutes,
            'is_flagged' => $isFlagged,
            'message' => $isFlagged 
                ? 'Check-in recorded but flagged for review' 
                : 'Check-in successful',
        ], 'Check-in processed');
    }

    /**
     * Employee check-out.
     * Calculates worked hours, early leave, and updates status.
     */
      
    public function checkOut(Request $request)
{
    $user = $request->user();
    $now = Carbon::now();

    $pointage = Pointage::where('user_id', $user->id)
        ->whereNull('check_out_at')
        ->latest()
        ->first();

    if (!$pointage) {
        return $this->errorResponse('No active check-in found', 422);
    }

    $scheduledEnd = Carbon::parse($pointage->scheduled_end);
    $checkInAt = Carbon::parse($pointage->check_in_at);

    // Raw worked minutes
    $rawMinutes = (int) $checkInAt->diffInMinutes($now);
    
    // Pause deduction
    $pauseService = app(PauseService::class);
    $pauseMinutes = $pointage->planning_id
        ? $pauseService->getTotalPauseMinutes($user->id, $pointage->planning_id)
        : 0;

    $workedMinutes = max(0, $rawMinutes - $pauseMinutes);

    // Early leave
    $earlyLeaveMinutes = 0;
    $status = $pointage->status;

    if ($now->lessThan($scheduledEnd)) {
        $earlyLeaveMinutes = (int) $scheduledEnd->diffInMinutes($now);

        if ($status === 'on_time') {
            $status = 'early_leave';
        }
    }

    // Overtime (ONLY ADDITION)
    $overtimeMinutes = 0;

    if ($now->greaterThan($scheduledEnd)) {
        $overtimeMinutes = (int) $now->diffInMinutes($scheduledEnd);
    }

    $pointage->update([
        'check_out_at' => $now,
        'worked_minutes' => $workedMinutes,
        'early_leave_minutes' => $earlyLeaveMinutes,
        'status' => $status,
    ]);

    AuditService::log('checked_out', Pointage::class, $pointage->id);

    return $this->successResponse([
        'pointage' => $pointage,
        'worked_hours' => round($workedMinutes / 60, 2),
        'pause_deducted' => round($pauseMinutes / 60, 2),
        'early_leave_minutes' => $earlyLeaveMinutes,
        'overtime_minutes' => $overtimeMinutes,
        'status' => $status,
    ], 'Check-out successful');
}
    /**
     * Admin verifies a flagged pointage.
     */
    public function verifyFlag(Request $request, Pointage $pointage)
    {
        $validated = $request->validate([
            'is_valid' => 'required|boolean',
            'notes' => 'nullable|string',
        ]);

        $pointage->update([
            'verified_by' => auth()->id(),
            'is_flagged' => !$validated['is_valid'],
            'flag_reason' => $validated['is_valid'] 
                ? ($request->notes ?? 'Verified by admin') 
                : $pointage->flag_reason,
        ]);

        AuditService::log(
            $validated['is_valid'] ? 'verified_valid' : 'verified_invalid',
            Pointage::class,
            $pointage->id
        );

        return $this->successResponse($pointage->load(['user', 'verifier']), 'Pointage reviewed');
    }

    /**
     * Get current user's pointage history.
     */
    public function myPointages(Request $request)
    {
        $pointages = Pointage::where('user_id', $request->user()->id)
            ->with('gpsLog')
            ->latest()
            ->paginate(15);

        return $this->paginatedResponse($pointages);
    }
        /**
     * List flagged pointages awaiting admin review.
     */
    public function flagged(Request $request)
    {
        $pointages = Pointage::where('is_flagged', true)
            ->whereNull('verified_by')
            ->with(['user', 'planning.shift', 'gpsLog'])
            ->latest()
            ->paginate(20);

        return $this->paginatedResponse($pointages);
    }
        /**
     * Detect employees who haven't checked in today (Absence Alert)
     * 
     * Implements the prompt requirement:
     * "If no check-in: Admin alert: 'Fatima has not checked in. Replace?'"
     */
    public function absentToday()
    {
        $today = now()->toDateString();
        
        // Get all employees who have planning today
        $planned = Planning::where('date', $today)
            ->with(['user', 'shift', 'team'])
            ->get();
        
        if ($planned->isEmpty()) {
            return $this->successResponse([], 'No planned assignments for today');
        }
        
        // Get employees who already checked in today
        $checkedInUserIds = Pointage::whereDate('check_in_at', $today)
            ->pluck('user_id')
            ->toArray();
        
        // Filter absent employees
        $absent = $planned->filter(function ($planning) use ($checkedInUserIds) {
            return !in_array($planning->user_id, $checkedInUserIds);
        })->values()->map(function ($planning) {
            // Get current hour status
            $now = now();
            $scheduledStart = Carbon::parse($planning->date . ' ' . $planning->shift->start_time);
            
            $status = 'pending';
            if ($now->greaterThan($scheduledStart->copy()->addHours(2))) {
                $status = 'late_absent'; // More than 2 hours late, likely absent
            } elseif ($now->greaterThan($scheduledStart)) {
                $status = 'delayed_absent';
            }
            
            // Find suggested replacement
            $suggestedReplacement = null;
            $suggestions = $this->planningService->getSuggestions(
                $planning->shift,
                Carbon::parse($planning->date),
                $planning->team_id
            );
            
            if (!empty($suggestions)) {
                $suggestedReplacement = $suggestions[0];
            }
            
            return [
                'planning_id' => $planning->id,
                'user_id' => $planning->user->id,
                'user_name' => $planning->user->name,
                'user_initials' => $planning->user->initials,
                'shift' => $planning->shift->name,
                'shift_start' => $planning->shift->start_time,
                'team' => $planning->team?->name,
                'scheduled_start' => $scheduledStart->toDateTimeString(),
                'status' => $status,
                'suggested_replacement' => $suggestedReplacement,
            ];
        });
        
        // Create alert notifications for admin (in-app)
 $admins = \App\Models\User::admins()->active()->get();
 
foreach ($absent as $absentee) {
    foreach ($admins as $admin) {
        Mail::to($admin->email)
            ->queue(new \App\Mail\AbsenceDetectedMail($absentee));
    }
}
        
        return $this->successResponse([
            'absent_count' => $absent->count(),
            'total_planned' => $planned->count(),
            'absent_employees' => $absent,
        ]);
    }
    
    /**
     * Get suggested replacement for an absent employee
     */
    public function replacementSuggestion(Request $request, Planning $planning)
    {
        $suggestions = $this->planningService->getSuggestions(
            $planning->shift,
            Carbon::parse($planning->date),
            $planning->team_id
        );
        
        return $this->successResponse([
            'planning_id' => $planning->id,
            'original_employee' => $planning->user->name,
            'suggestions' => $suggestions,
        ]);
    }
    // In PointageController — add:
public function employeePointages(Request $request, User $employee)
{
    $pointages = \App\Models\Pointage::where('user_id', $employee->id)
        ->when($request->has('date_from'), fn($q) => $q->whereDate('check_in_at', '>=', $request->date_from))
        ->when($request->has('date_to'),   fn($q) => $q->whereDate('check_in_at', '<=', $request->date_to))
        ->latest('check_in_at')
        ->paginate(20);

    return $this->paginatedResponse($pointages);
}
}
