<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

$command = $argv[1] ?? 'setup';
$db = portal_db();

const SECURITY_PASSWORD = 'SecurityPass123!';
const SECURE_OPEN_SLUG = 'security-open-course';
const SECURE_BLOCKED_SLUG = 'security-blocked-course';

function security_cleanup(PDO $db): void
{
    $submissionFileStmt = $db->prepare(
        "SELECT cs.filepath
         FROM course_submissions cs
         JOIN courses c ON c.id = cs.course_id
         WHERE c.slug IN (?, ?) AND cs.filepath != ''"
    );
    $submissionFileStmt->execute([SECURE_OPEN_SLUG, SECURE_BLOCKED_SLUG]);
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
         WHERE c.slug IN (?, ?) AND cfi.file_path != ''"
    );
    $fileStmt->execute([SECURE_OPEN_SLUG, SECURE_BLOCKED_SLUG]);
    foreach ($fileStmt->fetchAll(PDO::FETCH_COLUMN) as $filePath) {
        $absolute = portal_uploads_base() . DIRECTORY_SEPARATOR . (string) $filePath;
        if (is_file($absolute)) {
            @unlink($absolute);
            @rmdir(dirname($absolute));
        }
    }

    $usernames = ['sec_admin', 'sec_teacher', 'sec_student', 'sec_outsider', 'csrf_created_user'];
    foreach ($usernames as $username) {
        $db->prepare('DELETE FROM users WHERE username = ?')->execute([$username]);
    }

    foreach ([SECURE_OPEN_SLUG, SECURE_BLOCKED_SLUG] as $slug) {
        $db->prepare('DELETE FROM courses WHERE slug = ?')->execute([$slug]);
    }

    $db->exec("DELETE FROM login_attempts WHERE ip IN ('127.0.0.1', '::1', 'unknown')");
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

    $adminId = security_insert_user($db, 'sec_admin', 'sec_admin@example.test', 'Security Admin', 'SA', 'admin');
    $teacherId = security_insert_user($db, 'sec_teacher', 'sec_teacher@example.test', 'Security Teacher', 'ST', 'teacher');
    $studentId = security_insert_user($db, 'sec_student', 'sec_student@example.test', 'Security Student', 'SS', 'student');
    $outsiderId = security_insert_user($db, 'sec_outsider', 'sec_outsider@example.test', 'Security Outsider', 'SO', 'student');

    $openCourseId = security_insert_course($db, SECURE_OPEN_SLUG, 'SEC-OPEN', 'Open Course');
    $blockedCourseId = security_insert_course($db, SECURE_BLOCKED_SLUG, 'SEC-BLOCK', 'Blocked Course');

    $db->prepare('INSERT INTO enrollments (user_id, course_id) VALUES (?,?)')->execute([$studentId, $openCourseId]);
    $db->prepare('INSERT INTO course_teachers (course_id, user_id, assignment_role) VALUES (?,?,?)')->execute([$openCourseId, $teacherId, 'teacher']);

    $db->prepare('INSERT INTO course_staff (course_id, name, role) VALUES (?,?,?)')
        ->execute([$openCourseId, 'Security Teacher', 'Teacher']);
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
            'admin' => 'sec_admin',
            'teacher' => 'sec_teacher',
            'student' => 'sec_student',
            'outsider' => 'sec_outsider',
        ],
        'courses' => [
            'openSlug' => SECURE_OPEN_SLUG,
            'blockedSlug' => SECURE_BLOCKED_SLUG,
            'openCourseId' => $openCourseId,
            'blockedCourseId' => $blockedCourseId,
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

    if ($kind === 'file') {
        return is_file(portal_uploads_base() . DIRECTORY_SEPARATOR . $value) ? 1 : 0;
    }

    throw new InvalidArgumentException('Unknown count kind: ' . $kind);
}

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

    if ($command === 'count') {
        echo security_count($db, (string) ($argv[2] ?? ''), (string) ($argv[3] ?? '')) . PHP_EOL;
        exit(0);
    }

    throw new InvalidArgumentException('Unknown command: ' . $command);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
