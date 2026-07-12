<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

portal_require_login();

$me = portal_current_user();
$db = portal_db();
$uid = (int) ($me['id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!portal_verify_csrf()) {
        $_SESSION['notif_flash'] = ['error', 'Your session expired. Please try again.'];
        portal_redirect('notifications.php');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'mark_notification_read') {
        $id = (int) ($_POST['notification_id'] ?? 0);
        if ($id > 0) {
            $db->prepare(
                "UPDATE portal_notifications SET read_at = datetime('now')
                 WHERE id = ? AND user_id = ? AND read_at = ''"
            )->execute([$id, $uid]);
        }
        portal_redirect('notifications.php');
    }

    if ($action === 'mark_all_notifications_read') {
        $db->prepare(
            "UPDATE portal_notifications SET read_at = datetime('now')
             WHERE user_id = ? AND read_at = ''"
        )->execute([$uid]);
        portal_redirect('notifications.php');
    }

    portal_redirect('notifications.php');
}

$flash = null;
if (isset($_SESSION['notif_flash'])) {
    $flash = $_SESSION['notif_flash'];
    unset($_SESSION['notif_flash']);
}

$notifStmt = $db->prepare(
    "SELECT * FROM portal_notifications
     WHERE user_id = ?
     ORDER BY CASE WHEN read_at = '' THEN 0 ELSE 1 END, created_at DESC
     LIMIT 50"
);
$notifStmt->execute([$uid]);
$personalNotifications = $notifStmt->fetchAll();
$unreadNotifCount = count(array_filter(
    $personalNotifications,
    static fn(array $n): bool => trim((string) ($n['read_at'] ?? '')) === ''
));

$page_title = 'Notifications | ' . portal_school_name();
$active_page = 'notifications';
$page_eyebrow = 'Inbox';
$page_heading = 'Notifications';
$page_description = 'Updates others sent you — not your work queue. Marking and deadlines stay on the Dashboard under To do / Your priorities.';

ob_start();
?>
<section class="comm-layout">
    <?php if ($flash): ?>
    <div class="admin-flash <?= $flash[0] === 'success' ? 'success' : 'error' ?>">
        <span><?= portal_escape((string) $flash[1]) ?></span>
    </div>
    <?php endif; ?>

    <section class="comm-section" id="notifications">
        <div class="comm-section-head">
            <div>
                <p class="eyebrow">Inbox</p>
                <h2 class="comm-section-title"><?= portal_icon('bell', 'icon-sm') ?> Notifications</h2>
                <p class="comm-section-desc">Messages and updates sent to you. Work waiting on you lives on the Dashboard.</p>
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
                <span class="chip chip--muted"><?= count($personalNotifications) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($personalNotifications)): ?>
        <p class="dash-empty" style="padding:4px 0 8px;">No personal alerts yet. When someone replies to you in a lesson or discussion, or a module posts an announcement, it will show up here.</p>
        <?php else: ?>
        <div class="comm-notif-list">
            <?php foreach ($personalNotifications as $n): ?>
            <?php
                $isUnread = trim((string) ($n['read_at'] ?? '')) === '';
                $typeTag = match ((string) ($n['type'] ?? '')) {
                    'discussion_reply', 'discussion' => 'Discussion',
                    'lesson_answer', 'qa' => 'Q&A',
                    'announcement', 'announcements' => 'Announcement',
                    'grade', 'grades' => 'Grade',
                    default => '',
                };
            ?>
            <article class="comm-notif-item<?= $isUnread ? ' comm-notif-item--unread' : '' ?>">
                <div>
                    <?php if ($typeTag !== ''): ?>
                    <span class="dash-announce-tag dash-announce-tag--module" style="margin-bottom:6px;"><?= portal_escape($typeTag) ?></span>
                    <?php endif; ?>
                    <h4><?= portal_escape((string) $n['title']) ?></h4>
                    <?php if (trim((string) ($n['body'] ?? '')) !== ''): ?>
                    <p><?= portal_escape((string) $n['body']) ?></p>
                    <?php endif; ?>
                    <p class="sub-date" style="margin-top:6px;"><?= portal_escape(portal_relative_time((string) $n['created_at'])) ?></p>
                </div>
                <div class="button-row" style="flex-shrink:0;">
                    <?php if (trim((string) ($n['link'] ?? '')) !== ''): ?>
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
        <?php endif; ?>
    </section>
</section>
<?php
$page_content = ob_get_clean();
require __DIR__ . '/../layout.php';
