<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PauseController;
use App\Http\Controllers\PlanningAuditController;
use App\Http\Controllers\PlanningController;
use App\Http\Controllers\PlanningSandboxController;
use App\Http\Controllers\PlanningStatsController;
use App\Http\Controllers\PlanningTemplateController;
use App\Http\Controllers\PointageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\SkillController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // ── PUBLIC ──
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('login');

    // ── AUTHENTICATED ──
    Route::middleware(['auth:sanctum', 'active.user'])->group(function () {

        Route::post('/logout', [AuthController::class, 'logout']);

        // ── EMPLOYEE SELF ──
        Route::get('/me', [ProfileController::class, 'show']);
        Route::put('/me', [ProfileController::class, 'update']);
        Route::get('/me/planning', [PlanningController::class, 'myPlanning']);
        Route::get('/me/pointages', [PointageController::class, 'myPointages']);
        Route::get('/me/leave-requests', [LeaveRequestController::class, 'myRequests']);
        Route::post('/leave-requests', [LeaveRequestController::class, 'store']);
        // Notifications
        Route::get('/me/notifications', [NotificationController::class, 'index']);
        Route::get('/me/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/me/notifications/{id}/read', [NotificationController::class, 'markRead']);
        Route::post('/me/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::get('/me/history', [EmployeeController::class, 'myHistory']);

        // ── GLOBAL SEARCH ──
        Route::get('/search', [SearchController::class, 'search']);

        // Pointage
        Route::post('/pointages/check-in', [PointageController::class, 'checkIn']);
        Route::post('/pointages/check-out', [PointageController::class, 'checkOut']);

        Route::get('/me/tasks', [TaskController::class, 'myTasks']);
        // Self-pause
        Route::post('/me/pauses/start', [PauseController::class, 'startMyPause']);
        Route::post('/me/pauses/stop', [PauseController::class, 'stopMyPause']);
        // Skills
        Route::get('/skills', [SkillController::class, 'index']);

        // shift
        Route::get('/shifts', [ShiftController::class, 'index']);
        // teams
        Route::get('/teams', [TeamController::class, 'index']);
        // ── ADMIN ONLY ──
        Route::middleware('role:admin')->group(function () {

            // Employees
            Route::apiResource('/employees', EmployeeController::class);
            Route::get('/employees/{employee}/history', [EmployeeController::class, 'employeeHistory']);
            Route::get('/employees/{employee}/pointages', [PointageController::class, 'employeePointages']);
            // Teams (GET /teams at line 70 is outside admin for all authenticated users)
            Route::post('/teams', [TeamController::class, 'store']);
            Route::get('/teams/{team}', [TeamController::class, 'show']);
            Route::put('/teams/{team}', [TeamController::class, 'update']);
            Route::delete('/teams/{team}', [TeamController::class, 'destroy']);
            Route::post('/teams/{team}/assign', [TeamController::class, 'assignEmployee']);
            Route::delete('/teams/{team}/remove/{user}', [TeamController::class, 'removeEmployee']);
            // shift

            Route::post('/shifts', [ShiftController::class, 'store']);
            Route::put('/shifts/{shift}', [ShiftController::class, 'update']);
            Route::delete('/shifts/{shift}', [ShiftController::class, 'destroy']);
            // Planning
            Route::get('/planning', [PlanningController::class, 'index']);
            Route::post('/planning/suggest', [PlanningController::class, 'suggestEmployees']);
            Route::get('/planning/employee-info/{employee}', [PlanningController::class, 'employeeInfo']);
            Route::get('/employees/{employee}/planning', [PlanningController::class, 'employeePlanning']);

            Route::middleware('planning.locked')->group(function () {
                Route::post('/planning', [PlanningController::class, 'store']);
                Route::put('/planning/{planning}', [PlanningController::class, 'update']);
                Route::delete('/planning/{planning}', [PlanningController::class, 'destroy']);
            });

            Route::post('/planning/override-lock', [PlanningController::class, 'overrideLock']);
            Route::post('/planning/{planning}/lock', [PlanningController::class, 'lockPlanning']);
            Route::post('/planning/generate-next-week', [PlanningController::class, 'generateNextWeek']);
            Route::post('/planning/lock-current-week', [PlanningController::class, 'lockCurrentWeek']);

            // ── PLANNING TEMPLATES ──
            Route::get('/planning-templates', [PlanningTemplateController::class, 'index']);
            Route::post('/planning-templates', [PlanningTemplateController::class, 'store']);
            Route::get('/planning-templates/{planning_template}', [PlanningTemplateController::class, 'show']);
            Route::put('/planning-templates/{planning_template}', [PlanningTemplateController::class, 'update']);
            Route::delete('/planning-templates/{planning_template}', [PlanningTemplateController::class, 'destroy']);
            Route::post('/planning-templates/{planning_template}/duplicate', [PlanningTemplateController::class, 'duplicate']);
            Route::post('/planning-templates/{planning_template}/load', [PlanningTemplateController::class, 'load']);

            // ── PLANNING SANDBOX ──
            Route::post('/planning/sandbox/generate', [PlanningSandboxController::class, 'generate']);
            Route::post('/planning/sandbox/preview', [PlanningSandboxController::class, 'preview']);
            Route::post('/planning/sandbox/accept', [PlanningSandboxController::class, 'accept']);
            Route::post('/planning/sandbox/cancel', [PlanningSandboxController::class, 'cancel']);

            // ── PLANNING STATISTICS ──
            Route::get('/planning/stats', [PlanningStatsController::class, 'index']);

            // ── PLANNING COVERAGE ──
            Route::get('/planning/coverage', [PlanningController::class, 'coverage']);

            // ── PLANNING QUALITY ──
            Route::get('/planning/quality', [PlanningController::class, 'quality']);

            // ── PLANNING AUDIT ──
            Route::get('/planning/audits', [PlanningAuditController::class, 'index']);

            // ── PLANNING BATCH OPERATIONS ──
            Route::post('/planning/batch/delete', [PlanningController::class, 'batchDelete']);
            Route::post('/planning/batch/update-shift', [PlanningController::class, 'batchUpdateShift']);
            Route::post('/planning/batch/assign-employee', [PlanningController::class, 'batchAssignEmployee']);
            Route::post('/planning/batch/duplicate-day', [PlanningController::class, 'duplicateDay']);
            Route::post('/planning/batch/validate', [PlanningController::class, 'validateBatch']);

            // ── PLANNING WILDCARD (must be after all specific /planning/* routes) ──
            Route::get('/planning/{planning}', [PlanningController::class, 'show']);

            // Leave Management
            Route::get('/leave-requests', [LeaveRequestController::class, 'index']);
            Route::post('/leave-requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve']);
            Route::post('/leave-requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject']);

            // Devices (admin only)
            Route::get('/devices', [DeviceController::class, 'index']);
            Route::post('/devices/{device}/trust', [DeviceController::class, 'trust']);
            Route::post('/devices/{device}/untrust', [DeviceController::class, 'untrust']);

            // Pointage Review
            Route::get('/pointages/flagged', [PointageController::class, 'flagged']);
            Route::post('/pointages/{pointage}/verify', [PointageController::class, 'verifyFlag']);

            // Reports
            Route::apiResource('/reports', ReportController::class)->only(['index', 'store', 'show']);
            Route::get('/reports/{report}/download', [ReportController::class, 'download']);

            // Dashboard
            Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
            Route::get('/dashboard/live-feed', [DashboardController::class, 'liveFeed']);
            Route::get('/dashboard/coverage', [DashboardController::class, 'coverageGauge']);
            Route::get('/dashboard/weekly-history', [DashboardController::class, 'weeklyHistory']);

            // ── PAUSE SYSTEM ──
            Route::get('/pauses', [PauseController::class, 'index']);
            Route::get('/pauses/stats', [PauseController::class, 'stats']);
            Route::post('/pauses', [PauseController::class, 'store']);
            Route::get('/pauses/{pause}', [PauseController::class, 'show']);
            Route::put('/pauses/{pause}', [PauseController::class, 'update']);
            Route::post('/pauses/{pause}/cancel', [PauseController::class, 'cancel']);
            Route::post('/pauses/{pause}/complete', [PauseController::class, 'complete']);
            Route::delete('/pauses/{pause}', [PauseController::class, 'destroy']);
            Route::get('/pauses/planning/{planningId}', [PauseController::class, 'byPlanning']);
            Route::get('/pauses/batch', [PauseController::class, 'batchByPlannings']);
            Route::get('/pauses/active-today', [PauseController::class, 'activeToday']);

            // Dashboard pause widget
            Route::get('/dashboard/active-pauses', [DashboardController::class, 'activePauses']);

            // Settings
            Route::get('/settings', [SettingController::class, 'index']);
            Route::put('/settings', [SettingController::class, 'update']);

            // Audit
            Route::get('/audit-logs', [AuditLogController::class, 'index']);
            // ── RATINGS (ADMIN ONLY) ──
            Route::prefix('ratings')->group(function () {
                Route::post('/rate/{employee}', [\App\Http\Controllers\RatingController::class, 'rate']);
                Route::get('/current/{employee}', [\App\Http\Controllers\RatingController::class, 'current']);
                Route::get('/history/{employee}', [\App\Http\Controllers\RatingController::class, 'history']);
                Route::get('/stats', [\App\Http\Controllers\RatingController::class, 'stats']);
            });

            // ── TASKS (ADMIN ONLY) ──
            Route::apiResource('/tasks', TaskController::class);

            // ── SKILLS CRUD (ADMIN ONLY) ──
            Route::post('/skills', [SkillController::class, 'store']);
            Route::put('/skills/{skill}', [SkillController::class, 'update']);
            Route::delete('/skills/{skill}', [SkillController::class, 'destroy']);

            // ── ABSENCE SYSTEM (ADMIN ONLY) ──
            Route::prefix('pointage')->group(function () {
                Route::get('/absent-today', [\App\Http\Controllers\PointageController::class, 'absentToday']);
                Route::get('/replacement-suggestion/{planning}', [\App\Http\Controllers\PointageController::class, 'replacementSuggestion']);
                Route::post('/assign-replacement/{planning}', [\App\Http\Controllers\PointageController::class, 'assignReplacement']);
            });
        });
    });
});
