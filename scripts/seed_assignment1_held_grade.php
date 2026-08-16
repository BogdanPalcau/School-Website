<?php
declare(strict_types=1);

/**
 * Put a held vs unmarked pair on Accounting → Assignments → Assignment 1
 * so hold-then-release can be checked in the browser.
 *
 *   C:\xampp\php\php.exe scripts\seed_assignment1_held_grade.php
 */

require_once __DIR__ . '/../bootstrap.php';

$db = portal_db();
$course = $db->query("SELECT id, slug FROM courses WHERE slug = 'accounting-2526'")->fetch(PDO::FETCH_ASSOC);
if (!$course) {
    fwrite(STDERR, "Accounting course missing.\n");
    exit(1);
}
$courseId = (int) $course['id'];

$slot = $db->prepare(
    "SELECT id, title FROM course_folder_items
     WHERE course_id = ? AND type = 'submission' AND title = 'Assignment 1'
     LIMIT 1"
);
$slot->execute([$courseId]);
$item = $slot->fetch(PDO::FETCH_ASSOC);
if (!$item) {
    fwrite(STDERR, "Assignment 1 slot not found.\n");
    exit(1);
}
$itemId = (int) $item['id'];

$student = portal_find_user('bogdanstudent');
$student2 = portal_find_user('accstudent2');
$marker = portal_find_user('accteacher') ?: portal_find_user('bogdan');
if ($student === null || $marker === null) {
    fwrite(STDERR, "Need bogdanstudent and a teacher/owner.\n");
    exit(1);
}

$essay = 'Assignment 1 hold-release test. This pasted answer is long enough for the '
    . 'portal word checks: cash, receivables, payables, capital, and drawings are listed '
    . 'so the trial balance still appears to balance for this walkthrough.';
$wordCount = str_word_count($essay);
$now = gmdate('Y-m-d H:i:s');

$upsert = $db->prepare(
    "INSERT INTO course_submissions
        (item_id, course_id, user_id, filename, filepath, filesize, submitted_at,
         score, feedback, marked_at, marked_by, receipt_number, submission_text, text_word_count,
         eula_accepted_at, grades_released_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
     ON CONFLICT(item_id, user_id) DO UPDATE SET
        submitted_at = excluded.submitted_at,
        score = excluded.score,
        feedback = excluded.feedback,
        marked_at = excluded.marked_at,
        marked_by = excluded.marked_by,
        submission_text = excluded.submission_text,
        text_word_count = excluded.text_word_count,
        grades_released_at = excluded.grades_released_at,
        grade_seen_at = ''"
);

$receipt1 = function_exists('portal_generate_unique_receipt_number')
    ? portal_generate_unique_receipt_number($db)
    : ('TEST-' . strtoupper(bin2hex(random_bytes(8))));

$upsert->execute([
    $itemId, $courseId, (int) $student['id'],
    '', '', 0, $now,
    72,
    'Held test mark — students must not see 72% until you click Release.',
    $now, (int) $marker['id'], $receipt1, $essay, $wordCount, $now, '',
]);
$db->prepare(
    "UPDATE course_submissions SET grades_released_at = '' WHERE item_id = ? AND user_id = ?"
)->execute([$itemId, (int) $student['id']]);
echo "Assignment 1 / bogdanstudent: marked 72% HELD (not released)\n";

if ($student2 !== null) {
    $receipt2 = function_exists('portal_generate_unique_receipt_number')
        ? portal_generate_unique_receipt_number($db)
        : ('TEST-' . strtoupper(bin2hex(random_bytes(8))));
    $upsert->execute([
        $itemId, $courseId, (int) $student2['id'],
        '', '', 0, $now,
        null,
        '',
        '', null, $receipt2, $essay, $wordCount, $now, '',
    ]);
    echo "Assignment 1 / accstudent2: submitted, not graded\n";
}

echo "\nCheck as teacher: course.php?course=accounting-2526&section=gradebook\n";
echo "  Expect bogdanstudent 72% held, Release all grades on Assignment 1.\n";
echo "Check as bogdanstudent / bogdanstudent: same gradebook\n";
echo "  Expect Awaiting release, not 72%.\n";
echo "Then click Release all grades and reload as the student.\n";
