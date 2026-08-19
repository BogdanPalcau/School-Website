<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
portal_require_login();

$db = portal_db();
$me = portal_current_user();
$uid = (int) ($me['id'] ?? 0);

$activityId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$itemId = (int) ($_GET['item'] ?? $_POST['item'] ?? 0);

$activity = null;
if ($activityId > 0) {
    $activity = portal_activity_find($activityId);
} elseif ($itemId > 0) {
    $activity = portal_activity_find_by_item($itemId);
}

if ($activity === null) {
    http_response_code(404);
    exit('Activity not found.');
}

$activityId = (int) $activity['id'];
$courseId = (int) $activity['course_id'];
$canManage = portal_can_manage_course($courseId);

if (!portal_can_access_course($courseId)) {
    portal_log_security_event('activity_access_denied', 'medium', 'activity_id=' . $activityId);
    if (portal_is_fetch_request() || strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
        portal_activity_json_error('You do not have access to this activity.', 403);
    }
    portal_store_intended_path();
    portal_redirect('login.php');
}

if (($activity['status'] ?? '') !== 'published' && !$canManage) {
    portal_log_security_event('unpublished_activity_access_denied', 'medium', 'activity_id=' . $activityId);
    http_response_code(404);
    exit('Activity not found.');
}

if (!$canManage && portal_activity_content_locked($activity)) {
    portal_log_security_event(
        'forbidden_download',
        'medium',
        'Blocked access to locked activity ' . $activityId
    );
    if (portal_is_fetch_request() || strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
        portal_activity_json_error('This activity is locked by your teacher.', 403);
    }
    http_response_code(403);
    exit('This activity is locked by your teacher.');
}

$courseStmt = $db->prepare('SELECT * FROM courses WHERE id = ?');
$courseStmt->execute([$courseId]);
$course = $courseStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$isPreEnrollQuiz = (int) ($activity['is_pre_enroll'] ?? 0) === 1
    && (int) ($course['pre_enroll_quiz_id'] ?? 0) === $activityId;

$blockReason = portal_course_student_content_block_reason($courseId, $uid, $activity);
if ($blockReason !== '') {
    portal_log_security_event(
        'unauthorised_course_access',
        'medium',
        'Blocked activity ' . $activityId . ' (' . $blockReason . ')'
    );
    $message = $blockReason === 'pre_enroll'
        ? 'Complete the knowledge check before opening this module.'
        : 'This module is not open yet.';
    if (portal_is_fetch_request() || strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
        portal_activity_json_error($message, 403);
    }
    if ($blockReason === 'pre_enroll' && ($course['slug'] ?? '') !== '') {
        portal_redirect('course.php?course=' . urlencode((string) $course['slug']));
    }
    portal_redirect('courses.php');
}

/**
 * @return array<string, mixed>
 */
function portal_ap_request_payload(): array
{
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode(is_string($raw) ? $raw : '', true);
        return is_array($decoded) ? $decoded : [];
    }
    return $_POST;
}

/**
 * @param array<string, mixed> $payload
 */
function portal_ap_verify_csrf(array $payload): bool
{
    $token = (string) ($payload['_token'] ?? $_POST['_token'] ?? '');
    $valid = $token !== ''
        && !empty($_SESSION['_csrf'])
        && hash_equals((string) $_SESSION['_csrf'], $token);
    if (!$valid && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
        portal_log_security_event('csrf_failed', 'high', 'activity player CSRF failed');
    }
    return $valid;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
    $payload = portal_ap_request_payload();
    if (!portal_ap_verify_csrf($payload)) {
        portal_activity_json_error('Invalid security token. Refresh and try again.', 403);
    }

    $action = (string) ($payload['action'] ?? '');
    $attemptId = (int) ($payload['attempt_id'] ?? 0);
    $sessionToken = (string) ($payload['token'] ?? $payload['session_token'] ?? '');

    if ($canManage && !empty($payload['preview'])) {
        $previewResult = portal_activity_preview_post($activity, $payload);
        if (empty($previewResult['ok'])) {
            portal_activity_json_error(
                (string) ($previewResult['error'] ?? 'Preview failed.'),
                400,
                $previewResult
            );
        }
        portal_activity_json_ok($previewResult);
    }

    switch ($action) {
        case 'start':
            $result = portal_activity_start_attempt(
                $activityId,
                $uid,
                (string) ($payload['integrity_ack'] ?? '')
            );
            if (empty($result['ok'])) {
                portal_activity_json_error((string) ($result['error'] ?? 'Could not start.'), 400, $result);
            }
            portal_activity_json_ok($result);

        case 'resume': {
            $stmt = $db->prepare(
                "SELECT id FROM activity_attempts
                 WHERE activity_id = ? AND user_id = ? AND status = 'in_progress'
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$activityId, $uid]);
            $resumeId = (int) ($stmt->fetchColumn() ?: 0);
            if ($resumeId <= 0) {
                portal_activity_json_error('No attempt in progress.');
            }
            $result = portal_activity_start_attempt($activityId, $uid, (string) ($payload['integrity_ack'] ?? ''));
            if (empty($result['ok'])) {
                portal_activity_json_error((string) ($result['error'] ?? 'Could not resume.'), 400, $result);
            }
            portal_activity_json_ok($result);
        }

        case 'save_answer': {
            $result = portal_activity_save_answer(
                $attemptId,
                $uid,
                (int) ($payload['question_id'] ?? 0),
                $payload['answer'] ?? null,
                (int) ($payload['revision'] ?? 0),
                $sessionToken
            );
            if (empty($result['ok'])) {
                portal_activity_json_error(
                    (string) ($result['error'] ?? 'Could not save.'),
                    !empty($result['conflict']) ? 409 : 400,
                    $result
                );
            }
            portal_activity_json_ok($result);
        }

        case 'submit': {
            $result = portal_activity_submit_attempt($attemptId, $uid, $sessionToken);
            if (empty($result['ok'])) {
                portal_activity_json_error((string) ($result['error'] ?? 'Could not submit.'), 400, $result);
            }
            // Strip sensitive fields if feedback not released
            if (isset($result['player']) && is_array($result['player'])) {
                // get_attempt_for_player already gates correct answers
            }
            portal_activity_json_ok($result);
        }

        case 'leave_assessment': {
            $leaveAttemptStmt = $db->prepare('SELECT * FROM activity_attempts WHERE id = ? AND user_id = ?');
            $leaveAttemptStmt->execute([$attemptId, $uid]);
            $leaveAttempt = $leaveAttemptStmt->fetch(PDO::FETCH_ASSOC);
            if (!$leaveAttempt || !portal_activity_verify_attempt_token($leaveAttempt, $sessionToken)) {
                portal_activity_json_error('Your assessment session is invalid.', 403);
            }
            $endReason = (string) ($payload['end_reason'] ?? 'page_left');
            $result = portal_activity_end_assessment_attempt($attemptId, $uid, $endReason);
            if (empty($result['ok'])) {
                portal_activity_json_error((string) ($result['error'] ?? 'Could not end assessment.'), 400, $result);
            }
            portal_activity_json_ok($result);
        }

        case 'integrity_event': {
            $meta = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
            unset($meta['clipboard'], $meta['text'], $meta['paste_text'], $meta['html'], $meta['content']);
            if (isset($meta['char_count'])) {
                $meta['char_count'] = (int) $meta['char_count'];
            }
            if (isset($meta['has_html'])) {
                $meta['has_html'] = !empty($meta['has_html']) ? 1 : 0;
            }
            $result = portal_activity_record_integrity_event(
                $attemptId,
                $uid,
                (string) ($payload['event_type'] ?? ''),
                (string) ($payload['idempotency_key'] ?? bin2hex(random_bytes(8))),
                isset($payload['question_id']) && $payload['question_id'] !== ''
                    ? (int) $payload['question_id'] : null,
                (string) ($payload['source_classification'] ?? 'source_not_available'),
                $meta,
                (int) ($payload['client_elapsed_ms'] ?? 0),
                (string) ($payload['occurred_at'] ?? '')
            );
            if (empty($result['ok'])) {
                portal_activity_json_error((string) ($result['error'] ?? 'Could not record event.'), 400, $result);
            }
            portal_activity_json_ok($result);
        }

        case 'sync_timer': {
            $stmt = $db->prepare('SELECT * FROM activity_attempts WHERE id = ? AND user_id = ?');
            $stmt->execute([$attemptId, $uid]);
            $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$attempt) {
                portal_activity_json_error('Attempt not found.', 404);
            }
            if (!portal_activity_verify_attempt_token($attempt, $sessionToken)) {
                portal_activity_json_error('Your session is invalid. Refresh and try again.', 403);
            }
            $attempt = portal_activity_expire_if_needed($attempt);
            portal_activity_json_ok([
                'attempt' => [
                    'id' => (int) $attempt['id'],
                    'status' => $attempt['status'],
                    'expires_at' => $attempt['expires_at'],
                ],
                'server_now' => portal_activity_now_utc(),
            ]);
        }

        case 'result': {
            $stmt = $db->prepare(
                "SELECT id FROM activity_attempts
                 WHERE activity_id = ? AND user_id = ?
                   AND status IN ('submitted','auto_submitted','awaiting_manual_marking','marked','released')
                 ORDER BY attempt_number DESC LIMIT 1"
            );
            if ($attemptId > 0) {
                $stmt = $db->prepare('SELECT id FROM activity_attempts WHERE id = ? AND user_id = ? AND activity_id = ?');
                $stmt->execute([$attemptId, $uid, $activityId]);
            } else {
                $stmt->execute([$activityId, $uid]);
            }
            $rid = (int) ($stmt->fetchColumn() ?: 0);
            if ($rid <= 0) {
                portal_activity_json_error('No result available.');
            }
            $player = portal_activity_get_attempt_for_player($rid, $uid);
            if (empty($player['ok'])) {
                portal_activity_json_error((string) ($player['error'] ?? 'Result unavailable.'), 400, $player);
            }
            portal_activity_json_ok($player);
        }

        default:
            portal_activity_json_error('Unknown action.', 400);
    }
}

$csrfToken = portal_csrf_token();
$isPreview = $canManage && (int) ($_GET['preview'] ?? 0) === 1;

// Formal assessments are single-sitting activities. If a student returns via
// reload, history navigation, or a fresh tab after the unload request was lost,
// close the old attempt before rendering the lobby. Quizzes/practice still resume.
if (!$isPreview && ($activity['mode'] ?? '') === 'assessment') {
    $staleAttemptStmt = $db->prepare(
        "SELECT id, resume_allowed FROM activity_attempts
         WHERE activity_id = ? AND user_id = ? AND status = 'in_progress'
         ORDER BY id DESC LIMIT 1"
    );
    $staleAttemptStmt->execute([$activityId, $uid]);
    $staleAssessmentAttempt = $staleAttemptStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $staleAssessmentAttemptId = (int) ($staleAssessmentAttempt['id'] ?? 0);
    if ($staleAssessmentAttemptId > 0 && empty($staleAssessmentAttempt['resume_allowed'])) {
        portal_activity_end_assessment_attempt($staleAssessmentAttemptId, $uid, 'page_reopened');
    }
}

$summary = portal_activity_student_card_summary($activity, $uid);
$canStart = portal_activity_can_start($activity, $uid);

$inProgressId = $isPreview ? null : ($summary['in_progress_attempt_id'] ?? null);
$forcePlayer = isset($_GET['play']) || isset($_GET['attempt']);
$showPlayer = $inProgressId !== null && ($forcePlayer || isset($_GET['resume']) || (string) ($_GET['view'] ?? '') === 'play');

// Auto-enter player when resuming via ?resume=1 or when attempt query present
if ($inProgressId !== null && (isset($_GET['resume']) || (int) ($_GET['attempt'] ?? 0) === (int) $inProgressId)) {
    $showPlayer = true;
}

$latestResult = null;
if (!$isPreview) {
    $resultStmt = $db->prepare(
        "SELECT id, status, percentage, score, maximum_score, submitted_at, attempt_number
         FROM activity_attempts
         WHERE activity_id = ? AND user_id = ?
           AND status IN ('submitted','auto_submitted','awaiting_manual_marking','marked','released')
         ORDER BY attempt_number DESC LIMIT 1"
    );
    $resultStmt->execute([$activityId, $uid]);
    $latestResult = $resultStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$showFeedback = false;
if ($latestResult) {
    $showFeedback = portal_activity_feedback_visible($activity, $latestResult);
    if (($activity['mode'] ?? '') === 'assessment' && empty($activity['results_released']) && ($latestResult['status'] ?? '') !== 'released') {
        $showFeedback = false;
    }
}

$needsIntegrityAck = ($activity['mode'] ?? '') === 'assessment' && !empty($activity['integrity_enabled']);
$leaderboardRows = !empty($activity['leaderboard_enabled'])
    ? portal_activity_course_leaderboard((int) $activity['course_id'], 8)
    : [];
$overviewVersionId = $isPreview
    ? (portal_activity_draft_version_id($activityId) ?? portal_activity_published_version_id($activityId))
    : (portal_activity_published_version_id($activityId)
        ?? ($canManage ? portal_activity_draft_version_id($activityId) : null));
$questionCount = 0;
$maximumPoints = 0.0;
if ($overviewVersionId !== null) {
    $overviewStmt = $db->prepare(
        'SELECT COUNT(*) AS question_count, COALESCE(SUM(points), 0) AS maximum_points
         FROM activity_questions WHERE activity_version_id = ?'
    );
    $overviewStmt->execute([$overviewVersionId]);
    $overview = $overviewStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $questionCount = (int) ($overview['question_count'] ?? 0);
    $maximumPoints = (float) ($overview['maximum_points'] ?? 0);
}

if ($isPreview) {
    $canStart = $questionCount > 0
        ? ['ok' => true]
        : ['ok' => false, 'error' => 'Add questions in the builder before previewing.'];
}

$builderUrl = 'activity-builder.php?id=' . $activityId;
$bootstrap = [
    'activity' => [
        'id' => $activityId,
        'title' => $activity['title'],
        'mode' => $activity['mode'],
        'mode_label' => portal_activity_mode_label((string) $activity['mode']),
        'status' => $activity['status'],
        'short_description' => $activity['short_description'],
        'instructions_html' => $activity['instructions_html'],
        'time_limit_seconds' => (int) $activity['time_limit_seconds'],
        'max_attempts' => (int) $activity['max_attempts'],
        'integrity_enabled' => (int) ((($activity['mode'] ?? '') === 'assessment') ? $activity['integrity_enabled'] : 0),
        'focus_monitoring' => (int) ((($activity['mode'] ?? '') === 'assessment') ? $activity['focus_monitoring'] : 0),
        'paste_policy' => $activity['paste_policy'],
        'copy_policy' => $activity['copy_policy'],
        'fullscreen_policy' => $activity['fullscreen_policy'],
        'navigation_policy' => $activity['navigation_policy'],
        'feedback_policy' => $activity['feedback_policy'],
        'results_released' => (int) ($activity['results_released'] ?? 0),
        'xp_enabled' => (int) ($activity['xp_enabled'] ?? 0),
        'xp_amount' => (int) ($activity['xp_amount'] ?? 0),
        'leaderboard_enabled' => (int) ($activity['leaderboard_enabled'] ?? 0),
    ],
    'summary' => $summary,
    'can_start' => !empty($canStart['ok']),
    'can_start_message' => $canStart['error'] ?? null,
    'needs_integrity_ack' => $needsIntegrityAck,
    'csrf' => $csrfToken,
    'in_progress_attempt_id' => $inProgressId,
    'latest_result' => $latestResult && $showFeedback ? [
        'id' => (int) $latestResult['id'],
        'status' => $latestResult['status'],
        'percentage' => ((string) ($latestResult['status'] ?? '') === 'awaiting_manual_marking')
            ? null
            : $latestResult['percentage'],
        'attempt_number' => (int) $latestResult['attempt_number'],
        'submitted_at' => $latestResult['submitted_at'],
        'awaiting_marking' => ((string) ($latestResult['status'] ?? '') === 'awaiting_manual_marking'),
    ] : ($latestResult ? [
        'id' => (int) $latestResult['id'],
        'status' => $latestResult['status'],
        'attempt_number' => (int) $latestResult['attempt_number'],
        'submitted_at' => $latestResult['submitted_at'],
        'percentage' => null,
        'awaiting_release' => !$showFeedback,
        'awaiting_marking' => ((string) ($latestResult['status'] ?? '') === 'awaiting_manual_marking'),
    ] : null),
    'can_manage' => $canManage && !$isPreview,
    'preview' => $isPreview,
    'urls' => [
        'self' => 'activity.php?id=' . $activityId . ($isPreview ? '&preview=1' : ''),
        'course' => 'course.php?course=' . urlencode((string) ($course['slug'] ?? '')) . '&section=content',
        'builder' => $canManage ? $builderUrl : null,
        'results' => $canManage && !$isPreview ? 'activity-results.php?id=' . $activityId : null,
    ],
    'pre_enroll' => $isPreEnrollQuiz && !$isPreview && !$canManage,
    'auto_start_player' => $showPlayer,
];

$backUrl = $isPreview
    ? $builderUrl
    : ('course.php?course=' . urlencode((string) ($course['slug'] ?? '')) . '&section=content');
$backLabel = $isPreview ? 'Back to editor' : ($isPreEnrollQuiz ? 'Back' : 'Back to course');
$courseLabel = trim((string) ($course['code'] ?? '')) !== ''
    ? (string) $course['code']
    : (string) ($course['title'] ?? 'Course');
$page_title = (string) $activity['title'] . ' | ' . portal_school_name();
$page_eyebrow = $isPreview
    ? 'Student preview'
    : ($isPreEnrollQuiz ? 'Pre-enrolment quiz' : portal_activity_mode_label((string) $activity['mode']));
$page_heading = (string) $activity['title'];
$page_description = $isPreview
    ? 'This is the student view. Answers are not saved and do not count as an attempt.'
    : ($isPreEnrollQuiz
        ? 'Complete this short knowledge check to start the module.'
        : (string) ($activity['short_description'] ?: 'Complete this activity for your course.'));
$active_page = 'courses';
$layout_preview_as_student = $isPreview;

ob_start();
?>
<section class="activity-player"
         id="activity-player"
         data-activity-id="<?= (int) $activityId ?>"
         data-csrf="<?= portal_escape($csrfToken) ?>"
         data-mode="<?= portal_escape((string) $activity['mode']) ?>">

    <div data-ap-landing<?= $showPlayer ? ' hidden' : '' ?>>
        <?php if ($isPreview): ?>
        <div class="ap-preview-banner" role="status">
            Student preview — answers are not recorded.
            <a class="ap-preview-banner-link" href="<?= portal_escape($builderUrl) ?>">Back to editor</a>
        </div>
        <?php endif; ?>
        <div class="ap-toolbar">
            <a class="ap-back" href="<?= portal_escape($backUrl) ?>">
                <span aria-hidden="true">←</span> <?= portal_escape($backLabel) ?>
            </a>
            <div class="ap-toolbar-actions">
                <?php if ($canManage && !$isPreview): ?>
                    <a class="ap-tool-link" href="activity-builder.php?id=<?= (int) $activityId ?>">Edit</a>
                    <a class="ap-tool-link ap-tool-link--strong" href="activity-results.php?id=<?= (int) $activityId ?>">Submissions</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="ap-lobby">
            <div class="ap-lobby-main">
                <p class="ap-kicker">
                    <span class="activity-mode-pill activity-mode-pill--<?= portal_escape((string) $activity['mode']) ?>">
                        <?= portal_escape(portal_activity_mode_label((string) $activity['mode'])) ?>
                    </span>
                    <span><?= portal_escape($courseLabel) ?></span>
                    <?php if (($activity['status'] ?? '') === 'draft' && !$isPreview): ?>
                        <span class="activity-status activity-status--not-started">Draft</span>
                    <?php endif; ?>
                </p>
                <h1 class="ap-lobby-title"><?= portal_escape((string) $activity['title']) ?></h1>
                <p class="ap-lobby-lead"><?= portal_escape(
                    $isPreEnrollQuiz
                        ? 'Complete this short knowledge check, then you can open the module.'
                        : (trim((string) $activity['short_description']) !== ''
                        ? (string) $activity['short_description']
                        : (($activity['mode'] ?? '') === 'flashcard'
                            ? 'Flip through the cards to revise. Mark each one Know or Still learning as you go.'
                            : (($activity['mode'] ?? '') === 'assessment'
                            ? 'Review the details below, then continue when you are ready. Your answers are saved as you work.'
                            : 'Review the details below, then begin when you are ready.')))
                ) ?></p>

                <div class="ap-lobby-metrics" aria-label="Activity overview">
                    <?php if (($activity['mode'] ?? '') === 'flashcard'): ?>
                    <span><strong><?= $questionCount ?></strong> <?= $questionCount === 1 ? 'card' : 'cards' ?></span>
                    <span><strong>Study</strong> flip &amp; revise</span>
                    <span><strong>Untimed</strong></span>
                    <?php else: ?>
                    <span><strong><?= $questionCount ?></strong> <?= $questionCount === 1 ? 'question' : 'questions' ?></span>
                    <span><strong><?= portal_escape(rtrim(rtrim(number_format($maximumPoints, 2, '.', ''), '0'), '.')) ?></strong> points</span>
                    <span><strong><?= (int) $activity['time_limit_seconds'] > 0 ? (int) ceil(((int) $activity['time_limit_seconds']) / 60) . ' min' : 'Untimed' ?></strong></span>
                    <?php endif; ?>
                </div>

                <?php if ($inProgressId): ?>
                    <div class="ap-resume-note" role="status">
                        <span class="ap-resume-icon" aria-hidden="true">→</span>
                        <div>
                            <strong>Activity in progress</strong>
                            <span>Continue where you left off. Your saved answers are waiting.</span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (trim((string) $activity['instructions_html']) !== ''): ?>
                    <div class="ap-panel">
                        <h2 class="ap-panel-title">Instructions</h2>
                        <div class="ap-rich"><?= portal_sanitize_rich_text((string) $activity['instructions_html']) ?></div>
                    </div>
                <?php endif; ?>

                <?php if (($activity['mode'] ?? '') === 'assessment' && $inProgressId === null): ?>
                    <div class="ap-single-sitting-note">
                        <strong>One continuous sitting</strong>
                        <span>Once started, leaving, refreshing, or closing this page ends and submits the assessment. You cannot return to the same attempt.</span>
                    </div>
                <?php endif; ?>

                <?php if ($needsIntegrityAck && $inProgressId === null): ?>
                    <div class="ap-panel ap-panel--warn" data-ap-integrity-notice>
                        <div class="ap-integrity-heading">
                            <span class="ap-integrity-icon" aria-hidden="true">✓</span>
                            <div>
                                <h2 class="ap-panel-title">Before you begin</h2>
                                <p>Integrity checks are enabled for this assessment.</p>
                            </div>
                        </div>
                        <p class="ap-integrity-copy">Focus changes and paste or copy actions may be recorded for your teacher to review. Your clipboard text is never saved.</p>
                        <label class="ap-ack-label">
                            <input type="checkbox" data-ap-integrity-ack>
                            <span>I understand how the checks work and I’m ready to continue.</span>
                        </label>
                        <p class="ap-ack-error" data-ap-integrity-error hidden>Please tick the box above before starting.</p>
                    </div>
                <?php endif; ?>

                <div class="ap-landing-actions">
                    <?php if ($inProgressId): ?>
                        <button type="button" class="button ap-primary-action" data-ap-action="resume">Continue <?= ($activity['mode'] ?? '') === 'flashcard' ? 'studying' : (($activity['mode'] ?? '') === 'quiz' ? 'quiz' : 'activity') ?> <span aria-hidden="true">→</span></button>
                    <?php elseif (!empty($canStart['ok'])): ?>
                        <button type="button" class="button ap-primary-action" data-ap-action="start"><?=
                            ($activity['mode'] ?? '') === 'flashcard'
                                ? 'Study flashcards'
                                : (($activity['mode'] ?? '') === 'assessment' ? 'Start assessment' : 'Start activity')
                        ?> <span aria-hidden="true">→</span></button>
                    <?php else: ?>
                        <p class="ap-locked-msg"><?= portal_escape((string) ($canStart['error'] ?? 'Not available')) ?></p>
                    <?php endif; ?>
                    <?php if ($latestResult): ?>
                        <button type="button" class="button button-secondary" data-ap-action="view-result">View last result</button>
                    <?php endif; ?>
                    <a class="ap-text-link" href="<?= portal_escape($backUrl) ?>"><?= $isPreview ? 'Return to editor' : 'Return to course' ?></a>
                </div>
            </div>

            <aside class="ap-lobby-aside" aria-label="Activity details">
                <h2 class="ap-panel-title"><?= ($activity['mode'] ?? '') === 'assessment' ? 'Assessment details' : 'Activity details' ?></h2>
                <dl class="ap-facts">
                    <div>
                        <dt>Attempts</dt>
                        <dd>
                            <?php if ((int) $activity['max_attempts'] > 0): ?>
                                <?= $isPreview ? 0 : (int) $summary['attempt_count'] ?> used
                                <?php
                                    $attemptsLeft = $isPreview
                                        ? (int) $activity['max_attempts']
                                        : $summary['attempts_remaining'];
                                ?>
                                <?php if ($attemptsLeft !== null): ?>
                                    · <?= (int) $attemptsLeft ?> left
                                <?php endif; ?>
                            <?php else: ?>
                                Unlimited
                            <?php endif; ?>
                        </dd>
                    </div>
                    <?php if ((int) $activity['time_limit_seconds'] > 0): ?>
                        <div>
                            <dt>Time limit</dt>
                            <dd><?= (int) ceil(((int) $activity['time_limit_seconds']) / 60) ?> minutes</dd>
                        </div>
                    <?php else: ?>
                        <div>
                            <dt>Time limit</dt>
                            <dd>None</dd>
                        </div>
                    <?php endif; ?>
                    <?php if ($summary['best_percentage'] !== null && $showFeedback): ?>
                        <div>
                            <dt>Best score</dt>
                            <dd><?= portal_escape((string) round((float) $summary['best_percentage'], 1)) ?>%</dd>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($activity['estimated_minutes'])): ?>
                        <div>
                            <dt>Estimated time</dt>
                            <dd><?= (int) $activity['estimated_minutes'] ?> min</dd>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($activity['xp_enabled']) && (int) ($activity['xp_amount'] ?? 0) > 0): ?>
                        <div class="ap-fact-reward">
                            <dt>Completion reward</dt>
                            <dd><span class="ap-lobby-xp">+<?= (int) $activity['xp_amount'] ?> XP</span><?= ($activity['mode'] ?? '') === 'assessment' ? ' after release' : '' ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>
                <?php if (!empty($activity['leaderboard_enabled'])): ?>
                    <section class="activity-leaderboard activity-leaderboard--lobby" aria-labelledby="activity-leaderboard-title">
                        <div class="activity-leaderboard-head">
                            <span class="activity-leaderboard-mark"><?= portal_icon('trophy', 'icon-sm') ?></span>
                            <div>
                                <h2 id="activity-leaderboard-title" class="activity-leaderboard-title">Course XP</h2>
                                <p class="activity-leaderboard-note">Earn XP from activities to move up.</p>
                            </div>
                        </div>
                        <?php if ($leaderboardRows === []): ?>
                            <p class="activity-leaderboard-empty">No XP earned in this course yet.</p>
                        <?php else: ?>
                            <ol class="activity-leaderboard-list">
                                <?php foreach ($leaderboardRows as $rank => $leader): ?>
                                    <li class="activity-leaderboard-row<?= (int) $leader['id'] === $uid ? ' is-self' : '' ?>">
                                        <span class="activity-leaderboard-rank"><?= $rank + 1 ?></span>
                                        <span class="activity-leaderboard-name"><?= portal_escape((string) $leader['name']) ?><?= (int) $leader['id'] === $uid ? ' · You' : '' ?></span>
                                        <span class="activity-leaderboard-score"><?= (int) $leader['xp'] ?> XP</span>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            </aside>
        </div>
    </div>

    <div data-ap-shell<?= $showPlayer ? '' : ' hidden' ?>>
        <?php if (($activity['mode'] ?? '') === 'flashcard'): ?>
        <div class="fc-player" data-fc-player>
            <div class="ap-preview-banner" data-ap-banner<?= $isPreview ? '' : ' hidden' ?> role="status"><?php if ($isPreview): ?>
                Student preview — answers are not recorded.
                <a class="ap-preview-banner-link" href="<?= portal_escape($builderUrl) ?>">Back to editor</a>
            <?php endif; ?></div>
            <div class="fc-toolbar">
                <a class="ap-back" href="<?= portal_escape($backUrl) ?>">
                    <span aria-hidden="true">←</span> Exit
                </a>
                <div class="fc-toolbar-center">
                    <strong data-ap-title><?= portal_escape((string) $activity['title']) ?></strong>
                    <span data-fc-progress-label>Card 1 of <?= max(1, $questionCount) ?></span>
                </div>
                <label class="fc-shuffle">
                    <input type="checkbox" data-fc-shuffle<?= !empty($activity['shuffle_questions']) ? ' checked' : '' ?>>
                    <span>Shuffle</span>
                </label>
            </div>
            <div class="fc-progress-track" aria-hidden="true"><div class="fc-progress-fill" data-fc-progress-fill></div></div>
            <div class="fc-stage" data-fc-stage>
                <div class="fc-swipe-unit" data-fc-card-motion>
                    <div class="fc-card" data-fc-card role="button" tabindex="0" aria-label="Flashcard — tap to flip, swipe to mark">
                        <div class="fc-card-inner" data-fc-card-inner>
                            <div class="fc-face fc-face--front">
                                <div class="fc-face-body" data-fc-front></div>
                                <span class="fc-face-hint">Click or press Space to flip</span>
                            </div>
                            <div class="fc-face fc-face--back">
                                <div class="fc-face-body" data-fc-back></div>
                                <span class="fc-face-hint">Swipe right Know · left Still learning</span>
                            </div>
                        </div>
                    </div>
                    <div class="fc-actions" data-fc-actions>
                        <button type="button" class="button button-secondary" data-fc-mark="learning" disabled>Still learning</button>
                        <button type="button" class="button" data-fc-mark="known" disabled>Know</button>
                    </div>
                </div>
                <div class="fc-stats" aria-live="polite">
                    <span><strong data-fc-known>0</strong> know</span>
                    <span><strong data-fc-learning>0</strong> still learning</span>
                </div>
                <p class="fc-keys" data-fc-keys hidden>After flipping: swipe or use the buttons</p>
            </div>
            <div class="fc-end" data-fc-end hidden>
                <div class="fc-end-card">
                    <p class="fc-end-kicker">Session finished</p>
                    <h2>Deck complete</h2>
                    <p class="fc-end-summary" data-fc-end-summary></p>
                    <div class="fc-end-actions">
                        <button type="button" class="button" data-fc-restart>Study again</button>
                        <button type="button" class="button button-secondary" data-fc-restart-learning>Retry still learning</button>
                        <a class="button button-secondary" href="<?= portal_escape($backUrl) ?>">Back to course</a>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="ap-preview-banner" data-ap-banner<?= $isPreview ? '' : ' hidden' ?> role="status"><?php if ($isPreview): ?>
            Student preview — answers are not recorded.
            <a class="ap-preview-banner-link" href="<?= portal_escape($builderUrl) ?>">Back to editor</a>
        <?php endif; ?></div>
        <div class="ap-network-warning" data-ap-network hidden role="alert">
            <span class="ap-network-warning-icon" aria-hidden="true">!</span>
            <div>
                <strong data-ap-network-title>Connection lost</strong>
                <span data-ap-network-text>Your answers are safe on this device while we try to reconnect.</span>
            </div>
        </div>
        <div class="ap-toolbar ap-toolbar--play">
            <a class="ap-back" href="<?= portal_escape($backUrl) ?>">
                <span aria-hidden="true">←</span> Exit to course
            </a>
            <div class="ap-toolbar-center">
                <strong data-ap-title><?= portal_escape((string) $activity['title']) ?></strong>
                <span class="ap-toolbar-meta">
                    <span data-ap-mode-label><?= portal_escape(portal_activity_mode_label((string) $activity['mode'])) ?></span>
                    <span data-ap-question-counter></span>
                </span>
            </div>
            <div class="ap-toolbar-status">
                <div class="ap-timer" data-ap-timer hidden>
                    <?= portal_icon('clock', 'icon-sm') ?>
                    <span data-ap-timer-value>--</span>
                </div>
                <div class="ap-progress" aria-label="Answer progress">
                    <div class="ap-progress-label">
                        <span>Answered</span>
                        <span data-ap-progress-text>0%</span>
                    </div>
                    <div class="ap-progress-track"><div class="ap-progress-fill" data-ap-progress-fill></div></div>
                </div>
            </div>
        </div>

        <div class="ap-workspace">
            <aside class="ap-qnav-panel" aria-label="Question list">
                <p class="ap-qnav-heading">Questions</p>
                <div class="ap-qnav" data-ap-nav></div>
            </aside>
            <div class="ap-stage">
                <div class="ap-question-card" data-ap-question-root aria-live="polite"></div>
                <div class="ap-feedback" data-ap-feedback hidden></div>
                <div class="ap-footer-bar">
                    <button type="button" class="button button-secondary" data-ap-action="prev" disabled>Previous</button>
                    <span class="ap-save-state" data-ap-save-state aria-live="polite"></span>
                    <div class="ap-footer-right">
                        <button type="button" class="button ap-btn-continue" data-ap-action="next">Continue</button>
                        <button type="button" class="button ap-btn-submit" data-ap-action="submit" hidden>Submit quiz</button>
                    </div>
                </div>
                <p class="ap-early-submit" data-ap-early-submit hidden>
                    Finished early?
                    <button type="button" class="ap-text-link" data-ap-action="submit">Submit quiz now</button>
                </p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div data-ap-result hidden></div>

    <div class="ap-confirm" data-ap-confirm hidden role="presentation">
        <div class="ap-confirm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="ap-confirm-title" aria-describedby="ap-confirm-body">
            <div class="ap-confirm-brand" aria-label="Rangoon International Education Online">
                <img src="assets/rieo-crest.svg" alt="">
                <span><strong>RIEO</strong><small>Assessment system</small></span>
            </div>
            <div class="ap-confirm-icon" aria-hidden="true"><?= portal_icon('check-circle', 'icon-sm') ?></div>
            <h2 id="ap-confirm-title" class="ap-confirm-title">Submit quiz?</h2>
            <p id="ap-confirm-body" class="ap-confirm-body" data-ap-confirm-body></p>
            <div class="ap-confirm-actions">
                <button type="button" class="button button-secondary" data-ap-confirm-cancel>Keep editing</button>
                <button type="button" class="button" data-ap-confirm-ok>Submit quiz</button>
            </div>
        </div>
    </div>
</section>

<script type="application/json" id="ap-bootstrap"><?= portal_activity_json_encode($bootstrap) ?></script>
<?php if (($activity['mode'] ?? '') === 'flashcard'): ?>
<script src="assets/activity-flashcards.js?v=20260815gallery"></script>
<?php else: ?>
<script src="assets/activity-player.js?v=20260816-preenroll"></script>
<?php endif; ?>
<?php
$page_content = ob_get_clean();
require __DIR__ . '/../layout.php';
