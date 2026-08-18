<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../course_catalog.php';

portal_require_login();

$db  = portal_db();
$me  = portal_current_user();
$uid = (int) $me['id'];
$isStaff = portal_is_course_staff() || portal_is_admin();

// Opening Grades acknowledges newly released activity results for this user.
$db->prepare(
    "UPDATE activity_attempts
     SET grade_seen_at = datetime('now')
     WHERE user_id = ? AND status = 'released' AND grade_seen_at = ''"
)->execute([$uid]);

$toMark = [];
$heldGrades = [];
$moduleStats = [];
$staffMarked = [];
$staffAverage = null;
$studentGrades = [];
$byCourse = [];

if ($isStaff) {
    $assignedIds = portal_is_admin()
        ? array_map('intval', $db->query('SELECT id FROM courses')->fetchAll(PDO::FETCH_COLUMN))
        : portal_assigned_course_ids();

    if (!empty($assignedIds)) {
        $placeholders = implode(',', array_fill(0, count($assignedIds), '?'));

        $stmt = $db->prepare(
            "SELECT cs.id, cs.score, cs.marked_at, cs.submitted_at, cs.user_id,
                    cfi.title AS slot_title, cfi.submission_weight,
                    c.slug, c.title AS course_title, c.code, c.accent,
                    u.name AS student_name, u.initials AS student_initials
             FROM course_submissions cs
             JOIN course_folder_items cfi ON cfi.id = cs.item_id
             JOIN courses c ON c.id = cs.course_id
             JOIN users u ON u.id = cs.user_id
             WHERE cs.course_id IN ($placeholders)
               AND (cs.marked_at = '' OR cs.marked_at IS NULL OR cs.score IS NULL)
             ORDER BY cs.submitted_at ASC
             LIMIT 40"
        );
        $stmt->execute($assignedIds);
        $toMark = $stmt->fetchAll();

        $activityQueueStmt = $db->prepare(
            "SELECT t.id, t.percentage AS score, t.updated_at AS marked_at, t.submitted_at, t.user_id,
                    a.title AS slot_title, a.grade_weight AS submission_weight, a.id AS activity_id,
                    c.slug, c.title AS course_title, c.code, c.accent,
                    u.name AS student_name, u.initials AS student_initials,
                    'activity' AS grade_source
             FROM activity_attempts t
             JOIN course_activities a ON a.id = t.activity_id
             JOIN courses c ON c.id = t.course_id
             JOIN users u ON u.id = t.user_id
             WHERE t.course_id IN ($placeholders)
               AND a.include_in_gradebook = 1
               AND t.status = 'awaiting_manual_marking'
             ORDER BY t.submitted_at ASC"
        );
        $activityQueueStmt->execute($assignedIds);
        $toMark = array_merge($toMark, $activityQueueStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        usort($toMark, static fn(array $left, array $right): int =>
            strcmp((string) ($left['submitted_at'] ?? ''), (string) ($right['submitted_at'] ?? ''))
        );

        $stmt = $db->prepare(
            "SELECT cs.id, cs.score, cs.marked_at, cs.submitted_at, cs.user_id, cs.grades_released_at,
                    cfi.title AS slot_title, cfi.submission_weight, cs.item_id,
                    c.slug, c.title AS course_title, c.code, c.accent,
                    u.name AS student_name, u.initials AS student_initials
             FROM course_submissions cs
             JOIN course_folder_items cfi ON cfi.id = cs.item_id
             JOIN courses c ON c.id = cs.course_id
             JOIN users u ON u.id = cs.user_id
             WHERE cs.course_id IN ($placeholders)
               AND cs.marked_at != ''
               AND cs.score IS NOT NULL
               AND (cs.grades_released_at IS NULL OR trim(cs.grades_released_at) = '')
             ORDER BY cs.marked_at DESC
             LIMIT 40"
        );
        $stmt->execute($assignedIds);
        $heldGrades = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $activityHeldStmt = $db->prepare(
            "SELECT t.id, t.percentage AS score, t.updated_at AS marked_at, t.submitted_at, t.user_id,
                    a.title AS slot_title, a.grade_weight AS submission_weight, a.id AS activity_id,
                    c.slug, c.title AS course_title, c.code, c.accent,
                    u.name AS student_name, u.initials AS student_initials,
                    'activity' AS grade_source
             FROM activity_attempts t
             JOIN course_activities a ON a.id = t.activity_id
             JOIN courses c ON c.id = t.course_id
             JOIN users u ON u.id = t.user_id
             WHERE t.course_id IN ($placeholders)
               AND a.include_in_gradebook = 1
               AND t.status = 'marked'
               AND t.percentage IS NOT NULL
             ORDER BY t.updated_at DESC
             LIMIT 40"
        );
        $activityHeldStmt->execute($assignedIds);
        $heldGrades = array_merge($heldGrades, $activityHeldStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        usort($heldGrades, static fn(array $left, array $right): int =>
            strcmp((string) ($right['marked_at'] ?? ''), (string) ($left['marked_at'] ?? ''))
        );
        $heldGrades = array_slice($heldGrades, 0, 40);

        $stmt = $db->prepare(
            "SELECT c.id, c.slug, c.title, c.code, c.accent,
                    COUNT(cs.id) AS total_submissions,
                    SUM(CASE WHEN cs.id IS NOT NULL
                              AND (cs.marked_at = '' OR cs.marked_at IS NULL OR cs.score IS NULL)
                             THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN cs.id IS NOT NULL
                              AND cs.marked_at != ''
                              AND cs.score IS NOT NULL
                              AND (cs.grades_released_at IS NULL OR trim(cs.grades_released_at) = '')
                             THEN 1 ELSE 0 END) AS held_count,
                    SUM(CASE WHEN cs.id IS NOT NULL
                              AND cs.marked_at != ''
                              AND cs.score IS NOT NULL
                              AND cs.grades_released_at != ''
                             THEN 1 ELSE 0 END) AS marked_count
             FROM courses c
             LEFT JOIN course_submissions cs ON cs.course_id = c.id
             WHERE c.id IN ($placeholders)
             GROUP BY c.id
             ORDER BY pending_count DESC, c.title ASC"
        );
        $stmt->execute($assignedIds);
        $moduleStats = $stmt->fetchAll();

        $activityStatsStmt = $db->prepare(
            "SELECT t.course_id,
                    COUNT(*) AS total_submissions,
                    SUM(CASE WHEN t.status = 'awaiting_manual_marking' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN t.status = 'marked' THEN 1 ELSE 0 END) AS held_count,
                    SUM(CASE WHEN t.status = 'released' THEN 1 ELSE 0 END) AS marked_count
             FROM activity_attempts t
             JOIN course_activities a ON a.id = t.activity_id
             WHERE t.course_id IN ($placeholders)
               AND (a.include_in_gradebook = 1 OR t.status = 'released')
               AND t.status IN ('submitted','auto_submitted','awaiting_manual_marking','marked','released')
             GROUP BY t.course_id"
        );
        $activityStatsStmt->execute($assignedIds);
        $activityStats = [];
        foreach ($activityStatsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $activityStat) {
            $activityStats[(int) $activityStat['course_id']] = $activityStat;
        }
        foreach ($moduleStats as &$module) {
            $activityStat = $activityStats[(int) $module['id']] ?? null;
            if ($activityStat !== null) {
                $module['total_submissions'] = (int) $module['total_submissions'] + (int) $activityStat['total_submissions'];
                $module['pending_count'] = (int) $module['pending_count'] + (int) $activityStat['pending_count'];
                $module['held_count'] = (int) ($module['held_count'] ?? 0) + (int) ($activityStat['held_count'] ?? 0);
                $module['marked_count'] = (int) $module['marked_count'] + (int) $activityStat['marked_count'];
            }
        }
        unset($module);

        $stmt = $db->prepare(
            "SELECT cs.course_id, cs.score, cs.marked_at, cfi.submission_weight
             FROM course_submissions cs
             JOIN course_folder_items cfi ON cfi.id = cs.item_id
             WHERE cs.course_id IN ($placeholders)
               AND cs.marked_at != ''
               AND cs.score IS NOT NULL
               AND cs.grades_released_at != ''"
        );
        $stmt->execute($assignedIds);
        $markedByCourse = [];
        foreach ($stmt->fetchAll() as $row) {
            $staffMarked[] = $row;
            $markedByCourse[(int) $row['course_id']][] = $row;
        }

        // Merge activity gradebook scores into staff averages (visible once marked,
        // matching the "always show computed score" grades.php now uses for students)
        foreach ($assignedIds as $cid) {
            $cid = (int) $cid;
            $actStmt = $db->prepare(
                "SELECT a.course_id, t.percentage AS score, t.updated_at AS marked_at, a.grade_weight AS submission_weight
                 FROM course_activities a
                 JOIN activity_attempts t ON t.activity_id = a.id
                 WHERE a.course_id = ?
                   AND (a.include_in_gradebook = 1 OR t.status = 'released')
                   AND a.status = 'published'
                   AND t.status = 'released'
                   AND t.percentage IS NOT NULL"
            );
            $actStmt->execute([$cid]);
            foreach ($actStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $staffMarked[] = $row;
                $markedByCourse[$cid][] = $row;
            }
        }

        foreach ($moduleStats as &$module) {
            $courseId = (int) $module['id'];
            $module['average'] = !empty($markedByCourse[$courseId])
                ? portal_weighted_grade_average($markedByCourse[$courseId])
                : null;
        }
        unset($module);
    }

    $moduleCount = count($assignedIds ?? []);
    $staffAverage = !empty($staffMarked) ? portal_weighted_grade_average($staffMarked) : null;

    $page_title = 'Grades | ' . portal_school_name();
    $page_eyebrow = 'Teaching';
    $page_heading = 'Marking';
    $page_description = 'Mark work that needs a score, then release it when students should see it.';
} else {
    $courseIds = portal_enrolled_course_ids($uid);
    if (!empty($courseIds)) {
        $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
        $stmt = $db->prepare(
            "SELECT cs.id, cs.score, cs.marked_at, cs.submitted_at, cs.grades_released_at,
                    cfi.title AS slot_title, cfi.submission_weight,
                    c.slug, c.title AS course_title, c.code, c.accent,
                    c.id AS course_id, 'submission' AS grade_source
             FROM course_submissions cs
             JOIN course_folder_items cfi ON cfi.id = cs.item_id
             JOIN courses c ON c.id = cs.course_id
             WHERE cs.user_id = ?
               AND cs.course_id IN ($placeholders)
             ORDER BY c.title ASC, cs.submitted_at DESC"
        );
        $stmt->execute(array_merge([$uid], $courseIds));
        $studentGrades = $stmt->fetchAll();

        $courseMetaStmt = $db->prepare(
            "SELECT id, slug, title, code, accent FROM courses WHERE id IN ($placeholders)"
        );
        $courseMetaStmt->execute($courseIds);
        $courseMeta = [];
        foreach ($courseMetaStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $courseMeta[(int) $c['id']] = $c;
        }

        foreach ($courseIds as $cid) {
            $cid = (int) $cid;
            $activityRows = portal_activity_gradebook_rows($cid, $uid);
            $meta = $courseMeta[$cid] ?? null;
            if ($meta === null) {
                continue;
            }
            foreach ($activityRows as $ar) {
                $score = $ar['score'];
                $markedAt = (string) ($ar['marked_at'] ?? '');
                if ($score !== null && $markedAt === '') {
                    $markedAt = (string) ($ar['submitted_at'] ?? portal_activity_now());
                }
                $studentGrades[] = [
                    'id' => (int) ($ar['attempt_id'] ?? 0),
                    'score' => $score,
                    'marked_at' => $markedAt,
                    'submitted_at' => (string) ($ar['submitted_at'] ?? ''),
                    'slot_title' => (string) ($ar['title'] ?? 'Activity'),
                    'submission_weight' => $ar['submission_weight'] ?? $ar['weight'] ?? 100,
                    'slug' => (string) $meta['slug'],
                    'course_title' => (string) $meta['title'],
                    'code' => (string) $meta['code'],
                    'accent' => (string) $meta['accent'],
                    'course_id' => $cid,
                    'grade_source' => 'activity',
                    'activity_id' => (int) ($ar['activity_id'] ?? 0),
                    'activity_mode' => (string) ($ar['mode'] ?? 'quiz'),
                    'activity_status' => (string) ($ar['status'] ?? ''),
                ];
            }
        }
    }

    foreach ($studentGrades as $grade) {
        $key = (string) $grade['slug'];
        if (!isset($byCourse[$key])) {
            $byCourse[$key] = [
                'title'  => (string) $grade['course_title'],
                'code'   => (string) $grade['code'],
                'accent' => (string) $grade['accent'],
                'slug'   => $key,
                'rows'   => [],
            ];
        }
        $byCourse[$key]['rows'][] = $grade;
    }

    $page_title = 'My grades | ' . portal_school_name();
    $page_eyebrow = 'Results';
    $page_heading = 'My grades';
    $page_description = 'Returned marks, weighted averages, and feedback links across your modules.';
}

$active_page = 'grades';

$studentMarked = array_values(array_filter(
    $studentGrades,
    static function (array $g): bool {
        if ($g['score'] === null || trim((string) ($g['marked_at'] ?? '')) === '') {
            return false;
        }
        if (($g['grade_source'] ?? '') === 'activity') {
            return true;
        }
        return portal_submission_grades_released($g);
    }
));
$studentPending = count($studentGrades) - count($studentMarked);
$studentAverage = !empty($studentMarked) ? portal_weighted_grade_average($studentMarked) : null;

ob_start();
?>
<section class="grades-page">

<?php if ($isStaff): ?>

    <div class="grades-summary grades-summary--staff">
        <article class="grades-summary-card grades-summary-card--queue<?= count($toMark) > 0 ? ' grades-summary-card--accent' : '' ?>">
            <span>To mark</span>
            <strong><?= count($toMark) ?></strong>
            <small>Needs a teacher score</small>
        </article>
        <article class="grades-summary-card<?= count($heldGrades) > 0 ? ' grades-summary-card--accent' : '' ?>">
            <span>To release</span>
            <strong><?= count($heldGrades) ?></strong>
            <small>Scored, not visible to students</small>
        </article>
        <article class="grades-summary-card">
            <span>Weighted avg</span>
            <strong><?= $staffAverage !== null ? $staffAverage . '%' : '—' ?></strong>
            <small>Returned work only</small>
        </article>
        <article class="grades-summary-card">
            <span>Modules</span>
            <strong><?= (int) ($moduleCount ?? 0) ?></strong>
            <small><?= portal_is_admin() ? 'All active modules' : 'Assigned to you' ?></small>
        </article>
    </div>

    <div class="grades-staff-layout">
    <div class="grades-staff-queues">
    <?php if (empty($toMark) && empty($heldGrades)): ?>
        <article class="card-shell grades-panel">
            <div class="grades-empty-state">
                <h4>Caught up</h4>
                <p>Nothing to mark or release right now.</p>
            </div>
        </article>
    <?php else: ?>
    <article class="card-shell grades-panel grades-panel--queue">
        <div class="section-head">
            <div>
                <p class="eyebrow">Mark</p>
                <h3 class="card-title">To mark</h3>
            </div>
            <span class="chip"><?= count($toMark) ?></span>
        </div>

        <?php if (empty($toMark)): ?>
            <div class="grades-empty-state grades-empty-state--compact">
                <h4>Nothing to mark</h4>
                <p>Submitted work that still needs a score will appear here.</p>
            </div>
        <?php else: ?>
            <div class="grades-work-list">
                <div class="grades-simple-row grades-simple-row--head grades-simple-row--staff grades-row-head--hidden" role="row">
                    <span role="columnheader">Student</span>
                    <span role="columnheader">Work</span>
                    <span role="columnheader">Waiting</span>
                </div>
                <?php foreach ($toMark as $row): ?>
                    <?php
                        $queueHref = (($row['grade_source'] ?? '') === 'activity')
                            ? 'activity-results.php?id=' . (int) ($row['activity_id'] ?? 0) . '&attempt=' . (int) $row['id']
                            : 'course.php?course=' . urlencode((string) $row['slug']) . '&section=content&open_review=rvw-' . (int) $row['id'];
                    ?>
                    <a class="grades-work-row is-pending"
                       href="<?= portal_escape($queueHref) ?>">
                        <span class="grades-person">
                            <span class="grades-avatar"><?= portal_escape((string) ($row['student_initials'] ?: '?')) ?></span>
                            <span>
                                <strong><?= portal_escape((string) $row['student_name']) ?></strong>
                                <small><?= portal_escape((string) $row['code']) ?> · <?= portal_escape((string) $row['course_title']) ?></small>
                            </span>
                        </span>
                        <span class="grades-work-main">
                            <strong><?= portal_escape((string) $row['slot_title']) ?></strong>
                            <small>Submitted <?= portal_escape(portal_relative_time((string) $row['submitted_at'])) ?> · Weight <?= portal_escape(portal_format_submission_weight($row['submission_weight'] ?? 100)) ?></small>
                        </span>
                        <span class="grades-status grades-status--pending"><?= portal_escape(portal_wait_label((string) $row['submitted_at'])) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <article class="card-shell grades-panel grades-panel--release">
        <div class="section-head">
            <div>
                <p class="eyebrow">Release</p>
                <h3 class="card-title">To release</h3>
            </div>
            <span class="chip"><?= count($heldGrades) ?></span>
        </div>

        <?php if (empty($heldGrades)): ?>
            <div class="grades-empty-state grades-empty-state--compact">
                <h4>Nothing to release</h4>
                <p>Saved and auto-marked scores stay here until you publish them.</p>
            </div>
        <?php else: ?>
            <div class="grades-work-list">
                <div class="grades-simple-row grades-simple-row--head grades-simple-row--staff grades-row-head--hidden" role="row">
                    <span role="columnheader">Student</span>
                    <span role="columnheader">Work</span>
                    <span role="columnheader">Action</span>
                </div>
                <?php foreach ($heldGrades as $row): ?>
                    <?php
                        $heldHref = (($row['grade_source'] ?? '') === 'activity')
                            ? 'activity-results.php?id=' . (int) ($row['activity_id'] ?? 0) . '&attempt=' . (int) $row['id']
                            : 'course.php?course=' . urlencode((string) $row['slug']) . '&section=content&open_review=rvw-' . (int) $row['id'];
                    ?>
                    <a class="grades-work-row is-release"
                       href="<?= portal_escape($heldHref) ?>">
                        <span class="grades-person">
                            <span class="grades-avatar"><?= portal_escape((string) ($row['student_initials'] ?: '?')) ?></span>
                            <span>
                                <strong><?= portal_escape((string) $row['student_name']) ?></strong>
                                <small><?= portal_escape((string) $row['code']) ?> · <?= portal_escape((string) $row['course_title']) ?></small>
                            </span>
                        </span>
                        <span class="grades-work-main">
                            <strong><?= portal_escape((string) $row['slot_title']) ?></strong>
                            <small><?= (int) $row['score'] ?>% · Not visible to students yet</small>
                        </span>
                        <span class="grades-status grades-status--release">Release</span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
    <?php endif; ?>
    </div>

    <article class="card-shell grades-panel">
        <div class="section-head">
            <div>
                <p class="eyebrow">Modules</p>
                <h3 class="card-title">Grade health</h3>
            </div>
            <span class="chip"><?= count($moduleStats) ?></span>
        </div>

        <?php if (empty($moduleStats)): ?>
            <div class="grades-empty-state">
                <h4>No assigned modules</h4>
                <p>Assigned modules will appear here once you are added to a course.</p>
            </div>
        <?php else: ?>
            <div class="grades-module-grid">
                <?php foreach ($moduleStats as $module): ?>
                    <?php
                        $pendingCount = (int) ($module['pending_count'] ?? 0);
                        $heldCount = (int) ($module['held_count'] ?? 0);
                        $markedCount = (int) ($module['marked_count'] ?? 0);
                        $totalCount = (int) ($module['total_submissions'] ?? 0);
                        $moduleAverage = $module['average'] ?? null;
                    ?>
                    <a class="grades-health-card<?= ($pendingCount > 0 || $heldCount > 0) ? ' has-pending' : '' ?>"
                       href="course.php?course=<?= urlencode((string) $module['slug']) ?>&section=gradebook">
                        <span class="grades-module-accent" style="background:<?= portal_escape((string) $module['accent']) ?>"></span>
                        <span class="grades-health-main">
                            <strong><?= portal_escape((string) $module['title']) ?></strong>
                            <small><?= portal_escape((string) $module['code']) ?> · <?= $totalCount ?> submission<?= $totalCount === 1 ? '' : 's' ?></small>
                        </span>
                        <span class="grades-health-metrics">
                            <span><?= $pendingCount ?> to mark</span>
                            <?php if ($heldCount > 0): ?>
                                <span><?= $heldCount ?> to release</span>
                            <?php endif; ?>
                            <span><?= $markedCount ?> released</span>
                            <strong><?= $moduleAverage !== null ? (int) $moduleAverage . '%' : '—' ?></strong>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
    </div>

<?php else: ?>

    <div class="grades-summary">
        <article class="grades-summary-card">
            <span>Marked</span>
            <strong><?= count($studentMarked) ?></strong>
            <small>Returned submissions</small>
        </article>
        <article class="grades-summary-card">
            <span>Awaiting</span>
            <strong><?= $studentPending ?></strong>
            <small>Submitted, not marked</small>
        </article>
        <article class="grades-summary-card grades-summary-card--accent">
            <span>Weighted avg</span>
            <small>Returned work only</small>
            <strong><?= $studentAverage !== null ? $studentAverage . '%' : '—' ?></strong>
        </article>
    </div>

    <?php if (empty($byCourse)): ?>
        <article class="card-shell">
            <div class="gb-empty">
                <p class="eyebrow">No grades yet</p>
                <h3>Nothing to show</h3>
                <p>When teachers return marked work, it will appear here.</p>
                <p><a class="inline-action" href="courses.php">Browse courses</a></p>
            </div>
        </article>
    <?php else: ?>
        <div class="grades-modules">
            <?php foreach ($byCourse as $module): ?>
                <?php
                    $moduleMarked = array_values(array_filter(
                        $module['rows'],
                        static function (array $g): bool {
                            if ($g['score'] === null || trim((string) ($g['marked_at'] ?? '')) === '') {
                                return false;
                            }
                            if (($g['grade_source'] ?? '') === 'activity') {
                                return true;
                            }
                            return portal_submission_grades_released($g);
                        }
                    ));
                    $modulePending = count($module['rows']) - count($moduleMarked);
                    $moduleAvg = !empty($moduleMarked) ? portal_weighted_grade_average($moduleMarked) : null;
                ?>
                <article class="grades-module card-shell">
                    <div class="grades-module-head">
                        <span class="grades-module-accent" style="background:<?= portal_escape($module['accent']) ?>"></span>
                        <div>
                            <p class="eyebrow"><?= portal_escape($module['code']) ?></p>
                            <h3 class="card-title"><?= portal_escape($module['title']) ?></h3>
                        </div>
                        <div class="grades-module-meta">
                            <span class="grades-module-count"><?= count($module['rows']) ?> item<?= count($module['rows']) === 1 ? '' : 's' ?></span>
                            <?php if ($modulePending > 0): ?>
                                <span class="grades-status grades-status--pending"><?= $modulePending ?> awaiting</span>
                            <?php endif; ?>
                            <?php if ($moduleAvg !== null): ?>
                                <span class="grades-module-avg"><?= $moduleAvg ?>%</span>
                            <?php endif; ?>
                            <a class="inline-action" href="course.php?course=<?= urlencode($module['slug']) ?>&section=gradebook">Module</a>
                        </div>
                    </div>

                    <div class="grades-simple-table" role="table">
                        <div class="grades-simple-row grades-simple-row--head" role="row">
                            <span role="columnheader">Work</span>
                            <span role="columnheader">Mark</span>
                        </div>
                        <?php foreach ($module['rows'] as $row): ?>
                            <?php
                                $isActivityGrade = (($row['grade_source'] ?? '') === 'activity');
                                $isMarked = $isActivityGrade
                                    ? ($row['score'] !== null && trim((string) ($row['marked_at'] ?? '')) !== '')
                                    : (portal_submission_is_marked($row) && portal_submission_grades_released($row));
                                $isHeld = !$isActivityGrade && portal_submission_is_marked($row) && !portal_submission_grades_released($row);
                                $markedTs = portal_db_timestamp((string) ($row['marked_at'] ?? ''));
                                $awaitingTeacher = $isActivityGrade && (($row['activity_status'] ?? '') === 'awaiting_manual_marking');
                                $rowHref = $isActivityGrade
                                    ? 'activity.php?id=' . (int) ($row['activity_id'] ?? 0)
                                    : 'course.php?course=' . urlencode($module['slug']) . '&section=content&open_review=rvw-' . (int) $row['id'];
                                $statusNote = $isMarked && $markedTs
                                    ? 'Marked ' . date('j M Y', $markedTs)
                                    : ($isHeld || $awaitingTeacher ? 'Submitted' : 'Awaiting mark');
                                $scoreTag = $isMarked
                                    ? (int) $row['score'] . '%'
                                    : ($isHeld ? 'Awaiting release' : 'Not graded');
                            ?>
                            <a class="grades-simple-row<?= $isMarked ? ' is-marked' : ' is-pending' ?>"
                               role="row"
                               href="<?= portal_escape($rowHref) ?>">
                                <span class="grades-simple-work" role="cell">
                                    <strong>
                                        <?= portal_escape((string) $row['slot_title']) ?>
                                        <?php if ($isActivityGrade): ?>
                                            <span class="grades-item-tag grades-item-tag--<?= portal_escape((string) ($row['activity_mode'] ?? 'quiz')) ?>"><?= portal_escape(portal_activity_mode_label((string) ($row['activity_mode'] ?? 'quiz'))) ?></span>
                                        <?php endif; ?>
                                    </strong>
                                    <small><?= portal_escape($statusNote) ?> · Weight <?= portal_escape(portal_format_submission_weight($row['submission_weight'] ?? 100)) ?></small>
                                </span>
                                <span class="grades-simple-score<?= $isMarked ? ' is-marked' : '' ?><?= ($awaitingTeacher || $isHeld) ? ' is-awaiting' : '' ?>" role="cell">
                                    <?= portal_escape($scoreTag) ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php endif; ?>

</section>
<?php
$page_content = ob_get_clean();

require __DIR__ . '/../layout.php';
