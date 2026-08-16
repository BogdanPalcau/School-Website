<?php
declare(strict_types=1);

/**
 * Human walkthrough demo data for Accounting (accounting-2526).
 *
 * Idempotent: skips features that already have demo content.
 * Reuses existing penguin activity content when present; otherwise runs
 * scripts/seed_penguin_demo.php first.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/seed_accounting_demo.php
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/demo_penguin_files.php';

const ACC_SLUG = 'accounting-2526';
const DEMO_MODES_FOLDER = 'Demo — Accounting toolkit';
const DEMO_EVENT_TITLE = 'Demo — Accounting revision clinic';
const DEMO_SITE_ANN = 'Demo — Term start reminder';
const DEMO_GROUP_TITLE = 'Demo — Ledger Team A';
const DEMO_INVITE_EMAIL = 'demo.invite.student@example.test';
const DEMO_CHALLENGE = 'Demo — Trial balance challenge';
const DEMO_SURVEY = 'Demo — Module feedback survey';
const DEMO_ASSESSMENT = 'Demo — Double-entry assessment';

function acc_ok(string $msg): void
{
    echo $msg . "\n";
}

function acc_skip(string $msg): void
{
    echo "SKIP  {$msg}\n";
}

function acc_fail(string $msg): never
{
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit(1);
}

function acc_ensure_user(
    PDO $db,
    string $username,
    string $password,
    string $role,
    string $name,
    string $email,
    string $year = 'Year 11',
    string $programme = 'Business & Accounting'
): array {
    $initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name) ?: 'XX', 0, 2));
    $existing = portal_find_user($username);
    $hash = password_hash($password, PASSWORD_DEFAULT);

    if ($existing !== null) {
        $db->prepare(
            'UPDATE users
             SET password_hash = ?, role = ?, name = ?, email = ?, year = ?, programme = ?,
                 initials = ?, account_status = ?
             WHERE id = ?'
        )->execute([
            $hash, $role, $name, $email, $year, $programme, $initials, 'active', (int) $existing['id'],
        ]);
        $user = portal_find_user($username);
        if ($user === null) {
            acc_fail('User vanished after update: ' . $username);
        }
        acc_ok("Updated user {$username} / {$password} ({$role})");
        return $user;
    }

    $db->prepare(
        'INSERT INTO users (username, email, password_hash, name, year, programme, initials, role, account_status)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([$username, $email, $hash, $name, $year, $programme, $initials, $role, 'active']);

    $user = portal_find_user($username);
    if ($user === null) {
        acc_fail('Could not create ' . $username);
    }
    acc_ok("Created user {$username} / {$password} ({$role})");
    return $user;
}

function acc_login_as(array $user): void
{
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

function acc_publish_or_fail(int $activityId, string $label): void
{
    $pub = portal_activity_publish($activityId);
    if (!empty($pub['ok'])) {
        acc_ok("Published {$label} #{$activityId}");
        return;
    }
    $errs = $pub['validation']['errors'] ?? [];
    $detail = (string) ($pub['error'] ?? '');
    if ($detail === '' && is_array($errs) && $errs !== []) {
        $detail = implode('; ', $errs);
    }
    acc_fail("{$label} publish failed: " . ($detail !== '' ? $detail : 'unknown'));
}

function acc_has_activity(PDO $db, int $courseId, string $title): bool
{
    $stmt = $db->prepare('SELECT id FROM course_activities WHERE course_id = ? AND title = ? LIMIT 1');
    $stmt->execute([$courseId, $title]);
    return (int) ($stmt->fetchColumn() ?: 0) > 0;
}

function acc_folder_id(PDO $db, int $courseId, string $title): int
{
    $stmt = $db->prepare('SELECT id FROM course_folders WHERE course_id = ? AND title = ? LIMIT 1');
    $stmt->execute([$courseId, $title]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

function acc_ensure_folder(PDO $db, int $courseId, string $title, string $description, int $locked = 0): int
{
    $id = acc_folder_id($db, $courseId, $title);
    if ($id > 0) {
        return $id;
    }
    $max = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM course_folders WHERE course_id = ?');
    $max->execute([$courseId]);
    $sort = (int) $max->fetchColumn() + 1;
    $db->prepare(
        'INSERT INTO course_folders (course_id, title, description, locked, sort_order) VALUES (?,?,?,?,?)'
    )->execute([$courseId, $title, $description, $locked, $sort]);
    $id = (int) $db->lastInsertId();
    acc_ok("Created folder “{$title}”");
    return $id;
}

$db = portal_db();

$courseStmt = $db->prepare('SELECT id, slug, title FROM courses WHERE slug = ?');
$courseStmt->execute([ACC_SLUG]);
$course = $courseStmt->fetch(PDO::FETCH_ASSOC);
if (!$course) {
    acc_fail('Accounting course missing. Run the portal once so db_init seeds the catalog.');
}
$courseId = (int) $course['id'];
acc_ok('Target course #' . $courseId . ' ' . $course['title'] . ' (' . $course['slug'] . ')');

// ── Users & access ────────────────────────────────────────────────────────────
$owner = portal_find_user('bogdan');
if ($owner === null) {
    acc_fail('Owner “bogdan” not found.');
}
$admin = acc_ensure_user($db, 'accadmin', 'accadmin1', 'admin', 'Accounting Admin', 'accadmin@rieo.edu', 'Staff', 'Administration');
$teacher = acc_ensure_user($db, 'accteacher', 'accteacher1', 'teacher', 'Accounting Teacher', 'accteacher@rieo.edu', 'Staff', 'Business & Accounting');
$supervisor = acc_ensure_user($db, 'acctsupervisor', 'acctsupervisor1', 'teacher', 'Accounting Supervisor', 'acctsupervisor@rieo.edu', 'Staff', 'Business & Accounting');
$student1 = acc_ensure_user($db, 'bogdanstudent', 'bogdanstudent', 'student', 'Bogdan Student', 'bogdanstudent@rieo.edu');
$student2 = acc_ensure_user($db, 'accstudent2', 'accstudent2', 'student', 'Ava Ledger', 'accstudent2@rieo.edu');

$ownerId = (int) $owner['id'];
$teacherId = (int) $teacher['id'];
$supervisorId = (int) $supervisor['id'];
$student1Id = (int) $student1['id'];
$student2Id = (int) $student2['id'];

foreach ([$ownerId, $teacherId, $supervisorId, $student1Id, $student2Id] as $uid) {
    $db->prepare('INSERT OR IGNORE INTO enrollments (user_id, course_id) VALUES (?,?)')
        ->execute([$uid, $courseId]);
}
acc_ok('Enrollments ensured for owner, teacher, supervisor, and two students');

$db->prepare(
    "INSERT INTO course_teachers (course_id, user_id, assignment_role)
     VALUES (?,?,?)
     ON CONFLICT(course_id, user_id) DO UPDATE SET assignment_role = excluded.assignment_role"
)->execute([$courseId, $teacherId, 'teacher']);
$db->prepare(
    "INSERT INTO course_teachers (course_id, user_id, assignment_role)
     VALUES (?,?,?)
     ON CONFLICT(course_id, user_id) DO UPDATE SET assignment_role = excluded.assignment_role"
)->execute([$courseId, $supervisorId, 'supervisor']);
acc_ok('Assigned accteacher (teacher) and acctsupervisor (supervisor)');

acc_login_as($teacher);

// ── Penguin unit (activities already covered) ─────────────────────────────────
$penguinFolderId = acc_folder_id($db, $courseId, 'Demo — Penguins unit');
if ($penguinFolderId <= 0) {
    acc_ok('Penguin demo missing — running seed_penguin_demo.php…');
    $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'seed_penguin_demo.php');
    passthru($cmd, $code);
    if ($code !== 0) {
        acc_fail('seed_penguin_demo.php failed with exit ' . $code);
    }
    $penguinFolderId = acc_folder_id($db, $courseId, 'Demo — Penguins unit');
    if ($penguinFolderId <= 0) {
        acc_fail('Penguin folder still missing after seed.');
    }
} else {
    acc_skip('Penguin demo folder already present (flashcards / practice / quiz)');
}

// Refresh penguin-folder materials: remove generic external links, ensure uploads.
$bbcLinks = $db->prepare(
    "SELECT id, file_path FROM course_folder_items
     WHERE course_id = ? AND type = 'link'
       AND (title LIKE 'BBC%' OR title LIKE 'WWF%' OR url LIKE '%ifrs.org%' OR url LIKE '%bitesize%' OR url LIKE '%worldwildlife.org%')"
);
$bbcLinks->execute([$courseId]);
foreach ($bbcLinks->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $db->prepare('DELETE FROM course_folder_items WHERE id = ?')->execute([(int) $row['id']]);
    acc_ok('Removed non-penguin link item #' . (int) $row['id']);
}
if ($penguinFolderId > 0) {
    foreach ([
        ['Penguins — Class notes (Word)', 'Uploaded Word notes for the unit.', 'unit-penguins-class-notes.docx', demo_penguin_docx_bytes(), 2],
        ['Penguins — Lesson slides (PowerPoint)', 'Uploaded PowerPoint slides.', 'unit-penguins-lesson-slides.pptx', demo_penguin_pptx_bytes(), 3],
        ['Penguins — Species table (Excel)', 'Uploaded species spreadsheet.', 'unit-penguins-species-table.xlsx', demo_penguin_xlsx_bytes(), 4],
        ['Penguins — Fact sheet (PDF)', 'Uploaded printable fact sheet.', 'unit-penguins-fact-sheet.pdf', demo_penguin_pdf_bytes(), 5],
    ] as $doc) {
        $result = demo_attach_course_document(
            $db, $courseId, $penguinFolderId, $doc[0], $doc[1], $doc[2], $doc[3], (int) $doc[4], 1
        );
        acc_ok(($result['created'] ? 'Added' : 'Refreshed') . ' penguin-folder “' . $doc[0] . '”');
    }
}

// ── Course materials (penguin uploads: docx / pptx / xlsx / pdf / txt) ─────────
$materialsId = acc_ensure_folder(
    $db,
    $courseId,
    'Course Material',
    'Penguin unit reading pack — Word, PowerPoint, spreadsheet, PDF, and notes.'
);

// Remove leftover non-penguin demo links (IFRS / generic BBC, etc.).
$legacyTitles = [
    'Demo — IFRS overview',
    'Demo — Double-entry bookkeeping guide',
    'Demo — Extension reading',
];
foreach ($legacyTitles as $legacyTitle) {
    $legacy = $db->prepare(
        "SELECT id, file_path FROM course_folder_items
         WHERE course_id = ? AND title = ?"
    );
    $legacy->execute([$courseId, $legacyTitle]);
    foreach ($legacy->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fp = (string) ($row['file_path'] ?? '');
        if ($fp !== '') {
            $abs = portal_uploads_base() . DIRECTORY_SEPARATOR . $fp;
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
        $db->prepare('DELETE FROM course_folder_items WHERE id = ?')->execute([(int) $row['id']]);
        acc_ok('Removed leftover item “' . $legacyTitle . '”');
    }
}

$penguinDocs = [
    [
        'title' => 'Penguins — Class notes (Word)',
        'desc' => 'Editable class notes covering habitat, adaptations, and diet.',
        'file' => 'materials-penguins-class-notes.docx',
        'bytes' => demo_penguin_docx_bytes(),
        'sort' => 1,
    ],
    [
        'title' => 'Penguins — Lesson slides (PowerPoint)',
        'desc' => 'Short slide deck for the Penguins unit.',
        'file' => 'materials-penguins-lesson-slides.pptx',
        'bytes' => demo_penguin_pptx_bytes(),
        'sort' => 2,
    ],
    [
        'title' => 'Penguins — Species table (Excel)',
        'desc' => 'Species, regions, diet, and fun facts in a spreadsheet.',
        'file' => 'materials-penguins-species-table.xlsx',
        'bytes' => demo_penguin_xlsx_bytes(),
        'sort' => 3,
    ],
    [
        'title' => 'Penguins — Fact sheet (PDF)',
        'desc' => 'One-page printable fact sheet for revision.',
        'file' => 'materials-penguins-fact-sheet.pdf',
        'bytes' => demo_penguin_pdf_bytes(),
        'sort' => 4,
    ],
    [
        'title' => 'Penguins — Quick revision notes (Text)',
        'desc' => 'Plain-text study checklist.',
        'file' => 'materials-penguins-revision-notes.txt',
        'bytes' => demo_penguin_txt_bytes(),
        'sort' => 5,
    ],
];

foreach ($penguinDocs as $doc) {
    $result = demo_attach_course_document(
        $db,
        $courseId,
        $materialsId,
        $doc['title'],
        $doc['desc'],
        $doc['file'],
        $doc['bytes'],
        (int) $doc['sort'],
        1
    );
    acc_ok(($result['created'] ? 'Added' : 'Refreshed') . ' document “' . $doc['title'] . '”');
}

$lockedId = acc_folder_id($db, $courseId, 'Demo — Locked extension');
if ($lockedId <= 0) {
    $lockedId = acc_ensure_folder(
        $db,
        $courseId,
        'Demo — Locked extension',
        'Hidden from students until unlocked — contains extension penguin slides.',
        1
    );
}
demo_attach_course_document(
    $db,
    $courseId,
    $lockedId,
    'Penguins — Extension slides (PowerPoint)',
    'Extra slides unlocked by staff when the folder is opened.',
    'penguins-extension-slides.pptx',
    demo_penguin_pptx_bytes(),
    1,
    1
);
acc_ok('Ensured locked-folder penguin PowerPoint');

// ── Challenge / survey / assessment (missing activity modes) ──────────────────
$modesFolderId = acc_folder_id($db, $courseId, DEMO_MODES_FOLDER);
if ($modesFolderId <= 0) {
    $modesFolderId = acc_ensure_folder(
        $db,
        $courseId,
        DEMO_MODES_FOLDER,
        'Challenge, survey, and integrity-enabled assessment for full activity coverage.'
    );
}

if (!acc_has_activity($db, $courseId, DEMO_CHALLENGE)) {
    $ch = portal_activity_create($courseId, $modesFolderId, DEMO_CHALLENGE, 'challenge', $teacherId);
    if (empty($ch['ok'])) {
        acc_fail('Challenge create failed: ' . ($ch['error'] ?? 'unknown'));
    }
    $chId = (int) $ch['activity_id'];
    portal_activity_save_settings($chId, [
        'time_limit_seconds' => 600,
        'max_attempts' => 3,
        'xp_enabled' => 1,
        'xp_amount' => 40,
        'feedback_policy' => 'after_submission',
        'leaderboard_enabled' => 1,
    ], (int) (portal_activity_find($chId)['version'] ?? 1));
    portal_activity_add_question($chId, 'single_choice', '<p>Assets = Liabilities + ?</p>', null, [
        'points' => 1,
        'options' => [
            ['text' => 'Expenses', 'is_correct' => 0],
            ['text' => 'Equity', 'is_correct' => 1],
            ['text' => 'Revenue only', 'is_correct' => 0],
            ['text' => 'Cash flow', 'is_correct' => 0],
        ],
    ]);
    portal_activity_add_question($chId, 'true_false', '<p>A debit increases an asset account.</p>', null, [
        'points' => 1,
        'explanation_html' => '<p>Debits increase assets and expenses.</p>',
    ]);
    portal_activity_add_question($chId, 'numeric', '<p>If cash is debited $250 and credited $50, what is the net debit?</p>', null, [
        'points' => 1,
        'settings' => ['correct_value' => 200, 'tolerance' => 0],
    ]);
    acc_publish_or_fail($chId, 'challenge');
} else {
    acc_skip('Challenge activity already present');
}

if (!acc_has_activity($db, $courseId, DEMO_SURVEY)) {
    $sv = portal_activity_create($courseId, $modesFolderId, DEMO_SURVEY, 'survey', $teacherId);
    if (empty($sv['ok'])) {
        acc_fail('Survey create failed: ' . ($sv['error'] ?? 'unknown'));
    }
    $svId = (int) $sv['activity_id'];
    portal_activity_save_settings($svId, [
        'max_attempts' => 1,
        'xp_enabled' => 0,
        'include_in_gradebook' => 0,
        'feedback_policy' => 'never',
    ], (int) (portal_activity_find($svId)['version'] ?? 1));
    portal_activity_add_question($svId, 'rating_scale', '<p>How clear was this week’s double-entry lesson?</p>', null, [
        'points' => 0,
        'settings' => ['min' => 1, 'max' => 5],
    ]);
    portal_activity_add_question($svId, 'long_response', '<p>What should we spend more time on next week?</p>', null, [
        'points' => 0,
        'manual_marking' => 0,
    ]);
    acc_publish_or_fail($svId, 'survey');
} else {
    acc_skip('Survey activity already present');
}

if (!acc_has_activity($db, $courseId, DEMO_ASSESSMENT)) {
    $as = portal_activity_create($courseId, $modesFolderId, DEMO_ASSESSMENT, 'assessment', $teacherId);
    if (empty($as['ok'])) {
        acc_fail('Assessment create failed: ' . ($as['error'] ?? 'unknown'));
    }
    $asId = (int) $as['activity_id'];
    portal_activity_save_settings($asId, [
        'max_attempts' => 2,
        'integrity_enabled' => 1,
        'feedback_policy' => 'when_released',
        'results_released' => 1,
        'xp_enabled' => 1,
        'xp_amount' => 50,
        'include_in_gradebook' => 1,
        'grade_weight' => 15,
        'leaderboard_enabled' => 0,
    ], (int) (portal_activity_find($asId)['version'] ?? 1));
    portal_activity_add_question($asId, 'single_choice', '<p>Which account normally has a credit balance?</p>', null, [
        'points' => 2,
        'teacher_notes' => 'Look for equity / liability / revenue.',
        'options' => [
            ['text' => 'Cash', 'is_correct' => 0],
            ['text' => 'Accounts receivable', 'is_correct' => 0],
            ['text' => 'Share capital', 'is_correct' => 1],
            ['text' => 'Prepaid rent', 'is_correct' => 0],
        ],
    ]);
    portal_activity_add_question($asId, 'short_text', '<p>Name the accounting equation.</p>', null, [
        'points' => 2,
        'settings' => [
            'accepted_answers' => [
                'assets = liabilities + equity',
                'assets = liabilities + capital',
                'a = l + e',
            ],
        ],
    ]);
    acc_publish_or_fail($asId, 'assessment');
} else {
    acc_skip('Assessment activity already present');
}

// ── Submission slot + graded sample ───────────────────────────────────────────
$assignFolderId = acc_ensure_folder($db, $courseId, 'Assignments', 'Graded coursework submissions.');
$openSlot = $db->prepare(
    "SELECT id, title FROM course_folder_items
     WHERE course_id = ? AND type = 'submission' AND submission_deadline > datetime('now')
     ORDER BY id ASC LIMIT 1"
);
$openSlot->execute([$courseId]);
$slot = $openSlot->fetch(PDO::FETCH_ASSOC);
if (!$slot) {
    $deadline = (new DateTimeImmutable('+21 days'))->format('Y-m-d 09:00:00');
    $db->prepare(
        "INSERT INTO course_folder_items
            (folder_id, course_id, type, title, description, submission_deadline, submission_weight, submission_max_attempts, sort_order)
         VALUES (?,?,?,?,?,?,?,?,?)"
    )->execute([
        $assignFolderId, $courseId, 'submission',
        'Demo — Trial balance worksheet',
        'Paste or upload your completed trial balance. Minimum length applies for pasted text.',
        $deadline, 20, 3, 10,
    ]);
    $slotId = (int) $db->lastInsertId();
    acc_ok('Created open submission slot #' . $slotId);
} else {
    $slotId = (int) $slot['id'];
    acc_skip('Open submission slot already present: ' . $slot['title']);
}

$existingSub = $db->prepare(
    'SELECT id FROM course_submissions WHERE item_id = ? AND user_id = ? LIMIT 1'
);
$existingSub->execute([$slotId, $student1Id]);
if ((int) ($existingSub->fetchColumn() ?: 0) === 0) {
    $essay = 'This trial balance lists cash 1200, receivables 800, payables 450, '
        . 'capital 1400, and drawings 150. Debits and credits both total 2000, '
        . 'so the books appear to balance for this demo submission.';
    $wordCount = str_word_count($essay);
    $receipt = function_exists('portal_generate_unique_receipt_number')
        ? portal_generate_unique_receipt_number($db)
        : ('DEMO-' . strtoupper(bin2hex(random_bytes(6))));
    $now = gmdate('Y-m-d H:i:s');
    $db->prepare(
        "INSERT INTO course_submissions
            (item_id, course_id, user_id, filename, filepath, filesize, submitted_at,
             score, feedback, marked_at, marked_by, receipt_number, submission_text, text_word_count,
             eula_accepted_at, process_edit_seconds, grades_released_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    )->execute([
        $slotId, $courseId, $student1Id,
        '', '', 0, $now,
        88,
        'Clear structure and totals match. Watch drawings vs capital next time.',
        $now, $teacherId, $receipt, $essay, $wordCount, $now, 90, $now,
    ]);
    portal_notify_user(
        $student1Id,
        'grade',
        'Grade returned: Trial balance worksheet',
        'You scored 88%. Open Accounting → Assignments to read feedback.',
        'course.php?course=' . ACC_SLUG . '&section=content',
        $courseId
    );
    acc_ok('Created graded text submission for bogdanstudent (receipt ' . $receipt . ')');
} else {
    acc_skip('Graded submission for bogdanstudent already present on open slot');
}

// ── Schedule (keep existing Tuesday; add Thursday if missing) ─────────────────
$thu = $db->prepare(
    "SELECT COUNT(*) FROM course_schedule WHERE course_id = ? AND day_of_week = 'Thursday'"
);
$thu->execute([$courseId]);
if ((int) $thu->fetchColumn() === 0) {
    $db->prepare(
        'INSERT INTO course_schedule (course_id, day_of_week, start_time, end_time, room, notes, sort_order)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([$courseId, 'Thursday', '13:00', '14:30', 'Room B12', 'Workshop / past papers', 1]);
    acc_ok('Added Thursday schedule slot');
} else {
    acc_skip('Thursday schedule slot already present');
}

// ── Events ────────────────────────────────────────────────────────────────────
$ev = $db->prepare('SELECT id FROM events WHERE course_id = ? AND title = ? LIMIT 1');
$ev->execute([$courseId, DEMO_EVENT_TITLE]);
if ((int) ($ev->fetchColumn() ?: 0) === 0) {
    $start = (new DateTimeImmutable('+5 days'))->setTime(15, 0)->format('Y-m-d H:i:s');
    $end = (new DateTimeImmutable('+5 days'))->setTime(16, 0)->format('Y-m-d H:i:s');
    $db->prepare(
        "INSERT INTO events
            (course_id, created_by, title, summary, description, starts_at, ends_at, location, online_url, important, status)
         VALUES (?,?,?,?,?,?,?,?,?,?, 'scheduled')"
    )->execute([
        $courseId, $teacherId, DEMO_EVENT_TITLE,
        'Optional clinic before the assessment.',
        'Bring questions on journals, ledgers, and trial balances.',
        $start, $end, 'Library seminar room', '', 1,
    ]);
    portal_notify_user(
        $student1Id,
        'event',
        DEMO_EVENT_TITLE,
        'Starts ' . $start . ' — optional revision clinic.',
        'events.php',
        $courseId
    );
    acc_ok('Created course event “' . DEMO_EVENT_TITLE . '”');
} else {
    acc_skip('Course event already present');
}

$siteEvTitle = 'Demo — Whole-school careers fair';
$siteEv = $db->prepare('SELECT id FROM events WHERE course_id IS NULL AND title = ? LIMIT 1');
$siteEv->execute([$siteEvTitle]);
if ((int) ($siteEv->fetchColumn() ?: 0) === 0) {
    $start = (new DateTimeImmutable('+12 days'))->setTime(10, 0)->format('Y-m-d H:i:s');
    $end = (new DateTimeImmutable('+12 days'))->setTime(15, 0)->format('Y-m-d H:i:s');
    $db->prepare(
        "INSERT INTO events
            (course_id, created_by, title, summary, description, starts_at, ends_at, location, online_url, important, status)
         VALUES (NULL,?,?,?,?,?,?,?,?,?, 'scheduled')"
    )->execute([
        $ownerId, $siteEvTitle,
        'Open to all year groups.',
        'Visit employer stands including finance and accounting pathways.',
        $start, $end, 'Main hall', '', 0,
    ]);
    acc_ok('Created site-wide event “' . $siteEvTitle . '”');
} else {
    acc_skip('Site-wide event already present');
}

// ── Groups ────────────────────────────────────────────────────────────────────
$grp = $db->prepare('SELECT id FROM course_groups WHERE course_id = ? AND title = ? LIMIT 1');
$grp->execute([$courseId, DEMO_GROUP_TITLE]);
$groupId = (int) ($grp->fetchColumn() ?: 0);
if ($groupId <= 0) {
    $db->prepare(
        'INSERT INTO course_groups (course_id, title, description, max_members) VALUES (?,?,?,?)'
    )->execute([$courseId, DEMO_GROUP_TITLE, 'Pair work for ledger exercises.', 4]);
    $groupId = (int) $db->lastInsertId();
    acc_ok('Created group “' . DEMO_GROUP_TITLE . '”');
} else {
    acc_skip('Group already present');
}
foreach ([$student1Id, $student2Id] as $uid) {
    $db->prepare('INSERT OR IGNORE INTO course_group_members (group_id, user_id) VALUES (?,?)')
        ->execute([$groupId, $uid]);
}

// ── Site announcement ─────────────────────────────────────────────────────────
$sa = $db->prepare('SELECT id FROM site_announcements WHERE title = ? LIMIT 1');
$sa->execute([DEMO_SITE_ANN]);
if ((int) ($sa->fetchColumn() ?: 0) === 0) {
    $db->prepare(
        'INSERT INTO site_announcements (user_id, title, body, priority, pinned)
         VALUES (?,?,?,?,?)'
    )->execute([
        $ownerId,
        DEMO_SITE_ANN,
        "Welcome back.\n\nUse Accounting to walk through materials, activities, submissions, timetable, events, groups, and discussions.\nDemo accounts are listed in the seed script output.",
        'normal',
        1,
    ]);
    acc_ok('Created pinned site announcement');
} else {
    acc_skip('Site announcement already present');
}

// ── Discussion reply from second student ──────────────────────────────────────
$topic = $db->prepare(
    "SELECT id FROM course_discussion_topics WHERE course_id = ? AND title = ? LIMIT 1"
);
$topic->execute([$courseId, 'Demo — Surprising penguin facts']);
$topicId = (int) ($topic->fetchColumn() ?: 0);
if ($topicId > 0) {
    $replyCheck = $db->prepare(
        'SELECT COUNT(*) FROM course_discussion_replies WHERE topic_id = ? AND user_id = ?'
    );
    $replyCheck->execute([$topicId, $student2Id]);
    if ((int) $replyCheck->fetchColumn() === 0) {
        $db->prepare(
            'INSERT INTO course_discussion_replies (topic_id, course_id, user_id, body) VALUES (?,?,?,?)'
        )->execute([
            $topicId, $courseId, $student2Id,
            'Also surprised that some penguin species live near the equator, not only Antarctica.',
        ]);
        acc_ok('Added discussion reply from accstudent2');
    } else {
        acc_skip('accstudent2 already replied in demo discussion');
    }
}

// ── Video Q&A ─────────────────────────────────────────────────────────────────
$video = $db->prepare(
    "SELECT id FROM course_folder_items WHERE course_id = ? AND type = 'video' ORDER BY id ASC LIMIT 1"
);
$video->execute([$courseId]);
$videoId = (int) ($video->fetchColumn() ?: 0);
if ($videoId > 0) {
    $qaCheck = $db->prepare(
        'SELECT COUNT(*) FROM course_video_questions WHERE item_id = ? AND question LIKE ?'
    );
    $qaCheck->execute([$videoId, 'Demo —%']);
    if ((int) $qaCheck->fetchColumn() === 0) {
        $db->prepare(
            "INSERT INTO course_video_questions
                (item_id, course_id, user_id, question, answer, answered_by, answered_at, video_seconds, is_public)
             VALUES (?,?,?,?,?,?,?,?,1)"
        )->execute([
            $videoId, $courseId, $student1Id,
            'Demo — Why do teachers use this video in Accounting?',
            'It is sample multimedia content so you can test the lesson player, notes, and Q&A — not Accounting theory itself.',
            $teacherId,
            gmdate('Y-m-d H:i:s'),
            45,
        ]);
        portal_notify_user(
            $student1Id,
            'lesson_answer',
            'Your lesson question was answered',
            'Open the penguin video lesson to read the reply.',
            'course.php?course=' . ACC_SLUG . '&section=content',
            $courseId
        );
        acc_ok('Created answered video Q&A on demo lesson');
    } else {
        acc_skip('Demo video Q&A already present');
    }
}

// ── Pending student invite ────────────────────────────────────────────────────
if (function_exists('portal_invite_create')) {
    $invCheck = $db->prepare(
        'SELECT id FROM student_invites WHERE email = ? AND used_at IS NULL AND revoked_at IS NULL AND expires_at > ? LIMIT 1'
    );
    $invCheck->execute([DEMO_INVITE_EMAIL, time()]);
    if ((int) ($invCheck->fetchColumn() ?: 0) === 0) {
        acc_login_as($admin);
        $created = portal_invite_create(
            DEMO_INVITE_EMAIL,
            [$courseId],
            (int) $admin['id'],
            'Demo Invitee',
            'Year 11',
            14,
            '127.0.0.1'
        );
        if (!empty($created['ok'])) {
            $token = (string) ($created['token'] ?? '');
            acc_ok('Created pending invite for ' . DEMO_INVITE_EMAIL);
            acc_ok('Invite path: accept-invite.php?token=' . $token);
            $abs = function_exists('portal_invite_url') ? portal_invite_url($token) : '';
            if ($abs !== '' && str_starts_with($abs, 'http')) {
                acc_ok('Invite absolute URL: ' . $abs);
            }
        } else {
            acc_ok('Invite create skipped: ' . ($created['error'] ?? 'unknown'));
        }
        acc_login_as($teacher);
    } else {
        acc_skip('Pending invite for ' . DEMO_INVITE_EMAIL . ' already exists');
    }
}

acc_ok('');
acc_ok('══════════════════════════════════════════════════');
acc_ok(' Accounting human-test demo is ready');
acc_ok(' Course: course.php?course=' . ACC_SLUG);
acc_ok('══════════════════════════════════════════════════');
acc_ok(' Demo logins (username / password)');
acc_ok('  owner:       bogdan / (see database/INITIAL_OWNER_PASSWORD.txt)');
acc_ok('  admin:       accadmin / accadmin1');
acc_ok('  teacher:     accteacher / accteacher1');
acc_ok('  supervisor:  acctsupervisor / acctsupervisor1');
acc_ok('  student:     bogdanstudent / bogdanstudent');
acc_ok('  student 2:   accstudent2 / accstudent2');
acc_ok('');
acc_ok(' Covered: materials, locked folder, penguin activities,');
acc_ok(' challenge/survey/assessment, submission+grade, schedule,');
acc_ok(' events, groups, discussions, video Q&A, site announcement,');
acc_ok(' notifications, student invite.');
exit(0);
