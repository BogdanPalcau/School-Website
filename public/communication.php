<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

portal_require_login();

$me      = portal_current_user();
$isAdmin = portal_is_admin();
$db      = portal_db();
$flash   = null;

// ── POST actions (admin/owner only) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!portal_verify_csrf()) {
        $_SESSION['comm_flash'] = ['error', 'Your session expired. Please try that again.'];
        portal_redirect('communication.php');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'post_site_announcement' && $isAdmin) {
        $title    = substr(trim((string) ($_POST['title'] ?? '')), 0, 200);
        $body     = substr(portal_sanitize_rich_text(trim((string) ($_POST['body'] ?? ''))), 0, 20000);
        $priority = ((string) ($_POST['priority'] ?? 'normal')) === 'urgent' ? 'urgent' : 'normal';
        $pinned   = isset($_POST['pinned']) && $_POST['pinned'] === '1' ? 1 : 0;

        if ($title === '') {
            $_SESSION['comm_flash'] = ['error', 'Please add a title before posting.'];
        } else {
            $db->prepare(
                "INSERT INTO site_announcements (user_id, title, body, priority, pinned) VALUES (?,?,?,?,?)"
            )->execute([(int) $me['id'], $title, $body, $priority, $pinned]);
            $_SESSION['comm_flash'] = ['success', 'Announcement posted to the whole school.'];
        }
        portal_redirect('communication.php#major-announcements');
    }

    if ($action === 'delete_site_announcement' && $isAdmin) {
        $id = (int) ($_POST['announcement_id'] ?? 0);
        $db->prepare("DELETE FROM site_announcements WHERE id = ?")->execute([$id]);
        $_SESSION['comm_flash'] = ['success', 'Announcement removed.'];
        portal_redirect('communication.php#major-announcements');
    }

    if ($action === 'toggle_pin_site_announcement' && $isAdmin) {
        $id = (int) ($_POST['announcement_id'] ?? 0);
        $db->prepare(
            "UPDATE site_announcements SET pinned = CASE WHEN pinned = 1 THEN 0 ELSE 1 END WHERE id = ?"
        )->execute([$id]);
        portal_redirect('communication.php#major-announcements');
    }

    if ($action === 'mark_notification_read') {
        $id = (int) ($_POST['notification_id'] ?? 0);
        if ($id > 0) {
            $db->prepare(
                "UPDATE portal_notifications SET read_at = datetime('now')
                 WHERE id = ? AND user_id = ? AND read_at = ''"
            )->execute([$id, (int) $me['id']]);
        }
        portal_redirect('communication.php#for-you');
    }

    if ($action === 'mark_all_notifications_read') {
        $db->prepare(
            "UPDATE portal_notifications SET read_at = datetime('now')
             WHERE user_id = ? AND read_at = ''"
        )->execute([(int) $me['id']]);
        portal_redirect('communication.php#for-you');
    }

    portal_redirect('communication.php');
}

if (isset($_SESSION['comm_flash'])) {
    $flash = $_SESSION['comm_flash'];
    unset($_SESSION['comm_flash']);
}

// ── Data: major (school-wide) announcements ────────────────────────────────
$majorAnnouncements = $db->query(
    "SELECT sa.*, u.name AS author_name, u.initials AS author_initials
     FROM site_announcements sa
     JOIN users u ON u.id = sa.user_id
     ORDER BY sa.pinned DESC, sa.created_at DESC
     LIMIT 40"
)->fetchAll();

// ── Data: module (course) announcements relevant to this user ─────────────
$myCourseIds        = portal_my_announcement_course_ids();
$moduleAnnouncements = [];
if (!empty($myCourseIds)) {
    $placeholders = implode(',', array_fill(0, count($myCourseIds), '?'));
    $stmt = $db->prepare(
        "SELECT ca.*, u.name AS author_name, u.initials AS author_initials,
                c.title AS course_title, c.slug AS course_slug, c.accent AS course_accent
         FROM course_announcements ca
         JOIN users u ON u.id = ca.user_id
         JOIN courses c ON c.id = ca.course_id
         WHERE ca.course_id IN ($placeholders)
         ORDER BY ca.created_at DESC
         LIMIT 40"
    );
    $stmt->execute($myCourseIds);
    $moduleAnnouncements = $stmt->fetchAll();
}

// Personal notifications (e.g. lesson Q&A answers)
$notifStmt = $db->prepare(
    "SELECT * FROM portal_notifications
     WHERE user_id = ?
     ORDER BY CASE WHEN read_at = '' THEN 0 ELSE 1 END, created_at DESC
     LIMIT 30"
);
$notifStmt->execute([(int) $me['id']]);
$personalNotifications = $notifStmt->fetchAll();
$unreadNotifCount = count(array_filter($personalNotifications, static fn ($n) => (string) $n['read_at'] === ''));

$page_title = 'Communication | ' . portal_school_name();
$active_page = 'communication';
$page_eyebrow = 'School bulletin';
$page_heading = 'Communication';
$page_description = $isAdmin
    ? 'Post major school-wide announcements and keep an eye on what every module is telling students.'
    : 'Major announcements from the school office, plus the latest updates from the modules you\'re enrolled in.';

ob_start();
?>
<div class="comm-layout">

    <!-- ── Personal notifications ─────────────────────────────────────────── -->
    <?php if (!empty($personalNotifications)): ?>
    <section class="comm-section" id="for-you">
        <div class="comm-section-head">
            <div>
                <p class="eyebrow">Personal</p>
                <h2 class="comm-section-title"><?= portal_icon('sparkles', 'icon-sm') ?> For you</h2>
                <p class="comm-section-desc">Alerts just for you — like when a teacher answers your lesson question.</p>
            </div>
            <div class="button-row" style="align-items:center;gap:10px;">
                <?php if ($unreadNotifCount > 0): ?>
                <span class="chip"><?= (int) $unreadNotifCount ?> new</span>
                <form method="POST" style="margin:0;">
                    <?= portal_csrf_field() ?>
                    <input type="hidden" name="action" value="mark_all_notifications_read">
                    <button type="submit" class="button-secondary button--sm">Mark all read</button>
                </form>
                <?php else: ?>
                <span class="chip"><?= count($personalNotifications) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="comm-notif-list">
            <?php foreach ($personalNotifications as $n): ?>
            <?php $isUnread = (string) $n['read_at'] === ''; ?>
            <article class="comm-notif-item<?= $isUnread ? ' comm-notif-item--unread' : '' ?>">
                <div>
                    <h4><?= portal_escape((string) $n['title']) ?></h4>
                    <?php if ($n['body'] !== ''): ?>
                    <p><?= portal_escape((string) $n['body']) ?></p>
                    <?php endif; ?>
                    <p class="sub-date" style="margin-top:6px;"><?= portal_escape(date('j M Y · H:i', strtotime((string) $n['created_at']))) ?></p>
                </div>
                <div class="button-row" style="flex-shrink:0;">
                    <?php if ($n['link'] !== ''): ?>
                    <a class="button button--sm" href="<?= portal_escape((string) $n['link']) ?>">Open</a>
                    <?php endif; ?>
                    <?php if ($isUnread): ?>
                    <form method="POST" style="margin:0;">
                        <?= portal_csrf_field() ?>
                        <input type="hidden" name="action" value="mark_notification_read">
                        <input type="hidden" name="notification_id" value="<?= (int) $n['id'] ?>">
                        <button type="submit" class="button-secondary button--sm">Mark read</button>
                    </form>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- ── Major (school-wide) announcements ─────────────────────────────── -->
    <section class="comm-section" id="major-announcements">
        <div class="comm-section-head">
            <div>
                <p class="eyebrow">School-wide</p>
                <h2 class="comm-section-title"><?= portal_icon('megaphone', 'icon-sm') ?> Major announcements</h2>
                <p class="comm-section-desc">Official notices from the admin team. Pinned items always stay on top.</p>
            </div>
            <span class="chip"><?= count($majorAnnouncements) ?> posted</span>
        </div>

        <?php if ($flash): ?>
        <div class="admin-flash <?= $flash[0] === 'success' ? 'success' : 'error' ?>">
            <?php if ($flash[0] === 'success'): ?>
                <span><?= portal_escape($flash[1]) ?></span>
            <?php else: ?>
                <?= portal_icon('lock', 'admin-flash-icon') ?>
                <span><?= portal_escape($flash[1]) ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($isAdmin): ?>
        <details class="folder-admin-panel comm-post-panel">
            <summary class="folder-admin-trigger">
                <?= portal_icon('plus', 'icon-sm') ?>
                <span>Post a major announcement</span>
            </summary>
            <form method="POST" class="folder-admin-form" action="communication.php">
                <?= portal_csrf_field() ?>
                <input type="hidden" name="action" value="post_site_announcement">
                <label class="folder-form-label">
                    <span>Title</span>
                    <input type="text" name="title" required maxlength="200" placeholder="e.g. Half-term dates confirmed">
                </label>
                <label class="folder-form-label">
                    <span>Message <small>(optional)</small></span>
                    <div class="quill-wrap"><div class="quill-editor" data-target="site-ann-body"></div></div>
                    <textarea name="body" id="site-ann-body" class="rich-textarea" maxlength="20000" hidden></textarea>
                </label>
                <div class="comm-post-options">
                    <label class="folder-form-label comm-priority-field">
                        <span>Priority</span>
                        <select name="priority">
                            <option value="normal">Normal</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </label>
                    <label class="admin-checkbox-inline comm-pin-checkbox">
                        <input type="checkbox" name="pinned" value="1">
                        <?= portal_icon('pin', 'icon-sm') ?> Pin to top
                    </label>
                </div>
                <div class="button-row">
                    <button type="submit" class="button">Post to whole school</button>
                </div>
            </form>
        </details>
        <?php endif; ?>

        <?php if (empty($majorAnnouncements)): ?>
            <div class="folder-empty-state comm-empty">
                <?= portal_icon('megaphone') ?>
                <p>No school-wide announcements yet. <?= $isAdmin ? 'Post the first one above.' : 'Check back soon for updates from the school office.' ?></p>
            </div>
        <?php else: ?>
        <div class="announcement-feed">
            <?php foreach ($majorAnnouncements as $ann): ?>
            <?php $isPinned = (int) $ann['pinned'] === 1; $isUrgent = $ann['priority'] === 'urgent'; ?>
            <article class="announcement-card<?= $isPinned ? ' pinned' : '' ?><?= $isUrgent ? ' urgent' : '' ?>">
                <div class="announcement-card-head">
                    <div class="announcement-badges">
                        <?php if ($isPinned): ?><span class="announcement-badge announcement-badge--pinned"><?= portal_icon('pin', 'icon-xs') ?> Pinned</span><?php endif; ?>
                        <?php if ($isUrgent): ?><span class="announcement-badge announcement-badge--urgent"><?= portal_icon('alert', 'icon-xs') ?> Urgent</span><?php endif; ?>
                    </div>
                    <span class="announcement-date"><?= portal_escape(date('j M Y, g:ia', strtotime($ann['created_at']))) ?></span>
                </div>
                <h3 class="announcement-card-title"><?= portal_escape($ann['title']) ?></h3>
                <?php if ($ann['body'] !== ''): ?>
                    <div class="rich-body announcement-card-body"><?= portal_render_rich_text($ann['body']) ?></div>
                <?php endif; ?>
                <div class="announcement-card-foot">
                    <div class="announcement-author">
                        <span class="course-staff-avatar ann-avatar"><?= portal_escape($ann['author_initials']) ?></span>
                        <span><?= portal_escape($ann['author_name']) ?> · Admin team</span>
                    </div>
                    <?php if ($isAdmin): ?>
                    <div class="announcement-admin-actions">
                        <form method="POST">
                            <?= portal_csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_pin_site_announcement">
                            <input type="hidden" name="announcement_id" value="<?= (int) $ann['id'] ?>">
                            <button type="submit" class="btn-icon<?= $isPinned ? ' btn-icon--active' : '' ?>" title="<?= $isPinned ? 'Unpin' : 'Pin to top' ?>">
                                <?= portal_icon('pin', 'icon-sm') ?>
                            </button>
                        </form>
                        <form method="POST" onsubmit="return confirm('Delete this announcement?')">
                            <?= portal_csrf_field() ?>
                            <input type="hidden" name="action" value="delete_site_announcement">
                            <input type="hidden" name="announcement_id" value="<?= (int) $ann['id'] ?>">
                            <button type="submit" class="btn-icon-danger" title="Delete">
                                <?= portal_icon('trash', 'icon-sm') ?>
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <!-- ── Module (in-course) announcements ──────────────────────────────── -->
    <section class="comm-section" id="module-announcements">
        <div class="comm-section-head">
            <div>
                <p class="eyebrow">Your modules</p>
                <h2 class="comm-section-title"><?= portal_icon('book-open', 'icon-sm') ?> Module announcements</h2>
                <p class="comm-section-desc">
                    <?= $isAdmin
                        ? 'Everything teachers have posted across every module, newest first.'
                        : 'Updates from teachers in the modules you\'re enrolled in, newest first.' ?>
                </p>
            </div>
            <span class="chip"><?= count($moduleAnnouncements) ?> posted</span>
        </div>

        <?php if (empty($myCourseIds)): ?>
            <div class="folder-empty-state comm-empty">
                <?= portal_icon('book-open') ?>
                <p>You're not enrolled in any modules yet. Once you are, announcements from your teachers will show up here.</p>
            </div>
        <?php elseif (empty($moduleAnnouncements)): ?>
            <div class="folder-empty-state comm-empty">
                <?= portal_icon('book-open') ?>
                <p>No announcements yet from your modules. Check back after your teachers post updates.</p>
            </div>
        <?php else: ?>
        <div class="announcement-feed">
            <?php foreach ($moduleAnnouncements as $ann): ?>
            <article class="announcement-card module-announcement-card">
                <div class="announcement-card-head">
                    <a class="module-course-chip" href="course.php?course=<?= urlencode((string) $ann['course_slug']) ?>&section=announcements">
                        <span class="module-course-dot" style="background:<?= portal_escape((string) $ann['course_accent']) ?>"></span>
                        <?= portal_escape((string) $ann['course_title']) ?>
                    </a>
                    <span class="announcement-date"><?= portal_escape(date('j M Y', strtotime($ann['created_at']))) ?></span>
                </div>
                <h3 class="announcement-card-title"><?= portal_escape($ann['title']) ?></h3>
                <?php if ($ann['body'] !== ''): ?>
                    <div class="rich-body announcement-card-body"><?= portal_render_rich_text($ann['body']) ?></div>
                <?php endif; ?>
                <div class="announcement-card-foot">
                    <div class="announcement-author">
                        <span class="course-staff-avatar ann-avatar"><?= portal_escape($ann['author_initials']) ?></span>
                        <span><?= portal_escape($ann['author_name']) ?></span>
                    </div>
                    <a class="inline-action" href="course.php?course=<?= urlencode((string) $ann['course_slug']) ?>&section=announcements">Open module →</a>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

</div>

<?php if ($isAdmin): ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2/dist/quill.snow.css">
<script src="https://cdn.jsdelivr.net/npm/quill@2/dist/quill.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (typeof Quill === 'undefined') return;

    const toolbarOptions = [
        [{ header: [2, 3, false] }],
        ['bold', 'italic', 'underline', 'strike'],
        [{ list: 'ordered' }, { list: 'bullet' }],
        ['blockquote'],
        ['clean'],
    ];

    document.querySelectorAll('.quill-editor[data-target]').forEach(container => {
        const targetId = container.dataset.target;
        const textarea = document.getElementById(targetId);
        if (!textarea) return;

        const quill = new Quill(container, {
            theme: 'snow',
            placeholder: 'Write something…',
            modules: { toolbar: toolbarOptions },
        });

        quill.on('text-change', () => {
            textarea.value = quill.root.innerHTML === '<p><br></p>' ? '' : quill.root.innerHTML;
        });

        const form = textarea.closest('form');
        if (form) {
            form.addEventListener('submit', () => {
                textarea.value = quill.root.innerHTML === '<p><br></p>' ? '' : quill.root.innerHTML;
            });
        }
    });
});
</script>
<?php endif; ?>
<?php
$page_content = ob_get_clean();

require __DIR__ . '/../layout.php';
