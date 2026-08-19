                <?php if (portal_can_manage_course($courseId)): ?>
                    <details class="folder-admin-panel">
                        <summary class="folder-admin-trigger">
                            <?= portal_icon('megaphone', 'icon-sm') ?>
                            <span>Post announcement</span>
                        </summary>
                        <form method="POST" class="folder-admin-form">
                            <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                            <input type="hidden" name="action" value="post_announcement">
                            <label class="folder-form-label">
                                <span>Title</span>
                                <input type="text" name="title" required maxlength="200" placeholder="Announcement title">
                            </label>
                            <div class="folder-form-label">
                                <span>Message <small>(optional)</small></span>
                                <div class="quill-wrap"><div class="quill-editor" data-target="ann-body"></div></div>
                                <textarea name="body" id="ann-body" class="rich-textarea" maxlength="20000" hidden></textarea>
                            </div>
                            <button type="submit" class="button">Post</button>
                        </form>
                    </details>
                <?php endif; ?>

                <article class="card-shell">
                    <div class="section-head">
                        <div>
                            <p class="eyebrow">Course announcements</p>
                            <h3 class="card-title">Latest notices</h3>
                        </div>
                        <span class="chip"><?= count($courseAnnouncements) ?> posts</span>
                    </div>

                    <?php if (empty($courseAnnouncements)): ?>
                        <p style="margin:0;color:var(--muted);font-size:0.9rem;">No announcements yet.</p>
                    <?php else: ?>
                    <div class="simple-list">
                        <?php foreach ($courseAnnouncements as $ann): ?>
                            <article class="simple-list-item ann-item">
                                <time>
                                    <div class="course-staff-avatar ann-avatar"><?= portal_escape($ann['author_initials']) ?></div>
                                    <strong><?= portal_escape($ann['author_name']) ?></strong>
                                    <span><?= portal_escape(date('j M Y', strtotime($ann['created_at']))) ?></span>
                                </time>
                                <div class="simple-list-copy">
                                    <h3><?= portal_escape($ann['title']) ?></h3>
                                    <?php if ($ann['body'] !== ''): ?>
                                        <div class="rich-body"><?= portal_render_rich_text($ann['body']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php if (portal_can_manage_course($courseId) && (portal_is_admin() || (int)$ann['user_id'] === (int)(portal_current_user()['id'] ?? 0))): ?>
                                    <form method="POST" class="ann-delete-form">
                                        <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete_announcement">
                                        <input type="hidden" name="announcement_id" value="<?= (int) $ann['id'] ?>">
                                        <button type="submit" class="btn-icon-danger" title="Delete">
                                            <?= portal_icon('trash', 'icon-sm') ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
