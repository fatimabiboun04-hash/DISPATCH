<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\User;
use App\Services\AuditService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $teams = Team::with(['users', 'leader'])->paginate(15);
        return $this->paginatedResponse($teams);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'leader_id' => 'nullable|exists:users,id',
            'color' => 'nullable|string|max:7',
        ]);

        $team = Team::create($validated);

        AuditService::log('created', Team::class, $team->id);

        return $this->successResponse($team->load(['users', 'leader']), 'Team created', 201);
    }

    public function show(Team $team)
    {
        return $this->successResponse($team->load(['users.skills', 'leader']));
    }

    public function update(Request $request, Team $team)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'leader_id' => 'nullable|exists:users,id',
            'color' => 'nullable|string|max:7',
        ]);

        $oldData = $team->toArray();
        $team->update($validated);

        AuditService::log('updated', Team::class, $team->id, $oldData, $team->fresh()->toArray());

        return $this->successResponse($team->load(['users', 'leader']), 'Team updated');
    }

    public function destroy(Team $team)
    {
        $teamId = $team->id;
        $team->delete();

        AuditService::log('deleted', Team::class, $teamId);

        return response()->noContent();

    }

    /**
     * Assign employee to team.
     */
    public function assignEmployee(Request $request, Team $team)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $team->users()->syncWithoutDetaching([$validated['user_id']]);

        AuditService::log('assigned_to_team', Team::class, $team->id, null, ['user_id' => $validated['user_id']]);

        return $this->successResponse($team->load('users'), 'Employee assigned to team');
    }

    /**
     * Remove employee from team.
     */
    public function removeEmployee(Team $team, User $user)
    {
        $team->users()->detach($user->id);

        AuditService::log('removed_from_team', Team::class, $team->id, ['user_id' => $user->id], null);

        return $this->successResponse($team->load('users'), 'Employee removed from team');
    }
}