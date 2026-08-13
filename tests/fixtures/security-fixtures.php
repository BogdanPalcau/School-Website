<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

$command = $argv[1] ?? 'setup';
$db = portal_db();

const SECURITY_PASSWORD = 'SecurityPass123!';
const SECURE_OPEN_SLUG = 'security-open-course';
const SECURE_BLOCKED_SLUG = 'security-blocked-course';
const SECURE_EMPTY_SLUG = 'security-empty-course';

/**
 * @return list<string>
 */
function security_fixture_usernames(): array
{
    return [
        'sec_owner',
        'sec_admin',
        'sec_teacher',
        'sec_supervisor_teacher',
        'sec_student',
        'sec_student_two',
        'sec_outsider',
        'sec_role_target',
        'csrf_created_user',
    ];
}

/**
 * @return list<string>
 */
function security_fixture_course_slugs(): array
{
    return [SECURE_OPEN_SLUG, SECURE_BLOCKED_SLUG, SECURE_EMPTY_SLUG];
}

function security_cleanup(PDO $db): void
{
    $slugs = security_fixture_course_slugs();
    $slugPlaceholders = implode(',', array_fill(0, count($slugs), '?'));

    $submissionFileStmt = $db->prepare(
        "SELECT cs.filepath
         FROM course_submissions cs
         JOIN courses c ON c.id = cs.course_id
         WHERE c.slug IN ({$slugPlaceholders}) AND cs.filepath != ''"
    );
    $submissionFileStmt->execute($slugs);
    foreach ($submissionFileStmt->fetchAll(PDO::FETCH_COLUMN) as $filePath) {
        $absolute = portal_uploads_base() . DIRECTORY_SEPARATOR . (string) $filePath;
        if (is_file($absolute)) {
            @unlink($absolute);
            @rmdir(dirname($absolute));
        }
    }

    $fileStmt = $db->prepare(
        "SELECT cfi.file_path
         FROM course_folder_items cfi
         JOIN courses c ON c.id = cfi.course_id
         WHERE c.slug IN ({$slugPlaceholders}) AND cfi.file_path != ''"
    );
    $fileStmt->execute($slugs);
    foreach ($fileStmt->fetchAll(PDO::FETCH_COLUMN) as $filePath) {
        $absolute = portal_uploads_base() . DIRECTORY_SEPARATOR . (string) $filePath;
        if (is_file($absolute)) {
            @unlink($absolute);
            @rmdir(dirname($absolute));
        }
    }

    $usernames = security_fixture_usernames();
    foreach ($usernames as $username) {
        $db->prepare(
            'DELETE FROM password_reset_tokens
             WHERE user_id IN (SELECT id FROM users WHERE username = ?)'
        )->execute([$username]);
        $db->prepare(
            'DELETE FROM events WHERE created_by IN (SELECT id FROM users WHERE username = ?)'
        )->execute([$username]);
        $db->prepare('DELETE FROM users WHERE username = ?')->execute([$username]);
    }

    $db->prepare("DELETE FROM events WHERE title LIKE 'Security %' OR title = 'Forged CSRF Event'")->execute();

    foreach ($slugs as $slug) {
        $db->prepare('DELETE FROM courses WHERE slug = ?')->execute([$slug]);
    }

    $db->exec("DELETE FROM login_attempts WHERE ip IN ('127.0.0.1', '::1', 'unknown')");
    $db->exec("DELETE FROM password_reset_attempts WHERE ip IN ('127.0.0.1', '::1', 'unknown')");
    $db->exec("DELETE FROM security_events WHERE details LIKE 'sec_bulk_ui_%'");

    // Disposable rows created by Pass 2 CRUD / journey specs.
    $db->exec("DELETE FROM users WHERE username LIKE 'sec_crud_%'");
    $db->exec("DELETE FROM courses WHERE slug LIKE 'security-crud-%'");
}

function security_insert_user(PDO $db, string $username, string $email, string $name, string $initials, string $role): int
{
    $db->prepare(
        'INSERT INTO users (username, email, password_hash, name, year, programme, initials, role)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([
        $username,
        $email,
        password_hash(SECURITY_PASSWORD, PASSWORD_DEFAULT),
        $name,
        'Year 11',
        'Security Test',
        $initials,
        $role,
    ]);

    return (int) $db->lastInsertId();
}

function security_insert_course(PDO $db, string $slug, string $code, string $title): int
{
    $db->prepare(
        'INSERT INTO courses
         (slug, code, title, full_title, summary, year_group, term, status, status_label, accent, meeting, room, notice, student_count)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $slug,
        $code,
        $title,
        'Security Test - ' . $title,
        'Temporary course seeded by Playwright security tests.',
        'Security',
        'Test term',
        'open',
        'Open',
        '#c1202f',
        'Mon | 09:00',
        'Security Lab',
        '',
        0,
    ]);

    return (int) $db->lastInsertId();
}

function security_setup(PDO $db): array
{
    security_cleanup($db);

    $ownerId = security_insert_user($db, 'sec_owner', 'sec_owner@example.test', 'Security Owner', 'SO', 'owner');
    $adminId = security_insert_user($db, 'sec_admin', 'sec_admin@example.test', 'Security Admin', 'SA', 'admin');
    $teacherId = security_insert_user($db, 'sec_teacher', 'sec_teacher@example.test', 'Security Teacher', 'ST', 'teacher');
    $supervisorTeacherId = security_insert_user(
        $db,
        'sec_supervisor_teacher',
        'sec_supervisor_teacher@example.test',
        'Security Supervisor Teacher',
        'SV',
        'teacher'
    );
    $studentId = security_insert_user($db, 'sec_student', 'sec_student@example.test', 'Security Student', 'SS', 'student');
    $studentTwoId = security_insert_user(
        $db,
        'sec_student_two',
        'sec_student_two@example.test',
        'Security Student Two',
        'S2',
        'student'
    );
    $outsiderId = security_insert_user($db, 'sec_outsider', 'sec_outsider@example.test', 'Security Outsider', 'SX', 'student');
    $roleTargetId = security_insert_user(
        $db,
        'sec_role_target',
        'sec_role_target@example.test',
        'Security Role Target',
        'RT',
        'student'
    );

    $openCourseId = security_insert_course($db, SECURE_OPEN_SLUG, 'SEC-OPEN', 'Open Course');
    $blockedCourseId = security_insert_course($db, SECURE_BLOCKED_SLUG, 'SEC-BLOCK', 'Blocked Course');
    $emptyCourseId = security_insert_course($db, SECURE_EMPTY_SLUG, 'SEC-EMPTY', 'Empty Course');

    $db->prepare('INSERT INTO enrollments (user_id, course_id) VALUES (?,?)')->execute([$studentId, $openCourseId]);
    $db->prepare('INSERT INTO enrollments (user_id, course_id) VALUES (?,?)')->execute([$studentTwoId, $openCourseId]);
    $db->prepare('INSERT INTO course_teachers (course_id, user_id, assignment_role) VALUES (?,?,?)')
        ->execute([$openCourseId, $teacherId, 'teacher']);
    $db->prepare('INSERT INTO course_teachers (course_id, user_id, assignment_role) VALUES (?,?,?)')
        ->execute([$openCourseId, $supervisorTeacherId, 'supervisor']);

    $db->prepare('INSERT INTO course_staff (course_id, name, role) VALUES (?,?,?)')
        ->execute([$openCourseId, 'Security Teacher', 'Teacher']);
    $db->prepare('INSERT INTO course_staff (course_id, name, role) VALUES (?,?,?)')
        ->execute([$openCourseId, 'Security Supervisor Teacher', 'Course Supervisor']);
    $db->prepare('INSERT INTO course_staff (course_id, name, role) VALUES (?,?,?)')
        ->execute([$blockedCourseId, 'Security Teacher', 'Teacher']);

    $db->prepare('INSERT INTO course_folders (course_id, title, description, locked) VALUES (?,?,?,0)')
        ->execute([$openCourseId, 'Security Upload Folder', 'Folder for upload security tests.']);
    $folderId = (int) $db->lastInsertId();

    $db->prepare('INSERT INTO course_discussion_topics (course_id, user_id, title, body) VALUES (?,?,?,?)')
        ->execute([$openCourseId, $teacherId, 'Security Topic', 'Topic for access-control tests.']);
    $topicId = (int) $db->lastInsertId();

    $db->prepare('INSERT INTO course_groups (course_id, title, description, max_members) VALUES (?,?,?,0)')
        ->execute([$openCourseId, 'Open Security Group', 'Source group for student CSRF token.']);
    $openGroupId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO course_group_members (group_id, user_id) VALUES (?,?)')
        ->execute([$openGroupId, $studentId]);

    $db->prepare('INSERT INTO course_folders (course_id, title, description, locked) VALUES (?,?,?,0)')
        ->execute([$blockedCourseId, 'Blocked IDOR Folder', 'Cross-course target folder.']);
    $blockedFolderId = (int) $db->lastInsertId();

    $blockedUploadDir = portal_uploads_base() . DIRECTORY_SEPARATOR . 'courses' . DIRECTORY_SEPARATOR . $blockedCourseId;
    if (!is_dir($blockedUploadDir)) {
        mkdir($blockedUploadDir, 0755, true);
    }
    $blockedFilePath = 'courses' . DIRECTORY_SEPARATOR . $blockedCourseId . DIRECTORY_SEPARATOR . 'idor-blocked-material.txt';
    file_put_contents(portal_uploads_base() . DIRECTORY_SEPARATOR . $blockedFilePath, 'blocked material');

    $db->prepare(
        "INSERT INTO course_folder_items
         (folder_id, course_id, type, title, description, file_path, file_name, allow_download)
         VALUES (?,?,?,?,?,?,?,1)"
    )->execute([
        $blockedFolderId,
        $blockedCourseId,
        'document',
        'Blocked IDOR Material',
        'Cross-course target material.',
        $blockedFilePath,
        'blocked-material.txt',
    ]);
    $blockedItemId = (int) $db->lastInsertId();

    $blockedSubmissionDir = portal_uploads_base()
        . DIRECTORY_SEPARATOR . 'submissions'
        . DIRECTORY_SEPARATOR . $blockedItemId
        . DIRECTORY_SEPARATOR . $studentId;
    if (!is_dir($blockedSubmissionDir)) {
        mkdir($blockedSubmissionDir, 0755, true);
    }
    $blockedSubmissionPath = 'submissions'
        . DIRECTORY_SEPARATOR . $blockedItemId
        . DIRECTORY_SEPARATOR . $studentId
        . DIRECTORY_SEPARATOR . 'idor-blocked-submission.txt';
    file_put_contents(portal_uploads_base() . DIRECTORY_SEPARATOR . $blockedSubmissionPath, 'blocked submission');

    $db->prepare(
        'INSERT INTO course_submissions (item_id, course_id, user_id, filename, filepath, filesize)
         VALUES (?,?,?,?,?,?)'
    )->execute([
        $blockedItemId,
        $blockedCourseId,
        $outsiderId,
        'blocked-submission.txt',
        $blockedSubmissionPath,
        strlen('blocked submission'),
    ]);
    $blockedSubmissionId = (int) $db->lastInsertId();

    $db->prepare('INSERT INTO course_groups (course_id, title, description, max_members) VALUES (?,?,?,0)')
        ->execute([$blockedCourseId, 'Blocked IDOR Group', 'Cross-course target group.']);
    $blockedGroupId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO course_group_members (group_id, user_id) VALUES (?,?)')
        ->execute([$blockedGroupId, $studentId]);

    return [
        'password' => SECURITY_PASSWORD,
        'users' => [
            'owner' => 'sec_owner',
            'admin' => 'sec_admin',
            'teacher' => 'sec_teacher',
            'supervisorTeacher' => 'sec_supervisor_teacher',
            'student' => 'sec_student',
            'studentTwo' => 'sec_student_two',
            'outsider' => 'sec_outsider',
            'roleTarget' => 'sec_role_target',
        ],
        'userIds' => [
            'owner' => $ownerId,
            'admin' => $adminId,
            'teacher' => $teacherId,
            'supervisorTeacher' => $supervisorTeacherId,
            'student' => $studentId,
            'studentTwo' => $studentTwoId,
            'outsider' => $outsiderId,
            'roleTarget' => $roleTargetId,
        ],
        'emails' => [
            'owner' => 'sec_owner@example.test',
            'admin' => 'sec_admin@example.test',
            'teacher' => 'sec_teacher@example.test',
            'supervisorTeacher' => 'sec_supervisor_teacher@example.test',
            'student' => 'sec_student@example.test',
            'studentTwo' => 'sec_student_two@example.test',
            'outsider' => 'sec_outsider@example.test',
            'roleTarget' => 'sec_role_target@example.test',
        ],
        'assignmentRoles' => [
            'openCourse' => [
                'teacher' => 'teacher',
                'supervisorTeacher' => 'supervisor',
            ],
        ],
        'courses' => [
            'openSlug' => SECURE_OPEN_SLUG,
            'blockedSlug' => SECURE_BLOCKED_SLUG,
            'emptySlug' => SECURE_EMPTY_SLUG,
            'openCourseId' => $openCourseId,
            'blockedCourseId' => $blockedCourseId,
            'emptyCourseId' => $emptyCourseId,
        ],
        'folderId' => $folderId,
        'topicId' => $topicId,
        'idorTargets' => [
            'blockedFolderId' => $blockedFolderId,
            'blockedItemId' => $blockedItemId,
            'blockedGroupId' => $blockedGroupId,
            'blockedSubmissionId' => $blockedSubmissionId,
            'blockedMaterialPath' => $blockedFilePath,
            'blockedSubmissionPath' => $blockedSubmissionPath,
        ],
    ];
}

function security_count(PDO $db, string $kind, string $value): int
{
    if ($kind === 'user') {
        $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $stmt->execute([$value]);
        return (int) $stmt->fetchColumn();
    }

    if ($kind === 'user-role') {
        [$username, $role] = array_pad(explode('|', $value, 2), 2, '');
        $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ? AND role = ?');
        $stmt->execute([$username, $role]);
        return (int) $stmt->fetchColumn();
    }

    if ($kind === 'user-email') {
        [$username, $email] = array_pad(explode('|', $value, 2), 2, '');
        $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ? AND LOWER(email) = LOWER(?)');
        $stmt->execute([$username, $email]);
        return (int) $stmt->fetchColumn();
    }

    if ($kind === 'assignment-role') {
        [$slug, $username, $role] = array_pad(explode('|', $value, 3), 3, '');
        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM course_teachers ct
             JOIN courses c ON c.id = ct.course_id
             JOIN users u ON u.id = ct.user_id
             WHERE c.slug = ? AND u.username = ? AND ct.assignment_role = ?'
        );
        $stmt->execute([$slug, $username, $role]);
        return (int) $stmt->fetchColumn();
    }

    if ($kind === 'course') {
        $stmt = $db->prepare('SELECT COUNT(*) FROM courses WHERE slug = ?');
        $stmt->execute([$value]);
        return (int) $stmt->fetchColumn();
    }

    if ($kind === 'course-status') {
        [$slug, $status] = array_pad(explode('|', $value, 2), 2, '');
        $stmt = $db->prepare('SELECT COUNT(*) FROM courses WHERE slug = ? AND status = ?');
        $stmt->execute([$slug, $status]);
        return (int) $stmt->fetchColumn();
    }

    if ($kind === 'enrollment') {
        [$username, $slug] = array_pad(explode('|', $value, 2), 2, '');
        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM enrollments e
             JOIN users u ON u.id = e.user_id
             JOIN courses c ON c.id = e.course_id
             WHERE u.username = ? AND c.slug = ?'
        );
        $stmt->execute([$username, $slug]);
        return (int) $stmt->fetchColumn();
    }

    if ($kind === 'submission-user') {
        [$itemId, $username] = array_pad(explode('|', $value, 2), 2, '');
        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM course_submissions cs
             JOIN users u ON u.id = cs.user_id
             WHERE cs.item_id = ? AND u.username = ?'
        );
        $stmt->execute([(int) $itemId, $username]);
        return (int) $stmt->fetchColumn();
    }

    if ($kind === 'submission-score') {
        [$itemId, $username, $score] = array_pad(explode('|', $value, 3), 3, '');
        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM course_submissions cs
             JOIN users u ON u.id = cs.user_id
             WHERE cs.item_id = ? AND u.username = ? AND cs.score = ?'
        );
        $stmt->execute([(int) $itemId, $username, (int) $score]);
        return (int) $stmt->fetchColumn();
    }

    if ($kind === 'announcement') {
        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM course_announcements ca
             JOIN courses c ON c.id = ca.course_id
             WHERE c.slug = ? AND ca.title = ?'
        );
        [$slug, $title] = array_pad(explode('|', $value, 2), 2, '');
        $stmt->execute([$slug, $title]);
        return (int) $stmt->fetchColumn();
    }

    if ($kind === 'group') {
        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM course_groups cg
             JOIN courses c ON c.id = cg.course_id
             WHERE c.slug = ? AND cg.title = ?'
        );
        [$slug, $title] = array_pad(explode('|', $value, 2), 2, '');
        $stmt->execute([$slug, $title]);
        return (int) $stmt->fetchColumn();
    }

    if ($kind === 'schedule') {
        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM course_schedule css
             JOIN courses c ON c.id = css.course_id
             WHERE c.slug = ? AND css.day_of_week = ? AND css.start_time = ?'
        );
        [$slug, $day, $start] = array_pad(explode('|', $value, 3), 3, '');
        $stmt->execute([$slug, $day, $start]);
        return (int) $stmt->fetchColumn();
    }

    if ($kind === 'folder-locked') {
        $stmt = $db->prepare('SELECT COUNT(*) FROM course_folders WHERE id = ? AND locked = 1');
        $stmt->execute([(int) $value]);
        return (int) $stmt->fetchColumn();
    }

    if ($kind === 'unread-notifications') {
        $stmt = $db->prepare(
            "SELECT COUNT(*)
             FROM portal_notifications n
             JOIN users u ON u.id = n.user_id
             WHERE u.username = ? AND COALESCE(n.read_at, '') = ''"
        );
        $stmt->execute([$value]);
        return (int) $stmt->fetchColumn();
    }

    if ($kind === 'activity-by-title') {
        $stmt = $db->prepare('SELECT COUNT(*) FROM course_activities WHERE title = ?');
        $stmt->execute([$value]);
        return (int) $stmt->fetchColumn();
    }

    if ($kind === 'activity-attempt-version') {
        [$activityId, $versionId] = array_pad(explode('|', $value, 2), 2, '');
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM activity_attempts
             WHERE activity_id = ? AND activity_version_id = ?'
        );
        $stmt->execute([(int) $activityId, (int) $versionId]);
        return (int) $stmt->fetchColumn();
    }

    if ($kind === 'folder') {
        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM course_folders cf
             JOIN courses c ON c.id = cf.course_id
             WHERE c.slug = ? AND cf.title = ?'
        );
        $stmt->execute([SECURE_OPEN_SLUG, $value]);
        return (int) $stmt->fetchColumn();
    }

    if ($kind === 'item') {
        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM course_folder_items cfi
             JOIN courses c ON c.id = cfi.course_id
             WHERE c.slug = ? AND cfi.title = ?'
        );
        $stmt->execute([SECURE_OPEN_SLUG, $value]);
        return (int) $stmt->fetchColumn();
    }

    if ($kind === 'item-id') {
        $stmt = $db->prepare('SELECT COUNT(*) FROM course_folder_items WHERE id = ?');
        $stmt->execute([(int) $value]);
        return (int) $stmt->fetchColumn();
    }

    if ($kind === 'folder-id') {
        $stmt = $db->prepare('SELECT COUNT(*) FROM course_folders WHERE id = ?');
        $stmt->execute([(int) $value]);
        return (int) $stmt->fetchColumn();
    }

    if ($kind === 'submission-for-item') {
        $stmt = $db->prepare('SELECT COUNT(*) FROM course_submissions WHERE item_id = ?');
        $stmt->execute([(int) $value]);
        return (int) $stmt->fetchColumn();
    }

    if ($kind === 'group-member') {
        $stmt = $db->prepare(
            "SELECT COUNT(*)
             FROM course_group_members cgm
             JOIN users u ON u.id = cgm.user_id
             WHERE cgm.group_id = ? AND u.username = 'sec_student'"
        );
        $stmt->execute([(int) $value]);
        return (int) $stmt->fetchColumn();
    }

    if ($kind === 'event') {
        $stmt = $db->prepare('SELECT COUNT(*) FROM events WHERE title = ?');
        $stmt->execute([$value]);
        return (int) $stmt->fetchColumn();
    }

    if ($kind === 'security-ip') {
        $stmt = $db->prepare('SELECT COUNT(*) FROM security_events WHERE ip_address = ?');
        $stmt->execute([$value]);
        return (int) $stmt->fetchColumn();
    }

    if ($kind === 'file') {
        return is_file(portal_uploads_base() . DIRECTORY_SEPARATOR . $value) ? 1 : 0;
    }

    throw new InvalidArgumentException('Unknown count kind: ' . $kind);
}

/**
 * Insert a password-reset token for Playwright (SMTP may be unset, so UI never stores one).
 *
 * @return array{token:string,state:string,username:string}
 */
function security_seed_reset_token(PDO $db, string $username, string $state = 'valid'): array
{
    $state = in_array($state, ['valid', 'expired', 'used'], true) ? $state : 'valid';
    $stmt = $db->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $userId = (int) $stmt->fetchColumn();
    if ($userId <= 0) {
        throw new InvalidArgumentException('Unknown user for reset token: ' . $username);
    }

    $db->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([$userId]);

    $token = bin2hex(random_bytes(32));
    $now = time();
    $expiresAt = $state === 'expired' ? ($now - 60) : ($now + 3600);
    $usedAt = $state === 'used' ? $now : null;

    $db->prepare(
        'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, used_at, requested_ip, created_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        $userId,
        portal_password_reset_hash($token),
        $expiresAt,
        $usedAt,
        '127.0.0.1',
        $now,
    ]);

    return [
        'token' => $token,
        'state' => $state,
        'username' => $username,
    ];
}

/**
 * @return array{off:bool,on:bool}
 */
function security_check_dev_security_flag(): array
{
    $previous = getenv('PORTAL_SHOW_DEVELOPER_SECURITY');

    putenv('PORTAL_SHOW_DEVELOPER_SECURITY');
    putenv('PORTAL_SHOW_DEVELOPER_SECURITY=0');
    $off = portal_show_developer_security();

    putenv('PORTAL_SHOW_DEVELOPER_SECURITY=1');
    $on = portal_show_developer_security();

    if ($previous === false) {
        putenv('PORTAL_SHOW_DEVELOPER_SECURITY');
    } else {
        putenv('PORTAL_SHOW_DEVELOPER_SECURITY=' . $previous);
    }

    return ['off' => $off, 'on' => $on];
}

function security_seed_ui_events(PDO $db): array
{
    $db->prepare("DELETE FROM security_events WHERE details LIKE 'sec_bulk_ui_%'")->execute();
    $ip = '198.51.100.77';
    $now = gmdate('Y-m-d H:i:s');
    $insert = $db->prepare("
        INSERT INTO security_events
            (event_type, severity, user_id, username, ip_address, user_agent, route, method, details, reviewed, created_at)
        VALUES ('failed_login', 'medium', NULL, ?, ?, 'sec-bulk-ui', '/login.php', 'POST', ?, 0, ?)
    ");
    $ids = [];
    foreach (['bulk_a', 'bulk_b', 'bulk_c'] as $i => $user) {
        $insert->execute([$user, $ip, 'sec_bulk_ui_' . $user, $now]);
        $ids[] = (int) $db->lastInsertId();
    }

    return ['ip' => $ip, 'ids' => $ids];
}

/**
 * @return array<string, int|string|null>
 */
function security_lookup(PDO $db, string $kind, string $value): array
{
    if ($kind === 'item-id') {
        $stmt = $db->prepare(
            'SELECT cfi.id
             FROM course_folder_items cfi
             JOIN courses c ON c.id = cfi.course_id
             WHERE c.slug = ? AND cfi.title = ?
             ORDER BY cfi.id DESC LIMIT 1'
        );
        [$slug, $title] = array_pad(explode('|', $value, 2), 2, '');
        $stmt->execute([$slug, $title]);
        $id = (int) $stmt->fetchColumn();
        return ['id' => $id > 0 ? $id : null];
    }

    if ($kind === 'folder-id') {
        $stmt = $db->prepare(
            'SELECT cf.id
             FROM course_folders cf
             JOIN courses c ON c.id = cf.course_id
             WHERE c.slug = ? AND cf.title = ?
             ORDER BY cf.id DESC LIMIT 1'
        );
        [$slug, $title] = array_pad(explode('|', $value, 2), 2, '');
        $stmt->execute([$slug, $title]);
        $id = (int) $stmt->fetchColumn();
        return ['id' => $id > 0 ? $id : null];
    }

    if ($kind === 'activity-id') {
        $stmt = $db->prepare(
            'SELECT id FROM course_activities WHERE title = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$value]);
        $id = (int) $stmt->fetchColumn();
        return ['id' => $id > 0 ? $id : null];
    }

    if ($kind === 'announcement-id') {
        $stmt = $db->prepare(
            'SELECT ca.id
             FROM course_announcements ca
             JOIN courses c ON c.id = ca.course_id
             WHERE c.slug = ? AND ca.title = ?
             ORDER BY ca.id DESC LIMIT 1'
        );
        [$slug, $title] = array_pad(explode('|', $value, 2), 2, '');
        $stmt->execute([$slug, $title]);
        $id = (int) $stmt->fetchColumn();
        return ['id' => $id > 0 ? $id : null];
    }

    if ($kind === 'group-id') {
        $stmt = $db->prepare(
            'SELECT cg.id
             FROM course_groups cg
             JOIN courses c ON c.id = cg.course_id
             WHERE c.slug = ? AND cg.title = ?
             ORDER BY cg.id DESC LIMIT 1'
        );
        [$slug, $title] = array_pad(explode('|', $value, 2), 2, '');
        $stmt->execute([$slug, $title]);
        $id = (int) $stmt->fetchColumn();
        return ['id' => $id > 0 ? $id : null];
    }

    if ($kind === 'submission') {
        [$itemId, $username] = array_pad(explode('|', $value, 2), 2, '');
        $stmt = $db->prepare(
            'SELECT cs.id, cs.score, cs.feedback
             FROM course_submissions cs
             JOIN users u ON u.id = cs.user_id
             WHERE cs.item_id = ? AND u.username = ?
             ORDER BY cs.id DESC LIMIT 1'
        );
        $stmt->execute([(int) $itemId, $username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        return $row
            ? [
                'id' => (int) $row['id'],
                'score' => $row['score'] === null ? null : (int) $row['score'],
                'feedback' => (string) ($row['feedback'] ?? ''),
            ]
            : ['id' => null, 'score' => null, 'feedback' => ''];
    }

    if ($kind === 'published-version') {
        $stmt = $db->prepare(
            "SELECT id, version_number FROM activity_versions
             WHERE activity_id = ? AND status = 'published'
             ORDER BY version_number DESC, id DESC LIMIT 1"
        );
        $stmt->execute([(int) $value]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        return $row
            ? ['id' => (int) $row['id'], 'version_number' => (int) $row['version_number']]
            : ['id' => null, 'version_number' => null];
    }

    if ($kind === 'attempt') {
        [$activityId, $username] = array_pad(explode('|', $value, 2), 2, '');
        $stmt = $db->prepare(
            'SELECT aa.id, aa.activity_version_id, aa.status
             FROM activity_attempts aa
             JOIN users u ON u.id = aa.user_id
             WHERE aa.activity_id = ? AND u.username = ?
             ORDER BY aa.id DESC LIMIT 1'
        );
        $stmt->execute([(int) $activityId, $username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        return $row
            ? [
                'id' => (int) $row['id'],
                'activity_version_id' => (int) $row['activity_version_id'],
                'status' => (string) $row['status'],
            ]
            : ['id' => null, 'activity_version_id' => null, 'status' => null];
    }

    if ($kind === 'unread-notification-id') {
        $stmt = $db->prepare(
            "SELECT n.id
             FROM portal_notifications n
             JOIN users u ON u.id = n.user_id
             WHERE u.username = ? AND COALESCE(n.read_at, '') = ''
             ORDER BY n.id DESC LIMIT 1"
        );
        $stmt->execute([$value]);
        $id = (int) $stmt->fetchColumn();
        return ['id' => $id > 0 ? $id : null];
    }

    if ($kind === 'user-id') {
        $stmt = $db->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$value]);
        $id = (int) $stmt->fetchColumn();
        return ['id' => $id > 0 ? $id : null];
    }

    if ($kind === 'course-id') {
        $stmt = $db->prepare('SELECT id FROM courses WHERE slug = ? LIMIT 1');
        $stmt->execute([$value]);
        $id = (int) $stmt->fetchColumn();
        return ['id' => $id > 0 ? $id : null];
    }

    throw new InvalidArgumentException('Unknown lookup kind: ' . $kind);
}

// Only run CLI when this file is the entry script (safe to require from other fixtures).
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    try {
        if ($command === 'setup') {
            echo json_encode(security_setup($db), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
            exit(0);
        }

        if ($command === 'cleanup') {
            security_cleanup($db);
            echo "cleaned\n";
            exit(0);
        }

        if ($command === 'reset-login') {
            $db->exec("DELETE FROM login_attempts WHERE ip IN ('127.0.0.1', '::1', 'unknown')");
            echo "reset\n";
            exit(0);
        }

        if ($command === 'seed-security-ui') {
            echo json_encode(security_seed_ui_events($db), JSON_THROW_ON_ERROR) . PHP_EOL;
            exit(0);
        }

        if ($command === 'seed-reset-token') {
            echo json_encode(
                security_seed_reset_token($db, (string) ($argv[2] ?? ''), (string) ($argv[3] ?? 'valid')),
                JSON_THROW_ON_ERROR
            ) . PHP_EOL;
            exit(0);
        }

        if ($command === 'check-dev-security') {
            echo json_encode(security_check_dev_security_flag(), JSON_THROW_ON_ERROR) . PHP_EOL;
            exit(0);
        }

        if ($command === 'count') {
            echo security_count($db, (string) ($argv[2] ?? ''), (string) ($argv[3] ?? '')) . PHP_EOL;
            exit(0);
        }

        if ($command === 'lookup') {
            echo json_encode(
                security_lookup($db, (string) ($argv[2] ?? ''), (string) ($argv[3] ?? '')),
                JSON_THROW_ON_ERROR
            ) . PHP_EOL;
            exit(0);
        }

        throw new InvalidArgumentException('Unknown command: ' . $command);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
