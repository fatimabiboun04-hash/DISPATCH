<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PlanningController;
use App\Http\Controllers\PointageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\PauseController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\SkillController;

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // ── PUBLIC ──
    Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,1');

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
        // Pointage
        Route::post('/pointages/check-in', [PointageController::class, 'checkIn']);
        Route::post('/pointages/check-out', [PointageController::class, 'checkOut']);

        Route::get('/v1/skills', [SkillController::class, 'index']);


        //shift 
        Route::get('/shifts', [ShiftController::class, 'index']);
        //teams 
        Route::get('/teams', [TeamController::class, 'index']);
        // ── ADMIN ONLY ──
        Route::middleware('role:admin')->group(function () {

            // Employees
            Route::apiResource('/employees', EmployeeController::class);
            Route::get('/employees/{employee}/history', [EmployeeController::class, 'employeeHistory']);
            Route::get('/employees/{employee}/pointages', [PointageController::class, 'employeePointages']);
            // Teams
            Route::apiResource('/teams', TeamController::class);
            Route::post('/teams/{team}/assign', [TeamController::class, 'assignEmployee']);
            Route::delete('/teams/{team}/remove/{user}', [TeamController::class, 'removeEmployee']);
           //shift 

            
    Route::post('/shifts', [ShiftController::class, 'store']);
    Route::put('/shifts/{shift}', [ShiftController::class, 'update']);
    Route::delete('/shifts/{shift}', [ShiftController::class, 'destroy']);
            // Planning
            Route::middleware('planning.locked')->group(function () {
                Route::get('/planning', [PlanningController::class, 'index']);
                Route::post('/planning', [PlanningController::class, 'store']);
                Route::get('/planning/{planning}', [PlanningController::class, 'show']);
                Route::put('/planning/{planning}', [PlanningController::class, 'update']);
                Route::delete('/planning/{planning}', [PlanningController::class, 'destroy']);
                Route::post('/planning/suggest', [PlanningController::class, 'suggestEmployees']);
                Route::get('/employees/{employee}/planning', [PlanningController::class, 'employeePlanning']);
            });

            Route::post('/planning/override-lock', [PlanningController::class, 'overrideLock']);

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
            Route::apiResource('/reports', ReportController::class)->only(['index', 'store']);
            Route::get('/reports/{report}/download', [ReportController::class, 'download']);

            // Dashboard
            Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
            Route::get('/dashboard/live-feed', [DashboardController::class, 'liveFeed']);
            Route::get('/dashboard/coverage', [DashboardController::class, 'coverageGauge']);

            // ── PAUSE SYSTEM (NEW) ──
            Route::post('/pauses', [PauseController::class, 'store']);
            Route::put('/pauses/{pause}', [PauseController::class, 'update']);
            Route::delete('/pauses/{pause}', [PauseController::class, 'destroy']);
            Route::get('/pauses/planning/{planningId}', [PauseController::class, 'byPlanning']);
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
    Route::post('/toggle/{employee}', [\App\Http\Controllers\RatingController::class, 'toggle']);
    Route::get('/current/{employee}', [\App\Http\Controllers\RatingController::class, 'current']);
    Route::get('/history/{employee}', [\App\Http\Controllers\RatingController::class, 'history']);
});


// ── PLANNING WORKFLOW (ADMIN ONLY) ──
Route::prefix('planning')->group(function () {
    Route::post('/generate-next-week', [\App\Http\Controllers\PlanningController::class, 'generateNextWeek']);
    Route::post('/lock-current-week', [\App\Http\Controllers\PlanningController::class, 'lockCurrentWeek']);
});


// ── ABSENCE SYSTEM (ADMIN ONLY) ──
Route::prefix('pointage')->group(function () {
    Route::get('/absent-today', [\App\Http\Controllers\PointageController::class, 'absentToday']);
    Route::get('/replacement-suggestion/{planning}', [\App\Http\Controllers\PointageController::class, 'replacementSuggestion']);
});
        });
    });
});