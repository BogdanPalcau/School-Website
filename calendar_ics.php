<?php
declare(strict_types=1);

/**
 * iCalendar (.ics) export for weekly timetable slots, submission deadlines, and events.
 */

if (!function_exists('portal_ics_fold_line')) {
    function portal_ics_fold_line(string $line): string
    {
        $line = str_replace(["\r", "\n"], '', $line);
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = substr($line, 0, 75);
        $rest = substr($line, 75);
        while ($rest !== '' && $rest !== false) {
            $out .= "\r\n " . substr($rest, 0, 74);
            $rest = substr($rest, 74);
        }

        return $out;
    }
}

if (!function_exists('portal_ics_escape_text')) {
    function portal_ics_escape_text(string $text): string
    {
        $text = str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'], $text);

        return $text;
    }
}

if (!function_exists('portal_ics_format_dt')) {
    /** Floating local time (matches how the portal stores schedule/deadlines). */
    function portal_ics_format_dt(DateTimeInterface $dt): string
    {
        return $dt->format('Ymd\THis');
    }
}

if (!function_exists('portal_ics_parse_time_parts')) {
    /**
     * @return array{0:int,1:int}|null
     */
    function portal_ics_parse_time_parts(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $raw, $m)) {
            $h = (int) $m[1];
            $i = (int) $m[2];
            if ($h >= 0 && $h <= 23 && $i >= 0 && $i <= 59) {
                return [$h, $i];
            }
        }

        return null;
    }
}

if (!function_exists('portal_ics_next_weekday_datetime')) {
    function portal_ics_next_weekday_datetime(string $dayOfWeek, string $timeRaw): ?DateTimeImmutable
    {
        static $map = [
            'Monday' => 1,
            'Tuesday' => 2,
            'Wednesday' => 3,
            'Thursday' => 4,
            'Friday' => 5,
            'Saturday' => 6,
            'Sunday' => 7,
        ];
        $target = $map[$dayOfWeek] ?? null;
        if ($target === null) {
            return null;
        }
        $parts = portal_ics_parse_time_parts($timeRaw) ?? [9, 0];
        $now = new DateTimeImmutable('today');
        for ($i = 0; $i < 7; $i++) {
            $candidate = $now->modify('+' . $i . ' days');
            if ((int) $candidate->format('N') === $target) {
                return $candidate->setTime($parts[0], $parts[1], 0);
            }
        }

        return null;
    }
}

if (!function_exists('portal_ics_uid_host')) {
    function portal_ics_uid_host(): string
    {
        $base = function_exists('portal_configured_base_url') ? portal_configured_base_url() : '';
        if ($base !== '') {
            $host = parse_url($base, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return strtolower($host);
            }
        }

        return 'school-portal.local';
    }
}

if (!function_exists('portal_ics_vevent')) {
    /**
     * @param array{
     *   uid:string,
     *   summary:string,
     *   description?:string,
     *   location?:string,
     *   url?:string,
     *   dtstart:DateTimeInterface,
     *   dtend:DateTimeInterface,
     *   rrule?:string,
     *   categories?:string
     * } $event
     */
    function portal_ics_vevent(array $event): string
    {
        $stamp = portal_ics_format_dt(new DateTimeImmutable('now'));
        $lines = [
            'BEGIN:VEVENT',
            'UID:' . $event['uid'],
            'DTSTAMP:' . $stamp,
            'DTSTART:' . portal_ics_format_dt($event['dtstart']),
            'DTEND:' . portal_ics_format_dt($event['dtend']),
            'SUMMARY:' . portal_ics_escape_text($event['summary']),
        ];
        if (!empty($event['rrule'])) {
            $lines[] = 'RRULE:' . $event['rrule'];
        }
        if (!empty($event['description'])) {
            $lines[] = 'DESCRIPTION:' . portal_ics_escape_text((string) $event['description']);
        }
        if (!empty($event['location'])) {
            $lines[] = 'LOCATION:' . portal_ics_escape_text((string) $event['location']);
        }
        if (!empty($event['url'])) {
            $lines[] = 'URL:' . portal_ics_escape_text((string) $event['url']);
        }
        if (!empty($event['categories'])) {
            $lines[] = 'CATEGORIES:' . portal_ics_escape_text((string) $event['categories']);
        }
        $lines[] = 'END:VEVENT';

        return implode("\r\n", array_map('portal_ics_fold_line', $lines));
    }
}

if (!function_exists('portal_ics_calendar')) {
    /**
     * @param list<string> $vevents
     */
    function portal_ics_calendar(string $calName, array $vevents): string
    {
        $school = function_exists('portal_school_name') ? portal_school_name() : 'School Portal';
        $header = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//' . portal_ics_escape_text($school) . '//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . portal_ics_escape_text($calName),
        ];
        $footer = ['END:VCALENDAR'];
        $body = array_values(array_filter($vevents, static fn(string $e): bool => $e !== ''));

        return implode("\r\n", array_merge(
            array_map('portal_ics_fold_line', $header),
            $body,
            array_map('portal_ics_fold_line', $footer)
        )) . "\r\n";
    }
}

if (!function_exists('portal_ics_user_schedule_rows')) {
    /** @return list<array<string,mixed>> */
    function portal_ics_user_schedule_rows(array $user): array
    {
        $db = portal_db();
        $uid = (int) ($user['id'] ?? 0);
        if (portal_is_admin()) {
            return $db->query(
                "SELECT cs.*, c.slug, c.title, c.code, c.accent
                 FROM course_schedule cs
                 JOIN courses c ON c.id = cs.course_id
                 ORDER BY cs.id ASC"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        if (portal_is_course_staff()) {
            $stmt = $db->prepare(
                "SELECT cs.*, c.slug, c.title, c.code, c.accent
                 FROM course_schedule cs
                 JOIN courses c ON c.id = cs.course_id
                 JOIN course_teachers ct ON ct.course_id = c.id
                 WHERE ct.user_id = ?
                 ORDER BY cs.id ASC"
            );
            $stmt->execute([$uid]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        $stmt = $db->prepare(
            "SELECT cs.*, c.slug, c.title, c.code, c.accent
             FROM course_schedule cs
             JOIN courses c ON c.id = cs.course_id
             JOIN enrollments e ON e.course_id = c.id
             WHERE e.user_id = ?
             ORDER BY cs.id ASC"
        );
        $stmt->execute([$uid]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('portal_ics_user_deadline_rows')) {
    /** @return list<array<string,mixed>> */
    function portal_ics_user_deadline_rows(array $user): array
    {
        $uid = (int) ($user['id'] ?? 0);
        if ($uid <= 0) {
            return [];
        }

        $db = portal_db();
        $enroll = $db->prepare('SELECT course_id FROM enrollments WHERE user_id = ?');
        $enroll->execute([$uid]);
        $courseIds = array_map('intval', $enroll->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($courseIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
        $stmt = $db->prepare(
            "SELECT cfi.id, cfi.title, cfi.submission_deadline, cfi.description,
                    c.slug, c.title AS course_title, c.code,
                    cs.id AS submission_id
             FROM course_folder_items cfi
             JOIN courses c ON c.id = cfi.course_id
             LEFT JOIN course_submissions cs
                    ON cs.item_id = cfi.id AND cs.user_id = ?
             WHERE cfi.type = 'submission'
               AND cfi.submission_deadline != ''
               AND cfi.course_id IN ($placeholders)
             ORDER BY cfi.submission_deadline ASC
             LIMIT 100"
        );
        $stmt->execute(array_merge([$uid], $courseIds));

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('portal_ics_build_for_user')) {
    /**
     * @param list<string> $types timetable|deadlines|events
     */
    function portal_ics_build_for_user(array $user, array $types = ['timetable', 'deadlines', 'events']): string
    {
        $types = array_values(array_intersect($types, ['timetable', 'deadlines', 'events']));
        if ($types === []) {
            $types = ['timetable', 'deadlines', 'events'];
        }

        $host = portal_ics_uid_host();
        $vevents = [];
        $baseUrl = function_exists('portal_configured_base_url') ? portal_configured_base_url() : '';
        if ($baseUrl === '' && function_exists('portal_base_url')) {
            $baseUrl = portal_base_url();
        }

        if (in_array('timetable', $types, true)) {
            foreach (portal_ics_user_schedule_rows($user) as $row) {
                $day = (string) ($row['day_of_week'] ?? '');
                $startRaw = trim((string) ($row['start_time'] ?? ''));
                $endRaw = trim((string) ($row['end_time'] ?? ''));
                $dtStart = portal_ics_next_weekday_datetime($day, $startRaw !== '' ? $startRaw : '09:00');
                if ($dtStart === null) {
                    continue;
                }
                $endParts = portal_ics_parse_time_parts($endRaw);
                if ($endParts !== null) {
                    $dtEnd = $dtStart->setTime($endParts[0], $endParts[1], 0);
                    if ($dtEnd <= $dtStart) {
                        $dtEnd = $dtStart->modify('+1 hour');
                    }
                } else {
                    $dtEnd = $dtStart->modify('+1 hour');
                }

                $title = trim((string) ($row['title'] ?? 'Class'));
                $code = trim((string) ($row['code'] ?? ''));
                $summary = $code !== '' ? ($title . ' (' . $code . ')') : $title;
                $room = trim((string) ($row['room'] ?? ''));
                $notes = trim((string) ($row['notes'] ?? ''));
                $slug = (string) ($row['slug'] ?? '');
                $url = $baseUrl !== '' && $slug !== ''
                    ? rtrim($baseUrl, '/') . '/course.php?course=' . rawurlencode($slug) . '&section=calendar'
                    : '';
                $desc = $notes;
                if ($room !== '' && !preg_match('/^https?:\/\//i', $room)) {
                    $desc = trim($desc . ($desc !== '' ? "\n" : '') . 'Room: ' . $room);
                } elseif (preg_match('/^https?:\/\//i', $room)) {
                    $desc = trim($desc . ($desc !== '' ? "\n" : '') . 'Join: ' . $room);
                }

                $vevents[] = portal_ics_vevent([
                    'uid' => 'schedule-' . (int) $row['id'] . '@' . $host,
                    'summary' => $summary,
                    'description' => $desc,
                    'location' => preg_match('/^https?:\/\//i', $room) ? '' : $room,
                    'url' => $url !== '' ? $url : ($room !== '' && preg_match('/^https?:\/\//i', $room) ? $room : ''),
                    'dtstart' => $dtStart,
                    'dtend' => $dtEnd,
                    'rrule' => 'FREQ=WEEKLY;COUNT=16',
                    'categories' => 'Timetable',
                ]);
            }
        }

        if (in_array('deadlines', $types, true)) {
            foreach (portal_ics_user_deadline_rows($user) as $row) {
                $raw = trim((string) ($row['submission_deadline'] ?? ''));
                $ts = $raw !== '' ? strtotime($raw) : false;
                if ($ts === false) {
                    continue;
                }
                $dtStart = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone(date_default_timezone_get()));
                $dtEnd = $dtStart;
                $dtStartBlock = $dtStart->modify('-30 minutes');

                $itemTitle = trim((string) ($row['title'] ?? 'Assignment'));
                $courseTitle = trim((string) ($row['course_title'] ?? ''));
                $submitted = !empty($row['submission_id']);
                $summary = ($submitted ? 'Submitted: ' : 'Due: ') . $itemTitle;
                if ($courseTitle !== '') {
                    $summary .= ' — ' . $courseTitle;
                }
                $slug = (string) ($row['slug'] ?? '');
                $url = $baseUrl !== '' && $slug !== ''
                    ? rtrim($baseUrl, '/') . '/course.php?course=' . rawurlencode($slug) . '&section=content'
                    : '';
                $desc = trim((string) ($row['description'] ?? ''));
                if ($submitted) {
                    $desc = trim('Already submitted.' . ($desc !== '' ? "\n" . $desc : ''));
                }

                $vevents[] = portal_ics_vevent([
                    'uid' => 'deadline-' . (int) $row['id'] . '@' . $host,
                    'summary' => $summary,
                    'description' => $desc,
                    'url' => $url,
                    'dtstart' => $dtStartBlock,
                    'dtend' => $dtEnd,
                    'categories' => 'Deadline',
                ]);
            }
        }

        if (in_array('events', $types, true) && function_exists('portal_events_list')) {
            foreach (portal_events_list('upcoming', 'all', 100) as $event) {
                if (($event['status'] ?? '') === 'cancelled') {
                    continue;
                }
                $startRaw = trim((string) ($event['starts_at'] ?? ''));
                $startTs = $startRaw !== '' ? strtotime($startRaw) : false;
                if ($startTs === false) {
                    continue;
                }
                $tz = new DateTimeZone(date_default_timezone_get());
                $dtStart = (new DateTimeImmutable('@' . $startTs))->setTimezone($tz);
                $endRaw = trim((string) ($event['ends_at'] ?? ''));
                $endTs = $endRaw !== '' ? strtotime($endRaw) : false;
                $dtEnd = $endTs !== false
                    ? (new DateTimeImmutable('@' . $endTs))->setTimezone($tz)
                    : $dtStart->modify('+1 hour');
                if ($dtEnd <= $dtStart) {
                    $dtEnd = $dtStart->modify('+1 hour');
                }

                $summary = trim((string) ($event['title'] ?? 'Event'));
                $loc = trim((string) ($event['location'] ?? ''));
                $online = trim((string) ($event['online_url'] ?? ''));
                $desc = trim((string) ($event['summary'] ?? ''));
                if ($desc === '') {
                    $desc = trim(strip_tags((string) ($event['description'] ?? '')));
                }
                if ($online !== '') {
                    $desc = trim($desc . ($desc !== '' ? "\n" : '') . 'Online: ' . $online);
                }
                $url = $baseUrl !== ''
                    ? rtrim($baseUrl, '/') . '/events.php?event=' . (int) ($event['id'] ?? 0)
                    : '';

                $vevents[] = portal_ics_vevent([
                    'uid' => 'event-' . (int) ($event['id'] ?? 0) . '@' . $host,
                    'summary' => $summary,
                    'description' => $desc,
                    'location' => $loc,
                    'url' => $url !== '' ? $url : $online,
                    'dtstart' => $dtStart,
                    'dtend' => $dtEnd,
                    'categories' => 'Event',
                ]);
            }
        }

        $school = function_exists('portal_school_name') ? portal_school_name() : 'School Portal';
        $labelBits = [];
        if (in_array('timetable', $types, true)) {
            $labelBits[] = 'Timetable';
        }
        if (in_array('deadlines', $types, true)) {
            $labelBits[] = 'Deadlines';
        }
        if (in_array('events', $types, true)) {
            $labelBits[] = 'Events';
        }
        $calName = $school . ' — ' . implode(' + ', $labelBits);

        return portal_ics_calendar($calName, $vevents);
    }
}
