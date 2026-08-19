<?php
declare(strict_types=1);

// ── DB queries for this course ────────────────────────────────────────────────
$courseId = (int) $course['id'];
$_db = portal_db();
$_me = portal_current_user();

// Folders + items
$_fStmt = $_db->prepare(
    "SELECT * FROM course_folders WHERE course_id = ? AND COALESCE(is_pre_enroll, 0) = 0 ORDER BY sort_order ASC, id ASC"
);
$_fStmt->execute([$courseId]);
$courseFolders = $_fStmt->fetchAll();

foreach ($courseFolders as &$_folder) {
    $_iStmt = $_db->prepare(
        "SELECT * FROM course_folder_items WHERE folder_id = ? ORDER BY sort_order ASC, id ASC"
    );
    $_iStmt->execute([$_folder['id']]);
    $_folder['items'] = $_iStmt->fetchAll();
}
unset($_folder);

// Open lesson Q&A counts (teachers) — keyed by video item id
$videoOpenQuestionCounts = [];
if (portal_can_manage_course($courseId)) {
    $_oqStmt = $_db->prepare(
        "SELECT item_id, COUNT(*) AS open_count
         FROM course_video_questions
         WHERE course_id = ? AND answer = ''
         GROUP BY item_id"
    );
    $_oqStmt->execute([$courseId]);
    foreach ($_oqStmt->fetchAll() as $_oq) {
        $videoOpenQuestionCounts[(int) $_oq['item_id']] = (int) $_oq['open_count'];
    }
}

$submissionModals = '';

// Assigned course staff for this course (course-level teacher / supervisor)
$_tStmt = $_db->prepare(
    "SELECT u.id, u.name, u.initials, u.email, ct.assignment_role
     FROM course_teachers ct
     JOIN users u ON u.id = ct.user_id
     WHERE ct.course_id = ?
     ORDER BY ct.assignment_role DESC, u.name ASC"
);
$_tStmt->execute([$courseId]);
$courseTeachers = $_tStmt->fetchAll();

// Staff accounts (owner / admin / teacher) not yet assigned to this course
$courseTeacherIds = array_column($courseTeachers, 'id');
$availableTeachers = [];
if (portal_is_admin()) {
    $_atStmt = $_db->query(
        "SELECT id, name, initials, role FROM users
         WHERE role IN ('owner', 'admin', 'teacher')
         ORDER BY
            CASE role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END,
            name ASC"
    );
    foreach ($_atStmt->fetchAll() as $_t) {
        if (!in_array((int) $_t['id'], $courseTeacherIds, true)) {
            $availableTeachers[] = $_t;
        }
    }
}

// Announcements (newest first)
$_aStmt = $_db->prepare(
    "SELECT ca.*, u.name AS author_name, u.initials AS author_initials
     FROM course_announcements ca
     JOIN users u ON u.id = ca.user_id
     WHERE ca.course_id = ?
     ORDER BY ca.created_at DESC"
);
$_aStmt->execute([$courseId]);
$courseAnnouncements = $_aStmt->fetchAll();

// Which announcements has the current user already read?
$_readStmt = $_db->prepare(
    "SELECT ar.announcement_id FROM announcement_reads ar
     JOIN course_announcements ca ON ca.id = ar.announcement_id
     WHERE ar.user_id = ? AND ca.course_id = ?"
);
$_readStmt->execute([(int) $_me['id'], $courseId]);
$_readAnnIds    = array_map('intval', $_readStmt->fetchAll(PDO::FETCH_COLUMN));
$unreadAnnouncements = array_values(
    array_filter($courseAnnouncements, fn($a) => !in_array((int) $a['id'], $_readAnnIds, true))
);

// Submissions: teachers see all per slot; students see only their own
$slotSubmissions = []; // item_id → [ submissions ]
$mySubmissions   = []; // item_id → single submission row
if (portal_can_manage_course($courseId)) {
    $_ssStmt = $_db->prepare(
        "SELECT cs.*, u.name AS student_name, u.initials AS student_initials
         FROM course_submissions cs
         JOIN users u ON u.id = cs.user_id
         WHERE cs.course_id = ?
         ORDER BY cs.submitted_at DESC"
    );
    $_ssStmt->execute([$courseId]);
    foreach ($_ssStmt->fetchAll() as $_sub) {
        $slotSubmissions[(int) $_sub['item_id']][] = $_sub;
    }
} else {
    $_myStmt = $_db->prepare(
        "SELECT * FROM course_submissions WHERE course_id = ? AND user_id = ?"
    );
    $_myStmt->execute([$courseId, (int) $_me['id']]);
    foreach ($_myStmt->fetchAll() as $_sub) {
        $mySubmissions[(int) $_sub['item_id']] = $_sub;
    }
}

// Review annotations grouped by submission id
$submissionAnnotations = []; // submission_id → [ annotations ]
try {
    if (portal_can_manage_course($courseId)) {
        $_anStmt = $_db->prepare(
            "SELECT a.*, u.name AS author_name
             FROM course_submission_annotations a
             LEFT JOIN users u ON u.id = a.author_id
             WHERE a.course_id = ?
             ORDER BY a.id ASC"
        );
        $_anStmt->execute([$courseId]);
    } else {
        $_anStmt = $_db->prepare(
            "SELECT a.*, u.name AS author_name
             FROM course_submission_annotations a
             LEFT JOIN users u ON u.id = a.author_id
             JOIN course_submissions cs ON cs.id = a.submission_id
             WHERE a.course_id = ? AND cs.user_id = ?
             ORDER BY a.id ASC"
        );
        $_anStmt->execute([$courseId, (int) $_me['id']]);
    }
    foreach ($_anStmt->fetchAll() as $_an) {
        $submissionAnnotations[(int) $_an['submission_id']][] = $_an;
    }
} catch (\PDOException $e) {
    $submissionAnnotations = [];
}

if (portal_can_manage_course($courseId)) {
    $_gradeStmt = $_db->prepare(
        "SELECT cs.*, cfi.title AS slot_title, cfi.submission_deadline, cfi.submission_weight,
                u.name AS student_name, u.initials AS student_initials
         FROM course_submissions cs
         JOIN course_folder_items cfi ON cfi.id = cs.item_id
         JOIN users u ON u.id = cs.user_id
         WHERE cs.course_id = ?
         ORDER BY cfi.title ASC, u.name ASC"
    );
    $_gradeStmt->execute([$courseId]);
} else {
    $_gradeStmt = $_db->prepare(
        "SELECT cs.*, cfi.title AS slot_title, cfi.submission_deadline, cfi.submission_weight
         FROM course_submissions cs
         JOIN course_folder_items cfi ON cfi.id = cs.item_id
         WHERE cs.course_id = ? AND cs.user_id = ?
         ORDER BY cs.submitted_at DESC"
    );
    $_gradeStmt->execute([$courseId, (int) $_me['id']]);
}
$submissionGradebook = $_gradeStmt->fetchAll();

// Activities and quizzes share the course gradebook with uploaded assignments.
// Students only receive rows after the teacher has explicitly released them.
if (!portal_can_manage_course($courseId)) {
    foreach (portal_activity_gradebook_rows($courseId, (int) $_me['id']) as $_activityGrade) {
        $submissionGradebook[] = [
            'id' => (int) ($_activityGrade['attempt_id'] ?? 0),
            'item_id' => 0,
            'score' => $_activityGrade['score'],
            'marked_at' => (string) ($_activityGrade['marked_at'] ?? ''),
            'submitted_at' => (string) ($_activityGrade['submitted_at'] ?? ''),
            'slot_title' => (string) ($_activityGrade['title'] ?? 'Activity'),
            'submission_deadline' => '',
            'submission_weight' => $_activityGrade['submission_weight'] ?? 100,
            'grade_source' => 'activity',
            'activity_id' => (int) ($_activityGrade['activity_id'] ?? 0),
            'attempt_id' => (int) ($_activityGrade['attempt_id'] ?? 0),
        ];
    }
}

$submissionVersions = [];
if (portal_can_manage_course($courseId)) {
    $_versionStmt = $_db->prepare(
        "SELECT csv.*, u.name AS student_name, u.initials AS student_initials
         FROM course_submission_versions csv
         JOIN users u ON u.id = csv.user_id
         WHERE csv.course_id = ?
         ORDER BY csv.submitted_at DESC"
    );
    $_versionStmt->execute([$courseId]);
} else {
    $_versionStmt = $_db->prepare(
        "SELECT * FROM course_submission_versions
         WHERE course_id = ? AND user_id = ?
         ORDER BY submitted_at DESC"
    );
    $_versionStmt->execute([$courseId, (int) $_me['id']]);
}
foreach ($_versionStmt->fetchAll() as $_version) {
    $submissionVersions[(int) $_version['submission_id']][] = $_version;
}

// Class schedule
$_schStmt = $_db->prepare(
    "SELECT * FROM course_schedule WHERE course_id = ? ORDER BY sort_order ASC, id ASC"
);
$_schStmt->execute([$courseId]);
$courseSchedule = $_schStmt->fetchAll();
$courseScheduleSummary = portal_format_course_schedule_summary($courseSchedule);
$course['meeting'] = $courseScheduleSummary['meeting'];
$course['room'] = $courseScheduleSummary['room'];
$course['location_mode'] = $courseScheduleSummary['mode'];
$courseUpcomingEvents = portal_events_for_course($courseId, 20, true);

// Discussion topics (with reply count)
$_topicId = (int) ($_GET['topic'] ?? 0);
$_dtStmt = $_db->prepare(
    "SELECT dt.*, u.name AS author_name, u.initials AS author_initials,
            (SELECT COUNT(*) FROM course_discussion_replies r WHERE r.topic_id = dt.id) AS reply_count
     FROM course_discussion_topics dt
     JOIN users u ON u.id = dt.user_id
     WHERE dt.course_id = ?
     ORDER BY dt.created_at DESC"
);
$_dtStmt->execute([$courseId]);
$dbTopics = $_dtStmt->fetchAll();

$dbCurrentTopic = null;
$dbReplies      = [];
if ($_topicId > 0) {
    $_ctStmt = $_db->prepare(
        "SELECT dt.*, u.name AS author_name, u.initials AS author_initials
         FROM course_discussion_topics dt
         JOIN users u ON u.id = dt.user_id
         WHERE dt.id = ? AND dt.course_id = ?"
    );
    $_ctStmt->execute([$_topicId, $courseId]);
    $dbCurrentTopic = $_ctStmt->fetch() ?: null;

    if ($dbCurrentTopic) {
        $_rStmt = $_db->prepare(
            "SELECT dr.*, u.name AS author_name, u.initials AS author_initials
             FROM course_discussion_replies dr
             JOIN users u ON u.id = dr.user_id
             WHERE dr.topic_id = ?
             ORDER BY dr.created_at ASC"
        );
        $_rStmt->execute([$_topicId]);
        $dbReplies = $_rStmt->fetchAll();
    }
}

// Groups (with member count and current user's membership)
$_grpStmt = $_db->prepare(
    "SELECT cg.*,
            (SELECT COUNT(*) FROM course_group_members m WHERE m.group_id = cg.id) AS member_count
     FROM course_groups cg
     WHERE cg.course_id = ?
     ORDER BY cg.id ASC"
);
$_grpStmt->execute([$courseId]);
$dbGroups = $_grpStmt->fetchAll();

// Current user's group memberships for this course
$_myGrpStmt = $_db->prepare(
    "SELECT cgm.group_id FROM course_group_members cgm
     JOIN course_groups cg ON cg.id = cgm.group_id
     WHERE cg.course_id = ? AND cgm.user_id = ?"
);
$_myGrpStmt->execute([$courseId, (int)$_me['id']]);
$myGroupIds = array_map('intval', $_myGrpStmt->fetchAll(PDO::FETCH_COLUMN));

// Group members per group (for teacher view)
$groupMembers = [];
if (portal_can_manage_course($courseId)) {
    $_gmStmt = $_db->prepare(
        "SELECT cgm.group_id, u.name, u.initials
         FROM course_group_members cgm
         JOIN users u ON u.id = cgm.user_id
         JOIN course_groups cg ON cg.id = cgm.group_id
         WHERE cg.course_id = ?
         ORDER BY u.name ASC"
    );
    $_gmStmt->execute([$courseId]);
    foreach ($_gmStmt->fetchAll() as $_gm) {
        $groupMembers[(int)$_gm['group_id']][] = $_gm;
    }
}

// Tab visibility settings
$_enabledKeys = portal_enabled_tab_keys($courseId);

$requestedSection = (string) ($_GET['section'] ?? 'content');
$_allTabDefs      = portal_course_tab_definitions($course);

// Filter by enabled tabs
if ($_enabledKeys !== null) {
    if (!in_array('content', $_enabledKeys)) {
        $_enabledKeys[] = 'content';
    }
    $_allTabDefs = array_values(
        array_filter($_allTabDefs, fn($t) => in_array($t['key'], $_enabledKeys))
    );
}
$validSections = array_column($_allTabDefs, 'key');
$sectionKey    = in_array($requestedSection, $validSections, true) ? $requestedSection : 'content';
$tabs          = portal_course_tab_definitions($course, $sectionKey);
if ($_enabledKeys !== null) {
    $tabs = array_values(array_filter($tabs, fn($t) => in_array($t['key'], $_enabledKeys)));
}

// Auto-mark announcements as read when the user opens the Announcements tab
if ($sectionKey === 'announcements' && !empty($unreadAnnouncements)) {
    $_ins = $_db->prepare("INSERT OR IGNORE INTO announcement_reads (user_id, announcement_id) VALUES (?,?)");
    foreach ($unreadAnnouncements as $_ua) {
        $_ins->execute([(int) $_me['id'], (int) $_ua['id']]);
    }
    $unreadAnnouncements = [];
}

// When a student opens Grades (or a deep-linked returned review), clear those
// marks from the dashboard "Returned grades" queue.
if (!portal_can_manage_course($courseId)) {
    $_activityGradeBadgeStmt = $_db->prepare(
        "SELECT COUNT(*)
         FROM activity_attempts
         WHERE course_id = ? AND user_id = ?
           AND status = 'released'
           AND (grade_seen_at = '' OR grade_seen_at IS NULL)"
    );
    $_activityGradeBadgeStmt->execute([$courseId, (int) $_me['id']]);
    $unreadCourseGradeCount = (int) $_activityGradeBadgeStmt->fetchColumn();
    $_fileGradeBadgeStmt = $_db->prepare(
        "SELECT COUNT(*)
         FROM course_submissions
         WHERE course_id = ? AND user_id = ?
           AND score IS NOT NULL
           AND marked_at != ''
           AND grades_released_at != ''
           AND (grade_seen_at = '' OR grade_seen_at IS NULL)"
    );
    $_fileGradeBadgeStmt->execute([$courseId, (int) $_me['id']]);
    $unreadCourseGradeCount += (int) $_fileGradeBadgeStmt->fetchColumn();

    $openReviewRaw = (string) ($_GET['open_review'] ?? '');
    if (preg_match('/^rvw-(\d+)$/', $openReviewRaw, $openReviewMatch)) {
        $_db->prepare(
            "UPDATE course_submissions
             SET grade_seen_at = datetime('now')
             WHERE id = ? AND course_id = ? AND user_id = ?
               AND marked_at != ''
               AND grades_released_at != ''
               AND (grade_seen_at = '' OR grade_seen_at IS NULL)"
        )->execute([(int) $openReviewMatch[1], $courseId, (int) $_me['id']]);
    }
    if ($sectionKey === 'gradebook') {
        $_db->prepare(
            "UPDATE course_submissions
             SET grade_seen_at = datetime('now')
             WHERE course_id = ? AND user_id = ?
               AND marked_at != ''
               AND score IS NOT NULL
               AND grades_released_at != ''
               AND (grade_seen_at = '' OR grade_seen_at IS NULL)"
        )->execute([$courseId, (int) $_me['id']]);
        $_db->prepare(
            "UPDATE activity_attempts
             SET grade_seen_at = datetime('now')
             WHERE course_id = ? AND user_id = ?
               AND status = 'released'
               AND (grade_seen_at = '' OR grade_seen_at IS NULL)"
        )->execute([$courseId, (int) $_me['id']]);
        $unreadCourseGradeCount = 0;
    }
}

// Live badge counts — show only unread count
foreach ($tabs as &$_tab) {
    if ($_tab['key'] === 'announcements') {
        $_tab['badge'] = count($unreadAnnouncements);
    } elseif ($_tab['key'] === 'gradebook' && !portal_can_manage_course($courseId)) {
        $_tab['badge'] = (int) ($unreadCourseGradeCount ?? 0);
    }
}
unset($_tab);

$currentSection = $tabs[0];

foreach ($tabs as $tab) {
    if ($tab['key'] === $sectionKey) {
        $currentSection = $tab;
        break;
    }
}

$tabLookup = [];

foreach ($tabs as $tab) {
    $tabLookup[$tab['key']] = $tab['href'];
}

$page_title = $course['title'] . ' - ' . $currentSection['label'] . ' | ' . portal_school_name();
$active_page = 'courses';
$page_eyebrow = 'Course section';
$page_heading = $course['title'];
$page_description = $currentSection['label'] . ' | ' . $course['full_title'] . ' | ' . $course['meeting'];
if ($preEnrollBlocks) {
    $page_eyebrow = 'Before you start';
    $page_description = 'Complete a short knowledge check to open this module.';
}
