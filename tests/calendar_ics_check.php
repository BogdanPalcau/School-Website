<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/calendar_ics.php';

$failed = 0;
$passed = 0;

function ics_expect(bool $cond, string $label): void
{
    global $failed, $passed;
    if ($cond) {
        echo "PASS  {$label}\n";
        $passed++;
    } else {
        echo "FAIL  {$label}\n";
        $failed++;
    }
}

ics_expect(function_exists('portal_ics_build_for_user'), 'builder exists');
ics_expect(portal_ics_escape_text("Hello; world, ok\nline") === 'Hello\\; world\\, ok\\nline', 'text escaping');

$mon = portal_ics_next_weekday_datetime('Monday', '09:30');
ics_expect($mon instanceof DateTimeImmutable, 'next Monday resolves');
ics_expect($mon !== null && (int) $mon->format('N') === 1, 'resolved day is Monday');
ics_expect($mon !== null && $mon->format('H:i') === '09:30', 'resolved time is 09:30');

$vevent = portal_ics_vevent([
    'uid' => 'test-1@example.test',
    'summary' => 'Maths',
    'description' => 'Room A',
    'dtstart' => new DateTimeImmutable('2026-08-17 09:00:00'),
    'dtend' => new DateTimeImmutable('2026-08-17 10:00:00'),
    'rrule' => 'FREQ=WEEKLY;COUNT=16',
    'categories' => 'Timetable',
]);
ics_expect(str_contains($vevent, 'BEGIN:VEVENT'), 'vevent has begin');
ics_expect(str_contains($vevent, 'RRULE:FREQ=WEEKLY;COUNT=16'), 'vevent has rrule');
ics_expect(str_contains($vevent, 'DTSTART:20260817T090000'), 'vevent dtstart');

$cal = portal_ics_calendar('Test Cal', [$vevent]);
ics_expect(str_starts_with($cal, 'BEGIN:VCALENDAR'), 'calendar begins');
ics_expect(str_contains($cal, 'END:VCALENDAR'), 'calendar ends');

$user = ['id' => 0, 'role' => 'student'];
$built = portal_ics_build_for_user($user, ['timetable']);
ics_expect(str_contains($built, 'BEGIN:VCALENDAR'), 'build_for_user returns calendar');

$exportFile = dirname(__DIR__) . '/public/calendar-export.php';
ics_expect(is_file($exportFile), 'calendar-export.php exists');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
