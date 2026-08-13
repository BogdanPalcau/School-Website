<?php
declare(strict_types=1);

/**
 * Download an .ics calendar for the signed-in user.
 *
 * Query:
 *   types=timetable,deadlines,events  (comma-separated; default all)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../calendar_ics.php';

portal_require_login();

$me = portal_current_user();
$rawTypes = strtolower(trim((string) ($_GET['types'] ?? 'timetable,deadlines,events')));
$types = array_values(array_filter(array_map('trim', explode(',', $rawTypes))));
$types = array_values(array_intersect($types, ['timetable', 'deadlines', 'events']));
if ($types === []) {
    $types = ['timetable', 'deadlines', 'events'];
}

$ics = portal_ics_build_for_user($me, $types);
$stamp = date('Y-m-d');
$suffix = implode('-', $types);
$filename = 'portal-calendar-' . $suffix . '-' . $stamp . '.ics';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
echo $ics;
exit;
