<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../course_catalog.php';

portal_require_login();

$db  = portal_db();
$me  = portal_current_user();
$uid = (int) $me['id'];
$isStaff = portal_is_course_staff();
$isAdmin = portal_is_admin();
$firstName = trim(explode(' ', (string) ($me['name'] ?? 'there'))[0] ?: 'there');

$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

$catalog = portal_user_course_catalog($uid);
$courseIds = array_map(static fn(array $c): int => (int) $c['id'], $catalog);

$dayOrder = [
    'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4,
    'Friday' => 5, 'Saturday' => 6, 'Sunday' => 7,
];
$todayName = date('l');
$todayOrder = $dayOrder[$todayName] ?? 1;

$formatTime = static function (array $slot): string {
    $start = trim((string) ($slot['start_time'] ?? ''));
    $end = trim((string) ($slot['end_time'] ?? ''));
    if ($start === '' && $end === '') {
        return 'Time TBA';
    }
    if ($end === '') {
        return $start;
    }
    return $start . ' – ' . $end;
};

$isJoinUrl = static function (array $slot): bool {
    $url = trim((string) ($slot['room'] ?? ''));
    return $url !== '' && (bool) preg_match('/^https?:\/\//i', $url);
};

$relativeWhen = static function (string $raw): string {
    return portal_relative_time($raw);
};

$waitLabel = static function (string $raw): string {
    return portal_wait_label($raw);
};

$formatVideoStamp = static function (int $seconds): string {
    if ($seconds <= 0) {
        return '';
    }
    return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
};

// ── Schedule: today + next class fallback ─────────────────────────────────────
$scheduleRows = [];
if ($isAdmin) {
    $scheduleRows = $db->query(
        "SELECT cs.*, c.slug, c.title, c.code, c.accent
         FROM course_schedule cs
         JOIN courses c ON c.id = cs.course_id
         ORDER BY
            CASE cs.day_of_week
                WHEN 'Monday' THEN 1 WHEN 'Tuesday' THEN 2 WHEN 'Wednesday' THEN 3
                WHEN 'Thursday' THEN 4 WHEN 'Friday' THEN 5 WHEN 'Saturday' THEN 6
                WHEN 'Sunday' THEN 7 ELSE 8
            END,
            cs.start_time ASC"
    )->fetchAll();
} elseif ($isStaff) {
    $stmt = $db->prepare(
        "SELECT cs.*, c.slug, c.title, c.code, c.accent
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
            cs.start_time ASC"
    );
    $stmt->execute([$uid]);
    $scheduleRows = $stmt->fetchAll();
} elseif (!empty($courseIds)) {
    $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
    $stmt = $db->prepare(
        "SELECT cs.*, c.slug, c.title, c.code, c.accent
         FROM course_schedule cs
         JOIN courses c ON c.id = cs.course_id
         WHERE cs.course_id IN ($placeholders)
         ORDER BY
            CASE cs.day_of_week
                WHEN 'Monday' THEN 1 WHEN 'Tuesday' THEN 2 WHEN 'Wednesday' THEN 3
                WHEN 'Thursday' THEN 4 WHEN 'Friday' THEN 5 WHEN 'Saturday' THEN 6
                WHEN 'Sunday' THEN 7 ELSE 8
            END,
            cs.start_time ASC"
    );
    $stmt->execute($courseIds);
    $scheduleRows = $stmt->fetchAll();
}

$todayClasses = [];
$nextClass = null;
$nowHm = date('H:i');
foreach ($scheduleRows as $row) {
    $day = (string) ($row['day_of_week'] ?? '');
    $rowOrder = $dayOrder[$day] ?? 8;
    if ($day === $todayName) {
        $todayClasses[] = $row;
        $start = trim((string) ($row['start_time'] ?? ''));
        if ($nextClass === null && ($start === '' || $start >= $nowHm)) {
            $nextClass = $row;
        }
    } elseif ($nextClass === null && $rowOrder > $todayOrder) {
        $nextClass = $row;
    }
}
if ($nextClass === null && !empty($scheduleRows)) {
    $nextClass = $scheduleRows[0];
}
if ($nextClass === null && !empty($todayClasses)) {
    $nextClass = $todayClasses[0];
}

// ── Role-specific queues ──────────────────────────────────────────────────────
$upcomingDeadlines = [];
$returnedGrades = [];
$continueWatching = [];
$recentAnswers = [];
$pendingToMark = [];
$pendingQuestions = [];
$moduleWorkload = [];
$teacherDeadlines = [];
$assignedIds = [];

if (!$isStaff && !$isAdmin && !empty($courseIds)) {
    $placeholders = implode(',', array_fill(0, count($courseIds), '?'));

    $stmt = $db->prepare(
        "SELECT cfi.id, cfi.title, cfi.submission_deadline, cfi.course_id,
                c.slug, c.title AS course_title, c.code, c.accent,
                cs.id AS submission_id, cs.score, cs.marked_at
         FROM course_folder_items cfi
         JOIN courses c ON c.id = cfi.course_id
         LEFT JOIN course_submissions cs
                ON cs.item_id = cfi.id AND cs.user_id = ?
         WHERE cfi.type = 'submission'
           AND cfi.submission_deadline != ''
           AND cfi.course_id IN ($placeholders)
         ORDER BY cfi.submission_deadline ASC
         LIMIT 40"
    );
    $stmt->execute(array_merge([$uid], $courseIds));
    $rows = $stmt->fetchAll();

    $now = time();
    $horizon = $now + (21 * 86400);
    foreach ($rows as $row) {
        $info = portal_submission_deadline_info((string) $row['submission_deadline']);
        if (!$info['has_deadline'] || !isset($info['timestamp'])) {
            continue;
        }
        $ts = (int) $info['timestamp'];
        $submitted = !empty($row['submission_id']);
        $marked = trim((string) ($row['marked_at'] ?? '')) !== '';

        if ($ts > $horizon) {
            continue;
        }
        if ($ts < $now - (7 * 86400)) {
            continue;
        }

        $upcomingDeadlines[] = [
            'id'            => (int) $row['id'],
            'title'         => (string) $row['title'],
            'deadline'      => (string) $row['submission_deadline'],
            'deadline_info' => $info,
            'slug'          => (string) $row['slug'],
            'course_title'  => (string) $row['course_title'],
            'code'          => (string) $row['code'],
            'accent'        => (string) $row['accent'],
            'submitted'     => $submitted,
            'marked'        => $marked,
            'score'         => $row['score'],
        ];
        if (count($upcomingDeadlines) >= 8) {
            break;
        }
    }

    $stmt = $db->prepare(
        "SELECT cs.id, cs.score, cs.marked_at, cs.feedback,
                cfi.title AS item_title, cfi.id AS item_id,
                c.slug, c.title AS course_title, c.accent
         FROM course_submissions cs
         JOIN course_folder_items cfi ON cfi.id = cs.item_id
         JOIN courses c ON c.id = cs.course_id
         WHERE cs.user_id = ?
           AND cs.course_id IN ($placeholders)
           AND cs.marked_at != ''
         ORDER BY cs.marked_at DESC
         LIMIT 6"
    );
    $stmt->execute(array_merge([$uid], $courseIds));
    $returnedGrades = $stmt->fetchAll();

    $stmt = $db->prepare(
        "SELECT p.item_id, p.position_seconds, p.updated_at,
                cfi.title AS lesson_title,
                c.slug, c.title AS course_title, c.accent
         FROM course_video_progress p
         JOIN course_folder_items cfi ON cfi.id = p.item_id
         JOIN courses c ON c.id = cfi.course_id
         WHERE p.user_id = ?
           AND cfi.course_id IN ($placeholders)
           AND p.position_seconds >= 30
           AND cfi.type = 'video'
         ORDER BY p.updated_at DESC
         LIMIT 5"
    );
    $stmt->execute(array_merge([$uid], $courseIds));
    $continueWatching = $stmt->fetchAll();

    $stmt = $db->prepare(
        "SELECT id, title, body, link, created_at, read_at
         FROM portal_notifications
         WHERE user_id = ?
         ORDER BY CASE WHEN read_at = '' THEN 0 ELSE 1 END, created_at DESC
         LIMIT 5"
    );
    $stmt->execute([$uid]);
    $recentAnswers = $stmt->fetchAll();
}

if ($isStaff || $isAdmin) {
    $assignedIds = $isAdmin
        ? array_map('intval', $db->query('SELECT id FROM courses')->fetchAll(PDO::FETCH_COLUMN))
        : portal_assigned_course_ids();

    if (!empty($assignedIds)) {
        $placeholders = implode(',', array_fill(0, count($assignedIds), '?'));

        $stmt = $db->prepare(
            "SELECT cs.id, cs.submitted_at, cs.user_id,
                    cfi.title AS item_title, cfi.id AS item_id,
                    c.id AS course_id, c.slug, c.title AS course_title, c.code, c.accent,
                    u.name AS student_name, u.initials AS student_initials
             FROM course_submissions cs
             JOIN course_folder_items cfi ON cfi.id = cs.item_id
             JOIN courses c ON c.id = cs.course_id
             JOIN users u ON u.id = cs.user_id
             WHERE cs.course_id IN ($placeholders)
               AND (cs.marked_at = '' OR cs.marked_at IS NULL)
             ORDER BY cs.submitted_at ASC
             LIMIT 10"
        );
        $stmt->execute($assignedIds);
        $pendingToMark = $stmt->fetchAll();

        $stmt = $db->prepare(
            "SELECT q.id, q.question, q.video_seconds, q.created_at, q.item_id,
                    cfi.title AS lesson_title,
                    c.id AS course_id, c.slug, c.title AS course_title, c.code, c.accent,
                    u.name AS student_name, u.initials AS student_initials
             FROM course_video_questions q
             JOIN course_folder_items cfi ON cfi.id = q.item_id
             JOIN courses c ON c.id = q.course_id
             JOIN users u ON u.id = q.user_id
             WHERE q.course_id IN ($placeholders)
               AND (q.answer = '' OR q.answer IS NULL)
             ORDER BY q.created_at ASC
             LIMIT 10"
        );
        $stmt->execute($assignedIds);
        $pendingQuestions = $stmt->fetchAll();

        $stmt = $db->prepare(
            "SELECT c.id, c.slug, c.title, c.code, c.accent,
                    (SELECT COUNT(*) FROM course_video_questions q
                      WHERE q.course_id = c.id AND (q.answer = '' OR q.answer IS NULL)) AS open_questions,
                    (SELECT COUNT(*) FROM course_submissions cs
                      WHERE cs.course_id = c.id AND (cs.marked_at = '' OR cs.marked_at IS NULL)) AS unmarked
             FROM courses c
             WHERE c.id IN ($placeholders)
             ORDER BY (open_questions + unmarked) DESC, c.title ASC"
        );
        $stmt->execute($assignedIds);
        $moduleWorkload = $stmt->fetchAll();

        $stmt = $db->prepare(
            "SELECT cfi.id, cfi.title, cfi.submission_deadline,
                    c.slug, c.title AS course_title, c.code, c.accent,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS enrolled,
                    (SELECT COUNT(*) FROM course_submissions cs
                      WHERE cs.item_id = cfi.id) AS submitted_count
             FROM course_folder_items cfi
             JOIN courses c ON c.id = cfi.course_id
             WHERE cfi.type = 'submission'
               AND cfi.submission_deadline != ''
               AND cfi.course_id IN ($placeholders)
             ORDER BY cfi.submission_deadline ASC
             LIMIT 30"
        );
        $stmt->execute($assignedIds);
        $now = time();
        $horizon = $now + (14 * 86400);
        foreach ($stmt->fetchAll() as $row) {
            $info = portal_submission_deadline_info((string) $row['submission_deadline']);
            if (!$info['has_deadline'] || !isset($info['timestamp'])) {
                continue;
            }
            $ts = (int) $info['timestamp'];
            if ($ts < $now - (2 * 86400) || $ts > $horizon) {
                continue;
            }
            $teacherDeadlines[] = [
                'title'         => (string) $row['title'],
                'slug'          => (string) $row['slug'],
                'course_title'  => (string) $row['course_title'],
                'accent'        => (string) $row['accent'],
                'deadline_info' => $info,
                'enrolled'      => (int) $row['enrolled'],
                'submitted'     => (int) $row['submitted_count'],
            ];
            if (count($teacherDeadlines) >= 6) {
                break;
            }
        }
    }
}

// ── Announcements ─────────────────────────────────────────────────────────────
$majorAnnouncements = $db->query(
    "SELECT sa.id, sa.title, sa.priority, sa.pinned, sa.created_at,
            u.name AS author_name
     FROM site_announcements sa
     JOIN users u ON u.id = sa.user_id
     ORDER BY sa.pinned DESC, sa.created_at DESC
     LIMIT 3"
)->fetchAll();

$moduleAnnouncements = [];
$annCourseIds = portal_my_announcement_course_ids();
if (!empty($annCourseIds)) {
    $placeholders = implode(',', array_fill(0, count($annCourseIds), '?'));
    $stmt = $db->prepare(
        "SELECT ca.id, ca.title, ca.created_at,
                c.title AS course_title, c.slug AS course_slug, c.accent AS course_accent
         FROM course_announcements ca
         JOIN courses c ON c.id = ca.course_id
         WHERE ca.course_id IN ($placeholders)
         ORDER BY ca.created_at DESC
         LIMIT 3"
    );
    $stmt->execute($annCourseIds);
    $moduleAnnouncements = $stmt->fetchAll();
}

$notifStmt = $db->prepare(
    "SELECT COUNT(*) FROM portal_notifications
     WHERE user_id = ? AND read_at = ''"
);
$notifStmt->execute([$uid]);
$unreadNotifCount = (int) $notifStmt->fetchColumn();

$dueSoonCount = count(array_filter(
    $upcomingDeadlines,
    static fn(array $d): bool => in_array($d['deadline_info']['state'], ['soon', 'closed'], true)
        && !$d['submitted']
));

$openQuestionTotal = (int) array_sum(array_map(
    static fn(array $m): int => (int) ($m['open_questions'] ?? 0),
    $moduleWorkload
));
$unmarkedTotal = (int) array_sum(array_map(
    static fn(array $m): int => (int) ($m['unmarked'] ?? 0),
    $moduleWorkload
));

$page_title = 'Dashboard | ' . portal_school_name();
$active_page = 'dashboard';
$page_eyebrow = 'Overview';
$page_heading = $greeting . ', ' . $firstName;
$page_description = $isStaff || $isAdmin
    ? 'Action items first — unanswered questions, marking queue, and what’s due across your modules.'
    : 'Your day at a glance — classes, deadlines, returned work, and lessons to continue.';

ob_start();
?>
<section class="dash-layout">

    <div class="dash-stat-grid">
        <article class="dash-stat">
            <span class="dash-stat-label">Modules</span>
            <strong class="dash-stat-value"><?= count($catalog) ?></strong>
            <a class="dash-stat-link" href="courses.php">Browse courses</a>
        </article>
        <article class="dash-stat">
            <span class="dash-stat-label">Today</span>
            <strong class="dash-stat-value"><?= count($todayClasses) ?></strong>
            <a class="dash-stat-link" href="timetable.php">View timetable</a>
        </article>
        <?php if ($isStaff || $isAdmin): ?>
        <article class="dash-stat<?= $unmarkedTotal > 0 ? ' dash-stat--alert' : '' ?>">
            <span class="dash-stat-label">To mark</span>
            <strong class="dash-stat-value"><?= $unmarkedTotal ?></strong>
            <span class="dash-stat-caption">Across your modules</span>
        </article>
        <article class="dash-stat<?= $openQuestionTotal > 0 ? ' dash-stat--alert' : '' ?>">
            <span class="dash-stat-label">Questions</span>
            <strong class="dash-stat-value"><?= $openQuestionTotal ?></strong>
            <span class="dash-stat-caption">Waiting for a reply</span>
        </article>
        <?php else: ?>
        <article class="dash-stat<?= $dueSoonCount > 0 ? ' dash-stat--alert' : '' ?>">
            <span class="dash-stat-label">Due soon</span>
            <strong class="dash-stat-value"><?= $dueSoonCount ?></strong>
            <span class="dash-stat-caption">Needs your attention</span>
        </article>
        <article class="dash-stat<?= $unreadNotifCount > 0 ? ' dash-stat--alert' : '' ?>">
            <span class="dash-stat-label">Alerts</span>
            <strong class="dash-stat-value"><?= $unreadNotifCount ?></strong>
            <a class="dash-stat-link" href="communication.php#for-you">Open inbox</a>
        </article>
        <?php endif; ?>
    </div>

    <div class="dash-columns">
        <div class="dash-main stack">

            <article class="card-shell">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Schedule</p>
                        <h3 class="card-title">
                            <?php if (!empty($todayClasses)): ?>
                                Today · <?= portal_escape($todayName) ?>
                            <?php else: ?>
                                Next class
                            <?php endif; ?>
                        </h3>
                    </div>
                    <a class="inline-action" href="timetable.php">Full timetable</a>
                </div>

                <?php if (!empty($todayClasses)): ?>
                    <ul class="dash-list">
                        <?php foreach ($todayClasses as $slot): ?>
                            <?php
                                $joinUrl = trim((string) ($slot['room'] ?? ''));
                                $hasJoin = $isJoinUrl($slot);
                            ?>
                            <li class="dash-list-item<?= $hasJoin ? ' dash-list-item--actions' : '' ?>">
                                <span class="dash-accent" style="background:<?= portal_escape((string) $slot['accent']) ?>"></span>
                                <div class="dash-list-body">
                                    <strong><?= portal_escape((string) $slot['title']) ?></strong>
                                    <span><?= portal_escape($formatTime($slot)) ?> · <?= portal_escape((string) $slot['code']) ?></span>
                                </div>
                                <?php if ($hasJoin): ?>
                                    <a class="button button--sm" href="<?= portal_escape($joinUrl) ?>" target="_blank" rel="noopener noreferrer">Join</a>
                                <?php endif; ?>
                                <a class="button-secondary button--sm" href="course.php?course=<?= urlencode((string) $slot['slug']) ?>&section=calendar">Open</a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php elseif ($nextClass !== null): ?>
                    <?php
                        $joinUrl = trim((string) ($nextClass['room'] ?? ''));
                        $hasJoin = $isJoinUrl($nextClass);
                    ?>
                    <p class="dash-empty">No classes scheduled for today. Here’s your next one:</p>
                    <ul class="dash-list">
                        <li class="dash-list-item<?= $hasJoin ? ' dash-list-item--actions' : '' ?>">
                            <span class="dash-accent" style="background:<?= portal_escape((string) $nextClass['accent']) ?>"></span>
                            <div class="dash-list-body">
                                <strong><?= portal_escape((string) $nextClass['title']) ?></strong>
                                <span><?= portal_escape((string) $nextClass['day_of_week']) ?> · <?= portal_escape($formatTime($nextClass)) ?> · <?= portal_escape((string) $nextClass['code']) ?></span>
                            </div>
                            <?php if ($hasJoin): ?>
                                <a class="button button--sm" href="<?= portal_escape($joinUrl) ?>" target="_blank" rel="noopener noreferrer">Join</a>
                            <?php endif; ?>
                            <a class="button-secondary button--sm" href="course.php?course=<?= urlencode((string) $nextClass['slug']) ?>&section=calendar">Open</a>
                        </li>
                    </ul>
                <?php else: ?>
                    <p class="dash-empty">No classes on your timetable yet. <a href="courses.php">Browse your modules</a>.</p>
                <?php endif; ?>
            </article>

            <?php if ($isStaff || $isAdmin): ?>

            <article class="card-shell">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Lesson Q&amp;A</p>
                        <h3 class="card-title">Student questions</h3>
                        <p>Oldest unanswered first — start with who has waited longest.</p>
                    </div>
                    <span class="chip"><?= count($pendingQuestions) ?></span>
                </div>

                <?php if (empty($pendingQuestions)): ?>
                    <p class="dash-empty">No open questions — students haven’t asked anything new.</p>
                <?php else: ?>
                    <ul class="dash-list">
                        <?php foreach ($pendingQuestions as $q): ?>
                            <?php
                                $qText = trim((string) $q['question']);
                                if (strlen($qText) > 140) {
                                    $qText = substr($qText, 0, 137) . '…';
                                }
                                $stamp = $formatVideoStamp((int) ($q['video_seconds'] ?? 0));
                                $metaParts = [
                                    (string) $q['student_name'],
                                    (string) $q['lesson_title'],
                                    (string) $q['course_title'],
                                ];
                                if ($stamp !== '') {
                                    $metaParts[] = 'at ' . $stamp;
                                }
                            ?>
                            <li class="dash-list-item dash-list-item--question">
                                <span class="dash-accent" style="background:<?= portal_escape((string) $q['accent']) ?>"></span>
                                <div class="dash-list-body">
                                    <strong><?= portal_escape($qText) ?></strong>
                                    <span><?= portal_escape(implode(' · ', $metaParts)) ?></span>
                                </div>
                                <span class="dash-wait"><?= portal_escape($waitLabel((string) $q['created_at'])) ?></span>
                                <a class="button-secondary button--sm" href="lesson-viewer.php?item=<?= (int) $q['item_id'] ?>#q-<?= (int) $q['id'] ?>">Answer</a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </article>

            <article class="card-shell">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Marking</p>
                        <h3 class="card-title">Waiting for feedback</h3>
                        <p>Oldest submissions first.</p>
                    </div>
                    <span class="chip"><?= count($pendingToMark) ?></span>
                </div>

                <?php if (empty($pendingToMark)): ?>
                    <p class="dash-empty">You’re all caught up — no unmarked submissions.</p>
                <?php else: ?>
                    <ul class="dash-list">
                        <?php foreach ($pendingToMark as $row): ?>
                            <li class="dash-list-item dash-list-item--question">
                                <span class="dash-accent" style="background:<?= portal_escape((string) $row['accent']) ?>"></span>
                                <div class="dash-list-body">
                                    <strong><?= portal_escape((string) $row['item_title']) ?></strong>
                                    <span><?= portal_escape((string) $row['student_name']) ?> · <?= portal_escape((string) $row['course_title']) ?></span>
                                </div>
                                <span class="dash-wait"><?= portal_escape($waitLabel((string) $row['submitted_at'])) ?></span>
                                <a class="button-secondary button--sm" href="course.php?course=<?= urlencode((string) $row['slug']) ?>&section=content&open_review=rvw-<?= (int) $row['id'] ?>">Mark</a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </article>

            <?php if (!empty($teacherDeadlines)): ?>
            <article class="card-shell">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Deadlines</p>
                        <h3 class="card-title">Coming up in your modules</h3>
                        <p>Useful for chasing late or missing work.</p>
                    </div>
                    <span class="chip"><?= count($teacherDeadlines) ?></span>
                </div>
                <ul class="dash-list">
                    <?php foreach ($teacherDeadlines as $item): ?>
                        <?php
                            $state = (string) $item['deadline_info']['state'];
                            $missing = max(0, (int) $item['enrolled'] - (int) $item['submitted']);
                        ?>
                        <li class="dash-list-item">
                            <span class="dash-accent" style="background:<?= portal_escape($item['accent']) ?>"></span>
                            <div class="dash-list-body">
                                <strong><?= portal_escape($item['title']) ?></strong>
                                <span><?= portal_escape($item['course_title']) ?> · Due <?= portal_escape($item['deadline_info']['text']) ?> · <?= (int) $item['submitted'] ?>/<?= (int) $item['enrolled'] ?> in<?= $missing > 0 ? ' · ' . $missing . ' missing' : '' ?></span>
                            </div>
                            <span class="dash-status dash-status--<?= portal_escape($state) ?>"><?= portal_escape($state === 'closed' ? 'Passed' : ($state === 'soon' ? 'Due soon' : 'Open')) ?></span>
                            <a class="button-secondary button--sm" href="course.php?course=<?= urlencode($item['slug']) ?>&section=gradebook">Open</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </article>
            <?php endif; ?>

            <?php else: ?>

            <article class="card-shell">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Deadlines</p>
                        <h3 class="card-title">Coming up</h3>
                    </div>
                    <span class="chip"><?= count($upcomingDeadlines) ?></span>
                </div>

                <?php if (empty($upcomingDeadlines)): ?>
                    <p class="dash-empty">No upcoming deadlines in the next few weeks. <a href="courses.php">Check your courses</a> for new work.</p>
                <?php else: ?>
                    <ul class="dash-list">
                        <?php foreach ($upcomingDeadlines as $item): ?>
                            <?php
                                $state = (string) $item['deadline_info']['state'];
                                $statusLabel = 'Open';
                                if ($item['marked']) {
                                    $statusLabel = 'Marked';
                                } elseif ($item['submitted']) {
                                    $statusLabel = 'Submitted';
                                } elseif ($state === 'closed') {
                                    $statusLabel = 'Overdue';
                                } elseif ($state === 'soon') {
                                    $statusLabel = 'Due soon';
                                }
                            ?>
                            <li class="dash-list-item">
                                <span class="dash-accent" style="background:<?= portal_escape($item['accent']) ?>"></span>
                                <div class="dash-list-body">
                                    <strong><?= portal_escape($item['title']) ?></strong>
                                    <span><?= portal_escape($item['course_title']) ?> · Due <?= portal_escape($item['deadline_info']['text']) ?></span>
                                </div>
                                <span class="dash-status dash-status--<?= portal_escape($item['marked'] ? 'marked' : ($item['submitted'] ? 'submitted' : $state)) ?>"><?= portal_escape($statusLabel) ?></span>
                                <a class="button-secondary button--sm" href="course.php?course=<?= urlencode($item['slug']) ?>&section=content">Open</a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </article>

            <article class="card-shell">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Feedback</p>
                        <h3 class="card-title">Returned work</h3>
                    </div>
                    <span class="chip"><?= count($returnedGrades) ?></span>
                </div>

                <?php if (empty($returnedGrades)): ?>
                    <p class="dash-empty">No marked work yet — submitted assignments will appear here when graded.</p>
                <?php else: ?>
                    <ul class="dash-list">
                        <?php foreach ($returnedGrades as $row): ?>
                            <li class="dash-list-item">
                                <span class="dash-accent" style="background:<?= portal_escape((string) $row['accent']) ?>"></span>
                                <div class="dash-list-body">
                                    <strong><?= portal_escape((string) $row['item_title']) ?></strong>
                                    <span><?= portal_escape((string) $row['course_title']) ?> · Marked <?= portal_escape($relativeWhen((string) $row['marked_at'])) ?></span>
                                </div>
                                <span class="dash-status dash-status--marked">
                                    <?= $row['score'] !== null && $row['score'] !== '' ? portal_escape((string) $row['score']) . '%' : 'Marked' ?>
                                </span>
                                <a class="button-secondary button--sm" href="course.php?course=<?= urlencode((string) $row['slug']) ?>&section=content&open_review=rvw-<?= (int) $row['id'] ?>">View</a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </article>

            <?php if (!empty($continueWatching)): ?>
            <article class="card-shell">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Lessons</p>
                        <h3 class="card-title">Continue watching</h3>
                    </div>
                    <span class="chip"><?= count($continueWatching) ?></span>
                </div>
                <ul class="dash-list">
                    <?php foreach ($continueWatching as $row): ?>
                        <?php $stamp = $formatVideoStamp((int) $row['position_seconds']); ?>
                        <li class="dash-list-item dash-list-item--question">
                            <span class="dash-accent" style="background:<?= portal_escape((string) $row['accent']) ?>"></span>
                            <div class="dash-list-body">
                                <strong><?= portal_escape((string) $row['lesson_title']) ?></strong>
                                <span><?= portal_escape((string) $row['course_title']) ?><?= $stamp !== '' ? ' · Resume at ' . portal_escape($stamp) : '' ?> · <?= portal_escape($relativeWhen((string) $row['updated_at'])) ?></span>
                            </div>
                            <a class="button-secondary button--sm" href="lesson-viewer.php?item=<?= (int) $row['item_id'] ?>">Resume</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </article>
            <?php endif; ?>

            <?php endif; ?>

        </div>

        <aside class="dash-side stack">

            <?php if ($isStaff || $isAdmin): ?>
            <article class="card-shell">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Workload</p>
                        <h3 class="card-title">By module</h3>
                    </div>
                </div>

                <?php if (empty($moduleWorkload)): ?>
                    <p class="dash-empty">No modules assigned yet.</p>
                <?php else: ?>
                    <ul class="dash-workload-list">
                        <?php foreach ($moduleWorkload as $mod): ?>
                            <?php
                                $qCount = (int) $mod['open_questions'];
                                $mCount = (int) $mod['unmarked'];
                                $busy = $qCount + $mCount;
                            ?>
                            <li class="dash-workload-item<?= $busy > 0 ? ' is-busy' : '' ?>">
                                <a href="course.php?course=<?= urlencode((string) $mod['slug']) ?>">
                                    <span class="dash-accent" style="background:<?= portal_escape((string) $mod['accent']) ?>"></span>
                                    <span class="dash-workload-body">
                                        <strong><?= portal_escape((string) $mod['title']) ?></strong>
                                        <small><?= portal_escape((string) $mod['code']) ?></small>
                                    </span>
                                    <span class="dash-workload-counts">
                                        <span title="Open questions"><?= $qCount ?> Q</span>
                                        <span title="Unmarked submissions"><?= $mCount ?> mark</span>
                                    </span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </article>
            <?php else: ?>
            <article class="card-shell">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">For you</p>
                        <h3 class="card-title">Recent alerts</h3>
                    </div>
                    <a class="inline-action" href="communication.php#for-you">Inbox</a>
                </div>

                <?php if (empty($recentAnswers)): ?>
                    <p class="dash-empty">No personal alerts yet. Teacher replies to your lesson questions will show here.</p>
                <?php else: ?>
                    <ul class="dash-announce-list">
                        <?php foreach ($recentAnswers as $n): ?>
                            <?php
                                $link = trim((string) ($n['link'] ?? ''));
                                $href = $link !== '' ? $link : 'communication.php#for-you';
                                $unread = trim((string) ($n['read_at'] ?? '')) === '';
                            ?>
                            <li>
                                <a href="<?= portal_escape($href) ?>" class="<?= $unread ? 'is-unread' : '' ?>">
                                    <?php if ($unread): ?>
                                        <span class="dash-announce-tag">New</span>
                                    <?php endif; ?>
                                    <strong><?= portal_escape((string) $n['title']) ?></strong>
                                    <?php if (trim((string) ($n['body'] ?? '')) !== ''): ?>
                                        <span><?= portal_escape(substr((string) $n['body'], 0, 100)) ?></span>
                                    <?php endif; ?>
                                    <span><?= portal_escape($relativeWhen((string) $n['created_at'])) ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </article>
            <?php endif; ?>

            <article class="card-shell">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Your modules</p>
                        <h3 class="card-title">Quick access</h3>
                    </div>
                    <a class="inline-action" href="courses.php">All</a>
                </div>

                <?php if (empty($catalog)): ?>
                    <p class="dash-empty">No modules assigned yet.</p>
                <?php else: ?>
                    <ul class="dash-course-list">
                        <?php foreach (array_slice($catalog, 0, 6) as $course): ?>
                            <li>
                                <a class="dash-course-link" href="course.php?course=<?= urlencode((string) $course['slug']) ?>">
                                    <span class="dash-accent" style="background:<?= portal_escape((string) $course['accent']) ?>"></span>
                                    <span>
                                        <strong><?= portal_escape((string) $course['title']) ?></strong>
                                        <small><?= portal_escape((string) $course['code']) ?></small>
                                    </span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </article>

            <article class="card-shell">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Bulletin</p>
                        <h3 class="card-title">Latest updates</h3>
                    </div>
                    <a class="inline-action" href="communication.php">All</a>
                </div>

                <?php if (empty($majorAnnouncements) && empty($moduleAnnouncements)): ?>
                    <p class="dash-empty">No announcements yet.</p>
                <?php else: ?>
                    <ul class="dash-announce-list">
                        <?php foreach ($majorAnnouncements as $ann): ?>
                            <li>
                                <a href="communication.php#major-announcements">
                                    <span class="dash-announce-tag">School</span>
                                    <strong><?= portal_escape((string) $ann['title']) ?></strong>
                                    <span><?= portal_escape($relativeWhen((string) $ann['created_at'])) ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                        <?php foreach ($moduleAnnouncements as $ann): ?>
                            <li>
                                <a href="course.php?course=<?= urlencode((string) $ann['course_slug']) ?>&section=announcements">
                                    <span class="dash-announce-tag dash-announce-tag--module"><?= portal_escape((string) $ann['course_title']) ?></span>
                                    <strong><?= portal_escape((string) $ann['title']) ?></strong>
                                    <span><?= portal_escape($relativeWhen((string) $ann['created_at'])) ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </article>

        </aside>
    </div>
</section>
<?php
$page_content = ob_get_clean();

require __DIR__ . '/../layout.php';
