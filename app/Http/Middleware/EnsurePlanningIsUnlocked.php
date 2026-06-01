<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Carbon\Carbon;

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
            $lockTime = Setting::get('friday_lock_hour', ['time' => '17:00']);
            $lockHour = $lockTime['time'] ?? '17:00';
            $lockDateTime = Carbon::parse($now->toDateString() . ' ' . $lockHour);

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
