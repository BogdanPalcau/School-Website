<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../course_catalog.php';
require_once __DIR__ . '/../customization.php';

portal_require_login();

$staffCourseView = portal_is_teacher() || portal_is_admin() || portal_is_owner();

$page_title = 'Courses | ' . portal_school_name();
$active_page = 'courses';
$page_eyebrow = 'Portal';
$page_heading = 'Courses';
$page_description = $staffCourseView
    ? 'Your assigned and managed course spaces.'
    : 'Your enrolled course spaces.';

$currentUser  = portal_current_user();
$catalog      = portal_user_course_catalog((int) $currentUser['id']);
$query        = trim((string) ($_GET['q'] ?? ''));
$yearFilter   = (string) ($_GET['year'] ?? 'all');
$statusFilter = (string) ($_GET['status'] ?? 'all');
$customPrefs  = portal_customization_preferences((int) $currentUser['id']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && (string) ($_POST['action'] ?? '') === 'toggle_favorite_course') {
    if (!portal_verify_csrf()) {
        portal_redirect('courses.php');
    }

    $courseId = (int) ($_POST['course_id'] ?? 0);
    $allowedCourseIds = array_map(static fn(array $course): int => (int) ($course['id'] ?? 0), $catalog);
    if ($courseId > 0 && in_array($courseId, $allowedCourseIds, true)) {
        portal_toggle_favorite_course((int) $currentUser['id'], $courseId);
    }

    $return = [];
    $returnQuery = substr(trim((string) ($_POST['return_q'] ?? '')), 0, 150);
    $returnYear = substr(trim((string) ($_POST['return_year'] ?? 'all')), 0, 80);
    $returnStatus = (string) ($_POST['return_status'] ?? 'all');
    if ($returnQuery !== '') {
        $return['q'] = $returnQuery;
    }
    if ($returnYear !== 'all' && in_array($returnYear, portal_course_year_options($catalog), true)) {
        $return['year'] = $returnYear;
    }
    if (in_array($returnStatus, ['open', 'completed', 'archived'], true)) {
        $return['status'] = $returnStatus;
    }
    portal_redirect('courses.php' . ($return ? '?' . http_build_query($return) : ''));
}

$filteredCourses = array_values(array_filter(
    $catalog,
    static function (array $course) use ($query, $yearFilter, $statusFilter): bool {
        if ($yearFilter !== 'all' && $course['year_group'] !== $yearFilter) {
            return false;
        }

        if ($statusFilter !== 'all' && $course['status'] !== $statusFilter) {
            return false;
        }

        if ($query === '') {
            return true;
        }

        $haystack = implode(' ', [
            $course['code'],
            $course['title'],
            $course['full_title'],
            $course['summary'],
            $course['room'],
            $course['meeting'],
            implode(' ', array_column($course['staff'], 'name')),
        ]);

        return stripos($haystack, $query) !== false;
    }
));

$groupedCourses = portal_group_courses_by_year($filteredCourses);
$favoriteCourseIds = is_array($customPrefs['favorite_course_ids'])
    ? $customPrefs['favorite_course_ids']
    : [];
foreach ($groupedCourses as &$coursesInYear) {
    usort($coursesInYear, static function (array $a, array $b) use ($favoriteCourseIds): int {
        $aFavorite = in_array((int) ($a['id'] ?? 0), $favoriteCourseIds, true);
        $bFavorite = in_array((int) ($b['id'] ?? 0), $favoriteCourseIds, true);
        if ($aFavorite !== $bFavorite) {
            return $aFavorite ? -1 : 1;
        }
        return strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
    });
}
unset($coursesInYear);
$yearOptions = portal_course_year_options($catalog);
$resultCount = count($filteredCourses);

ob_start();
?>
<section class="course-browser">
    <article class="card-shell course-filter-card">
        <div class="section-head course-filter-head">
            <div class="course-filter-intro">
                <p class="eyebrow">Browse</p>
                <h3 class="card-title">Find a course</h3>
                <p class="course-filter-lead">Each course opens into the same shared student structure so navigation stays consistent across the portal.</p>
            </div>
            <span class="chip course-filter-count"><?= $resultCount ?> results</span>
        </div>

        <form class="course-filter-form" method="get" action="courses.php">
            <label class="course-filter-field course-filter-field--search">
                <span>Search</span>
                <input type="search" name="q" value="<?= portal_escape($query) ?>" placeholder="Search by title, code, teacher, or room">
            </label>

            <label class="course-filter-field course-filter-field--year">
                <span>Academic year</span>
                <select name="year">
                    <option value="all">All years</option>
                    <?php foreach ($yearOptions as $option): ?>
                        <option value="<?= portal_escape($option) ?>"<?= $yearFilter === $option ? ' selected' : '' ?>><?= portal_escape($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="course-filter-field course-filter-field--status">
                <span>Status</span>
                <select name="status">
                    <option value="all"<?= $statusFilter === 'all' ? ' selected' : '' ?>>All courses</option>
                    <option value="open"<?= $statusFilter === 'open' ? ' selected' : '' ?>>Open now</option>
                    <option value="completed"<?= $statusFilter === 'completed' ? ' selected' : '' ?>>Completed</option>
                    <option value="archived"<?= $statusFilter === 'archived' ? ' selected' : '' ?>>Archived</option>
                </select>
            </label>

            <div class="course-filter-actions">
                <button class="button" type="submit">Update list</button>
                <a class="button-secondary course-filter-clear" href="courses.php">Clear</a>
            </div>
        </form>
    </article>

    <?php if ($resultCount === 0): ?>
        <article class="card-shell course-empty-state">
            <h3 class="card-title">No courses match those filters.</h3>
            <p>Try a broader search or clear the filters to see every course again.</p>
        </article>
    <?php else: ?>
        <?php foreach ($groupedCourses as $year => $courses): ?>
            <article class="card-shell course-year-shell">
                <div class="section-head course-year-head">
                    <div>
                        <p class="eyebrow">Academic year</p>
                        <h3 class="card-title"><?= portal_escape($year) ?></h3>
                    </div>
                    <span class="chip"><?= count($courses) ?> courses</span>
                </div>

                <div class="course-list">
                    <?php foreach ($courses as $course): ?>
                        <?php
                        $studentCount = (int) ($course['student_count'] ?? 0);
                        $staffNames = implode(', ', array_column($course['staff'] ?? [], 'name'));
                        $isFavorite = in_array((int) $course['id'], $favoriteCourseIds, true);
                        ?>
                        <div class="course-list-item-shell<?= $isFavorite ? ' is-favorite' : '' ?>">
                            <a class="course-list-item" href="course.php?course=<?= portal_escape($course['slug']) ?>&amp;section=content" style="--course-accent: <?= portal_escape($course['accent']) ?>;">
                                <span class="course-list-accent" aria-hidden="true"></span>

                                <div class="course-list-copy">
                                    <p class="course-list-code"><?= portal_escape($course['code']) ?></p>
                                    <h3><?= portal_escape($course['title']) ?></h3>
                                    <p class="course-list-summary"><?= portal_escape($course['summary']) ?></p>

                                    <div class="course-list-meta">
                                        <?php if ($staffCourseView): ?>
                                            <?php if ($studentCount > 0): ?>
                                                <span><?= $studentCount ?> students</span>
                                            <?php endif; ?>
                                            <span><?= portal_escape($course['meeting']) ?></span>
                                            <span><?= portal_escape($course['room']) ?></span>
                                        <?php else: ?>
                                            <?php if ($staffNames !== ''): ?>
                                                <span><?= portal_escape($staffNames) ?></span>
                                            <?php endif; ?>
                                            <span><?= portal_escape($course['meeting']) ?></span>
                                            <span><?= portal_escape($course['room']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="course-list-right">
                                    <span class="course-status-pill<?= $course['status'] === 'open' ? ' active' : '' ?>"><?= portal_escape($course['status_label']) ?></span>
                                    <span class="course-list-link">Open course</span>
                                </div>
                            </a>
                            <form method="POST" class="course-favorite-form">
                                <?= portal_csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_favorite_course">
                                <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
                                <input type="hidden" name="return_q" value="<?= portal_escape($query) ?>">
                                <input type="hidden" name="return_year" value="<?= portal_escape($yearFilter) ?>">
                                <input type="hidden" name="return_status" value="<?= portal_escape($statusFilter) ?>">
                                <button type="submit"
                                        class="course-favorite-button<?= $isFavorite ? ' is-favorite' : '' ?>"
                                        aria-label="<?= $isFavorite ? 'Remove ' : 'Add ' ?><?= portal_escape($course['title']) ?> <?= $isFavorite ? 'from' : 'to' ?> favourites"
                                        title="<?= $isFavorite ? 'Remove from favourites' : 'Add to favourites' ?>"
                                        aria-pressed="<?= $isFavorite ? 'true' : 'false' ?>">&#9733;</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
<?php
$page_content = ob_get_clean();

require __DIR__ . '/../layout.php';
