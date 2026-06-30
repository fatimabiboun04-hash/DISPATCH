<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

echo "=== DATABASE AUDIT ===\n\n";

// Foreign keys
$fks = DB::select('SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL', [DB::getDatabaseName()]);
echo "Foreign keys found: " . count($fks) . "\n";

// Orphan checks
$checks = [
    ['plannings', 'user_id', 'users', 'Plannings without users'],
    ['plannings', 'shift_id', 'shifts', 'Plannings without shifts'],
    ['pointages', 'user_id', 'users', 'Pointages without users'],
    ['pointages', 'planning_id', 'plannings', 'Pointages without plannings'],
    ['pauses', 'user_id', 'users', 'Pauses without users'],
    ['pauses', 'planning_id', 'plannings', 'Pauses without plannings'],
    ['tasks', 'user_id', 'users', 'Tasks without users'],
    ['tasks', 'planning_id', 'plannings', 'Tasks without plannings'],
    ['leave_requests', 'user_id', 'users', 'Leave requests without users'],
    ['ratings', 'user_id', 'users', 'Ratings without users'],
    // notifications uses polymorphic notifiable_id/notifiable_type, skip
    ['team_user', 'user_id', 'users', 'team_user without users'],
    ['team_user', 'team_id', 'teams', 'team_user without teams'],
    ['devices', 'user_id', 'users', 'Devices without users'],
];

foreach ($checks as $c) {
    $count = DB::table($c[0])->whereNotIn($c[1], DB::table($c[2])->select('id'))->count();
    echo ($count > 0 ? "⚠ ORPHAN: " : "  OK: ") . $c[3] . " ($count)\n";
}

echo "\n=== ROW COUNTS ===\n";
$tables = ['users','teams','team_user','shifts','plannings','pointages','pauses','tasks','skills','skill_user','ratings','reports','notifications','leave_requests','audit_logs','planning_audits','devices','settings','planning_templates','weekly_snapshots','shift_skill'];
foreach ($tables as $t) {
    $c = DB::table($t)->count();
    echo "  $t: $c rows\n";
}

echo "\n=== DUPLICATE CHECK ===\n";
$dup = DB::select('SELECT email, COUNT(*) as cnt FROM users GROUP BY email HAVING cnt > 1');
echo "Duplicate user emails: " . count($dup) . "\n";

$dupPlannings = DB::select('SELECT user_id, date, COUNT(*) as cnt FROM plannings GROUP BY user_id, date HAVING cnt > 1');
echo "Duplicate plannings (same user+date): " . count($dupPlannings) . "\n";

echo "\n=== HISTORICAL WEEKS IN PLANNINGS ===\n";
$weeks = DB::select('SELECT DISTINCT week_number, year FROM plannings ORDER BY year ASC, week_number ASC');
foreach ($weeks as $w) {
    $count = DB::table('plannings')->where('week_number', $w->week_number)->where('year', $w->year)->count();
    $locked = 0;
    if (Schema::hasColumn('plannings', 'week_locked')) {
        $locked = DB::table('plannings')->where('week_number', $w->week_number)->where('year', $w->year)->where('week_locked', true)->count();
    }
    echo "  W{$w->week_number} {$w->year}: $count assignments" . (Schema::hasColumn('plannings', 'week_locked') ? " ($locked locked)" : "") . "\n";
}

echo "\n=== CONSISTENCY ===\n";
$empWithoutPlanning = DB::table('users')->where('role', 'employee')->whereNotIn('id', function($q) { $q->select('user_id')->from('plannings'); })->count();
echo "Employees without any planning: $empWithoutPlanning\n";

$planningNonEmployees = DB::table('plannings')->whereIn('user_id', function($q) { $q->select('id')->from('users')->where('role', '!=', 'employee'); })->count();
echo "Plannings for non-employees: $planningNonEmployees\n";

echo "\n=== RECENT AUDIT LOGS ===\n";
$logs = DB::table('audit_logs')->orderBy('created_at', 'desc')->limit(5)->get();
foreach ($logs as $l) {
    echo "  [{$l->created_at}] {$l->action} on {$l->entity_type}#{$l->entity_id} by user#{$l->user_id}\n";
}

echo "\n=== COMPLETE ===\n";
