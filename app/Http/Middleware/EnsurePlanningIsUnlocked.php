<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlanningIsUnlocked
{
    /**
     * Block planning modifications during Friday lock period.
     * Exception: override-lock endpoint handles its own logic.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $now = Carbon::now();

        // Only enforce on Fridays
        if ($now->isFriday()) {
            $lockTime = Cache::remember('friday_lock_hour', 86400, function () {
                return Setting::get('friday_lock_hour', ['time' => '17:00']);
            });
            $lockHour = $lockTime['time'] ?? '17:00';
            $lockDateTime = Carbon::parse($now->toDateString().' '.$lockHour);

            if ($now->greaterThanOrEqualTo($lockDateTime)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Planning is locked for Friday automation. Modifications are disabled until next week.',
                    'locked_until' => $now->copy()->addDays(3)->startOfDay()->toDateTimeString(), // Monday
                ], 423); // 423 Locked
            }
        }

        return $next($request);
    }
}
