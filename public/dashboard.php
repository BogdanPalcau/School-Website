<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../course_catalog.php';
require_once __DIR__ . '/../customization.php';

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
           AND cs.grades_released_at != ''
           AND (cs.grade_seen_at = '' OR cs.grade_seen_at IS NULL)
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

$dashUpcomingEvents = portal_events_for_dashboard(8);

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

$dashPrefs = portal_customization_preferences($uid);
$showContinue = !empty($dashPrefs['show_continue']);
$showBulletin = !empty($dashPrefs['show_bulletin']);
$feedDefaultFilter = (($dashPrefs['dashboard_focus'] ?? 'priorities') === 'schedule') ? 'class' : 'all';

$startOfToday = (int) strtotime('today midnight');
$endOfToday = $startOfToday + 86400;
$nowTs = time();

$dashFeed = [];
$pushFeed = static function (
    array $item
) use (&$dashFeed, $startOfToday, $endOfToday): void {
    $when = (int) ($item['when'] ?? 0);
    if ($when <= 0) {
        $when = time();
    }
    $item['when'] = $when;
    if ($when < $startOfToday) {
        $item['bucket'] = 0;
    } elseif ($when < $endOfToday) {
        $item['bucket'] = 1;
    } else {
        $item['bucket'] = 2;
    }
    $dashFeed[] = $item;
};

foreach ($todayClasses as $slot) {
    $joinUrl = trim((string) ($slot['room'] ?? ''));
    $hasJoin = $isJoinUrl($slot);
    $phase = $slotPhase($slot);
    $start = trim((string) ($slot['start_time'] ?? ''));
    $when = (int) strtotime(date('Y-m-d') . ' ' . ($start !== '' ? $start : '08:00'));
    $statusLabel = match ($phase) {
        'past' => 'Done',
        'current' => 'Now',
        default => 'Today',
    };
    $pushFeed([
        'filter' => 'class',
        'source' => 'class',
        'when' => $when,
        'title' => (string) $slot['title'],
        'meta' => $formatTime($slot) . ' · ' . (string) $slot['code'],
        'href' => 'course.php?course=' . urlencode((string) $slot['slug']) . '&section=calendar',
        'action' => $hasJoin && $phase !== 'past' ? 'Join' : 'Open',
        'action_href' => $hasJoin && $phase !== 'past' ? $joinUrl : '',
        'action_external' => $hasJoin && $phase !== 'past',
        'secondary' => $hasJoin && $phase !== 'past' ? 'Open' : '',
        'status' => $statusLabel,
        'status_class' => $phase === 'current' ? 'current' : ($phase === 'past' ? 'past' : 'open'),
        'stale' => false,
        'phase' => $phase,
        'accent' => (string) ($slot['accent'] ?? ''),
    ]);
}
if (empty($todayClasses) && is_array($nextClass)) {
    $joinUrl = trim((string) ($nextClass['room'] ?? ''));
    $hasJoin = $isJoinUrl($nextClass);
    $start = trim((string) ($nextClass['start_time'] ?? ''));
    $day = (string) ($nextClass['day_of_week'] ?? '');
    $when = (int) strtotime($day . ' ' . ($start !== '' ? $start : '08:00'));
    if ($when > 0 && $when < $nowTs) {
        $when = (int) strtotime('next ' . $day . ' ' . ($start !== '' ? $start : '08:00'));
    }
    $pushFeed([
        'filter' => 'class',
        'source' => 'class',
        'when' => $when > 0 ? $when : $nowTs + 86400,
        'title' => (string) $nextClass['title'],
        'meta' => $day . ' · ' . $formatTime($nextClass) . ' · ' . (string) $nextClass['code'],
        'href' => 'course.php?course=' . urlencode((string) $nextClass['slug']) . '&section=calendar',
        'action' => $hasJoin ? 'Join' : 'Open',
        'action_href' => $hasJoin ? $joinUrl : '',
        'action_external' => $hasJoin,
        'secondary' => $hasJoin ? 'Open' : '',
        'status' => 'Next class',
        'status_class' => 'open',
        'stale' => false,
        'phase' => 'upcoming',
        'accent' => (string) ($nextClass['accent'] ?? ''),
    ]);
}

if ($isStaff || $isAdmin) {
    foreach ($staffMarkQueue as $item) {
        $pushFeed([
            'filter' => 'work',
            'source' => 'mark',
            'when' => $nowTs - (int) ($item['age'] ?? 0),
            'title' => (string) $item['title'],
            'meta' => (string) $item['meta'] . (trim((string) ($item['time'] ?? '')) !== '' ? ' · ' . $item['time'] : ''),
            'href' => (string) $item['href'],
            'action' => 'Mark',
            'status' => !empty($item['stale']) ? 'Waiting' : 'To mark',
            'status_class' => !empty($item['stale']) ? 'closed' : 'soon',
            'stale' => !empty($item['stale']),
            'accent' => (string) ($item['accent'] ?? ''),
        ]);
    }
    foreach ($staffQuestionQueue as $item) {
        $pushFeed([
            'filter' => 'work',
            'source' => 'question',
            'when' => $nowTs - (int) ($item['age'] ?? 0),
            'title' => (string) $item['title'],
            'meta' => (string) $item['meta'] . (trim((string) ($item['time'] ?? '')) !== '' ? ' · ' . $item['time'] : ''),
            'href' => (string) $item['href'],
            'action' => 'Answer',
            'status' => 'Q&A',
            'status_class' => !empty($item['stale']) ? 'closed' : 'soon',
            'stale' => !empty($item['stale']),
            'accent' => (string) ($item['accent'] ?? ''),
        ]);
    }
    foreach ($staffDeadlineQueue as $item) {
        $state = (string) ($item['state'] ?? 'open');
        $pushFeed([
            'filter' => 'work',
            'source' => 'deadline',
            'when' => (int) ($item['ts'] ?? $nowTs),
            'title' => (string) $item['title'],
            'meta' => (string) $item['meta'] . ' · ' . (string) $item['time'],
            'href' => (string) $item['href'],
            'action' => 'Open',
            'status' => $state === 'closed' ? 'Past due' : ($state === 'soon' ? 'Due soon' : 'Upcoming'),
            'status_class' => $state === 'closed' ? 'closed' : ($state === 'soon' ? 'soon' : 'open'),
            'stale' => $state === 'closed',
            'accent' => (string) ($item['accent'] ?? ''),
        ]);
    }
} else {
    foreach ($studentDeadlineQueue as $item) {
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
        $pushFeed([
            'filter' => 'work',
            'source' => 'deadline',
            'when' => (int) ($item['deadline_info']['timestamp'] ?? $nowTs),
            'title' => (string) $item['title'],
            'meta' => (string) $item['course_title'] . ' · Due ' . (string) $item['deadline_info']['text'],
            'href' => 'course.php?course=' . urlencode((string) $item['slug']) . '&section=content',
            'action' => $submitted ? 'View' : 'Open',
            'status' => $statusLabel,
            'status_class' => $statusClass,
            'stale' => !$submitted && $state === 'closed',
            'accent' => (string) ($item['accent'] ?? ''),
        ]);
    }
    foreach ($returnedGrades as $row) {
        $scoreBit = ($row['score'] !== null && $row['score'] !== '') ? ' · ' . (int) $row['score'] . '%' : '';
        $pushFeed([
            'filter' => 'grade',
            'source' => 'grade',
            'when' => portal_db_timestamp((string) $row['marked_at']) ?? $nowTs,
            'title' => (string) $row['item_title'],
            'meta' => (string) $row['course_title'] . ' · Marked ' . $relativeWhen((string) $row['marked_at']) . $scoreBit,
            'href' => 'course.php?course=' . urlencode((string) $row['slug']) . '&section=gradebook&open_review=rvw-' . (int) $row['id'],
            'action' => 'View',
            'status' => 'Returned',
            'status_class' => 'marked',
            'stale' => false,
            'accent' => (string) ($row['accent'] ?? ''),
        ]);
    }
    if ($showContinue) {
        foreach ($continueWatching as $row) {
            $stamp = $formatVideoStamp((int) $row['position_seconds']);
            $pushFeed([
                'filter' => 'lesson',
                'source' => 'lesson',
                'when' => portal_db_timestamp((string) $row['updated_at']) ?? $nowTs,
                'title' => (string) $row['lesson_title'],
                'meta' => (string) $row['course_title']
                    . ($stamp !== '' ? ' · Resume at ' . $stamp : '')
                    . ' · ' . $relativeWhen((string) $row['updated_at']),
                'href' => 'lesson-viewer.php?item=' . (int) $row['item_id'],
                'action' => 'Resume',
                'status' => 'Lesson',
                'status_class' => 'open',
                'stale' => false,
                'accent' => (string) ($row['accent'] ?? ''),
            ]);
        }
    }
}

foreach ($unreadCourseAnnouncements as $ann) {
    $pushFeed([
        'filter' => 'message',
        'source' => 'unread-announcement',
        'when' => portal_db_timestamp((string) $ann['created_at']) ?? $nowTs,
        'title' => (string) $ann['title'],
        'meta' => (string) $ann['course_title'] . ' · ' . $relativeWhen((string) $ann['created_at']),
        'href' => 'course.php?course=' . urlencode((string) $ann['course_slug']) . '&section=announcements',
        'action' => 'Read',
        'status' => 'Unread',
        'status_class' => 'soon',
        'stale' => false,
        'accent' => (string) ($ann['course_accent'] ?? ''),
    ]);
}

if ($showBulletin) {
    foreach ($majorAnnouncements as $ann) {
        $pushFeed([
            'filter' => 'message',
            'source' => 'bulletin',
            'when' => portal_db_timestamp((string) $ann['created_at']) ?? $nowTs,
            'title' => (string) $ann['title'],
            'meta' => 'School · ' . $relativeWhen((string) $ann['created_at']),
            'href' => 'communication.php#major-announcements',
            'action' => 'Open',
            'status' => 'School',
            'status_class' => 'submitted',
            'stale' => false,
            'accent' => '',
        ]);
    }
    foreach ($bulletinModuleAnnouncements as $ann) {
        $pushFeed([
            'filter' => 'message',
            'source' => 'bulletin',
            'when' => portal_db_timestamp((string) $ann['created_at']) ?? $nowTs,
            'title' => (string) $ann['title'],
            'meta' => (string) $ann['course_title'] . ' · ' . $relativeWhen((string) $ann['created_at']),
            'href' => 'course.php?course=' . urlencode((string) $ann['course_slug']) . '&section=announcements',
            'action' => 'Open',
            'status' => 'Module',
            'status_class' => 'submitted',
            'stale' => false,
            'accent' => (string) ($ann['course_accent'] ?? ''),
        ]);
    }
}

$seenNotifLinks = [];
foreach ($recentInboxNotifications as $n) {
    $created = portal_db_timestamp((string) ($n['created_at'] ?? '')) ?? 0;
    $unread = trim((string) ($n['read_at'] ?? '')) === '';
    if (!$unread && ($created < $nowTs - (3 * 86400))) {
        continue;
    }
    $type = (string) ($n['type'] ?? '');
    if (in_array($type, ['announcement', 'announcements'], true)) {
        continue;
    }
    $link = trim((string) ($n['link'] ?? ''));
    $href = $link !== '' ? $link : 'notifications.php';
    $dupKey = $type . '|' . $href;
    if (isset($seenNotifLinks[$dupKey])) {
        continue;
    }
    $seenNotifLinks[$dupKey] = true;
    $typeTag = match ($type) {
        'discussion_reply', 'discussion' => 'Discussion',
        'lesson_answer', 'qa' => 'Q&A',
        'grade', 'grades' => 'Grade',
        'event' => 'Event',
        default => 'Inbox',
    };
    $body = trim((string) ($n['body'] ?? ''));
    $pushFeed([
        'filter' => 'message',
        'source' => 'notification',
        'when' => $created > 0 ? $created : $nowTs,
        'title' => (string) $n['title'],
        'meta' => ($body !== '' ? substr($body, 0, 100) . ' · ' : '') . $relativeWhen((string) $n['created_at']),
        'href' => $href,
        'action' => 'Open',
        'status' => $unread ? 'New' : $typeTag,
        'status_class' => $unread ? 'soon' : 'open',
        'stale' => false,
        'accent' => '',
    ]);
}

foreach ($dashUpcomingEvents as $dashEvent) {
    $when = portal_db_timestamp((string) ($dashEvent['starts_at'] ?? '')) ?? $nowTs;
    $dashCid = isset($dashEvent['course_id']) && $dashEvent['course_id'] !== null && $dashEvent['course_id'] !== ''
        ? (int) $dashEvent['course_id']
        : 0;
    $dashTag = $dashCid > 0 ? (string) ($dashEvent['course_title'] ?? 'Course') : 'School';
    $pushFeed([
        'filter' => 'event',
        'source' => 'event',
        'when' => $when,
        'title' => (string) $dashEvent['title'],
        'meta' => $dashTag . ' · ' . portal_event_format_full((string) $dashEvent['starts_at']),
        'href' => 'events.php?event=' . (int) $dashEvent['id'],
        'action' => 'Open',
        'status' => 'Event',
        'status_class' => 'open',
        'stale' => false,
        'accent' => (string) ($dashEvent['course_accent'] ?? ''),
    ]);
}

usort($dashFeed, static function (array $a, array $b): int {
    $byBucket = ((int) $a['bucket']) <=> ((int) $b['bucket']);
    if ($byBucket !== 0) {
        return $byBucket;
    }
    if ((int) $a['bucket'] === 0) {
        return ((int) $b['when']) <=> ((int) $a['when']);
    }
    return ((int) $a['when']) <=> ((int) $b['when']);
});
$dashFeed = array_slice($dashFeed, 0, 32);

$feedFilters = [
    'all' => 'All',
    'class' => 'Classes',
    'work' => 'Work',
    'grade' => 'Grades',
    'message' => 'Messages',
    'event' => 'Events',
    'lesson' => 'Lessons',
];
$feedFilterCounts = ['all' => count($dashFeed)];
foreach ($dashFeed as $item) {
    $key = (string) ($item['filter'] ?? '');
    if ($key === '') {
        continue;
    }
    $feedFilterCounts[$key] = ($feedFilterCounts[$key] ?? 0) + 1;
}
$visibleFeedFilters = ['all' => 'All'];
foreach ($feedFilters as $key => $label) {
    if ($key === 'all') {
        continue;
    }
    if (($feedFilterCounts[$key] ?? 0) > 0) {
        $visibleFeedFilters[$key] = $label;
    }
}
if (!isset($visibleFeedFilters[$feedDefaultFilter])) {
    $feedDefaultFilter = 'all';
}

$page_title = 'Dashboard | ' . portal_school_name();
$active_page = 'dashboard';
$page_eyebrow = 'Overview';
$page_heading = $greeting . ', ' . $firstName;
$page_description = 'One list for today’s classes, work waiting on you, and new updates. Filter it if you only want one type.';

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
            <a class="dash-stat-link" href="#to-do" data-feed-open="work">View work</a>
        </article>
        <?php endif; ?>
        <article class="dash-stat">
            <span class="dash-stat-label">Today</span>
            <strong class="dash-stat-value"><?= count($todayClasses) ?></strong>
            <a class="dash-stat-link" href="#to-do" data-feed-open="class">View schedule</a>
        </article>
        <article class="dash-stat<?= $unreadNotifCount > 0 ? ' dash-stat--alert' : '' ?>">
            <span class="dash-stat-label">Inbox</span>
            <strong class="dash-stat-value"><?= $unreadNotifCount ?></strong>
            <a class="dash-stat-link" href="#to-do" data-feed-open="message">Open inbox</a>
        </article>
    </div>

    <div class="dash-columns">
        <div class="dash-main stack">

            <article class="card-shell dash-work dash-feed" id="to-do" data-feed-default="<?= portal_escape($feedDefaultFilter) ?>">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Today / To do</p>
                        <h3 class="card-title">Your day</h3>
                        <p class="dash-section-rule">Classes, work, grades, messages, and events in one list. Filter to one type when you need to.</p>
                    </div>
                    <div class="button-row dash-feed-head-actions">
                        <?php if (!$isStaff && !$isAdmin): ?>
                            <a class="button-secondary button--sm" href="calendar-export.php?types=deadlines" title="Import into Google or Apple Calendar">Deadlines .ics</a>
                        <?php endif; ?>
                        <a class="inline-action" href="timetable.php">Timetable</a>
                    </div>
                </div>

                <?php if (count($visibleFeedFilters) > 2): ?>
                <div class="dash-feed-filters" role="toolbar" aria-label="Filter today’s list">
                    <?php foreach ($visibleFeedFilters as $filterKey => $filterLabel): ?>
                        <button type="button"
                                class="dash-feed-chip<?= $filterKey === $feedDefaultFilter ? ' is-active' : '' ?>"
                                data-feed-filter="<?= portal_escape($filterKey) ?>"
                                aria-pressed="<?= $filterKey === $feedDefaultFilter ? 'true' : 'false' ?>">
                            <?= portal_escape($filterLabel) ?>
                            <span><?= (int) ($feedFilterCounts[$filterKey] ?? 0) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($dashFeed === []): ?>
                    <div id="dashboard-upcoming-events">
                        <p class="dash-empty">You’re all caught up. <a href="courses.php">Open a module</a> or check the <a href="timetable.php">timetable</a>.</p>
                    </div>
                <?php else: ?>
                    <ul class="dash-work-list dash-feed-list" id="dashboard-upcoming-events">
                        <?php foreach ($dashFeed as $item): ?>
                            <?php
                                $filter = (string) ($item['filter'] ?? 'work');
                                $phase = (string) ($item['phase'] ?? '');
                                $actionHref = trim((string) ($item['action_href'] ?? ''));
                                $primaryHref = $actionHref !== '' ? $actionHref : (string) $item['href'];
                                $secondary = trim((string) ($item['secondary'] ?? ''));
                                $source = (string) ($item['source'] ?? '');
                                $statusClass = (string) ($item['status_class'] ?? 'open');
                                $btnClass = 'button-secondary';
                                if (in_array($source, ['mark', 'question'], true) || $statusClass === 'closed' || $statusClass === 'soon') {
                                    $btnClass = 'button';
                                }
                                if ($statusClass === 'submitted' || $source === 'grade' || $source === 'event' || $source === 'bulletin') {
                                    $btnClass = 'button-secondary';
                                }
                            ?>
                            <li class="dash-work-row dash-feed-item<?= $phase !== '' ? ' dash-work-row--' . portal_escape($phase) : '' ?><?= !empty($item['stale']) ? ' is-stale' : '' ?><?= $secondary !== '' ? ' dash-work-row--actions' : '' ?>"
                                data-feed-kind="<?= portal_escape($filter) ?>"
                                data-feed-source="<?= portal_escape($source) ?>">
                                <div class="dash-work-row-main">
                                    <strong><?= portal_escape((string) $item['title']) ?></strong>
                                    <span>
                                        <?= portal_escape((string) $item['meta']) ?>
                                        <?php if (trim((string) ($item['status'] ?? '')) !== ''): ?>
                                            · <span class="dash-status dash-status--<?= portal_escape($statusClass) ?>"><?= portal_escape((string) $item['status']) ?></span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="dash-work-row-actions">
                                    <a class="<?= $btnClass ?> button--sm"
                                       href="<?= portal_escape($primaryHref) ?>"
                                       <?php if (!empty($item['action_external'])): ?>target="_blank" rel="noopener noreferrer"<?php endif; ?>>
                                        <?= portal_escape((string) ($item['action'] ?? 'Open')) ?>
                                    </a>
                                    <?php if ($secondary !== ''): ?>
                                        <a class="button-secondary button--sm" href="<?= portal_escape((string) $item['href']) ?>"><?= portal_escape($secondary) ?></a>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="dash-empty" data-feed-empty hidden>Nothing in this filter. Choose All to see the full list.</p>
                    <p class="dash-queue-more">
                        <a href="notifications.php">All notifications</a>
                        ·
                        <a href="events.php">All events</a>
                        ·
                        <a href="communication.php">Announcements</a>
                    </p>
                <?php endif; ?>
            </article>
            <script>
            (function () {
                const root = document.getElementById('to-do');
                if (!root) return;
                const buttons = Array.from(root.querySelectorAll('[data-feed-filter]'));
                const items = Array.from(root.querySelectorAll('[data-feed-kind]'));
                const empty = root.querySelector('[data-feed-empty]');
                const allowed = buttons.map((btn) => btn.getAttribute('data-feed-filter'));
                function apply(filter) {
                    if (allowed.length && filter !== 'all' && !allowed.includes(filter)) {
                        filter = root.getAttribute('data-feed-default') || 'all';
                    }
                    let shown = 0;
                    items.forEach((el) => {
                        const on = filter === 'all' || el.getAttribute('data-feed-kind') === filter;
                        el.hidden = !on;
                        if (on) shown += 1;
                    });
                    if (empty) empty.hidden = shown > 0;
                    buttons.forEach((btn) => {
                        const active = btn.getAttribute('data-feed-filter') === filter;
                        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
                        btn.classList.toggle('is-active', active);
                    });
                }
                buttons.forEach((btn) => {
                    btn.addEventListener('click', () => apply(btn.getAttribute('data-feed-filter') || 'all'));
                });
                document.querySelectorAll('[data-feed-open]').forEach((link) => {
                    link.addEventListener('click', () => {
                        const filter = link.getAttribute('data-feed-open') || 'all';
                        window.setTimeout(() => apply(filter), 0);
                    });
                });
                apply(root.getAttribute('data-feed-default') || 'all');
            })();
            </script>

        </div>

        <aside class="dash-side stack">

            <?php if ($isStaff || $isAdmin): ?>
            <article class="card-shell" id="dashboard-quick-access">
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
            <article class="card-shell" id="dashboard-quick-access">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Your modules</p>
                        <h3 class="card-title">Quick access</h3>
                    </div>
                    <a class="inline-action" href="courses.php">All</a>
                </div>

                <?php
                    $favIds = [];
                    if (function_exists('portal_customization_preferences')) {
                        $dashPrefs = portal_customization_preferences($uid);
                        $favIds = is_array($dashPrefs['favorite_course_ids'] ?? null)
                            ? $dashPrefs['favorite_course_ids']
                            : [];
                    }
                    $quickFavorites = [];
                    $quickRest = [];
                    foreach ($catalog as $course) {
                        $cid = (int) ($course['id'] ?? 0);
                        if (in_array($cid, $favIds, true)) {
                            $quickFavorites[] = $course;
                        } elseif ((string) ($course['status'] ?? '') !== 'archived') {
                            $quickRest[] = $course;
                        }
                    }
                    $quickCatalog = array_slice(array_merge($quickFavorites, $quickRest), 0, 6);
                ?>
                <?php if ($catalog === []): ?>
                    <p class="dash-empty">No modules assigned yet.</p>
                <?php elseif ($quickCatalog === []): ?>
                    <p class="dash-empty">No current modules. <a href="courses.php?status=archived">View archived</a></p>
                <?php else: ?>
                    <ul class="dash-course-list">
                        <?php foreach (array_slice($quickCatalog, 0, 6) as $course): ?>
                            <?php $courseLocked = !portal_course_student_may_enter($course); ?>
                            <li>
                                <a class="dash-course-link<?= $courseLocked ? ' is-course-locked' : '' ?>"
                                   href="course.php?course=<?= urlencode((string) $course['slug']) ?>"
                                   <?php if ($courseLocked): ?>data-course-locked="1" aria-disabled="true"<?php endif; ?>>
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

            <?php endif; ?>
        </aside>
    </div>
</section>
<?php
$page_content = ob_get_clean();

require __DIR__ . '/../layout.php';
