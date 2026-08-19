<?php if (!empty($submissionModals)): ?>
<?= $submissionModals ?>
<?php endif; ?>

<div id="external-link-warning" class="external-link-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="external-link-title">
    <div class="external-link-dialog">
        <header class="external-link-header">
            <div>
                <p class="eyebrow">External source</p>
                <h3 id="external-link-title">You are about to leave this website</h3>
            </div>
            <button type="button" class="external-link-close" aria-label="Cancel">&times;</button>
        </header>
        <div class="external-link-body">
            <p class="external-link-message" data-external-link-message>Checking this link with Google Safe Browsing...</p>
            <p class="external-link-url" data-external-link-url></p>
            <div class="external-link-verdict" data-external-link-verdict>Checking...</div>
        </div>
        <footer class="external-link-actions">
            <button type="button" class="button button--ghost" data-external-link-cancel>Cancel</button>
            <button type="button" class="button" data-external-link-continue disabled>Continue</button>
        </footer>
    </div>
</div>

<?php if (!empty($unreadAnnouncements)): ?>
<!-- ── Unread announcements notification ─────────────────────────────────────── -->
<div id="ann-notification" class="ann-notify-overlay" hidden role="dialog" aria-modal="true" aria-label="New announcements">
    <div class="ann-notify-box">
        <div class="ann-notify-header">
            <div>
                <p class="eyebrow">New</p>
                <h3>Announcement<?= count($unreadAnnouncements) !== 1 ? 's' : '' ?></h3>
            </div>
            <button class="ann-notify-close" id="ann-notify-close" aria-label="Dismiss">×</button>
        </div>
        <div class="ann-notify-body">
            <?php foreach ($unreadAnnouncements as $ann): ?>
            <div class="ann-notify-item" data-ann-id="<?= (int) $ann['id'] ?>">
                <strong><?= portal_escape($ann['title']) ?></strong>
                <?php if ($ann['body'] !== ''): ?>
                    <div class="rich-body"><?= portal_render_rich_text($ann['body']) ?></div>
                <?php endif; ?>
                <span class="ann-notify-meta"><?= portal_escape(date('j M Y', strtotime($ann['created_at']))) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="ann-notify-footer">
            <button class="button" id="ann-mark-read">Mark as read</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Document viewer overlay (same-tab, smooth — mirrors the assignment review dialog) ── -->
<div id="doc-viewer-overlay" class="docviewer-overlay" hidden role="dialog" aria-modal="true" aria-label="Document viewer">
    <div class="docviewer-dialog">
        <header class="docviewer-dialog-header">
            <div class="docviewer-dialog-heading">
                <p class="eyebrow">Course document</p>
                <h3 id="doc-viewer-title">Document viewer</h3>
                <p class="docviewer-dialog-sub" id="doc-viewer-meta"></p>
            </div>
            <button type="button" class="docviewer-close" id="doc-viewer-close" aria-label="Close document viewer">
                <?= portal_icon('x', 'docviewer-close-icon') ?>
            </button>
        </header>
        <div class="docviewer-frame-wrap">
            <iframe id="doc-viewer-frame" class="docviewer-frame" title="Document viewer" allow="fullscreen" allowfullscreen tabindex="0"></iframe>
        </div>
    </div>
</div>
