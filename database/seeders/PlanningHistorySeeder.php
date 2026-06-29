<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\PlanningAudit;
use App\Models\Planning;
use App\Models\User;
use Illuminate\Database\Seeder;

class PlanningHistorySeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();
        $employees = User::where('role', 'employee')->where('status', 'active')->get();

        $now = now();

        // ── AuditLog entries for past planning operations ──
        $planningOperations = [
            ['action' => 'created', 'reason' => 'Création planning hebdomadaire'],
            ['action' => 'updated', 'reason' => 'Modification shift employé'],
            ['action' => 'locked',  'reason' => 'Verrouillage semaine planning'],
            ['action' => 'deleted', 'reason' => 'Suppression assignation'],
        ];

        $pastPlannings = Planning::where('date', '<', $now->startOfWeek()->format('Y-m-d'))
            ->inRandomOrder()
            ->limit(30)
            ->get();

        foreach ($pastPlannings as $i => $planning) {
            $op = $planningOperations[array_rand($planningOperations)];
            $daysAgo = $now->diffInDays($planning->date) + rand(0, 3);

            AuditLog::create([
                'user_id' => $admin->id,
                'action' => $op['action'],
                'entity_type' => 'App\Models\Planning',
                'entity_id' => $planning->id,
                'old_values' => $op['action'] === 'updated' ? [
                    'shift_id' => rand(1, 9),
                    'notes' => 'Ancienne note',
                ] : null,
                'new_values' => $op['action'] === 'created' || $op['action'] === 'updated' ? [
                    'user_id' => $planning->user_id,
                    'shift_id' => $planning->shift_id,
                    'date' => $planning->date,
                    'team_id' => $planning->team_id,
                ] : null,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Seeder/v1',
                'created_at' => $planning->created_at ?? $now->subDays($daysAgo),
            ]);
        }

        // ── PlanningAudit entries ──
        $planningAuditActions = ['created', 'locked', 'unlocked', 'batch_updated'];

        $auditPlannings = Planning::where('date', '<', $now->startOfWeek()->format('Y-m-d'))
            ->inRandomOrder()
            ->limit(20)
            ->get();

        foreach ($auditPlannings as $planning) {
            $action = $planningAuditActions[array_rand($planningAuditActions)];
            $daysAgo = $now->diffInDays($planning->date) + rand(0, 3);

            PlanningAudit::create([
                'planning_id' => $planning->id,
                'user_id' => $admin->id,
                'action' => $action,
                'old_values' => $action === 'updated' ? ['shift_id' => rand(1, 9)] : null,
                'new_values' => $action === 'created' || $action === 'updated'
                    ? ['user_id' => $planning->user_id, 'shift_id' => $planning->shift_id]
                    : null,
                'reason' => match ($action) {
                    'locked' => 'Verrouillage automatique fin de semaine',
                    'unlocked' => 'Déverrouillage par administrateur',
                    'batch_updated' => 'Mise à jour en lot des assignations',
                    default => 'Création planning',
                },
                'created_at' => $planning->created_at ?? $now->subDays($daysAgo),
            ]);
        }

        // ── AuditLog entries for week lock operations ──
        $weeksToLock = [1, 2, 3, 4];
        foreach ($weeksToLock as $weeksAgo) {
            $lockDate = $now->copy()->subWeeks($weeksAgo)->endOfWeek()->subDay();

            AuditLog::create([
                'user_id' => $admin->id,
                'action' => 'week_locked',
                'entity_type' => 'App\Models\Planning',
                'entity_id' => 0,
                'old_values' => null,
                'new_values' => ['week_number' => $lockDate->isoWeek, 'year' => $lockDate->isoWeekYear],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Seeder/v1',
                'created_at' => $lockDate,
            ]);
        }

        // ── AuditLog entries for employee management ──
        if ($employees->count() >= 2) {
            $randomEmployees = $employees->random(2);
            foreach ($randomEmployees as $emp) {
                AuditLog::create([
                    'user_id' => $admin->id,
                    'action' => 'employee_assigned',
                    'entity_type' => 'App\Models\Planning',
                    'entity_id' => $pastPlannings->first()?->id ?? 1,
                    'old_values' => null,
                    'new_values' => ['user_id' => $emp->id, 'name' => $emp->name],
                    'ip_address' => '127.0.0.1',
                    'user_agent' => 'Seeder/v1',
                    'created_at' => $now->subDays(rand(7, 14)),
                ]);
            }
        }
    }
}
