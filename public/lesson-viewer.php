<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
portal_require_login();

$db = portal_db();
$me = portal_current_user();

$itemId = (int) ($_GET['item'] ?? 0);
if (!$itemId) { http_response_code(400); exit('Bad request.'); }

$itemStmt = $db->prepare(
    "SELECT cfi.*, cf.locked AS folder_locked, c.id AS course_id, c.slug AS course_slug, c.full_title AS course_title
     FROM course_folder_items cfi
     JOIN course_folders cf ON cf.id = cfi.folder_id
     JOIN courses c ON c.id = cf.course_id
     WHERE cfi.id = ? AND cfi.type = 'video' AND (cfi.file_path != '' OR cfi.url != '')"
);
$itemStmt->execute([$itemId]);
$item = $itemStmt->fetch();

if (!$item) { http_response_code(404); exit('Video not found.'); }

// An external video item has no uploaded file — it points at an allowlisted platform
// (YouTube/Vimeo) instead. Re-validate on every load (not just at creation) so a
// database row can never smuggle an untrusted embed target to the browser.
$isExternalVideo = (string) $item['file_path'] === '' && (string) $item['url'] !== '';
$videoMeta = $isExternalVideo ? portal_parse_external_video_url((string) $item['url']) : null;
if ($isExternalVideo && $videoMeta === null) {
    http_response_code(404);
    exit('Video not found.');
}

$courseId = (int) $item['course_id'];

if (!portal_can_access_course($courseId)) {
    portal_log_security_event(
        'unauthorised_course_access',
        'medium',
        'Blocked access to lesson video item: ' . $itemId
    );
    http_response_code(403);
    exit('Access denied.');
}

$canManage = portal_can_manage_course($courseId);
if (!$canManage && portal_folder_item_content_locked($item)) {
    portal_log_security_event(
        'unauthorised_course_access',
        'medium',
        'Blocked access to locked lesson video item: ' . $itemId
    );
    http_response_code(403);
    exit('This material is locked.');
}
$canAsk = !$canManage;

if (empty($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['_csrf'];

$backUrl = 'course.php?course=' . urlencode((string) $item['course_slug']) . '&section=content';
$viewerUrl = 'lesson-viewer.php?item=' . $itemId;

/**
 * Render a single Q&A card. Shared by the initial page render and the
 * ask-question AJAX response so a newly posted question looks identical
 * without requiring a full page reload (which would reset video playback).
 */
function portal_render_lesson_qa_item(array $q, bool $canManage, bool $isExternalVideo, string $csrfToken, int $meId, string $anchorAttr = ''): string
{
    $isAnswered = (string) $q['answer'] !== '';
    $isOwn      = (int) $q['user_id'] === $meId;
    $isPublic   = (int) ($q['is_public'] ?? 1) === 1;
    $isPrivate  = $isAnswered && !$isPublic;
    $isPinned   = !empty($q['pinned']) && $isPublic;
    $canDelete  = $canManage || ($isOwn && !$isAnswered);
    $videoSecs  = (int) ($q['video_seconds'] ?? 0);

    ob_start();
    ?>
        <article class="qa-item <?= $isAnswered ? 'qa-item--answered' : 'qa-item--pending' ?><?= $isOwn ? ' qa-item--mine' : '' ?><?= $isPinned ? ' qa-item--pinned' : '' ?><?= $isPrivate ? ' qa-item--private' : '' ?>"
                 id="q-<?= (int) $q['id'] ?>"<?= $anchorAttr ?>>
            <div class="qa-question-row">
                <div class="course-staff-avatar ann-avatar"><?= portal_escape((string) ($q['asker_initials'] ?: '?')) ?></div>
                <div class="qa-question-body">
                    <div class="qa-question-head">
                        <strong><?= portal_escape((string) $q['asker_name']) ?><?= $isOwn ? ' <span class="qa-you">(you)</span>' : '' ?></strong>
                        <?php if ($videoSecs >= 0): ?>
                        <button type="button"
                                class="qa-video-stamp"
                                data-seek="<?= $videoSecs ?>"
                                title="Jump to this moment in the video">
                            <?= portal_icon('play', 'icon-xs') ?>
                            <?= portal_escape(portal_format_video_timestamp($videoSecs)) ?>
                        </button>
                        <?php endif; ?>
                        <span class="sub-date" title="Asked at"><?= portal_escape(date('j M · H:i', strtotime((string) $q['created_at']))) ?></span>
                        <?php if ($isPinned): ?>
                            <span class="qa-badge qa-badge--pinned">Pinned</span>
                        <?php endif; ?>
                        <?php if ($isPrivate): ?>
                            <span class="qa-badge qa-badge--private">Private</span>
                        <?php elseif ($isAnswered): ?>
                            <span class="qa-badge qa-badge--answered">Public</span>
                        <?php else: ?>
                            <span class="qa-badge qa-badge--pending"><?= $canManage ? 'Needs answer' : 'Waiting' ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="qa-text"><?= nl2br(portal_escape((string) $q['question'])) ?></p>
                </div>
                <div class="qa-item-actions">
                    <?php if ($canManage && $isAnswered && $isPublic): ?>
                    <form method="POST" class="qa-pin-form">
                        <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                        <input type="hidden" name="action" value="toggle_pin">
                        <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
                        <button type="submit" class="btn-icon" title="<?= $isPinned ? 'Unpin' : 'Pin as key answer' ?>">
                            <?= portal_icon('pin', 'icon-sm') ?>
                        </button>
                    </form>
                    <?php endif; ?>
                    <?php if ($canDelete): ?>
                    <form method="POST" class="qa-delete-form">
                        <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                        <input type="hidden" name="action" value="delete_question">
                        <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
                        <button type="submit" class="btn-icon-danger" title="Delete question">
                            <?= portal_icon('trash', 'icon-sm') ?>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isAnswered): ?>
            <div class="qa-answer-row<?= $isPrivate ? ' qa-answer-row--private' : '' ?>">
                <div class="course-staff-avatar ann-avatar teacher-avatar"><?= portal_escape((string) ($q['answerer_initials'] ?: '?')) ?></div>
                <div class="qa-answer-body">
                    <div class="qa-question-head">
                        <strong><?= portal_escape((string) $q['answerer_name']) ?></strong>
                        <span class="qa-role-tag"><?= $isPrivate ? 'Private reply' : 'Teacher' ?></span>
                        <span class="sub-date"><?= portal_escape(date('j M · H:i', strtotime((string) $q['answered_at']))) ?></span>
                    </div>
                    <p class="qa-text"><?= nl2br(portal_escape((string) $q['answer'])) ?></p>
                    <?php if ($isPrivate && $isOwn && !$canManage): ?>
                    <p class="qa-waiting-note">Only you and your teacher can see this reply.</p>
                    <?php endif; ?>
                    <?php if ($isPrivate && $canManage): ?>
                    <form method="POST" class="qa-publish-form">
                        <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                        <input type="hidden" name="action" value="publish_answer">
                        <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
                        <button type="submit" class="button-secondary button--sm">Share with class</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php elseif ($canManage): ?>
            <details class="qa-reply-details">
                <summary class="qa-reply-toggle">Answer this question</summary>
                <form method="POST" class="qa-answer-form">
                    <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                    <input type="hidden" name="action" value="answer_question">
                    <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
                    <textarea name="answer" required maxlength="2000" rows="2" placeholder="Write your reply…"></textarea>
                    <div class="qa-answer-actions">
                        <button type="submit" name="visibility" value="private" class="button-secondary button--sm">
                            Reply privately
                        </button>
                        <button type="submit" name="visibility" value="public" class="button button--sm">
                            Publish to class
                        </button>
                    </div>
                    <p class="qa-answer-hint">Private replies are only visible to you and this student. You can share them with the class later.</p>
                </form>
            </details>
            <?php elseif ($isOwn): ?>
            <p class="qa-waiting-note">Waiting for your teacher — once answered, this becomes visible to the class unless they reply privately.</p>
            <?php endif; ?>
        </article>
    <?php
    return (string) ob_get_clean();
}

// ── AJAX: ask a question without a full page reload ───────────────────────────
// A normal form POST would redirect back to this page, which resets native
// <video> playback to 0:00. Submitting via fetch keeps the video playing and
// just splices the new Q&A card into the existing list.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string) ($_POST['action'] ?? '') === 'ask_question'
    && portal_is_fetch_request()
) {
    if (!portal_verify_csrf()) {
        portal_json_response(['ok' => false, 'error' => 'Your session expired. Please refresh the page and try again.'], 403);
    }
    if (!$canAsk) {
        portal_json_response(['ok' => false, 'error' => 'Only students can ask questions on lesson videos.'], 403);
    }

    $question = substr(trim((string) ($_POST['question'] ?? '')), 0, 1000);
    $videoSeconds = (int) ($_POST['video_seconds'] ?? 0);
    // -1 means the student opted out of attaching a timestamp
    if ($videoSeconds < -1) {
        $videoSeconds = -1;
    }
    if ($question === '') {
        portal_json_response(['ok' => false, 'error' => 'Please type a question before sending.'], 422);
    }

    $db->prepare(
        "INSERT INTO course_video_questions (item_id, course_id, user_id, question, video_seconds) VALUES (?,?,?,?,?)"
    )->execute([$itemId, $courseId, (int) $me['id'], $question, $videoSeconds]);
    $newQuestionId = (int) $db->lastInsertId();

    $newQStmt = $db->prepare(
        "SELECT q.*, u.name AS asker_name, u.initials AS asker_initials,
                a.name AS answerer_name, a.initials AS answerer_initials
         FROM course_video_questions q
         JOIN users u ON u.id = q.user_id
         LEFT JOIN users a ON a.id = q.answered_by
         WHERE q.id = ?"
    );
    $newQStmt->execute([$newQuestionId]);
    $newQuestionRow = $newQStmt->fetch();

    portal_json_response([
        'ok' => true,
        'html' => $newQuestionRow
            ? portal_render_lesson_qa_item($newQuestionRow, $canManage, $isExternalVideo, $csrfToken, (int) $me['id'])
            : '',
    ]);
}

// ── AJAX: save watch progress ─────────────────────────────────────────────────
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string) ($_POST['action'] ?? '') === 'save_progress'
    && portal_is_fetch_request()
) {
    if (!portal_verify_csrf()) {
        portal_json_response(['ok' => false], 403);
    }
    $pos = max(0, (int) ($_POST['position_seconds'] ?? 0));
    $db->prepare(
        "INSERT INTO course_video_progress (item_id, user_id, position_seconds, updated_at)
         VALUES (?,?,?,datetime('now'))
         ON CONFLICT(item_id, user_id) DO UPDATE SET
            position_seconds = excluded.position_seconds,
            updated_at = datetime('now')"
    )->execute([$itemId, (int) $me['id'], $pos]);
    portal_json_response(['ok' => true, 'position' => $pos]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!portal_verify_csrf()) {
        portal_redirect($viewerUrl);
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'ask_question') {
        if (!$canAsk) {
            $_SESSION['lesson_flash'] = ['error', 'Only students can ask questions on lesson videos.'];
        } else {
            $question = substr(trim((string) ($_POST['question'] ?? '')), 0, 1000);
            $videoSeconds = (int) ($_POST['video_seconds'] ?? 0);
            if ($videoSeconds < -1) {
                $videoSeconds = -1;
            }
            if ($question !== '') {
                $db->prepare(
                    "INSERT INTO course_video_questions (item_id, course_id, user_id, question, video_seconds) VALUES (?,?,?,?,?)"
                )->execute([$itemId, $courseId, (int) $me['id'], $question, $videoSeconds]);
                $_SESSION['lesson_flash'] = ['success', 'Your question has been sent to your teacher.'];
            } else {
                $_SESSION['lesson_flash'] = ['error', 'Please type a question before sending.'];
            }
        }
    } elseif ($action === 'answer_question' && $canManage) {
        $questionId = (int) ($_POST['question_id'] ?? 0);
        $answer     = substr(trim((string) ($_POST['answer'] ?? '')), 0, 2000);
        $visibility = (string) ($_POST['visibility'] ?? 'public');
        $isPublic   = $visibility === 'private' ? 0 : 1;
        $qChk = $db->prepare("SELECT id, user_id, question FROM course_video_questions WHERE id = ? AND item_id = ? AND course_id = ?");
        $qChk->execute([$questionId, $itemId, $courseId]);
        $qRow = $qChk->fetch();
        if ($qRow && $answer !== '') {
            $db->prepare(
                "UPDATE course_video_questions
                 SET answer = ?, answered_by = ?, answered_at = datetime('now'), is_public = ?, pinned = CASE WHEN ? = 0 THEN 0 ELSE pinned END
                 WHERE id = ? AND item_id = ? AND course_id = ?"
            )->execute([$answer, (int) $me['id'], $isPublic, $isPublic, $questionId, $itemId, $courseId]);

            $askerId = (int) $qRow['user_id'];
            if ($askerId > 0 && $askerId !== (int) $me['id']) {
                $snippet = substr((string) $qRow['question'], 0, 120);
                $notifTitle = $isPublic
                    ? 'Your question on “' . substr((string) $item['title'], 0, 80) . '” was answered'
                    : 'Your teacher replied privately on “' . substr((string) $item['title'], 0, 80) . '”';
                portal_notify_user(
                    $askerId,
                    'lesson_answer',
                    $notifTitle,
                    $snippet,
                    $viewerUrl . '#q-' . $questionId,
                    $courseId
                );
            }

            $_SESSION['lesson_flash'] = [
                'success',
                $isPublic
                    ? 'Answer published for the whole class. The student was notified.'
                    : 'Private reply sent — only you and that student can see it.',
            ];
        } else {
            $_SESSION['lesson_flash'] = ['error', 'Please write an answer before sending it.'];
        }
    } elseif ($action === 'publish_answer' && $canManage) {
        $questionId = (int) ($_POST['question_id'] ?? 0);
        $qChk = $db->prepare(
            "SELECT id, user_id, question, answer FROM course_video_questions
             WHERE id = ? AND item_id = ? AND course_id = ? AND answer != '' AND is_public = 0"
        );
        $qChk->execute([$questionId, $itemId, $courseId]);
        $qRow = $qChk->fetch();
        if ($qRow) {
            $db->prepare(
                "UPDATE course_video_questions SET is_public = 1 WHERE id = ? AND item_id = ?"
            )->execute([$questionId, $itemId]);
            $askerId = (int) $qRow['user_id'];
            if ($askerId > 0) {
                portal_notify_user(
                    $askerId,
                    'lesson_answer',
                    'A private reply on “' . substr((string) $item['title'], 0, 80) . '” was shared with the class',
                    substr((string) $qRow['question'], 0, 120),
                    $viewerUrl . '#q-' . $questionId,
                    $courseId
                );
            }
            $_SESSION['lesson_flash'] = ['success', 'That answer is now visible to the whole class.'];
        }
    } elseif ($action === 'delete_question') {
        $questionId = (int) ($_POST['question_id'] ?? 0);
        $qStmt = $db->prepare("SELECT user_id, answer FROM course_video_questions WHERE id = ? AND item_id = ? AND course_id = ?");
        $qStmt->execute([$questionId, $itemId, $courseId]);
        $qRow = $qStmt->fetch();
        if ($qRow) {
            $isOwner = (int) $qRow['user_id'] === (int) $me['id'];
            if ($canManage || ($isOwner && (string) $qRow['answer'] === '')) {
                $db->prepare("DELETE FROM course_video_questions WHERE id = ? AND item_id = ? AND course_id = ?")
                   ->execute([$questionId, $itemId, $courseId]);
                $_SESSION['lesson_flash'] = ['success', 'Question deleted.'];
            }
        }
    } elseif ($action === 'toggle_pin' && $canManage) {
        $questionId = (int) ($_POST['question_id'] ?? 0);
        $qChk = $db->prepare("SELECT id, pinned, answer, is_public FROM course_video_questions WHERE id = ? AND item_id = ? AND course_id = ?");
        $qChk->execute([$questionId, $itemId, $courseId]);
        $qRow = $qChk->fetch();
        if ($qRow && (string) $qRow['answer'] !== '' && (int) ($qRow['is_public'] ?? 1) === 1) {
            $newPin = ((int) $qRow['pinned'] === 1) ? 0 : 1;
            if ($newPin === 1) {
                $db->prepare("UPDATE course_video_questions SET pinned = 0 WHERE item_id = ?")->execute([$itemId]);
            }
            $db->prepare("UPDATE course_video_questions SET pinned = ? WHERE id = ? AND item_id = ?")
               ->execute([$newPin, $questionId, $itemId]);
            $_SESSION['lesson_flash'] = ['success', $newPin ? 'Answer pinned for the class.' : 'Pin removed.'];
        }
    } elseif ($action === 'save_lesson_notes' && $canManage) {
        $notes = substr(trim((string) ($_POST['lesson_notes'] ?? '')), 0, 4000);
        $db->prepare("UPDATE course_folder_items SET lesson_notes = ? WHERE id = ? AND course_id = ? AND type = 'video'")
           ->execute([$notes, $itemId, $courseId]);
        $_SESSION['lesson_flash'] = ['success', 'Lesson notes saved.'];
    }

    portal_redirect($viewerUrl . '#qa');
}

$lessonFlash = $_SESSION['lesson_flash'] ?? null;
unset($_SESSION['lesson_flash']);

$allowDownload = (bool) ($item['allow_download'] ?? 0);
$canDownload   = !$isExternalVideo && ($canManage || $allowDownload);
$displayName   = $item['file_name'] !== '' ? $item['file_name'] : basename((string) $item['file_path']);
$ext           = $isExternalVideo ? '' : strtolower(pathinfo($displayName, PATHINFO_EXTENSION));
$mime          = $isExternalVideo ? '' : portal_video_mime_for_extension($ext);
$sourceLabel   = $isExternalVideo ? (string) ($videoMeta['label'] ?? 'Video') : strtoupper($ext);
$embedProvider = $isExternalVideo ? (string) ($videoMeta['provider'] ?? '') : '';
$description   = trim((string) ($item['description'] ?? ''));
$lessonNotes   = trim((string) ($item['lesson_notes'] ?? ''));
$videoUrl      = 'download.php?item=' . $itemId . '&view=1';
$downloadUrl   = 'download.php?item=' . $itemId;

// Rebuild the embed src here (not in the parser) so we can attach enablejsapi /
// origin for YouTube postMessage time sync — no YouTube Data API key required.
$embedSrc = '';
if ($isExternalVideo && is_array($videoMeta)) {
    $embedSrc = (string) $videoMeta['embed_url'];
    if ($embedProvider === 'youtube') {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
        $origin = ($https ? 'https' : 'http') . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $embedSrc .= (str_contains($embedSrc, '?') ? '&' : '?')
            . 'enablejsapi=1&rel=0&modestbranding=1&iv_load_policy=3&origin=' . rawurlencode($origin);
    } elseif ($embedProvider === 'vimeo') {
        $embedSrc .= (str_contains($embedSrc, '?') ? '&' : '?') . 'api=1';
    }
}

$savedPosition = 0;
$progStmt = $db->prepare("SELECT position_seconds FROM course_video_progress WHERE item_id = ? AND user_id = ?");
$progStmt->execute([$itemId, (int) $me['id']]);
$savedPosition = (int) ($progStmt->fetchColumn() ?: 0);

// Mark related "lesson answer" notifications as read when opening this lesson
$db->prepare(
    "UPDATE portal_notifications
     SET read_at = datetime('now')
     WHERE user_id = ? AND read_at = '' AND type = 'lesson_answer'
       AND link LIKE ?"
)->execute([(int) $me['id'], 'lesson-viewer.php?item=' . $itemId . '%']);

$qStmt = $db->prepare(
    "SELECT q.*, u.name AS asker_name, u.initials AS asker_initials,
            a.name AS answerer_name, a.initials AS answerer_initials
     FROM course_video_questions q
     JOIN users u ON u.id = q.user_id
     LEFT JOIN users a ON a.id = q.answered_by
     WHERE q.item_id = ?
     ORDER BY
        q.pinned DESC,
        CASE WHEN q.answer = '' THEN 0 ELSE 1 END,
        q.created_at DESC"
);
$qStmt->execute([$itemId]);
$allQuestions = $qStmt->fetchAll();

$questions = array_values(array_filter($allQuestions, function ($q) use ($canManage, $me) {
    $isOwn = (int) $q['user_id'] === (int) $me['id'];
    $hasAnswer = (string) $q['answer'] !== '';
    $isPublic = (int) ($q['is_public'] ?? 1) === 1;
    // Staff see everything; asker sees their own; class only sees public answered Q&As
    return $canManage || $isOwn || ($hasAnswer && $isPublic);
}));

$pendingCount = count(array_filter($questions, static fn ($q) => (string) $q['answer'] === ''));
$answeredCount = count($questions) - $pendingCount;

$page_title = $item['title'] . ' - Lesson video | ' . portal_school_name();
$active_page = 'courses';
$page_eyebrow = 'Lesson video · ' . $item['course_title'];
$page_heading = $item['title'];
$page_description = $description !== '' ? $description : 'Watch the lesson, then ask your teacher anything you are unsure about.';

ob_start();
?>
<section class="lesson-viewer" id="lesson-viewer-root"
         data-item-id="<?= $itemId ?>"
         data-csrf="<?= portal_escape($csrfToken) ?>"
         data-resume="<?= $savedPosition ?>"
         data-embed-provider="<?= portal_escape($embedProvider) ?>">
    <div class="lesson-toolbar">
        <a class="lesson-back" href="<?= portal_escape($backUrl) ?>">
            <span aria-hidden="true">←</span> Course content
        </a>
        <div class="lesson-toolbar-actions">
            <span class="lesson-pill"><?= portal_escape($sourceLabel) ?></span>
            <?php if ($canDownload): ?>
            <a class="lesson-toolbar-btn" href="<?= portal_escape($downloadUrl) ?>">
                <?= portal_icon('download', 'icon-xs') ?>
                Download
            </a>
            <?php endif; ?>
            <button type="button" class="lesson-toolbar-btn" id="lesson-theater-btn" title="Theater mode">
                <?= portal_icon('presentation', 'icon-xs') ?>
                <span data-theater-label>Theater</span>
            </button>
            <?php if ($canAsk): ?>
            <button type="button" class="lesson-toolbar-btn lesson-toolbar-btn--accent" id="lesson-ask-jump">
                <?= portal_icon('megaphone', 'icon-xs') ?>
                Ask a question
            </button>
            <?php elseif ($pendingCount > 0): ?>
            <a class="lesson-toolbar-btn lesson-toolbar-btn--accent" href="#qa-open">
                <?= portal_icon('megaphone', 'icon-xs') ?>
                Review <?= (int) $pendingCount ?> open
            </a>
            <?php else: ?>
            <a class="lesson-toolbar-btn" href="#qa">
                <?= portal_icon('megaphone', 'icon-xs') ?>
                Class questions
            </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="lesson-layout" id="lesson-layout">
        <div class="lesson-main">
            <article class="lesson-stage">
                <div class="lesson-player" id="lesson-player">
                    <?php if ($isExternalVideo): ?>
                        <div class="lesson-embed-wrap">
                            <iframe
                                id="lesson-embed-frame"
                                class="lesson-embed-frame"
                                src="<?= portal_escape($embedSrc) ?>"
                                title="<?= portal_escape($item['title']) ?>"
                                loading="lazy"
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                referrerpolicy="strict-origin-when-cross-origin"
                                allowfullscreen></iframe>
                        </div>
                    <?php else: ?>
                    <video id="lesson-video" controls preload="metadata" playsinline controlsList="<?= $canDownload ? '' : 'nodownload' ?>">
                        <source src="<?= portal_escape($videoUrl) ?>" type="<?= portal_escape($mime) ?>">
                    </video>
                    <div class="lesson-player-error">
                        <?= portal_icon('alert', 'lesson-player-error-icon') ?>
                        <span>This video could not be played. Your browser may not support the .<?= portal_escape($ext) ?> format.</span>
                    </div>
                    <button type="button" class="lesson-play-overlay" id="lesson-play-overlay" aria-label="Play lesson">
                        <span class="lesson-play-ring">
                            <?= portal_icon('play', 'lesson-play-icon') ?>
                        </span>
                        <span class="lesson-play-label">Play lesson</span>
                    </button>
                    <?php endif; ?>
                </div>

                <?php if ($savedPosition >= 5): ?>
                <div class="lesson-resume" id="lesson-resume">
                    <span>Continue from <strong><?= portal_escape(portal_format_video_timestamp($savedPosition)) ?></strong>?</span>
                    <div class="lesson-resume-actions">
                        <button type="button" class="button button--sm" id="lesson-resume-yes">Resume</button>
                        <button type="button" class="button-secondary button--sm" id="lesson-resume-no">Start over</button>
                    </div>
                </div>
                <?php endif; ?>

                <div class="lesson-controls-row">
                    <div class="lesson-watch-bar" id="lesson-watch-bar" hidden>
                        <div class="lesson-watch-track">
                            <div class="lesson-watch-fill" id="lesson-watch-fill"></div>
                        </div>
                        <span class="lesson-watch-label" id="lesson-watch-label">0%</span>
                    </div>
                    <label class="lesson-speed">
                        <span>Speed</span>
                        <select id="lesson-speed-select" aria-label="Playback speed">
                            <option value="0.75">0.75×</option>
                            <option value="1" selected>1×</option>
                            <option value="1.25">1.25×</option>
                            <option value="1.5">1.5×</option>
                        </select>
                    </label>
                </div>

                <p class="lesson-keys-hint">Shortcuts: <kbd>Space</kbd> play/pause · <kbd>←</kbd><kbd>→</kbd> skip 10s · <kbd>F</kbd> fullscreen · <kbd>T</kbd> theater<?= $isExternalVideo ? ' <span class="lesson-keys-hint-note">(click outside the player if a shortcut is ignored)</span>' : '' ?></p>
            </article>

            <article class="lesson-notes-card" id="lesson-notes">
                <header class="lesson-notes-head">
                    <div>
                        <p class="eyebrow">Lesson notes</p>
                        <h3>Outline &amp; key points</h3>
                    </div>
                    <?php if ($canManage): ?>
                    <button type="button" class="lesson-toolbar-btn" id="lesson-notes-edit-btn"><?= $lessonNotes !== '' ? 'Edit notes' : 'Add notes' ?></button>
                    <?php endif; ?>
                </header>

                <?php if ($canManage): ?>
                <form method="POST" class="lesson-notes-form" id="lesson-notes-form" hidden>
                    <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                    <input type="hidden" name="action" value="save_lesson_notes">
                    <label class="folder-form-label">
                        <span>Visible to students under this video</span>
                        <textarea name="lesson_notes" rows="5" maxlength="4000" placeholder="Key points, formulas, timestamps to revisit…"><?= portal_escape($lessonNotes) ?></textarea>
                    </label>
                    <div class="button-row">
                        <button type="submit" class="button button--sm">Save notes</button>
                        <button type="button" class="button-secondary button--sm" id="lesson-notes-cancel">Cancel</button>
                    </div>
                </form>
                <?php endif; ?>

                <div class="lesson-notes-body" id="lesson-notes-body" <?= ($canManage && $lessonNotes === '') ? 'hidden' : '' ?>>
                    <?php if ($lessonNotes !== ''): ?>
                        <p><?= nl2br(portal_escape($lessonNotes)) ?></p>
                    <?php else: ?>
                        <p class="lesson-notes-empty"><?= $canManage ? 'No notes yet — add an outline so students can follow along.' : 'No teacher notes for this lesson yet.' ?></p>
                    <?php endif; ?>
                </div>
            </article>
        </div>

        <aside class="lesson-side" id="qa">
            <article class="lesson-qa-panel">
                <header class="lesson-qa-head">
                    <div>
                        <p class="eyebrow">Class Q&amp;A</p>
                        <h3>Questions</h3>
                    </div>
                    <div class="lesson-qa-stats" id="lesson-qa-stats">
                        <span class="lesson-stat" id="lesson-qa-answered-stat" title="Answered"><?= (int) $answeredCount ?> answered</span>
                        <span class="lesson-stat lesson-stat--hot" id="lesson-qa-open-stat" style="<?= $pendingCount > 0 ? '' : 'display:none;' ?>"><?= (int) $pendingCount ?> open</span>
                    </div>
                </header>

                <?php if (is_array($lessonFlash) && isset($lessonFlash[0], $lessonFlash[1])): ?>
                <div class="admin-flash admin-flash--compact <?= $lessonFlash[0] === 'success' ? 'success' : 'error' ?>">
                    <?= portal_escape((string) $lessonFlash[1]) ?>
                </div>
                <?php endif; ?>

                <?php if ($canAsk): ?>
                <form method="POST" class="lesson-composer" id="lesson-composer">
                    <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                    <input type="hidden" name="action" value="ask_question">
                    <input type="hidden" name="video_seconds" id="lesson-video-seconds" value="0">
                    <div class="lesson-composer-row">
                        <div class="course-staff-avatar ann-avatar lesson-composer-avatar"><?= portal_escape((string) ($me['initials'] ?? '?')) ?></div>
                        <textarea name="question" id="lesson-question-input" required maxlength="1000" rows="2"
                                  placeholder="Ask about this moment in the lesson…"></textarea>
                    </div>
                    <div class="lesson-composer-foot">
                        <button type="button"
                                class="lesson-stamp-toggle is-on"
                                id="lesson-stamp-hint"
                                aria-pressed="true"
                                title="Turn timestamp attachment on or off">
                            <span class="lesson-stamp-toggle-switch" aria-hidden="true">
                                <span class="lesson-stamp-toggle-knob"></span>
                            </span>
                            <span class="lesson-stamp-toggle-copy">
                                <span class="lesson-stamp-toggle-label" id="lesson-stamp-label">Include timestamp</span>
                                <span class="lesson-stamp-toggle-time" id="lesson-stamp-time">0:00</span>
                            </span>
                        </button>
                        <span class="lesson-char-count" id="lesson-char-count">0 / 1000</span>
                        <button type="submit" class="button button--sm lesson-send-btn" id="lesson-send-btn" disabled>
                            Send question
                        </button>
                    </div>
                </form>
                <?php else: ?>
                <p class="lesson-staff-note">Students ask questions here. Reply privately (student only) or publish to the class. Click a time stamp to jump to that moment.</p>
                <?php endif; ?>

                <div class="lesson-qa-empty" id="lesson-qa-empty" style="<?= empty($questions) ? '' : 'display:none;' ?>">
                    <div class="lesson-qa-empty-icon"><?= portal_icon('megaphone', 'icon-sm') ?></div>
                    <?php if ($canAsk): ?>
                        <p>No questions yet — start the conversation.</p>
                    <?php else: ?>
                        <p class="lesson-qa-empty-title">Waiting for student questions</p>
                        <p>Share this lesson with your class. When students ask, open questions land here for you to answer publicly.</p>
                    <?php endif; ?>
                </div>
                <div class="lesson-qa-list" id="lesson-qa-list" style="<?= empty($questions) ? 'display:none;' : '' ?>">
                    <?php
                    $markedOpenAnchor = false;
                    foreach ($questions as $q):
                        $openAnchor = '';
                        if ((string) $q['answer'] === '' && !$markedOpenAnchor) {
                            $openAnchor = ' id="qa-open"';
                            $markedOpenAnchor = true;
                        }
                        echo portal_render_lesson_qa_item($q, $canManage, $isExternalVideo, $csrfToken, (int) $me['id'], $openAnchor);
                    endforeach;
                    ?>
                </div>
            </article>
        </aside>
    </div>

    <?php if ($canAsk): ?>
    <div class="lesson-mobile-ask" id="lesson-mobile-ask">
        <button type="button" class="button" id="lesson-mobile-ask-btn">
            <?= portal_icon('megaphone', 'icon-sm') ?>
            Ask about this lesson
        </button>
    </div>
    <?php endif; ?>
</section>

<script>
(function () {
    'use strict';
    var root = document.getElementById('lesson-viewer-root');
    var player = document.getElementById('lesson-player');
    var video  = document.getElementById('lesson-video');
    var embedFrame = document.getElementById('lesson-embed-frame');
    var embedProvider = root ? (root.dataset.embedProvider || '') : '';
    var overlay = document.getElementById('lesson-play-overlay');
    var watchBar = document.getElementById('lesson-watch-bar');
    var watchFill = document.getElementById('lesson-watch-fill');
    var watchLabel = document.getElementById('lesson-watch-label');
    var input = document.getElementById('lesson-question-input');
    var sendBtn = document.getElementById('lesson-send-btn');
    var charCount = document.getElementById('lesson-char-count');
    var secondsInput = document.getElementById('lesson-video-seconds');
    var stampHint = document.getElementById('lesson-stamp-hint');
    var stampLabel = document.getElementById('lesson-stamp-label');
    var stampTime = document.getElementById('lesson-stamp-time');
    var askJump = document.getElementById('lesson-ask-jump');
    var mobileAsk = document.getElementById('lesson-mobile-ask-btn');
    var composer = document.getElementById('lesson-composer');
    var speedSelect = document.getElementById('lesson-speed-select');
    var theaterBtn = document.getElementById('lesson-theater-btn');
    var resumeBox = document.getElementById('lesson-resume');
    var csrf = root ? root.dataset.csrf : '';
    var resumeAt = root ? parseInt(root.dataset.resume || '0', 10) : 0;
    var lastSaved = -1;
    var stampLocked = false;
    var lockedSeconds = 0;
    var stampAttachEnabled = true;
    var embedCurrentTime = 0;
    var embedDuration = 0;
    var embedPlaying = false;
    var embedReady = false;
    var pendingSeek = null;
    var pendingSeekPlay = true;
    var embedPollTimer = null;
    var YT_ORIGINS = {
        'https://www.youtube-nocookie.com': true,
        'https://www.youtube.com': true
    };
    var VIMEO_ORIGIN = 'https://player.vimeo.com';

    function formatStamp(total) {
        total = Math.max(0, Math.floor(total || 0));
        var h = Math.floor(total / 3600);
        var m = Math.floor((total % 3600) / 60);
        var s = total % 60;
        if (h > 0) return h + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        return m + ':' + String(s).padStart(2, '0');
    }

    function getPlaybackSeconds() {
        if (video) return Math.floor(video.currentTime || 0);
        return Math.floor(embedCurrentTime || 0);
    }

    function getPlaybackDuration() {
        if (video) return video.duration || 0;
        return embedDuration || 0;
    }

    function updateWatchBar() {
        if (!watchBar || !watchFill || !watchLabel) return;
        var duration = getPlaybackDuration();
        var pos = getPlaybackSeconds();
        if (!duration || duration <= 0) return;
        watchBar.hidden = false;
        var pct = Math.min(100, Math.round((pos / duration) * 100));
        watchFill.style.width = pct + '%';
        watchLabel.textContent = pct + '% watched';
    }

    function currentStampSeconds() {
        if (stampLocked) return lockedSeconds;
        return getPlaybackSeconds();
    }

    function syncStamp() {
        if (!secondsInput) return;
        if (!stampAttachEnabled) {
            secondsInput.value = '-1';
            if (stampHint) {
                stampHint.classList.remove('is-on');
                stampHint.classList.add('is-off');
                stampHint.setAttribute('aria-pressed', 'false');
                stampHint.title = 'Click to include the current video time with your question';
            }
            if (stampLabel) stampLabel.textContent = 'No timestamp';
            if (stampTime) {
                stampTime.textContent = 'Off';
                stampTime.hidden = false;
            }
            if (input) input.placeholder = 'Ask a question about this lesson…';
            return;
        }
        var secs = currentStampSeconds();
        secondsInput.value = String(secs);
        if (stampHint) {
            stampHint.classList.add('is-on');
            stampHint.classList.remove('is-off');
            stampHint.setAttribute('aria-pressed', 'true');
            stampHint.title = 'Click to send your question without a timestamp';
        }
        if (stampLabel) {
            stampLabel.textContent = stampLocked ? 'Timestamp locked' : 'Include timestamp';
        }
        if (stampTime) {
            stampTime.textContent = formatStamp(secs);
            stampTime.hidden = false;
        }
        if (input) input.placeholder = 'Ask about this moment in the lesson…';
    }

    function lockStampFromVideo() {
        if (!stampAttachEnabled) {
            syncStamp();
            return;
        }
        stampLocked = true;
        lockedSeconds = getPlaybackSeconds();
        syncStamp();
    }

    if (stampHint) {
        stampHint.addEventListener('click', function () {
            stampAttachEnabled = !stampAttachEnabled;
            if (!stampAttachEnabled) {
                stampLocked = false;
            } else if (input && document.activeElement === input) {
                // Re-lock to the current moment if they turn it back on while typing
                stampLocked = true;
                lockedSeconds = getPlaybackSeconds();
            }
            syncStamp();
        });
    }

    function hideOverlay() {
        if (overlay) overlay.classList.add('is-hidden');
        if (player) player.classList.add('is-playing');
    }

    function ytPost(func, args) {
        if (!embedFrame || !embedFrame.contentWindow) return;
        embedFrame.contentWindow.postMessage(JSON.stringify({
            event: 'command',
            func: func,
            args: args || []
        }), 'https://www.youtube-nocookie.com');
    }

    function vimeoPost(method, value) {
        if (!embedFrame || !embedFrame.contentWindow) return;
        var payload = { method: method };
        if (typeof value !== 'undefined') payload.value = value;
        embedFrame.contentWindow.postMessage(JSON.stringify(payload), VIMEO_ORIGIN);
    }

    function seekTo(seconds, playAfter) {
        if (typeof playAfter === 'undefined') playAfter = true;
        var secs = Math.max(0, Number(seconds) || 0);
        var duration = getPlaybackDuration();
        if (duration > 0) secs = Math.min(secs, duration);
        embedCurrentTime = secs;
        updateWatchBar();

        if (video) {
            video.currentTime = secs;
            if (playAfter) {
                hideOverlay();
                video.play().catch(function () {});
            }
            player?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        if (!embedProvider) return;

        // Queue until the embed handshake finishes (common on first Resume click).
        if (!embedReady) {
            pendingSeek = secs;
            pendingSeekPlay = playAfter;
            return;
        }

        if (embedProvider === 'youtube') {
            ytPost('seekTo', [secs, true]);
            if (playAfter) {
                ytPost('playVideo');
                embedPlaying = true;
            } else {
                ytPost('pauseVideo');
                embedPlaying = false;
            }
        } else if (embedProvider === 'vimeo') {
            vimeoPost('setCurrentTime', secs);
            if (playAfter) {
                vimeoPost('play');
                embedPlaying = true;
            } else {
                vimeoPost('pause');
                embedPlaying = false;
            }
        }
        player?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function pausePlayback() {
        if (video && !video.paused) {
            video.pause();
            return;
        }
        if (embedProvider === 'youtube') {
            ytPost('pauseVideo');
            embedPlaying = false;
        } else if (embedProvider === 'vimeo') {
            vimeoPost('pause');
            embedPlaying = false;
        }
    }

    function playPlayback() {
        if (video) {
            video.play().catch(function () {});
            return;
        }
        if (embedProvider === 'youtube') {
            ytPost('playVideo');
            embedPlaying = true;
        } else if (embedProvider === 'vimeo') {
            vimeoPost('play');
            embedPlaying = true;
        }
    }

    function togglePlayPause() {
        if (video) {
            if (video.paused) video.play().catch(function () {});
            else video.pause();
            return;
        }
        if (embedPlaying) pausePlayback();
        else playPlayback();
    }

    function skipBy(delta) {
        var next = Math.max(0, getPlaybackSeconds() + delta);
        var duration = getPlaybackDuration();
        if (duration > 0) next = Math.min(next, duration);
        if (video) {
            video.currentTime = next;
            return;
        }
        seekTo(next);
    }

    function setPlaybackSpeed(rate) {
        rate = parseFloat(rate) || 1;
        if (video) {
            video.playbackRate = rate;
            return;
        }
        if (embedProvider === 'youtube') ytPost('setPlaybackRate', [rate]);
        else if (embedProvider === 'vimeo') vimeoPost('setPlaybackRate', rate);
    }

    function onEmbedTime(seconds) {
        if (typeof seconds !== 'number' || !isFinite(seconds)) return;
        embedCurrentTime = Math.max(0, seconds);
        if (!stampLocked) syncStamp();
        updateWatchBar();
        if (Math.floor(embedCurrentTime) % 5 === 0) saveProgress(false);
    }

    function onEmbedDuration(seconds) {
        if (typeof seconds !== 'number' || !isFinite(seconds) || seconds <= 0) return;
        embedDuration = seconds;
        updateWatchBar();
    }

    function onEmbedReady() {
        if (embedReady) return;
        embedReady = true;
        var savedSpeed = localStorage.getItem('lessonPlaybackSpeed') || '1';
        if (speedSelect) speedSelect.value = savedSpeed;
        setPlaybackSpeed(savedSpeed);
        if (embedProvider === 'youtube') {
            ytPost('addEventListener', ['onStateChange']);
            ytPost('getDuration');
            ytPost('getCurrentTime');
        } else if (embedProvider === 'vimeo') {
            vimeoPost('addEventListener', 'timeupdate');
            vimeoPost('addEventListener', 'play');
            vimeoPost('addEventListener', 'pause');
            vimeoPost('addEventListener', 'ended');
            vimeoPost('getDuration');
            vimeoPost('getCurrentTime');
        }
        updateWatchBar();
        if (pendingSeek !== null) {
            var queued = pendingSeek;
            var queuedPlay = pendingSeekPlay;
            pendingSeek = null;
            seekTo(queued, queuedPlay);
        }
    }

    // YouTube/Vimeo parity with uploaded lessons — no Data API key; postMessage only.
    function initEmbedBridge() {
        if (!embedFrame || !embedProvider) return;

        window.addEventListener('message', function (e) {
            if (embedProvider === 'youtube') {
                if (!YT_ORIGINS[e.origin]) return;
                var data = e.data;
                if (typeof data === 'string') {
                    try { data = JSON.parse(data); } catch (err) { return; }
                }
                if (!data || typeof data !== 'object') return;
                if (data.event === 'onReady') {
                    onEmbedReady();
                }
                if (data.event === 'onStateChange') {
                    var state = typeof data.info === 'number' ? data.info : null;
                    // 1 playing, 2 paused, 0 ended, 3 buffering
                    if (state === 1) embedPlaying = true;
                    else if (state === 2 || state === 0) {
                        embedPlaying = false;
                        saveProgress(true);
                    }
                }
                if (data.event === 'infoDelivery' && data.info && typeof data.info === 'object') {
                    if (typeof data.info.currentTime === 'number') onEmbedTime(data.info.currentTime);
                    if (typeof data.info.duration === 'number') onEmbedDuration(data.info.duration);
                    if (typeof data.info.playerState === 'number') {
                        embedPlaying = data.info.playerState === 1;
                    }
                }
            } else if (embedProvider === 'vimeo') {
                if (e.origin !== VIMEO_ORIGIN) return;
                var vdata = e.data;
                if (typeof vdata === 'string') {
                    try { vdata = JSON.parse(vdata); } catch (err) { return; }
                }
                if (!vdata || typeof vdata !== 'object') return;
                if (vdata.event === 'ready') onEmbedReady();
                if (vdata.event === 'play') embedPlaying = true;
                if (vdata.event === 'pause' || vdata.event === 'ended') {
                    embedPlaying = false;
                    saveProgress(true);
                }
                if (vdata.event === 'timeupdate' && vdata.data) {
                    if (typeof vdata.data.seconds === 'number') onEmbedTime(vdata.data.seconds);
                    if (typeof vdata.data.duration === 'number') onEmbedDuration(vdata.data.duration);
                }
                if (vdata.method === 'getCurrentTime' && typeof vdata.value === 'number') onEmbedTime(vdata.value);
                if (vdata.method === 'getDuration' && typeof vdata.value === 'number') onEmbedDuration(vdata.value);
            }
        });

        function handshake() {
            if (!embedFrame.contentWindow) return;
            if (embedProvider === 'youtube') {
                embedFrame.contentWindow.postMessage(JSON.stringify({ event: 'listening', id: 1 }), '*');
            } else if (embedProvider === 'vimeo') {
                vimeoPost('addEventListener', 'ready');
                vimeoPost('getCurrentTime');
                vimeoPost('getDuration');
            }
        }

        embedFrame.addEventListener('load', handshake);
        handshake();

        if (embedProvider === 'youtube') {
            embedPollTimer = window.setInterval(function () {
                if (!embedFrame.contentWindow) return;
                ytPost('getCurrentTime');
                if (!embedDuration) ytPost('getDuration');
            }, 500);
        }
        syncStamp();
    }

    initEmbedBridge();

    function saveProgress(force) {
        if (!csrf) return;
        if (!video && !embedProvider) return;
        var pos = getPlaybackSeconds();
        if (!force && Math.abs(pos - lastSaved) < 3) return;
        lastSaved = pos;
        var body = new FormData();
        body.append('_token', csrf);
        body.append('action', 'save_progress');
        body.append('position_seconds', String(pos));
        fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            headers: { 'X-Requested-With': 'fetch' },
            body: body,
            credentials: 'same-origin'
        }).catch(function () {});
    }

    function focusComposer() {
        if (!composer || !input) return;
        pausePlayback();
        lockStampFromVideo();
        composer.scrollIntoView({ behavior: 'smooth', block: 'center' });
        composer.classList.add('is-focused', 'is-pulse');
        input.focus();
        window.setTimeout(function () { composer.classList.remove('is-pulse'); }, 900);
    }

    if (video) {
        video.addEventListener('error', function () {
            if (player) player.classList.add('is-errored');
            if (overlay) overlay.classList.add('is-hidden');
        });
        video.addEventListener('play', hideOverlay);
        video.addEventListener('playing', hideOverlay);
        video.addEventListener('pause', function () {
            if (video.currentTime > 0.2 && overlay && !video.ended) {
                overlay.classList.remove('is-hidden');
                var label = overlay.querySelector('.lesson-play-label');
                if (label) label.textContent = 'Resume';
            }
            syncStamp();
            saveProgress(true);
        });
        video.addEventListener('ended', function () {
            if (overlay) {
                overlay.classList.remove('is-hidden');
                var label = overlay.querySelector('.lesson-play-label');
                if (label) label.textContent = 'Watch again';
            }
            saveProgress(true);
        });
        video.addEventListener('timeupdate', function () {
            if (watchBar && watchFill && watchLabel && video.duration) {
                watchBar.hidden = false;
                var pct = Math.min(100, Math.round((video.currentTime / video.duration) * 100));
                watchFill.style.width = pct + '%';
                watchLabel.textContent = pct + '% watched';
            }
            if (!stampLocked) syncStamp();
            if (Math.floor(video.currentTime) % 5 === 0) saveProgress(false);
        });
        syncStamp();
    }

    if (overlay && video) {
        overlay.addEventListener('click', function () {
            video.play().catch(function () {});
        });
    }

    if (speedSelect) {
        var savedSpeed = localStorage.getItem('lessonPlaybackSpeed') || '1';
        speedSelect.value = savedSpeed;
        if (video) video.playbackRate = parseFloat(savedSpeed) || 1;
        speedSelect.addEventListener('change', function () {
            setPlaybackSpeed(speedSelect.value);
            localStorage.setItem('lessonPlaybackSpeed', speedSelect.value);
        });
    }

    if (resumeBox && resumeAt >= 5) {
        document.getElementById('lesson-resume-yes')?.addEventListener('click', function () {
            seekTo(resumeAt);
            resumeBox.hidden = true;
        });
        document.getElementById('lesson-resume-no')?.addEventListener('click', function () {
            seekTo(0, false);
            resumeBox.hidden = true;
            lastSaved = -1;
            saveProgress(true);
        });
    }

    // Delegated (not per-element) so "jump to this moment" also works on Q&A
    // cards that get inserted later via addQuestionCard(), without a page reload.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.qa-video-stamp[data-seek]');
        if (!btn) return;
        seekTo(btn.getAttribute('data-seek'));
    });

    if (askJump) askJump.addEventListener('click', focusComposer);
    if (mobileAsk) mobileAsk.addEventListener('click', focusComposer);

    function addQuestionCard(html) {
        var list = document.getElementById('lesson-qa-list');
        var empty = document.getElementById('lesson-qa-empty');
        if (!html || !list) return;
        if (empty) empty.style.display = 'none';
        list.style.display = '';
        list.insertAdjacentHTML('afterbegin', html);

        var openStat = document.getElementById('lesson-qa-open-stat');
        if (openStat) {
            var openCount = (parseInt(openStat.textContent, 10) || 0) + 1;
            openStat.textContent = openCount + ' open';
            openStat.style.display = '';
        }
    }

    // Ask a question over fetch, not a normal form POST: a normal POST redirects
    // back to this page, which resets native <video> playback to 0:00. Submitting
    // in the background keeps the lesson playing and just adds the new card.
    if (composer) {
        composer.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!input) return;
            var questionText = input.value.trim();
            if (!questionText || !csrf) return;

            stampLocked = stampAttachEnabled;
            if (stampAttachEnabled) {
                lockedSeconds = getPlaybackSeconds();
            }
            syncStamp();
            if (sendBtn) sendBtn.disabled = true;

            var body = new FormData();
            body.append('_token', csrf);
            body.append('action', 'ask_question');
            body.append('question', questionText);
            body.append('video_seconds', stampAttachEnabled && secondsInput ? secondsInput.value : '-1');

            fetch(window.location.pathname + window.location.search, {
                method: 'POST',
                headers: { 'X-Requested-With': 'fetch' },
                body: body,
                credentials: 'same-origin'
            })
                .then(function (res) { return res.json().catch(function () { return null; }); })
                .then(function (data) {
                    if (!data || !data.ok) {
                        throw new Error((data && data.error) || 'Could not send your question. Please try again.');
                    }
                    addQuestionCard(data.html);
                    input.value = '';
                    stampLocked = false;
                    syncStamp();
                    composer.classList.remove('is-focused');
                })
                .catch(function (err) {
                    window.alert(err.message || 'Could not send your question. Please check your connection and try again.');
                })
                .finally(function () {
                    syncComposer();
                });
        });
    }

    function syncComposer() {
        if (!input || !sendBtn || !charCount) return;
        var len = input.value.trim().length;
        charCount.textContent = input.value.length + ' / 1000';
        sendBtn.disabled = len === 0;
        charCount.classList.toggle('is-near-limit', input.value.length > 900);
    }

    if (input) {
        input.addEventListener('input', syncComposer);
        input.addEventListener('focus', function () {
            composer?.classList.add('is-focused');
            pausePlayback();
            lockStampFromVideo();
        });
        input.addEventListener('blur', function () {
            composer?.classList.remove('is-focused');
        });
        syncComposer();
    }

    function toggleTheater() {
        if (!theaterBtn || !root) return;
        root.classList.toggle('is-theater');
        var on = root.classList.contains('is-theater');
        theaterBtn.classList.toggle('lesson-toolbar-btn--accent', on);
        var label = theaterBtn.querySelector('[data-theater-label]');
        if (label) label.textContent = on ? 'Exit theater' : 'Theater';
        // Pull focus out of the YouTube/Vimeo iframe so the next T / F press
        // reaches this page instead of disappearing into the embed.
        try { theaterBtn.focus({ preventScroll: true }); } catch (err) { theaterBtn.focus(); }
    }

    // Theater mode: keep Q&A beside a larger player
    if (theaterBtn && root) {
        theaterBtn.addEventListener('click', function () {
            toggleTheater();
        });
    }

    // Lesson notes edit toggle
    var notesEdit = document.getElementById('lesson-notes-edit-btn');
    var notesForm = document.getElementById('lesson-notes-form');
    var notesBody = document.getElementById('lesson-notes-body');
    var notesCancel = document.getElementById('lesson-notes-cancel');
    if (notesEdit && notesForm) {
        notesEdit.addEventListener('click', function () {
            notesForm.hidden = false;
            if (notesBody) notesBody.hidden = true;
            notesEdit.hidden = true;
        });
    }
    if (notesCancel && notesForm) {
        notesCancel.addEventListener('click', function () {
            notesForm.hidden = true;
            if (notesBody) notesBody.hidden = false;
            if (notesEdit) notesEdit.hidden = false;
        });
    }

    // Keyboard shortcuts (ignore when typing). Note: while focus is inside a
    // cross-origin iframe the browser will not deliver keys here — after using
    // theater we refocus the button so subsequent presses work again.
    document.addEventListener('keydown', function (e) {
        var tag = (e.target && e.target.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable) return;

        if (e.key === 'f' || e.key === 'F') {
            e.preventDefault();
            if (document.fullscreenElement) document.exitFullscreen?.();
            else player?.requestFullscreen?.();
            return;
        } else if (e.key === 't' || e.key === 'T') {
            e.preventDefault();
            toggleTheater();
            return;
        }

        if (!video && !embedProvider) return;

        if (e.code === 'Space') {
            e.preventDefault();
            togglePlayPause();
        } else if (e.code === 'ArrowLeft') {
            e.preventDefault();
            skipBy(-10);
        } else if (e.code === 'ArrowRight') {
            e.preventDefault();
            skipBy(10);
        }
    });

    window.addEventListener('beforeunload', function () { saveProgress(true); });
    window.addEventListener('pagehide', function () {
        if (embedPollTimer) window.clearInterval(embedPollTimer);
    });

    function focusQuestionFromHash() {
        var hash = window.location.hash || '';
        var match = hash.match(/^#q-(\d+)$/);
        if (!match) return;
        var el = document.getElementById('q-' + match[1]);
        if (!el) return;

        var side = document.getElementById('qa') || el.closest('.lesson-side') || el;
        side.scrollIntoView({ behavior: 'smooth', block: 'start' });
        el.classList.add('qa-item--target');
        window.setTimeout(function () { el.classList.remove('qa-item--target'); }, 2600);

        var details = el.querySelector('details.qa-reply-details');
        if (details) {
            details.open = true;
        }

        var seekBtn = el.querySelector('[data-seek]');
        if (seekBtn) {
            var secs = parseInt(seekBtn.getAttribute('data-seek') || '0', 10);
            if (secs > 0) {
                window.setTimeout(function () { seekTo(secs); }, 150);
            }
        }

        var answerBox = el.querySelector('textarea[name="answer"]');
        if (answerBox) {
            window.setTimeout(function () {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                answerBox.focus();
            }, 350);
        } else {
            window.setTimeout(function () {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 200);
        }
    }

    window.addEventListener('hashchange', focusQuestionFromHash);
    window.setTimeout(focusQuestionFromHash, 80);
})();
</script>
<?php
$page_content = ob_get_clean();
require __DIR__ . '/../layout.php';
