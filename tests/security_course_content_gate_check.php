<?php
declare(strict_types=1);

/**
 * Course content URLs must honour the same closed-module and pre-enrolment
 * gates as course.php. Enrolled students must not open files, lessons, or
 * other activities via direct links while the module is closed or the quiz
 * is unfinished.
 */

require_once __DIR__ . '/../bootstrap.php';

$failures = 0;
$marker = 'content_gate_' . bin2hex(random_bytes(3));

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

function gate_login(array $user): void
{
    $_SESSION['portal_user'] = [
        'id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'email' => (string) $user['email'],
        'name' => (string) $user['name'],
        'year' => (string) ($user['year'] ?? 'Year 11'),
        'programme' => (string) ($user['programme'] ?? 'General'),
        'initials' => (string) ($user['initials'] ?? 'ST'),
        'role' => (string) $user['role'],
        'account_status' => 'active',
    ];
    $_SESSION['portal_login_at'] = gmdate('Y-m-d H:i:s');
}

$viewSrc = file_get_contents(__DIR__ . '/../public/view.php') ?: '';
$downloadSrc = file_get_contents(__DIR__ . '/../public/download.php') ?: '';
$lessonSrc = file_get_contents(__DIR__ . '/../public/lesson-viewer.php') ?: '';
$activitySrc = file_get_contents(__DIR__ . '/../public/activity.php') ?: '';
$mediaSrc = file_get_contents(__DIR__ . '/../public/activity-media.php') ?: '';
$dashSrc = file_get_contents(__DIR__ . '/../public/dashboard.php') ?: '';
$libSrc = file_get_contents(__DIR__ . '/../activity.php') ?: '';

expect_true(
    str_contains($libSrc, 'function portal_course_student_content_block_reason')
        && str_contains($libSrc, "return 'pre_enroll'"),
    'shared helper distinguishes closed vs pre-enrolment blocks'
);
expect_true(
    str_contains($downloadSrc, 'portal_course_content_blocked_for_student'),
    'download.php blocks gated course files'
);
expect_true(
    str_contains($viewSrc, 'portal_course_content_blocked_for_student'),
    'view.php blocks gated course files'
);
expect_true(
    str_contains($lessonSrc, 'portal_course_content_blocked_for_student'),
    'lesson-viewer.php blocks gated lessons'
);
expect_true(
    str_contains($activitySrc, 'portal_course_student_content_block_reason'),
    'activity.php uses the shared content gate'
);
expect_true(
    str_contains($mediaSrc, 'portal_course_content_blocked_for_student'),
    'activity-media.php blocks gated media'
);
expect_true(
    str_contains($dashSrc, 'portal_course_content_blocked_for_student'),
    'dashboard hides continue-watching links into gated modules'
);
expect_true(
    str_contains($libSrc, 'portal_course_student_content_block_reason($courseId, $userId, $activity)'),
    'starting an activity re-checks the course content gate'
);

$db = portal_db();
$hash = password_hash('TempPass!234', PASSWORD_DEFAULT);
$ids = [
    'users' => [],
    'courses' => [],
    'activities' => [],
    'versions' => [],
    'questions' => [],
];

try {
    $db->prepare(
        "INSERT INTO users (username, email, password_hash, name, year, programme, initials, role)
         VALUES (?,?,?,?,?,?,?,?)"
    )->execute([$marker . '_st', $marker . '_st@example.test', $hash, 'Gate Student', 'Year 11', 'Test', 'GS', 'student']);
    $studentId = (int) $db->lastInsertId();
    $ids['users'][] = $studentId;

    $db->prepare(
        "INSERT INTO users (username, email, password_hash, name, year, programme, initials, role)
         VALUES (?,?,?,?,?,?,?,?)"
    )->execute([$marker . '_te', $marker . '_te@example.test', $hash, 'Gate Teacher', 'Year 11', 'Test', 'GT', 'teacher']);
    $teacherId = (int) $db->lastInsertId();
    $ids['users'][] = $teacherId;

    $student = [
        'id' => $studentId,
        'username' => $marker . '_st',
        'email' => $marker . '_st@example.test',
        'name' => 'Gate Student',
        'role' => 'student',
    ];
    $teacher = [
        'id' => $teacherId,
        'username' => $marker . '_te',
        'email' => $marker . '_te@example.test',
        'name' => 'Gate Teacher',
        'role' => 'teacher',
    ];

    $db->prepare(
        'INSERT INTO courses
         (slug, code, title, full_title, summary, year_group, term, status, status_label, accent, meeting, room, notice, student_count)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $marker . '-closed',
        'GATE-CL',
        'Closed Gate Course',
        'Closed Gate Course',
        'Fixture',
        'Test',
        'Term',
        'closed',
        'Closed',
        '#c1202f',
        'No schedule yet',
        'Location TBA',
        '',
        0,
    ]);
    $closedId = (int) $db->lastInsertId();
    $ids['courses'][] = $closedId;
    $db->prepare('INSERT INTO enrollments (user_id, course_id) VALUES (?,?)')->execute([$studentId, $closedId]);
    $db->prepare('INSERT INTO course_teachers (course_id, user_id, assignment_role) VALUES (?,?,?)')
        ->execute([$closedId, $teacherId, 'teacher']);

    $db->prepare(
        'INSERT INTO courses
         (slug, code, title, full_title, summary, year_group, term, status, status_label, accent, meeting, room, notice, student_count)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $marker . '-open',
        'GATE-OP',
        'Open Gate Course',
        'Open Gate Course',
        'Fixture',
        'Test',
        'Term',
        'open',
        'Open',
        '#c1202f',
        'No schedule yet',
        'Location TBA',
        '',
        0,
    ]);
    $openId = (int) $db->lastInsertId();
    $ids['courses'][] = $openId;
    $db->prepare('INSERT INTO enrollments (user_id, course_id) VALUES (?,?)')->execute([$studentId, $openId]);
    $db->prepare('INSERT INTO course_teachers (course_id, user_id, assignment_role) VALUES (?,?,?)')
        ->execute([$openId, $teacherId, 'teacher']);

    $db->prepare(
        'INSERT INTO course_folders (course_id, title, description, locked, is_pre_enroll) VALUES (?,?,?,0,1)'
    )->execute([$openId, 'Pre-enrolment quiz', 'Hidden']);
    $folderId = (int) $db->lastInsertId();

    $db->prepare(
        "INSERT INTO course_activities
            (course_item_id, course_id, mode, status, title, is_pre_enroll, include_in_gradebook, created_by)
         VALUES (NULL, ?, 'quiz', 'published', 'Knowledge check', 1, 0, ?)"
    )->execute([$openId, $teacherId]);
    $quizId = (int) $db->lastInsertId();
    $ids['activities'][] = $quizId;

    $db->prepare(
        "INSERT INTO activity_versions (activity_id, version_number, status, published_at)
         VALUES (?, 1, 'published', datetime('now'))"
    )->execute([$quizId]);
    $versionId = (int) $db->lastInsertId();
    $ids['versions'][] = $versionId;

    $db->prepare(
        "INSERT INTO activity_questions (activity_version_id, question_type, prompt_html, points)
         VALUES (?, 'true_false', '<p>Ready?</p>', 1)"
    )->execute([$versionId]);
    $ids['questions'][] = (int) $db->lastInsertId();

    $db->prepare('UPDATE courses SET pre_enroll_quiz_id = ? WHERE id = ?')->execute([$quizId, $openId]);

    gate_login($student);
    expect_true(
        portal_course_student_content_block_reason($closedId, $studentId) === 'closed',
        'enrolled student is blocked from a closed module'
    );
    expect_true(
        portal_course_content_blocked_for_student($closedId, $studentId) === true,
        'closed-module helper is true for the student'
    );
    expect_true(
        portal_course_student_content_block_reason($openId, $studentId) === 'pre_enroll',
        'enrolled student is blocked until the pre-enrolment quiz is done'
    );

    $quiz = portal_activity_find($quizId);
    expect_true(is_array($quiz), 'pre-enrolment quiz can be loaded');
    expect_true(
        portal_course_student_content_block_reason($openId, $studentId, $quiz ?: null) === '',
        'the pre-enrolment quiz itself remains reachable'
    );

    $canStartOther = portal_activity_can_start([
        'id' => $quizId + 9999,
        'course_id' => $openId,
        'status' => 'published',
        'mode' => 'quiz',
        'is_pre_enroll' => 0,
        'opens_at' => '',
        'closes_at' => '',
        'max_attempts' => 0,
        'course_item_id' => 0,
    ], $studentId);
    expect_true(empty($canStartOther['ok']), 'student cannot start another activity while gated');

    $canStartQuiz = portal_activity_can_start($quiz ?: [], $studentId);
    expect_true(!empty($canStartQuiz['ok']), 'student can start the pre-enrolment quiz');

    gate_login($teacher);
    expect_true(
        portal_course_student_content_block_reason($closedId, $teacherId) === '',
        'assigned teacher is not blocked from a closed module'
    );
    expect_true(
        portal_course_student_content_block_reason($openId, $teacherId) === '',
        'assigned teacher is not blocked by the pre-enrolment quiz'
    );
} finally {
    try {
        if (!empty($ids['questions'])) {
            $db->exec('DELETE FROM activity_questions WHERE id IN (' . implode(',', array_map('intval', $ids['questions'])) . ')');
        }
        if (!empty($ids['versions'])) {
            $db->exec('DELETE FROM activity_versions WHERE id IN (' . implode(',', array_map('intval', $ids['versions'])) . ')');
        }
        if (!empty($ids['activities'])) {
            $db->exec('DELETE FROM course_activities WHERE id IN (' . implode(',', array_map('intval', $ids['activities'])) . ')');
        }
        if (!empty($ids['courses'])) {
            $courseList = implode(',', array_map('intval', $ids['courses']));
            $db->exec("DELETE FROM enrollments WHERE course_id IN ({$courseList})");
            $db->exec("DELETE FROM course_teachers WHERE course_id IN ({$courseList})");
            $db->exec("DELETE FROM course_folders WHERE course_id IN ({$courseList})");
            $db->exec("DELETE FROM courses WHERE id IN ({$courseList})");
        }
        if (!empty($ids['users'])) {
            $db->exec('DELETE FROM users WHERE id IN (' . implode(',', array_map('intval', $ids['users'])) . ')');
        }
    } catch (Throwable $e) {
        echo "WARN  cleanup: " . $e->getMessage() . "\n";
    }
    unset($_SESSION['portal_user'], $_SESSION['portal_login_at']);
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} check(s) failed.\n");
    exit(1);
}

echo "\nAll course content gate checks passed.\n";
