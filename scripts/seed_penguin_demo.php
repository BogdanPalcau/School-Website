<?php
declare(strict_types=1);

/**
 * Idempotent local demo seed for the existing Accounting course.
 * Adds a penguin-themed folder (video, links, flashcards, practice, quiz),
 * plus announcement + discussion. Removes the leftover penguins-demo course.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/seed_penguin_demo.php
 */

require_once __DIR__ . '/../bootstrap.php';

const TARGET_COURSE_SLUG = 'accounting-2526';
const DEMO_FOLDER_TITLE = 'Demo — Penguins unit';
const DEMO_ANNOUNCE_TITLE = 'Demo — Welcome to the Penguins unit';
const DEMO_TOPIC_TITLE = 'Demo — Surprising penguin facts';
const LEGACY_DEMO_SLUG = 'penguins-demo';
const PENGUIN_VIDEO = 'https://www.youtube.com/watch?v=O8qilxaBR20';

function penguin_fail(string $msg): never
{
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit(1);
}

function penguin_ok(string $msg): void
{
    echo $msg . "\n";
}

function penguin_ensure_student(PDO $db): array
{
    $existing = portal_find_user('bogdanstudent');
    if ($existing !== null) {
        return $existing;
    }

    $hash = password_hash('bogdanstudent', PASSWORD_DEFAULT);
    $cols = $db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC);
    $hasStatus = false;
    foreach ($cols as $c) {
        if (($c['name'] ?? '') === 'account_status') {
            $hasStatus = true;
            break;
        }
    }

    if ($hasStatus) {
        $db->prepare(
            'INSERT INTO users (username, email, password_hash, name, year, programme, initials, role, account_status)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            'bogdanstudent', 'bogdanstudent@rieo.edu', $hash,
            'Bogdan Student', 'Year 11', 'STEM pathway', 'BS', 'student', 'active',
        ]);
    } else {
        $db->prepare(
            'INSERT INTO users (username, email, password_hash, name, year, programme, initials, role)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([
            'bogdanstudent', 'bogdanstudent@rieo.edu', $hash,
            'Bogdan Student', 'Year 11', 'STEM pathway', 'BS', 'student',
        ]);
    }

    $user = portal_find_user('bogdanstudent');
    if ($user === null) {
        penguin_fail('Could not create bogdanstudent.');
    }
    penguin_ok('Created user bogdanstudent / bogdanstudent');
    return $user;
}

function penguin_login_as(array $user): void
{
    $_SESSION['portal_user'] = [
        'id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'email' => (string) $user['email'],
        'name' => (string) $user['name'],
        'year' => (string) ($user['year'] ?? 'Year 11'),
        'programme' => (string) ($user['programme'] ?? 'General'),
        'initials' => (string) ($user['initials'] ?? 'BG'),
        'role' => (string) $user['role'],
        'account_status' => portal_user_account_status($user),
    ];
    $_SESSION['portal_login_at'] = gmdate('Y-m-d H:i:s');
}

function penguin_purge_demo_folder(PDO $db, int $courseId): void
{
    $stmt = $db->prepare('SELECT id FROM course_folders WHERE course_id = ? AND title = ?');
    $stmt->execute([$courseId, DEMO_FOLDER_TITLE]);
    $folderIds = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($folderIds as $folderId) {
        $folderId = (int) $folderId;
        // Activities cascade from course_folder_items.
        $db->prepare('DELETE FROM course_folder_items WHERE folder_id = ?')->execute([$folderId]);
        $db->prepare('DELETE FROM course_folders WHERE id = ?')->execute([$folderId]);
        penguin_ok('Removed previous demo folder #' . $folderId);
    }

    $db->prepare('DELETE FROM course_announcements WHERE course_id = ? AND title = ?')
        ->execute([$courseId, DEMO_ANNOUNCE_TITLE]);
    $db->prepare('DELETE FROM course_discussion_topics WHERE course_id = ? AND title = ?')
        ->execute([$courseId, DEMO_TOPIC_TITLE]);
}

function penguin_publish_or_fail(int $activityId, string $label): void
{
    $pub = portal_activity_publish($activityId);
    if (!empty($pub['ok'])) {
        penguin_ok('Published ' . $label . ' activity #' . $activityId);
        return;
    }
    $errs = $pub['validation']['errors'] ?? [];
    $detail = (string) ($pub['error'] ?? '');
    if ($detail === '' && is_array($errs) && $errs !== []) {
        $detail = implode('; ', $errs);
    }
    penguin_fail($label . ' publish failed: ' . ($detail !== '' ? $detail : 'unknown'));
}

$db = portal_db();

$owner = portal_find_user('bogdan');
if ($owner === null) {
    penguin_fail('Owner user "bogdan" not found. Initialise the portal DB first.');
}
$student = penguin_ensure_student($db);
$ownerId = (int) $owner['id'];
$studentId = (int) $student['id'];
penguin_login_as($owner);

// Remove leftover standalone demo course so it cannot be discovered.
$legacy = $db->prepare('SELECT id FROM courses WHERE slug = ?');
$legacy->execute([LEGACY_DEMO_SLUG]);
$legacyId = (int) ($legacy->fetchColumn() ?: 0);
if ($legacyId > 0) {
    $db->prepare('DELETE FROM courses WHERE id = ?')->execute([$legacyId]);
    penguin_ok('Deleted leftover course #' . $legacyId . ' (' . LEGACY_DEMO_SLUG . ')');
}

$courseStmt = $db->prepare('SELECT id, slug, title FROM courses WHERE slug = ?');
$courseStmt->execute([TARGET_COURSE_SLUG]);
$course = $courseStmt->fetch(PDO::FETCH_ASSOC);
if (!$course) {
    penguin_fail('Target course not found: ' . TARGET_COURSE_SLUG);
}
$courseId = (int) $course['id'];
penguin_ok('Seeding into #' . $courseId . ' ' . $course['title'] . ' (' . $course['slug'] . ')');

penguin_purge_demo_folder($db, $courseId);

$db->prepare('INSERT OR IGNORE INTO enrollments (user_id, course_id) VALUES (?,?), (?,?)')
    ->execute([$ownerId, $courseId, $studentId, $courseId]);

$videoMeta = portal_parse_external_video_url(PENGUIN_VIDEO);
if ($videoMeta === null) {
    penguin_fail('Penguin video URL failed validation: ' . PENGUIN_VIDEO);
}
$videoUrl = (string) ($videoMeta['watch_url'] ?? PENGUIN_VIDEO);

$maxSort = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM course_folders WHERE course_id = ?');
$maxSort->execute([$courseId]);
$nextSort = (int) $maxSort->fetchColumn() + 1;

$db->prepare(
    "INSERT INTO course_folders (course_id, title, description, sort_order)
     VALUES (?,?,?,?)"
)->execute([
    $courseId,
    DEMO_FOLDER_TITLE,
    'Local test content: penguin video, flashcards, practice, and quiz.',
    $nextSort,
]);
$folderId = (int) $db->lastInsertId();

$db->prepare(
    "INSERT INTO course_folder_items
        (folder_id, course_id, type, title, description, url, sort_order)
     VALUES (?,?,?,?,?,?,?)"
)->execute([
    $folderId, $courseId, 'video',
    'All about penguins',
    'Short educational overview of penguins — watch before flashcards and quiz.',
    $videoUrl, 1,
]);

$db->prepare(
    "INSERT INTO course_folder_items
        (folder_id, course_id, type, title, description, url, sort_order)
     VALUES (?,?,?,?,?,?,?)"
)->execute([
    $folderId, $courseId, 'link',
    'BBC — Penguins overview',
    'Extra reading about penguin species and habitats.',
    'https://www.bbc.co.uk/bitesize', 2,
]);

$db->prepare(
    "INSERT INTO course_folder_items
        (folder_id, course_id, type, title, description, url, sort_order)
     VALUES (?,?,?,?,?,?,?)"
)->execute([
    $folderId, $courseId, 'link',
    'WWF — Why penguins matter',
    'Conservation context for class discussion.',
    'https://www.worldwildlife.org/species/penguin', 3,
]);

$db->prepare(
    "INSERT INTO course_announcements (course_id, user_id, title, body)
     VALUES (?,?,?,?)"
)->execute([
    $courseId,
    $ownerId,
    DEMO_ANNOUNCE_TITLE,
    "Demo content for portal testing.\n\n1) Watch the penguin video in “Demo — Penguins unit”.\n2) Complete the flashcards.\n3) Try practice, then the quiz.\n\nReply in the demo discussion with one penguin fact.",
]);

$db->prepare(
    "INSERT INTO course_discussion_topics (course_id, user_id, title, body)
     VALUES (?,?,?,?)"
)->execute([
    $courseId,
    $ownerId,
    DEMO_TOPIC_TITLE,
    'Share one fact from the video or reading that surprised you.',
]);
$topicId = (int) $db->lastInsertId();

$db->prepare(
    "INSERT INTO course_discussion_replies (topic_id, course_id, user_id, body)
     VALUES (?,?,?,?)"
)->execute([
    $topicId,
    $courseId,
    $studentId,
    'I did not know emperor penguins can dive hundreds of metres for food. Their black-and-white colour also helps camouflage in the water.',
]);

// Flashcards
$fc = portal_activity_create($courseId, $folderId, 'Penguin flashcards', 'flashcard', $ownerId);
if (empty($fc['ok'])) {
    penguin_fail('Flashcard create failed: ' . ($fc['error'] ?? 'unknown'));
}
$fcId = (int) $fc['activity_id'];
foreach ([
    ['What is a group of penguins called on land?', 'A rookery (or colony).'],
    ['Which continent is home to emperor penguins?', 'Antarctica.'],
    ['Why are many penguins black and white?', 'Countershading camouflage — dark from above, light from below in the water.'],
    ['Can penguins fly?', 'No. Their wings are adapted for swimming, not flight.'],
    ['What do penguins mainly eat?', 'Fish, krill, and other small sea animals.'],
] as $card) {
    $added = portal_activity_add_question($fcId, 'flashcard', $card[0], null, [
        'settings' => ['back' => $card[1]],
        'points' => 1,
    ]);
    if (empty($added['ok'])) {
        penguin_fail('Flashcard card failed: ' . ($added['error'] ?? 'unknown'));
    }
}
penguin_publish_or_fail($fcId, 'flashcards');

// Practice
$pr = portal_activity_create($courseId, $folderId, 'Penguin practice check', 'practice', $ownerId);
if (empty($pr['ok'])) {
    penguin_fail('Practice create failed: ' . ($pr['error'] ?? 'unknown'));
}
$prId = (int) $pr['activity_id'];
portal_activity_save_settings($prId, [
    'max_attempts' => 0,
    'feedback_policy' => 'after_each',
    'xp_enabled' => 1,
    'xp_amount' => 15,
    'leaderboard_enabled' => 1,
], (int) (portal_activity_find($prId)['version'] ?? 1));

if (empty(portal_activity_add_question($prId, 'true_false', '<p>Penguins are birds.</p>', null, [
    'points' => 1,
    'explanation_html' => '<p>Yes — flightless birds adapted for swimming.</p>',
])['ok'])) {
    penguin_fail('Practice Q1 failed');
}
if (empty(portal_activity_add_question($prId, 'single_choice', '<p>Emperor penguins live mainly in…</p>', null, [
    'points' => 1,
    'options' => [
        ['text' => 'The Sahara Desert', 'is_correct' => 0],
        ['text' => 'Antarctica', 'is_correct' => 1],
        ['text' => 'The Amazon rainforest', 'is_correct' => 0],
        ['text' => 'The Arctic only', 'is_correct' => 0],
    ],
])['ok'])) {
    penguin_fail('Practice Q2 failed');
}
penguin_publish_or_fail($prId, 'practice');

// Quiz
$qz = portal_activity_create($courseId, $folderId, 'Penguin quiz', 'quiz', $ownerId);
if (empty($qz['ok'])) {
    penguin_fail('Quiz create failed: ' . ($qz['error'] ?? 'unknown'));
}
$qzId = (int) $qz['activity_id'];
portal_activity_save_settings($qzId, [
    'max_attempts' => 3,
    'feedback_policy' => 'after_submission',
    'xp_enabled' => 1,
    'xp_amount' => 25,
    'leaderboard_enabled' => 1,
    'include_in_gradebook' => 1,
    'grade_weight' => 20,
], (int) (portal_activity_find($qzId)['version'] ?? 1));

$qzQs = [
    [
        'type' => 'single_choice',
        'prompt' => '<p>Why is a penguin’s colouring useful in the ocean?</p>',
        'options' => [
            ['text' => 'It helps them photosynthesize', 'is_correct' => 0],
            ['text' => 'Countershading camouflage from predators', 'is_correct' => 1],
            ['text' => 'It keeps their feathers dry only', 'is_correct' => 0],
            ['text' => 'It attracts mates with bright neon colours', 'is_correct' => 0],
        ],
    ],
    [
        'type' => 'true_false',
        'prompt' => '<p>All penguin species live only at the North Pole.</p>',
        'false_correct' => true,
    ],
    [
        'type' => 'single_choice',
        'prompt' => '<p>A penguin’s wings are best described as…</p>',
        'options' => [
            ['text' => 'Flippers for swimming', 'is_correct' => 1],
            ['text' => 'Tools for flying long distances', 'is_correct' => 0],
            ['text' => 'Decorative feathers only', 'is_correct' => 0],
            ['text' => 'Hands for climbing trees', 'is_correct' => 0],
        ],
    ],
    [
        'type' => 'short_text',
        'prompt' => '<p>Name one food penguins commonly eat.</p>',
        'extra' => [
            'settings' => [
                'accepted_answers' => ['fish', 'krill', 'squid', 'shrimp'],
            ],
        ],
    ],
];

foreach ($qzQs as $i => $q) {
    $extra = array_merge([
        'points' => 1,
        'options' => $q['options'] ?? [],
    ], $q['extra'] ?? []);
    $added = portal_activity_add_question($qzId, $q['type'], $q['prompt'], null, $extra);
    if (empty($added['ok'])) {
        penguin_fail('Quiz Q' . ($i + 1) . ' failed: ' . ($added['error'] ?? 'unknown'));
    }
    if (!empty($q['false_correct'])) {
        $qid = (int) $added['question_id'];
        $opts = $db->prepare(
            'SELECT id, option_text_html FROM activity_question_options WHERE question_id = ? ORDER BY sort_order, id'
        );
        $opts->execute([$qid]);
        foreach ($opts->fetchAll(PDO::FETCH_ASSOC) as $opt) {
            $label = strtolower(trim(strip_tags((string) $opt['option_text_html'])));
            $isFalse = $label === 'false';
            $db->prepare(
                'UPDATE activity_question_options SET is_correct = ?, credit = ? WHERE id = ?'
            )->execute([$isFalse ? 1 : 0, $isFalse ? 1 : 0, (int) $opt['id']]);
        }
    }
}
penguin_publish_or_fail($qzId, 'quiz');

penguin_ok('');
penguin_ok('Demo content is inside Accounting only.');
penguin_ok('Open: course.php?course=' . TARGET_COURSE_SLUG . '&section=content');
penguin_ok('Look for folder: ' . DEMO_FOLDER_TITLE);
exit(0);
