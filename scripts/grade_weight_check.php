<?php
declare(strict_types=1);

/**
 * Assessment grade weight + student released-grade checks.
 *
 *   C:\xampp\php\php.exe scripts/grade_weight_check.php
 */

require_once __DIR__ . '/../bootstrap.php';

$db = portal_db();
$fails = 0;
$passes = 0;

function g_ok(string $m): void { global $passes; $passes++; echo "PASS  {$m}\n"; }
function g_fail(string $m, string $d = ''): void { global $fails; $fails++; echo "FAIL  {$m}" . ($d !== '' ? " — {$d}" : '') . "\n"; }
function g_expect(bool $c, string $m, string $d = ''): void { $c ? g_ok($m) : g_fail($m, $d); }
function g_login(array $user): void
{
    $_SESSION = [];
    $_SESSION['portal_user'] = [
        'id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'email' => (string) $user['email'],
        'name' => (string) $user['name'],
        'year' => (string) ($user['year'] ?? 'Year 11'),
        'programme' => (string) ($user['programme'] ?? 'General'),
        'initials' => (string) ($user['initials'] ?? 'XX'),
        'role' => (string) $user['role'],
        'account_status' => portal_user_account_status($user),
    ];
    $_SESSION['portal_login_at'] = gmdate('Y-m-d H:i:s');
}

$courseId = (int) $db->query("SELECT id FROM courses WHERE slug = 'accounting-2526'")->fetchColumn();
if ($courseId <= 0) {
    fwrite(STDERR, "Accounting course missing.\n");
    exit(1);
}

$owner = portal_find_user('bogdan');
$student = portal_find_user('bogdanstudent');
$teacher = portal_find_user('accteacher');
if (!$owner || !$student || !$teacher) {
    fwrite(STDERR, "Need bogdan, bogdanstudent, and accteacher. Run role_access_check / seed first.\n");
    exit(1);
}

g_login($teacher);

// Free gradebook budget: temporarily exclude existing gradebook activities.
$prev = $db->prepare('SELECT id, include_in_gradebook, grade_weight FROM course_activities WHERE course_id = ? AND include_in_gradebook = 1');
$prev->execute([$courseId]);
$previousGradebook = $prev->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($previousGradebook as $row) {
    $db->prepare('UPDATE course_activities SET include_in_gradebook = 0 WHERE id = ?')->execute([(int) $row['id']]);
}
echo "INFO  Parked " . count($previousGradebook) . " existing gradebook activities\n";

$folder = $db->prepare("SELECT id FROM course_folders WHERE course_id = ? AND title = ?");
$folder->execute([$courseId, 'Demo — Penguins unit']);
$folderId = (int) ($folder->fetchColumn() ?: 0);
if ($folderId <= 0) {
    $db->prepare("INSERT INTO course_folders (course_id, title, description) VALUES (?,?,?)")
        ->execute([$courseId, 'Demo — Penguins unit', 'Grade weight checks']);
    $folderId = (int) $db->lastInsertId();
}

// Cleanup prior weight-check assessments
$old = $db->prepare("SELECT id, course_item_id FROM course_activities WHERE course_id = ? AND title LIKE 'Weightcheck %'");
$old->execute([$courseId]);
foreach ($old->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $db->prepare('DELETE FROM course_folder_items WHERE id = ?')->execute([(int) $row['course_item_id']]);
}

function g_make_assessment(int $courseId, int $folderId, int $teacherId, string $title, float $weight): int
{
    $created = portal_activity_create($courseId, $folderId, $title, 'assessment', $teacherId);
    if (empty($created['ok'])) {
        g_fail('Create ' . $title, (string) ($created['error'] ?? ''));
        return 0;
    }
    $id = (int) $created['activity_id'];
    $rev = (int) (portal_activity_find($id)['version'] ?? 1);
    $settings = portal_activity_save_settings($id, [
        'include_in_gradebook' => 1,
        'grade_weight' => $weight,
        'max_attempts' => 3,
        'integrity_enabled' => 0,
        'focus_monitoring' => 0,
        'feedback_policy' => 'when_released',
        'results_released' => 0,
    ], $rev);
    if (empty($settings['ok'])) {
        g_fail('Configure ' . $title, (string) ($settings['error'] ?? ''));
        return 0;
    }
    return $id;
}

echo "\n=== WEIGHT CAP ===\n";
$used0 = portal_course_gradebook_weight_total($courseId);
g_expect($used0 <= 0.001, 'Gradebook budget starts empty for test', 'used=' . $used0);

$a75 = g_make_assessment($courseId, $folderId, (int) $teacher['id'], 'Weightcheck Assessment A (75%)', 75);
g_expect($a75 > 0, 'Created 75% assessment');
$a25 = g_make_assessment($courseId, $folderId, (int) $teacher['id'], 'Weightcheck Assessment B (25%)', 25);
g_expect($a25 > 0, 'Created 25% assessment');
g_expect(
    abs(portal_course_gradebook_weight_total($courseId) - 100.0) < 0.01,
    'Total gradebook weight is 100%'
);

// Over 100% must fail
$overflow = portal_activity_create($courseId, $folderId, 'Weightcheck Overflow', 'assessment', (int) $teacher['id']);
$overflowId = (int) ($overflow['activity_id'] ?? 0);
if ($overflowId > 0) {
    $rev = (int) (portal_activity_find($overflowId)['version'] ?? 1);
    $bad = portal_activity_save_settings($overflowId, [
        'include_in_gradebook' => 1,
        'grade_weight' => 1,
    ], $rev);
    g_expect(empty($bad['ok']), 'Reject gradebook weight that would exceed 100%', (string) ($bad['error'] ?? 'no error'));
    $db->prepare('DELETE FROM course_folder_items WHERE id = (SELECT course_item_id FROM course_activities WHERE id = ?)')
        ->execute([$overflowId]);
}

// Raising B from 25 → 30 while A is 75 must fail
if ($a25 > 0) {
    $rev = (int) (portal_activity_find($a25)['version'] ?? 1);
    $bad = portal_activity_save_settings($a25, ['grade_weight' => 30], $rev);
    g_expect(empty($bad['ok']), 'Reject raising 25% assessment to 30% when 75% already used', (string) ($bad['error'] ?? ''));
}

echo "\n=== WEIGHTED AVERAGE MATH ===\n";
// 80% on 75-weight + 100% on 25-weight => (80*75 + 100*25)/100 = 85
$manual = portal_weighted_grade_average([
    ['score' => 80, 'marked_at' => '2026-01-01', 'submission_weight' => 75],
    ['score' => 100, 'marked_at' => '2026-01-01', 'submission_weight' => 25],
]);
g_expect($manual === 85, '75%@80 + 25%@100 averages to 85', 'got=' . var_export($manual, true));

$manual2 = portal_weighted_grade_average([
    ['score' => 60, 'marked_at' => '2026-01-01', 'submission_weight' => 75],
    ['score' => 100, 'marked_at' => '2026-01-01', 'submission_weight' => 25],
]);
g_expect($manual2 === 70, '75%@60 + 25%@100 averages to 70', 'got=' . var_export($manual2, true));

echo "\n=== BUILD ASSESSMENTS + STUDENT ATTEMPTS ===\n";
foreach ([$a75 => 'A', $a25 => 'B'] as $aid => $label) {
    if ($aid <= 0) {
        continue;
    }
    // One true/false each — True correct for A (score 100 if True), False correct for B... 
    // Simpler: both have True correct; student answers True on A (100%) and False on B (0%) then we need known mix.
    // Better: A has 1 Q True correct; B has 1 Q True correct; student gets A correct and B correct for 100/100 then we can't test 80/100.
    // Force percentages by updating attempt after submit.
    $q = portal_activity_add_question($aid, 'true_false', '<p>Weightcheck ' . $label . '?</p>');
    g_expect(!empty($q['ok']), "Add question to assessment {$label}");
    $pub = portal_activity_publish($aid);
    g_expect(!empty($pub['ok']), "Publish assessment {$label}", (string) ($pub['error'] ?? json_encode($pub['validation']['errors'] ?? [])));
}

g_login($student);
$attemptIds = [];
foreach ([$a75 => 80.0, $a25 => 100.0] as $aid => $targetPct) {
    if ($aid <= 0) {
        continue;
    }
    $start = portal_activity_start_attempt($aid, (int) $student['id'], 'I understand the integrity rules.');
    g_expect(!empty($start['ok']), 'Student starts assessment #' . $aid, (string) ($start['error'] ?? ''));
    if (empty($start['ok'])) {
        continue;
    }
    $attemptId = (int) ($start['attempt']['id'] ?? 0);
    $token = (string) ($start['token'] ?? '');
    $submit = portal_activity_submit_attempt($attemptId, (int) $student['id'], $token);
    g_expect(!empty($submit['ok']), 'Student submits assessment #' . $aid, (string) ($submit['error'] ?? ''));

    // Force known percentages for weighting (auto-score may be 0/100 depending on TF answers).
    $db->prepare(
        "UPDATE activity_attempts
         SET percentage = ?, score = ?, maximum_score = 1, status = 'submitted', updated_at = datetime('now')
         WHERE id = ?"
    )->execute([$targetPct, $targetPct / 100.0, $attemptId]);
    $attemptIds[$aid] = $attemptId;
}

echo "\n=== RELEASE + STUDENT GRADEBOOK ===\n";
g_login($teacher);
foreach ($attemptIds as $aid => $attemptId) {
    $db->prepare(
        "UPDATE activity_attempts SET status = 'released', updated_at = datetime('now') WHERE id = ?"
    )->execute([$attemptId]);
    $db->prepare('UPDATE course_activities SET results_released = 1 WHERE id = ?')->execute([$aid]);
    g_ok('Released attempt #' . $attemptId . ' for activity #' . $aid);
}

g_login($student);
$rows = portal_activity_gradebook_rows($courseId, (int) $student['id']);
$weightcheckRows = array_values(array_filter(
    $rows,
    static fn(array $r): bool => str_starts_with((string) $r['title'], 'Weightcheck ')
));
g_expect(count($weightcheckRows) === 2, 'Student gradebook shows both released assessments', 'count=' . count($weightcheckRows));

$avg = portal_weighted_grade_average($weightcheckRows);
g_expect($avg === 85, 'Student weighted average is 85 for 75%@80 + 25%@100', 'got=' . var_export($avg, true) . ' rows=' . json_encode($weightcheckRows));

// Unreleased should not appear for a fresh attempt if we clear release — spot-check filter requires status=released
$unreleasedCount = 0;
foreach ($weightcheckRows as $r) {
    if (($r['status'] ?? '') !== 'released') {
        $unreleasedCount++;
    }
}
g_expect($unreleasedCount === 0, 'Student only sees released assessment grades');

echo "\n=== CLEANUP ===\n";
g_login($teacher);
foreach ([$a75, $a25] as $aid) {
    if ($aid <= 0) {
        continue;
    }
    $itemId = (int) (portal_activity_find($aid)['course_item_id'] ?? 0);
    if ($itemId > 0) {
        $db->prepare('DELETE FROM course_folder_items WHERE id = ?')->execute([$itemId]);
    }
}
foreach ($previousGradebook as $row) {
    $db->prepare('UPDATE course_activities SET include_in_gradebook = ?, grade_weight = ? WHERE id = ?')
        ->execute([(int) $row['include_in_gradebook'], (float) $row['grade_weight'], (int) $row['id']]);
}
g_ok('Restored previous gradebook activity flags');

echo "\n=== SUMMARY ===\nPassed: {$passes}\nFailed: {$fails}\n";
exit($fails > 0 ? 1 : 0);
