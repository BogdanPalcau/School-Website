<?php
declare(strict_types=1);

/**
 * Activity fixtures for Playwright security/activity tests.
 * Reuses security course/users when present; otherwise sets them up.
 *
 * Usage:
 *   php tests/fixtures/activity-fixtures.php setup
 *   php tests/fixtures/activity-fixtures.php cleanup
 */

require_once __DIR__ . '/security-fixtures.php';

const ACTIVITY_PUBLISHED_TITLE = 'Security Published Assessment';
const ACTIVITY_DRAFT_TITLE = 'Security Draft Activity';

/**
 * @return array{publishedActivityId:int, draftActivityId:int, publishedItemId:int, draftItemId:int}
 */
function activity_fixtures_setup(PDO $db): array
{
    $security = security_setup($db);

    $openCourseId = (int) $security['courses']['openCourseId'];
    $folderId = (int) $security['folderId'];
    $teacherId = (int) $db->query("SELECT id FROM users WHERE username = 'sec_teacher'")->fetchColumn();
    $adminId = (int) $db->query("SELECT id FROM users WHERE username = 'sec_admin'")->fetchColumn();

    // Act as assigned teacher for create/publish helpers.
    $_SESSION['portal_user'] = [
        'id' => $teacherId,
        'username' => 'sec_teacher',
        'email' => 'sec_teacher@example.test',
        'name' => 'Security Teacher',
        'year' => 'Year 11',
        'programme' => 'Security Test',
        'initials' => 'ST',
        'role' => 'teacher',
    ];
    $_SESSION['portal_login_at'] = gmdate('Y-m-d H:i:s');

    $published = portal_activity_create(
        $openCourseId,
        $folderId,
        ACTIVITY_PUBLISHED_TITLE,
        'assessment',
        $teacherId
    );
    if (empty($published['ok'])) {
        throw new RuntimeException('Failed to create published assessment: ' . ($published['error'] ?? 'unknown'));
    }
    $publishedId = (int) $published['activity_id'];

    $q = portal_activity_add_question($publishedId, 'single_choice', '<p>What is 2+2?</p>', null, [
        'points' => 1,
        'explanation_html' => '<p>Four is correct.</p>',
        'teacher_notes' => 'Secret teacher note — must not leak',
        'options' => [
            ['text' => '3', 'is_correct' => 0],
            ['text' => '4', 'is_correct' => 1],
            ['text' => '5', 'is_correct' => 0],
        ],
    ]);
    if (empty($q['ok'])) {
        throw new RuntimeException('Failed to add assessment question');
    }

    $settings = portal_activity_save_settings($publishedId, [
        'max_attempts' => 6,
        'integrity_enabled' => 1,
        'feedback_policy' => 'when_released',
        'results_released' => 0,
        'xp_enabled' => 1,
        'xp_amount' => 30,
        'leaderboard_enabled' => 1,
    ], (int) (portal_activity_find($publishedId)['version'] ?? 1));
    if (empty($settings['ok'])) {
        // Revision may have bumped — retry once with fresh revision.
        $act = portal_activity_find($publishedId);
        $settings = portal_activity_save_settings($publishedId, [
            'max_attempts' => 6,
            'integrity_enabled' => 1,
            'feedback_policy' => 'when_released',
            'results_released' => 0,
            'xp_enabled' => 1,
            'xp_amount' => 30,
            'leaderboard_enabled' => 1,
        ], (int) ($act['version'] ?? 1));
    }

    $pub = portal_activity_publish($publishedId);
    if (empty($pub['ok'])) {
        $errs = $pub['validation']['errors'] ?? [];
        throw new RuntimeException(
            'Failed to publish assessment: ' . ($pub['error'] ?? 'unknown')
            . (!empty($errs) ? ' — ' . implode('; ', $errs) : '')
        );
    }

    $draft = portal_activity_create(
        $openCourseId,
        $folderId,
        ACTIVITY_DRAFT_TITLE,
        'quiz',
        $teacherId
    );
    if (empty($draft['ok'])) {
        throw new RuntimeException('Failed to create draft activity: ' . ($draft['error'] ?? 'unknown'));
    }
    $draftId = (int) $draft['activity_id'];

    $studentId = (int) $db->query("SELECT id FROM users WHERE username = 'sec_student'")->fetchColumn();
    $_SESSION['portal_user'] = [
        'id' => $studentId,
        'username' => 'sec_student',
        'email' => 'sec_student@example.test',
        'name' => 'Security Student',
        'year' => 'Year 11',
        'programme' => 'Security Test',
        'initials' => 'SS',
        'role' => 'student',
    ];
    $flagged = portal_activity_start_attempt($publishedId, $studentId, 'I acknowledge the integrity notice.');
    $flaggedAttemptId = (int) ($flagged['attempt']['id'] ?? 0);
    $flaggedQuestionId = (int) ($flagged['questions'][0]['id'] ?? 0);
    portal_activity_record_integrity_event(
        $flaggedAttemptId,
        $studentId,
        'multiple_tab_detected',
        'fixture-integrity-' . $flaggedAttemptId,
        $flaggedQuestionId,
        'source_not_available',
        ['tab_count' => 2]
    );
    portal_activity_submit_attempt($flaggedAttemptId, $studentId, (string) ($flagged['token'] ?? ''));
    $db->prepare("UPDATE activity_attempts SET end_reason = 'page_left' WHERE id = ?")
        ->execute([$flaggedAttemptId]);
    portal_activity_award_xp(
        $studentId,
        $openCourseId,
        $publishedId,
        $flaggedAttemptId,
        'fixture_course_xp',
        30,
        'fixture-course-xp:' . $publishedId . ':' . $studentId
    );

    $released = portal_activity_start_attempt($publishedId, $studentId, 'I acknowledge the integrity notice.');
    $releasedAttemptId = (int) ($released['attempt']['id'] ?? 0);
    portal_activity_submit_attempt($releasedAttemptId, $studentId, (string) ($released['token'] ?? ''));
    $db->prepare(
        "UPDATE activity_attempts
         SET status = 'released', grade_seen_at = '', updated_at = datetime('now')
         WHERE id = ?"
    )->execute([$releasedAttemptId]);

    // Keep the integrity/reopen fixture as the newest attempt so reopening it
    // is valid even with the separate released-grade fixture above.
    $reopenable = portal_activity_start_attempt($publishedId, $studentId, 'I acknowledge the integrity notice.');
    $flaggedAttemptId = (int) ($reopenable['attempt']['id'] ?? 0);
    $flaggedQuestionId = (int) ($reopenable['questions'][0]['id'] ?? 0);
    portal_activity_record_integrity_event(
        $flaggedAttemptId,
        $studentId,
        'multiple_tab_detected',
        'fixture-reopen-integrity-' . $flaggedAttemptId,
        $flaggedQuestionId,
        'source_not_available',
        ['tab_count' => 2]
    );
    portal_activity_submit_attempt($flaggedAttemptId, $studentId, (string) ($reopenable['token'] ?? ''));
    $db->prepare("UPDATE activity_attempts SET end_reason = 'page_left' WHERE id = ?")
        ->execute([$flaggedAttemptId]);

    $publishedRow = portal_activity_find($publishedId);
    $draftRow = portal_activity_find($draftId);

    return array_merge($security, [
        'publishedActivityId' => $publishedId,
        'draftActivityId' => $draftId,
        'publishedItemId' => (int) ($publishedRow['course_item_id'] ?? 0),
        'draftItemId' => (int) ($draftRow['course_item_id'] ?? 0),
        'flaggedAttemptId' => $flaggedAttemptId,
        'releasedAttemptId' => $releasedAttemptId,
        'titles' => [
            'published' => ACTIVITY_PUBLISHED_TITLE,
            'draft' => ACTIVITY_DRAFT_TITLE,
        ],
        'adminId' => $adminId,
        'teacherId' => $teacherId,
    ]);
}

function activity_fixtures_cleanup(PDO $db): void
{
    security_cleanup($db);
}

// Only run CLI when this file is the entry script (not when required by security-fixtures).
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    $command = $argv[1] ?? 'setup';
    $db = portal_db();

    try {
        if ($command === 'setup') {
            echo json_encode(activity_fixtures_setup($db), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
            exit(0);
        }
        if ($command === 'cleanup') {
            activity_fixtures_cleanup($db);
            echo "cleaned\n";
            exit(0);
        }
        throw new InvalidArgumentException('Unknown command: ' . $command);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
