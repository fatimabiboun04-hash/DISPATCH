<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    use ApiResponse;

    /**
     * List all shifts (admin + planning dropdown)
     */
    public function index()
    {
        $shifts = Shift::orderBy('start_time')->get();

        return $this->successResponse($shifts);
    }

    /**
     * Create new shift (admin only)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:day,night,conge,absence,emergency'], // ADD — required enum
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'break_minutes' => ['sometimes', 'integer', 'min:0', 'max:480'],        // ADD — optional
            'color' => ['nullable', 'string', 'max:7'],                      // ADD — optional
            'is_active' => ['sometimes', 'boolean'],
            'skill_ids' => ['nullable', 'array'],
            'skill_ids.*' => ['integer', 'exists:skills,id'],
        ]);

        $shift = Shift::create([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'break_minutes' => $validated['break_minutes'] ?? 0,
            'color' => $validated['color'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        if (! empty($validated['skill_ids'])) {
            $shift->skills()->sync($validated['skill_ids']);
        }

        return $this->successResponse($shift->load('skills'), 'Shift created', 201);
    }

    /**
     * Update shift
     */
    public function update(Request $request, Shift $shift)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'in:day,night,conge,absence,emergency'], // ADD
            'start_time' => ['sometimes', 'date_format:H:i'],
            'end_time' => ['sometimes', 'date_format:H:i'],
            'break_minutes' => ['sometimes', 'integer', 'min:0', 'max:480'],          // ADD
            'color' => ['nullable', 'string', 'max:7'],                        // ADD
            'is_active' => ['sometimes', 'boolean'],
            'skill_ids' => ['nullable', 'array'],
            'skill_ids.*' => ['integer', 'exists:skills,id'],
        ]);

        $shift->update($validated);

        if (array_key_exists('skill_ids', $validated)) {
            $shift->skills()->sync($validated['skill_ids'] ?? []);
        }

        return $this->successResponse($shift->load('skills'), 'Shift updated');
    }

    /**
     * Soft deactivate shift (better than delete)
     */
    public function destroy(Shift $shift)
    {
        $shift->update([
            'is_active' => false,
        ]);

        return $this->successResponse(null, 'Shift deactivated');
    }
}
