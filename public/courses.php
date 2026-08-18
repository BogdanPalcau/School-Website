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
    if (in_array($returnStatus, ['open', 'closed', 'completed', 'archived'], true)) {
        $return['status'] = $returnStatus;
    }
    portal_redirect('courses.php' . ($return ? '?' . http_build_query($return) : ''));
}

$courseSearchText = static function (array $course): string {
    return strtolower(trim(implode(' ', [
        (string) ($course['code'] ?? ''),
        (string) ($course['title'] ?? ''),
        (string) ($course['full_title'] ?? ''),
        (string) ($course['summary'] ?? ''),
        (string) ($course['room'] ?? ''),
        (string) ($course['meeting'] ?? ''),
        implode(' ', array_column($course['staff'] ?? [], 'name')),
    ])));
};

$matchesBrowse = static function (array $course) use ($query, $yearFilter, $courseSearchText): bool {
    if ($yearFilter !== 'all' && $course['year_group'] !== $yearFilter) {
        return false;
    }
    if ($query === '') {
        return true;
    }

    return str_contains($courseSearchText($course), strtolower($query));
};

$courseIsListed = static function (array $course, bool $ignoreStatus) use ($matchesBrowse, $statusFilter): bool {
    if (!$matchesBrowse($course)) {
        return false;
    }
    if (!$ignoreStatus && $statusFilter !== 'all' && ($course['status'] ?? '') !== $statusFilter) {
        return false;
    }

    return true;
};

$favoriteCourseIds = is_array($customPrefs['favorite_course_ids'])
    ? $customPrefs['favorite_course_ids']
    : [];

$sortCourseCards = static function (array &$coursesInYear) use ($favoriteCourseIds): void {
    usort($coursesInYear, static function (array $a, array $b) use ($favoriteCourseIds): int {
        $aFavorite = in_array((int) ($a['id'] ?? 0), $favoriteCourseIds, true);
        $bFavorite = in_array((int) ($b['id'] ?? 0), $favoriteCourseIds, true);
        if ($aFavorite !== $bFavorite) {
            return $aFavorite ? -1 : 1;
        }
        return strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
    });
};

$pinnedCourses = array_values(array_filter(
    $catalog,
    static function (array $course) use ($favoriteCourseIds): bool {
        return in_array((int) ($course['id'] ?? 0), $favoriteCourseIds, true);
    }
));
$sortCourseCards($pinnedCourses);
$pinnedIds = array_map(static fn(array $course): int => (int) $course['id'], $pinnedCourses);

$currentCourses = array_values(array_filter(
    $catalog,
    static function (array $course) use ($pinnedIds): bool {
        if (in_array((int) $course['id'], $pinnedIds, true)) {
            return false;
        }
        return (string) ($course['status'] ?? '') !== 'archived';
    }
));
$archivedCourses = array_values(array_filter(
    $catalog,
    static function (array $course) use ($pinnedIds): bool {
        if (in_array((int) $course['id'], $pinnedIds, true)) {
            return false;
        }
        return (string) ($course['status'] ?? '') === 'archived';
    }
));

$groupedCourses = portal_group_courses_by_year($currentCourses);
foreach ($groupedCourses as &$coursesInYear) {
    $sortCourseCards($coursesInYear);
}
unset($coursesInYear);
$sortCourseCards($archivedCourses);

$yearOptions = portal_course_year_options($catalog);
$showCurrent = $statusFilter !== 'archived';
$showArchived = $statusFilter === 'all' || $statusFilter === 'archived';

$countListed = static function (array $courses, bool $ignoreStatus) use ($courseIsListed): int {
    $count = 0;
    foreach ($courses as $course) {
        if ($courseIsListed($course, $ignoreStatus)) {
            $count++;
        }
    }

    return $count;
};

$pinnedVisibleCount = $countListed($pinnedCourses, true);
$currentVisibleCount = $showCurrent ? $countListed($currentCourses, false) : 0;
$archivedVisibleCount = $showArchived ? $countListed($archivedCourses, false) : 0;
$resultCount = $pinnedVisibleCount + $currentVisibleCount + $archivedVisibleCount;

$renderCourseCards = static function (array $courses, bool $ignoreStatus = false) use ($staffCourseView, $favoriteCourseIds, $query, $yearFilter, $statusFilter, $courseSearchText, $courseIsListed): void {
    foreach ($courses as $course) {
        $studentCount = (int) ($course['student_count'] ?? 0);
        $staffNames = implode(', ', array_column($course['staff'] ?? [], 'name'));
        $isFavorite = in_array((int) $course['id'], $favoriteCourseIds, true);
        $courseLocked = !$staffCourseView && !portal_course_student_may_enter($course);
        $isListed = $courseIsListed($course, $ignoreStatus);
        ?>
        <div class="course-list-item-shell<?= $isFavorite ? ' is-favorite' : '' ?>"
             data-course-item
             data-course-search="<?= portal_escape($courseSearchText($course)) ?>"
             data-course-year="<?= portal_escape((string) ($course['year_group'] ?? '')) ?>"
             data-course-status="<?= portal_escape((string) ($course['status'] ?? '')) ?>"
             data-course-favorite="<?= $isFavorite ? '1' : '0' ?>"
             <?php if (!$isListed): ?>hidden<?php endif; ?>>
            <a class="course-list-item<?= $courseLocked ? ' is-course-locked' : '' ?>"
               href="course.php?course=<?= portal_escape($course['slug']) ?>&amp;section=content"
               style="--course-accent: <?= portal_escape($course['accent']) ?>;"
               <?php if ($courseLocked): ?>data-course-locked="1" aria-disabled="true"<?php endif; ?>>
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
                    <span class="course-status-pill<?= portal_course_status_pill_class((string) $course['status']) ?>"><?= portal_escape($course['status_label']) ?></span>
                    <span class="course-list-link"><?= $courseLocked ? 'Closed' : 'Open course' ?></span>
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
        <?php
    }
};

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
            <span class="chip course-filter-count" aria-live="polite"><?= $resultCount ?> result<?= $resultCount === 1 ? '' : 's' ?></span>
        </div>

        <form class="course-filter-form" method="get" action="courses.php" data-live-filter>
            <label class="course-filter-field course-filter-field--search">
                <span>Search</span>
                <input type="search" name="q" value="<?= portal_escape($query) ?>" placeholder="Search by title, code, teacher, or room" autocomplete="off">
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
                    <option value="closed"<?= $statusFilter === 'closed' ? ' selected' : '' ?>>Closed</option>
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

    <article class="card-shell course-empty-state"<?= $resultCount > 0 ? ' hidden' : '' ?>>
            <h3 class="card-title">No courses match those filters.</h3>
            <p>Try a broader search or clear the filters to see every course again.</p>
        </article>

        <?php if ($pinnedCourses !== []): ?>
            <article class="card-shell course-year-shell course-year-shell--pinned" data-course-section="pinned"<?= $pinnedVisibleCount === 0 ? ' hidden' : '' ?>>
                <div class="section-head course-year-head">
                    <div>
                        <p class="eyebrow">Favourites</p>
                        <h3 class="card-title course-pinned-title"><?= portal_icon('pin', 'icon-sm') ?> Pinned</h3>
                    </div>
                    <span class="chip" data-course-count><?= $pinnedVisibleCount ?></span>
                </div>
                <div class="course-list">
                    <?php $renderCourseCards($pinnedCourses, true); ?>
                </div>
            </article>
        <?php endif; ?>

        <?php if ($groupedCourses !== []): ?>
            <?php foreach ($groupedCourses as $year => $courses): ?>
                <?php $yearVisibleCount = $showCurrent ? $countListed($courses, false) : 0; ?>
                <article class="card-shell course-year-shell" data-course-section="current"<?= $yearVisibleCount === 0 ? ' hidden' : '' ?>>
                    <div class="section-head course-year-head">
                        <div>
                            <p class="eyebrow">Academic year</p>
                            <h3 class="card-title"><?= portal_escape($year) ?></h3>
                        </div>
                        <span class="chip" data-course-count data-course-count-label="courses"><?= $yearVisibleCount ?> course<?= $yearVisibleCount === 1 ? '' : 's' ?></span>
                    </div>
                    <div class="course-list">
                        <?php $renderCourseCards($courses); ?>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($archivedCourses !== []): ?>
            <article class="card-shell course-year-shell course-year-shell--archived" data-course-section="archived"<?= $archivedVisibleCount === 0 ? ' hidden' : '' ?>>
                <div class="section-head course-year-head">
                    <div>
                        <p class="eyebrow">Past modules</p>
                        <h3 class="card-title">Archived</h3>
                    </div>
                    <span class="chip" data-course-count><?= $archivedVisibleCount ?></span>
                </div>
                <div class="course-list">
                    <?php $renderCourseCards($archivedCourses); ?>
                </div>
            </article>
        <?php endif; ?>
    </section>
    <script>
    (function () {
        var browser = document.querySelector('.course-browser');
        var form = browser && browser.querySelector('[data-live-filter]');
        if (!browser || !form) return;

        var searchInput = form.querySelector('input[name="q"]');
        var yearSelect = form.querySelector('select[name="year"]');
        var statusSelect = form.querySelector('select[name="status"]');
        var countEl = browser.querySelector('.course-filter-count');
        var emptyEl = browser.querySelector('.course-empty-state');

        function applyFilters() {
            var query = (searchInput ? searchInput.value : '').trim();
            var queryLower = query.toLowerCase();
            var year = yearSelect ? yearSelect.value : 'all';
            var status = statusSelect ? statusSelect.value : 'all';
            var total = 0;

            browser.querySelectorAll('[data-course-item]').forEach(function (item) {
                var hay = item.getAttribute('data-course-search') || '';
                var itemYear = item.getAttribute('data-course-year') || '';
                var itemStatus = item.getAttribute('data-course-status') || '';
                var inPinned = !!item.closest('[data-course-section="pinned"]');
                var matchSearch = queryLower === '' || hay.indexOf(queryLower) !== -1;
                var matchYear = year === 'all' || itemYear === year;
                var matchStatus = inPinned || status === 'all' || itemStatus === status;
                var show = matchSearch && matchYear && matchStatus;
                item.hidden = !show;
                if (show) total += 1;

                var returnQ = item.querySelector('input[name="return_q"]');
                var returnYear = item.querySelector('input[name="return_year"]');
                var returnStatus = item.querySelector('input[name="return_status"]');
                if (returnQ) returnQ.value = query;
                if (returnYear) returnYear.value = year;
                if (returnStatus) returnStatus.value = status;
            });

            browser.querySelectorAll('[data-course-section]').forEach(function (section) {
                var kind = section.getAttribute('data-course-section');
                var visibleItems = section.querySelectorAll('[data-course-item]:not([hidden])').length;
                var showSection = visibleItems > 0;
                if (kind === 'current') {
                    showSection = showSection && status !== 'archived';
                } else if (kind === 'archived') {
                    showSection = showSection && (status === 'all' || status === 'archived');
                }
                section.hidden = !showSection;

                var chip = section.querySelector('[data-course-count]');
                if (chip) {
                    if (chip.getAttribute('data-course-count-label') === 'courses') {
                        chip.textContent = visibleItems + ' course' + (visibleItems === 1 ? '' : 's');
                    } else {
                        chip.textContent = String(visibleItems);
                    }
                }
            });

            if (countEl) {
                countEl.textContent = total + ' result' + (total === 1 ? '' : 's');
            }
            if (emptyEl) {
                emptyEl.hidden = total > 0;
            }

            var url = new URL(window.location.href);
            url.searchParams.delete('q');
            url.searchParams.delete('year');
            url.searchParams.delete('status');
            if (query !== '') url.searchParams.set('q', query);
            if (year !== 'all') url.searchParams.set('year', year);
            if (status !== 'all') url.searchParams.set('status', status);
            var next = url.pathname + url.search + url.hash;
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', next);
            }
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            applyFilters();
        });
        if (searchInput) {
            searchInput.addEventListener('input', applyFilters);
            searchInput.addEventListener('search', applyFilters);
        }
        if (yearSelect) yearSelect.addEventListener('change', applyFilters);
        if (statusSelect) statusSelect.addEventListener('change', applyFilters);

        var clearLink = form.querySelector('.course-filter-clear');
        if (clearLink) {
            clearLink.addEventListener('click', function (event) {
                event.preventDefault();
                if (searchInput) searchInput.value = '';
                if (yearSelect) yearSelect.value = 'all';
                if (statusSelect) statusSelect.value = 'all';
                applyFilters();
                if (searchInput) searchInput.focus();
            });
        }
    })();
    </script>
<?php
$page_content = ob_get_clean();

require __DIR__ . '/../layout.php';
