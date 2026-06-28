<?php

namespace App\Http\Controllers;

use App\Models\Skill;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class SkillController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $skills = Skill::orderBy('category')
            ->orderBy('name')
            ->get();

        return $this->successResponse($skills);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
        ]);

        $skill = Skill::create($validated);

        return $this->successResponse($skill, 'Skill created', 201);
    }

    public function update(Request $request, Skill $skill)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
        ]);

        $skill->update($validated);

        return $this->successResponse($skill->fresh(), 'Skill updated');
    }

    public function destroy(Skill $skill)
    {
        $skill->delete();

        return $this->successResponse(null, 'Skill deleted');
    }
}
