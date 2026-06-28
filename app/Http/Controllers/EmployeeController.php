<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeRequest;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeController extends Controller
{
    use ApiResponse;

    /**
     * List all employees with pagination.
     */
    public function index(Request $request)
    {
        $employees = User::employees()
            ->with(['teams', 'skills'])
            ->when($request->search, function ($query, $search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            })
            //  filter by team
            ->when($request->team_id, function ($query, $teamId) {
                $query->whereHas('teams', fn ($q) => $q->where('teams.id', $teamId));
            })
        // filter by status (active/suspended)
            ->when($request->status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->paginate(15);

        return $this->paginatedResponse($employees);
    }

    /**
     * Store new employee.
     */
    public function store(StoreEmployeeRequest $request)
    {
        $employee = DB::transaction(function () use ($request) {

            $employee = new User([
                'name' => $request->name,
                'email' => $request->email,
                'password' => bcrypt($request->password),
                'phone' => $request->phone,
                'description' => $request->description,
            ]);
            $employee->role = $request->role ?? 'employee';
            $employee->status = 'active';
            $employee->save();

            if ($request->has('team_ids')) {
                $employee->teams()->sync($request->team_ids);
            }

            if ($request->has('skill_ids')) {
                $employee->skills()->sync($request->skill_ids);
            }

            return $employee;
        });

        AuditService::log(
            'created',
            User::class,
            $employee->id,
            null,
            $employee->fresh()->load(['teams', 'skills'])->toArray()
        );

        app(NotificationService::class)->notifyEmployeeCreated($employee->fresh());

        return $this->successResponse(
            $employee->load(['teams', 'skills']),
            'Employee created',
            201
        );
    }

    /**
     * Show single employee.
     */
    public function show(User $employee)
    {
        return $this->successResponse(
            $employee->load(['teams', 'skills', 'ratings' => fn ($q) => $q->latest()->limit(10)])
        );
    }

    /**
     * Update employee.
     */
    public function update(StoreEmployeeRequest $request, User $employee)
    {
        $oldData = $employee->toArray();

        $employee->update($request->only(['name', 'email', 'phone', 'description', 'status']));
        if ($request->filled('password')) {
            $employee->update([
                'password' => bcrypt($request->password),
            ]);
        }
        if ($request->has('team_ids')) {
            $employee->teams()->sync($request->team_ids);
        }

        if ($request->has('skill_ids')) {
            $employee->skills()->sync($request->skill_ids);
        }

        AuditService::log('updated', User::class, $employee->id, $oldData, $employee->fresh()->toArray());

        return $this->successResponse($employee->load(['teams', 'skills']), 'Employee updated');
    }

    /**
     * Delete employee.
     */
    public function destroy(User $employee)
    {
        $oldData = $employee->toArray();
        $employeeId = $employee->id;

        $employee->delete();

        AuditService::log('deleted', User::class, $employeeId, $oldData, null);

        return response()->noContent();
    }

    /**
     * Employee: view own activity timeline.
     * Used by: My History page
     */
    public function myHistory(Request $request)
    {
        $user = $request->user();

        return $this->buildHistory($user, $request);
    }

    /**
     * Admin: view any employee's activity timeline.
     * Used by: Employee Profile → Activity Timeline tab
     */
    public function employeeHistory(Request $request, User $employee)
    {
        return $this->buildHistory($employee, $request);
    }

    /**
     * Shared history builder — no duplication.
     */
    private function buildHistory(User $user, Request $request): \Illuminate\Http\JsonResponse
    {
        $perPage = (int) $request->get('per_page', 20);
        $page = (int) $request->get('page', 1);
        $fetchLimit = $perPage * 20;

        // Fetch real DB counts for accurate pagination
        $pointageCount = \App\Models\Pointage::where('user_id', $user->id)->count();
        $ratingCount = \App\Models\Rating::where('user_id', $user->id)->count();
        $leaveCount = \App\Models\LeaveRequest::where('user_id', $user->id)->count();
        $planningCount = \App\Models\Planning::where('user_id', $user->id)->count();
        $totalCount = $pointageCount + $ratingCount + $leaveCount + $planningCount;

        $pointages = \App\Models\Pointage::where('user_id', $user->id)
            ->latest('check_in_at')
            ->limit($fetchLimit)
            ->get()
            ->map(fn ($p) => [
                'type' => 'pointage',
                'icon' => '📍',
                'title' => $p->status === 'present' ? 'Checked in on time' : ucfirst($p->status),
                'description' => 'Check-in: '.optional($p->check_in_at)->format('H:i')
                               .' | Check-out: '.(optional($p->check_out_at)->format('H:i') ?? 'Pending'),
                'date' => $p->check_in_at,
                'meta' => ['status' => $p->status, 'worked_minutes' => $p->worked_minutes],
            ]);

        $ratings = \App\Models\Rating::where('user_id', $user->id)
            ->latest()
            ->limit($fetchLimit)
            ->get()
            ->map(fn ($r) => [
                'type' => 'rating',
                'icon' => $r->type === 'excellent' ? '⭐' : '🚩',
                'title' => $r->type === 'excellent' ? 'Rated Excellent' : 'Warning Issued',
                'description' => $r->reason ?? 'No reason provided',
                'date' => $r->created_at,
                'meta' => ['type' => $r->type, 'week' => $r->week_number, 'year' => $r->year],
            ]);

        $leaves = \App\Models\LeaveRequest::where('user_id', $user->id)
            ->latest()
            ->limit($fetchLimit)
            ->get()
            ->map(fn ($l) => [
                'type' => 'leave',
                'icon' => $l->status === 'approved' ? '✅' : ($l->status === 'rejected' ? '❌' : '⏳'),
                'title' => 'Leave Request — '.ucfirst($l->status),
                'description' => $l->start_date->format('d M').' → '.$l->end_date->format('d M Y'),
                'date' => $l->created_at,
                'meta' => ['status' => $l->status, 'reason' => $l->reason],
            ]);

        $plannings = \App\Models\Planning::where('user_id', $user->id)
            ->with('shift')
            ->latest('date')
            ->limit($fetchLimit)
            ->get()
            ->map(fn ($p) => [
                'type' => 'planning',
                'icon' => '📅',
                'title' => 'Assigned: '.optional($p->shift)->name,
                'description' => 'Week '.$p->week_number.' — '.\Carbon\Carbon::parse($p->date)->format('l, d M Y'),
                'date' => $p->created_at,
                'meta' => ['date' => $p->date, 'shift' => optional($p->shift)->name],
            ]);

        $timeline = $pointages
            ->concat($ratings)
            ->concat($leaves)
            ->concat($plannings)
            ->sortByDesc('date')
            ->values();

        $sliced = $timeline->slice(($page - 1) * $perPage, $perPage)->values();
        $lastPage = (int) ceil($totalCount / max($perPage, 1));

        return $this->successResponse([
            'data' => $sliced,
            'meta' => [
                'current_page' => (int) $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $totalCount,
            ],
        ]);
    }
}
