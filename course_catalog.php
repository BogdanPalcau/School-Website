<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (!function_exists('portal_staff_initials')) {
    function portal_staff_initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $initials .= strtoupper(substr($part, 0, 1));

            if (strlen($initials) === 2) {
                break;
            }
        }

        return $initials !== '' ? $initials : 'ST';
    }
}

if (!function_exists('portal_schedule_slot_is_online')) {
    function portal_schedule_slot_is_online(string $roomOrLink): bool
    {
        $value = trim($roomOrLink);
        return $value !== '' && (bool) preg_match('#^https?://#i', $value);
    }
}

if (!function_exists('portal_format_course_schedule_summary')) {
    /**
     * Build courses-list / hero copy from live calendar slots.
     *
     * @param list<array<string, mixed>> $slots
     * @return array{meeting: string, room: string, mode: string}
     */
    function portal_format_course_schedule_summary(array $slots): array
    {
        if ($slots === []) {
            return [
                'meeting' => 'No schedule yet',
                'room' => 'Location TBA',
                'mode' => 'TBA',
            ];
        }

        $meetingParts = [];
        $places = [];
        $hasOnline = false;
        $hasPhysical = false;

        foreach ($slots as $slot) {
            $day = substr(trim((string) ($slot['day_of_week'] ?? '')), 0, 3);
            $start = trim((string) ($slot['start_time'] ?? ''));
            $end = trim((string) ($slot['end_time'] ?? ''));
            $time = $start;
            if ($end !== '') {
                $time .= ($time !== '' ? '–' : '') . $end;
            }
            $part = trim($day . ($time !== '' ? ' ' . $time : ''));
            if ($part !== '') {
                $meetingParts[] = $part;
            }

            $location = trim((string) ($slot['room'] ?? ''));
            if ($location === '') {
                continue;
            }
            if (portal_schedule_slot_is_online($location)) {
                $hasOnline = true;
                continue;
            }
            $hasPhysical = true;
            $places[$location] = $location;
        }

        $meeting = $meetingParts !== [] ? implode(', ', $meetingParts) : 'Schedule set';

        if ($hasOnline && $hasPhysical) {
            $room = 'Online / ' . implode(', ', array_values($places));
            $mode = 'Hybrid';
        } elseif ($hasOnline) {
            $room = 'Online';
            $mode = 'Online';
        } elseif ($places !== []) {
            $room = implode(', ', array_values($places));
            $mode = 'On campus';
        } else {
            // Slots exist but no location/link entered yet — still prefer this over seed placeholders.
            $room = 'Location TBA';
            $mode = 'TBA';
        }

        return [
            'meeting' => $meeting,
            'room' => $room,
            'mode' => $mode,
        ];
    }
}

if (!function_exists('portal_course_schedules_by_course_id')) {
    /**
     * @return array<int, list<array<string, mixed>>>
     */
    function portal_course_schedules_by_course_id(): array
    {
        $rows = portal_db()->query(
            "SELECT course_id, day_of_week, start_time, end_time, room, notes, sort_order, id
             FROM course_schedule
             ORDER BY sort_order ASC, id ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $byCourse = [];
        foreach ($rows as $row) {
            $cid = (int) ($row['course_id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $byCourse[$cid][] = $row;
        }

        return $byCourse;
    }
}

if (!function_exists('portal_sync_course_meeting_from_schedule')) {
    /**
     * Persist courses.meeting / courses.room from current calendar slots.
     *
     * @return array{meeting: string, room: string, mode: string}
     */
    function portal_sync_course_meeting_from_schedule(int $courseId): array
    {
        $stmt = portal_db()->prepare(
            "SELECT day_of_week, start_time, end_time, room, notes
             FROM course_schedule
             WHERE course_id = ?
             ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute([$courseId]);
        $slots = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $summary = portal_format_course_schedule_summary($slots);
        portal_db()->prepare(
            'UPDATE courses SET meeting = ?, room = ? WHERE id = ?'
        )->execute([$summary['meeting'], $summary['room'], $courseId]);

        return $summary;
    }
}

if (!function_exists('portal_course_weekday_names')) {
    /** @return list<string> */
    function portal_course_weekday_names(): array
    {
        return ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    }
}

if (!function_exists('portal_save_course_schedule_from_post')) {
    /**
     * Replace a course weekly timetable from admin POST fields and sync meeting/room.
     *
     * @param array<string, mixed> $post
     */
    function portal_save_course_schedule_from_post(int $courseId, array $post): void
    {
        if ($courseId <= 0) {
            return;
        }

        $days = portal_course_weekday_names();
        $ids = $post['schedule_id'] ?? [];
        $dayVals = $post['schedule_day'] ?? [];
        $starts = $post['schedule_start'] ?? [];
        $ends = $post['schedule_end'] ?? [];
        $rooms = $post['schedule_room'] ?? [];
        $notes = $post['schedule_notes'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        if (!is_array($dayVals)) {
            $dayVals = [];
        }
        if (!is_array($starts)) {
            $starts = [];
        }
        if (!is_array($ends)) {
            $ends = [];
        }
        if (!is_array($rooms)) {
            $rooms = [];
        }
        if (!is_array($notes)) {
            $notes = [];
        }

        $count = max(count($ids), count($dayVals), count($starts), count($ends), count($rooms), count($notes));
        $db = portal_db();
        $keepIds = [];
        $sort = 0;

        for ($i = 0; $i < $count; $i++) {
            $day = substr(trim((string) ($dayVals[$i] ?? '')), 0, 20);
            $start = substr(trim((string) ($starts[$i] ?? '')), 0, 10);
            $end = substr(trim((string) ($ends[$i] ?? '')), 0, 10);
            $room = substr(trim((string) ($rooms[$i] ?? '')), 0, 500);
            $note = substr(trim((string) ($notes[$i] ?? '')), 0, 300);
            $slotId = (int) ($ids[$i] ?? 0);

            if ($start === '' && $end === '' && $room === '' && $note === '') {
                continue;
            }
            if (!in_array($day, $days, true)) {
                continue;
            }

            $sort++;
            if ($slotId > 0) {
                $chk = $db->prepare('SELECT id FROM course_schedule WHERE id = ? AND course_id = ? LIMIT 1');
                $chk->execute([$slotId, $courseId]);
                if ($chk->fetchColumn()) {
                    $db->prepare(
                        'UPDATE course_schedule
                         SET day_of_week = ?, start_time = ?, end_time = ?, room = ?, notes = ?, sort_order = ?
                         WHERE id = ? AND course_id = ?'
                    )->execute([$day, $start, $end, $room, $note, $sort, $slotId, $courseId]);
                    $keepIds[] = $slotId;
                    continue;
                }
            }

            $db->prepare(
                'INSERT INTO course_schedule (course_id, day_of_week, start_time, end_time, room, notes, sort_order)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([$courseId, $day, $start, $end, $room, $note, $sort]);
            $keepIds[] = (int) $db->lastInsertId();
        }

        if ($keepIds === []) {
            $db->prepare('DELETE FROM course_schedule WHERE course_id = ?')->execute([$courseId]);
        } else {
            $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
            $params = array_merge([$courseId], $keepIds);
            $db->prepare(
                "DELETE FROM course_schedule WHERE course_id = ? AND id NOT IN ($placeholders)"
            )->execute($params);
        }

        portal_sync_course_meeting_from_schedule($courseId);
    }
}

if (!function_exists('portal_default_course_sections')) {
    function portal_default_course_sections(array $course): array
    {
        return [
            [
                'anchor' => 'course-content',
                'icon' => 'OV',
                'title' => 'About this course',
                'description' => 'Start here for the course guide, key dates, teacher contact details, and how ' . $course['title'] . ' will run this year.',
                'items' => ['Course guide', 'Key dates', 'Class expectations'],
                'progress_text' => '3 ready',
                'progress' => 100,
            ],
            [
                'anchor' => 'course-assessment',
                'icon' => 'AS',
                'title' => 'Assessment and submission',
                'description' => 'Use this section for coursework briefs, submission instructions, and the deadlines that matter most.',
                'items' => ['Assessment brief', 'Submission checklist', 'Marking rubric'],
                'progress_text' => '2 live now',
                'progress' => 72,
            ],
            [
                'anchor' => 'course-resources',
                'icon' => 'WK',
                'title' => 'Weekly materials',
                'description' => 'Lesson slides, reading packs, revision notes, and support resources will sit here in the same place each week.',
                'items' => ['Lesson slides', 'Revision notes', 'Practice task'],
                'progress_text' => 'Template active',
                'progress' => 84,
            ],
            [
                'anchor' => 'course-support',
                'icon' => 'SP',
                'title' => 'Support and questions',
                'description' => 'A shared support area for help after class, catch-up work, and questions students need answered quickly.',
                'items' => ['Catch-up tasks', 'Support links', 'Contact your teacher'],
                'progress_text' => 'Always available',
                'progress' => 100,
            ],
        ];
    }
}

if (!function_exists('portal_default_course_calendar_items')) {
    function portal_default_course_calendar_items(array $course): array
    {
        return [
            [
                'slot' => 'Next taught session',
                'time' => $course['meeting'],
                'title' => 'Scheduled lesson in ' . $course['room'],
                'description' => 'This course keeps its weekly teaching slot here so students can confirm where the next session is taking place.',
            ],
            [
                'slot' => 'Main reminder',
                'time' => 'This week',
                'title' => $course['notice'],
                'description' => 'Teachers can use this area for quick reminders linked to lessons, short tasks, and anything students need to remember before class.',
            ],
            [
                'slot' => 'Independent study',
                'time' => 'Before the next lesson',
                'title' => 'Review the latest notes and examples',
                'description' => 'Use a short review block before the next class so the lesson can focus on practice instead of recap.',
            ],
        ];
    }
}

if (!function_exists('portal_default_course_announcements')) {
    function portal_default_course_announcements(array $course): array
    {
        return [
            [
                'title' => 'Weekly course notice',
                'meta' => 'Posted today',
                'body' => $course['notice'],
            ],
            [
                'title' => 'Teaching team update',
                'meta' => 'This week',
                'body' => $course['updates'][0] ?? 'A short class update will appear here when the teacher posts one.',
            ],
            [
                'title' => 'Looking ahead',
                'meta' => 'Next lesson',
                'body' => $course['updates'][1] ?? 'The next reminder, planning note, or resource update will appear here.',
            ],
        ];
    }
}

if (!function_exists('portal_default_course_discussions')) {
    function portal_default_course_discussions(array $course): array
    {
        return [
            [
                'title' => 'Week one questions',
                'meta' => 'Open discussion',
                'body' => 'Use this thread for short questions about lesson content, examples, and anything that needs clarification before the next class.',
                'replies' => 4,
            ],
            [
                'title' => 'Independent study check-in',
                'meta' => 'This week',
                'body' => 'Students can share how they approached the latest practice task and compare methods before the next lesson.',
                'replies' => 2,
            ],
        ];
    }
}

if (!function_exists('portal_default_course_gradebook_items')) {
    function portal_default_course_gradebook_items(array $course): array
    {
        $isLive = $course['status'] === 'open';

        return [
            [
                'title' => 'Classwork portfolio',
                'result' => $isLive ? 'Pending' : '82%',
                'status' => $isLive ? 'Not graded yet' : 'Released',
                'notes' => 'Ongoing practical work and short in-class submissions will appear here once they are marked.',
            ],
            [
                'title' => 'Knowledge check',
                'result' => $isLive ? 'Upcoming' : '76%',
                'status' => $isLive ? 'Scheduled' : 'Released',
                'notes' => 'Quick checks and short quizzes can be tracked here so students always know what has been graded.',
            ],
            [
                'title' => 'Teacher feedback',
                'result' => $isLive ? 'In progress' : 'Complete',
                'status' => $isLive ? 'Being prepared' : 'Published',
                'notes' => 'Written feedback, improvement points, and review comments can stay together in the gradebook view.',
            ],
        ];
    }
}

if (!function_exists('portal_default_course_messages')) {
    function portal_default_course_messages(array $course): array
    {
        return [
            [
                'title' => 'Message from ' . $course['staff'][0]['name'],
                'meta' => 'Teacher inbox',
                'body' => 'If you need to ask something specific about the next lesson or a task, this is where the direct course messages will appear.',
            ],
            [
                'title' => 'Course reminders',
                'meta' => 'Shared with the class',
                'body' => 'Short one-to-one follow-ups, missed work reminders, and support messages can be listed here without changing the layout.',
            ],
        ];
    }
}

if (!function_exists('portal_default_course_groups')) {
    function portal_default_course_groups(array $course): array
    {
        return [
            [
                'title' => 'Project group A',
                'members' => ['A. Rahman', 'E. Lin', 'S. Noor', 'T. Clark'],
                'focus' => 'Planning, research, and first draft preparation for the current course task.',
            ],
            [
                'title' => 'Project group B',
                'members' => ['H. Ali', 'J. Morris', 'L. Chen', 'P. Singh'],
                'focus' => 'Collaborative work area for practical tasks, peer review, and shared preparation before presentations.',
            ],
        ];
    }
}

if (!function_exists('portal_default_course_assessments')) {
    function portal_default_course_assessments(array $course): array
    {
        $isLive = $course['status'] === 'open';

        return [
            [
                'title' => 'Main coursework task',
                'weight' => '40%',
                'due' => $isLive ? 'Next review point' : 'Closed',
                'status' => $isLive ? 'Open now' : 'Read only',
                'description' => 'The main assessment brief, success criteria, and submission instructions for ' . $course['title'] . ' will sit here.',
            ],
            [
                'title' => 'Knowledge check',
                'weight' => '20%',
                'due' => $isLive ? 'Later this term' : 'Completed',
                'status' => $isLive ? 'Upcoming' : 'Archived',
                'description' => 'Short quizzes, in-class checks, or timed tasks can be listed here so students know what is coming next.',
            ],
            [
                'title' => 'Reflection or review',
                'weight' => '10%',
                'due' => $isLive ? 'End of unit' : 'Completed',
                'status' => $isLive ? 'Planned' : 'Archived',
                'description' => 'This area can hold reflection logs, draft reviews, or follow-up checks once teachers start adding more items.',
            ],
        ];
    }
}

if (!function_exists('portal_default_course_resources')) {
    function portal_default_course_resources(array $course): array
    {
        return [
            [
                'title' => 'Start here',
                'status' => 'Always available',
                'items' => ['Course guide', 'Teacher contact details', 'Class expectations'],
            ],
            [
                'title' => 'Weekly materials',
                'status' => 'Updated as lessons run',
                'items' => ['Lesson slides', 'Reading pack', 'Practice activity'],
            ],
            [
                'title' => 'Assessment help',
                'status' => 'Shared before deadlines',
                'items' => ['Submission checklist', 'Marking guide', 'Revision notes'],
            ],
        ];
    }
}

if (!function_exists('portal_default_course_support_items')) {
    function portal_default_course_support_items(array $course): array
    {
        return [
            [
                'title' => 'Ask your teacher',
                'description' => 'Use this area for the questions students usually have once they review work again after class.',
                'action' => 'Bring your notes, screenshots, or draft questions to the next lesson.',
            ],
            [
                'title' => 'Catch up after an absence',
                'description' => 'Missed lesson support, catch-up tasks, and short summaries can stay in one predictable place for everyone.',
                'action' => 'Check the weekly materials first, then follow the latest class notice.',
            ],
            [
                'title' => 'Study support',
                'description' => 'Revision routines, independent study prompts, and support links can be collected here without changing the student view.',
                'action' => 'Use the timetable and course calendar together to plan your revision time.',
            ],
        ];
    }
}

if (!function_exists('portal_course_tab_definitions')) {
    function portal_course_tab_definitions(array $course, string $activeKey = 'content'): array
    {
        $definitions = [
            'content' => [
                'key' => 'content',
                'label' => 'Content',
                'description' => 'Start here for the default course structure, lesson blocks, and the main template students will use.',
            ],
            'calendar' => [
                'key' => 'calendar',
                'label' => 'Calendar',
                'description' => 'Key lesson times, reminders, and course-specific planning notes for this class.',
            ],
            'announcements' => [
                'key' => 'announcements',
                'label' => 'Announcements',
                'description' => 'Short class updates, reminders, and teacher notices appear here.',
                'badge' => count($course['announcements'] ?? []),
            ],
            'discussions' => [
                'key' => 'discussions',
                'label' => 'Discussions',
                'description' => 'Class threads, shared questions, and topic-based discussion prompts for this course.',
            ],
            'gradebook' => [
                'key' => 'gradebook',
                'label' => 'Grades',
                'description' => 'Marks, feedback, and released results for this course.',
            ],
            'groups' => [
                'key' => 'groups',
                'label' => 'Groups',
                'description' => 'Project teams, shared work areas, and small-group organisation for this course.',
            ],
        ];

        foreach ($definitions as $key => &$definition) {
            $definition['href'] = 'course.php?' . http_build_query([
                'course' => $course['slug'],
                'section' => $key,
            ]);
            $definition['active'] = $key === $activeKey;
        }

        unset($definition);

        return array_values($definitions);
    }
}

if (!function_exists('portal_course_catalog')) {
    function portal_course_catalog(): array
    {
        portal_course_apply_scheduled_opens();
        $pdo = portal_db();

        $rows = $pdo->query(
            "SELECT * FROM courses ORDER BY year_group DESC, title ASC"
        )->fetchAll();

        $staffRows = $pdo->query(
            "SELECT cs.name, cs.role, c.slug
             FROM course_staff cs
             JOIN courses c ON c.id = cs.course_id"
        )->fetchAll();

        $staffBySlug = [];
        foreach ($staffRows as $s) {
            $staffBySlug[$s['slug']][] = [
                'name'     => $s['name'],
                'role'     => $s['role'],
                'initials' => portal_staff_initials($s['name']),
            ];
        }

        $courses = [];
        $schedulesByCourse = portal_course_schedules_by_course_id();
        foreach ($rows as $row) {
            $course            = $row;
            $course['id']      = (int) $row['id'];
            $course['staff']   = $staffBySlug[$row['slug']] ?? [['name' => 'TBA', 'role' => 'Teacher', 'initials' => 'TB']];
            $course['staff_count'] = count($course['staff']);
            $course['updates'] = [
                'Check the latest class notice for upcoming tasks and reminders.',
                'Review weekly materials before the next lesson.',
            ];

            $summary = portal_format_course_schedule_summary($schedulesByCourse[$course['id']] ?? []);
            // Prefer live calendar slots over seed/placeholder meeting + room columns.
            $course['meeting'] = $summary['meeting'];
            $course['room'] = $summary['room'];
            $course['location_mode'] = $summary['mode'];
            $course['schedule_slots'] = $schedulesByCourse[$course['id']] ?? [];

            $course['sections']        = portal_default_course_sections($course);
            $course['calendar_items']  = portal_default_course_calendar_items($course);
            $course['announcements']   = portal_default_course_announcements($course);
            $course['discussions']     = portal_default_course_discussions($course);
            $course['gradebook_items'] = portal_default_course_gradebook_items($course);
            $course['messages']        = portal_default_course_messages($course);
            $course['groups']          = portal_default_course_groups($course);
            $course['assessments']     = portal_default_course_assessments($course);
            $course['resource_groups'] = portal_default_course_resources($course);
            $course['support_items']   = portal_default_course_support_items($course);

            $courses[] = $course;
        }

        return $courses;
    }
}

if (!function_exists('portal_user_course_catalog')) {
    function portal_user_course_catalog(int $user_id): array
    {
        $pdo  = portal_db();
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $role = $stmt->fetchColumn() ?: 'student';

        if (in_array($role, ['owner', 'admin'], true)) {
            return portal_course_catalog();
        }

        if ($role === 'teacher') {
            $ids = function_exists('portal_assigned_course_ids_for_user')
                ? portal_assigned_course_ids_for_user($user_id)
                : [];
            // Legacy safety: include any leftover enrollments until migration runs.
            $enrolled = $pdo->prepare(
                'SELECT course_id FROM enrollments WHERE user_id = ?'
            );
            $enrolled->execute([$user_id]);
            $ids = array_values(array_unique(array_merge(
                $ids,
                array_map('intval', $enrolled->fetchAll(PDO::FETCH_COLUMN))
            )));
            if ($ids === []) {
                return [];
            }
            return array_values(
                array_filter(portal_course_catalog(), fn($c) => in_array((int) $c['id'], $ids, true))
            );
        }

        $enrolled = $pdo->prepare(
            "SELECT course_id FROM enrollments WHERE user_id = ?"
        );
        $enrolled->execute([$user_id]);
        $ids = array_map('intval', $enrolled->fetchAll(PDO::FETCH_COLUMN));

        if (empty($ids)) {
            return [];
        }

        return array_values(array_filter(
            portal_course_catalog(),
            static function (array $c) use ($ids): bool {
                if (!in_array((int) $c['id'], $ids, true)) {
                    return false;
                }
                // Drafts stay staff-only even if a student was enrolled early.
                return (string) ($c['status'] ?? '') !== 'draft';
            }
        ));
    }
}

if (!function_exists('portal_course_catalog_LEGACY_UNUSED')) {
    function portal_course_catalog_LEGACY_UNUSED(): array
    {
        $courses = [
            [
                'slug' => 'computer-science-2526',
                'code' => 'CSC-2526-01',
                'year_group' => '25/26',
                'term' => 'Spring term',
                'status' => 'open',
                'status_label' => 'Open',
                'accent' => '#d74264',
                'title' => 'Computer Science',
                'full_title' => '25/26 - Computer Science',
                'summary' => 'Programming, interface design, and practical problem-solving in Lab 4.',
                'meeting' => 'Mon and Thu | 09:00',
                'room' => 'Lab 4',
                'student_count' => 28,
                'notice' => 'Prototype review due Monday morning.',
                'updates' => ['Interface prototype review moved to Monday.', 'Bring your flowchart print-out for Thursday.'],
                'staff' => [
                    ['name' => 'Mr D. Hart', 'role' => 'Lead teacher'],
                    ['name' => 'Ms R. Khan', 'role' => 'Support teacher'],
                ],
            ],
            [
                'slug' => 'mathematics-2526',
                'code' => 'MAT-2526-02',
                'year_group' => '25/26',
                'term' => 'Full year',
                'status' => 'open',
                'status_label' => 'Open',
                'accent' => '#7a5cff',
                'title' => 'Mathematics',
                'full_title' => '25/26 - Mathematics',
                'summary' => 'Core methods, algebra, and exam practice with weekly problem sets.',
                'meeting' => 'Mon and Wed | 11:15',
                'room' => 'Room 12',
                'student_count' => 30,
                'notice' => 'Past-paper workshop this Wednesday.',
                'updates' => ['Calculator check at the start of next lesson.', 'Homework set 6 opens tonight at 18:00.'],
                'staff' => [
                    ['name' => 'Mrs K. Lewis', 'role' => 'Teacher'],
                ],
            ],
            [
                'slug' => 'physics-2526',
                'code' => 'PHY-2526-03',
                'year_group' => '25/26',
                'term' => 'Spring term',
                'status' => 'open',
                'status_label' => 'Open',
                'accent' => '#10b2a8',
                'title' => 'Physics',
                'full_title' => '25/26 - Physics',
                'summary' => 'Wave behaviour, practical write-ups, and short retrieval tasks each week.',
                'meeting' => 'Tue and Thu | 10:30',
                'room' => 'Science 2',
                'student_count' => 24,
                'notice' => 'Refraction write-up due Thursday evening.',
                'updates' => ['Practical groups are posted in class.', 'Revision questions added after every lesson.'],
                'staff' => [
                    ['name' => 'Dr A. Ndlovu', 'role' => 'Teacher'],
                ],
            ],
            [
                'slug' => 'english-literature-2526',
                'code' => 'ENG-2526-04',
                'year_group' => '25/26',
                'term' => 'Spring term',
                'status' => 'open',
                'status_label' => 'Open',
                'accent' => '#e59722',
                'title' => 'English Literature',
                'full_title' => '25/26 - English Literature',
                'summary' => 'Essay writing, close reading, and comparison work for the end-of-term assessment.',
                'meeting' => 'Wed | 08:45',
                'room' => 'Library wing',
                'student_count' => 26,
                'notice' => 'Essay draft feedback returns on Wednesday.',
                'updates' => ['Bring annotated texts to the next lesson.', 'Essay planning sheet uploaded this week.'],
                'staff' => [
                    ['name' => 'Ms J. Clarke', 'role' => 'Teacher'],
                ],
            ],
            [
                'slug' => 'media-technology-2526',
                'code' => 'MED-2526-05',
                'year_group' => '25/26',
                'term' => 'Spring term',
                'status' => 'open',
                'status_label' => 'Open',
                'accent' => '#b85cf0',
                'title' => 'Media Technology',
                'full_title' => '25/26 - Media Technology',
                'summary' => 'Creative production, editing workflows, and digital storytelling projects.',
                'meeting' => 'Fri | 09:00',
                'room' => 'Studio 1',
                'student_count' => 21,
                'notice' => 'Storyboard draft check on Friday.',
                'updates' => ['Editing lab remains open after school on Friday.', 'Reference examples posted in weekly materials.'],
                'staff' => [
                    ['name' => 'Mr S. Ajit', 'role' => 'Teacher'],
                    ['name' => 'Mr A. Basil', 'role' => 'Technician'],
                ],
            ],
            [
                'slug' => 'web-programming-2425',
                'code' => 'WEB-2425-01',
                'year_group' => '24/25',
                'term' => 'Completed',
                'status' => 'completed',
                'status_label' => 'Completed',
                'accent' => '#30b7f0',
                'title' => 'Web Programming',
                'full_title' => '24/25 - Web Programming',
                'summary' => 'HTML, CSS, and PHP fundamentals from last year\'s core build project.',
                'meeting' => 'Archived course',
                'room' => 'Lab archive',
                'student_count' => 0,
                'notice' => 'Reference only.',
                'updates' => ['Final project files remain available for revision.', 'Marks were released last summer.'],
                'staff' => [
                    ['name' => 'Mr D. Hart', 'role' => 'Teacher'],
                ],
            ],
            [
                'slug' => 'data-structures-2425',
                'code' => 'DSA-2425-02',
                'year_group' => '24/25',
                'term' => 'Completed',
                'status' => 'completed',
                'status_label' => 'Completed',
                'accent' => '#4cc9a6',
                'title' => 'Data Structures',
                'full_title' => '24/25 - Data Structures',
                'summary' => 'Lists, trees, algorithms, and the group challenge from the previous year.',
                'meeting' => 'Archived course',
                'room' => 'Archive',
                'student_count' => 0,
                'notice' => 'Available for recap and revision.',
                'updates' => ['Revision problems still open.', 'Past solutions remain read-only.'],
                'staff' => [
                    ['name' => 'Ms R. Khan', 'role' => 'Teacher'],
                ],
            ],
            [
                'slug' => 'operating-systems-2425',
                'code' => 'OPS-2425-03',
                'year_group' => '24/25',
                'term' => 'Completed',
                'status' => 'completed',
                'status_label' => 'Completed',
                'accent' => '#f05c7a',
                'title' => 'Operating Systems',
                'full_title' => '24/25 - Operating Systems',
                'summary' => 'Processes, memory, and system behaviour from the autumn archive.',
                'meeting' => 'Archived course',
                'room' => 'Archive',
                'student_count' => 0,
                'notice' => 'Archived after final assessment.',
                'updates' => ['Lecture notes are still available.', 'Assessment solutions remain view-only.'],
                'staff' => [
                    ['name' => 'Dr A. Ndlovu', 'role' => 'Teacher'],
                ],
            ],
            [
                'slug' => 'computer-systems-2324',
                'code' => 'SYS-2324-01',
                'year_group' => '23/24',
                'term' => 'Archived',
                'status' => 'archived',
                'status_label' => 'Archived',
                'accent' => '#2ec5d3',
                'title' => 'Computer Systems',
                'full_title' => '23/24 - Computer Systems',
                'summary' => 'An older course space kept for revision and reference only.',
                'meeting' => 'Archive only',
                'room' => 'Archive',
                'student_count' => 0,
                'notice' => 'Older materials only.',
                'updates' => ['This course is locked for editing.', 'Reference resources still available.'],
                'staff' => [
                    ['name' => 'Mrs K. Lewis', 'role' => 'Former teacher'],
                ],
            ],
            [
                'slug' => 'problem-solving-2324',
                'code' => 'PSP-2324-02',
                'year_group' => '23/24',
                'term' => 'Archived',
                'status' => 'archived',
                'status_label' => 'Archived',
                'accent' => '#ff7a1a',
                'title' => 'Problem Solving and Programming',
                'full_title' => '23/24 - Problem Solving and Programming',
                'summary' => 'Foundations from an earlier year, kept so revision examples stay easy to find.',
                'meeting' => 'Archive only',
                'room' => 'Archive',
                'student_count' => 0,
                'notice' => 'Archive course.',
                'updates' => ['Starter tasks remain available.', 'Folder editing is disabled in archive spaces.'],
                'staff' => [
                    ['name' => 'Mr D. Hart', 'role' => 'Former teacher'],
                ],
            ],
        ];

        foreach ($courses as &$course) {
            $course['sections'] = portal_default_course_sections($course);
            $course['calendar_items'] = portal_default_course_calendar_items($course);
            $course['announcements'] = portal_default_course_announcements($course);
            $course['discussions'] = portal_default_course_discussions($course);
            $course['gradebook_items'] = portal_default_course_gradebook_items($course);
            $course['messages'] = portal_default_course_messages($course);
            $course['groups'] = portal_default_course_groups($course);
            $course['assessments'] = portal_default_course_assessments($course);
            $course['resource_groups'] = portal_default_course_resources($course);
            $course['support_items'] = portal_default_course_support_items($course);
            $course['staff_count'] = count($course['staff']);

            foreach ($course['staff'] as &$member) {
                $member['initials'] = portal_staff_initials($member['name']);
            }

            unset($member);
        }

        unset($course);

        return $courses;
    }
}

if (!function_exists('portal_find_course')) {
    function portal_find_course(string $slug): ?array
    {
        foreach (portal_course_catalog() as $course) {
            if ($course['slug'] === $slug) {
                return $course;
            }
        }

        return null;
    }
}

if (!function_exists('portal_course_year_options')) {
    function portal_course_year_options(array $courses): array
    {
        $options = [];

        foreach ($courses as $course) {
            if (!in_array($course['year_group'], $options, true)) {
                $options[] = $course['year_group'];
            }
        }

        return $options;
    }
}

if (!function_exists('portal_group_courses_by_year')) {
    function portal_group_courses_by_year(array $courses): array
    {
        $grouped = [];

        foreach ($courses as $course) {
            $grouped[$course['year_group']][] = $course;
        }

        return $grouped;
    }
}

if (!function_exists('portal_valid_course_slug')) {
    function portal_valid_course_slug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug);
    }
}

if (!function_exists('portal_academic_year_current')) {
    function portal_academic_year_current(?int $timestamp = null): string
    {
        $ts = $timestamp ?? time();
        $year = (int) date('Y', $ts);
        $month = (int) date('n', $ts);
        // Academic year starts in September.
        $start = $month >= 9 ? $year : $year - 1;
        $end = $start + 1;

        return sprintf('%02d/%02d', $start % 100, $end % 100);
    }
}

if (!function_exists('portal_valid_academic_year')) {
    function portal_valid_academic_year(string $year): bool
    {
        if (!preg_match('/^(\d{2})\/(\d{2})$/', $year, $m)) {
            return false;
        }
        $start = (int) $m[1];
        $end = (int) $m[2];

        return $end === (($start + 1) % 100);
    }
}

if (!function_exists('portal_academic_year_compact')) {
    function portal_academic_year_compact(string $year): string
    {
        return portal_valid_academic_year($year) ? str_replace('/', '', $year) : '';
    }
}

if (!function_exists('portal_academic_year_options')) {
    /**
     * Current academic year plus a short window of upcoming years.
     *
     * @return list<string>
     */
    function portal_academic_year_options(): array
    {
        $current = portal_academic_year_current();
        [$startStr] = explode('/', $current);
        $start = (int) $startStr;
        $options = [];
        for ($i = 0; $i <= 2; $i++) {
            $from = ($start + $i) % 100;
            $to = ($from + 1) % 100;
            $options[] = sprintf('%02d/%02d', $from, $to);
        }

        return $options;
    }
}

if (!function_exists('portal_academic_year_allowed')) {
    function portal_academic_year_allowed(string $year, string $existing = ''): bool
    {
        if (!portal_valid_academic_year($year)) {
            return false;
        }
        if ($existing !== '' && $year === $existing) {
            return true;
        }

        return in_array($year, portal_academic_year_options(), true);
    }
}

if (!function_exists('portal_course_code_prefix_map')) {
    /** @return array<string, string> */
    function portal_course_code_prefix_map(): array
    {
        return [
            'english as a second language' => 'ESL',
            'english as second language' => 'ESL',
            'english language' => 'ENG',
            'english literature' => 'ENG',
            'computer science' => 'CS',
            'physical education' => 'PE',
            'extended mathematics' => 'MTH',
            'additional mathematics' => 'MTH',
            'further mathematics' => 'MTH',
            'biology' => 'BIO',
            'chemistry' => 'CHM',
            'physics' => 'PHY',
            'mathematics' => 'MTH',
            'maths' => 'MTH',
            'math' => 'MTH',
            'english' => 'ENG',
            'esl' => 'ESL',
            'ict' => 'ICT',
            'computing' => 'CS',
            'business' => 'BUS',
            'accounting' => 'ACC',
            'tourism' => 'TOU',
            'economics' => 'ECO',
            'history' => 'HIS',
            'geography' => 'GEO',
            'french' => 'FRE',
            'spanish' => 'SPA',
            'art' => 'ART',
            'music' => 'MUS',
        ];
    }
}

if (!function_exists('portal_course_title_prefix')) {
    function portal_course_title_prefix(string $title): string
    {
        $normalized = strtolower(trim((string) preg_replace('/\s+/', ' ', $title)));
        if ($normalized === '') {
            return 'CRS';
        }

        foreach (portal_course_code_prefix_map() as $name => $prefix) {
            if ($normalized === $name || str_starts_with($normalized, $name . ' ')) {
                return $prefix;
            }
        }

        $stop = ['a', 'an', 'the', 'of', 'and', 'as', 'for', 'to', 'in', 'with'];
        $words = [];
        foreach (explode(' ', $normalized) as $word) {
            $word = (string) preg_replace('/[^a-z0-9]/', '', $word);
            if ($word === '' || in_array($word, $stop, true)) {
                continue;
            }
            $words[] = $word;
        }
        if ($words === []) {
            return 'CRS';
        }
        if (count($words) >= 2) {
            $initials = '';
            foreach (array_slice($words, 0, 4) as $word) {
                $initials .= strtoupper($word[0]);
            }

            return $initials;
        }

        $word = $words[0];
        if (strlen($word) <= 4) {
            return strtoupper($word);
        }

        return strtoupper(substr($word, 0, 3));
    }
}

if (!function_exists('portal_generate_course_code')) {
    function portal_generate_course_code(string $title, string $year, ?int $exceptId = null): string
    {
        $prefix = portal_course_title_prefix($title);
        $compact = portal_academic_year_compact($year);
        if ($compact === '') {
            $compact = portal_academic_year_compact(portal_academic_year_current());
        }
        $stem = $prefix . '-' . $compact;
        $max = 0;
        $sql = 'SELECT code FROM courses WHERE code LIKE ?';
        $params = [$stem . '-%'];
        if ($exceptId !== null && $exceptId > 0) {
            $sql .= ' AND id != ?';
            $params[] = $exceptId;
        }
        $stmt = portal_db()->prepare($sql);
        $stmt->execute($params);
        $pattern = '/^' . preg_quote($stem, '/') . '-(\d+)/';
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
            if (preg_match($pattern, (string) $code, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return sprintf('%s-%02d', $stem, $max + 1);
    }
}

if (!function_exists('portal_assign_course_code')) {
    function portal_assign_course_code(string $title, string $year, string $existing = '', ?int $exceptId = null): string
    {
        $prefix = portal_course_title_prefix($title);
        $compact = portal_academic_year_compact($year);
        if ($compact === '') {
            return portal_generate_course_code($title, portal_academic_year_current(), $exceptId);
        }
        $stem = $prefix . '-' . $compact . '-';
        if ($existing !== '' && str_starts_with($existing, $stem)) {
            return $existing;
        }

        return portal_generate_course_code($title, $year, $exceptId);
    }
}

if (!function_exists('portal_slugify_course_title')) {
    function portal_slugify_course_title(string $title): string
    {
        $slug = strtolower($title);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'course';
    }
}

if (!function_exists('portal_generate_course_slug')) {
    function portal_generate_course_slug(string $title, string $year, ?int $exceptId = null): string
    {
        $compact = portal_academic_year_compact($year);
        if ($compact === '') {
            $compact = portal_academic_year_compact(portal_academic_year_current());
        }
        $base = portal_slugify_course_title($title) . '-' . $compact;
        $slug = $base;
        $n = 2;
        while (portal_course_slug_taken($slug, $exceptId) && $n < 100) {
            $slug = $base . '-' . $n;
            $n++;
        }

        return $slug;
    }
}

if (!function_exists('portal_resolve_course_slug')) {
    function portal_resolve_course_slug(string $posted, string $title, string $year, ?int $exceptId = null): string
    {
        $posted = strtolower(trim($posted));
        if ($posted !== '' && portal_valid_course_slug($posted) && !portal_course_slug_taken($posted, $exceptId)) {
            return $posted;
        }

        return portal_generate_course_slug($title, $year, $exceptId);
    }
}

if (!function_exists('portal_course_full_title')) {
    function portal_course_full_title(string $title, string $year): string
    {
        $title = trim($title);
        $year = trim($year);
        if ($title === '') {
            return $year;
        }
        if ($year === '' || !portal_valid_academic_year($year)) {
            return $title;
        }

        return $year . ' — ' . $title;
    }
}

if (!function_exists('portal_valid_course_accent')) {
    function portal_valid_course_accent(string $accent): bool
    {
        return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $accent);
    }
}

if (!function_exists('portal_course_status_keys')) {
    /** @return list<string> */
    function portal_course_status_keys(): array
    {
        return ['draft', 'closed', 'open', 'archived'];
    }
}

if (!function_exists('portal_course_status_label')) {
    function portal_course_status_label(string $status): string
    {
        return match ($status) {
            'open'     => 'Open',
            'closed'   => 'Closed',
            'draft'    => 'Draft',
            'archived' => 'Archived',
            default    => ucfirst($status),
        };
    }
}

if (!function_exists('portal_course_status_badge_class')) {
    function portal_course_status_badge_class(string $status): string
    {
        return in_array($status, portal_course_status_keys(), true)
            ? 'admin-badge--' . $status
            : 'admin-badge--draft';
    }
}

if (!function_exists('portal_course_normalize_status')) {
    function portal_course_normalize_status(string $status): string
    {
        return in_array($status, portal_course_status_keys(), true) ? $status : 'draft';
    }
}

if (!function_exists('portal_course_normalize_opens_at')) {
    function portal_course_normalize_opens_at(string $raw): string
    {
        $raw = trim(str_replace('T', ' ', $raw));
        if ($raw === '') {
            return '';
        }
        $ts = strtotime($raw);

        return $ts ? date('Y-m-d H:i:s', $ts) : '';
    }
}

if (!function_exists('portal_course_opens_at_input_value')) {
    function portal_course_opens_at_input_value(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $ts = strtotime($raw);

        return $ts ? date('Y-m-d\TH:i', $ts) : '';
    }
}

if (!function_exists('portal_course_opens_label')) {
    function portal_course_opens_label(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $ts = strtotime($raw);

        return $ts ? date('j M Y H:i', $ts) : '';
    }
}

if (!function_exists('portal_course_apply_scheduled_opens')) {
    function portal_course_apply_scheduled_opens(): void
    {
        try {
            portal_db()->exec(
                "UPDATE courses
                 SET status = 'open', status_label = 'Open'
                 WHERE status = 'closed'
                   AND opens_at IS NOT NULL
                   AND trim(opens_at) != ''
                   AND datetime(opens_at) <= datetime('now')"
            );
            portal_db()->exec(
                "UPDATE courses
                 SET status = 'archived', status_label = 'Archived'
                 WHERE status = 'open'
                   AND archives_at IS NOT NULL
                   AND trim(archives_at) != ''
                   AND datetime(archives_at) <= datetime('now')"
            );
        } catch (\Throwable) {
            // Columns may not exist yet during very early bootstrap.
        }
    }
}

if (!function_exists('portal_course_status_pill_class')) {
    function portal_course_status_pill_class(string $status): string
    {
        return match ($status) {
            'open'     => ' active',
            'closed'   => ' is-closed',
            'draft'    => ' is-draft',
            'archived' => ' is-archived',
            default    => '',
        };
    }
}

if (!function_exists('portal_course_student_may_enter')) {
    function portal_course_student_may_enter(array $course): bool
    {
        $status = (string) ($course['status'] ?? '');

        return in_array($status, ['open', 'archived'], true);
    }
}

if (!function_exists('portal_find_course_by_id')) {
    function portal_find_course_by_id(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        portal_course_apply_scheduled_opens();
        $stmt = portal_db()->prepare('SELECT * FROM courses WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}

if (!function_exists('portal_course_slug_taken')) {
    function portal_course_slug_taken(string $slug, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM courses WHERE slug = ?';
        $params = [$slug];
        if ($exceptId !== null && $exceptId > 0) {
            $sql .= ' AND id != ?';
            $params[] = $exceptId;
        }

        $stmt = portal_db()->prepare($sql);
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists('portal_course_code_taken')) {
    function portal_course_code_taken(string $code, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM courses WHERE code = ?';
        $params = [$code];
        if ($exceptId !== null && $exceptId > 0) {
            $sql .= ' AND id != ?';
            $params[] = $exceptId;
        }

        $stmt = portal_db()->prepare($sql);
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists('portal_admin_course_rows')) {
    /**
     * @return list<array<string, mixed>>
     */
    function portal_admin_course_rows(): array
    {
        portal_course_apply_scheduled_opens();
        $pdo = portal_db();

        $rows = $pdo->query(
            'SELECT * FROM courses ORDER BY year_group DESC, title ASC'
        )->fetchAll();

        $enrollCounts = [];
        foreach ($pdo->query('SELECT course_id, COUNT(*) AS cnt FROM enrollments GROUP BY course_id') as $er) {
            $enrollCounts[(int) $er['course_id']] = (int) $er['cnt'];
        }

        $staffCounts = [];
        foreach ($pdo->query('SELECT course_id, COUNT(*) AS cnt FROM course_teachers GROUP BY course_id') as $sr) {
            $staffCounts[(int) $sr['course_id']] = (int) $sr['cnt'];
        }

        $schedules = portal_course_schedules_by_course_id();

        $courses = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $row['id'] = $id;
            $row['enrollment_count'] = $enrollCounts[$id] ?? 0;
            $row['assigned_staff_count'] = $staffCounts[$id] ?? 0;
            $summary = portal_format_course_schedule_summary($schedules[$id] ?? []);
            $row['meeting'] = $summary['meeting'];
            $row['room'] = $summary['room'];
            $courses[] = $row;
        }

        return $courses;
    }
}

if (!function_exists('portal_course_deletion_blockers')) {
    /**
     * @return list<string>
     */
    function portal_course_deletion_blockers(int $courseId): array
    {
        if ($courseId <= 0) {
            return ['Invalid course.'];
        }

        $pdo = portal_db();
        $blockers = [];

        $checks = [
            ['enrollments', 'SELECT COUNT(*) FROM enrollments WHERE course_id = ?', 'student enrolments'],
            ['submissions', 'SELECT COUNT(*) FROM course_submissions WHERE course_id = ?', 'submissions'],
            ['discussions', 'SELECT COUNT(*) FROM course_discussion_topics WHERE course_id = ?', 'discussion topics'],
            ['files', "SELECT COUNT(*) FROM course_folder_items WHERE course_id = ? AND file_path != ''", 'uploaded files'],
            ['grades', 'SELECT COUNT(*) FROM course_submissions WHERE course_id = ? AND score IS NOT NULL', 'graded submissions'],
        ];

        foreach ($checks as [$key, $sql, $label]) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$courseId]);
            if ((int) $stmt->fetchColumn() > 0) {
                $blockers[] = $label;
            }
        }

        return $blockers;
    }
}

if (!function_exists('portal_user_enrollment_counts')) {
    /**
     * Course-access counts for the admin users table: student enrolments plus
     * teacher course assignments.
     *
     * @return array<int, int>
     */
    function portal_user_enrollment_counts(): array
    {
        $counts = [];
        $db = portal_db();
        foreach ($db->query('SELECT user_id, COUNT(*) AS cnt FROM enrollments GROUP BY user_id') as $row) {
            $counts[(int) $row['user_id']] = (int) $row['cnt'];
        }
        foreach ($db->query('SELECT user_id, COUNT(*) AS cnt FROM course_teachers GROUP BY user_id') as $row) {
            $uid = (int) $row['user_id'];
            $counts[$uid] = max($counts[$uid] ?? 0, (int) $row['cnt']);
        }

        return $counts;
    }
}
