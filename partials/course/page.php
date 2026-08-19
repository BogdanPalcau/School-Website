<section class="course-detail-page">
    <?php if (is_array($courseFlash) && isset($courseFlash[0], $courseFlash[1])): ?>
        <div class="admin-flash <?= $courseFlash[0] === 'success' ? 'success' : 'error' ?>" style="margin-bottom:12px;">
            <?php if ($courseFlash[0] === 'success'): ?>
                <span><?= portal_escape((string) $courseFlash[1]) ?></span>
            <?php else: ?>
                <?= portal_icon('lock', 'admin-flash-icon') ?>
                <span><?= portal_escape((string) $courseFlash[1]) ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

<?php if ($preEnrollBlocks && is_array($preEnrollActivity)): ?>
    <article class="pre-enroll-gate" style="--course-accent: <?= portal_escape((string) $course['accent']) ?>;">
        <p class="pre-enroll-gate-kicker"><?= portal_escape((string) $course['code']) ?></p>
        <h2>Knowledge check</h2>
        <p>Your teacher would like you to complete a short quiz before you start <strong><?= portal_escape((string) $course['title']) ?></strong>. It helps them see what you already know. It is not part of your course grade unless they choose to count it.</p>
        <a class="button" href="activity.php?id=<?= (int) $preEnrollActivity['id'] ?>">Start knowledge check</a>
        <a class="pre-enroll-gate-back" href="courses.php">Back to courses</a>
    </article>
</section>
<?php
$page_content = ob_get_clean();
require __DIR__ . '/../../layout.php';
exit;
endif; ?>

    <article class="course-hero-banner" style="--course-accent: <?= portal_escape($course['accent']) ?>;">
        <div class="course-hero-top">
            <a class="course-breadcrumb" href="courses.php">All courses</a>
            <span class="course-status-pill<?= portal_course_status_pill_class((string) $course['status']) ?>"><?= portal_escape($course['status_label']) ?></span>
        </div>

        <p class="course-list-code"><?= portal_escape($course['code']) ?></p>
        <h3><?= portal_escape($course['full_title']) ?></h3>

        <div class="course-hero-desc-row">
            <p><?= portal_escape($course['summary']) ?></p>
            <?php if (portal_can_manage_course($courseId)): ?>
            <button type="button"
                    class="settings-toggle course-desc-edit-btn"
                    data-settings-target="course-desc-form"
                    title="Edit description"
                    aria-label="Edit course description">
                <?= portal_icon('edit', 'icon-sm') ?>
            </button>
            <?php endif; ?>
        </div>

        <?php if (portal_can_manage_course($courseId)): ?>
        <div class="settings-panel course-desc-panel" id="course-desc-form" hidden>
            <form method="POST" class="folder-admin-form">
                <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                <input type="hidden" name="action" value="update_course_description">
                <input type="hidden" name="return_section" value="<?= portal_escape($sectionKey) ?>">
                <label class="folder-form-label">
                    <span>Course description</span>
                    <textarea name="summary" required maxlength="500" rows="3"
                              class="course-desc-textarea"><?= portal_escape($course['summary']) ?></textarea>
                </label>
                <div class="button-row">
                    <button type="submit" class="button button--sm">Save</button>
                    <button type="button" class="button-secondary button--sm settings-toggle"
                            data-settings-target="course-desc-form">Cancel</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php
            $heroMetaLine = array_values(array_filter([
                trim((string) ($course['term'] ?? '')),
                trim((string) ($course['meeting'] ?? '')),
                trim((string) ($course['room'] ?? '')),
                ((int) ($course['student_count'] ?? 0)) . ' students',
            ], static fn(string $part): bool => $part !== ''));
        ?>
        <p class="course-hero-meta-line"><?= portal_escape(implode(' · ', $heroMetaLine)) ?></p>
        <div class="course-hero-meta">
            <span><?= portal_escape($currentSection['label']) ?></span>
            <span><?= portal_escape($course['term']) ?></span>
            <span><?= portal_escape($course['meeting']) ?></span>
            <span><?= portal_escape($course['room']) ?></span>
            <span><?= (int) $course['student_count'] ?> students</span>
        </div>
    </article>

    <nav class="course-subnav" aria-label="Course sections">
        <?php foreach ($tabs as $tab): ?>
            <a class="course-tab<?= !empty($tab['active']) ? ' active' : '' ?>" href="<?= portal_escape($tab['href']) ?>">
                <span><?= portal_escape($tab['label']) ?></span>
                <?php if (!empty($tab['badge'])): ?>
                    <span class="course-tab-badge"><?= (int) $tab['badge'] ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
        <?php if (portal_can_manage_course($courseId)): ?>
            <button class="course-tab course-tab--settings" id="tab-settings-btn" type="button" aria-expanded="false" aria-controls="tab-settings-panel">
                <?= portal_icon('settings', 'nav-icon') ?>
            </button>
        <?php endif; ?>
    </nav>

    <?php if (portal_can_manage_course($courseId)): ?>
    <div class="tab-settings-panel" id="tab-settings-panel" hidden>
        <form method="POST" class="tab-settings-form">
            <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
            <input type="hidden" name="action" value="save_tab_settings">
            <span class="tab-settings-label">Visible sections:</span>
            <?php
            $allTabMeta = [
                'content'       => 'Content',
                'calendar'      => 'Calendar',
                'announcements' => 'Announcements',
                'discussions'   => 'Discussions',
                'gradebook'     => 'Grades',
                'groups'        => 'Groups',
            ];
            ?>
            <?php foreach ($allTabMeta as $tKey => $tLabel): ?>
                <?php $isOn = $_enabledKeys === null || in_array($tKey, $_enabledKeys); ?>
                <label class="tab-toggle<?= $tKey === 'content' ? ' tab-toggle--locked' : '' ?>">
                    <input type="checkbox" name="tab_keys[]" value="<?= portal_escape($tKey) ?>"
                        <?= $isOn ? 'checked' : '' ?>
                        <?= $tKey === 'content' ? 'disabled' : '' ?>>
                    <?= portal_escape($tLabel) ?>
                </label>
            <?php endforeach; ?>
            <button type="submit" class="button button--sm">Save</button>
        </form>
        <?php
        $preEnrollActivity = portal_pre_enroll_activity($course);
        $preEnrollOn = is_array($preEnrollActivity);
        $preEnrollLive = portal_pre_enroll_is_active($preEnrollActivity);
        ?>
        <div class="tab-settings-pre-enroll">
            <div>
                <p class="tab-settings-label">Pre-enrolment quiz</p>
                <p class="tab-settings-hint">Optional. If you set this up and publish it, students complete it the first time they open this module.</p>
                <?php if ($preEnrollOn): ?>
                    <p class="tab-settings-status">Status:
                        <?php if ($preEnrollLive): ?>
                            published — students will see it on first visit
                        <?php elseif (($preEnrollActivity['status'] ?? '') === 'published'): ?>
                            published, but add at least one question
                        <?php else: ?>
                            draft — students will not see it until you publish
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
            <div class="tab-settings-pre-enroll-actions">
                <form method="POST">
                    <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                    <input type="hidden" name="action" value="create_pre_enroll_quiz">
                    <button type="submit" class="button button--sm"><?= $preEnrollOn ? 'Edit quiz' : 'Set up quiz' ?></button>
                </form>
                <?php if ($preEnrollOn): ?>
                    <a class="button-secondary button--sm" href="activity.php?id=<?= (int) $preEnrollActivity['id'] ?>&preview=1">Preview</a>
                    <form method="POST" onsubmit="return confirm('Turn off the pre-enrolment quiz? Students will be able to open the module without it.');">
                        <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                        <input type="hidden" name="action" value="remove_pre_enroll_quiz">
                        <button type="submit" class="button-secondary button--sm">Turn off</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="course-detail-layout">
        <div class="stack course-main-stack<?= $sectionKey === 'content' ? ' course-main-stack--content' : '' ?>">
            <?php if ($sectionKey === 'content'): ?>
                <?php require __DIR__ . '/section-content.php'; ?>
            <?php elseif ($sectionKey === 'calendar'): ?>
                <?php require __DIR__ . '/section-calendar.php'; ?>
            <?php elseif ($sectionKey === 'announcements'): ?>
                <?php require __DIR__ . '/section-announcements.php'; ?>
            <?php elseif ($sectionKey === 'discussions'): ?>
                <?php require __DIR__ . '/section-discussions.php'; ?>
            <?php elseif ($sectionKey === 'gradebook'): ?>
                <?php require __DIR__ . '/section-gradebook.php'; ?>
            <?php else: ?>
                <?php require __DIR__ . '/section-groups.php'; ?>
            <?php endif; ?>
        </div>
        <?php require __DIR__ . '/aside.php'; ?>
