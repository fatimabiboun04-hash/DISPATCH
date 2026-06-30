<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Pause;
use App\Models\Planning;
use App\Models\Pointage;
use App\Models\Rating;
use App\Models\Shift;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class HistoricalPlanningSeeder extends Seeder
{
    private array $dayNames = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

    private int $totalPointages = 0;
    private int $totalPauses = 0;
    private int $totalTasks = 0;

    private array $pauseTypes = ['lunch', 'break', 'technical', 'emergency'];

    private array $taskTemplates = [
        ['title' => 'Inspection fibre zone nord',       'description' => "Vérifier l'intégrité des câbles fibre dans le secteur nord",        'priority' => 'high'],
        ['title' => 'Mise à jour équipements réseau',   'description' => 'Appliquer les correctifs de sécurité sur les routeurs',               'priority' => 'critical'],
        ['title' => 'Support client urgent',            'description' => 'Client signalant une panne totale — diagnostic immédiat',              'priority' => 'critical'],
        ['title' => 'Maintenance préventive antenne',   'description' => 'Nettoyage et vérification des antennes relais',                        'priority' => 'medium'],
        ['title' => "Rapport d'intervention",           'description' => "Rédiger le rapport d'intervention de la semaine",                      'priority' => 'low'],
        ['title' => 'Installation nouveau client',      'description' => 'Déploiement fibre chez nouveau client zone industrielle',               'priority' => 'medium'],
        ['title' => 'Vérification stocks équipements',  'description' => 'Inventaire du matériel en entrepôt',                                    'priority' => 'low'],
        ['title' => 'Test de couverture radio',         'description' => 'Effectuer des mesures de couverture radio secteur sud',                 'priority' => 'medium'],
        ['title' => 'Urgence panne câble principal',    'description' => 'Câble sectionné route principale — intervention rapide',                'priority' => 'critical'],
        ['title' => 'Configuration routeur client',     'description' => 'Paramétrage équipement client VIP',                                     'priority' => 'high'],
        ['title' => 'Audit sécurité trimestriel',       'description' => 'Vérification conforme des accès et habilitations',                      'priority' => 'high'],
        ['title' => 'Réunion coordination équipe',      'description' => 'Point hebdomadaire avec les chefs d\'équipe',                           'priority' => 'medium'],
        ['title' => 'Mise à jour documentation',        'description' => 'Mettre à jour les procédures d\'intervention',                          'priority' => 'low'],
        ['title' => 'Déploiement équipements 4G',       'description' => 'Installation de nouvelles antennes 4G site Est',                         'priority' => 'high'],
        ['title' => 'Vérification alarmes réseau',      'description' => 'Analyser les alertes remontées par le système de supervision',           'priority' => 'medium'],
        ['title' => 'Remplacement onduleur défectueux', 'description' => 'Onduleur hors service au NOC — remplacement urgent',                     'priority' => 'high'],
        ['title' => 'Nettoyage baies techniques',       'description' => 'Dépoussiérage et vérification des baies de brassage',                     'priority' => 'low'],
        ['title' => 'Intervention client prioritaire',  'description' => 'Client entreprise sans connexion depuis 2h',                             'priority' => 'critical'],
        ['title' => 'Test débit fibre post-réparation', 'description' => 'Vérifier que le débit est conforme après intervention',                  'priority' => 'medium'],
        ['title' => 'Préparation rapport mensuel',      'description' => 'Compiler les KPI du mois pour la direction',                            'priority' => 'medium'],
        ['title' => 'Vérification GPS véhicules',       'description' => "S'assurer que tous les trackers GPS des véhicules sont fonctionnels",    'priority' => 'low'],
        ['title' => 'Réparation câble aérien',          'description' => 'Câble endommagé par intempéries — réparation urgente',                    'priority' => 'high'],
        ['title' => 'Livraison matériel site distant',  'description' => 'Acheminer le matériel de remplacement vers le site de Bouskoura',         'priority' => 'medium'],
        ['title' => 'Formation nouvel arrivant',        'description' => 'Former le nouveau technicien aux procédures terrain',                   'priority' => 'low'],
    ];

    private array $ratingComments = [
        5 => [
            'Excellent travail cette semaine. Très réactif et professionnel.',
            'Performance remarquable. Continue comme ça !',
            'Un technicien exemplaire, toujours disponible et efficace.',
            'Résultats exceptionnels, aucun incident à signaler.',
        ],
        4 => [
            'Très bon travail, légères améliorations possibles sur la ponctualité.',
            'Bonne performance globale. Objectifs atteints.',
            'Travail sérieux et de qualité. Quelques retards mineurs.',
        ],
        3 => [
            'Travail correct mais peut mieux faire. À suivre.',
            'Performance moyenne. Points à améliorer : organisation.',
            'Résultats acceptables. Encourageons la progression.',
        ],
        2 => [
            'Des efforts sont nécessaires sur la rigueur et la ponctualité.',
            'Performance en dessous des attentes. Entretien souhaité.',
        ],
        1 => [
            'Semaine problématique. Plusieurs absences non justifiées.',
            'Comportement inapproprié sur le terrain. Mesures nécessaires.',
            'Non-respect des procédures de sécurité. Rappel urgent.',
        ],
    ];

    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();
        if (!$admin) {
            $this->command->error('Admin user not found. Run AdminSeeder first.');
            return;
        }

        $employees = User::where('role', 'employee')->where('status', 'active')->get();
        $shifts = Shift::all()->keyBy('name');
        $teams = Team::all()->keyBy('name');

        if ($employees->isEmpty()) {
            $this->command->error('No active employees found. Run EmployeesSeeder first.');
            return;
        }

        $now = now();
        $dayShifts = ['Matin', 'Après-midi', 'Journée'];
        $weekendDayShift = 'Weekend Jour';
        $weekendNightShift = 'Weekend Nuit';

        // Targeted historical weeks
        $weekOffsets = [-4, -3, -2, -1];
        $weekLabels = ['1 mois', '3 semaines', '2 semaines', '1 semaine'];

        $totalPlannings = 0;
        $totalRatings = 0;
        $totalAuditLogs = 0;

        foreach ($weekOffsets as $idx => $offset) {
            $startOfWeek = $now->copy()->startOfWeek()->addWeeks($offset);
            $weekNumber = (int) $startOfWeek->isoWeek;
            $year = (int) $startOfWeek->isoWeekYear;

            $this->command->info("Generating week {$weekLabels[$idx]} (W{$weekNumber} {$year})...");

            foreach ($employees as $employee) {
                $employeeTeams = $employee->teams()->pluck('teams.id')->toArray();
                if (empty($employeeTeams)) continue;
                $teamId = $employeeTeams[0];

                // Assign 6 days (Monday-Saturday) for each employee per week
                for ($dayIndex = 0; $dayIndex < 6; $dayIndex++) {
                    $date = $startOfWeek->copy()->addDays($dayIndex);
                    $dateStr = $date->format('Y-m-d');

                    // Skip if planning already exists for this employee on this date
                    $existingPlanning = Planning::where('user_id', $employee->id)
                        ->where('date', $dateStr)
                        ->first();

                    if ($existingPlanning) {
                        // Ensure related data exists for this planning
                        $this->ensureRelatedData($existingPlanning, $admin, $shifts, $dayIndex);
                        continue;
                    }

                    // Determine shift based on day
                    $isWeekend = ($dayIndex >= 5);
                    if ($isWeekend) {
                        $shiftName = rand(0, 1) ? $weekendDayShift : $weekendNightShift;
                        $shift = $shifts[$shiftName] ?? $shifts->first();
                    } else {
                        $shiftName = $dayShifts[array_rand($dayShifts)];
                        $shift = $shifts[$shiftName] ?? $shifts['Journée'];
                    }

                    $notesOptions = [
                        null,
                        null,
                        null,
                        'Intervention terminée',
                        'Équipement vérifié',
                        'Client satisfait',
                        'Rapport envoyé',
                        'Suivi nécessaire',
                    ];
                    $notes = $notesOptions[array_rand($notesOptions)];

                    $planning = Planning::create([
                        'user_id' => $employee->id,
                        'team_id' => $teamId,
                        'shift_id' => $shift->id,
                        'date' => $dateStr,
                        'week_number' => $weekNumber,
                        'year' => $year,
                        'notes' => $notes,
                        'created_by' => $admin->id,
                        'is_locked' => true,
                    ]);

                    $totalPlannings++;

                    // Create pointage (check-in/out)
                    $this->createPointage($planning, $shift, $admin);
                    $totalPointages++;

                    // Create pauses
                    $this->createPauses($planning, $shift, $admin);
                    $totalPauses++;

                    // Create tasks
                    $this->createTasks($planning, $admin, $dayIndex);
                    $totalTasks++;

                    // Create audit log
                    $this->createAuditLog($planning, $admin);
                    $totalAuditLogs++;

                    // Notification for planning creation
                    $this->createPlanningNotification($planning);
                }

                // Create weekly rating for this employee
                $this->createWeeklyRating($employee, $admin, $weekNumber, $year);
                $totalRatings++;
            }

            // Create week-level audit log
            AuditLog::create([
                'user_id' => $admin->id,
                'action' => 'week_locked',
                'entity_type' => 'Planning',
                'entity_id' => 0,
                'old_values' => null,
                'new_values' => json_encode([
                    'week_number' => $weekNumber,
                    'year' => $year,
                    'locked_at' => $startOfWeek->copy()->endOfWeek()->subDay()->format('Y-m-d H:i:s'),
                ]),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'HistoricalPlanningSeeder',
                'created_at' => $startOfWeek->copy()->endOfWeek()->subDay(),
            ]);
            $totalAuditLogs++;

            $this->command->info("  ✓ Week W{$weekNumber} {$year} complete");
        }

        $this->command->info('');
        $this->command->info("Historical Planning Seeder Complete:");
        $this->command->info("  - Weeks: " . implode(', ', $weekLabels));
        $this->command->info("  - Plannings created: {$totalPlannings}");
        $this->command->info("  - Pointages created: {$this->totalPointages}");
        $this->command->info("  - Pauses created: {$this->totalPauses}");
        $this->command->info("  - Tasks created: {$this->totalTasks}");
        $this->command->info("  - Weekly ratings: {$totalRatings}");
        $this->command->info("  - Audit logs: {$totalAuditLogs}");

        // Post-seed integrity check — repair any remaining missing relations across ALL plannings
        $this->repairMissingRelations($admin);
    }

    private function createPointage(Planning $planning, Shift $shift, User $admin): void
    {
        $dateStr = $planning->date instanceof Carbon
            ? $planning->date->format('Y-m-d')
            : (is_string($planning->date) ? $planning->date : '2026-01-01');
        $startRaw = $shift->getRawOriginal('start_time') ?? '00:00:00';
        $endRaw = $shift->getRawOriginal('end_time') ?? '23:59:00';
        $startTime = Carbon::parse($dateStr . ' ' . $startRaw);
        $endTime = Carbon::parse($dateStr . ' ' . $endRaw);
        // Handle overnight shifts (e.g., Nuit 22:00-06:00)
        if ($endTime->lessThanOrEqualTo($startTime)) {
            $endTime->addDay();
        }

        // Realistic check-in: 0-15 min before start
        $checkIn = (clone $startTime)->subMinutes(rand(0, 15));
        // Realistic check-out: 0-10 min after end
        $checkOut = (clone $endTime)->addMinutes(rand(0, 10));

        $workedMinutes = (int) $checkIn->diffInMinutes($checkOut) - ($shift->break_minutes ?? 0);
        $delayMinutes = max(0, (int) $startTime->diffInMinutes($checkIn, false));
        $overtimeMinutes = max(0, (int) $endTime->diffInMinutes($checkOut, false));

        // 85% on_time, 10% late, 5% early_leave
        $rand = rand(1, 100);
        if ($rand <= 85) {
            $status = 'on_time';
            $delayMinutes = 0;
        } elseif ($rand <= 95) {
            $status = 'late';
        } else {
            $status = 'early_leave';
            $workedMinutes = max(0, $workedMinutes - rand(15, 60));
        }

        Pointage::create([
            'user_id' => $planning->user_id,
            'planning_id' => $planning->id,
            'check_in_at' => $checkIn,
            'check_out_at' => $checkOut,
            'scheduled_start' => $startTime,
            'scheduled_end' => $endTime,
            'status' => $status,
            'worked_minutes' => $workedMinutes,
            'delay_minutes' => $delayMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'is_flagged' => false,
            'created_at' => $checkIn,
            'updated_at' => $checkOut,
        ]);
    }

    private function createPauses(Planning $planning, Shift $shift, User $admin): void
    {
        $dateStr = $planning->date instanceof Carbon
            ? $planning->date->format('Y-m-d')
            : (is_string($planning->date) ? $planning->date : '2026-01-01');
        $startRaw = $shift->getRawOriginal('start_time') ?? '00:00:00';
        $endRaw = $shift->getRawOriginal('end_time') ?? '23:59:00';
        $startTime = Carbon::parse($dateStr . ' ' . $startRaw);
        $endTime = Carbon::parse($dateStr . ' ' . $endRaw);

        if ($endTime->lessThanOrEqualTo($startTime)) {
            $endTime->addDay();
        }

        // Always create lunch break for shifts > 4h
        $shiftDuration = (int) $startTime->diffInMinutes($endTime);
        if ($shiftDuration <= 240) return;

        // Lunch break: 45-90 min, 2-3h after shift start
        $lunchStart = (clone $startTime)->addMinutes(rand(120, 180));
        $lunchDuration = rand(45, 90);
        $lunchEnd = (clone $lunchStart)->addMinutes($lunchDuration);

        if ($lunchEnd->lessThan($endTime)) {
            Pause::create([
                'user_id' => $planning->user_id,
                'team_id' => $planning->team_id,
                'planning_id' => $planning->id,
                'type' => 'lunch',
                'status' => 'completed',
                'pause_start' => $lunchStart,
                'pause_end' => $lunchEnd,
                'duration_minutes' => $lunchDuration,
                'created_at' => $lunchStart,
                'updated_at' => $lunchEnd,
            ]);
        }

        // 60% chance of second pause (coffee/technical)
        if (rand(1, 100) <= 60) {
            $pauseType = $this->pauseTypes[array_rand($this->pauseTypes)];
            $breakDuration = rand(10, 20);
            $breakStart = (clone $lunchEnd)->addMinutes(rand(60, 120));
            $breakEnd = (clone $breakStart)->addMinutes($breakDuration);

            if ($breakEnd->lessThan($endTime)) {
                $reasons = [
                    'break' => 'Pause café',
                    'technical' => 'Pause technique — vérification équipement',
                    'emergency' => 'Intervention brève non planifiée',
                    'lunch' => null,
                ];

                Pause::create([
                    'user_id' => $planning->user_id,
                    'team_id' => $planning->team_id,
                    'planning_id' => $planning->id,
                    'type' => $pauseType,
                    'reason' => $reasons[$pauseType] ?? 'Pause',
                    'status' => 'completed',
                    'pause_start' => $breakStart,
                    'pause_end' => $breakEnd,
                    'duration_minutes' => $breakDuration,
                    'created_at' => $breakStart,
                    'updated_at' => $breakEnd,
                ]);
            }
        }
    }

    private function createTasks(Planning $planning, User $admin, int $dayIndex): void
    {
        // 80% chance of having at least one task
        if (rand(1, 100) > 80) return;

        $template = $this->taskTemplates[array_rand($this->taskTemplates)];
        $statuses = ['completed', 'completed', 'completed', 'in_progress', 'cancelled'];
        $status = $statuses[array_rand($statuses)];

        Task::create([
            'user_id' => $planning->user_id,
            'planning_id' => $planning->id,
            'title' => $template['title'],
            'description' => $template['description'],
            'status' => $status,
            'priority' => $template['priority'],
            'due_date' => $planning->date,
            'created_by' => $admin->id,
            'created_at' => Carbon::parse($planning->date)->subDay(),
            'updated_at' => Carbon::parse($planning->date),
        ]);

        // 30% chance of a second task
        if (rand(1, 100) <= 30) {
            $template2 = $this->taskTemplates[array_rand($this->taskTemplates)];
            Task::create([
                'user_id' => $planning->user_id,
                'planning_id' => $planning->id,
                'title' => $template2['title'],
                'description' => $template2['description'],
                'status' => $statuses[array_rand($statuses)],
                'priority' => $template2['priority'],
                'due_date' => $planning->date,
                'created_by' => $admin->id,
                'created_at' => Carbon::parse($planning->date)->subDay(),
                'updated_at' => Carbon::parse($planning->date),
            ]);
        }
    }

    private function createWeeklyRating(User $employee, User $admin, int $weekNumber, int $year): void
    {
        // Check if rating already exists for this employee/week
        $existing = Rating::where('user_id', $employee->id)
            ->where('week_number', $weekNumber)
            ->where('year', $year)
            ->first();

        if ($existing) return;

        // Not every employee gets a rating
        if (rand(1, 100) > 85) return;

        $score = $this->weightedRandomScore($employee->name);
        $type = $score >= 4 ? 'excellent' : 'warning';
        $comments = $this->ratingComments[$score] ?? ['Évaluation hebdomadaire standard.'];
        $comment = $comments[array_rand($comments)];

        Rating::create([
            'user_id' => $employee->id,
            'rated_by' => $admin->id,
            'type' => $type,
            'score' => $score,
            'reason' => $comment,
            'comment' => $comment,
            'week_number' => $weekNumber,
            'year' => $year,
        ]);
    }

    private function createAuditLog(Planning $planning, User $admin): void
    {
        AuditLog::create([
            'user_id' => $admin->id,
            'action' => 'planning_created',
            'entity_type' => 'Planning',
            'entity_id' => $planning->id,
            'old_values' => null,
            'new_values' => json_encode([
                'user_id' => $planning->user_id,
                'shift_id' => $planning->shift_id,
                'date' => $planning->date,
                'week_number' => $planning->week_number,
                'year' => $planning->year,
            ]),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'HistoricalPlanningSeeder',
            'created_at' => Carbon::parse($planning->date)->subHours(rand(1, 12)),
        ]);
    }

    private function createPlanningNotification(Planning $planning): void
    {
        $user = User::find($planning->user_id);
        if (!$user) return;

        $dateStr = $planning->date instanceof Carbon
            ? $planning->date->format('Y-m-d')
            : (is_string($planning->date) ? $planning->date : 'unknown');

        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'planning_created',
            'data' => [
                'message' => "Vous avez été planifié le {$dateStr}",
                'link' => '/planning',
            ],
            'read_at' => rand(0, 1) ? Carbon::parse($dateStr) : null,
            'created_at' => Carbon::parse($dateStr)->subDay(),
            'updated_at' => Carbon::parse($dateStr)->subDay(),
        ]);
    }

    private function ensureRelatedData(Planning $planning, User $admin, $shifts, int $dayIndex): void
    {
        $shift = $planning->shift;

        // Ensure pointage exists
        if (!$planning->pointages()->exists()) {
            $this->createPointage($planning, $shift, $admin);
            $this->totalPointages++;
        }

        // Ensure pauses exist
        if (!$planning->pauses()->exists()) {
            $this->createPauses($planning, $shift, $admin);
            $this->totalPauses++;
        }

        // Ensure tasks exist
        if (!$planning->tasks()->exists()) {
            $this->createTasks($planning, $admin, $dayIndex);
            $this->totalTasks++;
        }

        // Ensure audit log exists
        $hasLog = AuditLog::where('entity_type', 'Planning')
            ->where('entity_id', $planning->id)
            ->exists();

        if (!$hasLog) {
            $this->createAuditLog($planning, $admin);
        }
    }

    private function repairMissingRelations(User $admin): void
    {
        $repairedPointages = 0;
        $repairedPauses = 0;
        $repairedTasks = 0;

        $this->command->info('');
        $this->command->info('Running post-seed integrity check...');

        // 1. Find all locked (past) plannings that lack pointages
        $noPointage = Planning::where('is_locked', true)->whereDoesntHave('pointages')->get();
        if ($noPointage->isNotEmpty()) {
            $this->command->info("  - Plannings without pointages: {$noPointage->count()}");
            foreach ($noPointage as $planning) {
                $shift = $planning->shift;
                if ($shift) {
                    $this->createPointage($planning, $shift, $admin);
                    $repairedPointages++;
                }
            }
        } else {
            $this->command->info('  - All plannings have pointages');
        }

        // 2. Find all pointages with check_in_at but whose planning has no pauses
        $noPauses = Pointage::whereNotNull('check_in_at')
            ->whereDoesntHave('planning.pauses')
            ->with('planning.shift')
            ->get();
        if ($noPauses->isNotEmpty()) {
            $this->command->info("  - Pointages without pauses: {$noPauses->count()}");
            foreach ($noPauses as $ptg) {
                $shift = $ptg->planning->shift;
                if ($shift) {
                    $this->createPauses($ptg->planning, $shift, $admin);
                    $repairedPauses++;
                }
            }
        } else {
            $this->command->info('  - All pointages have pauses');
        }

        // 3. Find locked plannings with pointages but no tasks — force-create one
        $noTasks = Planning::where('is_locked', true)
            ->whereHas('pointages')
            ->whereDoesntHave('tasks')
            ->get();
        if ($noTasks->isNotEmpty()) {
            $this->command->info("  - Plannings with pointages but no tasks: {$noTasks->count()}");
            foreach ($noTasks as $planning) {
                $this->createTaskForced($planning, $admin);
                $repairedTasks++;
            }
        } else {
            $this->command->info('  - All plannings with pointages have tasks');
        }

        // Final summary
        $totalRepaired = $repairedPointages + $repairedPauses + $repairedTasks;
        if ($totalRepaired > 0) {
            $this->command->info("  Repaired: {$repairedPointages} pointages, {$repairedPauses} pauses, {$repairedTasks} tasks");
        } else {
            $this->command->info('  ✓ No repairs needed — database is fully consistent');
        }
    }

    private function createTaskForced(Planning $planning, User $admin): void
    {
        $template = $this->taskTemplates[array_rand($this->taskTemplates)];
        $statuses = ['completed', 'completed', 'completed', 'in_progress', 'cancelled'];
        $status = $statuses[array_rand($statuses)];

        Task::create([
            'user_id' => $planning->user_id,
            'planning_id' => $planning->id,
            'title' => $template['title'],
            'description' => $template['description'],
            'status' => $status,
            'priority' => $template['priority'],
            'due_date' => $planning->date,
            'created_by' => $admin->id,
            'created_at' => Carbon::parse($planning->date)->subDay(),
            'updated_at' => Carbon::parse($planning->date),
        ]);
    }

    private function weightedRandomScore(string $name): int
    {
        $hash = crc32($name);
        $bias = abs($hash) % 10;

        if ($bias >= 7) return rand(4, 5);
        if ($bias >= 4) return rand(3, 5);
        if ($bias >= 2) return rand(2, 4);
        return rand(1, 3);
    }
}
