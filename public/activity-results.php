<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
portal_require_login();

$db = portal_db();
$me = portal_current_user();
$uid = (int) ($me['id'] ?? 0);

$activityId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$activity = portal_activity_find($activityId);
if ($activity === null) {
    http_response_code(404);
    exit('Activity not found.');
}

portal_activity_require_manage($activity);
$courseId = (int) $activity['course_id'];

$courseStmt = $db->prepare('SELECT id, slug, full_title, title, code, accent FROM courses WHERE id = ?');
$courseStmt->execute([$courseId]);
$course = $courseStmt->fetch(PDO::FETCH_ASSOC) ?: [];

/**
 * @return array{label: string, class: string}
 */
function portal_ar_status_meta(string $status): array
{
    return match ($status) {
        'awaiting_manual_marking' => ['label' => 'Needs marking', 'class' => 'warn'],
        'marked' => ['label' => 'Marked', 'class' => 'info'],
        'released' => ['label' => 'Released', 'class' => 'good'],
        'in_progress' => ['label' => 'In progress', 'class' => 'muted'],
        'invalidated' => ['label' => 'Invalidated', 'class' => 'bad'],
        'auto_submitted' => ['label' => 'Auto-submitted', 'class' => 'muted'],
        'submitted' => ['label' => 'Submitted', 'class' => 'info'],
        default => ['label' => ucfirst(str_replace('_', ' ', $status)), 'class' => 'muted'],
    };
}

function portal_ar_initials(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '?';
    }
    $parts = preg_split('/\s+/', $name) ?: [$name];
    $parts = array_values(array_filter($parts, static fn ($p) => $p !== ''));
    if (count($parts) >= 2) {
        return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[count($parts) - 1], 0, 1));
    }
    return mb_strtoupper(mb_substr($parts[0], 0, 2));
}

/**
 * @return array<string, mixed>
 */
function portal_ar_request_payload(): array
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
function portal_ar_verify_csrf(array $payload): bool
{
    $token = (string) ($payload['_token'] ?? $_POST['_token'] ?? '');
    $valid = $token !== ''
        && !empty($_SESSION['_csrf'])
        && hash_equals((string) $_SESSION['_csrf'], $token);
    if (!$valid && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
        portal_log_security_event('csrf_failed', 'high', 'activity-results CSRF failed');
    }
    return $valid;
}

/**
 * @return array<string, mixed>|null
 */
function portal_ar_load_attempt_detail(int $attemptId, int $activityId): ?array
{
    $db = portal_db();
    $stmt = $db->prepare(
        "SELECT t.*, u.name AS student_name, u.initials AS student_initials, u.email AS student_email
         FROM activity_attempts t
         JOIN users u ON u.id = t.user_id
         WHERE t.id = ? AND t.activity_id = ?"
    );
    $stmt->execute([$attemptId, $activityId]);
    $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$attempt) {
        return null;
    }

    $activity = portal_activity_find($activityId);
    $tree = portal_activity_load_version_tree((int) $attempt['activity_version_id'], true);
    $ansStmt = $db->prepare('SELECT * FROM activity_answers WHERE attempt_id = ?');
    $ansStmt->execute([$attemptId]);
    $answers = [];
    foreach ($ansStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $answers[(int) $row['question_id']] = [
            'answer' => portal_activity_json_decode((string) $row['answer_json'], null),
            'auto_score' => $row['auto_score'],
            'manual_score' => $row['manual_score'],
            'final_score' => $row['final_score'],
            'feedback_html' => $row['feedback_html'],
            'marked_at' => $row['marked_at'],
        ];
    }

    $eventsStmt = $db->prepare(
        'SELECT id, event_type, source_classification, event_metadata_json, client_elapsed_ms,
                occurred_at, received_at, question_id
         FROM activity_integrity_events
         WHERE attempt_id = ?
         ORDER BY received_at ASC, id ASC'
    );
    $eventsStmt->execute([$attemptId]);
    $events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($events as &$ev) {
        $meta = portal_activity_json_decode((string) ($ev['event_metadata_json'] ?? '{}'), []) ?: [];
        unset($meta['clipboard'], $meta['text'], $meta['paste_text'], $meta['html'], $meta['content']);
        $ev['metadata'] = $meta;
        $label = portal_activity_integrity_event_label((string) ($ev['event_type'] ?? ''));
        if (($ev['event_type'] ?? '') === 'paste_allowed' || ($ev['event_type'] ?? '') === 'paste_attempt') {
            $src = (string) ($ev['source_classification'] ?? $meta['source_classification'] ?? '');
            if ($src === 'likely_internal_portal') {
                $label = 'Paste from likely portal content';
            } elseif ($src === 'external_or_unknown') {
                $label = 'External or unknown paste attempt';
            }
        }
        $ev['label'] = $label;
        $ev['severity'] = portal_activity_integrity_event_severity($ev);
        unset($ev['event_metadata_json']);
    }
    unset($ev);

    $questions = [];
    $needsMarking = 0;
    $suggestionsReady = 0;
    foreach ($tree['questions'] as $q) {
        $qid = (int) $q['id'];
        $options = $tree['options_by_question'][$qid] ?? [];
        $settings = portal_activity_json_decode((string) ($q['settings_json'] ?? '{}'), []) ?: [];
        $points = (float) $q['points'];
        $isManual = (int) $q['manual_marking'];
        $questionMeta = [
            'id' => $qid,
            'question_type' => $q['question_type'],
            'prompt_html' => $q['prompt_html'],
            'points' => $points,
            'manual_marking' => $isManual,
            'options' => $options,
            'settings' => $settings,
        ];

        // Enrich the stored answer with everything the marking pane needs:
        // a readable version of what the student wrote, how the auto-marker
        // scored it, and (for written work) an advisory suggestion.
        $answerRow = $answers[$qid] ?? null;
        $rawAnswer = $answerRow['answer'] ?? null;
        $autoScore = $answerRow['auto_score'] ?? null;
        $manualScore = $answerRow['manual_score'] ?? null;
        $awaiting = $isManual === 1 && $manualScore === null;
        if ($awaiting) {
            $needsMarking++;
        }

        $view = portal_activity_answer_view($questionMeta, $options, $rawAnswer);
        $suggestion = $isManual === 1
            ? portal_activity_written_suggestion($questionMeta, $rawAnswer)
            : null;
        if ($suggestion !== null && $awaiting) {
            $suggestionsReady++;
        }

        $answers[$qid] = array_merge($answerRow ?? [
            'answer' => null,
            'auto_score' => null,
            'manual_score' => null,
            'final_score' => null,
            'feedback_html' => '',
            'marked_at' => '',
        ], [
            'view' => $view,
            'suggestion' => $suggestion,
            'awaiting_marking' => $awaiting,
            'auto_correct' => ($isManual === 0 && $autoScore !== null && $points > 0)
                ? ((float) $autoScore >= $points - 1e-9)
                : null,
            'has_response' => ($view['kind'] ?? 'empty') !== 'empty',
        ]);

        $questionMeta['expected_answer'] = trim((string) ($settings['expected_answer'] ?? ''));
        $questionMeta['keywords'] = is_array($settings['keywords'] ?? null) ? $settings['keywords'] : [];
        $questions[] = $questionMeta;
    }

    $rubrics = [];
    foreach ($questions as $q) {
        $rStmt = $db->prepare('SELECT * FROM activity_rubrics WHERE question_id = ? LIMIT 1');
        $rStmt->execute([(int) $q['id']]);
        $rubric = $rStmt->fetch(PDO::FETCH_ASSOC);
        if (!$rubric) {
            continue;
        }
        $cStmt = $db->prepare(
            'SELECT * FROM activity_rubric_criteria WHERE rubric_id = ? ORDER BY sort_order, id'
        );
        $cStmt->execute([(int) $rubric['id']]);
        $criteria = [];
        foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $lStmt = $db->prepare(
                'SELECT * FROM activity_rubric_levels WHERE criterion_id = ? ORDER BY sort_order, id'
            );
            $lStmt->execute([(int) $c['id']]);
            $c['levels'] = $lStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $criteria[] = $c;
        }
        $rubric['criteria'] = $criteria;
        $rubrics[(int) $q['id']] = $rubric;
    }

    $endReason = (string) ($attempt['end_reason'] ?? '');
    $endReasonMeta = portal_activity_attempt_end_reason_meta($endReason);
    $canReopen = ($activity['mode'] ?? '') === 'assessment'
        && in_array($endReason, ['page_left', 'connection_lost'], true)
        && empty($activity['results_released'])
        && in_array((string) ($attempt['status'] ?? ''), ['submitted', 'auto_submitted', 'awaiting_manual_marking', 'marked'], true);

    return [
        'attempt' => [
            'id' => (int) $attempt['id'],
            'user_id' => (int) $attempt['user_id'],
            'student_name' => $attempt['student_name'],
            'student_initials' => $attempt['student_initials'],
            'status' => $attempt['status'],
            'attempt_number' => (int) $attempt['attempt_number'],
            'started_at' => $attempt['started_at'],
            'submitted_at' => $attempt['submitted_at'],
            'score' => $attempt['score'],
            'maximum_score' => $attempt['maximum_score'],
            'percentage' => $attempt['percentage'],
            'overall_feedback_html' => $attempt['overall_feedback_html'],
            'end_reason' => $endReason,
            'end_reason_label' => $endReasonMeta['label'],
            'end_reason_description' => $endReasonMeta['description'],
            'end_reason_class' => $endReasonMeta['class'],
            'reopened_at' => (string) ($attempt['reopened_at'] ?? ''),
            'reopen_count' => (int) ($attempt['reopen_count'] ?? 0),
            'reopen_note' => (string) ($attempt['reopen_note'] ?? ''),
            'can_reopen' => $canReopen,
        ],
        'activity' => [
            'id' => $activityId,
            'title' => $activity['title'] ?? '',
            'mode' => $activity['mode'] ?? '',
            'results_released' => (int) ($activity['results_released'] ?? 0),
        ],
        'questions' => $questions,
        'answers' => $answers,
        'marking' => [
            'needs_marking' => $needsMarking,
            'suggestions_ready' => $suggestionsReady,
            'total_questions' => count($questions),
        ],
        'integrity_events' => $events,
        'integrity_summary' => portal_activity_integrity_summary_label($events),
        'integrity_review' => portal_activity_integrity_review_state($events),
        'rubrics' => $rubrics,
        'accommodation' => portal_activity_get_accommodation($activityId, (int) $attempt['user_id']),
    ];
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
    $payload = portal_ar_request_payload();
    if (!portal_ar_verify_csrf($payload)) {
        portal_activity_json_error('Invalid security token. Refresh and try again.', 403);
    }

    $action = (string) ($payload['action'] ?? '');

    switch ($action) {
        case 'mark_answer': {
            $attemptId = (int) ($payload['attempt_id'] ?? 0);
            $questionId = (int) ($payload['question_id'] ?? 0);
            $manualScore = array_key_exists('manual_score', $payload) ? (float) $payload['manual_score'] : null;
            $feedback = portal_sanitize_rich_text((string) ($payload['feedback_html'] ?? ''));

            $chk = $db->prepare('SELECT id FROM activity_attempts WHERE id = ? AND activity_id = ?');
            $chk->execute([$attemptId, $activityId]);
            if (!$chk->fetchColumn()) {
                portal_activity_json_error('Attempt not found.', 404);
            }

            $qChk = $db->prepare(
                "SELECT q.points FROM activity_questions q
                 JOIN activity_attempts t ON t.activity_version_id = q.activity_version_id
                 WHERE q.id = ? AND t.id = ?"
            );
            $qChk->execute([$questionId, $attemptId]);
            $points = $qChk->fetchColumn();
            if ($points === false) {
                portal_activity_json_error('Question not found for this attempt.');
            }
            if ($manualScore !== null) {
                $manualScore = max(0.0, min((float) $points, $manualScore));
            }

            $exists = $db->prepare(
                'SELECT id FROM activity_answers WHERE attempt_id = ? AND question_id = ?'
            );
            $exists->execute([$attemptId, $questionId]);
            if ($exists->fetchColumn()) {
                $db->prepare(
                    "UPDATE activity_answers
                     SET manual_score = ?, final_score = COALESCE(?, auto_score),
                         feedback_html = ?, marked_by = ?, marked_at = datetime('now'),
                         updated_at = datetime('now')
                     WHERE attempt_id = ? AND question_id = ?"
                )->execute([$manualScore, $manualScore, $feedback, $uid ?: null, $attemptId, $questionId]);
            } else {
                $db->prepare(
                    "INSERT INTO activity_answers
                        (attempt_id, question_id, answer_json, manual_score, final_score,
                         feedback_html, marked_by, marked_at)
                     VALUES (?,?,?,?,?,?,?,datetime('now'))"
                )->execute([
                    $attemptId, $questionId, 'null', $manualScore, $manualScore,
                    $feedback, $uid ?: null,
                ]);
            }

            portal_activity_score_attempt($attemptId);
            portal_activity_audit($activityId, 'answer_marked', [
                'attempt_id' => $attemptId,
                'question_id' => $questionId,
            ]);

            $detail = portal_ar_load_attempt_detail($attemptId, $activityId);
            portal_activity_json_ok(['detail' => $detail]);
        }

        case 'apply_suggestions': {
            // Fills in the suggested mark for every unmarked written answer on
            // the attempt. This is an explicit teacher action — nothing is
            // released, and each mark stays editable afterwards.
            $attemptId = (int) ($payload['attempt_id'] ?? 0);
            $chk = $db->prepare('SELECT id FROM activity_attempts WHERE id = ? AND activity_id = ?');
            $chk->execute([$attemptId, $activityId]);
            if (!$chk->fetchColumn()) {
                portal_activity_json_error('Attempt not found.', 404);
            }

            $detail = portal_ar_load_attempt_detail($attemptId, $activityId);
            if ($detail === null) {
                portal_activity_json_error('Attempt not found.', 404);
            }

            $applied = 0;
            foreach ($detail['questions'] as $question) {
                $qid = (int) $question['id'];
                $answer = $detail['answers'][$qid] ?? null;
                if ($answer === null || empty($answer['awaiting_marking'])) {
                    continue;
                }
                $suggestion = $answer['suggestion'] ?? null;
                if (!is_array($suggestion)) {
                    continue;
                }
                $score = max(0.0, min((float) $question['points'], (float) $suggestion['suggested_score']));
                $db->prepare(
                    "INSERT INTO activity_answers
                        (attempt_id, question_id, answer_json, manual_score, final_score, marked_by, marked_at)
                     VALUES (?,?,'null',?,?,?,datetime('now'))
                     ON CONFLICT(attempt_id, question_id) DO UPDATE SET
                        manual_score = excluded.manual_score,
                        final_score = excluded.manual_score,
                        marked_by = excluded.marked_by,
                        marked_at = datetime('now'),
                        updated_at = datetime('now')"
                )->execute([$attemptId, $qid, $score, $score, $uid ?: null]);
                $applied++;
            }

            if ($applied === 0) {
                portal_activity_json_error('No suggestions available to apply. Add an expected answer to the written questions first.');
            }

            portal_activity_score_attempt($attemptId);
            portal_activity_audit($activityId, 'suggestions_applied', [
                'attempt_id' => $attemptId,
                'questions' => $applied,
            ]);
            portal_activity_json_ok([
                'applied' => $applied,
                'detail' => portal_ar_load_attempt_detail($attemptId, $activityId),
            ]);
        }

        case 'complete_marking': {
            $attemptId = (int) ($payload['attempt_id'] ?? 0);
            $overall = portal_sanitize_rich_text((string) ($payload['overall_feedback_html'] ?? ''));
            $chk = $db->prepare('SELECT id, status FROM activity_attempts WHERE id = ? AND activity_id = ?');
            $chk->execute([$attemptId, $activityId]);
            $row = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                portal_activity_json_error('Attempt not found.', 404);
            }

            portal_activity_score_attempt($attemptId);

            // A grade cannot be finalised while written answers are unmarked.
            $detail = portal_ar_load_attempt_detail($attemptId, $activityId);
            $pending = (int) ($detail['marking']['needs_marking'] ?? 0);
            if ($pending > 0) {
                portal_activity_json_error(
                    $pending === 1
                        ? 'One answer still needs your mark before this attempt can be completed.'
                        : $pending . ' answers still need your mark before this attempt can be completed.',
                    400,
                    ['detail' => $detail]
                );
            }

            $db->prepare(
                "UPDATE activity_attempts
                 SET status = CASE WHEN status = 'released' THEN 'released' ELSE 'marked' END,
                     overall_feedback_html = ?, updated_at = datetime('now')
                 WHERE id = ? AND status IN ('submitted','auto_submitted','awaiting_manual_marking','marked','released')"
            )->execute([$overall, $attemptId]);

            portal_activity_audit($activityId, 'marking_completed', ['attempt_id' => $attemptId]);
            portal_activity_json_ok(['detail' => portal_ar_load_attempt_detail($attemptId, $activityId)]);
        }

        case 'release_results': {
            $attemptId = (int) ($payload['attempt_id'] ?? 0);
            $releaseAll = !empty($payload['all']);

            if ($releaseAll) {
                $pending = $db->prepare(
                    "SELECT COUNT(*) FROM activity_attempts
                     WHERE activity_id = ? AND status = 'awaiting_manual_marking'"
                );
                $pending->execute([$activityId]);
                $pendingCount = (int) $pending->fetchColumn();
                if ($pendingCount > 0) {
                    portal_activity_json_error(
                        'Cannot release grades yet — ' . $pendingCount . ' attempt'
                        . ($pendingCount === 1 ? '' : 's')
                        . ' still need teacher marking on written answers.',
                        400
                    );
                }
                $db->prepare(
                    "UPDATE course_activities SET results_released = 1, updated_at = datetime('now'), version = version + 1
                     WHERE id = ?"
                )->execute([$activityId]);
                $db->prepare(
                    "UPDATE activity_attempts
                     SET status = 'released', grade_seen_at = '', updated_at = datetime('now')
                     WHERE activity_id = ?
                       AND status IN ('submitted','auto_submitted','marked')"
                )->execute([$activityId]);
                $activityForRewards = portal_activity_find($activityId);
                if ($activityForRewards && ($activityForRewards['mode'] ?? '') === 'assessment') {
                    $releasedStmt = $db->prepare(
                        "SELECT id, user_id FROM activity_attempts
                         WHERE activity_id = ? AND status = 'released'"
                    );
                    $releasedStmt->execute([$activityId]);
                    foreach ($releasedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $releasedAttempt) {
                        portal_activity_award_badges_after_attempt(
                            (int) $releasedAttempt['user_id'],
                            $activityForRewards,
                            (int) $releasedAttempt['id']
                        );
                    }
                }
                portal_activity_audit($activityId, 'results_released_all');
            } else {
                $chk = $db->prepare('SELECT id, status FROM activity_attempts WHERE id = ? AND activity_id = ?');
                $chk->execute([$attemptId, $activityId]);
                $row = $chk->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    portal_activity_json_error('Attempt not found.', 404);
                }
                if ((string) ($row['status'] ?? '') === 'awaiting_manual_marking') {
                    portal_activity_json_error(
                        'Cannot release this grade until written answers have been marked.',
                        400
                    );
                }
                $db->prepare(
                    "UPDATE activity_attempts SET status = 'released', grade_seen_at = '', updated_at = datetime('now') WHERE id = ?"
                )->execute([$attemptId]);
                $activityForRewards = portal_activity_find($activityId);
                if ($activityForRewards && ($activityForRewards['mode'] ?? '') === 'assessment') {
                    $rewardUser = $db->prepare('SELECT user_id FROM activity_attempts WHERE id = ?');
                    $rewardUser->execute([$attemptId]);
                    portal_activity_award_badges_after_attempt(
                        (int) $rewardUser->fetchColumn(),
                        $activityForRewards,
                        $attemptId
                    );
                }
                portal_activity_audit($activityId, 'results_released', ['attempt_id' => $attemptId]);
            }

            $activity = portal_activity_find($activityId);
            portal_activity_json_ok([
                'activity' => $activity,
                'detail' => $attemptId > 0 ? portal_ar_load_attempt_detail($attemptId, $activityId) : null,
            ]);
        }

        case 'invalidate_attempt': {
            $attemptId = (int) ($payload['attempt_id'] ?? 0);
            $reason = substr(trim((string) ($payload['reason'] ?? '')), 0, 500);
            $chk = $db->prepare('SELECT id FROM activity_attempts WHERE id = ? AND activity_id = ?');
            $chk->execute([$attemptId, $activityId]);
            if (!$chk->fetchColumn()) {
                portal_activity_json_error('Attempt not found.', 404);
            }
            $db->prepare(
                "UPDATE activity_attempts
                 SET status = 'invalidated', overall_feedback_html = ?, updated_at = datetime('now')
                 WHERE id = ?"
            )->execute([$reason !== '' ? portal_escape($reason) : 'Attempt invalidated by staff.', $attemptId]);
            $db->prepare('DELETE FROM gamification_events WHERE attempt_id = ?')->execute([$attemptId]);
            portal_activity_audit($activityId, 'attempt_invalidated', [
                'attempt_id' => $attemptId,
                'reason' => $reason,
            ]);
            portal_activity_json_ok(['detail' => portal_ar_load_attempt_detail($attemptId, $activityId)]);
        }

        case 'reopen_attempt': {
            $attemptId = (int) ($payload['attempt_id'] ?? 0);
            $note = substr(trim((string) ($payload['note'] ?? '')), 0, 500);
            $chk = $db->prepare(
                'SELECT id, user_id, attempt_number, status, end_reason
                 FROM activity_attempts WHERE id = ? AND activity_id = ?'
            );
            $chk->execute([$attemptId, $activityId]);
            $row = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                portal_activity_json_error('Attempt not found.', 404);
            }
            if (($activity['mode'] ?? '') !== 'assessment'
                || !in_array((string) ($row['end_reason'] ?? ''), ['page_left', 'connection_lost'], true)
                || !in_array((string) ($row['status'] ?? ''), ['submitted', 'auto_submitted', 'awaiting_manual_marking', 'marked'], true)) {
                portal_activity_json_error('Only an assessment ended by leaving or connection loss can be reopened.');
            }
            if (!empty($activity['results_released'])) {
                portal_activity_json_error('This attempt cannot be reopened after results have been released.');
            }

            $newer = $db->prepare(
                'SELECT COUNT(*) FROM activity_attempts
                 WHERE activity_id = ? AND user_id = ? AND attempt_number > ?'
            );
            $newer->execute([$activityId, (int) $row['user_id'], (int) $row['attempt_number']]);
            if ((int) $newer->fetchColumn() > 0) {
                portal_activity_json_error('This attempt cannot be reopened because the student has already started a newer attempt.');
            }
            $active = $db->prepare(
                "SELECT COUNT(*) FROM activity_attempts
                 WHERE activity_id = ? AND user_id = ? AND id <> ? AND status = 'in_progress'"
            );
            $active->execute([$activityId, (int) $row['user_id'], $attemptId]);
            if ((int) $active->fetchColumn() > 0) {
                portal_activity_json_error('This attempt cannot be reopened while the student has another active attempt.');
            }

            $db->beginTransaction();
            try {
                $db->prepare(
                    "UPDATE activity_attempts
                     SET status = 'in_progress', submitted_at = '', score = NULL,
                         maximum_score = NULL, percentage = NULL, end_reason = '',
                         resume_allowed = 1, session_token_hash = '', reopened_at = datetime('now'),
                         reopened_by = ?, reopen_count = reopen_count + 1, reopen_note = ?,
                         overall_feedback_html = '', updated_at = datetime('now')
                     WHERE id = ?"
                )->execute([$uid ?: null, $note, $attemptId]);
                $db->prepare(
                    "UPDATE activity_answers
                     SET auto_score = NULL, manual_score = NULL, final_score = NULL,
                         feedback_html = '', marked_by = NULL, marked_at = '', updated_at = datetime('now')
                     WHERE attempt_id = ?"
                )->execute([$attemptId]);
                $db->prepare('DELETE FROM gamification_events WHERE attempt_id = ?')->execute([$attemptId]);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                portal_activity_json_error('Could not reopen this attempt.');
            }

            portal_activity_record_integrity_event(
                $attemptId,
                (int) $row['user_id'],
                'attempt_reopened',
                'attempt-reopened-' . $attemptId . '-' . bin2hex(random_bytes(6)),
                null,
                'source_not_available',
                ['reopened_by' => $uid]
            );
            portal_activity_audit($activityId, 'attempt_reopened', [
                'attempt_id' => $attemptId,
                'student_id' => (int) $row['user_id'],
                'note' => $note,
            ]);
            portal_activity_json_ok(['detail' => portal_ar_load_attempt_detail($attemptId, $activityId)]);
        }

        case 'delete_attempt':
        case 'delete_attempts': {
            $ids = [];
            if (isset($payload['attempt_ids']) && is_array($payload['attempt_ids'])) {
                foreach ($payload['attempt_ids'] as $id) {
                    $ids[] = (int) $id;
                }
            }
            if (!empty($payload['attempt_id'])) {
                $ids[] = (int) $payload['attempt_id'];
            }
            $result = portal_activity_delete_attempts($activityId, $ids);
            if (empty($result['ok'])) {
                portal_activity_json_error((string) ($result['error'] ?? 'Could not delete.'), 400, $result);
            }
            portal_activity_json_ok([
                'deleted' => (int) ($result['deleted'] ?? 0),
                'attempt_ids' => $result['attempt_ids'] ?? [],
                'summary' => portal_activity_results_summary($activityId),
            ]);
        }

        case 'save_accommodation': {
            $targetUser = (int) ($payload['user_id'] ?? 0);
            $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : $payload;
            $result = portal_activity_save_accommodation($activityId, $targetUser, $fields, $uid);
            if (empty($result['ok'])) {
                portal_activity_json_error((string) ($result['error'] ?? 'Could not save.'), 400, $result);
            }
            portal_activity_json_ok($result);
        }

        case 'export_csv': {
            $stmt = $db->prepare(
                "SELECT u.name, u.email, t.attempt_number, t.status, t.score, t.maximum_score,
                        t.percentage, t.started_at, t.submitted_at,
                        (SELECT COUNT(*) FROM activity_integrity_events ie
                         WHERE ie.attempt_id = t.id
                           AND ie.event_type NOT IN ('window_focus','connection_restored')
                           AND NOT (
                               ie.event_type IN ('paste_attempt','paste_allowed')
                               AND ie.source_classification = 'likely_internal_portal'
                           )) AS integrity_flags
                 FROM activity_attempts t
                 JOIN users u ON u.id = t.user_id
                 WHERE t.activity_id = ?
                 ORDER BY u.name ASC, t.attempt_number ASC"
            );
            $stmt->execute([$activityId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="activity-' . $activityId . '-results.csv"');
            header('X-Content-Type-Options: nosniff');
            $out = fopen('php://output', 'w');
            if ($out === false) {
                portal_activity_json_error('Could not export.');
            }
            fputcsv($out, ['Name', 'Email', 'Attempt', 'Status', 'Score', 'Maximum', 'Percentage', 'Integrity flags', 'Started', 'Submitted']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    portal_activity_csv_safe((string) $r['name']),
                    portal_activity_csv_safe((string) $r['email']),
                    (int) $r['attempt_number'],
                    (string) $r['status'],
                    $r['score'] ?? '',
                    $r['maximum_score'] ?? '',
                    $r['percentage'] ?? '',
                    (int) ($r['integrity_flags'] ?? 0),
                    (string) $r['started_at'],
                    (string) $r['submitted_at'],
                ]);
            }
            fclose($out);
            exit;
        }

        case 'load_attempt': {
            $attemptId = (int) ($payload['attempt_id'] ?? 0);
            $detail = portal_ar_load_attempt_detail($attemptId, $activityId);
            if ($detail === null) {
                portal_activity_json_error('Attempt not found.', 404);
            }
            portal_activity_json_ok(['detail' => $detail]);
        }

        default:
            portal_activity_json_error('Unknown action.', 400);
    }
}

$csrfToken = portal_csrf_token();
$summary = portal_activity_results_summary($activityId);
$analytics = portal_activity_question_analytics($activityId);

$statusFilter = (string) ($_GET['status'] ?? '');
$q = trim((string) ($_GET['q'] ?? ''));

$sql = "SELECT t.id, t.user_id, t.attempt_number, t.status, t.percentage, t.score,
               t.maximum_score, t.started_at, t.submitted_at, t.updated_at, t.end_reason,
               (SELECT COUNT(*) FROM activity_integrity_events ie
                WHERE ie.attempt_id = t.id) AS integrity_event_count,
               (SELECT COUNT(*) FROM activity_integrity_events ie
                WHERE ie.attempt_id = t.id
                  AND ie.event_type NOT IN ('window_focus','connection_restored')
                  AND NOT (
                      ie.event_type IN ('paste_attempt','paste_allowed')
                      AND ie.source_classification = 'likely_internal_portal'
                  )) AS integrity_flag_count,
               u.name AS student_name, u.initials AS student_initials
        FROM activity_attempts t
        JOIN users u ON u.id = t.user_id
        WHERE t.activity_id = ?";
$params = [$activityId];
if ($statusFilter === 'integrity_flagged') {
    $sql .= " AND EXISTS (
        SELECT 1 FROM activity_integrity_events ie
        WHERE ie.attempt_id = t.id
          AND ie.event_type NOT IN ('window_focus','connection_restored')
          AND NOT (
              ie.event_type IN ('paste_attempt','paste_allowed')
              AND ie.source_classification = 'likely_internal_portal'
          )
    )";
} elseif ($statusFilter !== '' && in_array($statusFilter, [
    'in_progress', 'submitted', 'auto_submitted', 'awaiting_manual_marking', 'marked', 'released', 'invalidated',
], true)) {
    $sql .= ' AND t.status = ?';
    $params[] = $statusFilter;
}
if ($q !== '') {
    $sql .= ' AND (u.name LIKE ? OR u.email LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
$sql .= ' ORDER BY t.submitted_at DESC, t.id DESC LIMIT 200';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$attempts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$selectedAttemptId = (int) ($_GET['attempt'] ?? ($attempts[0]['id'] ?? 0));
$selectedDetail = $selectedAttemptId > 0
    ? portal_ar_load_attempt_detail($selectedAttemptId, $activityId)
    : null;

$bootstrap = [
    'activity' => $activity,
    'summary' => $summary,
    'analytics' => $analytics,
    'attempts' => $attempts,
    'detail' => $selectedDetail,
    'csrf' => $csrfToken,
    'filters' => ['status' => $statusFilter, 'q' => $q],
    'urls' => [
        'self' => 'activity-results.php?id=' . $activityId,
        'builder' => 'activity-builder.php?id=' . $activityId,
        'player' => 'activity.php?id=' . $activityId,
        'course' => 'course.php?course=' . urlencode((string) ($course['slug'] ?? '')) . '&section=content',
    ],
];

$backUrl = 'activity-builder.php?id=' . $activityId;
$page_title = 'Submissions · ' . (string) $activity['title'];
$page_eyebrow = 'Student submissions';
$page_heading = (string) $activity['title'];
$page_description = 'Review attempts, mark responses, and release grades.';
$active_page = 'courses';

ob_start();
?>
<section class="activity-results"
         id="activity-results"
         data-activity-id="<?= (int) $activityId ?>"
         data-csrf="<?= portal_escape($csrfToken) ?>">

    <header class="ar-header">
        <a class="ar-back" href="<?= portal_escape($backUrl) ?>">
            <span aria-hidden="true">←</span>
            <span>Back to builder</span>
        </a>
        <div class="ar-header-actions">
            <button type="button" class="button button--ghost" data-ar-action="export-csv">Export CSV</button>
            <button type="button" class="button" data-ar-action="release-all">Release all grades</button>
            <a class="button button--ghost" href="activity.php?id=<?= (int) $activityId ?>">Open player</a>
        </div>
    </header>

    <div class="activity-results-summary" data-ar-summary>
        <div class="activity-results-stat"><strong><?= (int) $summary['attempts'] ?></strong><span>Attempts</span></div>
        <div class="activity-results-stat"><strong><?= (int) $summary['students'] ?></strong><span>Students</span></div>
        <div class="activity-results-stat"><strong><?= $summary['avg_percentage'] !== null ? portal_escape((string) $summary['avg_percentage']) . '%' : '—' ?></strong><span>Average</span></div>
        <div class="activity-results-stat"><strong><?= (int) $summary['awaiting_marking'] ?></strong><span>Awaiting marking</span></div>
        <div class="activity-results-stat activity-results-stat--integrity<?= (int) ($summary['integrity_flagged'] ?? 0) > 0 ? ' has-flags' : '' ?>">
            <strong><?= (int) ($summary['integrity_flagged'] ?? 0) ?></strong><span>Integrity flagged</span>
        </div>
    </div>

    <div class="ar-layout">
        <aside class="ar-list-panel">
            <form class="ar-filters" method="GET">
                <input type="hidden" name="id" value="<?= (int) $activityId ?>">
                <label class="ar-filters-search">
                    <span class="visually-hidden">Search</span>
                    <?= portal_icon('search', 'icon-sm') ?>
                    <input type="search" name="q" value="<?= portal_escape($q) ?>" placeholder="Search students…">
                </label>
                <div class="ar-filters-row">
                    <label class="ar-filters-status">
                        <span class="visually-hidden">Status</span>
                        <select name="status">
                            <option value="">All statuses</option>
                            <option value="integrity_flagged"<?= $statusFilter === 'integrity_flagged' ? ' selected' : '' ?>>Integrity flagged</option>
                            <?php foreach (['submitted','awaiting_manual_marking','marked','released','in_progress','invalidated','auto_submitted'] as $st): ?>
                                <option value="<?= $st ?>"<?= $statusFilter === $st ? ' selected' : '' ?>><?= portal_escape(portal_ar_status_meta($st)['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit" class="ar-btn ar-btn--sm">Filter</button>
                </div>
            </form>
            <div class="ar-list-heading">
                <span data-ar-list-count><?= count($attempts) ?> submission<?= count($attempts) === 1 ? '' : 's' ?></span>
                <?php if ($attempts !== []): ?>
                    <label class="ar-bulk-select-all">
                        <input type="checkbox" data-ar-select-all>
                        <span>Select all</span>
                    </label>
                <?php endif; ?>
            </div>
            <?php if ($attempts !== []): ?>
                <div class="ar-bulk-bar" data-ar-bulk-bar hidden>
                    <span class="ar-bulk-count" data-ar-bulk-count>0 selected</span>
                    <button type="button" class="ar-btn ar-btn--danger ar-btn--sm" data-ar-action="delete-selected" disabled>
                        <?= portal_icon('trash', 'icon-sm') ?>
                        Delete selected
                    </button>
                </div>
            <?php endif; ?>
            <div class="activity-results-list" data-ar-attempt-list role="list">
                <?php if ($attempts === []): ?>
                    <div class="ar-empty ar-empty--panel">
                        <strong>No submissions yet</strong>
                        <p>When students take this activity, their attempts show up here for review and marking.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($attempts as $row):
                        $statusMeta = portal_ar_status_meta((string) $row['status']);
                        $initials = portal_ar_initials((string) $row['student_name']);
                        $endReasonMeta = portal_activity_attempt_end_reason_meta((string) ($row['end_reason'] ?? ''));
                    ?>
                        <div class="ar-attempt-row<?= (int) $row['id'] === $selectedAttemptId ? ' is-selected' : '' ?>"
                             data-ar-attempt-row="<?= (int) $row['id'] ?>"
                             role="listitem">
                            <label class="ar-attempt-check" title="Select attempt">
                                <input type="checkbox" data-ar-select-attempt value="<?= (int) $row['id'] ?>">
                                <span class="visually-hidden">Select attempt <?= (int) $row['attempt_number'] ?></span>
                            </label>
                            <button type="button"
                                    class="ar-attempt-card<?= (int) $row['id'] === $selectedAttemptId ? ' is-selected' : '' ?>"
                                    data-ar-attempt="<?= (int) $row['id'] ?>">
                                <span class="ar-attempt-avatar" aria-hidden="true"><?= portal_escape($initials) ?></span>
                                <span class="ar-attempt-info">
                                    <span class="ar-attempt-title-row">
                                        <strong><?= portal_escape((string) $row['student_name']) ?></strong>
                                        <span class="ar-attempt-score"><?= $row['percentage'] !== null ? portal_escape((string) round((float) $row['percentage'], 1)) . '%' : '—' ?></span>
                                    </span>
                                    <span class="ar-attempt-meta">
                                        <span class="ar-attempt-pill ar-attempt-pill--<?= $statusMeta['class'] ?>"><?= portal_escape($statusMeta['label']) ?></span>
                                        <span class="ar-attempt-num">Attempt <?= (int) $row['attempt_number'] ?></span>
                                    </span>
                                    <?php if ((int) ($row['integrity_flag_count'] ?? 0) > 0): ?>
                                        <span class="ar-attempt-integrity">
                                            <span class="ar-attempt-pill ar-attempt-pill--integrity" title="Review integrity signals before releasing this result">
                                                Integrity flagged · <?= (int) $row['integrity_flag_count'] ?>
                                            </span>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (in_array((string) ($row['end_reason'] ?? ''), ['page_left', 'connection_lost', 'time_expired'], true)): ?>
                                        <span class="ar-attempt-end ar-attempt-end--<?= portal_escape((string) $endReasonMeta['class']) ?>">
                                            <?= portal_escape((string) $endReasonMeta['label']) ?>
                                        </span>
                                    <?php endif; ?>
                                </span>
                            </button>
                            <button type="button"
                                    class="ar-attempt-delete"
                                    data-ar-delete-attempt="<?= (int) $row['id'] ?>"
                                    title="Delete this attempt"
                                    aria-label="Delete attempt <?= (int) $row['attempt_number'] ?> for <?= portal_escape((string) $row['student_name']) ?>">
                                <?= portal_icon('trash', 'icon-sm') ?>
                            </button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </aside>

        <main class="ar-detail-panel" data-ar-detail>
            <p class="ar-empty" data-ar-detail-empty<?= $selectedDetail ? ' hidden' : '' ?>>Select an attempt to review.</p>
            <div data-ar-detail-body<?= $selectedDetail ? '' : ' hidden' ?>></div>
        </main>
    </div>

    <section class="ar-analytics">
        <div class="ar-analytics-head">
            <h2>Question analytics</h2>
            <p>How students are doing on each question, based on submitted attempts.</p>
        </div>
        <?php if ($analytics === []): ?>
            <p class="ar-empty">Analytics appear after students submit.</p>
        <?php else: ?>
            <div class="ar-analytics-table">
                <?php foreach ($analytics as $i => $row):
                    $facility = $row['facility'];
                    $facilityClass = $facility === null ? 'muted' : ($facility >= 70 ? 'good' : ($facility >= 40 ? 'warn' : 'bad'));
                ?>
                    <div class="ar-analytics-row">
                        <span class="ar-analytics-num">Q<?= $i + 1 ?></span>
                        <div class="ar-analytics-main">
                            <strong><?= portal_escape((string) $row['prompt_excerpt']) ?: 'Untitled question' ?></strong>
                            <span class="ar-analytics-type"><?= portal_escape(portal_activity_question_type_label((string) $row['question_type'])) ?></span>
                        </div>
                        <span class="ar-analytics-responses"><?= (int) $row['responses'] ?> response<?= (int) $row['responses'] === 1 ? '' : 's' ?></span>
                        <div class="ar-analytics-facility">
                            <?php if ($facility !== null): ?>
                                <span class="ar-analytics-bar"><span style="width: <?= (float) $facility ?>%" class="ar-analytics-bar-fill ar-analytics-bar-fill--<?= $facilityClass ?>"></span></span>
                                <span class="ar-analytics-pct ar-analytics-pct--<?= $facilityClass ?>"><?= portal_escape((string) $facility) ?>%</span>
                            <?php else: ?>
                                <span class="ar-analytics-pct ar-analytics-pct--muted">—</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <div class="ap-confirm ar-official-prompt" data-ar-reopen-prompt hidden role="presentation">
        <div class="ap-confirm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="ar-reopen-title" aria-describedby="ar-reopen-body">
            <div class="ap-confirm-brand" aria-label="Rangoon International Education Online">
                <img src="assets/rieo-crest.svg" alt="">
                <span><strong>RIEO</strong><small>Assessment administration</small></span>
            </div>
            <div class="ap-confirm-icon" aria-hidden="true"><?= portal_icon('history', 'icon-sm') ?></div>
            <h2 id="ar-reopen-title" class="ap-confirm-title">Reopen assessment attempt?</h2>
            <p id="ar-reopen-body" class="ap-confirm-body">
                The student will be permitted to return once and continue with their saved answers. This action is recorded in the assessment audit trail.
            </p>
            <label class="ar-prompt-field">
                <span>Administrative note <small>Optional</small></span>
                <textarea rows="3" maxlength="500" data-ar-reopen-note placeholder="For example: connection issue confirmed by teacher"></textarea>
            </label>
            <div class="ap-confirm-actions">
                <button type="button" class="button button-secondary" data-ar-reopen-cancel>Keep attempt closed</button>
                <button type="button" class="button" data-ar-reopen-confirm>Authorise reopening</button>
            </div>
        </div>
    </div>
</section>

<script type="application/json" id="ar-bootstrap"><?= portal_activity_json_encode($bootstrap) ?></script>
<script src="assets/activity-results.js?v=20260802h"></script>
<?php
$page_content = ob_get_clean();
require __DIR__ . '/../layout.php';
