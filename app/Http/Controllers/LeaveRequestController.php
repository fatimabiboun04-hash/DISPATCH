<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLeaveRequest;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\PlanningService;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;

class LeaveRequestController extends Controller
{
    use ApiResponse;

    protected PlanningService $planningService;

    protected NotificationService $notificationService;

    public function __construct(PlanningService $planningService, NotificationService $notificationService)
    {
        $this->planningService = $planningService;
        $this->notificationService = $notificationService;
    }

    /**
     * Admin: list all leave requests with filters.
     */
    public function index(Request $request)
    {
        $query = LeaveRequest::with('user');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        $leaves = $query->latest()->paginate(20);

        return $this->paginatedResponse($leaves);
    }

    /**
     * Employee: submit leave request.
     */
    public function store(StoreLeaveRequest $request)
    {
        $user = $request->user();

        // Check for overlapping approved leave
        $overlap = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->where(function ($query) use ($request) {
                $query->whereBetween('start_date', [$request->start_date, $request->end_date])
                    ->orWhereBetween('end_date', [$request->start_date, $request->end_date])
                    ->orWhere(function ($q) use ($request) {
                        $q->where('start_date', '<=', $request->start_date)
                            ->where('end_date', '>=', $request->end_date);
                    });
            })
            ->exists();

        if ($overlap) {
            return $this->errorResponse('You already have approved leave in this date range', 422);
        }

        $leave = LeaveRequest::create([
            'user_id' => $user->id,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'reason' => $request->reason,
            'type' => $request->type,
            'status' => 'pending',
        ]);
        $this->notificationService->notifyLeaveSubmitted($leave->load('user'));
        \App\Jobs\SendLeaveRequestEmailJob::dispatch($leave, 'admin', 'submitted');

        AuditService::log('created', LeaveRequest::class, $leave->id);

        return $this->successResponse($leave->load('user'), 'Leave request submitted', 201);
    }

    /**
     * Admin: approve leave request.
     *
     * If employee has planning entries during the leave period:
     *   - First call (no force) → returns conflict warning
     *   - Second call with force=true → removes planning and approves
     *
     * After approval, suggests replacement employees.
     */
    public function approve(Request $request, LeaveRequest $leaveRequest)
    {
        if ($leaveRequest->status !== 'pending') {
            return $this->errorResponse('Leave request already processed', 422);
        }

        $validated = $request->validate([
            'force' => 'sometimes|boolean',
        ]);
        $force = $validated['force'] ?? false;

        // Check for planning conflicts
        $conflicts = $this->planningService->getConflictsForLeave(
            $leaveRequest->user_id,
            $leaveRequest->start_date->toDateString(),
            $leaveRequest->end_date->toDateString()
        );

        $hasConflicts = ! empty($conflicts);

        // If conflicts exist and not forced, return warning
        if ($hasConflicts && ! $force) {
            return $this->errorResponse(
                'Conflict detected: employee has assigned planning entries during this leave period',
                422,
                [
                    'has_conflicts' => true,
                    'requires_force' => true,
                    'conflicts' => $conflicts,
                    'conflict_count' => count($conflicts),
                ]
            );
        }

        // Remove planning entries (with or without force)
        $planningRemoved = 0;
        if ($hasConflicts) {
            $planningRemoved = $this->planningService->removeEmployeeFromDateRange(
                $leaveRequest->user_id,
                $leaveRequest->start_date->toDateString(),
                $leaveRequest->end_date->toDateString()
            );
        }

        // Approve the leave
        $leaveRequest->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        // Generate replacement suggestions for the first removed planning date
        $replacementSuggestions = [];
        if ($planningRemoved > 0 && ! empty($conflicts)) {
            $firstConflict = $conflicts[0];
            $shift = Shift::where('name', $firstConflict['shift_name'])->first();
            if ($shift) {
                $replacementSuggestions = $this->planningService->getSuggestions(
                    $shift,
                    Carbon::parse($firstConflict['date'])
                );
            }
        }

        AuditService::log('approved', LeaveRequest::class, $leaveRequest->id);
        $this->notificationService->notifyLeaveApproved($leaveRequest);
        \App\Jobs\SendLeaveRequestEmailJob::dispatch($leaveRequest, 'employee', 'approved');
        PlanningService::bumpSuggestionsVersion();

        return $this->successResponse([
            'leave_request' => $leaveRequest->load(['user', 'approver']),
            'planning_removed' => $planningRemoved,
            'planning_conflicts' => $conflicts,
            'replacement_suggestions' => $replacementSuggestions,
        ], $planningRemoved > 0
            ? "Leave approved. {$planningRemoved} planning entr".($planningRemoved > 1 ? 'ies' : 'y').' removed.'
            : 'Leave approved');
    }

    /**
     * Admin: reject leave request.
     */
    public function reject(Request $request, LeaveRequest $leaveRequest)
    {
        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        if ($leaveRequest->status !== 'pending') {
            return $this->errorResponse('Leave request already processed', 422);
        }

        $leaveRequest->update([
            'status' => 'rejected',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'rejection_reason' => $validated['rejection_reason'],
        ]);
        $this->notificationService->notifyLeaveRejected($leaveRequest);
        \App\Jobs\SendLeaveRequestEmailJob::dispatch($leaveRequest, 'employee', 'rejected');
        AuditService::log('rejected', LeaveRequest::class, $leaveRequest->id);
        PlanningService::bumpSuggestionsVersion();

        return $this->successResponse($leaveRequest->load(['user', 'approver']), 'Leave rejected');
    }

    /**
     * Employee: view own leave requests.
     */
    public function myRequests(Request $request)
    {
        $leaves = LeaveRequest::where('user_id', $request->user()->id)
            ->latest()
            ->paginate(15);

        return $this->paginatedResponse($leaves);
    }
}
