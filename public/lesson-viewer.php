<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
portal_require_login();

$db = portal_db();
$me = portal_current_user();

$itemId = (int) ($_GET['item'] ?? 0);
if (!$itemId) { http_response_code(400); exit('Bad request.'); }

$itemStmt = $db->prepare(
    "SELECT cfi.*, c.id AS course_id, c.slug AS course_slug, c.full_title AS course_title
     FROM course_folder_items cfi
     JOIN course_folders cf ON cf.id = cfi.folder_id
     JOIN courses c ON c.id = cf.course_id
     WHERE cfi.id = ? AND cfi.type = 'video' AND cfi.file_path != ''"
);
$itemStmt->execute([$itemId]);
$item = $itemStmt->fetch();

if (!$item) { http_response_code(404); exit('Video not found.'); }

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
$canAsk = !$canManage;

if (empty($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['_csrf'];

$backUrl = 'course.php?course=' . urlencode((string) $item['course_slug']) . '&section=content';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!portal_verify_csrf()) {
        portal_redirect('lesson-viewer.php?item=' . $itemId);
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'ask_question') {
        if (!$canAsk) {
            $_SESSION['lesson_flash'] = ['error', 'Only students can ask questions on lesson videos.'];
        } else {
            $question = substr(trim((string) ($_POST['question'] ?? '')), 0, 1000);
            $videoSeconds = max(0, (int) ($_POST['video_seconds'] ?? 0));
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
        $qChk = $db->prepare("SELECT id FROM course_video_questions WHERE id = ? AND item_id = ? AND course_id = ?");
        $qChk->execute([$questionId, $itemId, $courseId]);
        if ($qChk->fetch() && $answer !== '') {
            $db->prepare(
                "UPDATE course_video_questions
                 SET answer = ?, answered_by = ?, answered_at = datetime('now')
                 WHERE id = ? AND item_id = ? AND course_id = ?"
            )->execute([$answer, (int) $me['id'], $questionId, $itemId, $courseId]);
            $_SESSION['lesson_flash'] = ['success', 'Your answer is now visible to the whole class.'];
        } else {
            $_SESSION['lesson_flash'] = ['error', 'Please write an answer before publishing it.'];
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
    }

    portal_redirect('lesson-viewer.php?item=' . $itemId . '#qa');
}

$lessonFlash = $_SESSION['lesson_flash'] ?? null;
unset($_SESSION['lesson_flash']);

$allowDownload = (bool) ($item['allow_download'] ?? 0);
$canDownload   = $canManage || $allowDownload;
$displayName   = $item['file_name'] !== '' ? $item['file_name'] : basename((string) $item['file_path']);
$ext           = strtolower(pathinfo($displayName, PATHINFO_EXTENSION));
$mime          = portal_video_mime_for_extension($ext);
$description   = trim((string) ($item['description'] ?? ''));
$videoUrl      = 'download.php?item=' . $itemId . '&view=1';
$downloadUrl   = 'download.php?item=' . $itemId;

$qStmt = $db->prepare(
    "SELECT q.*, u.name AS asker_name, u.initials AS asker_initials,
            a.name AS answerer_name, a.initials AS answerer_initials
     FROM course_video_questions q
     JOIN users u ON u.id = q.user_id
     LEFT JOIN users a ON a.id = q.answered_by
     WHERE q.item_id = ?
     ORDER BY
        CASE WHEN q.answer = '' THEN 0 ELSE 1 END,
        q.created_at DESC"
);
$qStmt->execute([$itemId]);
$allQuestions = $qStmt->fetchAll();

$questions = array_values(array_filter($allQuestions, function ($q) use ($canManage, $me) {
    return $canManage || (string) $q['answer'] !== '' || (int) $q['user_id'] === (int) $me['id'];
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
<section class="lesson-viewer">
    <div class="lesson-toolbar">
        <a class="lesson-back" href="<?= portal_escape($backUrl) ?>">
            <span aria-hidden="true">←</span> Course content
        </a>
        <div class="lesson-toolbar-actions">
            <span class="lesson-pill"><?= portal_escape(strtoupper($ext)) ?></span>
            <?php if ($canDownload): ?>
            <a class="lesson-toolbar-btn" href="<?= portal_escape($downloadUrl) ?>">
                <?= portal_icon('download', 'icon-xs') ?>
                Download
            </a>
            <?php endif; ?>
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

    <div class="lesson-layout">
        <div class="lesson-main">
            <article class="lesson-stage">
                <div class="lesson-player" id="lesson-player">
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
                </div>

                <div class="lesson-watch-bar" id="lesson-watch-bar" hidden>
                    <div class="lesson-watch-track">
                        <div class="lesson-watch-fill" id="lesson-watch-fill"></div>
                    </div>
                    <span class="lesson-watch-label" id="lesson-watch-label">0%</span>
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
                    <div class="lesson-qa-stats">
                        <span class="lesson-stat" title="Answered"><?= (int) $answeredCount ?> answered</span>
                        <?php if ($pendingCount > 0): ?>
                        <span class="lesson-stat lesson-stat--hot"><?= (int) $pendingCount ?> open</span>
                        <?php endif; ?>
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
                        <span class="lesson-stamp-hint" id="lesson-stamp-hint">Will attach at 0:00</span>
                        <span class="lesson-char-count" id="lesson-char-count">0 / 1000</span>
                        <button type="submit" class="button button--sm lesson-send-btn" id="lesson-send-btn" disabled>
                            Send question
                        </button>
                    </div>
                </form>
                <?php else: ?>
                <p class="lesson-staff-note">Students ask questions here. Open questions are listed below — click a time stamp to jump to that point in the video.</p>
                <?php endif; ?>

                <?php if (empty($questions)): ?>
                    <div class="lesson-qa-empty">
                        <div class="lesson-qa-empty-icon"><?= portal_icon('megaphone', 'icon-sm') ?></div>
                        <p><?= $canAsk ? 'No questions yet — start the conversation.' : 'No student questions on this lesson yet.' ?></p>
                    </div>
                <?php else: ?>
                <div class="lesson-qa-list">
                    <?php
                    $markedOpenAnchor = false;
                    foreach ($questions as $q):
                        $isAnswered = (string) $q['answer'] !== '';
                        $isOwn      = (int) $q['user_id'] === (int) $me['id'];
                        $canDelete  = $canManage || ($isOwn && !$isAnswered);
                        $videoSecs  = (int) ($q['video_seconds'] ?? 0);
                        $openAnchor = '';
                        if (!$isAnswered && !$markedOpenAnchor) {
                            $openAnchor = ' id="qa-open"';
                            $markedOpenAnchor = true;
                        }
                    ?>
                        <article class="qa-item <?= $isAnswered ? 'qa-item--answered' : 'qa-item--pending' ?><?= $isOwn ? ' qa-item--mine' : '' ?>"<?= $openAnchor ?>>
                            <div class="qa-question-row">
                                <div class="course-staff-avatar ann-avatar"><?= portal_escape((string) ($q['asker_initials'] ?: '?')) ?></div>
                                <div class="qa-question-body">
                                    <div class="qa-question-head">
                                        <strong><?= portal_escape((string) $q['asker_name']) ?><?= $isOwn ? ' <span class="qa-you">(you)</span>' : '' ?></strong>
                                        <button type="button"
                                                class="qa-video-stamp"
                                                data-seek="<?= $videoSecs ?>"
                                                title="Jump to this moment in the video">
                                            <?= portal_icon('play', 'icon-xs') ?>
                                            <?= portal_escape(portal_format_video_timestamp($videoSecs)) ?>
                                        </button>
                                        <span class="sub-date" title="Asked at"><?= portal_escape(date('j M · H:i', strtotime((string) $q['created_at']))) ?></span>
                                        <?php if ($isAnswered): ?>
                                            <span class="qa-badge qa-badge--answered">Answered</span>
                                        <?php else: ?>
                                            <span class="qa-badge qa-badge--pending"><?= $canManage ? 'Needs answer' : 'Waiting' ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="qa-text"><?= nl2br(portal_escape((string) $q['question'])) ?></p>
                                </div>
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

                            <?php if ($isAnswered): ?>
                            <div class="qa-answer-row">
                                <div class="course-staff-avatar ann-avatar teacher-avatar"><?= portal_escape((string) ($q['answerer_initials'] ?: '?')) ?></div>
                                <div class="qa-answer-body">
                                    <div class="qa-question-head">
                                        <strong><?= portal_escape((string) $q['answerer_name']) ?></strong>
                                        <span class="qa-role-tag">Teacher</span>
                                        <span class="sub-date"><?= portal_escape(date('j M · H:i', strtotime((string) $q['answered_at']))) ?></span>
                                    </div>
                                    <p class="qa-text"><?= nl2br(portal_escape((string) $q['answer'])) ?></p>
                                </div>
                            </div>
                            <?php elseif ($canManage): ?>
                            <details class="qa-reply-details">
                                <summary class="qa-reply-toggle">Answer this publicly</summary>
                                <form method="POST" class="qa-answer-form">
                                    <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                    <input type="hidden" name="action" value="answer_question">
                                    <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
                                    <textarea name="answer" required maxlength="2000" rows="2" placeholder="Your answer will appear for the whole class…"></textarea>
                                    <button type="submit" class="button button--sm">Publish answer</button>
                                </form>
                            </details>
                            <?php elseif ($isOwn): ?>
                            <p class="qa-waiting-note">Waiting for your teacher — once answered, this becomes visible to the class.</p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </article>
        </aside>
    </div>
</section>

<script>
(function () {
    'use strict';
    var player = document.getElementById('lesson-player');
    var video  = document.getElementById('lesson-video');
    var overlay = document.getElementById('lesson-play-overlay');
    var watchBar = document.getElementById('lesson-watch-bar');
    var watchFill = document.getElementById('lesson-watch-fill');
    var watchLabel = document.getElementById('lesson-watch-label');
    var input = document.getElementById('lesson-question-input');
    var sendBtn = document.getElementById('lesson-send-btn');
    var charCount = document.getElementById('lesson-char-count');
    var secondsInput = document.getElementById('lesson-video-seconds');
    var stampHint = document.getElementById('lesson-stamp-hint');
    var askJump = document.getElementById('lesson-ask-jump');
    var composer = document.getElementById('lesson-composer');

    function formatStamp(total) {
        total = Math.max(0, Math.floor(total || 0));
        var h = Math.floor(total / 3600);
        var m = Math.floor((total % 3600) / 60);
        var s = total % 60;
        if (h > 0) {
            return h + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        }
        return m + ':' + String(s).padStart(2, '0');
    }

    function syncStamp() {
        if (!video || !secondsInput) return;
        var secs = Math.floor(video.currentTime || 0);
        secondsInput.value = String(secs);
        if (stampHint) {
            stampHint.textContent = 'Will attach at ' + formatStamp(secs);
        }
    }

    function hideOverlay() {
        if (overlay) overlay.classList.add('is-hidden');
        if (player) player.classList.add('is-playing');
    }

    function seekTo(seconds) {
        if (!video) return;
        var target = Math.max(0, Number(seconds) || 0);
        video.currentTime = target;
        hideOverlay();
        video.play().catch(function () {});
        player?.scrollIntoView({ behavior: 'smooth', block: 'center' });
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
                overlay.querySelector('.lesson-play-label').textContent = 'Resume';
            }
            syncStamp();
        });
        video.addEventListener('ended', function () {
            if (overlay) {
                overlay.classList.remove('is-hidden');
                overlay.querySelector('.lesson-play-label').textContent = 'Watch again';
            }
        });
        video.addEventListener('timeupdate', function () {
            if (watchBar && watchFill && watchLabel && video.duration) {
                watchBar.hidden = false;
                var pct = Math.min(100, Math.round((video.currentTime / video.duration) * 100));
                watchFill.style.width = pct + '%';
                watchLabel.textContent = pct + '% watched';
            }
            syncStamp();
        });
        syncStamp();
    }

    if (overlay && video) {
        overlay.addEventListener('click', function () {
            video.play().catch(function () {});
        });
    }

    document.querySelectorAll('.qa-video-stamp[data-seek]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            seekTo(btn.getAttribute('data-seek'));
        });
    });

    function focusComposer() {
        if (!composer || !input) return;
        composer.scrollIntoView({ behavior: 'smooth', block: 'center' });
        composer.classList.add('is-focused', 'is-pulse');
        input.focus();
        window.setTimeout(function () {
            composer.classList.remove('is-pulse');
        }, 900);
        syncStamp();
    }

    if (askJump) {
        askJump.addEventListener('click', focusComposer);
    }

    if (composer) {
        composer.addEventListener('submit', syncStamp);
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
            syncStamp();
        });
        input.addEventListener('blur', function () {
            composer?.classList.remove('is-focused');
        });
        syncComposer();
    }
})();
</script>
<?php
$page_content = ob_get_clean();
require __DIR__ . '/../layout.php';
