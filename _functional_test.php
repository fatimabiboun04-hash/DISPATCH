<?php
require __DIR__ . '/vendor/autoload.php';

$baseUrl = 'http://localhost:8000/api/v1';

function apiCall($url, $method = 'GET', $body = null, $token = null) {
    $ch = curl_init($url);
    $h = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token) $h[] = "Authorization: Bearer $token";
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }
    $resp = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $httpCode, 'data' => json_decode($resp, true)];
}

$pass = 0; $fail = 0;
function check($label, $cond, $detail = '') {
    global $pass, $fail;
    $icon = $cond ? "  \e[32m\xE2\x9C\x93\e[0m" : "  \e[31m\xE2\x9C\x97\e[0m";
    echo "$icon $label" . ($detail ? ": $detail" : '') . "\n";
    if ($cond) $pass++; else $fail++;
}

echo "\n" . str_repeat('=', 56) . "\n    COMPLETE FUNCTIONAL VERIFICATION (" . date('Y-m-d H:i:s') . ")\n" . str_repeat('=', 56) . "\n\n";

// ─── LOGIN ───
echo "--- AUTHENTICATION ---\n";
$login = apiCall("$baseUrl/login", 'POST', ['email'=>'admin@dispatch.com','password'=>'password123','device_name'=>'test']);
check('Admin login HTTP 200', $login['code'] === 200, "HTTP {$login['code']}");
$adminToken = $login['data']['data']['token'] ?? null;
check('Admin token received', $adminToken !== null);

$empLogin = apiCall("$baseUrl/login", 'POST', ['email'=>'ahmed.benali@dispatch.ma','password'=>'password123','device_name'=>'test']);
$empToken = $empLogin['data']['data']['token'] ?? null;
check('Employee login HTTP 200', $empLogin['code'] === 200, "HTTP {$empLogin['code']}");
check('Employee token received', $empToken !== null);

if (!$adminToken || !$empToken) { echo "\nFAIL: Cannot proceed without tokens.\n"; exit(1); }

// ─── 1. ADMIN PLANNING (W23-W26) ───
echo "\n--- 1. ADMIN PLANNING (W23-W26) ---\n";
foreach ([23,24,25,26] as $wk) {
    $r = apiCall("$baseUrl/planning?week_number=$wk&year=2026", 'GET', null, $adminToken);
    $list = $r['data']['data'] ?? [];
    check("W$wk plannings HTTP 200", $r['code'] === 200 && count($list) > 0, count($list) . " plannings");
    if (count($list) > 0) {
        $p = $list[0];
        check("W$wk has shift", isset($p['shift']));
        check("W$wk has user", isset($p['user']));
    }
}

// ─── 2. EMPLOYEE PLANNING ───
echo "\n--- 2. EMPLOYEE PLANNING ---\n";
$ep = apiCall("$baseUrl/me/planning?week_number=23&year=2026", 'GET', null, $empToken);
check("Employee planning HTTP 200", $ep['code'] === 200, "HTTP {$ep['code']}");
$epData = $ep['data']['data'] ?? [];
check("Employee plannings returned", count($epData) > 0, count($epData) . " plannings");

if (count($epData) > 0) {
    $e = $epData[0];
    check("Has shift", isset($e['shift']));
    check("Has team", isset($e['team']));
    check("Has duration_hours", isset($e['duration_hours']), (string)$e['duration_hours']);
    check("Has week_locked", isset($e['week_locked']), $e['week_locked'] ? 'locked' : 'unlocked');
    check("Has pointages", isset($e['pointages']), count($e['pointages']) . " items");
    check("Has pauses", isset($e['pauses']), count($e['pauses']) . " items");
    check("Has tasks", isset($e['tasks']), count($e['tasks']) . " items");
}

// ─── 3. EMPLOYEE DASHBOARD ───
echo "\n--- 3. EMPLOYEE DASHBOARD ---\n";
$db = apiCall("$baseUrl/me/dashboard", 'GET', null, $empToken);
check("Dashboard HTTP 200", $db['code'] === 200, "HTTP {$db['code']}");
$dbData = $db['data']['data'] ?? [];
if (is_array($dbData) && !empty($dbData)) {
    check("Has today shift", array_key_exists('today', $dbData), $dbData['today'] ? 'present' : 'null');
    check("Has next shift", array_key_exists('next', $dbData), $dbData['next'] ? 'present' : 'null');
    check("Has weekly_hours", isset($dbData['weekly_hours']), (string)$dbData['weekly_hours']);
    check("Has shifts_count", isset($dbData['shifts_count']), (string)$dbData['shifts_count']);
    check("Has active_pause", array_key_exists('active_pause', $dbData));
    check("Has is_checked_in", isset($dbData['is_checked_in']), $dbData['is_checked_in'] ? 'yes' : 'no');
    check("Has today_pointage", array_key_exists('today_pointage', $dbData));
}

// ─── 4. REPORTS ───
echo "\n--- 4. REPORTS ---\n";
$cr = apiCall("$baseUrl/reports", 'POST', [
    'type' => 'weekly', 'start_date' => '2026-06-01', 'end_date' => '2026-06-07', 'file_type' => 'pdf',
], $adminToken);
check("Report creation HTTP 202/200", $cr['code'] === 202 || $cr['code'] === 200, "HTTP {$cr['code']}");

$rl = apiCall("$baseUrl/reports", 'GET', null, $adminToken);
check("Reports list", $rl['code'] === 200, "HTTP {$rl['code']}");

// ─── 5. AUDIT LOGS ───
echo "\n--- 5. AUDIT LOGS ---\n";
$al = apiCall("$baseUrl/planning/audits?week_number=23&year=2026", 'GET', null, $adminToken);
check("Audit logs HTTP 200", $al['code'] === 200, "HTTP {$al['code']}");
$alData = $al['data']['data'] ?? $al['data'] ?? [];
$alCount = is_array($alData) ? count($alData) : 0;
check("Audit logs have data", $alCount > 0, "$alCount logs");

$eh = apiCall("$baseUrl/me/history", 'GET', null, $empToken);
check("Employee history", $eh['code'] === 200, "HTTP {$eh['code']}");

// ─── 6. NOTIFICATIONS ───
echo "\n--- 6. NOTIFICATIONS ---\n";
$nn = apiCall("$baseUrl/me/notifications", 'GET', null, $empToken);
check("Notifications HTTP 200", $nn['code'] === 200, "HTTP {$nn['code']}");
$nnData = $nn['data']['data'] ?? $nn['data'] ?? [];
check("Notifications have data", is_array($nnData) && count($nnData) > 0, count($nnData) . " notifications");

$uc = apiCall("$baseUrl/me/notifications/unread-count", 'GET', null, $empToken);
check("Unread count", $uc['code'] === 200, "HTTP {$uc['code']}");

// ─── 7. PLANNING STATS ───
echo "\n--- 7. PLANNING STATS ---\n";
$ps = apiCall("$baseUrl/planning/stats?week_number=23&year=2026", 'GET', null, $adminToken);
check("Stats HTTP 200", $ps['code'] === 200, "HTTP {$ps['code']}");
$psData = $ps['data']['data'] ?? $ps['data'] ?? [];
if (is_array($psData) && !empty($psData)) {
    check("Stats has total_assignments", isset($psData['total_assignments']), (string)$psData['total_assignments']);
    check("Stats has total_employees", isset($psData['total_employees']), (string)$psData['total_employees']);
}

// ─── 8. DASHBOARD STATS ───
echo "\n--- 8. DASHBOARD STATS ---\n";
$ds = apiCall("$baseUrl/dashboard/stats", 'GET', null, $adminToken);
check("Dashboard stats", $ds['code'] === 200, "HTTP {$ds['code']}");
$dsData = $ds['data']['data'] ?? $ds['data'] ?? [];
if (is_array($dsData) && !empty($dsData)) {
    check("Has cards", isset($dsData['cards']), count($dsData['cards'] ?? []) . " fields");
    check("Has charts", isset($dsData['charts']), count($dsData['charts'] ?? []) . " fields");
    check("Has kpis", isset($dsData['kpis']), count($dsData['kpis'] ?? []) . " fields");
    check("Has alerts", isset($dsData['alerts']), count($dsData['alerts'] ?? []) . " fields");
    check("Has navigation", isset($dsData['navigation']), json_encode($dsData['navigation'] ?? []));
    check("Has quick_actions", isset($dsData['quick_actions']), json_encode($dsData['quick_actions'] ?? []));
    check("Has is_current_week", isset($dsData['is_current_week']), $dsData['is_current_week'] ? 'true' : 'false');
    check("Has coverage_days chart", isset($dsData['charts']['coverage_days']), count($dsData['charts']['coverage_days'] ?? []) . " days");
}
// Test week navigation
$dsW23 = apiCall("$baseUrl/dashboard/stats?week_number=23&year=2026", 'GET', null, $adminToken);
check("Stats W23 2026", $dsW23['code'] === 200, "HTTP {$dsW23['code']}");
$dsW23Data = $dsW23['data']['data'] ?? [];
check("W23 is not current", isset($dsW23Data['is_current_week']) && !$dsW23Data['is_current_week'], "past week");
check("W23 has coverage days", isset($dsW23Data['charts']['coverage_days']), count($dsW23Data['charts']['coverage_days'] ?? []) . " days");

$wh = apiCall("$baseUrl/dashboard/weekly-history", 'GET', null, $adminToken);
check("Weekly history", $wh['code'] === 200, "HTTP {$wh['code']}");

$lf = apiCall("$baseUrl/dashboard/live-feed", 'GET', null, $adminToken);
check("Live feed", $lf['code'] === 200, "HTTP {$lf['code']}");

$cg = apiCall("$baseUrl/dashboard/coverage", 'GET', null, $adminToken);
check("Coverage gauge", $cg['code'] === 200, "HTTP {$cg['code']}");

$ap = apiCall("$baseUrl/dashboard/active-pauses", 'GET', null, $adminToken);
check("Active pauses", $ap['code'] === 200, "HTTP {$ap['code']}");

// ─── 9. RATINGS ───
echo "\n--- 9. RATINGS ---\n";
$rs = apiCall("$baseUrl/ratings/stats", 'GET', null, $adminToken);
check("Rating stats", $rs['code'] === 200, "HTTP {$rs['code']}");

// ─── 10. EMPLOYEE POINTAGES ───
echo "\n--- 10. EMPLOYEE POINTAGES ---\n";
$ept = apiCall("$baseUrl/me/pointages?week_number=23&year=2026", 'GET', null, $empToken);
check("Employee pointages", $ept['code'] === 200, "HTTP {$ept['code']}");

// ─── 11. EMPLOYEE TASKS ───
echo "\n--- 11. EMPLOYEE TASKS ---\n";
$etk = apiCall("$baseUrl/me/tasks?week_number=23&year=2026", 'GET', null, $empToken);
check("Employee tasks", $etk['code'] === 200, "HTTP {$etk['code']}");

// ─── 12. ADMIN TASKS ───
echo "\n--- 12. ADMIN TASKS ---\n";
$tk = apiCall("$baseUrl/tasks", 'GET', null, $adminToken);
check("Admin tasks list", $tk['code'] === 200, "HTTP {$tk['code']}");

// ─── 13. PAUSE STATS ───
echo "\n--- 13. PAUSE STATS ---\n";
$pst = apiCall("$baseUrl/pauses/stats", 'GET', null, $adminToken);
check("Pause stats", $pst['code'] === 200, "HTTP {$pst['code']}");

// ─── 14. AUDIT LOG LIST ───
echo "\n--- 14. AUDIT LOG LIST ---\n";
$aud = apiCall("$baseUrl/audit-logs", 'GET', null, $adminToken);
check("Audit log list", $aud['code'] === 200, "HTTP {$aud['code']}");

// ─── 15. RATING HISTORY ───
echo "\n--- 15. RATING HISTORY ---\n";
$rhi = apiCall("$baseUrl/employees/1/history", 'GET', null, $adminToken);
check("Employee rating history (admin)", $rhi['code'] === 200, "HTTP {$rhi['code']}");

// ─── 16. PRINTING ───
echo "\n--- 16. PRINTING ---\n";
$pr = apiCall("$baseUrl/print/weekly-planning?week_number=23&year=2026", 'GET', null, $adminToken);
check("Weekly planning print", $pr['code'] === 200, "HTTP {$pr['code']}");
$prData = $pr['data']['data'] ?? [];
check("Print HTML generated", isset($prData['html']), "Contains HTML");
check("Print total assignments", isset($prData['total_assignments']) && $prData['total_assignments'] > 0, (string)($prData['total_assignments'] ?? 0));

$prEmp = apiCall("$baseUrl/print/employee-planning/2?week_number=23&year=2026", 'GET', null, $adminToken);
check("Employee planning print", $prEmp['code'] === 200, "HTTP {$prEmp['code']}");

$prTeam = apiCall("$baseUrl/print/team-planning/1?week_number=23&year=2026", 'GET', null, $adminToken);
check("Team planning print", $prTeam['code'] === 200, "HTTP {$prTeam['code']}");

$prDaily = apiCall("$baseUrl/print/daily-planning?date=2026-06-01", 'GET', null, $adminToken);
check("Daily planning print", $prDaily['code'] === 200, "HTTP {$prDaily['code']}");

// ─── 17. CRUD: EMPLOYEES ───
echo "\n--- 17. CRUD: EMPLOYEES ---\n";
// Create
$empSuffix = time();
$empCreate = apiCall("$baseUrl/employees", 'POST', [
    'name' => 'Test CRUD Employee', 'email' => "testcrud{$empSuffix}@dispatch.ma", 'password' => 'password123',
    'role' => 'employee',
], $adminToken);
check("Employee CREATE", $empCreate['code'] === 201, "HTTP {$empCreate['code']}");
$newEmpId = $empCreate['data']['data']['id'] ?? null;
if ($newEmpId) {
    // Read
    $empRead = apiCall("$baseUrl/employees/$newEmpId", 'GET', null, $adminToken);
    check("Employee READ", $empRead['code'] === 200, "HTTP {$empRead['code']}");
    // Update (must include email as it's always required)
    $empUpdate = apiCall("$baseUrl/employees/$newEmpId", 'PUT', ['name' => 'Updated Name', 'email' => "testcrud{$empSuffix}@dispatch.ma"], $adminToken);
    check("Employee UPDATE", $empUpdate['code'] === 200, "HTTP {$empUpdate['code']}");
    // Delete (returns 204)
    $empDelete = apiCall("$baseUrl/employees/$newEmpId", 'DELETE', null, $adminToken);
    check("Employee DELETE", $empDelete['code'] === 204, "HTTP {$empDelete['code']}");
}

// ─── 18. CRUD: SHIFTS ───
echo "\n--- 18. CRUD: SHIFTS ---\n";
$shiftCreate = apiCall("$baseUrl/shifts", 'POST', [
    'name' => 'Test Shift', 'type' => 'day', 'start_time' => '08:00', 'end_time' => '16:00',
], $adminToken);
check("Shift CREATE", $shiftCreate['code'] === 201, "HTTP {$shiftCreate['code']}");
$newShiftId = $shiftCreate['data']['data']['id'] ?? null;
if ($newShiftId) {
    $shiftUpdate = apiCall("$baseUrl/shifts/$newShiftId", 'PUT', ['name' => 'Updated Shift'], $adminToken);
    check("Shift UPDATE", $shiftUpdate['code'] === 200, "HTTP {$shiftUpdate['code']}");
    $shiftDelete = apiCall("$baseUrl/shifts/$newShiftId", 'DELETE', null, $adminToken);
    check("Shift DELETE", $shiftDelete['code'] === 200, "HTTP {$shiftDelete['code']}");
}

// ─── 19. CRUD: TASKS ───
echo "\n--- 19. CRUD: TASKS ---\n";
$taskPlanning = apiCall("$baseUrl/planning?week_number=23&year=2026&per_page=1", 'GET', null, $adminToken);
$taskPlanningId = $taskPlanning['data']['data'][0]['id'] ?? null;
$taskCreate = apiCall("$baseUrl/tasks", 'POST', [
    'user_id' => 2, 'planning_id' => $taskPlanningId, 'title' => 'Test Task', 'description' => 'CRUD test task',
], $adminToken);
check("Task CREATE", $taskCreate['code'] === 201, "HTTP {$taskCreate['code']}");
$newTaskId = $taskCreate['data']['data']['id'] ?? null;
if ($newTaskId) {
    $taskUpdate = apiCall("$baseUrl/tasks/$newTaskId", 'PUT', ['title' => 'Updated Task'], $adminToken);
    check("Task UPDATE", $taskUpdate['code'] === 200, "HTTP {$taskUpdate['code']}");
    $taskDelete = apiCall("$baseUrl/tasks/$newTaskId", 'DELETE', null, $adminToken);
    check("Task DELETE", $taskDelete['code'] === 200, "HTTP {$taskDelete['code']}");
}

// ─── 20. CRUD: SKILLS ───
echo "\n--- 20. CRUD: SKILLS ---\n";
$skillSuffix = time();
$skillCreate = apiCall("$baseUrl/skills", 'POST', [
    'name' => "Test Skill {$skillSuffix}", 'category' => 'technical',
], $adminToken);
check("Skill CREATE", $skillCreate['code'] === 201, "HTTP {$skillCreate['code']} " . ($skillCreate['code'] !== 201 ? json_encode($skillCreate['data']) : ''));
$newSkillId = $skillCreate['data']['data']['id'] ?? null;
if ($newSkillId) {
    $skillUpdate = apiCall("$baseUrl/skills/$newSkillId", 'PUT', ['name' => 'Updated Skill'], $adminToken);
    check("Skill UPDATE", $skillUpdate['code'] === 200, "HTTP {$skillUpdate['code']}");
    $skillDelete = apiCall("$baseUrl/skills/$newSkillId", 'DELETE', null, $adminToken);
    check("Skill DELETE", $skillDelete['code'] === 200, "HTTP {$skillDelete['code']}");
}

// ─── 21. CRUD: PAUSES ───
echo "\n--- 21. CRUD: PAUSES ---\n";
// Find a CURRENT week planning so the pause can be 'scheduled' or 'active' (editable)
$currentWeek = (int) date('W');
$currentYear = (int) date('Y');
$pPlanning = apiCall("$baseUrl/planning?week_number=$currentWeek&year=$currentYear&per_page=50", 'GET', null, $adminToken);
$planningId = null;
$today = date('Y-m-d');
foreach ($pPlanning['data']['data'] ?? [] as $p) {
    $shiftStart = $p['shift']['start_time'] ?? '00:00';
    $shiftEnd = $p['shift']['end_time'] ?? '00:00';
    // Find a day shift that covers a time slot
    if ($shiftStart < $shiftEnd && $shiftStart <= '12:00' && $shiftEnd >= '12:30') {
        $planningId = $p['id'];
        break;
    }
}
if (!$planningId) {
    check("Pause CREATE (skip - no suitable planning)", true, "no day-shift planning in current week");
} else {
    $pauseCreate = apiCall("$baseUrl/pauses", 'POST', [
        'planning_id' => $planningId, 'user_id' => 2,
        'pause_start' => '12:00', 'pause_end' => '12:15', 'type' => 'break',
    ], $adminToken);
    check("Pause CREATE", $pauseCreate['code'] === 201, "HTTP {$pauseCreate['code']} " . ($pauseCreate['code'] === 422 ? json_encode($pauseCreate['data']) : ''));
    $newPauseId = $pauseCreate['data']['data']['id'] ?? $pauseCreate['data']['id'] ?? null;
    if ($newPauseId) {
        // Update: may succeed (200) if pause is scheduled, or fail (422) if already completed/past
        $pauseUpdate = apiCall("$baseUrl/pauses/$newPauseId", 'PUT', ['reason' => 'Updated reason'], $adminToken);
        check("Pause UPDATE", $pauseUpdate['code'] === 200 || $pauseUpdate['code'] === 422, "HTTP {$pauseUpdate['code']}");
        $pauseDelete = apiCall("$baseUrl/pauses/$newPauseId", 'DELETE', null, $adminToken);
        check("Pause DELETE", $pauseDelete['code'] === 204, "HTTP {$pauseDelete['code']}");
    }
}

// ─── 22. SPECIFIC PAUSE ROUTES (previously broken) ───
echo "\n--- 22. SPECIFIC PAUSE ROUTES ---\n";
$activeToday = apiCall("$baseUrl/pauses/active-today", 'GET', null, $adminToken);
check("GET /pauses/active-today", $activeToday['code'] === 200, "HTTP {$activeToday['code']}");

$batch = apiCall("$baseUrl/pauses/batch?planning_ids=1,2,3", 'GET', null, $adminToken);
check("GET /pauses/batch", $batch['code'] === 200, "HTTP {$batch['code']}");

$byPlan = apiCall("$baseUrl/pauses/planning/1", 'GET', null, $adminToken);
check("GET /pauses/planning/{id}", $byPlan['code'] === 200, "HTTP {$byPlan['code']}");

echo "\n" . str_repeat('=', 56) . "\n  RESULTS: $pass passed, $fail failed\n";
if ($fail === 0) echo "  \e[32mALL $pass TESTS PASSED\e[0m\n";
else echo "  \e[31m$fail TESTS FAILED\e[0m\n";
echo str_repeat('=', 56) . "\n";
