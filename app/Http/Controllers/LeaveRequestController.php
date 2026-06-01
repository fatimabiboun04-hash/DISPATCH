<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLeaveRequest;
use App\Models\LeaveRequest;
use App\Services\AuditService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class LeaveRequestController extends Controller
{
    use ApiResponse;

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
                \App\Jobs\SendLeaveRequestEmailJob::dispatch($leave, 'admin', 'submitted');

        AuditService::log('created', LeaveRequest::class, $leave->id);

        return $this->successResponse($leave->load('user'), 'Leave request submitted', 201);
    }

    public function show(LeaveRequest $leaveRequest)
    {
        return $this->successResponse($leaveRequest->load(['user', 'approver']));
    }

    /**
     * Admin: approve leave request.
     */
    public function approve(Request $request, LeaveRequest $leaveRequest)
    {
        if ($leaveRequest->status !== 'pending') {
            return $this->errorResponse('Leave request already processed', 422);
        }

        $leaveRequest->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        AuditService::log('approved', LeaveRequest::class, $leaveRequest->id);
                \App\Jobs\SendLeaveRequestEmailJob::dispatch($leaveRequest, 'employee', 'approved');
        return $this->successResponse($leaveRequest->load(['user', 'approver']), 'Leave approved');
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
                \App\Jobs\SendLeaveRequestEmailJob::dispatch($leaveRequest, 'employee', 'rejected');
        AuditService::log('rejected', LeaveRequest::class, $leaveRequest->id);

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
