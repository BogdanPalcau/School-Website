<?php
declare(strict_types=1);

/**
 * School / course events helpers — one-off dated activities (not weekly schedule).
 */

if (!function_exists('portal_visible_event_course_ids')) {
    /**
     * Course IDs whose events the current user may see.
     * Same shape as portal_my_announcement_course_ids().
     *
     * @return int[]
     */
    function portal_visible_event_course_ids(): array
    {
        return portal_my_announcement_course_ids();
    }
}

if (!function_exists('portal_event_can_view')) {
    /**
     * @param array<string, mixed> $event
     */
    function portal_event_can_view(array $event): bool
    {
        if (!portal_is_logged_in()) {
            return false;
        }

        $courseId = isset($event['course_id']) && $event['course_id'] !== null && $event['course_id'] !== ''
            ? (int) $event['course_id']
            : 0;

        if ($courseId <= 0) {
            return true; // school-wide
        }

        return in_array($courseId, portal_visible_event_course_ids(), true)
            || portal_can_access_course($courseId);
    }
}

if (!function_exists('portal_event_can_manage')) {
    /**
     * @param array<string, mixed> $event
     */
    function portal_event_can_manage(array $event): bool
    {
        if (!portal_is_logged_in()) {
            return false;
        }
        if (portal_is_admin()) {
            return true;
        }

        $courseId = isset($event['course_id']) && $event['course_id'] !== null && $event['course_id'] !== ''
            ? (int) $event['course_id']
            : 0;

        if ($courseId <= 0) {
            return false;
        }

        return portal_can_manage_course($courseId);
    }
}

if (!function_exists('portal_event_can_create')) {
    function portal_event_can_create(?int $courseId): bool
    {
        if (!portal_is_logged_in()) {
            return false;
        }
        if ($courseId === null || $courseId <= 0) {
            return portal_is_admin();
        }

        return portal_can_manage_course($courseId);
    }
}

if (!function_exists('portal_event_staff_can_compose')) {
    /** True if the user may open the create panel (further restricted by course options). */
    function portal_event_staff_can_compose(): bool
    {
        return portal_is_admin() || portal_is_teacher();
    }
}

if (!function_exists('portal_event_normalize_datetime')) {
    /**
     * Accept datetime-local or SQLite-ish strings; return Y-m-d H:i:s or ''.
     */
    function portal_event_normalize_datetime(string $raw): string
    {
        $raw = trim(str_replace('T', ' ', $raw));
        if ($raw === '') {
            return '';
        }
        // Allow "Y-m-d H:i" → add seconds
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw)) {
            $raw .= ':00';
        }
        $ts = portal_db_timestamp($raw);
        if ($ts === null) {
            return '';
        }

        return date('Y-m-d H:i:s', $ts);
    }
}

if (!function_exists('portal_event_is_past')) {
    /**
     * @param array<string, mixed> $event
     */
    function portal_event_is_past(array $event, ?int $now = null): bool
    {
        $now = $now ?? time();
        $end = trim((string) ($event['ends_at'] ?? ''));
        $start = trim((string) ($event['starts_at'] ?? ''));
        $raw = $end !== '' ? $end : $start;
        $ts = portal_db_timestamp($raw);

        return $ts !== null && $ts < $now;
    }
}

if (!function_exists('portal_event_is_upcoming')) {
    /**
     * @param array<string, mixed> $event
     */
    function portal_event_is_upcoming(array $event, ?int $now = null): bool
    {
        return !portal_event_is_past($event, $now);
    }
}

if (!function_exists('portal_event_validate_payload')) {
    /**
     * @param array<string, mixed> $input
     * @return array{ok:bool,errors:string[],data:array<string,mixed>}
     */
    function portal_event_validate_payload(array $input, bool $forUpdate = false): array
    {
        $errors = [];
        $title = substr(trim((string) ($input['title'] ?? '')), 0, 200);
        $summary = substr(trim((string) ($input['summary'] ?? '')), 0, 500);
        $description = substr(portal_sanitize_rich_text(trim((string) ($input['description'] ?? ''))), 0, 20000);
        $startsAt = portal_event_normalize_datetime((string) ($input['starts_at'] ?? ''));
        $endsAt = portal_event_normalize_datetime((string) ($input['ends_at'] ?? ''));
        $location = substr(trim((string) ($input['location'] ?? '')), 0, 200);
        $onlineUrl = substr(trim((string) ($input['online_url'] ?? '')), 0, 500);

        $scope = (string) ($input['scope'] ?? 'course');
        if ($scope !== 'school' && $scope !== 'course') {
            $scope = 'course';
        }

        $courseId = (int) ($input['course_id'] ?? 0);
        if ($scope === 'school') {
            $courseId = 0;
        }

        $important = !empty($input['important']) ? 1 : 0;
        if (!portal_is_admin()) {
            $important = 0;
            if ($scope === 'school') {
                $errors[] = 'Only admins can create school-wide events.';
            }
        }

        if ($title === '') {
            $errors[] = 'Please add a title.';
        }
        if ($summary === '') {
            $errors[] = 'Please add a short summary.';
        }
        if ($startsAt === '') {
            $errors[] = 'Please choose a valid start date and time.';
        }

        if ($endsAt !== '' && $startsAt !== '') {
            $startTs = portal_db_timestamp($startsAt);
            $endTs = portal_db_timestamp($endsAt);
            if ($startTs !== null && $endTs !== null && $endTs < $startTs) {
                $errors[] = 'End date/time cannot be earlier than the start.';
            }
        }

        if ($onlineUrl !== '' && !portal_valid_external_url($onlineUrl)) {
            $errors[] = 'Online link must be a valid http or https URL.';
        }

        $resolvedCourseId = null;
        if ($scope === 'course') {
            if ($courseId <= 0) {
                $errors[] = 'Please choose a course for this event.';
            } elseif (!portal_event_can_create($courseId)) {
                $errors[] = 'You cannot create events for that course.';
            } else {
                $resolvedCourseId = $courseId;
            }
        } elseif (!portal_event_can_create(null)) {
            $errors[] = 'You cannot create school-wide events.';
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'data' => [
                'title' => $title,
                'summary' => $summary,
                'description' => $description,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'location' => $location,
                'online_url' => $onlineUrl,
                'course_id' => $resolvedCourseId,
                'important' => $important,
                'scope' => $scope,
                'notify' => !empty($input['notify']) ? 1 : 0,
            ],
        ];
    }
}

if (!function_exists('portal_event_select_sql')) {
    function portal_event_select_sql(): string
    {
        return "SELECT e.*,
                       u.name AS organiser_name,
                       u.initials AS organiser_initials,
                       c.title AS course_title,
                       c.slug AS course_slug,
                       c.accent AS course_accent,
                       c.code AS course_code
                FROM events e
                JOIN users u ON u.id = e.created_by
                LEFT JOIN courses c ON c.id = e.course_id";
    }
}

if (!function_exists('portal_event_get')) {
    /**
     * @return array<string, mixed>|null
     */
    function portal_event_get(int $id, bool $requireView = true): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = portal_db()->prepare(portal_event_select_sql() . ' WHERE e.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        if ($requireView && !portal_event_can_view($row)) {
            return null;
        }

        return $row;
    }
}

if (!function_exists('portal_events_visibility_sql')) {
    /**
     * @param list<int|string> $params appended in place
     */
    function portal_events_visibility_sql(array &$params): string
    {
        if (portal_is_admin()) {
            return '1=1';
        }

        $courseIds = portal_visible_event_course_ids();
        if ($courseIds === []) {
            return 'e.course_id IS NULL';
        }

        $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
        foreach ($courseIds as $cid) {
            $params[] = $cid;
        }

        return "(e.course_id IS NULL OR e.course_id IN ($placeholders))";
    }
}

if (!function_exists('portal_events_list')) {
    /**
     * @param 'upcoming'|'past' $view
     * @param 'all'|'school'|'courses' $scope
     * @return list<array<string, mixed>>
     */
    function portal_events_list(string $view = 'upcoming', string $scope = 'all', int $limit = 200): array
    {
        $view = $view === 'past' ? 'past' : 'upcoming';
        $scope = in_array($scope, ['all', 'school', 'courses'], true) ? $scope : 'all';
        $limit = max(1, min(500, $limit));

        $params = [];
        $where = [portal_events_visibility_sql($params)];

        if ($scope === 'school') {
            $where[] = 'e.course_id IS NULL';
        } elseif ($scope === 'courses') {
            $where[] = 'e.course_id IS NOT NULL';
        }

        $now = date('Y-m-d H:i:s');
        // Past if COALESCE(ends_at, starts_at) < now; upcoming otherwise.
        if ($view === 'past') {
            $where[] = "datetime(CASE WHEN e.ends_at != '' THEN e.ends_at ELSE e.starts_at END) < datetime(?)";
            $params[] = $now;
            $order = 'datetime(e.starts_at) DESC';
        } else {
            $where[] = "datetime(CASE WHEN e.ends_at != '' THEN e.ends_at ELSE e.starts_at END) >= datetime(?)";
            $params[] = $now;
            $order = 'datetime(e.starts_at) ASC';
        }

        $sql = portal_event_select_sql()
            . ' WHERE ' . implode(' AND ', $where)
            . " ORDER BY $order LIMIT $limit";

        $stmt = portal_db()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('portal_event_featured')) {
    /**
     * @param list<array<string, mixed>>|null $upcoming preloaded upcoming list
     * @return array<string, mixed>|null
     */
    function portal_event_featured(?array $upcoming = null): ?array
    {
        $list = $upcoming ?? portal_events_list('upcoming', 'all', 100);
        $scheduled = array_values(array_filter(
            $list,
            static fn (array $e): bool => (string) ($e['status'] ?? '') !== 'cancelled'
        ));

        foreach ($scheduled as $event) {
            if (
                !empty($event['important'])
                && ($event['course_id'] === null || $event['course_id'] === '' || (int) $event['course_id'] === 0)
            ) {
                return $event;
            }
        }

        return $scheduled[0] ?? null;
    }
}

if (!function_exists('portal_event_group_upcoming')) {
    /**
     * @param list<array<string, mixed>> $events
     * @return array{today:list<array<string,mixed>>,this_week:list<array<string,mixed>>,later:list<array<string,mixed>>}
     */
    function portal_event_group_upcoming(array $events): array
    {
        $todayStart = strtotime('today');
        $todayEnd = strtotime('tomorrow') - 1;
        $weekEnd = strtotime('sunday 23:59:59');
        if ($weekEnd === false || $weekEnd < ($todayEnd ?: time())) {
            $weekEnd = strtotime('+7 days');
        }

        $groups = ['today' => [], 'this_week' => [], 'later' => []];
        foreach ($events as $event) {
            $ts = portal_db_timestamp((string) ($event['starts_at'] ?? ''));
            if ($ts === null) {
                $groups['later'][] = $event;
                continue;
            }
            if ($ts >= $todayStart && $ts <= $todayEnd) {
                $groups['today'][] = $event;
            } elseif ($ts <= $weekEnd) {
                $groups['this_week'][] = $event;
            } else {
                $groups['later'][] = $event;
            }
        }

        return $groups;
    }
}

if (!function_exists('portal_event_format_full')) {
    function portal_event_format_full(string $startsAt): string
    {
        $ts = portal_db_timestamp($startsAt);
        if ($ts === null) {
            return $startsAt;
        }

        return date('l j F Y \a\t H:i', $ts);
    }
}

if (!function_exists('portal_event_format_time_range')) {
    /**
     * @param array<string, mixed> $event
     */
    function portal_event_format_time_range(array $event): string
    {
        $startTs = portal_db_timestamp((string) ($event['starts_at'] ?? ''));
        if ($startTs === null) {
            return '';
        }
        $start = date('H:i', $startTs);
        $endRaw = trim((string) ($event['ends_at'] ?? ''));
        if ($endRaw === '') {
            return $start;
        }
        $endTs = portal_db_timestamp($endRaw);
        if ($endTs === null) {
            return $start;
        }

        return $start . ' – ' . date('H:i', $endTs);
    }
}

if (!function_exists('portal_event_chip_parts')) {
    /**
     * @param array<string, mixed> $event
     * @return array{month:string,day:string}
     */
    function portal_event_chip_parts(array $event): array
    {
        $ts = portal_db_timestamp((string) ($event['starts_at'] ?? ''));
        if ($ts === null) {
            return ['month' => '', 'day' => ''];
        }

        return [
            'month' => date('M', $ts),
            'day' => date('d', $ts),
        ];
    }
}

if (!function_exists('portal_event_place_label')) {
    /**
     * @param array<string, mixed> $event
     */
    function portal_event_place_label(array $event): string
    {
        $loc = trim((string) ($event['location'] ?? ''));
        $url = trim((string) ($event['online_url'] ?? ''));
        if ($loc !== '') {
            return $loc;
        }
        if ($url !== '') {
            return 'Online';
        }

        return 'Location TBC';
    }
}

if (!function_exists('portal_event_notify_recipients')) {
    /**
     * @param array<string, mixed> $event
     * @return list<int>
     */
    function portal_event_notify_recipients(array $event): array
    {
        $db = portal_db();
        $courseId = isset($event['course_id']) && $event['course_id'] !== null && $event['course_id'] !== ''
            ? (int) $event['course_id']
            : 0;

        $ids = [];
        if ($courseId <= 0) {
            $ids = array_map('intval', $db->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } else {
            $enrolled = $db->prepare('SELECT user_id FROM enrollments WHERE course_id = ?');
            $enrolled->execute([$courseId]);
            $ids = array_map('intval', $enrolled->fetchAll(PDO::FETCH_COLUMN) ?: []);

            $staff = $db->prepare('SELECT user_id FROM course_teachers WHERE course_id = ?');
            $staff->execute([$courseId]);
            $ids = array_merge($ids, array_map('intval', $staff->fetchAll(PDO::FETCH_COLUMN) ?: []));
        }

        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        // Only recipients who would be authorised to view this event.
        $filtered = [];
        foreach ($ids as $uid) {
            // School-wide: all users. Course: must be enrolled/staff/admin — already selected.
            $filtered[] = $uid;
        }

        return $filtered;
    }
}

if (!function_exists('portal_event_send_notifications')) {
    /**
     * @param array<string, mixed> $event
     */
    function portal_event_send_notifications(array $event, string $kind = 'new'): int
    {
        $id = (int) ($event['id'] ?? 0);
        $title = trim((string) ($event['title'] ?? ''));
        if ($id <= 0 || $title === '') {
            return 0;
        }

        $when = portal_event_format_full((string) ($event['starts_at'] ?? ''));
        $notifTitle = match ($kind) {
            'cancelled' => 'Event cancelled: ' . $title,
            'updated' => 'Event updated: ' . $title,
            default => 'New event: ' . $title,
        };
        $body = match ($kind) {
            'cancelled' => $when !== '' ? ('Was scheduled for ' . $when) : 'This event has been cancelled.',
            'updated' => $when !== '' ? $when : 'Details have changed.',
            default => $when,
        };

        $courseId = isset($event['course_id']) && $event['course_id'] !== null && $event['course_id'] !== ''
            ? (int) $event['course_id']
            : 0;
        $link = 'events.php?event=' . $id;
        $sent = 0;
        foreach (portal_event_notify_recipients($event) as $userId) {
            if (portal_notify_user($userId, 'event', $notifTitle, $body, $link, $courseId)) {
                $sent++;
            }
        }

        return $sent;
    }
}

if (!function_exists('portal_event_meaningful_changes')) {
    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    function portal_event_meaningful_changes(array $before, array $after): bool
    {
        foreach (['starts_at', 'ends_at', 'location', 'online_url', 'status'] as $key) {
            $a = trim((string) ($before[$key] ?? ''));
            $b = trim((string) ($after[$key] ?? ''));
            if ($a !== $b) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('portal_events_for_dashboard')) {
    /**
     * @return list<array<string, mixed>>
     */
    function portal_events_for_dashboard(int $limit = 3): array
    {
        $limit = max(1, min(10, $limit));
        $events = portal_events_list('upcoming', 'all', 50);
        $scheduled = array_values(array_filter(
            $events,
            static fn (array $e): bool => (string) ($e['status'] ?? '') !== 'cancelled'
        ));

        return array_slice($scheduled, 0, $limit);
    }
}

if (!function_exists('portal_events_for_course')) {
    /**
     * @return list<array<string, mixed>>
     */
    function portal_events_for_course(int $courseId, int $limit = 20, bool $upcomingOnly = true): array
    {
        if ($courseId <= 0) {
            return [];
        }

        $params = [$courseId];
        $where = ['e.course_id = ?'];
        if ($upcomingOnly) {
            $where[] = "datetime(CASE WHEN e.ends_at != '' THEN e.ends_at ELSE e.starts_at END) >= datetime(?)";
            $params[] = date('Y-m-d H:i:s');
        }

        $limit = max(1, min(100, $limit));
        $sql = portal_event_select_sql()
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY datetime(e.starts_at) ASC LIMIT ' . $limit;

        $stmt = portal_db()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('portal_event_create')) {
    /**
     * @param array<string, mixed> $data from portal_event_validate_payload
     * @return array{ok:bool,id:int,error:string}
     */
    function portal_event_create(array $data, int $createdBy): array
    {
        $courseId = $data['course_id'] ?? null;
        if (!portal_event_can_create($courseId === null ? null : (int) $courseId)) {
            return ['ok' => false, 'id' => 0, 'error' => 'You are not allowed to create this event.'];
        }

        portal_db()->prepare(
            "INSERT INTO events
                (course_id, created_by, title, summary, description, starts_at, ends_at, location, online_url, important, status, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?, 'scheduled', datetime('now'), datetime('now'))"
        )->execute([
            $courseId,
            $createdBy,
            $data['title'],
            $data['summary'],
            $data['description'],
            $data['starts_at'],
            $data['ends_at'],
            $data['location'],
            $data['online_url'],
            (int) $data['important'],
        ]);

        $id = (int) portal_db()->lastInsertId();

        return ['ok' => true, 'id' => $id, 'error' => ''];
    }
}

if (!function_exists('portal_event_update')) {
    /**
     * @param array<string, mixed> $data
     * @return array{ok:bool,error:string}
     */
    function portal_event_update(int $eventId, array $data): array
    {
        $existing = portal_event_get($eventId, false);
        if (!$existing || !portal_event_can_manage($existing)) {
            return ['ok' => false, 'error' => 'You are not allowed to edit this event.'];
        }

        // Teachers cannot move an event to school-wide or unassigned courses.
        $courseId = $data['course_id'] ?? null;
        if (!portal_event_can_create($courseId === null ? null : (int) $courseId)) {
            return ['ok' => false, 'error' => 'You cannot assign this event to that scope or course.'];
        }

        portal_db()->prepare(
            "UPDATE events SET
                course_id = ?, title = ?, summary = ?, description = ?,
                starts_at = ?, ends_at = ?, location = ?, online_url = ?,
                important = ?, updated_at = datetime('now')
             WHERE id = ?"
        )->execute([
            $courseId,
            $data['title'],
            $data['summary'],
            $data['description'],
            $data['starts_at'],
            $data['ends_at'],
            $data['location'],
            $data['online_url'],
            (int) $data['important'],
            $eventId,
        ]);

        return ['ok' => true, 'error' => ''];
    }
}

if (!function_exists('portal_event_cancel')) {
    /**
     * @return array{ok:bool,error:string}
     */
    function portal_event_cancel(int $eventId): array
    {
        $existing = portal_event_get($eventId, false);
        if (!$existing || !portal_event_can_manage($existing)) {
            return ['ok' => false, 'error' => 'You are not allowed to cancel this event.'];
        }

        portal_db()->prepare(
            "UPDATE events SET status = 'cancelled', updated_at = datetime('now') WHERE id = ?"
        )->execute([$eventId]);

        return ['ok' => true, 'error' => ''];
    }
}

if (!function_exists('portal_event_delete')) {
    /**
     * @return array{ok:bool,error:string}
     */
    function portal_event_delete(int $eventId): array
    {
        $existing = portal_event_get($eventId, false);
        if (!$existing || !portal_event_can_manage($existing)) {
            return ['ok' => false, 'error' => 'You are not allowed to delete this event.'];
        }

        portal_db()->prepare('DELETE FROM events WHERE id = ?')->execute([$eventId]);
        // Drop new/updated/cancelled alerts so the inbox no longer points at a gone event.
        portal_notifications_unsend('events.php?event=' . $eventId, true, 'event');

        return ['ok' => true, 'error' => ''];
    }
}

if (!function_exists('portal_event_summary_stats')) {
    /**
     * @param list<array<string, mixed>> $upcoming
     * @return array{next:?array<string,mixed>,this_week:int,school:int,course:int}
     */
    function portal_event_summary_stats(array $upcoming): array
    {
        $scheduled = array_values(array_filter(
            $upcoming,
            static fn (array $e): bool => (string) ($e['status'] ?? '') !== 'cancelled'
        ));
        $weekEnd = strtotime('sunday 23:59:59') ?: strtotime('+7 days');
        $thisWeek = 0;
        $school = 0;
        $course = 0;
        foreach ($scheduled as $event) {
            $cid = isset($event['course_id']) && $event['course_id'] !== null && $event['course_id'] !== ''
                ? (int) $event['course_id']
                : 0;
            if ($cid <= 0) {
                $school++;
            } else {
                $course++;
            }
            $ts = portal_db_timestamp((string) ($event['starts_at'] ?? ''));
            if ($ts !== null && $ts <= $weekEnd) {
                $thisWeek++;
            }
        }

        return [
            'next' => $scheduled[0] ?? null,
            'this_week' => $thisWeek,
            'school' => $school,
            'course' => $course,
        ];
    }
}

if (!function_exists('portal_event_manageable_courses')) {
    /**
     * Courses the current user may attach an event to.
     *
     * @return list<array{id:int,title:string,code:string,accent:string}>
     */
    function portal_event_manageable_courses(): array
    {
        $db = portal_db();
        if (portal_is_admin()) {
            return $db->query(
                'SELECT id, title, code, accent FROM courses ORDER BY title ASC'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $ids = portal_assigned_course_ids();
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "SELECT id, title, code, accent FROM courses WHERE id IN ($placeholders) ORDER BY title ASC"
        );
        $stmt->execute($ids);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
