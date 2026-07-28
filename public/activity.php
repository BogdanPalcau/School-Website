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

if (($activity['status'] ?? '') === 'draft' && !$canManage) {
    http_response_code(403);
    exit('This activity is not available yet.');
}

$courseStmt = $db->prepare('SELECT id, slug, full_title, title, code, accent FROM courses WHERE id = ?');
$courseStmt->execute([$courseId]);
$course = $courseStmt->fetch(PDO::FETCH_ASSOC) ?: [];

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
$summary = portal_activity_student_card_summary($activity, $uid);
$canStart = portal_activity_can_start($activity, $uid);

$inProgressId = $summary['in_progress_attempt_id'] ?? null;
$forcePlayer = isset($_GET['play']) || isset($_GET['attempt']);
$showPlayer = $inProgressId !== null && ($forcePlayer || isset($_GET['resume']) || (string) ($_GET['view'] ?? '') === 'play');

// Auto-enter player when resuming via ?resume=1 or when attempt query present
if ($inProgressId !== null && (isset($_GET['resume']) || (int) ($_GET['attempt'] ?? 0) === (int) $inProgressId)) {
    $showPlayer = true;
}

$latestResult = null;
$resultStmt = $db->prepare(
    "SELECT id, status, percentage, score, maximum_score, submitted_at, attempt_number
     FROM activity_attempts
     WHERE activity_id = ? AND user_id = ?
       AND status IN ('submitted','auto_submitted','awaiting_manual_marking','marked','released')
     ORDER BY attempt_number DESC LIMIT 1"
);
$resultStmt->execute([$activityId, $uid]);
$latestResult = $resultStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$showFeedback = false;
if ($latestResult) {
    $showFeedback = portal_activity_feedback_visible($activity, $latestResult);
    if (($activity['mode'] ?? '') === 'assessment' && empty($activity['results_released']) && ($latestResult['status'] ?? '') !== 'released') {
        $showFeedback = false;
    }
}

$needsIntegrityAck = ($activity['mode'] ?? '') === 'assessment' && !empty($activity['integrity_enabled']);

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
    'can_manage' => $canManage,
    'urls' => [
        'self' => 'activity.php?id=' . $activityId,
        'course' => 'course.php?course=' . urlencode((string) ($course['slug'] ?? '')) . '&section=content',
        'builder' => $canManage ? 'activity-builder.php?id=' . $activityId : null,
        'results' => $canManage ? 'activity-results.php?id=' . $activityId : null,
    ],
    'auto_start_player' => $showPlayer,
];

$backUrl = 'course.php?course=' . urlencode((string) ($course['slug'] ?? '')) . '&section=content';
$courseLabel = trim((string) ($course['code'] ?? '')) !== ''
    ? (string) $course['code']
    : (string) ($course['title'] ?? 'Course');
$page_title = (string) $activity['title'] . ' | ' . portal_school_name();
$page_eyebrow = portal_activity_mode_label((string) $activity['mode']);
$page_heading = (string) $activity['title'];
$page_description = (string) ($activity['short_description'] ?: 'Complete this activity for your course.');
$active_page = 'courses';

ob_start();
?>
<section class="activity-player"
         id="activity-player"
         data-activity-id="<?= (int) $activityId ?>"
         data-csrf="<?= portal_escape($csrfToken) ?>"
         data-mode="<?= portal_escape((string) $activity['mode']) ?>">

    <div data-ap-landing<?= $showPlayer ? ' hidden' : '' ?>>
        <div class="ap-toolbar">
            <a class="ap-back" href="<?= portal_escape($backUrl) ?>">
                <span aria-hidden="true">←</span> Back to course
            </a>
            <div class="ap-toolbar-actions">
                <?php if ($canManage): ?>
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
                    <?php if (($activity['status'] ?? '') === 'draft'): ?>
                        <span class="activity-status activity-status--not-started">Draft</span>
                    <?php endif; ?>
                </p>
                <h1 class="ap-lobby-title"><?= portal_escape((string) $activity['title']) ?></h1>
                <?php if (trim((string) $activity['short_description']) !== ''): ?>
                    <p class="ap-lobby-lead"><?= portal_escape((string) $activity['short_description']) ?></p>
                <?php endif; ?>

                <?php if (trim((string) $activity['instructions_html']) !== ''): ?>
                    <div class="ap-panel">
                        <h2 class="ap-panel-title">Instructions</h2>
                        <div class="ap-rich"><?= portal_sanitize_rich_text((string) $activity['instructions_html']) ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($needsIntegrityAck && $inProgressId === null): ?>
                    <div class="ap-panel ap-panel--warn" data-ap-integrity-notice>
                        <h2 class="ap-panel-title">Integrity notice</h2>
                        <p>This assessment monitors focus changes, paste/copy attempts, and similar signals. Clipboard contents are never stored — only counts and classifications.</p>
                        <label class="ap-ack-label">
                            <input type="checkbox" data-ap-integrity-ack>
                            I understand and will complete this assessment honestly.
                        </label>
                    </div>
                <?php endif; ?>

                <div class="ap-landing-actions">
                    <?php if ($inProgressId): ?>
                        <button type="button" class="button" data-ap-action="resume">Resume attempt</button>
                    <?php elseif (!empty($canStart['ok'])): ?>
                        <button type="button" class="button" data-ap-action="start">Begin</button>
                    <?php else: ?>
                        <p class="ap-locked-msg"><?= portal_escape((string) ($canStart['error'] ?? 'Not available')) ?></p>
                    <?php endif; ?>
                    <?php if ($latestResult): ?>
                        <button type="button" class="button button-secondary" data-ap-action="view-result">View last result</button>
                    <?php endif; ?>
                    <a class="ap-text-link" href="<?= portal_escape($backUrl) ?>">Cancel and return</a>
                </div>
            </div>

            <aside class="ap-lobby-aside" aria-label="Activity details">
                <h2 class="ap-panel-title">Details</h2>
                <dl class="ap-facts">
                    <div>
                        <dt>Attempts</dt>
                        <dd>
                            <?php if ((int) $activity['max_attempts'] > 0): ?>
                                <?= (int) $summary['attempt_count'] ?> used
                                <?php if ($summary['attempts_remaining'] !== null): ?>
                                    · <?= (int) $summary['attempts_remaining'] ?> left
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
                </dl>
            </aside>
        </div>
    </div>

    <div data-ap-shell<?= $showPlayer ? '' : ' hidden' ?>>
        <div class="ap-preview-banner" data-ap-banner hidden role="status"></div>
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
    </div>

    <div data-ap-result hidden></div>

    <div class="ap-confirm" data-ap-confirm hidden role="presentation">
        <div class="ap-confirm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="ap-confirm-title" aria-describedby="ap-confirm-body">
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
<script src="assets/activity-player.js?v=20260728f"></script>
<?php
$page_content = ob_get_clean();
require __DIR__ . '/../layout.php';
