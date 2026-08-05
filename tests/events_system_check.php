<?php
declare(strict_types=1);

/**
 * CLI checks for the Events system.
 * Run: C:\xampp\php\php.exe tests/events_system_check.php
 */

require_once __DIR__ . '/../bootstrap.php';

$failures = 0;

function expect_true(bool $cond, string $label): void
{
    global $failures;
    if ($cond) {
        echo "PASS  {$label}\n";
        return;
    }
    $failures++;
    echo "FAIL  {$label}\n";
}

function expect_eq($a, $b, string $label): void
{
    expect_true($a === $b, $label . ' (got ' . var_export($a, true) . ')');
}

/**
 * @param array<string, mixed> $user
 */
function ev_login_as(array $user): void
{
    $_SESSION['portal_user'] = [
        'id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'email' => (string) ($user['email'] ?? ''),
        'name' => (string) ($user['name'] ?? ''),
        'year' => (string) ($user['year'] ?? ''),
        'programme' => (string) ($user['programme'] ?? ''),
        'initials' => (string) ($user['initials'] ?? ''),
        'role' => (string) ($user['role'] ?? 'student'),
    ];
    $_SESSION['portal_login_at'] = gmdate('Y-m-d H:i:s');
}

function ev_logout(): void
{
    unset($_SESSION['portal_user'], $_SESSION['portal_login_at']);
}

/**
 * @return array{id:int, username:string, email:string, name:string, year:string, programme:string, initials:string, role:string}
 */
function ev_insert_user(PDO $db, string $username, string $role): array
{
    $db->prepare(
        'INSERT INTO users (username, email, password_hash, name, year, programme, initials, role)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([
        $username,
        $username . '@events.test',
        password_hash('EventsTestPass123!', PASSWORD_DEFAULT),
        'Events ' . ucfirst($role),
        'Year 11',
        'Events Test',
        strtoupper(substr($role, 0, 2)),
        $role,
    ]);
    $id = (int) $db->lastInsertId();

    return [
        'id' => $id,
        'username' => $username,
        'email' => $username . '@events.test',
        'name' => 'Events ' . ucfirst($role),
        'year' => 'Year 11',
        'programme' => 'Events Test',
        'initials' => strtoupper(substr($role, 0, 2)),
        'role' => $role,
    ];
}

$slug = 'events-test-' . bin2hex(random_bytes(4));
$slugB = $slug . '-b';
$adminUser = $teacherUser = $studentUser = $outsiderUser = null;
$courseId = $blockedCourseId = 0;
$createdEventIds = [];
$db = portal_db();

try {
    echo "=== Fixtures ({$slug}) ===\n";

    $adminUser = ev_insert_user($db, $slug . '-admin', 'admin');
    $teacherUser = ev_insert_user($db, $slug . '-teacher', 'teacher');
    $studentUser = ev_insert_user($db, $slug . '-student', 'student');
    $outsiderUser = ev_insert_user($db, $slug . '-outsider', 'student');

    $db->prepare(
        'INSERT INTO courses
         (slug, code, title, full_title, summary, year_group, term, status, status_label, accent, meeting, room, notice, student_count)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $slug, 'EV-OPEN', 'Events Open Course', 'Events Open Course', 'Events test',
        'Test', 'Test', 'open', 'Open', '#c1202f', '', '', '', 0,
    ]);
    $courseId = (int) $db->lastInsertId();

    $db->prepare(
        'INSERT INTO courses
         (slug, code, title, full_title, summary, year_group, term, status, status_label, accent, meeting, room, notice, student_count)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $slugB, 'EV-BLOCK', 'Events Blocked Course', 'Events Blocked Course', 'Events test blocked',
        'Test', 'Test', 'open', 'Open', '#1d4ed8', '', '', '', 0,
    ]);
    $blockedCourseId = (int) $db->lastInsertId();

    $db->prepare('INSERT INTO course_teachers (course_id, user_id, assignment_role) VALUES (?,?,?)')
        ->execute([$courseId, $teacherUser['id'], 'teacher']);
    $db->prepare('INSERT INTO enrollments (user_id, course_id) VALUES (?,?)')
        ->execute([$studentUser['id'], $courseId]);

    // ── URL / date validation ────────────────────────────────────────────────
    echo "\n=== Validation ===\n";
    expect_true(portal_valid_external_url('https://example.com/join'), 'https URL accepted');
    expect_true(portal_valid_external_url('http://example.com/join'), 'http URL accepted');
    expect_true(!portal_valid_external_url('javascript:alert(1)'), 'javascript URL rejected');
    expect_true(!portal_valid_external_url('data:text/html,hi'), 'data URL rejected');
    expect_true(!portal_valid_external_url('not-a-url'), 'bare string rejected');

    ev_login_as($adminUser);
    $badDates = portal_event_validate_payload([
        'title' => 'Bad dates',
        'summary' => 'Summary',
        'starts_at' => '2030-06-10 15:00',
        'ends_at' => '2030-06-10 14:00',
        'scope' => 'school',
    ]);
    expect_true(!$badDates['ok'], 'end before start rejected');

    $badUrl = portal_event_validate_payload([
        'title' => 'Bad url',
        'summary' => 'Summary',
        'starts_at' => '2030-06-10 15:00',
        'online_url' => 'javascript:alert(1)',
        'scope' => 'school',
    ]);
    expect_true(!$badUrl['ok'], 'unsafe online_url rejected in payload');

    // ── Create permissions ───────────────────────────────────────────────────
    echo "\n=== Create permissions ===\n";

    ev_login_as($adminUser);
    expect_true(portal_event_can_create(null), 'admin can create school-wide');
    $schoolCreate = portal_event_create([
        'title' => 'School Assembly',
        'summary' => 'Whole school meeting',
        'description' => '',
        'starts_at' => date('Y-m-d H:i:s', strtotime('+3 days')),
        'ends_at' => '',
        'location' => 'Hall',
        'online_url' => '',
        'course_id' => null,
        'important' => 1,
    ], (int) $adminUser['id']);
    expect_true($schoolCreate['ok'], 'admin creates school-wide event');
    $schoolEventId = (int) $schoolCreate['id'];
    $createdEventIds[] = $schoolEventId;

    ev_login_as($teacherUser);
    expect_true(!portal_event_can_create(null), 'teacher cannot create school-wide');
    $teacherSchool = portal_event_validate_payload([
        'title' => 'Teacher school',
        'summary' => 'Nope',
        'starts_at' => date('Y-m-d H:i:s', strtotime('+4 days')),
        'scope' => 'school',
    ]);
    expect_true(!$teacherSchool['ok'], 'teacher school-wide payload rejected');

    expect_true(portal_event_can_create($courseId), 'teacher can create for assigned course');
    expect_true(!portal_event_can_create($blockedCourseId), 'teacher cannot create for unassigned course');

    $teacherCreate = portal_event_create([
        'title' => 'Course Workshop',
        'summary' => 'Assigned course event',
        'description' => '',
        'starts_at' => date('Y-m-d H:i:s', strtotime('+2 days')),
        'ends_at' => '',
        'location' => 'Lab',
        'online_url' => 'https://meet.example.com/room',
        'course_id' => $courseId,
        'important' => 1, // should be forced off by validate; create path trusts data — simulate validated
    ], (int) $teacherUser['id']);
    // Force important via raw insert path check: validation zeros important for non-admin
    $teacherPayload = portal_event_validate_payload([
        'title' => 'Course Workshop B',
        'summary' => 'Assigned course event',
        'starts_at' => date('Y-m-d H:i:s', strtotime('+5 days')),
        'scope' => 'course',
        'course_id' => $courseId,
        'important' => '1',
        'online_url' => 'https://meet.example.com/ok',
    ]);
    expect_true($teacherPayload['ok'], 'teacher assigned course payload ok');
    expect_eq($teacherPayload['data']['important'], 0, 'teacher cannot mark important');

    $teacherCreate2 = portal_event_create($teacherPayload['data'], (int) $teacherUser['id']);
    expect_true($teacherCreate2['ok'], 'teacher creates assigned course event');
    $courseEventId = (int) $teacherCreate2['id'];
    $createdEventIds[] = $courseEventId;
    if ($teacherCreate['ok']) {
        $createdEventIds[] = (int) $teacherCreate['id'];
    }

    $blockedAttempt = portal_event_create([
        'title' => 'Blocked',
        'summary' => 'Should fail',
        'description' => '',
        'starts_at' => date('Y-m-d H:i:s', strtotime('+6 days')),
        'ends_at' => '',
        'location' => '',
        'online_url' => '',
        'course_id' => $blockedCourseId,
        'important' => 0,
    ], (int) $teacherUser['id']);
    expect_true(!$blockedAttempt['ok'], 'teacher create for unassigned course fails');

    // Admin creates blocked-course event for IDOR tests
    ev_login_as($adminUser);
    $blockedEvent = portal_event_create([
        'title' => 'Blocked Course Event',
        'summary' => 'Not for open student',
        'description' => '',
        'starts_at' => date('Y-m-d H:i:s', strtotime('+7 days')),
        'ends_at' => '',
        'location' => 'Elsewhere',
        'online_url' => 'https://secret.example.com',
        'course_id' => $blockedCourseId,
        'important' => 0,
    ], (int) $adminUser['id']);
    expect_true($blockedEvent['ok'], 'admin creates blocked-course event');
    $blockedEventId = (int) $blockedEvent['id'];
    $createdEventIds[] = $blockedEventId;

    // ── Visibility / IDOR ────────────────────────────────────────────────────
    echo "\n=== Visibility ===\n";

    ev_login_as($studentUser);
    $schoolRow = portal_event_get($schoolEventId, true);
    expect_true($schoolRow !== null, 'student can view school-wide event');
    $courseRow = portal_event_get($courseEventId, true);
    expect_true($courseRow !== null, 'student can view enrolled course event');
    $blockedRow = portal_event_get($blockedEventId, true);
    expect_true($blockedRow === null, 'student cannot view other course event (IDOR)');

    ev_login_as($outsiderUser);
    expect_true(portal_event_get($courseEventId, true) === null, 'unenrolled student cannot view course event');
    expect_true(portal_event_get($schoolEventId, true) !== null, 'unenrolled student can view school-wide');

    // ── Manage / cancel / delete auth ────────────────────────────────────────
    echo "\n=== Manage permissions ===\n";

    ev_login_as($studentUser);
    expect_true(!portal_event_can_manage($schoolRow ?? ['course_id' => null]), 'student cannot manage school event');
    expect_true(portal_event_cancel($courseEventId)['ok'] === false, 'student cancel fails');
    expect_true(portal_event_delete($courseEventId)['ok'] === false, 'student delete fails');

    ev_login_as($teacherUser);
    expect_true(portal_event_can_manage(portal_event_get($courseEventId, false) ?: []), 'teacher can manage assigned course event');
    expect_true(!portal_event_can_manage(portal_event_get($schoolEventId, false) ?: ['course_id' => null]), 'teacher cannot manage school-wide');
    expect_true(portal_event_cancel($schoolEventId)['ok'] === false, 'teacher cancel school-wide fails');

    // ── Featured / cancel visibility ─────────────────────────────────────────
    echo "\n=== Featured and cancel ===\n";

    ev_login_as($adminUser);
    $featured = portal_event_featured();
    expect_true($featured !== null && (int) $featured['id'] === $schoolEventId, 'important school-wide is featured');

    $cancel = portal_event_cancel($schoolEventId);
    expect_true($cancel['ok'], 'admin can cancel school event');
    $cancelled = portal_event_get($schoolEventId, false);
    expect_eq((string) ($cancelled['status'] ?? ''), 'cancelled', 'cancelled status stored');

    ev_login_as($studentUser);
    $stillVisible = portal_event_get($schoolEventId, true);
    expect_true($stillVisible !== null, 'cancelled event remains viewable');
    $featuredAfter = portal_event_featured();
    expect_true(
        $featuredAfter === null || (int) $featuredAfter['id'] !== $schoolEventId,
        'cancelled event is not featured'
    );

    // ── Notifications ────────────────────────────────────────────────────────
    echo "\n=== Notifications ===\n";

    ev_login_as($adminUser);
    $notifyEvent = portal_event_create([
        'title' => 'Notify Me Event',
        'summary' => 'Notification test',
        'description' => '',
        'starts_at' => date('Y-m-d H:i:s', strtotime('+8 days')),
        'ends_at' => '',
        'location' => '',
        'online_url' => '',
        'course_id' => $courseId,
        'important' => 0,
    ], (int) $adminUser['id']);
    expect_true($notifyEvent['ok'], 'notify fixture event created');
    $notifyId = (int) $notifyEvent['id'];
    $createdEventIds[] = $notifyId;
    $notifyRow = portal_event_get($notifyId, false);
    $recipients = portal_event_notify_recipients($notifyRow ?: []);
    expect_true(in_array((int) $studentUser['id'], $recipients, true), 'enrolled student is recipient');
    expect_true(in_array((int) $teacherUser['id'], $recipients, true), 'assigned teacher is recipient');
    expect_true(!in_array((int) $outsiderUser['id'], $recipients, true), 'outsider not recipient for course event');

    portal_save_user_preferences((int) $studentUser['id'], [
        'notify_grades' => 1,
        'notify_qa' => 1,
        'notify_announcements' => 1,
        'notify_events' => 0,
    ]);
    $beforeCount = (int) $db->prepare(
        "SELECT COUNT(*) FROM portal_notifications WHERE user_id = ? AND type = 'event' AND link = ?"
    )->execute([$studentUser['id'], 'events.php?event=' . $notifyId]) ?: 0;
    // recount properly
    $cntStmt = $db->prepare("SELECT COUNT(*) FROM portal_notifications WHERE user_id = ? AND type = 'event' AND link = ?");
    $cntStmt->execute([$studentUser['id'], 'events.php?event=' . $notifyId]);
    $beforeCount = (int) $cntStmt->fetchColumn();

    portal_event_send_notifications($notifyRow ?: [], 'new');
    $cntStmt->execute([$studentUser['id'], 'events.php?event=' . $notifyId]);
    $afterOptOut = (int) $cntStmt->fetchColumn();
    expect_eq($afterOptOut, $beforeCount, 'opted-out student receives no event notification');

    portal_save_user_preferences((int) $studentUser['id'], [
        'notify_grades' => 1,
        'notify_qa' => 1,
        'notify_announcements' => 1,
        'notify_events' => 1,
    ]);
    portal_event_send_notifications($notifyRow ?: [], 'new');
    $cntStmt->execute([$studentUser['id'], 'events.php?event=' . $notifyId]);
    $afterOptIn = (int) $cntStmt->fetchColumn();
    expect_true($afterOptIn > $beforeCount, 'opted-in student receives event notification');

    $outStmt = $db->prepare("SELECT COUNT(*) FROM portal_notifications WHERE user_id = ? AND type = 'event' AND link = ?");
    $outStmt->execute([$outsiderUser['id'], 'events.php?event=' . $notifyId]);
    expect_eq((int) $outStmt->fetchColumn(), 0, 'outsider receives no course-event notification');

    ev_login_as($adminUser);
    expect_true(portal_event_delete($notifyId)['ok'], 'admin can delete notify fixture event');
    $createdEventIds = array_values(array_filter(
        $createdEventIds,
        static fn (int $eid): bool => $eid !== $notifyId
    ));
    $cntStmt->execute([$studentUser['id'], 'events.php?event=' . $notifyId]);
    expect_eq((int) $cntStmt->fetchColumn(), 0, 'deleting event unsends its notifications');

    // ── Dashboard relevance ──────────────────────────────────────────────────
    echo "\n=== Dashboard ===\n";
    ev_login_as($studentUser);
    $dash = portal_events_for_dashboard(10);
    $dashIds = array_map(static fn (array $e): int => (int) $e['id'], $dash);
    expect_true(!in_array($blockedEventId, $dashIds, true), 'dashboard excludes non-visible course events');
    expect_true(
        in_array($courseEventId, $dashIds, true),
        'dashboard includes visible upcoming course events'
    );

    // ── CSRF helper presence (request-level covered in Playwright) ───────────
    echo "\n=== CSRF helpers ===\n";
    expect_true(function_exists('portal_verify_csrf'), 'portal_verify_csrf exists');
    expect_true(function_exists('portal_csrf_field'), 'portal_csrf_field exists');

    echo "\n=== Done ===\n";
} catch (Throwable $e) {
    $failures++;
    echo 'FAIL  exception: ' . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    foreach ($createdEventIds as $eid) {
        $db->prepare('DELETE FROM events WHERE id = ?')->execute([$eid]);
    }
    if ($courseId > 0) {
        $db->prepare('DELETE FROM courses WHERE id = ?')->execute([$courseId]);
    }
    if ($blockedCourseId > 0) {
        $db->prepare('DELETE FROM courses WHERE id = ?')->execute([$blockedCourseId]);
    }
    foreach ([$adminUser, $teacherUser, $studentUser, $outsiderUser] as $u) {
        if ($u) {
            $db->prepare('DELETE FROM portal_notifications WHERE user_id = ?')->execute([(int) $u['id']]);
            $db->prepare('DELETE FROM user_preferences WHERE user_id = ?')->execute([(int) $u['id']]);
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([(int) $u['id']]);
        }
    }
    ev_logout();
}

exit($failures > 0 ? 1 : 0);
