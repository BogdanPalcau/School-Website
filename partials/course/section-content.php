                <?php if (portal_can_manage_course($courseId)): ?>
                    <details class="folder-admin-panel">
                        <summary class="folder-admin-trigger">
                            <?= portal_icon('plus', 'icon-sm') ?>
                            <span>New folder</span>
                        </summary>
                        <form method="POST" class="folder-admin-form">
                            <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                            <input type="hidden" name="action" value="create_folder">
                            <div class="folder-form-row">
                                <label class="folder-form-label">
                                    <span>Folder name</span>
                                    <input type="text" name="title" required maxlength="200" placeholder="e.g. Assessment 1">
                                </label>
                                <label class="folder-form-label">
                                    <span>Description <small>(optional)</small></span>
                                    <input type="text" name="description" maxlength="500" placeholder="Brief description shown under the folder name">
                                </label>
                            </div>
                            <label class="folder-form-label folder-form-check">
                                <input type="checkbox" name="locked" value="1">
                                Lock this folder <small style="font-weight:400">(students can see the folder, but not its contents)</small>
                            </label>
                            <button type="submit" class="button">Create folder</button>
                        </form>
                    </details>
                <?php endif; ?>

                <?php if (empty($courseFolders)): ?>
                    <?php if (portal_can_manage_course($courseId)): ?>
                        <article class="folder-empty-state">
                            <?= portal_icon('folder', 'folder-empty-icon') ?>
                            <p>No folders yet. Create the first folder above to organise course materials.</p>
                        </article>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="folder-stack" id="folder-stack">
                    <?php foreach ($courseFolders as $folder): ?>
                        <?php $folderLocked = !empty($folder['locked']); ?>
                        <div class="folder-row" data-folder-id="<?= (int) $folder['id'] ?>">
                        <?php if (portal_can_manage_course($courseId)): ?>
                            <div class="course-move-btns" data-course-move="folder">
                                <button type="button" class="course-move-btn" data-course-move-dir="up" aria-label="Move folder up" title="Move up">
                                    <?= portal_icon('chevron-up', 'icon-sm') ?>
                                </button>
                                <button type="button" class="course-move-btn" data-course-move-dir="down" aria-label="Move folder down" title="Move down">
                                    <?= portal_icon('chevron-down', 'icon-sm') ?>
                                </button>
                            </div>
                        <?php endif; ?>
                        <details class="folder-card<?= portal_can_manage_course($courseId) ? ' folder-card--managed' : '' ?><?= $folderLocked ? ' folder-card--locked' : '' ?>">
                            <summary class="folder-summary">
                                <?php if (portal_can_manage_course($courseId)): ?>
                                    <span class="folder-drag-handle" title="Drag to reorder">
                                        <?= portal_icon('grip', 'grip-icon') ?>
                                    </span>
                                <?php endif; ?>
                                <span class="folder-status-dot"></span>
                                <?= portal_icon('folder', 'folder-icon') ?>
                                <div class="folder-info">
                                    <h3><?= portal_escape($folder['title']) ?></h3>
                                    <?php if ($folder['description'] !== ''): ?>
                                        <p><?= portal_escape($folder['description']) ?></p>
                                    <?php endif; ?>
                                    <?php if ($folderLocked): ?>
                                        <span class="folder-lock-badge">Locked</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (portal_can_manage_course($courseId)): ?>
                                    <button type="button"
                                            class="folder-lock-toggle<?= $folderLocked ? ' is-locked' : '' ?>"
                                            data-folder-id="<?= (int)$folder['id'] ?>"
                                            title="<?= $folderLocked ? 'Unlock folder' : 'Lock folder' ?>"
                                            aria-label="<?= $folderLocked ? 'Unlock folder' : 'Lock folder' ?>">
                                        <?= portal_icon('lock', 'icon-sm') ?>
                                    </button>
                                    <button type="button"
                                            class="folder-settings-button settings-toggle"
                                            data-settings-target="folder-settings-<?= (int)$folder['id'] ?>"
                                            aria-label="Folder settings">
                                        <?= portal_icon('settings', 'icon-sm') ?>
                                    </button>
                                <?php endif; ?>
                                <?= portal_icon('chevron-down', 'folder-chevron') ?>
                            </summary>

                            <div class="folder-body">
                                <?php if (portal_can_manage_course($courseId)): ?>
                                    <div class="settings-panel folder-settings-panel" id="folder-settings-<?= (int)$folder['id'] ?>" hidden>
                                        <form method="POST" class="folder-admin-form folder-admin-form--inner">
                                            <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                            <input type="hidden" name="action" value="update_folder_settings">
                                            <input type="hidden" name="folder_id" value="<?= (int)$folder['id'] ?>">
                                            <div class="folder-form-row">
                                                <label class="folder-form-label">
                                                    <span>Folder name</span>
                                                    <input type="text" name="title" required maxlength="200" value="<?= portal_escape($folder['title']) ?>">
                                                </label>
                                                <label class="folder-form-label">
                                                    <span>Description <small>(optional)</small></span>
                                                    <input type="text" name="description" maxlength="500" value="<?= portal_escape($folder['description']) ?>">
                                                </label>
                                            </div>
                                            <label class="folder-form-label folder-form-check">
                                                <input type="checkbox" name="locked" value="1" <?= $folderLocked ? 'checked' : '' ?>>
                                                Lock folder <small style="font-weight:400">(students cannot open the contents)</small>
                                            </label>
                                            <button type="submit" class="button button--sm">Save folder settings</button>
                                        </form>
                                    </div>
                                <?php endif; ?>

                                <?php if ($folderLocked && !portal_can_manage_course($courseId)): ?>
                                    <p class="folder-locked-note">This folder is locked by your teacher.</p>
                                <?php elseif (portal_can_manage_course($courseId) || !empty($folder['items'])): ?>
                                    <div class="folder-items" data-folder-id="<?= (int) $folder['id'] ?>">
                                        <?php foreach ($folder['items'] as $item): ?>
                                            <?php
                                                $itemFileName = $item['file_name'] !== '' ? $item['file_name'] : $item['file_path'];
                                                $isPresentation = $item['type'] === 'document' && portal_is_presentation_file((string) $itemFileName);
                                                $itemKindClass = $isPresentation ? 'presentation' : $item['type'];
                                                $itemKindLabel = $isPresentation
                                                    ? 'Presentation'
                                                    : ($item['type'] === 'submission' ? 'Submission slot'
                                                        : ($item['type'] === 'activity' ? 'Activity' : ucfirst($item['type'])));
                                                $itemLocked = !empty($item['locked']);
                                                $itemExternalUrl = (!$itemLocked && $item['type'] === 'link')
                                                    ? portal_course_normalize_external_url((string) $item['url'])
                                                    : '';
                                                $activityRow = null;
                                                $activitySummary = null;
                                                if ($item['type'] === 'activity') {
                                                    $activityRow = portal_activity_find_by_item((int) $item['id']);
                                                    // Draft, archived, and orphaned activity slots are staff-only.
                                                    // Do not leak their title or even their existence to students.
                                                    if (!portal_can_manage_course($courseId)
                                                        && (!$activityRow || (string) ($activityRow['status'] ?? '') !== 'published')) {
                                                        continue;
                                                    }
                                                    if ($activityRow) {
                                                        $activitySummary = portal_activity_student_card_summary(
                                                            $activityRow,
                                                            (int) (portal_current_user()['id'] ?? 0)
                                                        );
                                                        $itemKindLabel = portal_activity_mode_label((string) $activityRow['mode']);
                                                    }
                                                }
                                            ?>
                                            <div class="folder-item folder-item--<?= portal_escape($itemKindClass) ?><?= portal_can_manage_course($courseId) ? ' folder-item--managed' : '' ?><?= ($itemLocked && !portal_can_manage_course($courseId)) ? ' folder-item--student-locked' : '' ?><?= $itemExternalUrl !== '' ? ' folder-item--external-link' : '' ?>"
                                                 data-item-id="<?= (int) $item['id'] ?>"
                                                 data-folder-id="<?= (int) $folder['id'] ?>"
                                                 <?php if ($itemExternalUrl !== ''): ?>
                                                 data-safe-external-link="1"
                                                 data-safe-url="<?= portal_escape($itemExternalUrl) ?>"
                                                 role="link"
                                                 tabindex="0"
                                                 <?php endif; ?>>
                                                <?php if (portal_can_manage_course($courseId)): ?>
                                                    <span class="item-drag-handle" title="Drag to reorder">
                                                        <?= portal_icon('grip', 'grip-icon') ?>
                                                    </span>
                                                    <div class="course-move-btns" data-course-move="item">
                                                        <button type="button" class="course-move-btn" data-course-move-dir="up" aria-label="Move item up" title="Move up">
                                                            <?= portal_icon('chevron-up', 'icon-sm') ?>
                                                        </button>
                                                        <button type="button" class="course-move-btn" data-course-move-dir="down" aria-label="Move item down" title="Move down">
                                                            <?= portal_icon('chevron-down', 'icon-sm') ?>
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($isPresentation): ?>
                                                    <?= portal_icon('presentation', 'item-type-icon') ?>
                                                <?php elseif ($item['type'] === 'document'): ?>
                                                    <?= portal_icon('file', 'item-type-icon') ?>
                                                <?php elseif ($item['type'] === 'video'): ?>
                                                    <?= portal_icon('video', 'item-type-icon') ?>
                                                <?php elseif ($item['type'] === 'link'): ?>
                                                    <?= portal_icon('link', 'item-type-icon') ?>
                                                <?php elseif ($item['type'] === 'activity'): ?>
                                                    <?= portal_icon('activity', 'item-type-icon') ?>
                                                <?php else: ?>
                                                    <?= portal_icon('upload', 'item-type-icon') ?>
                                                <?php endif; ?>

                                                <div class="folder-item-info">
                                                    <?php if ($itemLocked && !portal_can_manage_course($courseId)): ?>
                                                        <div class="item-locked-state">
                                                            <span class="item-locked-title"><?= portal_escape($item['title']) ?></span>
                                                            <span class="item-locked-badge">
                                                                <?= portal_icon('lock', 'icon-xs') ?>
                                                                Locked
                                                            </span>
                                                        </div>
                                                    <?php else: ?>
                                                    <?php if ($item['file_path'] !== ''): ?>
                                                        <?php
                                                            $fExt  = strtolower(pathinfo((string) $itemFileName, PATHINFO_EXTENSION));
                                                            $canDl = !portal_can_manage_course($courseId) && !empty($item['allow_download']);
                                                            $isVideoItem = $item['type'] === 'video';
                                                            $itemViewerUrl = $isVideoItem
                                                                ? 'lesson-viewer.php?item=' . (int) $item['id']
                                                                : 'view.php?item=' . (int) $item['id'];
                                                        ?>
                                                        <div class="file-item-row">
                                                            <a href="<?= portal_escape($itemViewerUrl) ?>"
                                                               class="file-view-link"
                                                               <?php if ($isVideoItem): ?>target="_blank"<?php else: ?>data-doc-viewer="1"<?php endif; ?>>
                                                                <?= portal_icon($isVideoItem ? 'video' : ($isPresentation ? 'presentation' : 'file'), 'icon-xs') ?>
                                                                <?= portal_escape($item['title']) ?>
                                                                <span class="file-ext-badge"><?= portal_escape(strtoupper($fExt)) ?></span>
                                                            </a>
                                                            <?php if ($canDl): ?>
                                                            <a href="download.php?item=<?= (int)$item['id'] ?>" class="btn-file-dl" title="Download" download>
                                                                <?= portal_icon('download', 'icon-xs') ?>
                                                            </a>
                                                            <?php endif; ?>
                                                            <?php if (portal_can_manage_course($courseId)): ?>
                                                            <button type="button"
                                                                    class="btn-dl-toggle<?= !empty($item['allow_download']) ? ' is-enabled' : '' ?>"
                                                                    data-item-id="<?= (int)$item['id'] ?>"
                                                                    title="<?= !empty($item['allow_download']) ? 'Students can download — click to disable' : 'Students cannot download — click to enable' ?>">
                                                                <?= portal_icon('download', 'icon-xs') ?>
                                                            </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php elseif ($item['type'] === 'video' && $item['url'] !== ''): ?>
                                                        <?php $itemVideoMeta = portal_parse_external_video_url((string) $item['url']); ?>
                                                        <div class="file-item-row">
                                                            <a href="lesson-viewer.php?item=<?= (int) $item['id'] ?>" class="file-view-link" target="_blank">
                                                                <?= portal_icon('video', 'icon-xs') ?>
                                                                <?= portal_escape($item['title']) ?>
                                                                <span class="file-ext-badge"><?= portal_escape($itemVideoMeta['label'] ?? 'Video') ?></span>
                                                            </a>
                                                        </div>
                                                    <?php elseif ($item['url'] !== ''): ?>
                                                        <a class="item-url-link"
                                                           href="<?= portal_escape($itemExternalUrl !== '' ? $itemExternalUrl : (string) $item['url']) ?>"
                                                           target="_blank"
                                                           rel="noopener noreferrer"
                                                           data-safe-external-link="1"
                                                           data-safe-url="<?= portal_escape($itemExternalUrl !== '' ? $itemExternalUrl : (string) $item['url']) ?>">
                                                            <?= portal_icon('link', 'icon-xs') ?>
                                                            <?= portal_escape($item['title']) ?>
                                                        </a>
                                                    <?php elseif ($item['type'] === 'activity' && $activityRow): ?>
                                                        <a class="file-view-link" href="activity.php?id=<?= (int) $activityRow['id'] ?>">
                                                            <?= portal_icon('activity', 'icon-xs') ?>
                                                            <?= portal_escape((string) ($activityRow['title'] ?: $item['title'])) ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <strong><?= portal_escape($item['title']) ?></strong>
                                                    <?php endif; ?>
                                                    <?php if ($item['description'] !== ''): ?>
                                                        <p><?= portal_escape($item['description']) ?></p>
                                                    <?php endif; ?>
                                                    <?php if ($item['type'] === 'activity' && $activityRow && $activitySummary): ?>
                                                        <?php $activityCanManage = portal_can_manage_course($courseId); ?>
                                                        <div class="activity-slot-card">
                                                            <div class="activity-slot-row">
                                                                <div class="activity-slot-meta">
                                                                    <span class="activity-mode-pill activity-mode-pill--<?= portal_escape((string) $activitySummary['mode']) ?>">
                                                                        <?= portal_escape((string) $activitySummary['mode_label']) ?>
                                                                    </span>
                                                                    <?php if ($activityCanManage): ?>
                                                                        <?php $activityStatusKey = (string) ($activitySummary['status'] ?? 'draft'); ?>
                                                                        <span class="activity-status activity-status--<?= portal_escape($activityStatusKey === 'published' ? 'completed' : ($activityStatusKey === 'closed' ? 'submitted' : 'not-started')) ?>">
                                                                            <?= portal_escape(ucfirst($activityStatusKey)) ?>
                                                                        </span>
                                                                    <?php else: ?>
                                                                        <span class="activity-status activity-status--<?= portal_escape(str_replace('_', '-', (string) ($activitySummary['student_status'] ?? 'not-started'))) ?>">
                                                                            <?= portal_escape(ucwords(str_replace('_', ' ', (string) ($activitySummary['student_status'] ?? 'not_started')))) ?>
                                                                        </span>
                                                                    <?php endif; ?>
                                                                    <?php if (!empty($activitySummary['estimated_minutes'])): ?>
                                                                        <span class="activity-slot-note"><?= (int) $activitySummary['estimated_minutes'] ?> min</span>
                                                                    <?php endif; ?>
                                                                    <?php if ($activitySummary['attempts_remaining'] !== null): ?>
                                                                        <span class="activity-slot-note"><?= (int) $activitySummary['attempts_remaining'] ?> left</span>
                                                                    <?php endif; ?>
                                                                    <?php if (!$activityCanManage && $activitySummary['best_percentage'] !== null): ?>
                                                                        <span class="activity-slot-note activity-slot-note--score"><?= portal_escape((string) round((float) $activitySummary['best_percentage'], 1)) ?>%</span>
                                                                    <?php endif; ?>
                                                                    <?php if (!$activityCanManage && !empty($activitySummary['xp_enabled']) && (int) ($activitySummary['xp_amount'] ?? 0) > 0): ?>
                                                                        <span class="activity-slot-note activity-slot-note--xp">+<?= (int) $activitySummary['xp_amount'] ?> XP</span>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <div class="activity-slot-links">
                                                                    <?php if ($activityCanManage): ?>
                                                                        <a class="activity-slot-cta" href="activity-results.php?id=<?= (int) $activityRow['id'] ?>">Submissions</a>
                                                                        <a href="activity-builder.php?id=<?= (int) $activityRow['id'] ?>">Edit</a>
                                                                        <a href="activity.php?id=<?= (int) $activityRow['id'] ?>">Preview</a>
                                                                    <?php elseif ($activitySummary['in_progress_attempt_id']): ?>
                                                                        <a class="activity-slot-cta" href="activity.php?id=<?= (int) $activityRow['id'] ?>&amp;resume=1">Resume</a>
                                                                    <?php elseif ($activitySummary['can_start']): ?>
                                                                        <a class="activity-slot-cta" href="activity.php?id=<?= (int) $activityRow['id'] ?>">Start</a>
                                                                    <?php elseif (in_array($activitySummary['student_status'] ?? '', ['submitted', 'completed', 'awaiting_marking'], true)): ?>
                                                                        <a href="activity.php?id=<?= (int) $activityRow['id'] ?>"><?= portal_escape((string) ($activitySummary['primary_action'] ?? 'View')) ?></a>
                                                                    <?php else: ?>
                                                                        <span class="activity-slot-disabled"><?= portal_escape((string) ($activitySummary['primary_action'] ?? 'Unavailable')) ?></span>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php
                                                        $openQs = ($item['type'] === 'video')
                                                            ? (int) ($videoOpenQuestionCounts[(int) $item['id']] ?? 0)
                                                            : 0;
                                                    ?>
                                                    <?php if ($item['type'] !== 'submission' && $item['type'] !== 'activity' || $openQs > 0): ?>
                                                    <div class="folder-item-meta">
                                                        <?php if ($item['type'] !== 'submission' && $item['type'] !== 'activity'): ?>
                                                        <span class="item-type-badge item-type-badge--<?= portal_escape($itemKindClass) ?>">
                                                            <?= portal_escape($itemKindLabel) ?>
                                                        </span>
                                                        <?php endif; ?>
                                                        <?php if ($openQs > 0 && portal_can_manage_course($courseId)): ?>
                                                        <a class="video-open-q-badge" href="lesson-viewer.php?item=<?= (int) $item['id'] ?>#qa-open" title="Review open student questions">
                                                            <?= portal_icon('megaphone', 'icon-xs') ?>
                                                            <?= $openQs ?> open
                                                        </a>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php endif; ?>

                                                    <?php if (portal_can_manage_course($courseId) && $item['type'] !== 'submission' && $item['type'] !== 'activity'): ?>
                                                        <?php
                                                            $itemDeadlineValue = $item['submission_deadline'] !== ''
                                                                ? date('Y-m-d\TH:i', strtotime((string) $item['submission_deadline']))
                                                                : '';
                                                        ?>
                                                        <div class="settings-panel item-settings-panel" id="item-settings-<?= (int)$item['id'] ?>" hidden>
                                                            <form method="POST" class="folder-admin-form folder-admin-form--inner item-settings-form">
                                                                <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                                                <input type="hidden" name="action" value="update_item_settings">
                                                                <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                                                                <div class="folder-form-row">
                                                                    <label class="folder-form-label">
                                                                        <span>Title</span>
                                                                        <input type="text" name="title" required maxlength="200" value="<?= portal_escape($item['title']) ?>">
                                                                    </label>
                                                                    <label class="folder-form-label">
                                                                        <span>Description <small>(optional)</small></span>
                                                                        <input type="text" name="description" maxlength="500" value="<?= portal_escape($item['description']) ?>">
                                                                    </label>
                                                                </div>
                                                                <?php if ($item['type'] === 'link' || ($item['type'] === 'document' && $item['file_path'] === '')): ?>
                                                                    <label class="folder-form-label">
                                                                        <span>URL</span>
                                                                        <input type="text" inputmode="url" autocomplete="url" name="url" maxlength="2000" value="<?= portal_escape($item['url']) ?>" placeholder="https://... or www.example.com">
                                                                    </label>
                                                                <?php elseif ($item['type'] === 'video' && $item['file_path'] === ''): ?>
                                                                    <label class="folder-form-label">
                                                                        <span>Video link <small>(YouTube or Vimeo only)</small></span>
                                                                        <input type="text" inputmode="url" autocomplete="url" name="url" maxlength="2000" value="<?= portal_escape($item['url']) ?>" placeholder="https://www.youtube.com/watch?v=...">
                                                                    </label>
                                                                <?php endif; ?>
                                                                <?php if (($item['type'] === 'document' || $item['type'] === 'video') && $item['file_path'] !== ''): ?>
                                                                    <label class="folder-form-label folder-form-check">
                                                                        <input type="checkbox" name="allow_download" value="1" <?= !empty($item['allow_download']) ? 'checked' : '' ?>>
                                                                        Allow students to download this file
                                                                    </label>
                                                                <?php endif; ?>
                                                                <button type="submit" class="button button--sm">Save item settings</button>
                                                            </form>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if ($item['type'] === 'submission'): ?>
                                                        <?php
                                                            $itemDeadlineValue = $item['submission_deadline'] !== ''
                                                                ? date('Y-m-d\TH:i', strtotime((string) $item['submission_deadline']))
                                                                : '';
                                                            $deadlineInfo = portal_submission_deadline_info((string) $item['submission_deadline']);
                                                            $deadlinePassed = $deadlineInfo['passed'];
                                                            $modalId = 'sub-slot-modal-' . (int) $item['id'];
                                                            $slotEditPanelId = 'sub-slot-edit-' . (int) $item['id'];
                                                            $slotMaxAttempts = (int) ($item['submission_max_attempts'] ?? 0);
                                                            $slotWeightLabel = portal_format_submission_weight($item['submission_weight'] ?? 100);
                                                            if (portal_can_manage_course($courseId)) {
                                                                $subs = $slotSubmissions[(int)$item['id']] ?? [];
                                                            } else {
                                                                $mySub = $mySubmissions[(int)$item['id']] ?? null;
                                                                $mySubAttemptsUsed = $mySub ? count($submissionVersions[(int) $mySub['id']] ?? []) : 0;
                                                                $attemptsReached = $slotMaxAttempts > 0 && $mySubAttemptsUsed >= $slotMaxAttempts;
                                                            }
                                                        ?>
                                                        <div class="sub-slot-card"
                                                             data-sub-modal="<?= portal_escape($modalId) ?>"
                                                             data-item-id="<?= (int) $item['id'] ?>"
                                                             role="button"
                                                             tabindex="0"
                                                             aria-label="Open submission details for <?= portal_escape($item['title']) ?>">
                                                            <?= portal_render_submission_deadline((string) $item['submission_deadline']) ?>
                                                            <?php if (portal_can_manage_course($courseId)): ?>
                                                                <div class="sub-slot-card-row sub-slot-card-row--manage">
                                                                    <div class="sub-slot-card-meta-line">
                                                                        <span class="sub-slot-file">
                                                                            <?= portal_icon('upload', 'icon-xs') ?>
                                                                            <span><?= count($subs) ?> submission<?= count($subs) !== 1 ? 's' : '' ?></span>
                                                                        </span>
                                                                        <?php if ($slotMaxAttempts > 0): ?>
                                                                        <span class="sub-slot-attempts-limit">Max <?= $slotMaxAttempts ?> attempt<?= $slotMaxAttempts === 1 ? '' : 's' ?></span>
                                                                        <?php endif; ?>
                                                                        <span class="sub-slot-weight">Weight <?= portal_escape($slotWeightLabel) ?></span>
                                                                    </div>
                                                                    <button type="button"
                                                                            class="button button--sm sub-slot-card-edit"
                                                                            data-sub-open-edit="<?= portal_escape($modalId) ?>"
                                                                            data-sub-open-edit-form="1"
                                                                            title="Edit slot">
                                                                        <?= portal_icon('edit', 'icon-xs') ?>
                                                                        Edit slot
                                                                    </button>
                                                                </div>
                                                            <?php else: ?>
                                                                <div class="sub-slot-card-row" data-sub-card-row>
                                                                    <?php if ($mySub): ?>
                                                                        <span class="sub-slot-file" data-sub-card-file>
                                                                            <?= portal_icon('file', 'icon-xs') ?>
                                                                            <span><?= portal_escape($mySub['filename']) ?></span>
                                                                        </span>
                                                                        <?php if ($mySub && portal_submission_is_marked($mySub) && portal_submission_grades_released($mySub)): ?>
                                                                            <span class="sub-slot-status sub-slot-status--graded" data-sub-card-status><?= (int)$mySub['score'] ?>%</span>
                                                                        <?php elseif ($mySub && portal_submission_is_marked($mySub)): ?>
                                                                            <span class="sub-slot-status sub-slot-status--pending" data-sub-card-status>Awaiting release</span>
                                                                        <?php else: ?>
                                                                            <span class="sub-slot-status sub-slot-status--pending" data-sub-card-status><?= $mySub ? 'Not graded' : '' ?></span>
                                                                        <?php endif; ?>
                                                                    <?php else: ?>
                                                                        <span class="sub-slot-file sub-slot-file--empty" data-sub-card-file>
                                                                            <?= portal_icon('upload', 'icon-xs') ?>
                                                                            <span><?= $deadlinePassed ? 'No submission' : 'Not submitted yet' ?></span>
                                                                        </span>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if (!portal_can_manage_course($courseId) && $mySub): ?>
                                                            <p class="sub-slot-card-meta" data-sub-card-meta>
                                                                Submitted <?= portal_escape(date('j M Y H:i', strtotime($mySub['submitted_at']))) ?>
                                                            </p>
                                                            <?php elseif (!portal_can_manage_course($courseId)): ?>
                                                            <p class="sub-slot-card-meta is-hidden" data-sub-card-meta></p>
                                                            <?php endif; ?>
                                                            <?php if (!portal_can_manage_course($courseId) && $slotMaxAttempts > 0): ?>
                                                            <p class="sub-slot-attempts-note" data-sub-attempts-note data-max-attempts="<?= $slotMaxAttempts ?>">
                                                                Attempt <?= min($mySubAttemptsUsed, $slotMaxAttempts) ?> of <?= $slotMaxAttempts ?> used
                                                            </p>
                                                            <?php endif; ?>
                                                        </div>

                                                        <?php ob_start(); ?>
                                                        <div id="<?= portal_escape($modalId) ?>"
                                                             class="sub-slot-overlay"
                                                             data-item-id="<?= (int) $item['id'] ?>"
                                                             hidden
                                                             role="dialog"
                                                             aria-modal="true"
                                                             aria-labelledby="<?= portal_escape($modalId) ?>-title"
                                                             ondragenter="if(window.portalUploadDragOver){window.portalUploadDragOver(event);}else{event.preventDefault();}"
                                                             ondragover="if(window.portalUploadDragOver){window.portalUploadDragOver(event);}else{event.preventDefault();if(event.dataTransfer)event.dataTransfer.dropEffect='copy';}"
                                                             ondrop="if(window.portalUploadDrop){window.portalUploadDrop(event);}else{event.preventDefault();}">
                                                            <div class="sub-slot-dialog"
                                                                 ondragenter="event.preventDefault();"
                                                                 ondragover="event.preventDefault();if(event.dataTransfer)event.dataTransfer.dropEffect='copy';"
                                                                 ondrop="if(window.portalUploadDrop){window.portalUploadDrop(event);}else{event.preventDefault();}">
                                                                <header class="sub-slot-dialog-header">
                                                                    <div class="sub-slot-dialog-heading">
                                                                        <p class="eyebrow">Submission</p>
                                                                        <h3 id="<?= portal_escape($modalId) ?>-title"><?= portal_escape($item['title']) ?></h3>
                                                                        <?= portal_render_submission_deadline((string) $item['submission_deadline'], 'sub-slot-deadline--header') ?>
                                                                        <?php if ($slotMaxAttempts > 0): ?>
                                                                        <p class="sub-slot-attempts-note sub-slot-attempts-note--header" <?= portal_can_manage_course($courseId) ? '' : 'data-sub-attempts-note data-max-attempts="' . $slotMaxAttempts . '"' ?>>
                                                                            <?= portal_can_manage_course($courseId) ? 'Max ' . $slotMaxAttempts . ' attempt' . ($slotMaxAttempts === 1 ? '' : 's') . ' per student' : 'Attempt ' . min($mySubAttemptsUsed, $slotMaxAttempts) . ' of ' . $slotMaxAttempts . ' used' ?>
                                                                        </p>
                                                                        <?php endif; ?>
                                                                        <?php if (portal_can_manage_course($courseId)): ?>
                                                                        <p class="sub-slot-attempts-note sub-slot-attempts-note--header">Weight <?= portal_escape($slotWeightLabel) ?></p>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    <div class="sub-slot-dialog-header-actions">
                                                                        <?php if (portal_can_manage_course($courseId)): ?>
                                                                        <button type="button"
                                                                                class="button button--sm settings-toggle"
                                                                                data-settings-target="<?= portal_escape($slotEditPanelId) ?>"
                                                                                aria-label="Edit slot">
                                                                            <?= portal_icon('edit', 'icon-xs') ?>
                                                                            Edit slot
                                                                        </button>
                                                                        <?php endif; ?>
                                                                        <button type="button" class="sub-slot-dialog-close" aria-label="Close">&times;</button>
                                                                    </div>
                                                                </header>

                                                                <div class="sub-slot-dialog-body">
                                                                <?php if (portal_can_manage_course($courseId)): ?>
                                                                    <div class="settings-panel sub-slot-edit-panel" id="<?= portal_escape($slotEditPanelId) ?>" data-sub-slot-edit-panel hidden>
                                                                        <form method="POST" class="folder-admin-form folder-admin-form--inner">
                                                                            <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                                                            <input type="hidden" name="action" value="update_item_settings">
                                                                            <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                                                                            <div class="folder-form-row">
                                                                                <label class="folder-form-label">
                                                                                    <span>Title</span>
                                                                                    <input type="text" name="title" required maxlength="200" value="<?= portal_escape($item['title']) ?>">
                                                                                </label>
                                                                                <label class="folder-form-label">
                                                                                    <span>Description <small>(optional)</small></span>
                                                                                    <input type="text" name="description" maxlength="500" value="<?= portal_escape($item['description']) ?>">
                                                                                </label>
                                                                            </div>
                                                                            <div class="folder-form-row">
                                                                                <label class="folder-form-label">
                                                                                    <span>Deadline <small>(optional — leave blank for none)</small></span>
                                                                                    <input type="datetime-local" name="submission_deadline" value="<?= portal_escape($itemDeadlineValue) ?>">
                                                                                </label>
                                                                                <label class="folder-form-label">
                                                                                    <span>Grade weight <small>(%)</small></span>
                                                                                    <input type="number" name="submission_weight" min="0" max="100" step="0.01" inputmode="decimal" value="<?= portal_escape(portal_format_submission_weight($item['submission_weight'] ?? 100, false)) ?>">
                                                                                </label>
                                                                                <label class="folder-form-label">
                                                                                    <span>Re-submissions allowed</span>
                                                                                    <select name="submission_max_attempts">
                                                                                        <option value="0" <?= (int) ($item['submission_max_attempts'] ?? 0) === 0 ? 'selected' : '' ?>>Unlimited</option>
                                                                                        <option value="1" <?= (int) ($item['submission_max_attempts'] ?? 0) === 1 ? 'selected' : '' ?>>1 (no resubmission)</option>
                                                                                        <option value="2" <?= (int) ($item['submission_max_attempts'] ?? 0) === 2 ? 'selected' : '' ?>>2</option>
                                                                                    </select>
                                                                                </label>
                                                                                <?php if ($showExternalAiSlotOption): ?>
                                                                                <label class="folder-form-label folder-form-check">
                                                                                    <input type="checkbox" name="submission_ai_detection" value="1" <?= !empty($item['submission_ai_detection']) ? 'checked' : '' ?>>
                                                                                    External AI detection <small style="font-weight:400">(GPTZero for this assignment)</small>
                                                                                </label>
                                                                                <?php elseif ($externalAiSiteWide): ?>
                                                                                <p class="submit-hint" style="margin:0;">External AI detection is enabled site-wide for all submissions.</p>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                            <div class="button-row">
                                                                                <button type="submit" class="button button--sm">Save slot settings</button>
                                                                                <button type="button"
                                                                                        class="button-secondary button--sm settings-toggle"
                                                                                        data-settings-target="<?= portal_escape($slotEditPanelId) ?>">Cancel</button>
                                                                            </div>
                                                                        </form>
                                                                    </div>
                                                                    <?php if (!empty($subs)): ?>
                                                                        <p class="sub-modal-count"><?= count($subs) ?> submission<?= count($subs) !== 1 ? 's' : '' ?></p>
                                                                        <?php foreach ($subs as $sub): ?>
                                                                        <article class="sub-modal-entry sub-modal-entry--row" data-submission-id="<?= (int) $sub['id'] ?>">
                                                                            <div class="sub-modal-entry-who">
                                                                                <div class="course-staff-avatar sub-avatar"><?= portal_escape($sub['student_initials']) ?></div>
                                                                                <div>
                                                                                    <strong><?= portal_escape($sub['student_name']) ?></strong>
                                                                                    <span class="sub-date"><?= portal_escape(date('j M Y H:i', strtotime($sub['submitted_at']))) ?> &middot; <?= portal_escape($sub['filename']) ?></span>
                                                                                </div>
                                                                            </div>
                                                                            <div class="sub-modal-entry-end">
                                                                                <?php if (portal_submission_is_marked($sub) && portal_submission_grades_released($sub)): ?>
                                                                                    <span class="sub-modal-grade sub-modal-grade--marked" data-grade-status><?= (int)$sub['score'] ?><small>/100</small></span>
                                                                                <?php elseif (portal_submission_is_marked($sub)): ?>
                                                                                    <span class="sub-modal-grade sub-modal-grade--held" data-grade-status><?= (int)$sub['score'] ?><small>/100 held</small></span>
                                                                                <?php else: ?>
                                                                                    <span class="sub-modal-grade sub-modal-grade--pending" data-grade-status>Not graded</span>
                                                                                <?php endif; ?>
                                                                                <button type="button" class="button button--sm" data-review-open="rvw-<?= (int)$sub['id'] ?>">Open review</button>
                                                                                <form method="POST" class="sub-delete-form" onsubmit="return confirm('Remove this submission?');">
                                                                                    <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                                                                    <input type="hidden" name="action" value="delete_submission">
                                                                                    <input type="hidden" name="submission_id" value="<?= (int)$sub['id'] ?>">
                                                                                    <button type="submit" class="btn-icon-danger" title="Remove submission">
                                                                                        <?= portal_icon('trash', 'icon-sm') ?>
                                                                                    </button>
                                                                                </form>
                                                                            </div>
                                                                        </article>
                                                                        <?php endforeach; ?>
                                                                    <?php else: ?>
                                                                        <p class="sub-empty">No submissions yet.</p>
                                                                    <?php endif; ?>

                                                                <?php else: ?>
                                                                    <div class="sub-modal-student" data-student-sub-modal>
                                                                    <div class="sub-modal-mine<?= $mySub ? ' is-visible' : '' ?>" data-sub-mine>
                                                                        <div class="sub-modal-mine-info">
                                                                            <span class="sub-slot-file" data-sub-filename><?= portal_icon('file', 'icon-xs') ?><span><?= $mySub ? portal_escape($mySub['filename']) : '' ?></span></span>
                                                                            <span class="sub-modal-mine-meta" data-sub-date><?= $mySub ? 'Submitted ' . portal_escape(date('j M Y H:i', strtotime($mySub['submitted_at']))) : '' ?></span>
                                                                        </div>
                                                                        <div class="sub-modal-mine-end">
                                                                            <span class="sub-modal-grade<?php
                                                                                $mineReleased = $mySub && portal_submission_is_marked($mySub) && portal_submission_grades_released($mySub);
                                                                                echo $mineReleased ? ' sub-modal-grade--marked' : ' sub-modal-grade--pending';
                                                                            ?>" data-sub-grade-badge><?php
                                                                                if ($mineReleased) {
                                                                                    echo (int) $mySub['score'] . '<small>/100</small>';
                                                                                } elseif ($mySub && portal_submission_is_marked($mySub)) {
                                                                                    echo 'Awaiting release';
                                                                                } else {
                                                                                    echo 'Not graded';
                                                                                }
                                                                            ?></span>
                                                                            <?php if ($mySub): ?>
                                                                            <button type="button" class="button button--sm" data-review-open="rvw-<?= (int)$mySub['id'] ?>" data-sub-review-btn>Open review</button>
                                                                            <?php else: ?>
                                                                            <button type="button" class="button button--sm is-hidden" data-review-open="" data-sub-review-btn>Open review</button>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>
                                                                    <div class="sub-submit-success is-hidden" data-sub-success role="status"></div>

                                                                    <?php if ($deadlinePassed): ?>
                                                                        <?php if (!$mySub): ?>
                                                                            <p class="sub-empty sub-empty--closed">This submission slot is closed.</p>
                                                                        <?php endif; ?>
                                                                    <?php elseif ($attemptsReached): ?>
                                                                        <p class="sub-empty sub-empty--closed">You have used all <?= $slotMaxAttempts ?> allowed submission attempt<?= $slotMaxAttempts === 1 ? '' : 's' ?> for this assignment.</p>
                                                                    <?php else: ?>
                                                                    <section class="sub-modal-section sub-modal-section--submit" data-sub-submit-section>
                                                                        <p class="sub-empty sub-empty--closed is-hidden" data-sub-attempts-closed-msg><?= $slotMaxAttempts > 0 ? 'You have used all ' . $slotMaxAttempts . ' allowed submission attempt' . ($slotMaxAttempts === 1 ? '' : 's') . ' for this assignment.' : '' ?></p>
                                                                        <div data-sub-submit-fields>
                                                                        <h4 class="sub-modal-section-title" data-sub-submit-title><?= $mySub ? 'Re-submit work' : 'Submit work' ?></h4>
                                                                        <form method="POST" enctype="multipart/form-data" class="submit-work-form submit-work-form--modal" data-item-id="<?= (int) $item['id'] ?>">
                                                                            <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                                                            <input type="hidden" name="action" value="submit_work">
                                                                            <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                                                            <input type="hidden" name="process_edit_seconds" value="0">
                                                                            <input type="hidden" name="process_paste_events" value="0">
                                                                            <input type="hidden" name="process_pasted_chars" value="0">
                                                                            <div class="sub-submit-error" data-sub-error role="alert"></div>
                                                                            <label class="submit-file-label">
                                                                                <span class="submit-hint">1. Choose file type <small>(or skip — dropping a file detects it)</small></span>
                                                                                <select name="submission_type" required data-sub-type-select>
                                                                                    <option value="">Select type…</option>
                                                                                    <?php foreach (portal_submission_type_labels() as $typeKey => $typeLabel): ?>
                                                                                    <option value="<?= portal_escape($typeKey) ?>"><?= portal_escape($typeLabel) ?></option>
                                                                                    <?php endforeach; ?>
                                                                                </select>
                                                                            </label>
                                                                            <p class="sub-pptx-note is-hidden" data-sub-pptx-note role="note">
                                                                                <strong>PowerPoint note:</strong>
                                                                                Teachers review presentations by downloading the file and opening it in PowerPoint (or similar). There is no in-browser slide preview. AI detection is disabled for this file type.
                                                                            </p>
                                                                            <div class="submit-file-label">
                                                                                <span class="submit-hint" id="sub-file-hint-<?= (int) $item['id'] ?>">2. Drop or choose a file (max 40 MB)</span>
                                                                                <div class="upload-dropzone" data-upload-dropzone data-upload-autodetect-type
                                                                                     aria-labelledby="sub-file-hint-<?= (int) $item['id'] ?>"
                                                                                     ondragenter="event.preventDefault(); this.classList.add('is-dragover');"
                                                                                     ondragover="event.preventDefault(); event.stopPropagation(); if(event.dataTransfer)event.dataTransfer.dropEffect='copy'; this.classList.add('is-dragover');"
                                                                                     ondragleave="this.classList.remove('is-dragover');"
                                                                                     ondrop="event.preventDefault(); event.stopPropagation(); this.classList.remove('is-dragover'); if(window.portalUploadDrop)window.portalUploadDrop(event);">
                                                                                    <input type="file" name="submission_file" id="sub-file-<?= (int) $item['id'] ?>"
                                                                                           data-sub-file-input data-upload-input>
                                                                                    <div class="upload-dropzone-ui">
                                                                                        <strong class="upload-dropzone-title">Drop a file here</strong>
                                                                                        <span class="upload-dropzone-sub">Type is filled in automatically if empty</span>
                                                                                        <label class="upload-dropzone-browse" for="sub-file-<?= (int) $item['id'] ?>">Browse files</label>
                                                                                        <div class="upload-dropzone-file-row is-hidden" data-upload-file-row>
                                                                                            <span class="upload-dropzone-file" data-upload-filename></span>
                                                                                            <button type="button" class="upload-dropzone-clear" data-upload-clear title="Remove file" aria-label="Remove file">
                                                                                                <?= portal_icon('trash', 'icon-xs') ?>
                                                                                            </button>
                                                                                        </div>
                                                                                    </div>
                                                                                    <div class="upload-progress is-hidden" data-upload-progress aria-live="polite">
                                                                                        <div class="upload-progress-track">
                                                                                            <div class="upload-progress-bar" data-upload-progress-bar></div>
                                                                                        </div>
                                                                                        <span class="upload-progress-label" data-upload-progress-label>0%</span>
                                                                                    </div>
                                                                                    <p class="upload-dropzone-error is-hidden" data-upload-error role="alert"></p>
                                                                                </div>
                                                                            </div>
                                                                            <label class="submit-file-label">
                                                                                <span class="submit-hint">Or paste your text</span>
                                                                                <textarea name="submission_text" rows="6" maxlength="200000" placeholder="Paste your work here if you are not uploading a file." data-sub-text-counter data-min-words="<?= (int) portal_submission_min_words() ?>"></textarea>
                                                                                <span class="submit-word-count" data-sub-word-count></span>
                                                                            </label>
                                                                            <div class="sub-receipt-card is-hidden" data-sub-receipt-card hidden>
                                                                                <p class="sub-receipt-card__eyebrow">Submission receipt</p>
                                                                                <p class="sub-receipt-card__number" data-sub-receipt-number></p>
                                                                                <dl class="sub-receipt-card__meta">
                                                                                    <div><dt>Student</dt><dd data-sub-receipt-student></dd></div>
                                                                                    <div><dt>Course</dt><dd data-sub-receipt-course></dd></div>
                                                                                    <div><dt>Assignment</dt><dd data-sub-receipt-assignment></dd></div>
                                                                                    <div><dt>File</dt><dd data-sub-receipt-file></dd></div>
                                                                                    <div><dt>Type</dt><dd data-sub-receipt-type></dd></div>
                                                                                    <div><dt>Submitted</dt><dd data-sub-receipt-when></dd></div>
                                                                                    <div><dt>File fingerprint</dt><dd data-sub-receipt-hash></dd></div>
                                                                                </dl>
                                                                                <div class="sub-receipt-card__actions">
                                                                                    <button type="button" class="button button--sm" data-sub-receipt-copy>Copy receipt</button>
                                                                                    <button type="button" class="button button--sm button--ghost" data-sub-receipt-print>Print</button>
                                                                                </div>
                                                                                <p class="sub-receipt-card__note">Keep this receipt. Admins can use it to find your submission if anything goes missing.</p>
                                                                            </div>
                                                                            <button type="submit" class="button" data-sub-submit-btn><?= $mySub ? 'Re-submit' : 'Submit work' ?></button>
                                                                        </form>
                                                                        </div>
                                                                    </section>
                                                                    <?php endif; ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <?php
                                                            // Dedicated Turnitin-style review overlays
                                                            $reviewList = portal_can_manage_course($courseId)
                                                                ? $subs
                                                                : ($mySub ? [$mySub] : []);
                                                            $isTeacherReview = portal_can_manage_course($courseId);
                                                        ?>
                                                        <?php foreach ($reviewList as $reviewSub): ?>
                                                            <?php
                                                                $reviewAnns = $submissionAnnotations[(int) $reviewSub['id']] ?? [];
                                                                $reviewWho  = $isTeacherReview ? (string) ($reviewSub['student_name'] ?? '') : 'Your submission';
                                                            ?>
                                                            <div id="rvw-<?= (int) $reviewSub['id'] ?>" class="rvw-overlay" hidden role="dialog" aria-modal="true">
                                                                <div class="rvw-dialog">
                                                                    <header class="rvw-dialog-header">
                                                                        <div class="rvw-dialog-heading">
                                                                            <p class="eyebrow">Assignment review</p>
                                                                            <h3><?= portal_escape($item['title']) ?></h3>
                                                                            <p class="rvw-dialog-sub"><?= portal_escape($reviewWho) ?> &middot; <?= portal_escape((string) $reviewSub['filename']) ?> &middot; <?= portal_escape(date('j M Y H:i', strtotime((string) $reviewSub['submitted_at']))) ?></p>
                                                                        </div>
                                                                        <div class="rvw-dialog-actions">
                                                                            <?php if ($isTeacherReview): ?>
                                                                                <form method="POST" class="sub-rerun-form">
                                                                                    <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                                                                    <input type="hidden" name="action" value="rerun_integrity">
                                                                                    <input type="hidden" name="submission_id" value="<?= (int) $reviewSub['id'] ?>">
                                                                                    <button type="submit" class="button button--sm button--ghost">Re-run checks</button>
                                                                                </form>
                                                                            <?php endif; ?>
                                                                            <button type="button" class="rvw-close" aria-label="Close">&times;</button>
                                                                        </div>
                                                                    </header>
                                                                    <?= portal_render_submission_review($reviewSub, $isTeacherReview, $item, $reviewAnns, $csrfToken) ?>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                        <?php $submissionModals .= ob_get_clean(); ?>
                                                    <?php endif; ?>
                                                    <?php endif; // end locked check ?>
                                                </div>

                                                <?php if (portal_can_manage_course($courseId)): ?>
                                                    <div class="folder-item-actions">
                                                        <button type="button"
                                                                class="folder-lock-toggle<?= $itemLocked ? ' is-locked' : '' ?>"
                                                                data-item-id="<?= (int)$item['id'] ?>"
                                                                title="<?= $itemLocked ? 'Unlock item' : 'Lock item' ?>"
                                                                aria-label="<?= $itemLocked ? 'Unlock item' : 'Lock item' ?>">
                                                            <?= portal_icon('lock', 'icon-sm') ?>
                                                        </button>
                                                        <?php if ($item['type'] === 'submission'): ?>
                                                        <button type="button"
                                                                class="folder-settings-button"
                                                                data-sub-open-edit="sub-slot-modal-<?= (int)$item['id'] ?>"
                                                                data-sub-open-edit-form="1"
                                                                title="Edit slot"
                                                                aria-label="Edit slot">
                                                            <?= portal_icon('edit', 'icon-sm') ?>
                                                        </button>
                                                        <?php elseif ($item['type'] === 'activity' && $activityRow): ?>
                                                        <a class="folder-settings-button"
                                                           href="activity-builder.php?id=<?= (int) $activityRow['id'] ?>"
                                                           title="Edit activity"
                                                           aria-label="Edit activity">
                                                            <?= portal_icon('edit', 'icon-sm') ?>
                                                        </a>
                                                        <?php else: ?>
                                                        <button type="button"
                                                                class="folder-settings-button settings-toggle"
                                                                data-settings-target="item-settings-<?= (int)$item['id'] ?>"
                                                                aria-label="Item settings">
                                                            <?= portal_icon('settings', 'icon-sm') ?>
                                                        </button>
                                                        <?php endif; ?>
                                                        <form method="POST" class="folder-item-delete-form">
                                                            <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                                            <input type="hidden" name="action" value="delete_item">
                                                            <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                                            <button type="submit" class="btn-icon-danger" title="Delete item">
                                                                <?= portal_icon('trash', 'icon-sm') ?>
                                                            </button>
                                                        </form>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="folder-empty-note">No items in this folder yet.</p>
                                <?php endif; ?>

                                <?php if (portal_can_manage_course($courseId)): ?>
                                    <details class="folder-admin-panel folder-admin-panel--inner">
                                        <summary class="folder-admin-trigger folder-admin-trigger--sm">
                                            <?= portal_icon('plus', 'icon-sm') ?>
                                            <span>Add item</span>
                                        </summary>
                                        <form method="POST" enctype="multipart/form-data" class="folder-admin-form folder-admin-form--inner">
                                            <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                            <input type="hidden" name="action" value="create_item">
                                            <input type="hidden" name="folder_id" value="<?= (int) $folder['id'] ?>">
                                            <div class="folder-form-row">
                                                <label class="folder-form-label">
                                                    <span>Type</span>
                                                    <select name="type" class="item-type-select">
                                                        <option value="document">File upload</option>
                                                        <option value="video">Video</option>
                                                        <option value="link">Link</option>
                                                        <option value="submission">Submission slot</option>
                                                        <option value="activity">Activity</option>
                                                    </select>
                                                </label>
                                                <label class="folder-form-label">
                                                    <span>Title</span>
                                                    <input type="text" name="title" required maxlength="200" placeholder="Item name">
                                                </label>
                                            </div>
                                            <div class="folder-form-row item-activity-group" style="display:none;">
                                                <label class="folder-form-label">
                                                    <span>Activity mode</span>
                                                    <select name="activity_mode">
                                                        <option value="practice">Practice</option>
                                                        <option value="quiz" selected>Quiz</option>
                                                        <option value="challenge">Challenge</option>
                                                        <option value="assessment">Assessment</option>
                                                        <option value="survey">Survey</option>
                                                        <option value="flashcard">Flashcards</option>
                                                    </select>
                                                </label>
                                            </div>
                                            <div class="folder-form-row item-file-group">
                                                <div class="folder-form-label" style="grid-column:1/-1;">
                                                    <span class="item-file-label">Upload file <small>(<?= portal_escape(portal_supported_upload_hint()) ?> - max 40 MB)</small></span>
                                                    <div class="upload-dropzone" data-upload-dropzone>
                                                        <input type="file" name="file" id="item-file-input" class="item-file-input" data-upload-input
                                                               accept=".doc,.docx,.xlsx,.pdf,.txt,.ppt,.pptx,.pps,.ppsx,.pot,.potx,.odp"
                                                               data-doc-accept=".doc,.docx,.xlsx,.pdf,.txt,.ppt,.pptx,.pps,.ppsx,.pot,.potx,.odp"
                                                               data-doc-hint="Upload file <small>(<?= portal_escape(portal_supported_upload_hint()) ?> - max 40 MB)</small>"
                                                               data-video-accept=".mp4,.webm,.mov,.m4v,.ogv"
                                                               data-video-hint="Upload a video file <small>(<?= portal_escape(portal_supported_video_upload_hint()) ?>) — or paste a link below</small>">
                                                        <div class="upload-dropzone-ui">
                                                            <strong class="upload-dropzone-title">Drop a file here</strong>
                                                            <span class="upload-dropzone-sub">or use Browse</span>
                                                            <label class="upload-dropzone-browse" for="item-file-input">Browse files</label>
                                                            <div class="upload-dropzone-file-row is-hidden" data-upload-file-row>
                                                                <span class="upload-dropzone-file" data-upload-filename></span>
                                                                <button type="button" class="upload-dropzone-clear" data-upload-clear title="Remove file" aria-label="Remove file">
                                                                    <?= portal_icon('trash', 'icon-xs') ?>
                                                                </button>
                                                            </div>
                                                        </div>
                                                        <div class="upload-progress is-hidden" data-upload-progress aria-live="polite">
                                                            <div class="upload-progress-track">
                                                                <div class="upload-progress-bar" data-upload-progress-bar></div>
                                                            </div>
                                                            <span class="upload-progress-label" data-upload-progress-label>0%</span>
                                                        </div>
                                                        <p class="upload-dropzone-error is-hidden" data-upload-error role="alert"></p>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="folder-form-row item-url-group">
                                                <label class="folder-form-label" style="grid-column:1/-1;">
                                                    <span class="item-url-label">Or paste URL <small>(optional)</small></span>
                                                    <input type="text" inputmode="url" autocomplete="url" name="url" maxlength="2000" placeholder="https://... or www.example.com"
                                                           data-doc-placeholder="https://... or www.example.com"
                                                           data-video-placeholder="https://www.youtube.com/watch?v=... or https://vimeo.com/...">
                                                </label>
                                            </div>
                                            <div class="folder-form-row">
                                                <label class="folder-form-label" style="grid-column:1/-1;">
                                                    <span>Description <small>(optional)</small></span>
                                                    <input type="text" name="description" maxlength="500" placeholder="Short note for students">
                                                </label>
                                            </div>
                                            <div class="folder-form-row item-submission-group">
                                                <label class="folder-form-label">
                                                    <span>Deadline <small>(optional — leave blank for none)</small></span>
                                                    <input type="datetime-local" name="submission_deadline" min="<?= portal_escape(date('Y-m-d\TH:i')) ?>">
                                                </label>
                                                <label class="folder-form-label">
                                                    <span>Grade weight <small>(%)</small></span>
                                                    <input type="number" name="submission_weight" min="0" max="100" step="0.01" inputmode="decimal" value="100">
                                                </label>
                                                <label class="folder-form-label">
                                                    <span>Re-submissions allowed</span>
                                                    <select name="submission_max_attempts">
                                                        <option value="0">Unlimited</option>
                                                        <option value="1">1 (no resubmission)</option>
                                                        <option value="2">2</option>
                                                    </select>
                                                </label>
                                                <?php if ($showExternalAiSlotOption): ?>
                                                <label class="folder-form-label folder-form-check">
                                                    <input type="checkbox" name="submission_ai_detection" value="1">
                                                    External AI detection <small style="font-weight:400">(GPTZero for this assignment)</small>
                                                </label>
                                                <?php elseif ($externalAiSiteWide): ?>
                                                <p class="submit-hint" style="margin:0;">External AI detection is enabled site-wide for all submissions.</p>
                                                <?php endif; ?>
                                            </div>
                                            <label class="folder-form-label folder-form-check">
                                                <input type="checkbox" name="allow_download" value="1">
                                                Allow students to download this file <small style="font-weight:400">(off by default)</small>
                                            </label>
                                            <button type="submit" class="button">Add</button>
                                        </form>
                                    </details>

                                    <?php if (portal_is_admin()): ?>
                                    <form method="POST" class="folder-delete-form">
                                        <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete_folder">
                                        <input type="hidden" name="folder_id" value="<?= (int) $folder['id'] ?>">
                                        <button type="submit" class="btn-danger-sm">
                                            <?= portal_icon('trash', 'icon-sm') ?> Delete folder
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </details>
                        </div><!-- /.folder-row -->
                    <?php endforeach; ?>
                    </div><!-- /#folder-stack -->
                <?php endif; ?>
