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
                'label' => 'Gradebook',
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
        foreach ($rows as $row) {
            $course            = $row;
            $course['id']      = (int) $row['id'];
            $course['staff']   = $staffBySlug[$row['slug']] ?? [['name' => 'TBA', 'role' => 'Teacher', 'initials' => 'TB']];
            $course['staff_count'] = count($course['staff']);
            $course['updates'] = [
                'Check the latest class notice for upcoming tasks and reminders.',
                'Review weekly materials before the next lesson.',
            ];

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

        if (in_array($role, ['teacher', 'supervisor'], true)) {
            $assigned = $pdo->prepare(
                "SELECT course_id FROM course_teachers WHERE user_id = ?"
            );
            $assigned->execute([$user_id]);
            $ids = array_map('intval', $assigned->fetchAll(PDO::FETCH_COLUMN));
            if (empty($ids)) {
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

        return array_values(
            array_filter(portal_course_catalog(), fn($c) => in_array((int) $c['id'], $ids, true))
        );
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

if (!function_exists('portal_valid_course_accent')) {
    function portal_valid_course_accent(string $accent): bool
    {
        return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $accent);
    }
}

if (!function_exists('portal_course_status_label')) {
    function portal_course_status_label(string $status): string
    {
        return match ($status) {
            'open'     => 'Open',
            'draft'    => 'Draft',
            'archived' => 'Archived',
            default    => ucfirst($status),
        };
    }
}

if (!function_exists('portal_find_course_by_id')) {
    function portal_find_course_by_id(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

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

        $courses = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $row['id'] = $id;
            $row['enrollment_count'] = $enrollCounts[$id] ?? 0;
            $row['assigned_staff_count'] = $staffCounts[$id] ?? 0;
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
     * @return array<int, int>
     */
    function portal_user_enrollment_counts(): array
    {
        $counts = [];
        foreach (portal_db()->query('SELECT user_id, COUNT(*) AS cnt FROM enrollments GROUP BY user_id') as $row) {
            $counts[(int) $row['user_id']] = (int) $row['cnt'];
        }

        return $counts;
    }
}
