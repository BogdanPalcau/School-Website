                <!-- ── Groups section ──────────────────────────────────────── -->
                <?php if (portal_can_manage_course($courseId)): ?>
                <details class="folder-admin-panel">
                    <summary class="folder-admin-trigger">
                        <?= portal_icon('plus', 'icon-sm') ?> <span>Create group</span>
                    </summary>
                    <form method="POST" class="folder-admin-form">
                        <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                        <input type="hidden" name="action" value="create_group">
                        <div class="folder-form-row">
                            <label class="folder-form-label">
                                <span>Group name</span>
                                <input type="text" name="title" required maxlength="150" placeholder="e.g. Project Group A">
                            </label>
                            <label class="folder-form-label">
                                <span>Max members <small>(0 = unlimited)</small></span>
                                <input type="number" name="max_members" value="0" min="0" max="999">
                            </label>
                        </div>
                        <label class="folder-form-label">
                            <span>Description <small>(optional)</small></span>
                            <input type="text" name="description" maxlength="400" placeholder="What this group is for">
                        </label>
                        <button type="submit" class="button">Create</button>
                    </form>
                </details>
                <?php endif; ?>

                <?php if (empty($dbGroups)): ?>
                    <article class="folder-empty-state">
                        <?= portal_icon('users', 'folder-empty-icon') ?>
                        <p>No groups yet<?= portal_can_manage_course($courseId) ? '. Create one above.' : '.' ?></p>
                    </article>
                <?php else: ?>
                <?php $alreadyInAGroup = !empty($myGroupIds) && !portal_can_manage_course($courseId); ?>
                <?php if ($alreadyInAGroup): ?>
                    <p class="group-single-note">You can only be in one group on this module. Leave your current group before joining another.</p>
                <?php endif; ?>
                <div class="groups-grid">
                    <?php foreach ($dbGroups as $group): ?>
                    <?php
                        $gid       = (int)$group['id'];
                        $memberCnt = (int)$group['member_count'];
                        $maxM      = (int)$group['max_members'];
                        $isMember  = in_array($gid, $myGroupIds, true);
                        $isFull    = $maxM > 0 && $memberCnt >= $maxM;
                        $members   = $groupMembers[$gid] ?? [];
                    ?>
                    <article class="group-card">
                        <div class="group-card-head">
                            <h3><?= portal_escape($group['title']) ?></h3>
                            <span class="chip"><?= $memberCnt ?><?= $maxM > 0 ? ' / ' . $maxM : '' ?></span>
                        </div>
                        <p class="group-desc<?= trim((string) $group['description']) === '' ? ' is-empty' : '' ?>"><?= portal_escape((string) $group['description']) ?></p>

                        <?php if (portal_can_manage_course($courseId)): ?>
                            <?php if (!empty($members)): ?>
                            <div class="group-members-list">
                                <?php foreach ($members as $m): ?>
                                <span class="group-member-chip">
                                    <span class="course-staff-avatar sub-avatar"><?= portal_escape($m['initials']) ?></span>
                                    <?= portal_escape($m['name']) ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <form method="POST" class="group-delete-form">
                                <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                <input type="hidden" name="action" value="delete_group">
                                <input type="hidden" name="group_id" value="<?= $gid ?>">
                                <button type="submit" class="btn-danger-sm"><?= portal_icon('trash','icon-sm') ?> Delete</button>
                            </form>
                        <?php elseif ($isMember): ?>
                            <div class="group-joined-badge">✓ Joined</div>
                            <form method="POST">
                                <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                <input type="hidden" name="action" value="leave_group">
                                <input type="hidden" name="group_id" value="<?= $gid ?>">
                                <button type="submit" class="btn-danger-sm">Leave</button>
                            </form>
                        <?php elseif ($alreadyInAGroup): ?>
                            <span class="group-locked-badge">Leave your current group to join this one</span>
                        <?php elseif (!$isFull): ?>
                            <form method="POST">
                                <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                <input type="hidden" name="action" value="join_group">
                                <input type="hidden" name="group_id" value="<?= $gid ?>">
                                <button type="submit" class="button button--sm">Join</button>
                            </form>
                        <?php else: ?>
                            <span class="group-full-badge">Group full</span>
                        <?php endif; ?>
                    </article>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
