<?php
declare(strict_types=1);

/**
 * Admin/owner student invites — email-bound, single-use, course-locked tokens.
 */

if (!function_exists('portal_invite_hash')) {
    function portal_invite_hash(string $token): string
    {
        return hash_hmac('sha256', $token, portal_app_secret());
    }
}

if (!function_exists('portal_invite_url')) {
    function portal_invite_url(string $token): string
    {
        $base = portal_configured_base_url();
        if ($base === '') {
            $base = portal_base_url();
        }

        return rtrim($base, '/') . '/accept-invite.php?token=' . rawurlencode($token);
    }
}

if (!function_exists('portal_invite_email_url')) {
    /** Absolute URL for outbound invite mail — empty when PORTAL_BASE_URL unset. */
    function portal_invite_email_url(string $token): string
    {
        $base = portal_configured_base_url();
        if ($base === '') {
            return '';
        }

        return rtrim($base, '/') . '/accept-invite.php?token=' . rawurlencode($token);
    }
}

if (!function_exists('portal_invite_attempt_is_locked')) {
    function portal_invite_attempt_is_locked(string $ip, int $maxAttempts = 8, int $windowSeconds = 900): bool
    {
        try {
            $stmt = portal_db()->prepare(
                'SELECT COUNT(*) FROM student_invite_attempts WHERE ip = ? AND attempted_at > ?'
            );
            $stmt->execute([$ip, time() - $windowSeconds]);

            return (int) $stmt->fetchColumn() >= $maxAttempts;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('portal_invite_record_attempt')) {
    function portal_invite_record_attempt(string $ip): void
    {
        try {
            portal_db()
                ->prepare('INSERT INTO student_invite_attempts (ip, attempted_at) VALUES (?, ?)')
                ->execute([$ip, time()]);
        } catch (\Throwable $e) {
            // ignore
        }
    }
}

if (!function_exists('portal_invite_course_ids')) {
    /** @return list<int> */
    function portal_invite_course_ids(int $inviteId): array
    {
        if ($inviteId <= 0) {
            return [];
        }
        try {
            $stmt = portal_db()->prepare(
                'SELECT course_id FROM student_invite_courses WHERE invite_id = ? ORDER BY course_id ASC'
            );
            $stmt->execute([$inviteId]);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
            if ($ids !== []) {
                return $ids;
            }
            // Legacy single-course invites.
            $legacy = portal_db()->prepare('SELECT course_id FROM student_invites WHERE id = ? LIMIT 1');
            $legacy->execute([$inviteId]);
            $cid = (int) ($legacy->fetchColumn() ?: 0);

            return $cid > 0 ? [$cid] : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('portal_invite_course_summaries')) {
    /**
     * @return list<array{id:int,title:string,code:string}>
     */
    function portal_invite_course_summaries(int $inviteId): array
    {
        $ids = portal_invite_course_ids($inviteId);
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = portal_db()->prepare(
            "SELECT id, title, code FROM courses WHERE id IN ($placeholders) ORDER BY title ASC"
        );
        $stmt->execute($ids);

        return array_map(
            static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'title' => (string) $row['title'],
                    'code' => (string) $row['code'],
                ];
            },
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }
}

if (!function_exists('portal_invite_create')) {
    /**
     * @param list<int>|int $courseIds
     * @return array{ok:bool,error?:string,token?:string,invite_id?:int,email_sent?:bool}
     */
    function portal_invite_create(
        string $email,
        array|int $courseIds,
        int $invitedBy,
        string $lockedName = '',
        string $lockedYear = '',
        int $expiresDays = 7,
        string $createdIp = ''
    ): array {
        $email = strtolower(trim($email));
        $lockedName = substr(trim($lockedName), 0, 200);
        $lockedYear = substr(trim($lockedYear), 0, 40);
        $expiresDays = max(1, min(30, $expiresDays));

        if (is_int($courseIds)) {
            $courseIds = [$courseIds];
        }
        $courseIds = array_values(array_unique(array_filter(
            array_map('intval', $courseIds),
            static fn(int $id): bool => $id > 0
        )));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Enter a valid email address.'];
        }
        if ($courseIds === []) {
            return ['ok' => false, 'error' => 'Choose at least one course to enrol the student into.'];
        }
        if ($invitedBy <= 0) {
            return ['ok' => false, 'error' => 'Could not create invite.'];
        }

        $db = portal_db();
        $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
        $courseStmt = $db->prepare(
            "SELECT id, title, code FROM courses WHERE id IN ($placeholders) ORDER BY title ASC"
        );
        $courseStmt->execute($courseIds);
        $courseRows = $courseStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($courseRows) !== count($courseIds)) {
            return ['ok' => false, 'error' => 'One or more selected courses were not found.'];
        }

        $existing = $db->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
        $existing->execute([$email]);
        if ($existing->fetch()) {
            return ['ok' => false, 'error' => 'An account with that email already exists.'];
        }

        if ($lockedYear !== '' && !in_array($lockedYear, portal_year_group_options(), true)) {
            return ['ok' => false, 'error' => 'Choose a valid year group, or leave it unlocked.'];
        }

        $primaryCourseId = (int) $courseRows[0]['id'];
        $now = time();
        $db->prepare(
            "UPDATE student_invites
             SET revoked_at = ?
             WHERE email = ? AND used_at IS NULL AND revoked_at IS NULL AND expires_at > ?"
        )->execute([$now, $email, $now]);

        $token = bin2hex(random_bytes(32));
        $tokenHash = portal_invite_hash($token);
        $expiresAt = $now + ($expiresDays * 86400);

        try {
            $db->beginTransaction();
            $db->prepare(
                'INSERT INTO student_invites
                 (email, token_hash, course_id, locked_name, locked_year, invited_by,
                  expires_at, used_at, revoked_at, created_at, created_ip, accepted_ip, accepted_user_id)
                 VALUES (?,?,?,?,?,?,?,NULL,NULL,?,?,?,NULL)'
            )->execute([
                $email,
                $tokenHash,
                $primaryCourseId,
                $lockedName,
                $lockedYear,
                $invitedBy,
                $expiresAt,
                $now,
                substr($createdIp, 0, 80),
                '',
            ]);
            $inviteId = (int) $db->lastInsertId();
            $link = $db->prepare(
                'INSERT OR IGNORE INTO student_invite_courses (invite_id, course_id) VALUES (?,?)'
            );
            foreach ($courseIds as $cid) {
                $link->execute([$inviteId, $cid]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            return ['ok' => false, 'error' => 'Could not store the invite. Try again.'];
        }

        $titles = array_map(
            static fn(array $r): string => (string) $r['title'],
            $courseRows
        );
        portal_log_security_event(
            'invite_created',
            'info',
            'Student invite created for ' . $email . ' (' . count($courseIds) . ' course(s))',
            $invitedBy
        );

        $inviteRow = [
            'id' => $inviteId,
            'email' => $email,
            'course_id' => $primaryCourseId,
            'course_title' => implode(', ', $titles),
            'course_titles' => $titles,
            'locked_name' => $lockedName,
            'expires_at' => $expiresAt,
            'invited_by' => $invitedBy,
        ];
        $emailSent = portal_invite_send_email($inviteRow, $token);

        return [
            'ok' => true,
            'token' => $token,
            'invite_id' => $inviteId,
            'email_sent' => $emailSent,
        ];
    }
}

if (!function_exists('portal_invite_find_by_hash')) {
    function portal_invite_find_by_hash(string $tokenHash): ?array
    {
        if ($tokenHash === '') {
            return null;
        }
        try {
            $stmt = portal_db()->prepare(
                'SELECT i.*, c.title AS course_title, c.code AS course_code, c.slug AS course_slug
                 FROM student_invites i
                 JOIN courses c ON c.id = i.course_id
                 WHERE i.token_hash = ?
                 LIMIT 1'
            );
            $stmt->execute([$tokenHash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $summaries = portal_invite_course_summaries((int) $row['id']);
            $row['courses'] = $summaries;
            $row['course_titles'] = array_map(
                static fn(array $c): string => $c['title'],
                $summaries
            );
            if ($summaries !== []) {
                $row['course_title'] = implode(', ', $row['course_titles']);
                $row['course_code'] = implode(', ', array_map(
                    static fn(array $c): string => $c['code'],
                    $summaries
                ));
            }

            return $row;
        } catch (\Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('portal_invite_find_valid')) {
    function portal_invite_find_valid(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) < 32) {
            return null;
        }
        $row = portal_invite_find_by_hash(portal_invite_hash($token));
        if ($row === null) {
            return null;
        }
        if ($row['revoked_at'] !== null && $row['revoked_at'] !== '') {
            return null;
        }
        if ($row['used_at'] !== null && $row['used_at'] !== '') {
            return null;
        }
        if ((int) $row['expires_at'] < time()) {
            return null;
        }

        return $row;
    }
}

if (!function_exists('portal_invite_revoke')) {
    function portal_invite_revoke(int $inviteId, int $actorId): bool
    {
        if ($inviteId <= 0) {
            return false;
        }
        $now = time();
        $stmt = portal_db()->prepare(
            'UPDATE student_invites
             SET revoked_at = ?
             WHERE id = ? AND used_at IS NULL AND revoked_at IS NULL'
        );
        $stmt->execute([$now, $inviteId]);
        $ok = $stmt->rowCount() > 0;
        if ($ok) {
            portal_log_security_event(
                'invite_revoked',
                'info',
                'Student invite #' . $inviteId . ' revoked',
                $actorId
            );
        }

        return $ok;
    }
}

if (!function_exists('portal_invite_list_pending')) {
    /** @return list<array<string,mixed>> */
    function portal_invite_list_pending(): array
    {
        $now = time();
        $stmt = portal_db()->query(
            "SELECT i.*, u.name AS invited_by_name
             FROM student_invites i
             LEFT JOIN users u ON u.id = i.invited_by
             WHERE i.used_at IS NULL
               AND i.revoked_at IS NULL
               AND i.expires_at > {$now}
             ORDER BY i.created_at DESC
             LIMIT 200"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $summaries = portal_invite_course_summaries((int) $row['id']);
            $row['courses'] = $summaries;
            $row['course_titles'] = array_map(
                static fn(array $c): string => $c['title'],
                $summaries
            );
            $row['course_title'] = $summaries === []
                ? ''
                : implode(', ', $row['course_titles']);
            $row['course_code'] = $summaries === []
                ? ''
                : implode(', ', array_map(static fn(array $c): string => $c['code'], $summaries));
            $row['course_count'] = count($summaries);
        }
        unset($row);

        return $rows;
    }
}

if (!function_exists('portal_invite_send_email')) {
    /**
     * @param array<string,mixed> $invite
     */
    function portal_invite_send_email(array $invite, string $token): bool
    {
        if (!function_exists('portal_transactional_mail_ready') || !portal_transactional_mail_ready()) {
            return false;
        }
        $url = portal_invite_email_url($token);
        if ($url === '') {
            return false;
        }

        $email = strtolower(trim((string) ($invite['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $school = portal_school_name();
        $titles = $invite['course_titles'] ?? null;
        if (is_array($titles) && $titles !== []) {
            $courseLabel = count($titles) === 1
                ? (string) $titles[0]
                : (count($titles) . ' courses (' . implode(', ', $titles) . ')');
        } else {
            $courseLabel = trim((string) ($invite['course_title'] ?? 'your course'));
        }
        $nameHint = trim((string) ($invite['locked_name'] ?? ''));
        $greeting = $nameHint !== '' ? portal_escape($nameHint) : 'there';
        $expiresAt = (int) ($invite['expires_at'] ?? 0);
        $expiresLabel = $expiresAt > 0 ? date('j M Y H:i', $expiresAt) : 'soon';

        $subject = 'You are invited to ' . $school;
        $intro = '<p>You have been invited to join <strong>' . portal_escape($courseLabel)
            . '</strong> on the ' . portal_escape($school) . ' portal.</p>'
            . '<p>This invite is for <strong>' . portal_escape($email) . '</strong> only. '
            . 'It expires on ' . portal_escape($expiresLabel) . '.</p>';

        $html = '<p>Hi ' . $greeting . ',</p>'
            . $intro
            . '<p><a href="' . portal_escape($url) . '">Create your account</a></p>'
            . '<p style="color:#666;font-size:13px;">If you were not expecting this, you can ignore this email.</p>';

        $text = "Hi " . ($nameHint !== '' ? $nameHint : 'there') . ",\n\n"
            . "You have been invited to join {$courseLabel} on the {$school} portal.\n"
            . "This invite is for {$email} only. It expires on {$expiresLabel}.\n\n"
            . "Create your account:\n{$url}\n\n"
            . "If you were not expecting this, you can ignore this email.\n";

        $ok = portal_mail_send($email, $subject, $html, $text);
        if ($ok) {
            portal_log_security_event(
                'invite_email_sent',
                'info',
                'Invite email sent to ' . $email,
                (int) ($invite['invited_by'] ?? 0) ?: null
            );
        }

        return $ok;
    }
}

if (!function_exists('portal_invite_accept')) {
    /**
     * @param array{
     *   email?:string,
     *   username?:string,
     *   password?:string,
     *   name?:string,
     *   year?:string
     * } $payload
     * @return array{ok:bool,error?:string,user_id?:int}
     */
    function portal_invite_accept(string $token, array $payload, string $ip = ''): array
    {
        $ip = substr(trim($ip), 0, 80);
        if (portal_invite_attempt_is_locked($ip)) {
            portal_log_security_event(
                'invite_accept_failed',
                'medium',
                'Invite accept throttled for IP'
            );

            return ['ok' => false, 'error' => 'Too many attempts. Please wait about 15 minutes and try again.'];
        }

        $invite = portal_invite_find_valid($token);
        if ($invite === null) {
            portal_invite_record_attempt($ip);
            portal_log_security_event(
                'invite_accept_failed',
                'medium',
                'Invalid or expired invite token'
            );

            return ['ok' => false, 'error' => 'This invite link is invalid or has expired.'];
        }

        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $username = trim((string) ($payload['username'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        $name = trim((string) ($payload['name'] ?? ''));
        $year = trim((string) ($payload['year'] ?? ''));

        $boundEmail = strtolower(trim((string) ($invite['email'] ?? '')));
        if ($email === '' || $email !== $boundEmail) {
            portal_invite_record_attempt($ip);
            portal_log_security_event(
                'invite_accept_failed',
                'high',
                'Invite email mismatch for invite #' . (int) $invite['id']
            );

            return ['ok' => false, 'error' => 'Use the email address this invite was sent to.'];
        }

        $lockedName = trim((string) ($invite['locked_name'] ?? ''));
        $lockedYear = trim((string) ($invite['locked_year'] ?? ''));
        if ($lockedName !== '') {
            $name = $lockedName;
        }
        if ($lockedYear !== '') {
            $year = $lockedYear;
        }
        if ($year === '') {
            $year = 'Year 11';
        }
        if (!in_array($year, portal_year_group_options(), true)) {
            return ['ok' => false, 'error' => 'Choose a valid year group.'];
        }

        if ($username === '' || $name === '') {
            return ['ok' => false, 'error' => 'Name and username are required.'];
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $username)) {
            return ['ok' => false, 'error' => 'Username must be 3–40 characters (letters, numbers, . _ -).'];
        }

        $passError = portal_password_validate($password);
        if ($passError !== '') {
            return ['ok' => false, 'error' => $passError];
        }

        $db = portal_db();
        $dup = $db->prepare(
            'SELECT id FROM users WHERE LOWER(email) = ? OR LOWER(username) = ? LIMIT 1'
        );
        $dup->execute([$email, strtolower($username)]);
        if ($dup->fetch()) {
            portal_invite_record_attempt($ip);

            return ['ok' => false, 'error' => 'That username or email is already in use.'];
        }

        $courseIds = portal_invite_course_ids((int) $invite['id']);
        if ($courseIds === []) {
            return ['ok' => false, 'error' => 'The course for this invite is no longer available.'];
        }
        $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
        $courseChk = $db->prepare("SELECT COUNT(*) FROM courses WHERE id IN ($placeholders)");
        $courseChk->execute($courseIds);
        if ((int) $courseChk->fetchColumn() !== count($courseIds)) {
            return ['ok' => false, 'error' => 'One or more courses for this invite are no longer available.'];
        }

        $parts = preg_split('/\s+/', $name) ?: [];
        $initials = strtoupper(substr($parts[0] ?? 'S', 0, 1) . substr($parts[1] ?? 'T', 0, 1));
        $now = time();

        try {
            $db->beginTransaction();

            // Re-check invite under lock.
            $lock = $db->prepare(
                'SELECT id FROM student_invites
                 WHERE id = ? AND used_at IS NULL AND revoked_at IS NULL AND expires_at > ?
                 LIMIT 1'
            );
            $lock->execute([(int) $invite['id'], $now]);
            if (!$lock->fetch()) {
                $db->rollBack();
                portal_invite_record_attempt($ip);

                return ['ok' => false, 'error' => 'This invite link is invalid or has expired.'];
            }

            $db->prepare(
                'INSERT INTO users (username, email, password_hash, name, year, programme, initials, role)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                $username,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                $name,
                $year,
                'General',
                $initials,
                'student',
            ]);
            $userId = (int) $db->lastInsertId();

            $enrol = $db->prepare('INSERT OR IGNORE INTO enrollments (user_id, course_id) VALUES (?,?)');
            foreach ($courseIds as $cid) {
                $enrol->execute([$userId, $cid]);
            }

            $db->prepare(
                'UPDATE student_invites
                 SET used_at = ?, accepted_ip = ?, accepted_user_id = ?
                 WHERE id = ?'
            )->execute([$now, $ip, $userId, (int) $invite['id']]);

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            portal_invite_record_attempt($ip);
            $msg = str_contains($e->getMessage(), 'UNIQUE')
                ? 'That username or email is already in use.'
                : 'Could not create your account. Please try again.';

            return ['ok' => false, 'error' => $msg];
        }

        portal_log_security_event(
            'invite_accepted',
            'info',
            'Student invite #' . (int) $invite['id'] . ' accepted; user #' . $userId . ' created',
            $userId
        );

        if (PHP_SAPI === 'cli') {
            return ['ok' => true, 'user_id' => $userId];
        }

        if (!portal_attempt_login($username, $password)) {
            return [
                'ok' => true,
                'user_id' => $userId,
                'error' => 'Account created, but automatic sign-in failed. Please sign in.',
            ];
        }

        return ['ok' => true, 'user_id' => $userId];
    }
}
