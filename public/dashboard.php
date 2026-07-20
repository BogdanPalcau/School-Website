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

$slotPhase = static function (array $slot) use ($nowHm): string {
    $start = trim((string) ($slot['start_time'] ?? ''));
    $end = trim((string) ($slot['end_time'] ?? ''));
    if ($start === '') {
        return 'upcoming';
    }
    if ($end !== '' && $end < $nowHm) {
        return 'past';
    }
    if ($start <= $nowHm && ($end === '' || $end >= $nowHm)) {
        return 'current';
    }
    if ($start < $nowHm) {
        return 'past';
    }
    return 'upcoming';
};

foreach ($scheduleRows as $row) {
    $day = (string) ($row['day_of_week'] ?? '');
    $rowOrder = $dayOrder[$day] ?? 8;
    if ($day === $todayName) {
        $todayClasses[] = $row;
        $start = trim((string) ($row['start_time'] ?? ''));
        $phase = $slotPhase($row);
        if ($nextClass === null && ($phase === 'current' || $start === '' || $start >= $nowHm)) {
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
    $horizon = $now + (30 * 86400);
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
        // Keep overdue work visible for two weeks so students still see missed deadlines
        if ($ts < $now - (14 * 86400)) {
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

}

// Personal alerts (students + staff — discussion replies, Q&A, announcements, etc.)
$recentAnswers = [];
$notifListStmt = $db->prepare(
    "SELECT id, title, body, link, created_at, read_at, type
     FROM portal_notifications
     WHERE user_id = ?
     ORDER BY CASE WHEN read_at = '' THEN 0 ELSE 1 END, created_at DESC
     LIMIT 8"
);
$notifListStmt->execute([$uid]);
$recentAnswers = $notifListStmt->fetchAll();

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
$unreadCourseAnnouncements = [];
$unreadAnnouncementCount = 0;
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

    $unreadCountStmt = $db->prepare(
        "SELECT COUNT(*)
         FROM course_announcements ca
         WHERE ca.course_id IN ($placeholders)
           AND NOT EXISTS (
             SELECT 1 FROM announcement_reads ar
             WHERE ar.announcement_id = ca.id AND ar.user_id = ?
           )"
    );
    $unreadCountStmt->execute(array_merge($annCourseIds, [$uid]));
    $unreadAnnouncementCount = (int) $unreadCountStmt->fetchColumn();

    if ($unreadAnnouncementCount > 0) {
        $unreadListStmt = $db->prepare(
            "SELECT ca.id, ca.title, ca.created_at,
                    c.title AS course_title, c.slug AS course_slug, c.accent AS course_accent
             FROM course_announcements ca
             JOIN courses c ON c.id = ca.course_id
             WHERE ca.course_id IN ($placeholders)
               AND NOT EXISTS (
                 SELECT 1 FROM announcement_reads ar
                 WHERE ar.announcement_id = ca.id AND ar.user_id = ?
               )
             ORDER BY ca.created_at DESC
             LIMIT 3"
        );
        $unreadListStmt->execute(array_merge($annCourseIds, [$uid]));
        $unreadCourseAnnouncements = $unreadListStmt->fetchAll();
    }
}

// Student announcements have dedicated priority/bulletin surfaces. Keep Inbox
// focused on replies, grades and other personal updates instead of repeating them.
$unreadAnnouncementIds = array_map(
    static fn(array $announcement): int => (int) ($announcement['id'] ?? 0),
    $unreadCourseAnnouncements
);
$bulletinModuleAnnouncements = array_values(array_filter(
    $moduleAnnouncements,
    static fn(array $announcement): bool => !in_array((int) ($announcement['id'] ?? 0), $unreadAnnouncementIds, true)
));
$recentInboxNotifications = ($isStaff || $isAdmin)
    ? $recentAnswers
    : array_values(array_filter(
        $recentAnswers,
        static fn(array $notification): bool => !in_array(
            (string) ($notification['type'] ?? ''),
            ['announcement', 'announcements'],
            true
        )
    ));

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
$needsAttentionTotal = $openQuestionTotal + $unmarkedTotal;

// Staff work queues — bucketed by kind (mark → Q&A → deadlines), urgency within each
$staffMarkQueue = [];
$staffQuestionQueue = [];
$staffDeadlineQueue = [];
$waitAgeSeconds = static function (string $raw): int {
    $ts = portal_db_timestamp($raw);
    if ($ts === null) {
        return 0;
    }
    return max(0, time() - $ts);
};

if ($isStaff || $isAdmin) {
    foreach ($pendingToMark as $row) {
        $age = $waitAgeSeconds((string) $row['submitted_at']);
        $staffMarkQueue[] = [
            'title' => (string) $row['item_title'],
            'meta'  => (string) $row['student_name'] . ' · ' . (string) $row['course_title'],
            'time'  => $waitLabel((string) $row['submitted_at']),
            'href'  => 'course.php?course=' . urlencode((string) $row['slug'])
                . '&section=content&open_review=rvw-' . (int) $row['id'],
            'accent'=> (string) ($row['accent'] ?? ''),
            'age'   => $age,
            'stale' => $age >= 2 * 86400,
        ];
    }
    usort($staffMarkQueue, static fn(array $a, array $b): int => $b['age'] <=> $a['age']);
    $staffMarkQueue = array_slice($staffMarkQueue, 0, 8);

    foreach ($pendingQuestions as $q) {
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
        $age = $waitAgeSeconds((string) $q['created_at']);
        $staffQuestionQueue[] = [
            'title' => $qText,
            'meta'  => implode(' · ', $metaParts),
            'time'  => $waitLabel((string) $q['created_at']),
            'href'  => 'lesson-viewer.php?item=' . (int) $q['item_id'] . '#q-' . (int) $q['id'],
            'accent'=> (string) ($q['accent'] ?? ''),
            'age'   => $age,
            'stale' => $age >= 2 * 86400,
        ];
    }
    usort($staffQuestionQueue, static fn(array $a, array $b): int => $b['age'] <=> $a['age']);
    $staffQuestionQueue = array_slice($staffQuestionQueue, 0, 8);

    foreach ($teacherDeadlines as $item) {
        $state = (string) $item['deadline_info']['state'];
        $missing = max(0, (int) $item['enrolled'] - (int) $item['submitted']);
        $ts = (int) ($item['deadline_info']['timestamp'] ?? 0);
        $staffDeadlineQueue[] = [
            'title' => (string) $item['title'],
            'meta'  => (string) $item['course_title']
                . ' · ' . (int) $item['submitted'] . '/' . (int) $item['enrolled'] . ' in'
                . ($missing > 0 ? ' · ' . $missing . ' missing' : ''),
            'time'  => 'Due ' . (string) $item['deadline_info']['text'],
            'href'  => 'course.php?course=' . urlencode((string) $item['slug']) . '&section=gradebook',
            'accent'=> (string) ($item['accent'] ?? ''),
            'state' => $state,
            'ts'    => $ts,
        ];
    }
    usort($staffDeadlineQueue, static function (array $a, array $b): int {
        $rank = static fn(string $s): int => match ($s) {
            'closed' => 0,
            'soon' => 1,
            default => 2,
        };
        $byState = $rank((string) $a['state']) <=> $rank((string) $b['state']);
        if ($byState !== 0) {
            return $byState;
        }
        return ((int) $a['ts']) <=> ((int) $b['ts']);
    });
    $staffDeadlineQueue = array_slice($staffDeadlineQueue, 0, 6);
}

$staffTodoTotal = count($staffMarkQueue) + count($staffQuestionQueue) + count($staffDeadlineQueue);

// Student priorities — deadlines first (all upcoming), then returned grades
$studentDeadlineQueue = [];
if (!$isStaff && !$isAdmin) {
    foreach ($upcomingDeadlines as $item) {
        // Marked work moves to "Returned grades"; still list submitted/open deadlines here
        if (!empty($item['marked'])) {
            continue;
        }
        $state = (string) $item['deadline_info']['state'];
        $submitted = !empty($item['submitted']);
        $studentDeadlineQueue[] = $item + [
            'submitted' => $submitted,
            'urgency' => $submitted ? 3 : match ($state) {
                'closed' => 0,
                'soon' => 1,
                default => 2,
            },
        ];
    }
    usort($studentDeadlineQueue, static function (array $a, array $b): int {
        $byUrgency = ((int) $a['urgency']) <=> ((int) $b['urgency']);
        if ($byUrgency !== 0) {
            return $byUrgency;
        }
        return ((int) ($a['deadline_info']['timestamp'] ?? 0))
            <=> ((int) ($b['deadline_info']['timestamp'] ?? 0));
    });
    $studentDeadlineQueue = array_slice($studentDeadlineQueue, 0, 8);
}
$studentOpenDeadlineCount = count(array_filter(
    $studentDeadlineQueue,
    static fn(array $d): bool => empty($d['submitted'])
));
$studentPriorityTotal = $studentOpenDeadlineCount + count($returnedGrades);
$studentHasPriorities = !empty($studentDeadlineQueue) || !empty($returnedGrades) || $unreadAnnouncementCount > 0;

$page_title = 'Dashboard | ' . portal_school_name();
$active_page = 'dashboard';
$page_eyebrow = 'Overview';
$page_heading = $greeting . ', ' . $firstName;
$page_description = $isStaff || $isAdmin
    ? 'Work waiting on you sits in To do. Updates others sent you live in Notifications.'
    : 'Deadlines and returned work are in Your priorities. Updates others sent you live in Notifications.';

ob_start();
?>
<section class="dash-layout">

    <div class="dash-stat-grid dash-stat-grid--3">
        <?php if ($isStaff || $isAdmin): ?>
        <article class="dash-stat<?= $needsAttentionTotal > 0 ? ' dash-stat--alert' : '' ?>">
            <span class="dash-stat-label">To do</span>
            <strong class="dash-stat-value"><?= $needsAttentionTotal ?></strong>
            <a class="dash-stat-link" href="#to-do"><?= $unmarkedTotal ?> to mark · <?= $openQuestionTotal ?> Q&amp;A</a>
        </article>
        <?php else: ?>
        <article class="dash-stat<?= $dueSoonCount > 0 ? ' dash-stat--alert' : '' ?>">
            <span class="dash-stat-label">Due soon</span>
            <strong class="dash-stat-value"><?= $dueSoonCount ?></strong>
            <a class="dash-stat-link" href="#priorities">View deadlines</a>
        </article>
        <?php endif; ?>
        <article class="dash-stat">
            <span class="dash-stat-label">Today</span>
            <strong class="dash-stat-value"><?= count($todayClasses) ?></strong>
            <a class="dash-stat-link" href="#dash-schedule">View schedule</a>
        </article>
        <article class="dash-stat<?= $unreadNotifCount > 0 ? ' dash-stat--alert' : '' ?>">
            <span class="dash-stat-label">Inbox</span>
            <strong class="dash-stat-value"><?= $unreadNotifCount ?></strong>
            <a class="dash-stat-link" href="notifications.php">Open notifications</a>
        </article>
    </div>

    <div class="dash-columns">
        <div class="dash-main stack">

            <article class="card-shell dash-work" id="dash-schedule">
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
                    <ul class="dash-work-list">
                        <?php foreach ($todayClasses as $slot): ?>
                            <?php
                                $joinUrl = trim((string) ($slot['room'] ?? ''));
                                $hasJoin = $isJoinUrl($slot);
                                $phase = $slotPhase($slot);
                                $phaseLabel = match ($phase) {
                                    'past' => 'Done',
                                    'current' => 'Now',
                                    default => '',
                                };
                            ?>
                            <li class="dash-work-row dash-work-row--<?= portal_escape($phase) ?><?= $hasJoin ? ' dash-work-row--actions' : '' ?>">
                                <div class="dash-work-row-main">
                                    <strong><?= portal_escape((string) $slot['title']) ?></strong>
                                    <span>
                                        <?= portal_escape($formatTime($slot)) ?> · <?= portal_escape((string) $slot['code']) ?>
                                        <?php if ($phaseLabel !== ''): ?>
                                            · <span class="dash-status dash-status--<?= $phase === 'current' ? 'current' : 'past' ?>"><?= portal_escape($phaseLabel) ?></span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="dash-work-row-actions">
                                    <?php if ($hasJoin && $phase !== 'past'): ?>
                                        <a class="button button--sm" href="<?= portal_escape($joinUrl) ?>" target="_blank" rel="noopener noreferrer">Join</a>
                                    <?php endif; ?>
                                    <a class="button-secondary button--sm" href="course.php?course=<?= urlencode((string) $slot['slug']) ?>&section=calendar">Open</a>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php elseif ($nextClass !== null): ?>
                    <?php
                        $joinUrl = trim((string) ($nextClass['room'] ?? ''));
                        $hasJoin = $isJoinUrl($nextClass);
                    ?>
                    <p class="dash-empty">No classes scheduled for today. Here’s your next one:</p>
                    <ul class="dash-work-list">
                        <li class="dash-work-row<?= $hasJoin ? ' dash-work-row--actions' : '' ?>">
                            <div class="dash-work-row-main">
                                <strong><?= portal_escape((string) $nextClass['title']) ?></strong>
                                <span><?= portal_escape((string) $nextClass['day_of_week']) ?> · <?= portal_escape($formatTime($nextClass)) ?> · <?= portal_escape((string) $nextClass['code']) ?></span>
                            </div>
                            <div class="dash-work-row-actions">
                                <?php if ($hasJoin): ?>
                                    <a class="button button--sm" href="<?= portal_escape($joinUrl) ?>" target="_blank" rel="noopener noreferrer">Join</a>
                                <?php endif; ?>
                                <a class="button-secondary button--sm" href="course.php?course=<?= urlencode((string) $nextClass['slug']) ?>&section=calendar">Open</a>
                            </div>
                        </li>
                    </ul>
                <?php else: ?>
                    <p class="dash-empty">No classes on your timetable yet. <a href="courses.php">Browse your modules</a>.</p>
                <?php endif; ?>
            </article>

            <?php if ($isStaff || $isAdmin): ?>

            <article class="card-shell dash-work" id="to-do">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Queue</p>
                        <h3 class="card-title">To do</h3>
                        <p class="dash-section-rule">Work waiting on you — mark, answer, deadlines.</p>
                    </div>
                    <?php if ($staffTodoTotal > 0): ?>
                        <span class="chip chip--muted"><?= $staffTodoTotal ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($staffTodoTotal === 0): ?>
                    <p class="dash-empty">You’re all caught up — nothing to mark and no open questions right now.</p>
                <?php else: ?>
                    <div class="dash-work-panels">

                        <?php if (!empty($staffMarkQueue)): ?>
                        <?php
                            $markShown = array_slice($staffMarkQueue, 0, 4);
                            $markHasMore = $unmarkedTotal > count($markShown);
                        ?>
                        <section class="dash-work-panel dash-work-panel--urgent">
                            <header class="dash-work-panel-head">
                                <h4>To mark</h4>
                                <span><?= count($markShown) ?><?= $markHasMore ? ' / ' . $unmarkedTotal : '' ?></span>
                            </header>
                            <ul class="dash-work-list">
                                <?php foreach ($markShown as $item): ?>
                                    <?php
                                        $meta = trim((string) ($item['meta'] ?? ''));
                                        if (strlen($meta) > 100) {
                                            $meta = substr($meta, 0, 97) . '…';
                                        }
                                    ?>
                                    <li class="dash-work-row<?= !empty($item['stale']) ? ' is-stale' : '' ?>">
                                        <div class="dash-work-row-main">
                                            <strong><?= portal_escape((string) $item['title']) ?></strong>
                                            <span>
                                                <?= portal_escape($meta) ?>
                                                <?php if (trim((string) ($item['time'] ?? '')) !== ''): ?>
                                                    · <em class="dash-work-age<?= !empty($item['stale']) ? ' is-stale' : '' ?>"><?= portal_escape((string) $item['time']) ?></em>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <a class="button button--sm" href="<?= portal_escape((string) $item['href']) ?>">Mark</a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if ($markHasMore): ?>
                                <p class="dash-queue-more"><a href="grades.php">See all unmarked</a></p>
                            <?php endif; ?>
                        </section>
                        <?php endif; ?>

                        <?php if (!empty($staffQuestionQueue)): ?>
                        <?php
                            $qShown = array_slice($staffQuestionQueue, 0, 4);
                            $qHasMore = $openQuestionTotal > count($qShown);
                        ?>
                        <section class="dash-work-panel">
                            <header class="dash-work-panel-head">
                                <h4>Questions waiting</h4>
                                <span><?= count($qShown) ?><?= $qHasMore ? ' / ' . $openQuestionTotal : '' ?></span>
                            </header>
                            <ul class="dash-work-list">
                                <?php foreach ($qShown as $item): ?>
                                    <?php
                                        $meta = trim((string) ($item['meta'] ?? ''));
                                        if (strlen($meta) > 100) {
                                            $meta = substr($meta, 0, 97) . '…';
                                        }
                                    ?>
                                    <li class="dash-work-row<?= !empty($item['stale']) ? ' is-stale' : '' ?>">
                                        <div class="dash-work-row-main">
                                            <strong><?= portal_escape((string) $item['title']) ?></strong>
                                            <span>
                                                <?= portal_escape($meta) ?>
                                                <?php if (trim((string) ($item['time'] ?? '')) !== ''): ?>
                                                    · <em class="dash-work-age<?= !empty($item['stale']) ? ' is-stale' : '' ?>"><?= portal_escape((string) $item['time']) ?></em>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <a class="button button--sm" href="<?= portal_escape((string) $item['href']) ?>">Answer</a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if ($qHasMore): ?>
                                <p class="dash-queue-more"><a href="courses.php">See all modules</a></p>
                            <?php endif; ?>
                        </section>
                        <?php endif; ?>

                        <?php if (!empty($staffDeadlineQueue)): ?>
                        <?php
                            $deadlineShown = array_slice($staffDeadlineQueue, 0, 4);
                            $deadlineHasMore = count($staffDeadlineQueue) > count($deadlineShown);
                        ?>
                        <section class="dash-work-panel dash-work-panel--soft">
                            <header class="dash-work-panel-head">
                                <h4>Upcoming deadlines</h4>
                                <span><?= count($deadlineShown) ?><?= $deadlineHasMore ? '+' : '' ?></span>
                            </header>
                            <ul class="dash-work-list">
                                <?php foreach ($deadlineShown as $item): ?>
                                    <?php
                                        $meta = trim((string) ($item['meta'] ?? ''));
                                        $state = (string) ($item['state'] ?? 'open');
                                        $statusLabel = $state === 'closed' ? 'Past due' : ($state === 'soon' ? 'Due soon' : 'Upcoming');
                                    ?>
                                    <li class="dash-work-row">
                                        <div class="dash-work-row-main">
                                            <strong><?= portal_escape((string) $item['title']) ?></strong>
                                            <span>
                                                <?= $meta !== '' ? portal_escape($meta) . ' · ' : '' ?>
                                                <?= portal_escape((string) $item['time']) ?>
                                                · <span class="dash-status dash-status--<?= portal_escape($state) ?>"><?= portal_escape($statusLabel) ?></span>
                                            </span>
                                        </div>
                                        <a class="button-secondary button--sm" href="<?= portal_escape((string) $item['href']) ?>">Open</a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </section>
                        <?php endif; ?>

                    </div>
                <?php endif; ?>
            </article>

            <?php else: ?>

            <article class="card-shell dash-work" id="priorities">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Queue</p>
                        <h3 class="card-title">Your priorities</h3>
                        <p class="dash-section-rule">Work waiting on you — deadlines and returned grades.</p>
                    </div>
                    <?php if ($studentPriorityTotal > 0): ?>
                        <span class="chip chip--muted"><?= $studentPriorityTotal ?></span>
                    <?php endif; ?>
                </div>

                <?php if (!$studentHasPriorities): ?>
                    <p class="dash-empty">Nothing urgent right now — check your courses for new work, or pick up a lesson below.</p>
                <?php else: ?>
                    <div class="dash-work-panels">

                        <?php if (!empty($studentDeadlineQueue)): ?>
                        <?php
                            $studentDeadlineShown = array_slice($studentDeadlineQueue, 0, 4);
                            $studentDeadlineMore = count($studentDeadlineQueue) > count($studentDeadlineShown);
                        ?>
                        <section class="dash-work-panel dash-work-panel--urgent">
                            <header class="dash-work-panel-head">
                                <h4>Deadlines</h4>
                                <span><?= count($studentDeadlineShown) ?><?= $studentDeadlineMore ? '+' : '' ?></span>
                            </header>
                            <ul class="dash-work-list">
                                <?php foreach ($studentDeadlineShown as $item): ?>
                                    <?php
                                        $state = (string) $item['deadline_info']['state'];
                                        $submitted = !empty($item['submitted']);
                                        if ($submitted) {
                                            $statusLabel = 'Submitted';
                                            $statusClass = 'submitted';
                                        } elseif ($state === 'closed') {
                                            $statusLabel = 'Overdue';
                                            $statusClass = 'closed';
                                        } elseif ($state === 'soon') {
                                            $statusLabel = 'Due soon';
                                            $statusClass = 'soon';
                                        } else {
                                            $statusLabel = 'Upcoming';
                                            $statusClass = 'open';
                                        }
                                    ?>
                                    <li class="dash-work-row<?= !$submitted && $state === 'closed' ? ' is-stale' : '' ?>">
                                        <div class="dash-work-row-main">
                                            <strong><?= portal_escape($item['title']) ?></strong>
                                            <span>
                                                <?= portal_escape($item['course_title']) ?>
                                                · Due <?= portal_escape($item['deadline_info']['text']) ?>
                                                · <span class="dash-status dash-status--<?= $statusClass ?>"><?= portal_escape($statusLabel) ?></span>
                                            </span>
                                        </div>
                                        <a class="<?= $submitted ? 'button-secondary' : 'button' ?> button--sm" href="course.php?course=<?= urlencode($item['slug']) ?>&section=content"><?= $submitted ? 'View' : 'Open' ?></a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if ($studentDeadlineMore): ?>
                                <p class="dash-queue-more"><a href="courses.php">See all modules</a></p>
                            <?php endif; ?>
                        </section>
                        <?php endif; ?>

                        <?php if (!empty($returnedGrades)): ?>
                        <?php
                            $returnedShown = array_slice($returnedGrades, 0, 4);
                            $returnedMore = count($returnedGrades) > count($returnedShown);
                        ?>
                        <section class="dash-work-panel<?= empty($studentDeadlineQueue) ? ' dash-work-panel--urgent' : '' ?>">
                            <header class="dash-work-panel-head">
                                <h4>Returned grades</h4>
                                <span><?= count($returnedShown) ?><?= $returnedMore ? '+' : '' ?></span>
                            </header>
                            <ul class="dash-work-list">
                                <?php foreach ($returnedShown as $row): ?>
                                    <li class="dash-work-row">
                                        <div class="dash-work-row-main">
                                            <strong><?= portal_escape((string) $row['item_title']) ?></strong>
                                            <span>
                                                <?= portal_escape((string) $row['course_title']) ?>
                                                · Marked <?= portal_escape($relativeWhen((string) $row['marked_at'])) ?>
                                                <?php if ($row['score'] !== null && $row['score'] !== ''): ?>
                                                    · <span class="dash-status dash-status--marked"><?= portal_escape((string) $row['score']) ?>%</span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <a class="button-secondary button--sm" href="course.php?course=<?= urlencode((string) $row['slug']) ?>&section=content&open_review=rvw-<?= (int) $row['id'] ?>">View</a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </section>
                        <?php endif; ?>

                        <?php if ($unreadAnnouncementCount > 0): ?>
                        <section class="dash-work-panel dash-work-panel--soft">
                            <header class="dash-work-panel-head">
                                <h4>Unread announcements</h4>
                                <span><?= $unreadAnnouncementCount ?></span>
                            </header>
                            <p class="dash-queue-teaser">
                                <?= $unreadAnnouncementCount === 1
                                    ? 'You have 1 unread module announcement.'
                                    : 'You have ' . $unreadAnnouncementCount . ' unread module announcements.' ?>
                                <a href="communication.php#module-announcements">Read now</a>
                            </p>
                        </section>
                        <?php endif; ?>

                    </div>
                <?php endif; ?>
            </article>

            <?php if (!empty($continueWatching)): ?>
            <article class="card-shell dash-work">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Lessons</p>
                        <h3 class="card-title">Continue watching</h3>
                    </div>
                    <span class="chip chip--muted"><?= count($continueWatching) ?></span>
                </div>
                <ul class="dash-work-list">
                    <?php foreach (array_slice($continueWatching, 0, 4) as $row): ?>
                        <?php $stamp = $formatVideoStamp((int) $row['position_seconds']); ?>
                        <li class="dash-work-row">
                            <div class="dash-work-row-main">
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

            <article class="card-shell" id="recent-alerts">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Inbox</p>
                        <h3 class="card-title">Notifications</h3>
                        <p class="dash-section-rule">Updates others sent you — not your work queue.</p>
                    </div>
                    <a class="inline-action" href="notifications.php">Open all</a>
                </div>

                <?php if (empty($recentInboxNotifications)): ?>
                    <p class="dash-empty">
                        <?= $isStaff || $isAdmin
                            ? 'No notifications yet. Student replies and system updates will show here.'
                            : 'No notifications yet. Replies and new announcements will show here.' ?>
                    </p>
                <?php else: ?>
                <ul class="dash-announce-list">
                    <?php foreach (array_slice($recentInboxNotifications, 0, 5) as $n): ?>
                        <?php
                            $link = trim((string) ($n['link'] ?? ''));
                            $href = $link !== '' ? $link : 'notifications.php';
                            $unread = trim((string) ($n['read_at'] ?? '')) === '';
                            $typeTag = match ((string) ($n['type'] ?? '')) {
                                'discussion_reply', 'discussion' => 'Discussion',
                                'lesson_answer', 'qa' => 'Q&A',
                                'announcement', 'announcements' => 'Announcement',
                                'grade', 'grades' => 'Grade',
                                default => '',
                            };
                        ?>
                        <li>
                            <a href="<?= portal_escape($href) ?>" class="<?= $unread ? 'is-unread' : '' ?>">
                                <?php if ($unread): ?>
                                    <span class="dash-announce-tag">New</span>
                                <?php endif; ?>
                                <?php if ($typeTag !== ''): ?>
                                    <span class="dash-announce-tag dash-announce-tag--module"><?= portal_escape($typeTag) ?></span>
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

            <?php if ($isStaff || $isAdmin): ?>
            <article class="card-shell">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Shortcuts</p>
                        <h3 class="card-title">Your modules</h3>
                    </div>
                    <a class="inline-action" href="courses.php">All</a>
                </div>

                <?php if (empty($moduleWorkload) && empty($catalog)): ?>
                    <p class="dash-empty">No modules assigned yet.</p>
                <?php elseif (!empty($moduleWorkload)): ?>
                    <ul class="dash-workload-list">
                        <?php foreach (array_slice($moduleWorkload, 0, 8) as $mod): ?>
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
                                    <?php if ($busy > 0): ?>
                                    <span class="dash-workload-counts">
                                        <span title="Open questions"><?= $qCount ?> Q</span>
                                        <span title="Unmarked submissions"><?= $mCount ?> mark</span>
                                    </span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
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
                <p class="dash-empty" style="margin-top:12px;margin-bottom:0;">
                    <a href="timetable.php">Timetable</a>
                    ·
                    <a href="communication.php">Communication</a>
                </p>
            </article>
            <?php else: ?>
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

            <?php if ($unreadAnnouncementCount > 0): ?>
            <article class="card-shell">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Unread</p>
                        <h3 class="card-title">
                            <?= $unreadAnnouncementCount ?> announcement<?= $unreadAnnouncementCount !== 1 ? 's' : '' ?>
                        </h3>
                    </div>
                    <a class="inline-action" href="communication.php#module-announcements">All</a>
                </div>
                <ul class="dash-announce-list">
                    <?php foreach ($unreadCourseAnnouncements as $ann): ?>
                        <li>
                            <a class="is-unread" href="course.php?course=<?= urlencode((string) $ann['course_slug']) ?>&section=announcements">
                                <span class="dash-announce-tag dash-announce-tag--module"><?= portal_escape((string) $ann['course_title']) ?></span>
                                <strong><?= portal_escape((string) $ann['title']) ?></strong>
                                <span><?= portal_escape($relativeWhen((string) $ann['created_at'])) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </article>
            <?php endif; ?>
            <?php endif; ?>

            <?php if (!$isStaff && !$isAdmin): ?>
            <article class="card-shell">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Bulletin</p>
                        <h3 class="card-title">Latest updates</h3>
                    </div>
                    <a class="inline-action" href="communication.php">All</a>
                </div>

                <?php if (empty($majorAnnouncements) && empty($bulletinModuleAnnouncements)): ?>
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
                        <?php foreach ($bulletinModuleAnnouncements as $ann): ?>
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
            <?php endif; ?>

        </aside>
    </div>
</section>
<?php
$page_content = ob_get_clean();

require __DIR__ . '/../layout.php';
