<?php

namespace App\Http\Controllers;

use App\Models\Rating;
use App\Models\User;
use App\Services\AuditService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class RatingController extends Controller
{
    use ApiResponse;

    /**
     * Toggle rating between ⭐ (Excellent) and 🚩 (Warning)
     * 
     * First click on same star → Creates Excellent rating
     * Second click on same star (if Excellent exists) → Converts to Warning
     * 
     * This implements the prompt requirement:
     * "First click → Yellow star ⭐ (Excellent)
     *  Second click on same star → Red flag 🚩 (Warning / behavioral issue)"
     */
    public function toggle(Request $request, User $employee)
    {
        // Only admins can rate employees
       

        $weekNumber = now()->isoWeek();
        $year = now()->isoWeekYear();

        // Validate reason if provided
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        // Check for existing Excellent rating this week
        $existingExcellent = Rating::where('user_id', $employee->id)
            ->where('type', 'excellent')
            ->where('week_number', $weekNumber)
            ->where('year', $year)
            ->first();

        // SECOND CLICK: Excellent exists → Escalate to Warning (🚩)
        if ($existingExcellent) {
            // Delete the existing Excellent rating
            $existingExcellent->delete();

            // Create Warning rating
            $rating = Rating::create([
                'user_id' => $employee->id,
                'rated_by' => auth()->id(),
                'type' => 'warning',
                'reason' => $validated['reason'] ?? 'Escalated from ⭐ to 🚩 - Behavioral issue detected',
                'week_number' => $weekNumber,
                'year' => $year,
            ]);

            AuditService::log('rating_escalated', Rating::class, $rating->id, null, [
                'from' => 'excellent',
                'to' => 'warning',
                'employee_id' => $employee->id,
            ]);

            return $this->successResponse([
                'rating' => $rating,
                'type' => 'warning',
                'icon' => '🚩',
                'message' => "Warning (🚩) issued to {$employee->name}",
            ]);
        }

        // FIRST CLICK or REPLACE: Delete any existing Warning, create Excellent (⭐)
        Rating::where('user_id', $employee->id)
            ->where('week_number', $weekNumber)
            ->where('year', $year)
            ->delete();

        $rating = Rating::create([
            'user_id' => $employee->id,
            'rated_by' => auth()->id(),
            'type' => 'excellent',
            'reason' => $validated['reason'] ?? 'Excellent performance this week ⭐',
            'week_number' => $weekNumber,
            'year' => $year,
        ]);

        AuditService::log('rating_created', Rating::class, $rating->id, null, [
            'type' => 'excellent',
            'employee_id' => $employee->id,
        ]);

        return $this->successResponse([
            'rating' => $rating,
            'type' => 'excellent',
            'icon' => '⭐',
            'message' => "Excellent rating (⭐) given to {$employee->name}",
        ]);
    }

    /**
     * Get current rating for an employee (current week)
     */
    public function current(User $employee)
    {
        $weekNumber = now()->isoWeek();
        $year = now()->isoWeekYear();

        $rating = Rating::where('user_id', $employee->id)
            ->where('week_number', $weekNumber)
            ->where('year', $year)
            ->first();

        return $this->successResponse([
            'has_rating' => !is_null($rating),
            'type' => $rating?->type,
            'icon' => $rating?->type === 'excellent' ? '⭐' : ($rating?->type === 'warning' ? '🚩' : null),
            'reason' => $rating?->reason,
            'week_number' => $weekNumber,
            'year' => $year,
        ]);
    }

    /**
     * Get rating history for an employee (all weeks)
     */
    public function history(User $employee, Request $request)
    {
        $query = Rating::where('user_id', $employee->id)
            ->with('rater')
            ->orderBy('year', 'desc')
            ->orderBy('week_number', 'desc');

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        $ratings = $query->paginate(20);

        return $this->paginatedResponse($ratings);
    }
}