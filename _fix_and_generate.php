<?php
/**
 * Phase 20.1 — Fix and regenerate complete previous week (W26 2026)
 * 
 * Handles all ENUM constraints properly.
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

echo "================================================================\n";
echo "  GENERATING COMPLETE PREVIOUS WEEK (W26 2026)\n";
echo "  " . now()->format('Y-m-d H:i:s') . "\n";
echo "================================================================\n\n";

$weekNumber = 26;
$year = 2026;
$weekStart = Carbon::parse('2026-06-22');
$weekEnd = Carbon::parse('2026-06-28');
$adminId = 1;

$employees = User::where('role', 'employee')->where('id', '!=', 22)->get()->keyBy('id');
$teams = Team::all()->keyBy('id');
$shifts = Shift::whereIn('id', [1, 2, 3, 4, 5, 6])->get()->keyBy('id');

echo "Employees: {$employees->count()}\n";
echo "Teams: {$teams->count()}\n";
echo "Shifts: {$shifts->count()}\n\n";

// ─── Step 1: Clean ───
echo "Step 1: Cleaning existing W26 data...\n";

$existingIds = Planning::where('week_number', $weekNumber)->where('year', $year)->pluck('id');
if ($existingIds->isNotEmpty()) {
    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    Pause::whereIn('planning_id', $existingIds)->delete();
    Pointage::whereIn('planning_id', $existingIds)->delete();
    Task::whereIn('planning_id', $existingIds)->delete();
    PlanningAudit::whereIn('planning_id', $existingIds)->delete();
    Planning::where('week_number', $weekNumber)->where('year', $year)->delete();
    Rating::where('week_number', $weekNumber)->where('year', $year)->delete();
    Report::where('week_number', $weekNumber)->where('year', $year)->delete();
    AuditLog::whereBetween('created_at', [$weekStart->startOfDay(), $weekEnd->endOfDay()])->delete();
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
    echo "  Deleted " . count($existingIds) . " existing plannings.\n";
}
echo "  Done.\n\n";

// ─── Step 2: Planning Assignments ───
echo "Step 2: Generating planning assignments...\n";

$dayNames = ['Mon' => 0, 'Tue' => 1, 'Wed' => 2, 'Thu' => 3, 'Fri' => 4, 'Sat' => 5, 'Sun' => 6];

$patterns = [
    // Team 1 — Alpha
    1 => [
        2  => [0=>1, 1=>1, 2=>4, 3=>4, 4=>2, 5=>5, 6=>null],      // Ahmed
        3  => [0=>2, 1=>2, 2=>3, 3=>3, 4=>1, 5=>null, 6=>null],    // Karim
        4  => [0=>3, 1=>3, 2=>1, 3=>1, 4=>null, 5=>6, 6=>5],       // Youssef
        5  => [0=>1, 1=>4, 2=>4, 3=>2, 4=>2, 5=>5, 6=>null],       // Mohamed
        6  => [0=>null, 1=>null, 2=>3, 3=>3, 4=>3, 5=>6, 6=>6],    // Hassan
    ],
    // Team 2 — Beta
    2 => [
        7  => [0=>1, 1=>1, 2=>2, 3=>2, 4=>3, 5=>5, 6=>null],
        8  => [0=>2, 1=>2, 2=>3, 3=>1, 4=>1, 5=>null, 6=>5],
        9  => [0=>3, 1=>3, 2=>1, 3=>4, 4=>4, 5=>6, 6=>null],
        10 => [0=>4, 1=>4, 2=>4, 3=>3, 4=>2, 5=>null, 6=>6],
        11 => [0=>null, 1=>1, 2=>1, 3=>2, 4=>3, 5=>5, 6=>5],
    ],
    // Team 3 — Gamma
    3 => [
        12 => [0=>1, 1=>1, 2=>1, 3=>4, 4=>4, 5=>null, 6=>null],
        13 => [0=>2, 1=>2, 2=>4, 3=>1, 4=>1, 5=>5, 6=>null],
        14 => [0=>4, 1=>4, 2=>2, 3=>2, 4=>2, 5=>null, 6=>6],
        15 => [0=>null, 1=>null, 2=>3, 3=>3, 4=>3, 5=>5, 6=>6],
    ],
    // Team 4 — Delta
    4 => [
        16 => [0=>4, 1=>4, 2=>4, 3=>4, 4=>1, 5=>null, 6=>null],
        17 => [0=>null, 1=>1, 2=>1, 3=>1, 4=>4, 5=>5, 6=>null],
        18 => [0=>1, 1=>null, 2=>null, 3=>4, 4=>4, 5=>6, 6=>5],
    ],
    // Team 5 — Sigma
    5 => [
        19 => [0=>1, 1=>1, 2=>2, 3=>2, 4=>3, 5=>null, 6=>5],
        20 => [0=>2, 1=>2, 2=>3, 3=>3, 4=>1, 5=>5, 6=>null],
        21 => [0=>3, 1=>3, 2=>1, 3=>1, 4=>null, 5=>null, 6=>6],
    ],
];

$allPlannings = [];

foreach ($patterns as $teamId => $members) {
    foreach ($members as $userId => $days) {
        foreach ($days as $dayIdx => $shiftId) {
            if ($shiftId === null) continue;
            $date = $weekStart->copy()->addDays($dayIdx);
            $p = Planning::create([
                'user_id' => $userId,
                'team_id' => $teamId,
                'shift_id' => $shiftId,
                'date' => $date,
                'week_number' => $weekNumber,
                'year' => $year,
                'created_by' => $adminId,
                'is_locked' => true,
            ]);
            $allPlannings[] = $p;
        }
    }
}

echo "  Created: " . count($allPlannings) . " plannings\n\n";

// ─── Step 3: Pointages ───
echo "Step 3: Generating pointages...\n";

$totalP = 0;
$withDelay = 0;
$withEarly = 0;
$flagged = 0;

foreach ($allPlannings as $planning) {
    $shift = $shifts[$planning->shift_id] ?? null;
    if (!$shift || in_array($shift->type, ['conge', 'absence'])) continue;

    $date = Carbon::parse($planning->date);
    
    // Build scheduled times
    $schedStart = $date->copy()->setTime(
        (int)$shift->start_time->format('H'),
        (int)$shift->start_time->format('i')
    );
    $schedEnd = $date->copy()->setTime(
        (int)$shift->end_time->format('H'),
        (int)$shift->end_time->format('i')
    );
    if ($schedEnd->lessThan($schedStart)) $schedEnd->addDay();

    // Determine check-in behavior
    $rand = random_int(1, 100);
    if ($rand <= 5) {
        // No show
        $status = 'no_show';
        $checkIn = null;
        $checkOut = null;
        $worked = null;
        $delay = 0;
        $early = 0;
        $overtime = 0;
        $isFlagged = false;
        $flagReason = null;
    } elseif ($rand <= 20) {
        // Late arrival
        $delay = random_int(5, 45);
        $checkIn = $schedStart->copy()->addMinutes($delay);
        $rand2 = random_int(1, 100);
        $early = ($rand2 <= 20) ? random_int(5, 25) : 0;
        $overtime = 0;
        $checkOut = $schedEnd->copy()->subMinutes($early);
        $worked = (int)$checkIn->diffInMinutes($checkOut);
        $isFlagged = ($delay > 30) ? (random_int(1, 100) <= 50) : false;
        $flagReason = $isFlagged ? 'Retard significatif' : null;
        $status = 'late';
        $withDelay++;
    } elseif ($rand <= 35) {
        // Early leave
        $early = random_int(5, 30);
        $delay = 0;
        $checkIn = $schedStart;
        $checkOut = $schedEnd->copy()->subMinutes($early);
        $worked = (int)$checkIn->diffInMinutes($checkOut);
        $isFlagged = false;
        $flagReason = null;
        $status = 'early_leave';
        $withEarly++;
    } elseif ($rand <= 40) {
        // Flagged (suspicious check-in/out)
        $isFlagged = true;
        $delay = 0;
        $early = 0;
        $checkIn = $schedStart;
        $checkOut = $schedEnd;
        $worked = (int)$checkIn->diffInMinutes($checkOut);
        $flagReason = 'Pointage anormal détecté';
        $status = 'flagged';
        $flagged++;
    } else {
        // On time
        $delay = 0;
        $early = 0;
        $checkIn = $schedStart;
        $checkOut = $schedEnd;
        $worked = (int)$checkIn->diffInMinutes($checkOut);
        $isFlagged = false;
        $flagReason = null;
        $status = 'on_time';
    }
    
    // 2% chance of overtime
    $overtime = 0;
    if (random_int(1, 100) <= 2 && $status === 'on_time') {
        $overtime = random_int(15, 90);
        $checkOut = $checkOut->copy()->addMinutes($overtime);
        $worked = (int)$checkIn->diffInMinutes($checkOut);
    }

    Pointage::create([
        'user_id' => $planning->user_id,
        'planning_id' => $planning->id,
        'check_in_at' => $checkIn,
        'check_out_at' => $checkOut,
        'scheduled_start' => $schedStart,
        'scheduled_end' => $schedEnd,
        'status' => $status,
        'worked_minutes' => $worked,
        'delay_minutes' => $delay,
        'early_leave_minutes' => $early,
        'overtime_minutes' => $overtime,
        'is_flagged' => $isFlagged,
        'flag_reason' => $flagReason,
    ]);

    $totalP++;
}

echo "  Total: $totalP\n";
echo "  Late arrivals: $withDelay\n";
echo "  Early leaves: $withEarly\n";
echo "  Flagged: $flagged\n\n";

// ─── Step 4: Pauses ───
echo "Step 4: Generating pauses...\n";

$pauseTypeDefs = [
    'break'    => ['weight' => 30, 'min' => 10, 'max' => 20, 'reason' => 'Pause café'],
    'lunch'    => ['weight' => 35, 'min' => 30, 'max' => 60, 'reason' => 'Pause déjeuner'],
    'technical'=> ['weight' => 20, 'min' => 15, 'max' => 45, 'reason' => 'Pause technique'],
    'medical'  => ['weight' => 10, 'min' => 15, 'max' => 30, 'reason' => 'Visite médicale'],
    'emergency'=> ['weight' => 5,  'min' => 10, 'max' => 20, 'reason' => 'Urgence'],
];

$totalPauses = 0;

foreach ($allPlannings as $planning) {
    $shift = $shifts[$planning->shift_id] ?? null;
    if (!$shift || in_array($shift->type, ['conge', 'absence'])) continue;

    $date = Carbon::parse($planning->date);
    $schedStart = $date->copy()->setTime((int)$shift->start_time->format('H'), (int)$shift->start_time->format('i'));
    $schedEnd = $date->copy()->setTime((int)$shift->end_time->format('H'), (int)$shift->end_time->format('i'));
    if ($schedEnd->lessThan($schedStart)) $schedEnd->addDay();

    $hours = (int)$schedStart->diffInHours($schedEnd);
    $numPauses = $hours >= 8 ? random_int(2, 3) : ($hours >= 5 ? random_int(1, 2) : ($hours >= 3 ? 1 : 0));
    if ($numPauses === 0) continue;

    $slotMinutes = (int)$schedStart->diffInMinutes($schedEnd);
    if ($slotMinutes <= 0) continue;

    for ($i = 0; $i < $numPauses; $i++) {
        // Weighted random type
        $r = random_int(1, 100);
        $cum = 0;
        $selectedType = 'break';
        foreach ($pauseTypeDefs as $t => $def) {
            $cum += $def['weight'];
            if ($r <= $cum) { $selectedType = $t; break; }
        }

        $def = $pauseTypeDefs[$selectedType];
        $pauseOffset = ($i + 1) * (int)($slotMinutes / ($numPauses + 1)) + random_int(-10, 10);
        $pauseDuration = random_int($def['min'], $def['max']);

        $pauseStart = $schedStart->copy()->addMinutes(max(10, $pauseOffset));
        $pauseEnd = $pauseStart->copy()->addMinutes($pauseDuration);

        if ($pauseEnd->greaterThan($schedEnd)) $pauseEnd = $schedEnd->copy()->subMinutes(5);
        if ($pauseStart->greaterThanOrEqualTo($pauseEnd)) continue;

        Pause::create([
            'user_id' => $planning->user_id,
            'team_id' => $planning->team_id,
            'planning_id' => $planning->id,
            'type' => $selectedType,
            'reason' => $def['reason'],
            'status' => 'completed',
            'pause_start' => $pauseStart,
            'pause_end' => $pauseEnd,
            'duration_minutes' => $pauseDuration,
            'created_by' => $planning->user_id,
        ]);
        $totalPauses++;
    }
}

echo "  Total: $totalPauses\n\n";

// ─── Step 5: Tasks ───
echo "Step 5: Generating tasks...\n";

$taskTitles = [
    1 => ['Intervention urgence client','Maintenance préventive serveurs','Déploiement correctif sécurité','Supervision infrastructure','Mise à jour firmware','Diagnostic panne réseau','Rapport d\'intervention'],
    2 => ['Maintenance routeurs','Dépannage fibre optique','Configuration VPN site distant','Inventaire équipements réseau','Mise à jour certificats SSL','Test redondance liaison','Analyse trafic réseau'],
    3 => ['Support utilisateur N2','Résolution tickets incidents','Formation nouvel utilisateur','Déploiement poste travail','Migration messagerie','Nettoyage parc informatique','Documentation procédures'],
    4 => ['Vérification indicateurs','Réunion coordination','Validation rapports activité','Planification ressources','Audit conformité','Suivi KPIs','Préparation rapport direction'],
    5 => ['Réception vérification stock','Préparation commandes urgentes','Inventaire magasin','Expédition matériel distant','Gestion retours','Organisation tournée livraison','Nettoyage zone stockage'],
];

$statuses = ['completed', 'completed', 'completed', 'completed', 'completed', 'in_progress', 'cancelled'];
$priorities = ['low', 'medium', 'medium', 'high'];
$totalTasks = 0;

foreach ($allPlannings as $planning) {
    if (random_int(1, 100) > 70) continue;
    $titles = $taskTitles[$planning->team_id] ?? $taskTitles[5];
    $n = random_int(1, random_int(1, 2));

    for ($i = 0; $i < $n; $i++) {
        $dueDate = Carbon::parse($planning->date)->addHours(random_int(2, 8));
        Task::create([
            'user_id' => $planning->user_id,
            'planning_id' => $planning->id,
            'title' => $titles[array_rand($titles)],
            'description' => 'Tâche planifiée pour le ' . $planning->date->format('d/m/Y'),
            'status' => $statuses[array_rand($statuses)],
            'priority' => $priorities[array_rand($priorities)],
            'due_date' => $dueDate,
            'created_by' => $adminId,
        ]);
        $totalTasks++;
    }
}

echo "  Total: $totalTasks\n\n";

// ─── Step 6: Ratings ───
echo "Step 6: Generating ratings...\n";

$totalRatings = 0;
$dist = [];
$comments = [
    5 => ['Excellent travail cette semaine', 'Performance remarquable', 'Dépassement constant des objectifs'],
    4 => ['Très bonne semaine', 'Bon travail d\'équipe', 'Résultats solides'],
    3 => ['Semaine satisfaisante', 'Travail correct', 'Peut mieux faire'],
    2 => ['Semaine moyenne', 'Présence irrégulière', 'Objectifs partiellement atteints'],
    1 => ['Semaine insuffisante', 'Retards répétés', 'Doit améliorer sa ponctualité'],
];

// ENUM type only allows 'excellent' and 'warning'
$ratingTypeMap = [5 => 'excellent', 4 => 'excellent', 3 => 'excellent', 2 => 'warning', 1 => 'warning'];

foreach ($employees as $emp) {
    if (random_int(1, 100) > 90) continue; // 10% unrated
    
    // Realistic score distribution
    $r = random_int(1, 100);
    $score = match(true) { $r <= 10 => 5, $r <= 35 => 4, $r <= 70 => 3, $r <= 88 => 2, default => 1 };
    
    $type = $ratingTypeMap[$score];
    $comment = $comments[$score][array_rand($comments[$score])];
    
    Rating::create([
        'user_id' => $emp->id,
        'rated_by' => $adminId,
        'type' => $type,
        'score' => $score,
        'reason' => $comment,
        'comment' => $comment,
        'week_number' => $weekNumber,
        'year' => $year,
    ]);
    
    $dist[$score] = ($dist[$score] ?? 0) + 1;
    $totalRatings++;
}

echo "  Total: $totalRatings\n";
echo "  Distribution: " . json_encode($dist) . "\n\n";

// ─── Step 7: Report ───
echo "Step 7: Creating report...\n";

Report::create([
    'week_number' => $weekNumber,
    'year' => $year,
    'type' => 'weekly',
    'start_date' => $weekStart->toDateString(),
    'end_date' => $weekEnd->toDateString(),
    'file_type' => 'pdf',
    'status' => 'completed',
    'generated_by' => $adminId,
]);

echo "  Report created.\n\n";

// ─── Step 8: Verify ───
echo "Step 8: Verification...\n";

$pIds = Planning::where('week_number', $weekNumber)->where('year', $year)->pluck('id');
$finalPlannings = $pIds->count();
$finalPointages = Pointage::whereIn('planning_id', $pIds)->count();
$finalPauses = Pause::whereIn('planning_id', $pIds)->count();
$finalTasks = Task::whereIn('planning_id', $pIds)->count();
$finalRatings = Rating::where('week_number', $weekNumber)->where('year', $year)->count();
$finalReports = Report::where('week_number', $weekNumber)->where('year', $year)->count();
$teamsCovered = Planning::where('week_number', $weekNumber)->where('year', $year)->distinct('team_id')->count('team_id');
$employeesScheduled = Planning::where('week_number', $weekNumber)->where('year', $year)->distinct('user_id')->count('user_id');
$daysCovered = Planning::where('week_number', $weekNumber)->where('year', $year)->distinct('date')->count('date');

// Check employee access
$emp = $employees->first();
$empW26 = Planning::where('user_id', $emp->id)->where('week_number', $weekNumber)->where('year', $year)
    ->with(['shift', 'team', 'pointages', 'pauses', 'tasks'])->get();

echo "\n============================================================\n";
echo "  GENERATION COMPLETE — FINAL REPORT\n";
echo "============================================================\n";
echo "  Planning records:       $finalPlannings\n";
echo "  Teams scheduled:        $teamsCovered\n";
echo "  Employees scheduled:    $employeesScheduled\n";
echo "  Days covered:           $daysCovered\n";
echo "  Pointages:              $finalPointages\n";
echo "  Pauses:                 $finalPauses\n";
echo "  Tasks:                  $finalTasks\n";
echo "  Ratings:                $finalRatings\n";
echo "  Reports:                $finalReports\n";
echo "------------------------------------------\n";
echo "  Employee: {$emp->name} — {$empW26->count()}/7 days\n";
echo "    With pointages: {$empW26->filter(fn($p) => $p->pointages->count() > 0)->count()}\n";
echo "    With pauses:    {$empW26->filter(fn($p) => $p->pauses->count() > 0)->count()}\n";
echo "    With tasks:     {$empW26->filter(fn($p) => $p->tasks->count() > 0)->count()}\n";
echo "============================================================\n";
