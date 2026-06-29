<?php

namespace App\Http\Controllers;

use App\Models\Rating;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\PlanningService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class RatingController extends Controller
{
    use ApiResponse;

    public function rate(Request $request, User $employee)
    {
        if (! $request->user() || ! $request->user()->isAdmin()) {
            return $this->errorResponse('Unauthorized. Only admins can rate employees.', 403);
        }

        $validated = $request->validate([
            'score' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        $weekNumber = now()->isoWeek();
        $year = now()->isoWeekYear();

        $type = Rating::typeFromScore($validated['score']);

        Rating::where('user_id', $employee->id)
            ->where('week_number', $weekNumber)
            ->where('year', $year)
            ->delete();

        $rating = Rating::create([
            'user_id' => $employee->id,
            'rated_by' => auth()->id(),
            'type' => $type,
            'score' => $validated['score'],
            'comment' => $validated['comment'] ?? null,
            'week_number' => $weekNumber,
            'year' => $year,
        ]);

        AuditService::log('rating_created', Rating::class, $rating->id, null, [
            'score' => $validated['score'],
            'employee_id' => $employee->id,
        ]);

        app(NotificationService::class)->notifyRatingGiven($employee, $type);
        PlanningService::bumpSuggestionsVersion();

        return $this->successResponse([
            'rating' => $rating->load('rater'),
            'score' => $rating->score,
            'type' => $rating->type,
            'label' => Rating::scoreLabel($rating->score),
        ]);
    }

    public function current(User $employee)
    {
        $weekNumber = now()->isoWeek();
        $year = now()->isoWeekYear();

        $rating = Rating::where('user_id', $employee->id)
            ->where('week_number', $weekNumber)
            ->where('year', $year)
            ->with('rater')
            ->first();

        $allRatings = Rating::where('user_id', $employee->id)->get();
        $avgScore = $allRatings->avg('score');

        return $this->successResponse([
            'has_rating' => ! is_null($rating),
            'score' => $rating?->score,
            'type' => $rating?->type,
            'label' => $rating ? Rating::scoreLabel($rating->score) : null,
            'comment' => $rating?->comment,
            'week_number' => $weekNumber,
            'year' => $year,
            'average_score' => $avgScore ? round($avgScore, 1) : null,
            'total_ratings' => $allRatings->count(),
        ]);
    }

    public function history(User $employee, Request $request)
    {
        $query = Rating::where('user_id', $employee->id)
            ->with('rater')
            ->orderBy('year', 'desc')
            ->orderBy('week_number', 'desc');

        if ($request->has('score')) {
            $query->where('score', $request->score);
        }

        $ratings = $query->paginate(20);

        return $this->paginatedResponse($ratings);
    }

    public function stats()
    {
        $currentWeek = now()->isoWeek();
        $currentYear = now()->isoWeekYear();

        $allRatings = Rating::where('week_number', $currentWeek)
            ->where('year', $currentYear)
            ->get();

        $totalRated = $allRatings->count();
        $avgScore = $allRatings->avg('score');
        $fiveStar = $allRatings->where('score', 5)->count();
        $fourStar = $allRatings->where('score', 4)->count();
        $threeStar = $allRatings->where('score', 3)->count();
        $twoStar = $allRatings->where('score', 2)->count();
        $oneStar = $allRatings->where('score', 1)->count();
        $needsImprovement = $allRatings->where('score', '<=', 2)->count();

        $recentEvaluations = Rating::with(['user', 'rater'])
            ->where('week_number', $currentWeek)
            ->where('year', $currentYear)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'employee_name' => $r->user?->name,
                'employee_id' => $r->user_id,
                'score' => $r->score,
                'label' => Rating::scoreLabel($r->score),
                'rated_by_name' => $r->rater?->name,
                'created_at' => $r->created_at,
            ]);

        return $this->successResponse([
            'week_number' => $currentWeek,
            'year' => $currentYear,
            'total_rated' => $totalRated,
            'average_score' => $avgScore ? round($avgScore, 1) : null,
            'distribution' => [
                '5' => $fiveStar,
                '4' => $fourStar,
                '3' => $threeStar,
                '2' => $twoStar,
                '1' => $oneStar,
            ],
            'needs_improvement_count' => $needsImprovement,
            'recent_evaluations' => $recentEvaluations,
        ]);
    }
}
