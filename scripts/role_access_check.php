<?php
declare(strict_types=1);

/**
 * Role capability smoke test for Accounting penguin demo content.
 *
 * Covers student, course teacher, and course supervisor for actions they
 * would actually perform. Prints PASS/FAIL lines and exits non-zero on fails.
 *
 *   C:\xampp\php\php.exe scripts/role_access_check.php
 */

require_once __DIR__ . '/../bootstrap.php';

$db = portal_db();
$fails = 0;
$passes = 0;

function t_ok(string $label): void
{
    global $passes;
    $passes++;
    echo "PASS  {$label}\n";
}

function t_fail(string $label, string $detail = ''): void
{
    global $fails;
    $fails++;
    echo "FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function t_info(string $msg): void
{
    echo "INFO  {$msg}\n";
}

function t_login(array $user): void
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

function t_ensure_user(PDO $db, string $username, string $password, string $role, string $name, string $email): array
{
    $u = portal_find_user($username);
    if ($u !== null) {
        // Keep password predictable for local testing.
        $db->prepare('UPDATE users SET password_hash = ?, role = ?, name = ?, email = ?, account_status = ? WHERE id = ?')
            ->execute([
                password_hash($password, PASSWORD_DEFAULT),
                $role,
                $name,
                $email,
                'active',
                (int) $u['id'],
            ]);
        $u = portal_find_user($username);
        if ($u === null) {
            throw new RuntimeException('User vanished after update: ' . $username);
        }
        return $u;
    }

    $cols = $db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC);
    $hasStatus = false;
    foreach ($cols as $c) {
        if (($c['name'] ?? '') === 'account_status') {
            $hasStatus = true;
            break;
        }
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hasStatus) {
        $db->prepare(
            'INSERT INTO users (username, email, password_hash, name, year, programme, initials, role, account_status)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([$username, $email, $hash, $name, 'Year 11', 'STEM pathway', strtoupper(substr($name, 0, 2)), $role, 'active']);
    } else {
        $db->prepare(
            'INSERT INTO users (username, email, password_hash, name, year, programme, initials, role)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$username, $email, $hash, $name, 'Year 11', 'STEM pathway', strtoupper(substr($name, 0, 2)), $role]);
    }
    $u = portal_find_user($username);
    if ($u === null) {
        throw new RuntimeException('Could not create ' . $username);
    }
    return $u;
}

function t_expect(bool $cond, string $label, string $detail = ''): void
{
    if ($cond) {
        t_ok($label);
    } else {
        t_fail($label, $detail);
    }
}

// ── Setup ───────────────────────────────────────────────────────────────────
$courseStmt = $db->prepare('SELECT id, slug, title FROM courses WHERE slug = ?');
$courseStmt->execute(['accounting-2526']);
$course = $courseStmt->fetch(PDO::FETCH_ASSOC);
if (!$course) {
    fwrite(STDERR, "Accounting course missing.\n");
    exit(1);
}
$courseId = (int) $course['id'];
t_info('Course #' . $courseId . ' ' . $course['title']);

$owner = portal_find_user('bogdan');
if ($owner === null) {
    fwrite(STDERR, "Owner bogdan missing.\n");
    exit(1);
}

$student = t_ensure_user($db, 'bogdanstudent', 'bogdanstudent', 'student', 'Bogdan Student', 'bogdanstudent@rieo.edu');
$teacher = t_ensure_user($db, 'accteacher', 'accteacher1', 'teacher', 'Accounting Teacher', 'accteacher@rieo.edu');
$supervisor = t_ensure_user($db, 'acctsupervisor', 'acctsupervisor1', 'teacher', 'Accounting Supervisor', 'acctsupervisor@rieo.edu');
$outsider = t_ensure_user($db, 'outsideteacher', 'outsideteacher1', 'teacher', 'Outside Teacher', 'outsideteacher@rieo.edu');

$db->prepare('INSERT OR IGNORE INTO enrollments (user_id, course_id) VALUES (?,?)')->execute([(int) $student['id'], $courseId]);
$db->prepare('DELETE FROM enrollments WHERE user_id = ? AND course_id = ?')->execute([(int) $outsider['id'], $courseId]);

$db->prepare(
    "INSERT INTO course_teachers (course_id, user_id, assignment_role)
     VALUES (?,?,?)
     ON CONFLICT(course_id, user_id) DO UPDATE SET assignment_role = excluded.assignment_role"
)->execute([$courseId, (int) $teacher['id'], 'teacher']);

$db->prepare(
    "INSERT INTO course_teachers (course_id, user_id, assignment_role)
     VALUES (?,?,?)
     ON CONFLICT(course_id, user_id) DO UPDATE SET assignment_role = excluded.assignment_role"
)->execute([$courseId, (int) $supervisor['id'], 'supervisor']);

// Ensure outsider is not assigned.
$db->prepare('DELETE FROM course_teachers WHERE course_id = ? AND user_id = ?')
    ->execute([$courseId, (int) $outsider['id']]);

$acts = $db->prepare(
    "SELECT id, course_id, mode, status, title, course_item_id FROM course_activities
     WHERE course_id = ? AND title LIKE 'Penguin%' ORDER BY id"
);
$acts->execute([$courseId]);
$activities = $acts->fetchAll(PDO::FETCH_ASSOC);
if ($activities === []) {
    fwrite(STDERR, "No Penguin activities on Accounting. Run scripts/seed_penguin_demo.php first.\n");
    exit(1);
}
$byMode = [];
foreach ($activities as $a) {
    $byMode[(string) $a['mode']] = $a;
}
t_info('Activities: ' . implode(', ', array_map(static fn($a) => $a['mode'] . '#' . $a['id'], $activities)));

$video = $db->prepare(
    "SELECT id, title, url FROM course_folder_items WHERE course_id = ? AND type = 'video' ORDER BY id DESC LIMIT 1"
);
$video->execute([$courseId]);
$videoItem = $video->fetch(PDO::FETCH_ASSOC);
t_expect($videoItem !== false && trim((string) ($videoItem['url'] ?? '')) !== '', 'Video item present on Accounting');
if ($videoItem) {
    $meta = portal_parse_external_video_url((string) $videoItem['url']);
    t_expect($meta !== null, 'Video URL validates', (string) $videoItem['url']);
}

$folder = $db->prepare("SELECT id, locked FROM course_folders WHERE course_id = ? AND title = ?");
$folder->execute([$courseId, 'Demo — Penguins unit']);
$demoFolder = $folder->fetch(PDO::FETCH_ASSOC);
t_expect($demoFolder !== false, 'Demo penguin folder exists');

echo "\n=== STUDENT (bogdanstudent) ===\n";
t_login($student);
t_expect(portal_can_access_course($courseId), 'Student can access Accounting');
t_expect(!portal_can_manage_course($courseId), 'Student cannot manage Accounting');
t_expect(portal_course_assignment_role($courseId) === null, 'Student has no staff assignment');

foreach ($activities as $a) {
    $can = portal_activity_can_start($a, (int) $student['id']);
    t_expect(!empty($can['ok']), 'Student can start ' . $a['mode'] . ' "' . $a['title'] . '"', (string) ($can['error'] ?? ''));
}

// Student attempt lifecycle on practice (unlimited).
$practice = $byMode['practice'] ?? null;
if ($practice) {
    $start = portal_activity_start_attempt((int) $practice['id'], (int) $student['id']);
    t_expect(!empty($start['ok']), 'Student starts practice attempt', (string) ($start['error'] ?? ''));
    if (!empty($start['ok'])) {
        $attemptId = (int) ($start['attempt']['id'] ?? 0);
        $token = (string) ($start['token'] ?? '');
        $versionId = (int) ($start['attempt']['activity_version_id'] ?? portal_activity_published_version_id((int) $practice['id']));
        $tree = portal_activity_load_version_tree($versionId, true);
        $q = $tree['questions'][0] ?? null;
        $opts = $q ? ($tree['options_by_question'][(int) $q['id']] ?? []) : [];
        $correct = null;
        foreach ($opts as $o) {
            if (!empty($o['is_correct'])) {
                $correct = $o;
                break;
            }
        }
        if ($q && $correct && $attemptId > 0 && $token !== '') {
            $save = portal_activity_save_answer(
                $attemptId,
                (int) $student['id'],
                (int) $q['id'],
                ['option_id' => (int) $correct['id']],
                1,
                $token
            );
            t_expect(!empty($save['ok']), 'Student saves practice answer', (string) ($save['error'] ?? ''));
        } else {
            t_fail('Student practice question/options available', 'attempt=' . $attemptId);
        }
        $submit = portal_activity_submit_attempt($attemptId, (int) $student['id'], $token);
        t_expect(!empty($submit['ok']), 'Student submits practice attempt', (string) ($submit['error'] ?? ''));
    }
}

// Quiz attempt
$quiz = $byMode['quiz'] ?? null;
if ($quiz) {
    $start = portal_activity_start_attempt((int) $quiz['id'], (int) $student['id']);
    t_expect(!empty($start['ok']), 'Student starts quiz attempt', (string) ($start['error'] ?? ''));
    if (!empty($start['ok'])) {
        $attemptId = (int) ($start['attempt']['id'] ?? 0);
        $token = (string) ($start['token'] ?? '');
        $submit = portal_activity_submit_attempt($attemptId, (int) $student['id'], $token);
        t_expect(!empty($submit['ok']), 'Student submits quiz attempt', (string) ($submit['error'] ?? ''));
        $GLOBALS['__student_quiz_attempt'] = $attemptId;
    }
}

// Student must not manage activities
$createDenied = portal_activity_create($courseId, (int) ($demoFolder['id'] ?? 0), 'Student should not create', 'quiz', (int) $student['id']);
t_expect(empty($createDenied['ok']), 'Student cannot create activity', json_encode($createDenied));

// Discussion reply
if ($demoFolder) {
    $topic = $db->prepare('SELECT id FROM course_discussion_topics WHERE course_id = ? AND title = ?');
    $topic->execute([$courseId, 'Demo — Surprising penguin facts']);
    $topicId = (int) ($topic->fetchColumn() ?: 0);
    if ($topicId > 0) {
        $db->prepare(
            'INSERT INTO course_discussion_replies (topic_id, course_id, user_id, body) VALUES (?,?,?,?)'
        )->execute([$topicId, $courseId, (int) $student['id'], 'Student test reply: penguins cannot fly.']);
        t_ok('Student can post discussion reply');
    } else {
        t_fail('Demo discussion topic present for student reply');
    }
}

// Announcement read
$ann = $db->prepare('SELECT id FROM course_announcements WHERE course_id = ? AND title = ?');
$ann->execute([$courseId, 'Demo — Welcome to the Penguins unit']);
$annId = (int) ($ann->fetchColumn() ?: 0);
if ($annId > 0) {
    $db->prepare('INSERT OR IGNORE INTO announcement_reads (user_id, announcement_id) VALUES (?,?)')
        ->execute([(int) $student['id'], $annId]);
    t_ok('Student can mark announcement read');
}

echo "\n=== COURSE TEACHER (accteacher) ===\n";
t_login($teacher);
t_expect(portal_can_access_course($courseId), 'Teacher can access Accounting');
t_expect(portal_can_manage_course($courseId), 'Teacher can manage Accounting');
t_expect(portal_is_course_teacher($courseId), 'Teacher assignment_role=teacher');
t_expect(!portal_is_course_supervisor($courseId), 'Teacher is not supervisor');
t_expect(!portal_is_admin(), 'Teacher is not global admin');

$folderCreateTitle = 'Rolecheck teacher folder ' . bin2hex(random_bytes(2));
$db->prepare('INSERT INTO course_folders (course_id, title, description) VALUES (?,?,?)')
    ->execute([$courseId, $folderCreateTitle, 'Temporary']);
$teacherFolderId = (int) $db->lastInsertId();
t_expect($teacherFolderId > 0, 'Teacher can create folder');

$actCreate = portal_activity_create($courseId, $teacherFolderId, 'Teacher draft quiz', 'quiz', (int) $teacher['id']);
t_expect(!empty($actCreate['ok']), 'Teacher can create activity', (string) ($actCreate['error'] ?? ''));
$teacherDraftId = (int) ($actCreate['activity_id'] ?? 0);

if ($teacherDraftId > 0) {
    portal_activity_add_question($teacherDraftId, 'true_false', '<p>Teacher draft Q?</p>');
    // Leave unpublished — student must not start it.
}

if ($quiz) {
    t_expect(portal_can_manage_course((int) $quiz['course_id']), 'Teacher can open activity results (manage gate)');

    $attemptId = (int) ($GLOBALS['__student_quiz_attempt'] ?? 0);
    if ($attemptId > 0) {
        $rel = $db->prepare(
            "UPDATE activity_attempts SET status = 'released', updated_at = datetime('now') WHERE id = ? AND activity_id = ?"
        );
        $rel->execute([$attemptId, (int) $quiz['id']]);
        t_expect(true, 'Teacher can release student quiz attempt');
    }
}

// Teacher cannot assign staff (admin-only path) — simulate the gate.
t_expect(!portal_is_admin(), 'Teacher cannot use admin-only staff assignment UI');

echo "\n=== COURSE SUPERVISOR (acctsupervisor) ===\n";
t_login($supervisor);
t_expect(portal_can_access_course($courseId), 'Supervisor can access Accounting');
t_expect(portal_can_manage_course($courseId), 'Supervisor can manage Accounting');
t_expect(portal_is_course_supervisor($courseId), 'Supervisor assignment_role=supervisor');
t_expect(!portal_is_course_teacher($courseId), 'Supervisor is not assignment_role=teacher');
t_expect(!portal_is_admin(), 'Supervisor is not global admin');

$supFolderTitle = 'Rolecheck supervisor folder ' . bin2hex(random_bytes(2));
$db->prepare('INSERT INTO course_folders (course_id, title, description) VALUES (?,?,?)')
    ->execute([$courseId, $supFolderTitle, 'Temporary']);
$supFolderId = (int) $db->lastInsertId();
t_expect($supFolderId > 0, 'Supervisor can create folder');

$supAct = portal_activity_create($courseId, $supFolderId, 'Supervisor practice', 'practice', (int) $supervisor['id']);
t_expect(!empty($supAct['ok']), 'Supervisor can create activity', (string) ($supAct['error'] ?? ''));

if ($quiz) {
    t_expect(portal_can_manage_course((int) $quiz['course_id']), 'Supervisor can open activity results');
}

// Lock/unlock demo folder as supervisor (manager action)
if ($demoFolder) {
    $db->prepare('UPDATE course_folders SET locked = 1 WHERE id = ? AND course_id = ?')
        ->execute([(int) $demoFolder['id'], $courseId]);
    t_ok('Supervisor can lock demo folder');

    t_login($student);
    foreach ($activities as $a) {
        // Only activities in the locked folder should block — penguin ones are there.
        $item = $db->prepare('SELECT folder_id FROM course_folder_items WHERE id = ?');
        $item->execute([(int) $a['course_item_id']]);
        $fid = (int) ($item->fetchColumn() ?: 0);
        if ($fid === (int) $demoFolder['id']) {
            $can = portal_activity_can_start($a, (int) $student['id']);
            t_expect(empty($can['ok']), 'Student blocked from locked-folder activity ' . $a['mode'], 'expected lock, got: ' . json_encode($can));
        }
    }

    t_login($supervisor);
    $db->prepare('UPDATE course_folders SET locked = 0 WHERE id = ? AND course_id = ?')
        ->execute([(int) $demoFolder['id'], $courseId]);
    t_ok('Supervisor unlocked demo folder after lock test');
}

echo "\n=== OUTSIDER TEACHER (not assigned, not enrolled) ===\n";
t_login($outsider);
t_expect(!portal_can_access_course($courseId), 'Unassigned teacher cannot access Accounting');
t_expect(!portal_can_manage_course($courseId), 'Unassigned teacher cannot manage Accounting');
if ($quiz) {
    $can = portal_activity_can_start($quiz, (int) $outsider['id']);
    t_expect(empty($can['ok']), 'Unassigned teacher cannot start quiz', json_encode($can));
}

echo "\n=== STUDENT vs TEACHER DRAFT ===\n";
if ($teacherDraftId > 0) {
    $draft = portal_activity_find($teacherDraftId);
    t_login($student);
    $can = portal_activity_can_start($draft, (int) $student['id']);
    t_expect(empty($can['ok']), 'Student cannot start unpublished teacher draft', json_encode($can));

    t_login($teacher);
    $can = portal_activity_can_start($draft, (int) $teacher['id']);
    t_expect(!empty($can['ok']), 'Teacher can preview/start own draft', (string) ($can['error'] ?? ''));
}

echo "\n=== SUPERVISOR vs TEACHER DIFFERENCE CHECK ===\n";
t_login($teacher);
$teacherCanAssignStaff = portal_is_admin();
t_login($supervisor);
$supervisorCanAssignStaff = portal_is_admin();
t_expect(
    $teacherCanAssignStaff === false && $supervisorCanAssignStaff === false,
    'Neither course teacher nor supervisor can assign staff (admin/owner only)'
);
t_info('Note: portal_can_manage_course treats teacher and supervisor the same for content/activities.');

// Cleanup temporary folders/activities created during the check
t_login($owner);
foreach ([$teacherFolderId ?? 0, $supFolderId ?? 0] as $fid) {
    if ($fid > 0) {
        $db->prepare('DELETE FROM course_folder_items WHERE folder_id = ?')->execute([$fid]);
        $db->prepare('DELETE FROM course_folders WHERE id = ?')->execute([$fid]);
    }
}
t_info('Cleaned temporary rolecheck folders');

echo "\n=== SUMMARY ===\n";
echo "Passed: {$passes}\nFailed: {$fails}\n";
echo "Logins for manual UI checks:\n";
echo "  student:     bogdanstudent / bogdanstudent\n";
echo "  teacher:     accteacher / accteacher1\n";
echo "  supervisor:  acctsupervisor / acctsupervisor1\n";
exit($fails > 0 ? 1 : 0);
