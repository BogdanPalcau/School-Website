<?php
declare(strict_types=1);

/**
 * CLI regression checks for live course schedule summaries.
 * Run: php tests/course_schedule_summary_check.php
 */

$dbPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'portal-schedule-check-' . bin2hex(random_bytes(8)) . '.sqlite';
putenv('PORTAL_DB_PATH=' . $dbPath);
register_shutdown_function(static function () use ($dbPath): void {
    foreach ([$dbPath, $dbPath . '-shm', $dbPath . '-wal'] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
});

require_once __DIR__ . '/../course_catalog.php';

$failures = 0;

function schedule_expect_same(mixed $expected, mixed $actual, string $label): void
{
    global $failures;
    if ($expected === $actual) {
        echo "PASS  {$label}\n";
        return;
    }

    $failures++;
    echo "FAIL  {$label} (expected "
        . var_export($expected, true)
        . ', got '
        . var_export($actual, true)
        . ")\n";
}

$db = portal_db();
$courseStmt = $db->prepare('SELECT id, meeting, room FROM courses WHERE slug = ?');
$courseStmt->execute(['computer-science-2526']);
$storedCourse = $courseStmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($storedCourse)) {
    fwrite(STDERR, "FAIL  seeded course was not created\n");
    exit(1);
}

$courseId = (int) $storedCourse['id'];
$course = portal_find_course('computer-science-2526');
schedule_expect_same(
    (string) $storedCourse['meeting'],
    (string) ($course['meeting'] ?? ''),
    'catalog preserves configured meeting when no live slots exist'
);
schedule_expect_same(
    (string) $storedCourse['room'],
    (string) ($course['room'] ?? ''),
    'catalog preserves configured room when no live slots exist'
);

$db->prepare(
    'INSERT INTO course_schedule (course_id, day_of_week, start_time, end_time, room, notes, sort_order)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
)->execute([$courseId, 'Tuesday', '10:00', '11:00', 'https://meet.example.test/class', '', 0]);

$course = portal_find_course('computer-science-2526');
schedule_expect_same(
    'Tue 10:00–11:00',
    (string) ($course['meeting'] ?? ''),
    'catalog prefers a live slot meeting'
);
schedule_expect_same('Online', (string) ($course['room'] ?? ''), 'catalog labels a live join URL as online');

$courseStmt->execute(['computer-science-2526']);
$storedAfterRead = $courseStmt->fetch(PDO::FETCH_ASSOC);
schedule_expect_same(
    (string) $storedCourse['meeting'],
    (string) ($storedAfterRead['meeting'] ?? ''),
    'reading live schedule leaves configured meeting fallback unchanged'
);
schedule_expect_same(
    (string) $storedCourse['room'],
    (string) ($storedAfterRead['room'] ?? ''),
    'reading live schedule leaves configured room fallback unchanged'
);

$db->prepare(
    'INSERT INTO course_schedule (course_id, day_of_week, start_time, end_time, room, notes, sort_order)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
)->execute([$courseId, 'Thursday', '13:00', '14:00', 'Lab 4', '', 1]);

$course = portal_find_course('computer-science-2526');
schedule_expect_same('Hybrid', (string) ($course['location_mode'] ?? ''), 'mixed live slots are hybrid');
schedule_expect_same(
    'Online / Lab 4',
    (string) ($course['room'] ?? ''),
    'hybrid summary includes the physical room without exposing the join URL'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} schedule regression check(s) failed.\n");
    exit(1);
}

echo "All course schedule summary checks passed.\n";
