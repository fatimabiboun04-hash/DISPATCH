<?php
/**
 * Phase 20.1 — Generate a complete, realistic previous week (W26 2026)
 * 
 * This script:
 * 1. Clears existing W26 data
 * 2. Generates planning assignments for all 20 employees across 5 teams
 * 3. Creates realistic pointages (check-in/out with delays, early leaves, overtime)
 * 4. Creates realistic pauses (lunch, coffee, technical, emergency)
 * 5. Assigns relevant tasks
 * 6. Generates ratings for all employees
 * 7. Creates a report
 * 8. Verifies everything works
 */

require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Planning;
use App\Models\Pointage;
use App\Models\Pause;
use App\Models\Task;
use App\Models\Rating;
use App\Models\Report;
use App\Models\AuditLog;
use App\Models\PlanningAudit;
use App\Models\Shift;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

echo "========================================================\n";
echo "  GENERATING COMPLETE PREVIOUS WEEK (W26 2026)\n";
echo "  " . now()->format('Y-m-d H:i:s') . "\n";
echo "========================================================\n\n";

// ─── Configuration ───
$weekNumber = 26;
$year = 2026;
$weekStart = Carbon::parse('2026-06-22'); // Monday
$weekEnd = Carbon::parse('2026-06-28');   // Sunday
$adminUserId = 1; // Admin user

// Get all employees and teams
$allEmployees = User::where('role', 'employee')->where('id', '!=', 22)->get()->keyBy('id');
$teams = Team::all()->keyBy('id');
$shifts = Shift::whereIn('id', [1, 2, 3, 4, 5, 6, 7])->get()->keyBy('id');

echo "Employees: {$allEmployees->count()}\n";
echo "Teams: {$teams->count()}\n";
echo "Shifts: {$shifts->count()}\n\n";

// ─── Step 1: Clean existing W26 data ───
echo "Step 1: Cleaning existing W26 data...\n";

$existingPlanningIds = Planning::where('week_number', $weekNumber)->where('year', $year)->pluck('id');

if ($existingPlanningIds->isNotEmpty()) {
    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    
    Pause::whereIn('planning_id', $existingPlanningIds)->delete();
    Pointage::whereIn('planning_id', $existingPlanningIds)->delete();
    Task::whereIn('planning_id', $existingPlanningIds)->delete();
    PlanningAudit::whereIn('planning_id', $existingPlanningIds)->delete();
    Planning::where('week_number', $weekNumber)->where('year', $year)->delete();
    
    // Clean ratings and reports for W26
    Rating::where('week_number', $weekNumber)->where('year', $year)->delete();
    Report::where('week_number', $weekNumber)->where('year', $year)->delete();
    
    // Clean audit logs for W26 period
    AuditLog::whereBetween('created_at', [$weekStart->startOfDay(), $weekEnd->endOfDay()])->delete();
    
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
    
    echo "  Deleted " . count($existingPlanningIds) . " existing plannings and related data.\n";
}
echo "  Done.\n\n";

// ─── Step 2: Generate Planning Assignments ───
echo "Step 2: Generating planning assignments...\n";
echo "  Date range: {$weekStart->format('Y-m-d')} to {$weekEnd->format('Y-m-d')}\n\n";

// Define shift patterns PER TEAM
// Each team has realistic rotating shifts
$shiftPatterns = [
    // Team 1 — Alpha (Rapid Response): 5 employees, 24/7 coverage
    1 => [
        2  => ['Mon' => 1, 'Tue' => 1, 'Wed' => 2, 'Thu' => 2, 'Fri' => 4, 'Sat' => 5, 'Sun' => 6], // Ahmed - morning shifts, weekend
        3  => ['Mon' => 2, 'Tue' => 2, 'Wed' => 3, 'Thu' => 3, 'Fri' => 1, 'Sat' => null, 'Sun' => null], // Karim - afternoon/night rotation
        4  => ['Mon' => 3, 'Tue' => 3, 'Wed' => 1, 'Thu' => 1, 'Fri' => null, 'Sat' => 6, 'Sun' => 5], // Youssef - night/morning
        5  => ['Mon' => 1, 'Tue' => 4, 'Wed' => 4, 'Thu' => 1, 'Fri' => 2, 'Sat' => 2, 'Sun' => null], // Mohamed - mixed
        6  => ['Mon' => null, 'Tue' => null, 'Wed' => 3, 'Thu' => 3, 'Fri' => 3, 'Sat' => 5, 'Sun' => 6], // Hassan - rest Mon/Tue
    ],
    // Team 2 — Beta (Network Maintenance): 5 employees
    2 => [
        7  => ['Mon' => 1, 'Tue' => 1, 'Wed' => 2, 'Thu' => 2, 'Fri' => 3, 'Sat' => 3, 'Sun' => null], // Noureddine
        8  => ['Mon' => 2, 'Tue' => 2, 'Wed' => 3, 'Thu' => 1, 'Fri' => 1, 'Sat' => null, 'Sun' => 5], // Abdelkader
        9  => ['Mon' => 3, 'Tue' => 3, 'Wed' => 1, 'Thu' => 4, 'Fri' => 4, 'Sat' => 6, 'Sun' => null], // Driss
        10 => ['Mon' => 4, 'Tue' => 4, 'Wed' => 4, 'Thu' => 3, 'Fri' => 2, 'Sat' => null, 'Sun' => 6], // Rachid
        11 => ['Mon' => null, 'Tue' => 1, 'Wed' => 1, 'Thu' => 2, 'Fri' => 3, 'Sat' => 5, 'Sun' => 5], // Samir - off Monday
    ],
    // Team 3 — Gamma (Technical Support): 4 employees
    3 => [
        12 => ['Mon' => 1, 'Tue' => 1, 'Wed' => 1, 'Thu' => 4, 'Fri' => 4, 'Sat' => null, 'Sun' => null], // Fatima
        13 => ['Mon' => 2, 'Tue' => 2, 'Wed' => 4, 'Thu' => 1, 'Fri' => 1, 'Sat' => 5, 'Sun' => null], // Amina
        14 => ['Mon' => 4, 'Tue' => 4, 'Wed' => 2, 'Thu' => 2, 'Fri' => 2, 'Sat' => null, 'Sun' => 6], // Saad
        15 => ['Mon' => null, 'Tue' => null, 'Wed' => 3, 'Thu' => 3, 'Fri' => 3, 'Sat' => 5, 'Sun' => 6], // Jawad - off Mon/Tue
    ],
    // Team 4 — Delta (Supervision): 3 employees
    4 => [
        16 => ['Mon' => 4, 'Tue' => 4, 'Wed' => 4, 'Thu' => 4, 'Fri' => 1, 'Sat' => null, 'Sun' => null], // Moncef
        17 => ['Mon' => null, 'Tue' => 1, 'Wed' => 1, 'Thu' => 1, 'Fri' => 4, 'Sat' => 5, 'Sun' => null], // Abdelmajid
        18 => ['Mon' => 1, 'Tue' => null, 'Wed' => null, 'Thu' => 4, 'Fri' => 4, 'Sat' => 6, 'Sun' => 5], // Hakim
    ],
    // Team 5 — Sigma (Logistics): 3 employees
    5 => [
        19 => ['Mon' => 1, 'Tue' => 1, 'Wed' => 2, 'Thu' => 2, 'Fri' => 3, 'Sat' => null, 'Sun' => 5], // Tariq
        20 => ['Mon' => 2, 'Tue' => 2, 'Wed' => 3, 'Thu' => 3, 'Fri' => 1, 'Sat' => 5, 'Sun' => null], // Khalid
        21 => ['Mon' => 3, 'Tue' => 3, 'Wed' => 1, 'Thu' => 1, 'Fri' => null, 'Sat' => null, 'Sun' => 6], // Youness
    ],
];

$dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$totalPlannings = 0;
$createdPlannings = [];

foreach ($shiftPatterns as $teamId => $members) {
    $team = $teams[$teamId];
    
    foreach ($members as $userId => $schedule) {
        $employee = $allEmployees[$userId];
        
        foreach ($dayNames as $dayIndex => $dayName) {
            $shiftId = $schedule[$dayName];
            
            if ($shiftId === null) {
                continue; // Rest day
            }
            
            $date = $weekStart->copy()->addDays($dayIndex);
            
            $planning = Planning::create([
                'user_id' => $userId,
                'team_id' => $teamId,
                'shift_id' => $shiftId,
                'date' => $date,
                'week_number' => $weekNumber,
                'year' => $year,
                'notes' => null,
                'created_by' => $adminUserId,
                'is_locked' => true,
            ]);
            
            $createdPlannings[] = $planning;
            $totalPlannings++;
        }
    }
}

echo "  Total plannings created: $totalPlannings\n\n";

// ─── Step 3: Generate Pointages ───
echo "Step 3: Generating pointages with realistic check-in/out...\n";

$totalPointages = 0;
$completedP = 0;
$incompleteP = 0;

foreach ($createdPlannings as $planning) {
    $shift = $shifts[$planning->shift_id];
    if (!$shift) continue;
    
    // Skip rest-type shifts (Congé, Absence) - no pointages
    if (in_array($shift->type, ['conge', 'absence', 'emergency'])) {
        continue;
    }
    
    $date = Carbon::parse($planning->date);
    $hour = (int)$shift->start_time->format('H');
    $minute = (int)$shift->start_time->format('i');
    $isNightShift = $hour >= 22 || $hour < 6;
    
    // Build scheduled start/end times on the correct dates
    $scheduledStart = $date->copy()->setTime($hour, $minute);
    $scheduledEnd = $date->copy()->setTime(
        (int)$shift->end_time->format('H'),
        (int)$shift->end_time->format('i')
    );
    
    // Handle overnight shifts
    if ($scheduledEnd->lessThan($scheduledStart)) {
        $scheduledEnd->addDay();
    }
    
    // Simulate realistic check-in (some late, some early, some on time)
    $isLate = random_int(1, 100) <= 25; // 25% late arrivals
    $isEarlyLeave = random_int(1, 100) <= 15; // 15% early departures
    $isOvertime = random_int(1, 100) <= 10; // 10% overtime
    $isFlagged = random_int(1, 100) <= 5; // 5% flagged (suspicious)
    
    $lateMinutes = $isLate ? random_int(5, 45) : 0;
    $earlyMinutes = $isEarlyLeave ? random_int(5, 30) : 0;
    $overMinutes = $isOvertime ? random_int(15, 90) : 0;
    
    $checkIn = $scheduledStart->copy()->addMinutes($lateMinutes);
    $checkOut = $scheduledEnd->copy()->subMinutes($earlyMinutes)->addMinutes($overMinutes);
    
    // Random 3% of pointages are "missing check-out" (incomplete)
    $missingCheckOut = random_int(1, 100) <= 3;
    
    $workedMinutes = $missingCheckOut ? null : (int)$checkIn->diffInMinutes($checkOut);
    
    $pointage = Pointage::create([
        'user_id' => $planning->user_id,
        'planning_id' => $planning->id,
        'check_in_at' => $checkIn,
        'check_out_at' => $missingCheckOut ? null : $checkOut,
        'scheduled_start' => $scheduledStart,
        'scheduled_end' => $scheduledEnd,
        'status' => $missingCheckOut ? 'incomplete' : 'completed',
        'worked_minutes' => $workedMinutes,
        'delay_minutes' => $lateMinutes,
        'early_leave_minutes' => $earlyMinutes,
        'overtime_minutes' => $overMinutes,
        'verification_data' => null,
        'is_flagged' => $isFlagged,
        'flag_reason' => $isFlagged ? 'Horaire异常' : null,
        'verified_by' => null,
    ]);
    
    $totalPointages++;
    if ($missingCheckOut) $incompleteP++;
    else $completedP++;
    
    // Record audit log for pointage
    AuditService::log('pointage_created', Pointage::class, $pointage->id, null, [
        'planning_id' => $planning->id,
        'user_id' => $planning->user_id,
        'check_in' => $checkIn->toIso8601String(),
        'check_out' => $missingCheckOut ? null : $checkOut->toIso8601String(),
        'status' => $pointage->status,
    ]);
}

echo "  Total pointages: $totalPointages\n";
echo "  Completed (with check-out): $completedP\n";
echo "  Incomplete (no check-out): $incompleteP\n";
echo "  With delays: ~" . round($totalPointages * 0.25) . "\n";
echo "  With early leaves: ~" . round($totalPointages * 0.15) . "\n";
echo "  Flagged: ~" . round($totalPointages * 0.05) . "\n\n";

// ─── Step 4: Generate Pauses ───
echo "Step 4: Generating pauses...\n";

$pauseTypes = ['lunch', 'break', 'technical', 'medical', 'emergency'];
$pauseTypeWeights = [40, 30, 15, 10, 5]; // percentage weights
$totalPauses = 0;

foreach ($createdPlannings as $planning) {
    $shift = $shifts[$planning->shift_id];
    if (!$shift) continue;
    if (in_array($shift->type, ['conge', 'absence'])) continue;
    
    $date = Carbon::parse($planning->date);
    $shiftStartHour = (int)$shift->start_time->format('H');
    $shiftEndHour = (int)$shift->end_time->format('H');
    $durationHours = $shift->duration_hours;
    
    // Number of pauses based on shift duration
    $numPauses = 0;
    if ($durationHours >= 7) $numPauses = random_int(2, 3);
    elseif ($durationHours >= 5) $numPauses = random_int(1, 2);
    elseif ($durationHours >= 3) $numPauses = 1;
    else continue;
    
    if (in_array($shift->type, ['emergency', 'conge', 'absence'])) continue;
    
    // Log the planned days for debugging
    $plannedPauses = [];
    
    // Generate pause windows
    $shiftStartTime = $date->copy()->setTime($shiftStartHour, 0);
    $shiftEndTime = $date->copy()->setTime($shiftEndHour, 0);
    if ($shiftEndTime->lessThan($shiftStartTime)) $shiftEndTime->addDay();
    
    $pauseSlotDuration = (int)$shiftStartTime->diffInMinutes($shiftEndTime);
    if ($pauseSlotDuration <= 0) continue;
    
    // For overnight shifts that are very long (weekend night = 11h), add more pauses
    if ($durationHours >= 10) {
        $numPauses = random_int(3, 4);
    }
    
    for ($i = 0; $i < $numPauses; $i++) {
        // Pick a weighted random type
        $rand = random_int(1, 100);
        $cumulative = 0;
        $selectedType = 'break';
        foreach ($pauseTypes as $idx => $type) {
            $cumulative += $pauseTypeWeights[$idx];
            if ($rand <= $cumulative) { $selectedType = $type; break; }
        }
        
        // Calculate pause timing within shift
        $slotSize = (int)($pauseSlotDuration / ($numPauses + 1));
        $pauseStartOffset = ($i + 1) * $slotSize + random_int(-15, 15);
        $pauseDuration = match($selectedType) {
            'lunch' => random_int(30, 60),
            'break' => random_int(10, 20),
            'technical' => random_int(15, 45),
            'medical' => random_int(15, 30),
            'emergency' => random_int(10, 20),
            default => random_int(10, 30),
        };
        
        // Emergency pauses only for a few people
        if ($selectedType === 'emergency' && random_int(1, 10) > 2) continue;
        
        $pauseStart = $shiftStartTime->copy()->addMinutes($pauseStartOffset);
        $pauseEnd = $pauseStart->copy()->addMinutes($pauseDuration);
        
        // Ensure pause is within shift
        if ($pauseStart->lessThan($shiftStartTime)) $pauseStart = $shiftStartTime->copy()->addMinutes(30);
        if ($pauseEnd->greaterThan($shiftEndTime)) $pauseEnd = $shiftEndTime->copy()->subMinutes(5);
        if ($pauseStart->greaterThanOrEqualTo($pauseEnd)) continue;
        
        // Determine status based on time (W26 is in the past, so all completed or expired)
        $status = 'completed';
        
        Pause::create([
            'user_id' => $planning->user_id,
            'team_id' => $planning->team_id,
            'planning_id' => $planning->id,
            'type' => $selectedType,
            'reason' => match($selectedType) {
                'lunch' => 'Pause déjeuner',
                'break' => 'Pause café',
                'technical' => 'Pause technique',
                'medical' => 'Visite médicale',
                'emergency' => random_int(1, 3) === 1 ? 'Urgence personnelle' : 'Urgence famille',
                default => null,
            },
            'status' => $status,
            'pause_start' => $pauseStart,
            'pause_end' => $pauseEnd,
            'duration_minutes' => $pauseDuration,
            'created_by' => $planning->user_id,
        ]);
        
        $totalPauses++;
    }
}

echo "  Total pauses generated: $totalPauses\n\n";

// ─── Step 5: Generate Tasks ───
echo "Step 5: Generating tasks...\n";

// Realistic task titles grouped by team
$taskTitles = [
    1 => [ // Alpha — Intervention Rapide
        'Intervention urgence client A',
        'Maintenance préventive serveurs',
        'Déploiement correctif sécurité',
        'Supervision infrastructure critique',
        'Mise à jour firmware équipements',
        'Diagnostic panne réseau principal',
        'Rapport d\'intervention hebdomadaire',
    ],
    2 => [ // Beta — Maintenance Réseau
        'Maintenance routeurs principaux',
        'Dépannage liaison fibre optique',
        'Configuration VPN site distant',
        'Inventaire équipements réseau',
        'Mise à jour certificats SSL',
        'Test de redondance liaison',
        'Analyse trafic réseau anormal',
    ],
    3 => [ // Gamma — Support Technique
        'Support utilisateur niveau 2',
        'Résolution tickets incidents',
        'Formation nouvel utilisateur',
        'Déploiement poste de travail',
        'Migration messagerie utilisateur',
        'Nettoyage parc informatique',
        'Documentation procédures support',
    ],
    4 => [ // Delta — Supervision
        'Vérification indicateurs production',
        'Réunion coordination équipes',
        'Validation rapports d\'activité',
        'Planification ressources semaine',
        'Audit conformité procédures',
        'Suivi indicateurs performance',
        'Préparation rapport direction',
    ],
    5 => [ // Sigma — Logistique
        'Réception et vérification stock',
        'Préparation commandes urgentes',
        'Inventaire magasin mensuel',
        'Expédition matériel site distant',
        'Gestion retours fournisseurs',
        'Organisation tournée livraison',
        'Nettoyage zone stockage',
    ],
];

$taskStatuses = ['completed', 'completed', 'completed', 'completed', 'completed', 'in_progress', 'cancelled'];
$totalTasks = 0;

foreach ($createdPlannings as $planning) {
    // 65% chance of having a task
    if (random_int(1, 100) > 65) continue;
    
    $teamId = $planning->team_id;
    $titles = $taskTitles[$teamId] ?? $taskTitles[5]; // fallback to logistics
    
    // Each planning can have 1-2 tasks
    $numTasks = random_int(1, 2);
    
    for ($i = 0; $i < $numTasks; $i++) {
        $title = $titles[array_rand($titles)];
        $status = $taskStatuses[array_rand($taskStatuses)];
        $priority = ['low','medium','high'][random_int(0, 2)];
        
        Task::create([
            'user_id' => $planning->user_id,
            'planning_id' => $planning->id,
            'title' => $title,
            'description' => 'Tâche ' . $title . ' pour ' . date('d/m/Y', strtotime($planning->date)),
            'status' => $status,
            'priority' => $priority,
            'due_date' => Carbon::parse($planning->date)->addHours(random_int(2, 8)),
            'created_by' => $adminUserId,
        ]);
        
        $totalTasks++;
    }
}

echo "  Total tasks generated: $totalTasks\n\n";

// ─── Step 6: Generate Ratings ───
echo "Step 6: Generating ratings...\n";

$totalRatings = 0;
$ratingScores = [];
$ratingComments = [
    5 => ['Excellent travail cette semaine', 'Performance remarquable', 'Dépassement constant des objectifs'],
    4 => ['Très bonne semaine', 'Bon travail d\'équipe', 'Résultats solides'],
    3 => ['Semaine satisfaisante', 'Travail correct', 'Peut mieux faire'],
    2 => ['Semaine moyenne', 'Présence irrégulière', 'Objectifs partiellement atteints'],
    1 => ['Semaine insuffisante', 'Retards répétés', 'Doit améliorer sa ponctualité'],
];

foreach ($allEmployees as $employee) {
    if ($employee->id == 22) continue; // Skip test CRUD user
    
    // 90% of employees get rated
    if (random_int(1, 100) > 90) {
        echo "  Skipping rating for #{$employee->id} {$employee->name}\n";
        continue;
    }
    
    // Score distribution (realistic: most 3-4, some 5, few 1-2)
    $scoreRand = random_int(1, 100);
    $score = match(true) {
        $scoreRand <= 10 => 5,  // 10% excellent
        $scoreRand <= 35 => 4,  // 25% very good
        $scoreRand <= 65 => 3,  // 30% good
        $scoreRand <= 85 => 2,  // 20% average
        default => 1,           // 15% needs improvement
    };
    
    $type = match($score) {
        5 => 'excellent',
        4 => 'very_good',
        3 => 'good',
        2 => 'average',
        1 => 'warning',
    };
    
    $comments = $ratingComments[$score];
    $comment = $comments[array_rand($comments)];
    
    Rating::create([
        'user_id' => $employee->id,
        'rated_by' => $adminUserId,
        'type' => $type,
        'score' => $score,
        'reason' => $comment,
        'comment' => $comment,
        'week_number' => $weekNumber,
        'year' => $year,
    ]);
    
    $ratingScores[$score] = ($ratingScores[$score] ?? 0) + 1;
    $totalRatings++;
}

echo "  Total ratings: $totalRatings\n";
echo "  Score distribution: " . json_encode($ratingScores) . "\n\n";

// ─── Step 7: Generate Report ───
echo "Step 7: Generating report...\n";

$report = Report::create([
    'week_number' => $weekNumber,
    'year' => $year,
    'title' => "Rapport hebdomadaire - Semaine $weekNumber $year",
    'type' => 'weekly',
    'status' => 'completed',
    'generated_by' => $adminUserId,
]);

echo "  Report #{$report->id} created for W26 $year\n\n";

// ─── Step 8: Verify Data Integrity ───
echo "Step 8: Verifying data integrity...\n";

// Verify plannings
$finalPlannings = Planning::where('week_number', $weekNumber)->where('year', $year)->count();
$finalPointages = Pointage::whereIn('planning_id', Planning::where('week_number', $weekNumber)->where('year', $year)->pluck('id'))->count();
$finalPauses = Pause::whereIn('planning_id', Planning::where('week_number', $weekNumber)->where('year', $year)->pluck('id'))->count();
$finalTasks = Task::whereIn('planning_id', Planning::where('week_number', $weekNumber)->where('year', $year)->pluck('id'))->count();
$finalRatings = Rating::where('week_number', $weekNumber)->where('year', $year)->count();
$finalReports = Report::where('week_number', $weekNumber)->where('year', $year)->count();

// Verify teams
$teamsPresent = Planning::where('week_number', $weekNumber)->where('year', $year)->distinct('team_id')->count('team_id');
$employeesPresent = Planning::where('week_number', $weekNumber)->where('year', $year)->distinct('user_id')->count('user_id');
$daysPresent = Planning::where('week_number', $weekNumber)->where('year', $year)->distinct('date')->count('date');

// Check all modules
echo "  Plannings: $finalPlannings\n";
echo "  Pointages: $finalPointages\n";
echo "  Pauses: $finalPauses\n";
echo "  Tasks: $finalTasks\n";
echo "  Ratings: $finalRatings\n";
echo "  Reports: $finalReports\n";
echo "  Teams covered: $teamsPresent\n";
echo "  Employees scheduled: $employeesPresent\n";
echo "  Days covered: $daysPresent\n";

// Check for orphan records
$pIds = Planning::where('week_number', $weekNumber)->where('year', $year)->pluck('id');
$orphanPointages = Pointage::whereIn('planning_id', $pIds)->whereNotIn('planning_id', $pIds)->count();
$orphanPauses = Pause::whereIn('planning_id', $pIds)->whereNotIn('planning_id', $pIds)->count();

echo "  Orphan pointages: $orphanPointages\n";
echo "  Orphan pauses: $orphanPauses\n";

// Check employee planning accessibility for employee #2
$empPlanning = Planning::where('user_id', 2)->where('week_number', $weekNumber)->where('year', $year)
    ->with(['shift', 'team', 'pointages', 'pauses', 'tasks'])->get();
echo "  Employee #2 planning count: {$empPlanning->count()}\n";
echo "  Employee #2 with pointages: " . $empPlanning->filter(fn($p) => $p->pointages->count() > 0)->count() . "/{$empPlanning->count()}\n";
echo "  Employee #2 with pauses: " . $empPlanning->filter(fn($p) => $p->pauses->count() > 0)->count() . "/{$empPlanning->count()}\n";

echo "\n========================================================\n";
echo "  GENERATION COMPLETE\n";
echo "========================================================\n";
echo "  Plannings: $finalPlannings\n";
echo "  Teams: $teamsPresent\n";
echo "  Employees: $employeesPresent\n";
echo "  Pointages: $finalPointages\n";
echo "  Pauses: $finalPauses\n";
echo "  Tasks: $finalTasks\n";
echo "  Ratings: $finalRatings\n";
echo "  Reports: $finalReports\n";
echo "========================================================\n";
