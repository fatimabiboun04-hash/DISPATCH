<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Block suspended users with a full-page response.
     * Frontend should detect this and render a lockout screen.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isSuspended()) {
            return response()->json([
                'success' => false,
                'locked' => true,
                'message' => 'Your account has been suspended.',
                'reason' => $user->suspension_reason,
                'contact_admin' => true,
            ], 403);
        }

        return $next($request);
    }
}
