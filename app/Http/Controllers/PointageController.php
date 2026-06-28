<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckInRequest;
use App\Models\GpsLog;
use App\Models\LeaveRequest;
use App\Models\Planning;
use App\Models\Pointage;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditService;
use App\Services\GpsValidationService;
use App\Services\NotificationService;
use App\Services\PauseService;
use App\Services\PlanningService;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PointageController extends Controller
{
    use ApiResponse;

    protected GpsValidationService $gpsService;

    protected PlanningService $planningService;

    protected NotificationService $notificationService;

    public function __construct(GpsValidationService $gpsService, PlanningService $planningService, NotificationService $notificationService)
    {
        $this->gpsService = $gpsService;
        $this->planningService = $planningService;
        $this->notificationService = $notificationService;
    }

    /**
     * Employee check-in with multi-verification.
     * Runs GPS, time, and device checks. Flags suspicious activity.
     */
    public function checkIn(CheckInRequest $request)
    {
        $user = $request->user();
        $now = Carbon::now();

        $activePointage = Pointage::where('user_id', $user->id)
            ->whereNull('check_out_at')
            ->latest()
            ->first();

        if ($activePointage) {
            return $this->errorResponse('You already have an active check-in', 422);
        }

        // Find expected planning for today
        $planning = Planning::where('user_id', $user->id)
            ->where('date', $now->toDateString())
            ->with('shift')
            ->first();

        if (! $planning) {
            return $this->errorResponse('No planning found for today', 422);
        }

        // Reject check-in if user has an approved leave covering today
        $onLeave = LeaveRequest::where('user_id', $user->id)
            ->approved()
            ->forDateRange($now->toDateString(), $now->toDateString())
            ->exists();

        if ($onLeave) {
            return $this->errorResponse('You are on approved leave today', 422);
        }

        $scheduledStart = Carbon::parse($planning->date->toDateString().' '.$planning->shift->start_time);
        $scheduledEnd = Carbon::parse($planning->date->toDateString().' '.$planning->shift->end_time);

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
            $delayMinutes = (int) $graceEnd->diffInMinutes($now);
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
            'device_fingerprint' => hash('sha256', $request->device_fingerprint),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'selfie_path' => null,
            'qr_scanned' => false,
        ];

        // Handle optional selfie upload
        if ($request->hasFile('selfie')) {
            $path = $request->file('selfie')->store('pointages/selfies', 'public');
            $verificationData['selfie_path'] = $path;
        }

        // Determine if flagged
        $isFlagged = false;
        $flagReasons = [];

        if (! $gpsValidation['valid']) {
            $isFlagged = true;
            $flagReasons[] = "GPS outside allowed zone: {$gpsValidation['distance']}m from office";
        }

        // Check for unusually early check-in (> 30 min before scheduled start)
        if ($now->lessThan($scheduledStart->copy()->subMinutes(30))) {
            $isFlagged = true;
            $flagReasons[] = 'Unusually early check-in';
        }

        // Check device trust status
        $deviceFingerprint = hash('sha256', $request->device_fingerprint);
        $device = $user->devices()->where('fingerprint', $deviceFingerprint)->first();
        if (! $device) {
            $isFlagged = true;
            $flagReasons[] = 'Unknown device detected';
            // Register new device as untrusted
            $user->devices()->create([
                'fingerprint' => $deviceFingerprint,
                'name' => strip_tags($request->header('X-Device-Name', 'Unknown Device')),
                'is_trusted' => false,
                'last_used_at' => $now,
            ]);
        } else {
            $device->update(['last_used_at' => $now]);
            if (! $device->is_trusted) {
                $isFlagged = true;
                $flagReasons[] = 'Device not trusted';
            }
        }

        // GPS accuracy threshold check
        $maxAccuracy = Setting::get('gps_max_accuracy', ['meters' => 50])['meters'] ?? 50;
        if ($request->filled('accuracy_meters') && $request->accuracy_meters > $maxAccuracy) {
            $isFlagged = true;
            $flagReasons[] = "Low GPS accuracy: {$request->accuracy_meters}m (max {$maxAccuracy}m)";
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

        // Notify admins if check-in was flagged
        if ($isFlagged) {
            $pointage->load('user');
            $this->notificationService->notifyPointageFlagged($pointage);
        }

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

        if (! $pointage) {
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

            if ($status !== 'flagged') {
                $status = 'early_leave';
            }
        }

        // Overtime
        $overtimeMinutes = 0;

        if ($now->greaterThan($scheduledEnd)) {
            $overtimeMinutes = (int) $now->diffInMinutes($scheduledEnd);
        }

        $pointage->update([
            'check_out_at' => $now,
            'worked_minutes' => $workedMinutes,
            'early_leave_minutes' => $earlyLeaveMinutes,
            'overtime_minutes' => $overtimeMinutes,
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

        if ($validated['is_valid']) {
            $newStatus = $pointage->delay_minutes > 0 ? 'late' : 'on_time';
            if ($pointage->check_out_at && $pointage->early_leave_minutes > 0) {
                $newStatus = 'early_leave';
            }
        } else {
            $newStatus = 'flagged';
        }

        $pointage->update([
            'verified_by' => auth()->id(),
            'is_flagged' => ! $validated['is_valid'],
            'status' => $newStatus,
            'flag_reason' => $validated['is_valid']
                ? ($request->notes ?? 'Verified by admin')
                : $pointage->flag_reason,
        ]);

        AuditService::log(
            $validated['is_valid'] ? 'verified_valid' : 'verified_invalid',
            Pointage::class,
            $pointage->id
        );

        // Notify employee of verification result
        $this->notificationService->notifyFlagVerified($pointage->load('user'));

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
        $absentPlannings = $planned->filter(function ($planning) use ($checkedInUserIds) {
            return ! in_array($planning->user_id, $checkedInUserIds);
        })->values();

        // Single aggregated notification instead of one per absent employee
        if ($absentPlannings->isNotEmpty()) {
            $absentNames = $absentPlannings->pluck('user.name')->implode(', ');
            $count = $absentPlannings->count();
            $this->notificationService->notifyAbsenceDetected(
                "{$count} employé(s) n'ont pas pointé",
                ['absent_count' => $count, 'names' => $absentNames]
            );
        }

        $absent = $absentPlannings->map(function ($planning) {
            $now = now();
            $scheduledStart = Carbon::parse($planning->date.' '.$planning->shift->start_time);

            $status = 'pending';
            if ($now->greaterThan($scheduledStart->copy()->addHours(2))) {
                $status = 'late_absent';
            } elseif ($now->greaterThan($scheduledStart)) {
                $status = 'delayed_absent';
            }

            $replacement = null;
            $suggestions = $this->planningService->getSuggestions(
                $planning->shift,
                Carbon::parse($planning->date),
                $planning->team_id
            );

            if (! empty($suggestions)) {
                $replacement = $suggestions[0];
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
                'suggested_replacement' => $replacement,
            ];
        });

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

    /**
     * Assign a replacement employee for an absent planning slot
     */
    public function assignReplacement(Request $request, Planning $planning)
    {
        $validated = $request->validate([
            'replacement_user_id' => ['required', 'exists:users,id'],
        ]);

        $replacement = User::findOrFail($validated['replacement_user_id']);

        $existing = Planning::where('user_id', $replacement->id)
            ->where('date', $planning->date)
            ->exists();

        if ($existing) {
            return $this->errorResponse('Cet employé a déjà un planning pour cette date', 422);
        }

        // Check replacement is not on approved leave for this date
        $onLeave = \App\Models\LeaveRequest::where('user_id', $replacement->id)
            ->where('status', 'approved')
            ->where('start_date', '<=', $planning->date)
            ->where('end_date', '>=', $planning->date)
            ->exists();

        if ($onLeave) {
            return $this->errorResponse("L'employé est en congé approuvé pour cette date", 422);
        }

        $newPlanning = Planning::create([
            'user_id' => $replacement->id,
            'team_id' => $planning->team_id,
            'shift_id' => $planning->shift_id,
            'date' => $planning->date,
            'week_number' => $planning->week_number,
            'year' => $planning->year,
            'notes' => 'Remplacement pour '.$planning->user->name,
            'created_by' => $request->user()->id,
        ]);

        $this->notificationService->notifyPlanningAssigned($replacement, $newPlanning);

        return $this->successResponse(
            $newPlanning->load(['user', 'shift', 'team']),
            'Remplacement assigné',
            201
        );
    }

    // In PointageController — add:
    public function employeePointages(Request $request, User $employee)
    {
        $pointages = \App\Models\Pointage::where('user_id', $employee->id)
            ->when($request->has('date_from'), fn ($q) => $q->whereDate('check_in_at', '>=', $request->date_from))
            ->when($request->has('date_to'), fn ($q) => $q->whereDate('check_in_at', '<=', $request->date_to))
            ->latest('check_in_at')
            ->paginate(20);

        return $this->paginatedResponse($pointages);
    }
}
