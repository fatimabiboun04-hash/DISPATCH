<?php

namespace App\Http\Controllers;

use App\Services\HoursCalculatorService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    use ApiResponse;

    protected HoursCalculatorService $hoursCalculator;

    public function __construct(HoursCalculatorService $hoursCalculator)
    {
        $this->hoursCalculator = $hoursCalculator;
    }

    /**
     * Get current user's profile with stats.
     */
    public function show(Request $request)
    {
        $now = now();
        $weekNumber = $now->isoWeek();
        $year = $now->isoWeekYear();

        $user = $request->user()->load([
            'teams',
            'skills',
            'ratings' => fn ($q) => $q->where('week_number', $weekNumber)
                ->where('year', $year)
                ->latest()->limit(1),
        ]);

        $hoursStatus = $this->hoursCalculator->getHoursStatus($user, $weekNumber, $year);

        $currentRating = $user->ratings->first();

        // ADD: Calculate "member since X months" for each team using pivot joined_at
        $teamsWithTenure = $user->teams->map(function ($team) {
            $joinedAt = $team->pivot->joined_at
                ? \Carbon\Carbon::parse($team->pivot->joined_at)
                : null;
            $monthsInTeam = $joinedAt ? (int) $joinedAt->diffInMonths(now()) : null;
            $sinceFormatted = $joinedAt ? $joinedAt->format('M Y') : null;

            return [
                'id' => $team->id,
                'name' => $team->name,
                'color' => $team->color,
                'joined_at' => $joinedAt?->toDateString(),
                'months_in_team' => $monthsInTeam,
                'since_formatted' => $sinceFormatted,
                // e.g. "Member of Team 1 since 3 months"
                'tenure_label' => $monthsInTeam !== null
                    ? "Member of {$team->name} since {$monthsInTeam} month(s)"
                    : "Member of {$team->name}",
            ];
        });

        // ADD: Total hours this month from actual pointages
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();
        $monthlyMinutes = \App\Models\Pointage::where('user_id', $user->id)
            ->whereNotNull('check_out_at')
            ->whereNotNull('worked_minutes')
            ->whereBetween('check_in_at', [$monthStart, $monthEnd])
            ->sum('worked_minutes');
        $monthlyHours = round($monthlyMinutes / 60, 1);

        return $this->successResponse([
            'profile' => $user,
            'teams' => $teamsWithTenure,   // enriched teams with tenure
            'stats' => [
                'weekly_hours' => $hoursStatus['hours'],
                'weekly_limit' => $user->weekly_hours_limit,
                'hours_state' => $hoursStatus['color'],
                'alert_message' => $hoursStatus['alert_message'],
                'current_week' => $weekNumber,
                'is_overtime' => $hoursStatus['is_overtime'],
                'is_under_hours' => $hoursStatus['is_under_hours'],
                'monthly_hours' => $monthlyHours,           // ADD
                'monthly_hours_label' => "Total hours this month: {$monthlyHours}h", // ADD
            ],
            'current_rating' => [
                'has_rating' => ! is_null($currentRating),
                'type' => $currentRating?->type,
                'icon' => $currentRating?->type === 'excellent' ? '⭐'
                              : ($currentRating?->type === 'warning' ? '🚩' : null),
                'reason' => $currentRating?->reason,
            ],
        ]);
    }

    /**
     * Update current user's profile.
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:20',
            'description' => 'nullable|string',
            'avatar' => 'nullable|image|max:2048',
            'current_password' => 'required_with:password|string',
            'password' => 'sometimes|string|min:8|confirmed',
        ]);

        // Handle password change
        if ($request->has('password')) {
            if (! Hash::check($request->current_password, $user->password)) {
                return $this->errorResponse('Current password is incorrect', 422);
            }
            $validated['password'] = bcrypt($request->password);
            unset($validated['current_password']);
        }

        // Handle avatar upload
        if ($request->hasFile('avatar')) {
            // Delete old avatar if exists
            if ($user->avatar) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($user->avatar);
            }
            // Store on public disk so it generates a real URL
            $path = $request->file('avatar')->store('avatars', 'public');
            $validated['avatar'] = $path;
        }

        $user->update($validated);

        return $this->successResponse($user->fresh()->load('teams'), 'Profile updated');
    }
}
