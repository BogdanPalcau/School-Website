<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../course_catalog.php';

portal_require_login();

$db  = portal_db();
$me  = portal_current_user();
$uid = (int) $me['id'];
$isStaff = portal_is_course_staff() || portal_is_admin();

$toMark = [];
$gradedByMe = [];
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
               AND cs.marked_at != ''
               AND cs.score IS NOT NULL
             ORDER BY cs.marked_at DESC
             LIMIT 40"
        );
        $stmt->execute($assignedIds);
        $gradedByMe = $stmt->fetchAll();

        $stmt = $db->prepare(
            "SELECT c.id, c.slug, c.title, c.code, c.accent,
                    COUNT(cs.id) AS total_submissions,
                    SUM(CASE WHEN cs.id IS NOT NULL
                              AND (cs.marked_at = '' OR cs.marked_at IS NULL OR cs.score IS NULL)
                             THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN cs.id IS NOT NULL
                              AND cs.marked_at != ''
                              AND cs.score IS NOT NULL
                             THEN 1 ELSE 0 END) AS marked_count
             FROM courses c
             LEFT JOIN course_submissions cs ON cs.course_id = c.id
             WHERE c.id IN ($placeholders)
             GROUP BY c.id
             ORDER BY pending_count DESC, c.title ASC"
        );
        $stmt->execute($assignedIds);
        $moduleStats = $stmt->fetchAll();

        $stmt = $db->prepare(
            "SELECT cs.course_id, cs.score, cs.marked_at, cfi.submission_weight
             FROM course_submissions cs
             JOIN course_folder_items cfi ON cfi.id = cs.item_id
             WHERE cs.course_id IN ($placeholders)
               AND cs.marked_at != ''
               AND cs.score IS NOT NULL"
        );
        $stmt->execute($assignedIds);
        $markedByCourse = [];
        foreach ($stmt->fetchAll() as $row) {
            $staffMarked[] = $row;
            $markedByCourse[(int) $row['course_id']][] = $row;
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
    $page_heading = 'Grades and marking';
    $page_description = 'Review marking queues, returned work, and module grade health.';
} else {
    $courseIds = portal_enrolled_course_ids($uid);
    if (!empty($courseIds)) {
        $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
        $stmt = $db->prepare(
            "SELECT cs.id, cs.score, cs.marked_at, cs.submitted_at,
                    cfi.title AS slot_title, cfi.submission_weight,
                    c.slug, c.title AS course_title, c.code, c.accent
             FROM course_submissions cs
             JOIN course_folder_items cfi ON cfi.id = cs.item_id
             JOIN courses c ON c.id = cs.course_id
             WHERE cs.user_id = ?
               AND cs.course_id IN ($placeholders)
             ORDER BY c.title ASC, cs.submitted_at DESC"
        );
        $stmt->execute(array_merge([$uid], $courseIds));
        $studentGrades = $stmt->fetchAll();
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
    static fn(array $g): bool => $g['score'] !== null && trim((string) ($g['marked_at'] ?? '')) !== ''
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
            <small>Oldest submissions first</small>
        </article>
        <article class="grades-summary-card">
            <span>Returned</span>
            <strong><?= count($gradedByMe) ?></strong>
            <small>Latest 40 shown</small>
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
    <article class="card-shell grades-panel grades-panel--queue">
        <div class="section-head">
            <div>
                <p class="eyebrow">Queue</p>
                <h3 class="card-title">Left to grade</h3>
            </div>
            <span class="chip"><?= count($toMark) ?></span>
        </div>

        <?php if (empty($toMark)): ?>
            <div class="grades-empty-state">
                <h4>All clear</h4>
                <p>There are no submitted assignments waiting for a mark.</p>
            </div>
            <p class="grades-empty-line">You’re caught up — nothing waiting to be marked.</p>
        <?php else: ?>
            <div class="grades-work-list">
                <div class="grades-simple-row grades-simple-row--head grades-simple-row--staff grades-row-head--hidden" role="row">
                    <span role="columnheader">Student</span>
                    <span role="columnheader">Work</span>
                    <span role="columnheader">Waiting</span>
                </div>
                <?php foreach ($toMark as $row): ?>
                    <a class="grades-work-row is-pending"
                       href="course.php?course=<?= urlencode((string) $row['slug']) ?>&section=content&open_review=rvw-<?= (int) $row['id'] ?>">
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
                        $markedCount = (int) ($module['marked_count'] ?? 0);
                        $totalCount = (int) ($module['total_submissions'] ?? 0);
                        $moduleAverage = $module['average'] ?? null;
                    ?>
                    <a class="grades-health-card<?= $pendingCount > 0 ? ' has-pending' : '' ?>"
                       href="course.php?course=<?= urlencode((string) $module['slug']) ?>&section=gradebook">
                        <span class="grades-module-accent" style="background:<?= portal_escape((string) $module['accent']) ?>"></span>
                        <span class="grades-health-main">
                            <strong><?= portal_escape((string) $module['title']) ?></strong>
                            <small><?= portal_escape((string) $module['code']) ?> · <?= $totalCount ?> submission<?= $totalCount === 1 ? '' : 's' ?></small>
                        </span>
                        <span class="grades-health-metrics">
                            <span><?= $pendingCount ?> pending</span>
                            <span><?= $markedCount ?> returned</span>
                            <strong><?= $moduleAverage !== null ? (int) $moduleAverage . '%' : '—' ?></strong>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
    </div>

    <article class="card-shell grades-panel">
        <div class="section-head">
            <div>
                <p class="eyebrow">Done</p>
                <h3 class="card-title">Already graded</h3>
            </div>
            <span class="chip"><?= count($gradedByMe) ?></span>
        </div>

        <?php if (empty($gradedByMe)): ?>
            <div class="grades-empty-state grades-empty-state--compact">
                <h4>No returned marks yet</h4>
                <p>Returned submissions will be listed here after marking.</p>
            </div>
        <?php else: ?>
            <div class="grades-work-list grades-work-list--returned">
                <div class="grades-simple-row grades-simple-row--head grades-simple-row--staff grades-row-head--hidden" role="row">
                    <span role="columnheader">Student</span>
                    <span role="columnheader">Work</span>
                    <span role="columnheader">Mark</span>
                </div>
                <?php foreach ($gradedByMe as $row): ?>
                    <?php $markedTs = portal_db_timestamp((string) ($row['marked_at'] ?? '')); ?>
                    <a class="grades-work-row is-marked"
                       href="course.php?course=<?= urlencode((string) $row['slug']) ?>&section=content&open_review=rvw-<?= (int) $row['id'] ?>">
                        <span class="grades-person">
                            <span class="grades-avatar"><?= portal_escape((string) ($row['student_initials'] ?: '?')) ?></span>
                            <span>
                                <strong><?= portal_escape((string) $row['student_name']) ?></strong>
                                <small><?= portal_escape((string) $row['code']) ?> · <?= portal_escape((string) $row['course_title']) ?></small>
                            </span>
                        </span>
                        <span class="grades-work-main">
                            <strong><?= portal_escape((string) $row['slot_title']) ?></strong>
                            <small><?= $markedTs ? 'Marked ' . portal_escape(date('j M Y', $markedTs)) : 'Marked' ?> · Weight <?= portal_escape(portal_format_submission_weight($row['submission_weight'] ?? 100)) ?></small>
                        </span>
                        <span class="grades-status grades-status--marked"><?= (int) $row['score'] ?>%</span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

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
                        static fn(array $g): bool => $g['score'] !== null && trim((string) ($g['marked_at'] ?? '')) !== ''
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
                                $isMarked = $row['score'] !== null && trim((string) ($row['marked_at'] ?? '')) !== '';
                                $markedTs = portal_db_timestamp((string) ($row['marked_at'] ?? ''));
                            ?>
                            <a class="grades-simple-row<?= $isMarked ? ' is-marked' : ' is-pending' ?>"
                               role="row"
                               href="course.php?course=<?= urlencode($module['slug']) ?>&section=content&open_review=rvw-<?= (int) $row['id'] ?>">
                                <span class="grades-simple-work" role="cell">
                                    <strong><?= portal_escape((string) $row['slot_title']) ?></strong>
                                    <small><?= $isMarked && $markedTs
                                        ? 'Marked ' . portal_escape(date('j M Y', $markedTs))
                                        : 'Awaiting mark' ?> · Weight <?= portal_escape(portal_format_submission_weight($row['submission_weight'] ?? 100)) ?></small>
                                </span>
                                <span class="grades-simple-score<?= $isMarked ? ' is-marked' : '' ?>" role="cell">
                                    <?= $isMarked ? (int) $row['score'] . '%' : '—' ?>
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
