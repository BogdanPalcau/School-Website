                <?php if ($dbCurrentTopic): ?>
                    <!-- ── Single topic view ────────────────────────────────── -->
                    <a class="forum-back-link" href="course.php?course=<?= urlencode($slug) ?>&section=discussions">
                        ← Back to discussions
                    </a>
                    <article class="card-shell forum-topic-header">
                        <div class="forum-topic-meta">
                            <div class="course-staff-avatar ann-avatar"><?= portal_escape($dbCurrentTopic['author_initials']) ?></div>
                            <div>
                                <strong><?= portal_escape($dbCurrentTopic['author_name']) ?></strong>
                                <span class="sub-date"><?= portal_escape(date('j M Y', strtotime($dbCurrentTopic['created_at']))) ?></span>
                            </div>
                            <?php if (portal_can_manage_course($courseId)): ?>
                            <form method="POST" style="margin:0;margin-left:auto;">
                                <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                <input type="hidden" name="action" value="delete_topic">
                                <input type="hidden" name="topic_id" value="<?= (int)$dbCurrentTopic['id'] ?>">
                                <button type="submit" class="btn-danger-sm"><?= portal_icon('trash','icon-sm') ?> Delete topic</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <h2 class="forum-topic-title"><?= portal_escape($dbCurrentTopic['title']) ?></h2>
                        <?php if ($dbCurrentTopic['body'] !== ''): ?>
                            <div class="forum-topic-body rich-body"><?= portal_render_rich_text($dbCurrentTopic['body']) ?></div>
                        <?php endif; ?>
                    </article>

                    <?php foreach ($dbReplies as $reply): ?>
                    <article class="forum-reply-card">
                        <div class="course-staff-avatar ann-avatar reply-avatar"><?= portal_escape($reply['author_initials']) ?></div>
                        <div class="forum-reply-content">
                            <div class="forum-reply-head">
                                <strong><?= portal_escape($reply['author_name']) ?></strong>
                                <span class="sub-date"><?= portal_escape(date('j M Y H:i', strtotime($reply['created_at']))) ?></span>
                            </div>
                            <div class="rich-body"><?= portal_render_rich_text($reply['body']) ?></div>
                        </div>
                        <?php $canDelReply = portal_can_manage_course($courseId) || (int)$reply['user_id'] === (int)$_me['id']; ?>
                        <?php if ($canDelReply): ?>
                        <form method="POST" style="margin:0;align-self:start;">
                            <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete_reply">
                            <input type="hidden" name="reply_id" value="<?= (int)$reply['id'] ?>">
                            <input type="hidden" name="topic_id" value="<?= (int)$dbCurrentTopic['id'] ?>">
                            <button type="submit" class="btn-icon-danger"><?= portal_icon('trash','icon-sm') ?></button>
                        </form>
                        <?php endif; ?>
                    </article>
                    <?php endforeach; ?>

                    <article class="card-shell forum-reply-form-card">
                        <p class="eyebrow">Post a reply</p>
                        <form method="POST" class="forum-reply-form">
                            <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                            <input type="hidden" name="action" value="post_reply">
                            <input type="hidden" name="topic_id" value="<?= (int)$dbCurrentTopic['id'] ?>">
                            <div class="quill-wrap"><div class="quill-editor" data-target="reply-body"></div></div>
                            <textarea name="body" id="reply-body" class="rich-textarea" maxlength="20000" hidden></textarea>
                            <button type="submit" class="button">Post reply</button>
                        </form>
                    </article>

                <?php else: ?>
                    <!-- ── Topic list view ─────────────────────────────────── -->
                    <?php if (portal_can_manage_course($courseId)): ?>
                    <details class="folder-admin-panel">
                        <summary class="folder-admin-trigger">
                            <?= portal_icon('plus', 'icon-sm') ?> <span>New topic</span>
                        </summary>
                        <form method="POST" class="folder-admin-form">
                            <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                            <input type="hidden" name="action" value="create_topic">
                            <label class="folder-form-label">
                                <span>Topic title</span>
                                <input type="text" name="title" required maxlength="200" placeholder="e.g. Chapter 3 discussion">
                            </label>
                            <div class="folder-form-label">
                                <span>Opening message <small>(optional)</small></span>
                                <div class="quill-wrap"><div class="quill-editor" data-target="topic-body"></div></div>
                                <textarea name="body" id="topic-body" class="rich-textarea" maxlength="20000" hidden></textarea>
                            </div>
                            <button type="submit" class="button">Create topic</button>
                        </form>
                    </details>
                    <?php endif; ?>

                    <article class="card-shell">
                        <div class="section-head">
                            <div>
                                <p class="eyebrow">Discussions</p>
                                <h3 class="card-title">Topics</h3>
                            </div>
                            <span class="chip"><?= count($dbTopics) ?></span>
                        </div>
                        <?php if (empty($dbTopics)): ?>
                            <p style="margin:0;color:var(--muted);font-size:.9rem;">No topics yet.</p>
                        <?php else: ?>
                        <div class="forum-topic-list">
                            <?php foreach ($dbTopics as $topic): ?>
                            <a class="forum-topic-row" href="course.php?course=<?= urlencode($slug) ?>&section=discussions&topic=<?= (int)$topic['id'] ?>">
                                <div class="forum-topic-row-avatar course-staff-avatar"><?= portal_escape($topic['author_initials']) ?></div>
                                <div class="forum-topic-row-info">
                                    <strong><?= portal_escape($topic['title']) ?></strong>
                                    <span>by <?= portal_escape($topic['author_name']) ?> &middot; <?= portal_escape(date('j M Y', strtotime($topic['created_at']))) ?></span>
                                </div>
                                <span class="chip"><?= (int)$topic['reply_count'] ?> <?= (int)$topic['reply_count'] === 1 ? 'reply' : 'replies' ?></span>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </article>
                <?php endif; ?>
