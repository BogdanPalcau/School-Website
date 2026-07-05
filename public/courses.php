<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../course_catalog.php';

portal_require_login();

$page_title = 'Courses | ' . portal_school_name();
$active_page = 'courses';
$page_eyebrow = 'Course browser';
$page_heading = 'Courses';
$page_description = 'Browse current and archived course spaces. Each course follows a consistent student layout so materials, notices, discussions, and progress are easy to find.';

$currentUser  = portal_current_user();
$catalog      = portal_user_course_catalog((int) $currentUser['id']);
$query        = trim((string) ($_GET['q'] ?? ''));
$yearFilter   = (string) ($_GET['year'] ?? 'all');
$statusFilter = (string) ($_GET['status'] ?? 'all');

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
$yearOptions = portal_course_year_options($catalog);
$resultCount = count($filteredCourses);

ob_start();
?>
<section class="course-browser">
    <article class="card-shell">
        <div class="section-head">
            <div>
                <p class="eyebrow">Browse</p>
                <h3 class="card-title">Find a course</h3>
                <p>Each course opens into the same shared student structure so navigation stays consistent across the portal.</p>
            </div>
            <span class="chip"><?= $resultCount ?> results</span>
        </div>

        <form class="course-filter-form" method="get" action="courses.php">
            <label class="course-filter-field">
                <span>Search</span>
                <input type="search" name="q" value="<?= portal_escape($query) ?>" placeholder="Search by title, code, teacher, or room">
            </label>

            <label class="course-filter-field">
                <span>Academic year</span>
                <select name="year">
                    <option value="all">All years</option>
                    <?php foreach ($yearOptions as $option): ?>
                        <option value="<?= portal_escape($option) ?>"<?= $yearFilter === $option ? ' selected' : '' ?>><?= portal_escape($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="course-filter-field">
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
                <a class="button-secondary" href="courses.php">Clear</a>
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
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Academic year</p>
                        <h3 class="card-title"><?= portal_escape($year) ?></h3>
                    </div>
                    <span class="chip"><?= count($courses) ?> courses</span>
                </div>

                <div class="course-list">
                    <?php foreach ($courses as $course): ?>
                        <a class="course-list-item" href="course.php?course=<?= portal_escape($course['slug']) ?>&amp;section=content" style="--course-accent: <?= portal_escape($course['accent']) ?>;">
                            <span class="course-list-accent" aria-hidden="true"></span>

                            <div class="course-list-copy">
                                <p class="course-list-code"><?= portal_escape($course['code']) ?></p>
                                <h3><?= portal_escape($course['full_title']) ?></h3>
                                <p class="course-list-summary"><?= portal_escape($course['summary']) ?></p>

                                <div class="course-list-meta">
                                    <span><?= portal_escape($course['status_label']) ?></span>
                                    <span><?= portal_escape(implode(', ', array_column($course['staff'], 'name'))) ?></span>
                                    <span><?= portal_escape($course['meeting']) ?></span>
                                    <span><?= portal_escape($course['room']) ?></span>
                                </div>
                            </div>

                            <div class="course-list-right">
                                <span class="course-status-pill<?= $course['status'] === 'open' ? ' active' : '' ?>"><?= portal_escape($course['term']) ?></span>
                                <span class="course-list-link">Open course</span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
<?php
$page_content = ob_get_clean();

require __DIR__ . '/../layout.php';
