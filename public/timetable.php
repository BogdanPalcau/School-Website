<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

portal_require_login();

$page_title = 'Timetable | ' . portal_school_name();
$active_page = 'timetable';
$page_eyebrow = 'Weekly schedule';
$page_heading = 'Timetable';
$page_description = 'Your live class schedule from the modules you can access.';

$db = portal_db();
$me = portal_current_user();
$dayOrder = [
    'Monday' => 1,
    'Tuesday' => 2,
    'Wednesday' => 3,
    'Thursday' => 4,
    'Friday' => 5,
    'Saturday' => 6,
    'Sunday' => 7,
];
$dayLabels = array_keys($dayOrder);
$todayName = date('l');

if (portal_is_admin()) {
    $stmt = $db->query(
        "SELECT cs.*, c.id AS course_id, c.slug, c.title, c.code, c.accent
         FROM course_schedule cs
         JOIN courses c ON c.id = cs.course_id
         ORDER BY
            CASE cs.day_of_week
                WHEN 'Monday' THEN 1 WHEN 'Tuesday' THEN 2 WHEN 'Wednesday' THEN 3
                WHEN 'Thursday' THEN 4 WHEN 'Friday' THEN 5 WHEN 'Saturday' THEN 6
                WHEN 'Sunday' THEN 7 ELSE 8
            END,
            cs.start_time ASC,
            c.title ASC"
    );
    $scheduleRows = $stmt->fetchAll();
} elseif (portal_is_course_staff()) {
    $stmt = $db->prepare(
        "SELECT cs.*, c.id AS course_id, c.slug, c.title, c.code, c.accent
         FROM course_schedule cs
         JOIN courses c ON c.id = cs.course_id
         JOIN course_teachers ct ON ct.course_id = c.id
         WHERE ct.user_id = ?
         ORDER BY
            CASE cs.day_of_week
                WHEN 'Monday' THEN 1 WHEN 'Tuesday' THEN 2 WHEN 'Wednesday' THEN 3
                WHEN 'Thursday' THEN 4 WHEN 'Friday' THEN 5 WHEN 'Saturday' THEN 6
                WHEN 'Sunday' THEN 7 ELSE 8
            END,
            cs.start_time ASC,
            c.title ASC"
    );
    $stmt->execute([(int) $me['id']]);
    $scheduleRows = $stmt->fetchAll();
} else {
    $stmt = $db->prepare(
        "SELECT cs.*, c.id AS course_id, c.slug, c.title, c.code, c.accent
         FROM course_schedule cs
         JOIN courses c ON c.id = cs.course_id
         JOIN enrollments e ON e.course_id = c.id
         WHERE e.user_id = ?
         ORDER BY
            CASE cs.day_of_week
                WHEN 'Monday' THEN 1 WHEN 'Tuesday' THEN 2 WHEN 'Wednesday' THEN 3
                WHEN 'Thursday' THEN 4 WHEN 'Friday' THEN 5 WHEN 'Saturday' THEN 6
                WHEN 'Sunday' THEN 7 ELSE 8
            END,
            cs.start_time ASC,
            c.title ASC"
    );
    $stmt->execute([(int) $me['id']]);
    $scheduleRows = $stmt->fetchAll();
}

$scheduleByDay = array_fill_keys($dayLabels, []);
foreach ($scheduleRows as $row) {
    $day = (string) ($row['day_of_week'] ?? '');
    if (!isset($scheduleByDay[$day])) {
        continue;
    }
    $scheduleByDay[$day][] = $row;
}

$courseIds = [];
foreach ($scheduleRows as $row) {
    $courseIds[(int) $row['course_id']] = true;
}
$activeDayCount = count(array_filter($scheduleByDay, static fn(array $slots): bool => !empty($slots)));

$nextClass = null;
$todayOrder = $dayOrder[$todayName] ?? 1;
foreach ($scheduleRows as $row) {
    $rowOrder = $dayOrder[(string) $row['day_of_week']] ?? 8;
    if ($rowOrder >= $todayOrder) {
        $nextClass = $row;
        break;
    }
}
if ($nextClass === null && !empty($scheduleRows)) {
    $nextClass = $scheduleRows[0];
}

$formatTimeRange = static function (array $slot): string {
    $start = trim((string) ($slot['start_time'] ?? ''));
    $end = trim((string) ($slot['end_time'] ?? ''));
    if ($start === '' && $end === '') {
        return 'Time TBA';
    }
    if ($end === '') {
        return $start;
    }
    return $start . ' - ' . $end;
};

$isJoinUrl = static function (array $slot): bool {
    $url = trim((string) ($slot['room'] ?? ''));
    return $url !== '' && (bool) preg_match('/^https?:\/\//i', $url);
};

ob_start();
?>
<section class="calendar-layout" id="week-schedule">
    <article class="card-shell" id="week-view">
        <div class="section-head">
            <div>
                <p class="eyebrow">Week view</p>
                <h3 class="card-title">Your weekly schedule</h3>
                <p>Live classes from your modules.</p>
            </div>
            <span class="chip"><?= count($scheduleRows) ?> class<?= count($scheduleRows) === 1 ? '' : 'es' ?></span>
        </div>

        <div class="calendar-overview">
            <div class="calendar-overview-item calendar-overview-item--strong">
                <span>Classes</span>
                <strong><?= count($scheduleRows) ?></strong>
            </div>
            <div class="calendar-overview-item">
                <span>Today</span>
                <strong><?= count($scheduleByDay[$todayName] ?? []) ?></strong>
            </div>
            <div class="calendar-overview-item">
                <span>Active days</span>
                <strong><?= $activeDayCount ?></strong>
            </div>
            <div class="calendar-overview-item">
                <span>Modules</span>
                <strong><?= count($courseIds) ?></strong>
            </div>
        </div>

        <div class="calendar-grid calendar-grid--schedule">
            <?php foreach ($dayLabels as $day): ?>
                <?php $slots = $scheduleByDay[$day] ?? []; ?>
                <article class="calendar-card<?= $day === $todayName ? ' featured' : '' ?><?= empty($slots) ? ' calendar-card--empty' : '' ?>">
                    <div class="calendar-day-head">
                        <span class="calendar-date"><?= portal_escape(substr($day, 0, 3)) ?></span>
                        <div>
                            <h3><?= portal_escape($day) ?></h3>
                            <p class="calendar-meta"><?= count($slots) ?> class<?= count($slots) === 1 ? '' : 'es' ?></p>
                        </div>
                    </div>

                    <?php if (empty($slots)): ?>
                        <div class="calendar-empty-slot">No classes</div>
                    <?php else: ?>
                        <div class="calendar-class-list">
                            <?php foreach ($slots as $slot): ?>
                                <?php
                                    $joinUrl = trim((string) ($slot['room'] ?? ''));
                                    $hasJoin = $isJoinUrl($slot);
                                    $detailsId = 'calendar-slot-' . (int) $slot['id'];
                                ?>
                                <details class="calendar-class" id="<?= portal_escape($detailsId) ?>">
                                    <summary>
                                        <span class="calendar-class-accent" style="background:<?= portal_escape((string) $slot['accent']) ?>"></span>
                                        <span>
                                            <strong><?= portal_escape((string) $slot['title']) ?></strong>
                                            <small>
                                                <span><?= portal_escape($formatTimeRange($slot)) ?></span>
                                                <span><?= portal_escape((string) $slot['code']) ?></span>
                                            </small>
                                        </span>
                                        <span class="calendar-class-chevron">&rsaquo;</span>
                                    </summary>
                                    <div class="calendar-class-detail">
                                        <?php if (trim((string) ($slot['notes'] ?? '')) !== ''): ?>
                                            <p><?= portal_escape((string) $slot['notes']) ?></p>
                                        <?php endif; ?>
                                        <div class="calendar-class-actions">
                                            <a class="inline-action" href="course.php?course=<?= urlencode((string) $slot['slug']) ?>&section=calendar">Course calendar</a>
                                            <?php if ($hasJoin): ?>
                                                <a class="calendar-join-button" href="<?= portal_escape($joinUrl) ?>" target="_blank" rel="noopener noreferrer">Join class</a>
                                            <?php else: ?>
                                                <span class="calendar-no-link">No join link set</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </details>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>

        <?php
            $quietDayLabels = [];
            foreach ($dayLabels as $day) {
                if (empty($scheduleByDay[$day]) && $day !== $todayName) {
                    $quietDayLabels[] = substr($day, 0, 3);
                }
            }
        ?>
        <?php if (!empty($quietDayLabels)): ?>
            <p class="calendar-quiet-days">No classes: <?= portal_escape(implode(' · ', $quietDayLabels)) ?></p>
        <?php endif; ?>
    </article>

    <div class="stack">
        <article class="card-shell">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Today</p>
                    <h3 class="card-title"><?= portal_escape($todayName) ?></h3>
                </div>
                <span class="chip"><?= count($scheduleByDay[$todayName] ?? []) ?> class<?= count($scheduleByDay[$todayName] ?? []) === 1 ? '' : 'es' ?></span>
            </div>

            <div class="deadline-list calendar-today-list">
                <?php if (empty($scheduleByDay[$todayName] ?? [])): ?>
                    <article class="deadline-item calendar-side-item">
                        <div>
                            <h3>No classes today</h3>
                            <p class="section-copy">Your enrolled modules do not have any schedule slots for today.</p>
                        </div>
                    </article>
                <?php else: ?>
                    <?php foreach ($scheduleByDay[$todayName] as $slot): ?>
                        <article class="deadline-item calendar-side-item">
                            <div>
                                <h3><?= portal_escape((string) $slot['title']) ?></h3>
                                <p class="section-copy"><?= portal_escape((string) $slot['code']) ?><?= trim((string) ($slot['notes'] ?? '')) !== '' ? ' - ' . portal_escape((string) $slot['notes']) : '' ?></p>
                            </div>
                            <span class="score-chip">
                                <strong><?= portal_escape($formatTimeRange($slot)) ?></strong>
                            </span>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>

        <article class="card-shell">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Next</p>
                    <h3 class="card-title">Next class</h3>
                </div>
            </div>
            <article class="schedule-note">
                <?php if ($nextClass === null): ?>
                    <div>
                        <h3>No scheduled classes</h3>
                        <p>Your enrolled modules do not have any weekly schedule slots yet.</p>
                    </div>
                    <a class="inline-action" href="courses.php">Open modules</a>
                <?php else: ?>
                    <?php $nextJoinUrl = trim((string) ($nextClass['room'] ?? '')); ?>
                    <div class="calendar-next-class">
                        <span class="calendar-class-accent" style="background:<?= portal_escape((string) $nextClass['accent']) ?>"></span>
                        <div>
                        <h3><?= portal_escape((string) $nextClass['title']) ?></h3>
                        <p><?= portal_escape((string) $nextClass['day_of_week']) ?> at <?= portal_escape($formatTimeRange($nextClass)) ?>.</p>
                        </div>
                    </div>
                    <?php if ($isJoinUrl($nextClass)): ?>
                        <a class="calendar-join-button" href="<?= portal_escape($nextJoinUrl) ?>" target="_blank" rel="noopener noreferrer">Join</a>
                    <?php else: ?>
                        <a class="inline-action" href="course.php?course=<?= urlencode((string) $nextClass['slug']) ?>&section=calendar">Details</a>
                    <?php endif; ?>
                <?php endif; ?>
            </article>
        </article>
    </div>
</section>
<?php
$page_content = ob_get_clean();

require __DIR__ . '/../layout.php';
