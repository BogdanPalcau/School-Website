<?php
declare(strict_types=1);

/**
 * Transactional notification emails (grades + deadline reminders).
 * Relies on mailer.php + PORTAL_BASE_URL (same fail-closed rules as password reset).
 */

if (!function_exists('portal_transactional_mail_ready')) {
    function portal_transactional_mail_ready(): bool
    {
        return function_exists('portal_mail_configured')
            && portal_mail_configured()
            && function_exists('portal_configured_base_url')
            && portal_configured_base_url() !== '';
    }
}

if (!function_exists('portal_mail_app_url')) {
    /** Build an absolute portal URL from a public/ relative path (e.g. grades.php). */
    function portal_mail_app_url(string $relativePath): string
    {
        $base = portal_configured_base_url();
        if ($base === '') {
            return '';
        }
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

        return $base . '/' . $relativePath;
    }
}

if (!function_exists('portal_mail_claim_send')) {
    /**
     * Claim a one-time mail slot. Returns true only on first claim for this key.
     */
    function portal_mail_claim_send(int $userId, string $kind, string $refKey): bool
    {
        if ($userId <= 0 || $kind === '' || $refKey === '') {
            return false;
        }

        try {
            $stmt = portal_db()->prepare(
                'INSERT OR IGNORE INTO portal_mail_sent (user_id, kind, ref_key, sent_at)
                 VALUES (?, ?, ?, datetime(\'now\'))'
            );
            $stmt->execute([$userId, substr($kind, 0, 80), substr($refKey, 0, 200)]);

            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('portal_mail_user_is_emailable')) {
    function portal_mail_user_is_emailable(?array $user): bool
    {
        if ($user === null) {
            return false;
        }
        $status = function_exists('portal_user_account_status')
            ? portal_user_account_status($user)
            : 'active';
        if ($status === 'banned') {
            return false;
        }
        $email = strtolower(trim((string) ($user['email'] ?? '')));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}

if (!function_exists('portal_mail_send_user_notice')) {
    /**
     * Send a simple branded notice. Returns false when mail is not ready or delivery fails.
     */
    function portal_mail_send_user_notice(
        array $user,
        string $subject,
        string $introHtml,
        string $ctaRelativePath,
        string $ctaLabel = 'Open in portal'
    ): bool {
        if (!portal_transactional_mail_ready() || !portal_mail_user_is_emailable($user)) {
            return false;
        }

        $url = portal_mail_app_url($ctaRelativePath);
        if ($url === '') {
            return false;
        }

        $school = portal_school_name();
        $name = trim((string) ($user['name'] ?? '')) ?: 'there';
        $safeName = portal_escape($name);
        $safeSchool = portal_escape($school);
        $safeSubject = portal_escape($subject);
        $safeUrl = portal_escape($url);
        $safeCta = portal_escape($ctaLabel);

        $html = '<p>Hi ' . $safeName . ',</p>'
            . $introHtml
            . '<p><a href="' . $safeUrl . '">' . $safeCta . '</a></p>'
            . '<p style="color:#666;font-size:13px;">You received this because of your notification settings on '
            . $safeSchool . '. Change them under Settings → Notifications.</p>';

        $textIntro = portal_mail_html_to_text($introHtml);
        $text = "Hi {$name},\n\n{$textIntro}\n\n{$ctaLabel}:\n{$url}\n\n"
            . "— {$school}\n";

        return portal_mail_send((string) $user['email'], $subject, $html, $text);
    }
}

if (!function_exists('portal_notify_grade_returned')) {
    /**
     * In-app grade alert + optional email (when SMTP + PORTAL_BASE_URL are set).
     *
     * @param string $dedupeRef Stable key for email dedupe (e.g. submission:12:88)
     */
    function portal_notify_grade_returned(
        int $userId,
        int $courseId,
        string $title,
        string $body,
        string $link,
        string $dedupeRef
    ): void {
        if ($userId <= 0) {
            return;
        }

        portal_notify_user($userId, 'grade', $title, $body, $link, $courseId);

        $prefs = portal_user_preferences($userId);
        if (empty($prefs['notify_grades'])) {
            return;
        }
        if (!portal_mail_claim_send($userId, 'grade', $dedupeRef)) {
            return;
        }

        $user = portal_find_user_by_id($userId);
        if ($user === null) {
            return;
        }

        $intro = '<p>' . portal_escape($body !== '' ? $body : $title) . '</p>';
        portal_mail_send_user_notice(
            $user,
            $title,
            $intro,
            $link !== '' ? $link : 'grades.php',
            'View your grade'
        );
    }
}

if (!function_exists('portal_send_deadline_reminder_emails')) {
    /**
     * Email students about submission deadlines falling within the next $withinHours.
     * Skips students who already submitted, opted out, or were already reminded for the slot.
     *
     * @return array{scanned:int,sent:int,skipped:int,errors:int}
     */
    function portal_send_deadline_reminder_emails(int $withinHours = 24): array
    {
        $stats = ['scanned' => 0, 'sent' => 0, 'skipped' => 0, 'errors' => 0];
        $withinHours = max(1, min(72, $withinHours));

        if (!portal_transactional_mail_ready()) {
            return $stats;
        }

        $db = portal_db();
        $now = time();
        $horizon = $now + ($withinHours * 3600);
        $nowSql = date('Y-m-d H:i:s', $now);
        $horizonSql = date('Y-m-d H:i:s', $horizon);

        $slots = $db->prepare(
            "SELECT cfi.id AS item_id, cfi.title AS item_title, cfi.submission_deadline,
                    c.id AS course_id, c.slug AS course_slug, c.title AS course_title
             FROM course_folder_items cfi
             JOIN courses c ON c.id = cfi.course_id
             WHERE cfi.type = 'submission'
               AND cfi.submission_deadline != ''
               AND cfi.submission_deadline > ?
               AND cfi.submission_deadline <= ?
               AND COALESCE(cfi.locked, 0) = 0
               AND LOWER(COALESCE(c.status, 'open')) = 'open'"
        );
        $slots->execute([$nowSql, $horizonSql]);
        $items = $slots->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $enrolled = $db->prepare(
            "SELECT u.id, u.name, u.email, u.account_status
             FROM enrollments e
             JOIN users u ON u.id = e.user_id
             WHERE e.course_id = ?
               AND u.role = 'student'"
        );
        $hasSubmission = $db->prepare(
            'SELECT 1 FROM course_submissions WHERE item_id = ? AND user_id = ? LIMIT 1'
        );

        foreach ($items as $item) {
            $itemId = (int) ($item['item_id'] ?? 0);
            $courseId = (int) ($item['course_id'] ?? 0);
            if ($itemId <= 0 || $courseId <= 0) {
                continue;
            }

            $deadlineRaw = (string) ($item['submission_deadline'] ?? '');
            $deadlineInfo = portal_submission_deadline_info($deadlineRaw);
            if (!$deadlineInfo['has_deadline'] || !empty($deadlineInfo['passed'])) {
                continue;
            }

            $enrolled->execute([$courseId]);
            $students = $enrolled->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($students as $student) {
                $stats['scanned']++;
                $uid = (int) ($student['id'] ?? 0);
                if ($uid <= 0 || !portal_mail_user_is_emailable($student)) {
                    $stats['skipped']++;
                    continue;
                }

                $prefs = portal_user_preferences($uid);
                if (empty($prefs['notify_deadlines'])) {
                    $stats['skipped']++;
                    continue;
                }

                $hasSubmission->execute([$itemId, $uid]);
                if ($hasSubmission->fetchColumn()) {
                    $stats['skipped']++;
                    continue;
                }

                $ref = 'item:' . $itemId;
                if (!portal_mail_claim_send($uid, 'deadline_24h', $ref)) {
                    $stats['skipped']++;
                    continue;
                }

                $courseTitle = (string) ($item['course_title'] ?? 'your course');
                $itemTitle = (string) ($item['item_title'] ?? 'Assignment');
                $dueText = (string) ($deadlineInfo['text'] ?? $deadlineRaw);
                $slug = (string) ($item['course_slug'] ?? '');
                $link = 'course.php?course=' . rawurlencode($slug) . '&section=content';

                $subject = 'Due soon: ' . $itemTitle;
                $intro = '<p><strong>' . portal_escape($itemTitle) . '</strong> in '
                    . portal_escape($courseTitle) . ' is due <strong>'
                    . portal_escape($dueText) . '</strong>.</p>'
                    . '<p>You have not submitted yet. Open the course to upload or paste your work.</p>';

                $ok = portal_mail_send_user_notice(
                    $student,
                    $subject,
                    $intro,
                    $link,
                    'Open assignment'
                );
                if ($ok) {
                    $stats['sent']++;
                } else {
                    $stats['errors']++;
                }
            }
        }

        return $stats;
    }
}

if (!function_exists('portal_notify_activity_grades_released')) {
    /**
     * Notify students whose attempts were just released (in-app + email).
     *
     * @param list<array{user_id:int|string,id?:int|string,percentage?:mixed}> $attempts
     */
    function portal_notify_activity_grades_released(
        array $activity,
        array $course,
        array $attempts
    ): void {
        $courseId = (int) ($activity['course_id'] ?? ($course['id'] ?? 0));
        $activityId = (int) ($activity['id'] ?? 0);
        $title = trim((string) ($activity['title'] ?? 'Activity'));
        if ($title === '') {
            $title = 'Activity';
        }
        $slug = (string) ($course['slug'] ?? '');
        $link = $activityId > 0
            ? 'activity.php?id=' . $activityId
            : ('course.php?course=' . rawurlencode($slug) . '&section=gradebook');

        $seen = [];
        foreach ($attempts as $attempt) {
            $uid = (int) ($attempt['user_id'] ?? 0);
            if ($uid <= 0 || isset($seen[$uid])) {
                continue;
            }
            $seen[$uid] = true;
            $pct = $attempt['percentage'] ?? null;
            $scoreBit = ($pct !== null && $pct !== '')
                ? (' Your result: ' . (int) round((float) $pct) . '%.')
                : '';
            $attemptId = (int) ($attempt['id'] ?? 0);
            portal_notify_grade_returned(
                $uid,
                $courseId,
                'Grade released: ' . $title,
                'Results for ' . $title . ' are now available.' . $scoreBit,
                $link,
                'activity:' . $activityId . ':user:' . $uid . ':attempt:' . $attemptId
            );
        }
    }
}
