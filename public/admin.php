<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../course_catalog.php';

portal_require_admin();

$currentUser = portal_current_user();
$isOwner     = portal_is_owner();
$pdo         = portal_db();

$adminSections = ['dashboard', 'users', 'courses', 'enrollments', 'invites', 'activities', 'integrity', 'security'];
$section       = (string) ($_GET['section'] ?? 'dashboard');
if (!in_array($section, $adminSections, true)) {
    $section = 'dashboard';
}

$enrollTargetId = (int) ($_GET['enroll'] ?? 0);
if ($enrollTargetId > 0 && !isset($_GET['section'])) {
    $section = 'enrollments';
}

$editCourseId      = (int) ($_GET['edit'] ?? 0);
$duplicateCourseId = (int) ($_GET['duplicate'] ?? 0);
$editUserId        = (int) ($_GET['edit_user'] ?? 0);
if ($editCourseId > 0 || $duplicateCourseId > 0) {
    $section = 'courses';
}
if ($editUserId > 0) {
    $section = 'users';
}

$redirectSection = static function (string $targetSection, array $extra = []) use ($section): void {
    $params = array_merge(['section' => $targetSection], $extra);
    portal_redirect('admin.php?' . http_build_query($params));
};

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!portal_verify_csrf()) {
        $_SESSION['admin_flash'] = ['error', 'Your session expired. Please try that again.'];
        portal_redirect('admin.php?section=' . urlencode($section));
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_user') {
        $username  = trim((string) ($_POST['username'] ?? ''));
        $email     = strtolower(trim((string) ($_POST['email'] ?? '')));
        $name      = trim((string) ($_POST['name'] ?? ''));
        $year      = trim((string) ($_POST['year'] ?? 'Year 11'));
        $password  = (string) ($_POST['password'] ?? '');
        $newRole   = (string) ($_POST['role'] ?? 'student');

        if ($isOwner) {
            if (!in_array($newRole, ['admin', 'teacher', 'student'], true)) {
                $newRole = 'student';
            }
        } elseif (!in_array($newRole, ['teacher', 'student'], true)) {
            $newRole = 'student';
        }

        $parts    = preg_split('/\s+/', $name) ?: [];
        $initials = strtoupper(substr($parts[0] ?? 'S', 0, 1) . substr($parts[1] ?? 'T', 0, 1));
        $passError = portal_password_validate($password);

        if ($username === '' || $email === '' || $name === '' || $password === '') {
            $_SESSION['admin_flash'] = ['error', 'All fields are required.'];
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['admin_flash'] = ['error', 'Enter a valid email address.'];
        } elseif ($passError !== '') {
            $_SESSION['admin_flash'] = ['error', $passError];
        } else {
            try {
                $pdo->prepare("
                    INSERT INTO users (username, email, password_hash, name, year, programme, initials, role)
                    VALUES (?,?,?,?,?,?,?,?)
                ")->execute([
                    $username, $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $name, $year, 'General', $initials, $newRole,
                ]);
                $_SESSION['admin_flash'] = ['success', "Account for {$name} created successfully."];
            } catch (\PDOException $e) {
                $msg = str_contains($e->getMessage(), 'UNIQUE') ? 'Username or email already in use.' : 'Could not create account.';
                $_SESSION['admin_flash'] = ['error', $msg];
            }
        }
        $redirectSection('users');
    }

    if ($action === 'update_user') {
        $targetId  = (int) ($_POST['user_id'] ?? 0);
        $target    = portal_find_user_by_id($targetId);
        $username  = trim((string) ($_POST['username'] ?? ''));
        $email     = strtolower(trim((string) ($_POST['email'] ?? '')));
        $name      = trim((string) ($_POST['name'] ?? ''));
        $year      = trim((string) ($_POST['year'] ?? 'Year 11'));
        $newRole   = (string) ($_POST['role'] ?? ($target['role'] ?? 'student'));
        $newPass   = (string) ($_POST['new_password'] ?? '');
        $confirmPass = (string) ($_POST['confirm_password'] ?? '');

        $canManage = $target !== null
            && (int) $target['id'] !== (int) $currentUser['id']
            && $target['role'] !== 'owner'
            && ($isOwner || !in_array($target['role'], ['admin', 'owner'], true));

        if (!$canManage) {
            $_SESSION['admin_flash'] = ['error', 'You cannot edit that account.'];
            $redirectSection('users');
        }

        if ($isOwner) {
            if (!in_array($newRole, ['admin', 'teacher', 'student'], true)) {
                $newRole = (string) $target['role'];
            }
        } else {
            // Admins may only keep/switch student ↔ teacher
            if (!in_array($newRole, ['teacher', 'student'], true)) {
                $newRole = in_array($target['role'], ['teacher', 'student'], true)
                    ? (string) $target['role']
                    : 'student';
            }
        }

        $parts    = preg_split('/\s+/', $name) ?: [];
        $initials = strtoupper(substr($parts[0] ?? 'S', 0, 1) . substr($parts[1] ?? 'T', 0, 1));

        if ($username === '' || $email === '' || $name === '') {
            $_SESSION['admin_flash'] = ['error', 'Name, username, and email are required.'];
            $redirectSection('users', ['edit_user' => $targetId]);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['admin_flash'] = ['error', 'Enter a valid email address.'];
            $redirectSection('users', ['edit_user' => $targetId]);
        }

        $dup = $pdo->prepare('SELECT id FROM users WHERE (LOWER(email) = ? OR LOWER(username) = ?) AND id != ? LIMIT 1');
        $dup->execute([$email, strtolower($username), $targetId]);
        if ($dup->fetch()) {
            $_SESSION['admin_flash'] = ['error', 'That username or email is already used by another account.'];
            $redirectSection('users', ['edit_user' => $targetId]);
        }

        $passwordChanged = false;
        if ($newPass !== '' || $confirmPass !== '') {
            $passError = portal_password_validate($newPass);
            if ($passError !== '') {
                $_SESSION['admin_flash'] = ['error', $passError];
                $redirectSection('users', ['edit_user' => $targetId]);
            }
            if ($newPass !== $confirmPass) {
                $_SESSION['admin_flash'] = ['error', 'New passwords do not match.'];
                $redirectSection('users', ['edit_user' => $targetId]);
            }
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($newPass, PASSWORD_DEFAULT), $targetId]);
            $passwordChanged = true;
        }

        $pdo->prepare('UPDATE users SET username = ?, email = ?, name = ?, year = ?, initials = ?, role = ? WHERE id = ?')
            ->execute([$username, $email, $name, $year, $initials, $newRole, $targetId]);

        $oldRole = (string) ($target['role'] ?? '');
        if ($oldRole === 'student' && $newRole === 'teacher') {
            portal_set_user_course_access($targetId, portal_enrolled_course_ids($targetId));
        } elseif ($oldRole === 'teacher' && $newRole === 'student') {
            portal_set_user_course_access($targetId, portal_assigned_course_ids_for_user($targetId));
        }

        $notes = [];
        if ($passwordChanged) {
            $notes[] = 'password reset';
        }
        if ($newRole !== (string) $target['role']) {
            $notes[] = 'role → ' . $newRole;
        }
        portal_log_security_event(
            'user_updated',
            'medium',
            'Updated account: ' . substr($name, 0, 80) . ($notes !== [] ? ' (' . implode(', ', $notes) . ')' : ''),
            (int) $currentUser['id']
        );

        $_SESSION['admin_flash'] = ['success', $passwordChanged
            ? "{$name}'s account and password were updated."
            : "{$name}'s account was updated."];
        $redirectSection('users');
    }

    if ($action === 'delete_user') {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $target   = portal_find_user_by_id($targetId);

        if (!$target) {
            $_SESSION['admin_flash'] = ['error', 'User not found.'];
        } elseif ($targetId === (int) $currentUser['id']) {
            $_SESSION['admin_flash'] = ['error', 'You cannot delete your own account.'];
        } elseif ($target['role'] === 'owner') {
            $_SESSION['admin_flash'] = ['error', 'Owner accounts cannot be deleted.'];
        } elseif ($target['role'] === 'admin' && !$isOwner) {
            $_SESSION['admin_flash'] = ['error', 'Only the owner can delete admin accounts.'];
        } else {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$targetId]);
            portal_log_security_event(
                'user_deleted',
                'medium',
                'Deleted account: ' . substr((string) $target['name'], 0, 80),
                (int) $currentUser['id']
            );
            $_SESSION['admin_flash'] = ['success', "Account for {$target['name']} deleted."];
        }
        $redirectSection('users');
    }

    if ($action === 'change_role') {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $newRole  = (string) ($_POST['role'] ?? '');
        $target   = portal_find_user_by_id($targetId);

        $canChange = $target !== null
            && $targetId !== (int) $currentUser['id']
            && $target['role'] !== 'owner'
            && ($isOwner || !in_array($target['role'], ['admin', 'owner'], true));

        if (!$canChange) {
            $_SESSION['admin_flash'] = ['error', 'You cannot change that role.'];
        } elseif ($isOwner && !in_array($newRole, ['admin', 'teacher', 'student'], true)) {
            $_SESSION['admin_flash'] = ['error', 'Invalid role.'];
        } elseif (!$isOwner && !in_array($newRole, ['teacher', 'student'], true)) {
            $_SESSION['admin_flash'] = ['error', 'Admins can only set Student or Teacher roles.'];
        } else {
            $oldRole = (string) ($target['role'] ?? '');
            $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$newRole, $targetId]);
            // Student → teacher: move enrolments into course assignments so Courses lists them.
            if ($oldRole === 'student' && $newRole === 'teacher') {
                portal_set_user_course_access($targetId, portal_enrolled_course_ids($targetId));
            } elseif ($oldRole === 'teacher' && $newRole === 'student') {
                portal_set_user_course_access($targetId, portal_assigned_course_ids_for_user($targetId));
            }
            portal_log_security_event(
                'role_changed',
                'medium',
                "{$target['name']}'s role changed to {$newRole}",
                (int) $currentUser['id']
            );
            $_SESSION['admin_flash'] = ['success', "{$target['name']}'s role updated to {$newRole}."];
        }
        $redirectSection('users');
    }

    if ($action === 'save_enrollments') {
        $targetId  = (int) ($_POST['user_id'] ?? 0);
        $courseIds = array_map('intval', (array) ($_POST['course_ids'] ?? []));
        $target    = portal_find_user_by_id($targetId);

        if (!$target) {
            $_SESSION['admin_flash'] = ['error', 'User not found.'];
        } elseif (!portal_set_user_course_access($targetId, $courseIds)) {
            $_SESSION['admin_flash'] = ['error', 'Could not save course access.'];
        } else {
            $label = ((string) ($target['role'] ?? '')) === 'teacher'
                ? 'Course assignments'
                : 'Enrolments';
            $_SESSION['admin_flash'] = ['success', "{$label} for {$target['name']} saved."];
        }
        $redirectSection('enrollments', ['enroll' => $targetId > 0 ? $targetId : null]);
    }

    if ($action === 'create_invite') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $courseIds = array_map('intval', (array) ($_POST['course_ids'] ?? []));
        // Back-compat if an older form posts course_id.
        $legacyCourse = (int) ($_POST['course_id'] ?? 0);
        if ($legacyCourse > 0) {
            $courseIds[] = $legacyCourse;
        }
        $lockedName = trim((string) ($_POST['locked_name'] ?? ''));
        $lockedYear = trim((string) ($_POST['locked_year'] ?? ''));
        $expiresDays = (int) ($_POST['expires_days'] ?? 7);
        $result = portal_invite_create(
            $email,
            $courseIds,
            (int) ($currentUser['id'] ?? 0),
            $lockedName,
            $lockedYear,
            $expiresDays,
            portal_client_ip()
        );
        if (empty($result['ok'])) {
            $_SESSION['admin_flash'] = ['error', (string) ($result['error'] ?? 'Could not create invite.')];
        } else {
            $token = (string) ($result['token'] ?? '');
            $_SESSION['admin_invite_link'] = portal_invite_url($token);
            $mailNote = !empty($result['email_sent'])
                ? ' Invite email sent.'
                : ' Email not sent (configure SMTP + PORTAL_BASE_URL). Copy the link below.';
            $count = count(array_unique(array_filter($courseIds)));
            $_SESSION['admin_flash'] = [
                'success',
                'Invite created for ' . $email . ' (' . $count . ' course' . ($count === 1 ? '' : 's') . ').' . $mailNote,
            ];
        }
        $redirectSection('invites');
    }

    if ($action === 'revoke_invite') {
        $inviteId = (int) ($_POST['invite_id'] ?? 0);
        if (portal_invite_revoke($inviteId, (int) ($currentUser['id'] ?? 0))) {
            $_SESSION['admin_flash'] = ['success', 'Invite revoked.'];
        } else {
            $_SESSION['admin_flash'] = ['error', 'Could not revoke that invite (it may already be used or revoked).'];
        }
        $redirectSection('invites');
    }

    if ($action === 'create_course') {
        $title       = trim((string) ($_POST['title'] ?? ''));
        $slug        = strtolower(trim((string) ($_POST['slug'] ?? '')));
        $summary     = trim((string) ($_POST['summary'] ?? ''));
        $yearGroup   = trim((string) ($_POST['year_group'] ?? ''));
        $term        = trim((string) ($_POST['term'] ?? 'Full year'));
        $status      = portal_course_normalize_status((string) ($_POST['status'] ?? 'draft'));
        $statusLabel = portal_course_status_label($status);
        $opensAt     = $status === 'closed'
            ? portal_course_normalize_opens_at((string) ($_POST['opens_at'] ?? ''))
            : '';
        $archivesAt  = $status === 'open'
            ? portal_course_normalize_opens_at((string) ($_POST['archives_at'] ?? ''))
            : '';
        $accent      = trim((string) ($_POST['accent'] ?? '#c1202f'));
        $notice      = trim((string) ($_POST['notice'] ?? ''));

        if ($title === '') {
            $_SESSION['admin_flash'] = ['error', 'Course title is required.'];
        } elseif (!portal_academic_year_allowed($yearGroup)) {
            $_SESSION['admin_flash'] = ['error', 'Choose the current academic year or an upcoming one.'];
        } elseif (!portal_valid_course_accent($accent)) {
            $_SESSION['admin_flash'] = ['error', 'Accent must be a valid hex colour like #c1202f.'];
        } else {
            $fullTitle = portal_course_full_title($title, $yearGroup);
            $code = portal_assign_course_code($title, $yearGroup);
            $slug = portal_resolve_course_slug($slug, $title, $yearGroup);
            try {
                $pdo->prepare("
                    INSERT INTO courses
                        (slug, code, title, full_title, summary, year_group, term, status, status_label,
                         accent, meeting, room, notice, student_count, opens_at, archives_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,?)
                ")->execute([
                    $slug, $code, $title, $fullTitle, $summary, $yearGroup, $term,
                    $status, $statusLabel, $accent, 'No schedule yet', 'Location TBA', $notice, $opensAt, $archivesAt,
                ]);
                $newCourseId = (int) $pdo->lastInsertId();
                if ($newCourseId > 0) {
                    portal_save_course_schedule_from_post($newCourseId, $_POST);
                }
                $_SESSION['admin_flash'] = ['success', "Course “{$title}” created."];
                portal_course_apply_scheduled_opens();
            } catch (\PDOException) {
                $_SESSION['admin_flash'] = ['error', 'Could not create course. Check slug and code are unique.'];
            }
        }
        $redirectSection('courses');
    }

    if ($action === 'update_course') {
        $courseId    = (int) ($_POST['course_id'] ?? 0);
        $course      = portal_find_course_by_id($courseId);
        $title       = trim((string) ($_POST['title'] ?? ''));
        $summary     = trim((string) ($_POST['summary'] ?? ''));
        $yearGroup   = trim((string) ($_POST['year_group'] ?? ''));
        $term        = trim((string) ($_POST['term'] ?? ''));
        $status      = portal_course_normalize_status((string) ($_POST['status'] ?? 'draft'));
        $statusLabel = portal_course_status_label($status);
        $opensAt     = $status === 'closed'
            ? portal_course_normalize_opens_at((string) ($_POST['opens_at'] ?? ''))
            : (is_array($course) ? trim((string) ($course['opens_at'] ?? '')) : '');
        $archivesAt  = $status === 'open'
            ? portal_course_normalize_opens_at((string) ($_POST['archives_at'] ?? ''))
            : (is_array($course) ? trim((string) ($course['archives_at'] ?? '')) : '');
        $accent      = trim((string) ($_POST['accent'] ?? '#c1202f'));
        $notice      = trim((string) ($_POST['notice'] ?? ''));

        if (!$course) {
            $_SESSION['admin_flash'] = ['error', 'Course not found.'];
        } elseif ($title === '') {
            $_SESSION['admin_flash'] = ['error', 'Course title is required.'];
        } elseif (!portal_academic_year_allowed($yearGroup, (string) ($course['year_group'] ?? ''))) {
            $_SESSION['admin_flash'] = ['error', 'Choose the current academic year or an upcoming one.'];
        } elseif (!portal_valid_course_accent($accent)) {
            $_SESSION['admin_flash'] = ['error', 'Accent must be a valid hex colour like #c1202f.'];
        } else {
            $fullTitle = portal_course_full_title($title, $yearGroup);
            $code = portal_assign_course_code($title, $yearGroup, (string) ($course['code'] ?? ''), $courseId);
            $pdo->prepare("
                UPDATE courses SET
                    title = ?, full_title = ?, code = ?, summary = ?, year_group = ?, term = ?,
                    status = ?, status_label = ?, accent = ?, notice = ?, opens_at = ?, archives_at = ?
                WHERE id = ?
            ")->execute([
                $title, $fullTitle, $code, $summary, $yearGroup, $term,
                $status, $statusLabel, $accent, $notice, $opensAt, $archivesAt, $courseId,
            ]);
            portal_save_course_schedule_from_post($courseId, $_POST);
            $_SESSION['admin_flash'] = ['success', "Course “{$title}” updated."];
            portal_course_apply_scheduled_opens();
        }
        $redirectSection('courses');
    }

    if ($action === 'archive_course') {
        $courseId = (int) ($_POST['course_id'] ?? 0);
        $courseRow = portal_find_course_by_id($courseId);
        if (!$courseRow) {
            $_SESSION['admin_flash'] = ['error', 'Course not found.'];
        } else {
            $pdo->prepare("UPDATE courses SET status = 'archived', status_label = 'Archived' WHERE id = ?")
                ->execute([$courseId]);
            portal_log_security_event(
                'course_archived',
                'info',
                'Archived course: ' . substr((string) $courseRow['title'], 0, 80),
                (int) $currentUser['id']
            );
            $_SESSION['admin_flash'] = ['success', 'Course archived. All data was kept.'];
        }
        $redirectSection('courses');
    }

    if ($action === 'restore_course') {
        $courseId = (int) ($_POST['course_id'] ?? 0);
        $courseRow = portal_find_course_by_id($courseId);
        if (!$courseRow) {
            $_SESSION['admin_flash'] = ['error', 'Course not found.'];
        } else {
            $pdo->prepare("UPDATE courses SET status = 'open', status_label = 'Open' WHERE id = ?")
                ->execute([$courseId]);
            portal_log_security_event(
                'course_restored',
                'info',
                'Restored course: ' . substr((string) $courseRow['title'], 0, 80),
                (int) $currentUser['id']
            );
            $_SESSION['admin_flash'] = ['success', 'Course restored and marked as open.'];
        }
        $redirectSection('courses');
    }

    if ($action === 'duplicate_course') {
        $sourceId  = (int) ($_POST['source_course_id'] ?? 0);
        $source    = portal_find_course_by_id($sourceId);
        $title     = trim((string) ($_POST['title'] ?? ''));
        $postedSlug = strtolower(trim((string) ($_POST['slug'] ?? '')));

        if (!$source) {
            $_SESSION['admin_flash'] = ['error', 'Source course not found.'];
        } elseif ($title === '') {
            $_SESSION['admin_flash'] = ['error', 'Course title is required.'];
        } else {
            $yearGroup = portal_academic_year_allowed((string) ($source['year_group'] ?? ''))
                ? (string) $source['year_group']
                : portal_academic_year_current();
            $fullTitle = portal_course_full_title($title, $yearGroup);
            $code = portal_assign_course_code($title, $yearGroup);
            $slug = portal_resolve_course_slug($postedSlug, $title, $yearGroup);
            try {
                $pdo->prepare("
                    INSERT INTO courses
                        (slug, code, title, full_title, summary, year_group, term, status, status_label,
                         accent, meeting, room, notice, student_count)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0)
                ")->execute([
                    $slug,
                    $code,
                    $title,
                    $fullTitle,
                    (string) $source['summary'],
                    $yearGroup,
                    (string) $source['term'],
                    'draft',
                    'Draft',
                    (string) $source['accent'],
                    (string) $source['meeting'],
                    (string) $source['room'],
                    (string) $source['notice'],
                ]);
                $_SESSION['admin_flash'] = ['success', "Course duplicated as “{$title}”."];
            } catch (\PDOException) {
                $_SESSION['admin_flash'] = ['error', 'Could not duplicate course.'];
            }
        }
        $redirectSection('courses');
    }

    if ($action === 'delete_course' && $isOwner) {
        $courseId = (int) ($_POST['course_id'] ?? 0);
        $course   = portal_find_course_by_id($courseId);
        if (!$course) {
            $_SESSION['admin_flash'] = ['error', 'Course not found.'];
        } else {
            $blockers = portal_course_deletion_blockers($courseId);
            if ($blockers !== []) {
                $_SESSION['admin_flash'] = ['error', 'Cannot delete: course has ' . implode(', ', $blockers) . '. Archive instead.'];
            } else {
                $pdo->prepare('DELETE FROM courses WHERE id = ?')->execute([$courseId]);
                // Course-scoped inbox alerts (announcements, replies, lesson answers, course events).
                $pdo->prepare('DELETE FROM portal_notifications WHERE course_id = ?')->execute([$courseId]);
                $_SESSION['admin_flash'] = ['success', 'Empty course deleted permanently.'];
            }
        }
        $redirectSection('courses');
    }

    if ($action === 'mark_security_event_reviewed') {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        if (portal_mark_security_event_reviewed($eventId, (int) $currentUser['id'])) {
            $_SESSION['admin_flash'] = ['success', 'Security event marked as reviewed.'];
        } else {
            $_SESSION['admin_flash'] = ['error', 'Could not update that security event.'];
        }
        $redirectSection('security', [
            'sec_period'   => (string) ($_POST['sec_period'] ?? '24h'),
            'sec_reviewed' => (string) ($_POST['sec_reviewed'] ?? 'all'),
            'sec_severity' => (string) ($_POST['sec_severity'] ?? 'all'),
            'sec_type'     => (string) ($_POST['sec_type'] ?? 'all'),
            'sec_ip'       => (string) ($_POST['sec_ip'] ?? ''),
            'sec_ids'      => (string) ($_POST['sec_ids'] ?? ''),
        ]);
    }

    if ($action === 'mark_security_low_info_reviewed') {
        $marked = portal_mark_security_events_reviewed_by_severity(['info', 'low'], (int) $currentUser['id']);
        $_SESSION['admin_flash'] = ['success', $marked . ' low-priority event' . ($marked === 1 ? '' : 's') . ' marked reviewed.'];
        $redirectSection('security', [
            'sec_period'   => (string) ($_POST['sec_period'] ?? '24h'),
            'sec_reviewed' => (string) ($_POST['sec_reviewed'] ?? 'all'),
            'sec_severity' => (string) ($_POST['sec_severity'] ?? 'all'),
            'sec_type'     => (string) ($_POST['sec_type'] ?? 'all'),
            'sec_ip'       => (string) ($_POST['sec_ip'] ?? ''),
        ]);
    }

    if ($action === 'bulk_security_action') {
        $bulkAction = (string) ($_POST['bulk_action'] ?? '');
        $filterPeriod = (string) ($_POST['sec_period'] ?? '24h');
        $filterReviewed = (string) ($_POST['sec_reviewed'] ?? 'all');
        $filterSeverity = (string) ($_POST['sec_severity'] ?? 'all');
        $filterType = (string) ($_POST['sec_type'] ?? 'all');
        $filterIp = trim((string) ($_POST['sec_ip'] ?? ''));
        $filterIdsRaw = trim((string) ($_POST['sec_ids'] ?? ''));
        $filterIds = array_values(array_filter(array_map('intval', preg_split('/\s*,\s*/', $filterIdsRaw) ?: [])));

        if ($bulkAction === 'mark_reviewed') {
            if ((string) ($_POST['select_all_matching'] ?? '') === '1') {
                $matched = portal_security_events_filtered(
                    $filterPeriod,
                    $filterReviewed,
                    $filterSeverity,
                    $filterType,
                    $filterIp,
                    500,
                    $filterIds
                );
                $ids = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $matched);
            } else {
                $ids = array_map('intval', (array) ($_POST['event_ids'] ?? []));
            }
            $marked = portal_mark_security_events_reviewed_bulk($ids, (int) $currentUser['id']);
            $_SESSION['admin_flash'] = [
                'success',
                $marked . ' event' . ($marked === 1 ? '' : 's') . ' marked reviewed.',
            ];
        } else {
            $_SESSION['admin_flash'] = ['error', 'Choose a bulk action to apply.'];
        }

        $redirectSection('security', [
            'sec_period'   => $filterPeriod,
            'sec_reviewed' => $filterReviewed,
            'sec_severity' => $filterSeverity,
            'sec_type'     => $filterType,
            'sec_ip'       => $filterIp,
            'sec_ids'      => $filterIdsRaw,
        ]);
    }

    if ($action === 'security_account_action') {
        $targetId = (int) ($_POST['target_user_id'] ?? 0);
        $accountAction = (string) ($_POST['account_action'] ?? '');
        $reason = substr(trim((string) ($_POST['reason'] ?? '')), 0, 200);
        $statusMap = [
            'ban' => 'banned',
            'mute' => 'muted',
            'restrict' => 'restricted',
            'activate' => 'active',
        ];

        if ($accountAction === 'delete') {
            $target = portal_find_user_by_id($targetId);
            if (!$target) {
                $_SESSION['admin_flash'] = ['error', 'User not found.'];
            } elseif ($targetId === (int) $currentUser['id']) {
                $_SESSION['admin_flash'] = ['error', 'You cannot delete your own account.'];
            } elseif ($target['role'] === 'owner') {
                $_SESSION['admin_flash'] = ['error', 'Owner accounts cannot be deleted.'];
            } elseif ($target['role'] === 'admin' && !$isOwner) {
                $_SESSION['admin_flash'] = ['error', 'Only the owner can delete admin accounts.'];
            } else {
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$targetId]);
                portal_log_security_event(
                    'user_deleted',
                    'medium',
                    'Deleted account from security activity: ' . substr((string) $target['name'], 0, 80)
                        . ($reason !== '' ? ' — ' . $reason : ''),
                    (int) $currentUser['id']
                );
                $_SESSION['admin_flash'] = ['success', "Account for {$target['name']} deleted."];
            }
        } elseif (isset($statusMap[$accountAction])) {
            $result = portal_set_user_account_status(
                $targetId,
                $statusMap[$accountAction],
                (int) $currentUser['id'],
                $reason
            );
            if (!empty($result['ok'])) {
                $_SESSION['admin_flash'] = [
                    'success',
                    'Account set to ' . portal_account_status_label($statusMap[$accountAction]) . '.',
                ];
            } else {
                $_SESSION['admin_flash'] = ['error', (string) ($result['error'] ?? 'Could not update account.')];
            }
        } else {
            $_SESSION['admin_flash'] = ['error', 'Unknown account action.'];
        }

        $redirectSection('security', [
            'sec_period'   => (string) ($_POST['sec_period'] ?? '24h'),
            'sec_reviewed' => (string) ($_POST['sec_reviewed'] ?? 'all'),
            'sec_severity' => (string) ($_POST['sec_severity'] ?? 'all'),
            'sec_type'     => (string) ($_POST['sec_type'] ?? 'all'),
            'sec_ip'       => (string) ($_POST['sec_ip'] ?? ''),
            'sec_ids'      => (string) ($_POST['sec_ids'] ?? ''),
        ]);
    }

    if ($action === 'save_trusted_proxies') {
        $raw = trim((string) ($_POST['trusted_proxies'] ?? ''));
        $parts = array_values(array_filter(array_map('trim', explode(',', $raw))));
        $clean = [];
        foreach ($parts as $part) {
            if (str_contains($part, '/')) {
                [$subnet, $mask] = array_pad(explode('/', $part, 2), 2, '');
                if (filter_var($subnet, FILTER_VALIDATE_IP) && ctype_digit((string) $mask)) {
                    $clean[] = $subnet . '/' . (int) $mask;
                }
            } elseif (filter_var($part, FILTER_VALIDATE_IP)) {
                $clean[] = $part;
            }
        }
        portal_site_setting_set('trusted_proxies', implode(', ', $clean));
        $_SESSION['admin_flash'] = ['success', 'Trusted proxy list saved.'];
        $redirectSection('security');
    }

    if ($action === 'lookup_submission_receipt') {
        if (!portal_is_admin()) {
            portal_log_security_event('unauthorised_admin_access', 'high', 'Blocked receipt lookup');
            $_SESSION['admin_flash'] = ['error', 'Access denied.'];
            $redirectSection('dashboard');
        }

        $adminId = (int) ($currentUser['id'] ?? 0);
        $ip = portal_client_ip();
        portal_receipt_lookup_record_attempt($adminId, $ip);

        if (portal_receipt_lookup_rate_limited($adminId, $ip)) {
            portal_log_security_event(
                'unauthorised_admin_access',
                'medium',
                'Receipt lookup rate limited for admin #' . $adminId
            );
            $_SESSION['admin_flash'] = ['error', 'Too many receipt lookups. Try again later.'];
            unset($_SESSION['admin_receipt_result']);
            $redirectSection('dashboard');
        }

        $normalized = portal_normalize_receipt_number((string) ($_POST['receipt_number'] ?? ''));
        $masked = portal_mask_receipt_number($normalized);
        $found = portal_receipt_format_ok($normalized) ? portal_find_submission_by_receipt($normalized) : null;

        if ($found === null) {
            portal_log_security_event(
                'unauthorised_admin_access',
                'low',
                'Receipt lookup miss ' . $masked . ' by admin #' . $adminId
            );
            $_SESSION['admin_flash'] = ['error', 'Receipt not found.'];
            unset($_SESSION['admin_receipt_result']);
        } else {
            portal_log_security_event(
                'unauthorised_admin_access',
                'info',
                'Receipt lookup hit ' . $masked . ' submission #' . (int) $found['id'] . ' by admin #' . $adminId
            );
            unset($found['filepath']);
            $_SESSION['admin_flash'] = ['success', 'Submission found.'];
            $_SESSION['admin_receipt_result'] = $found;
        }
        $redirectSection('dashboard');
    }

    if ($action === 'save_integrity_settings') {
        $policy = (string) ($_POST['external_ai_policy'] ?? 'disabled');
        if (!in_array($policy, ['disabled', 'site_wide', 'per_module'], true)) {
            $policy = 'disabled';
        }

        $apiKey = trim((string) ($_POST['gptzero_api_key'] ?? ''));
        $clearGptZeroKey = isset($_POST['clear_gptzero_api_key']) && $_POST['clear_gptzero_api_key'] === '1';
        $hasExistingGptZeroKey = portal_gptzero_key_configured();
        $willHaveGptZeroKey = $apiKey !== '' || ($hasExistingGptZeroKey && !$clearGptZeroKey);

        if ($clearGptZeroKey && $apiKey === '' && $policy !== 'disabled') {
            $_SESSION['admin_flash'] = ['error', 'You cannot remove the GPTZero key while external AI checks are enabled. Disable GPTZero checks first.'];
            $redirectSection('integrity');
        }

        if ($policy === 'site_wide' && !$willHaveGptZeroKey) {
            $_SESSION['admin_flash'] = ['error', 'Add a GPTZero API key before enabling site-wide external AI checks.'];
            $redirectSection('integrity');
        }

        if ($policy === 'per_module' && !$willHaveGptZeroKey) {
            $_SESSION['admin_flash'] = ['error', 'Add a GPTZero API key before enabling selected-module external AI checks.'];
            $redirectSection('integrity');
        }

        $gptZeroKeyToValidate = '';
        if ($apiKey !== '') {
            $gptZeroKeyToValidate = $apiKey;
        } elseif ($policy !== 'disabled' && !$clearGptZeroKey) {
            $gptZeroKeyToValidate = portal_gptzero_api_key();
        }

        if ($gptZeroKeyToValidate !== '') {
            $validation = portal_gptzero_validate_api_key($gptZeroKeyToValidate);
            if (!$validation['ok']) {
                $_SESSION['admin_flash'] = ['error', $validation['error']];
                $redirectSection('integrity');
            }
        }

        $safeBrowsingKey = trim((string) ($_POST['google_safe_browsing_api_key'] ?? ''));
        if ($safeBrowsingKey !== '') {
            portal_site_setting_set('google_safe_browsing_api_key', $safeBrowsingKey);
        }
        if (isset($_POST['clear_google_safe_browsing_api_key']) && $_POST['clear_google_safe_browsing_api_key'] === '1') {
            portal_site_setting_set('google_safe_browsing_api_key', '');
        }

        portal_site_setting_set('external_ai_policy', $policy);

        if ($apiKey !== '') {
            portal_gptzero_key_save($apiKey);
        } elseif ($clearGptZeroKey) {
            portal_gptzero_key_clear();
        }

        $pdo->exec('UPDATE courses SET external_ai_detection = 0');
        if ($policy === 'per_module') {
            $courseIds = array_map('intval', (array) ($_POST['external_ai_courses'] ?? []));
            $upd = $pdo->prepare('UPDATE courses SET external_ai_detection = 1 WHERE id = ?');
            foreach ($courseIds as $cid) {
                if ($cid > 0) {
                    $upd->execute([$cid]);
                }
            }
        }

        $message = 'Integrity and link safety settings saved.';
        if ($apiKey !== '' && $policy === 'site_wide') {
            $message = 'GPTZero key saved. Site-wide checks are now enabled.';
        } elseif ($apiKey !== '' && $policy === 'per_module') {
            $message = 'GPTZero key saved. Selected-module checks are now enabled.';
        }

        $_SESSION['admin_flash'] = ['success', $message];
        $redirectSection('integrity');
    }

    portal_redirect('admin.php?section=' . urlencode($section));
}

// ── Read flash ─────────────────────────────────────────────────────────────────
$flash = [];
if (isset($_SESSION['admin_flash'])) {
    $flash = $_SESSION['admin_flash'];
    unset($_SESSION['admin_flash']);
}
$inviteLinkOnce = '';
if (isset($_SESSION['admin_invite_link']) && is_string($_SESSION['admin_invite_link'])) {
    $inviteLinkOnce = (string) $_SESSION['admin_invite_link'];
    unset($_SESSION['admin_invite_link']);
}

// ── Page data ─────────────────────────────────────────────────────────────────
$users              = portal_all_users();
$adminCourses       = portal_admin_course_rows();
$allCourses         = portal_course_catalog();
$enrollmentCounts   = portal_user_enrollment_counts();
$enrollTarget       = $enrollTargetId > 0 ? portal_find_user_by_id($enrollTargetId) : null;
$enrolledIds        = $enrollTarget ? portal_user_course_access_ids($enrollTargetId) : [];
$enrollTargetIsTeacher = $enrollTarget && ((string) ($enrollTarget['role'] ?? '')) === 'teacher';
$editCourse         = $editCourseId > 0 ? portal_find_course_by_id($editCourseId) : null;
$editCourseSchedule = [];
if ($editCourse) {
    $schedStmt = $pdo->prepare(
        'SELECT id, day_of_week, start_time, end_time, room, notes
         FROM course_schedule
         WHERE course_id = ?
         ORDER BY sort_order ASC, id ASC'
    );
    $schedStmt->execute([(int) $editCourse['id']]);
    $editCourseSchedule = $schedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$courseWeekdays = portal_course_weekday_names();
$duplicateCourse    = $duplicateCourseId > 0 ? portal_find_course_by_id($duplicateCourseId) : null;
$editUser           = $editUserId > 0 ? portal_find_user_by_id($editUserId) : null;
$yearGroupOptions   = portal_year_group_options();
$pendingInvites     = portal_invite_list_pending();
$inviteCourses      = $pdo->query(
    "SELECT id, title, code, status, status_label, accent FROM courses ORDER BY title ASC"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$userQuery  = trim((string) ($_GET['user_q'] ?? ''));
$userRole   = (string) ($_GET['user_role'] ?? 'all');
$userYear   = (string) ($_GET['user_year'] ?? 'all');
$courseQuery = trim((string) ($_GET['course_q'] ?? ''));
$courseStatus = (string) ($_GET['course_status'] ?? 'all');
$courseYear = (string) ($_GET['course_year'] ?? 'all');
$enrollCourseQ = trim((string) ($_GET['enroll_course_q'] ?? ''));

$filteredUsers = array_values(array_filter(
    $users,
    static function (array $u) use ($userQuery, $userRole, $userYear): bool {
        if ($userRole !== 'all' && $u['role'] !== $userRole) {
            return false;
        }
        if ($userYear !== 'all' && (string) $u['year'] !== $userYear) {
            return false;
        }
        if ($userQuery === '') {
            return true;
        }
        $haystack = implode(' ', [$u['name'], $u['email'], $u['username'], $u['year']]);
        return stripos($haystack, $userQuery) !== false;
    }
));

$filteredAdminCourses = array_values(array_filter(
    $adminCourses,
    static function (array $c) use ($courseQuery, $courseStatus, $courseYear): bool {
        if ($courseYear !== 'all' && $c['year_group'] !== $courseYear) {
            return false;
        }
        if ($courseStatus !== 'all' && $c['status'] !== $courseStatus) {
            return false;
        }
        if ($courseQuery === '') {
            return true;
        }
        $haystack = implode(' ', [$c['title'], $c['code'], $c['slug'], $c['full_title'], $c['room']]);
        return stripos($haystack, $courseQuery) !== false;
    }
));

$courseYearOptions = portal_course_year_options($adminCourses);
$academicYearOptions = portal_academic_year_options();
$currentAcademicYear = portal_academic_year_current();

$stats = [
    'total_users'       => count($users),
    'owners'            => count(array_filter($users, fn($u) => $u['role'] === 'owner')),
    'admins'            => count(array_filter($users, fn($u) => $u['role'] === 'admin')),
    'course_supervisors' => (int) $pdo->query(
        "SELECT COUNT(*) FROM course_teachers WHERE assignment_role = 'supervisor'"
    )->fetchColumn(),
    'teachers'          => count(array_filter($users, fn($u) => $u['role'] === 'teacher')),
    'students'          => count(array_filter($users, fn($u) => $u['role'] === 'student')),
    'total_courses'     => count($adminCourses),
    'open_courses'      => count(array_filter($adminCourses, fn($c) => $c['status'] === 'open')),
    'closed_courses'    => count(array_filter($adminCourses, fn($c) => $c['status'] === 'closed')),
    'archived_courses'  => count(array_filter($adminCourses, fn($c) => $c['status'] === 'archived')),
    'draft_courses'     => count(array_filter($adminCourses, fn($c) => $c['status'] === 'draft')),
    'total_enrollments' => (int) $pdo->query('SELECT COUNT(*) FROM enrollments')->fetchColumn(),
    'total_submissions' => (int) $pdo->query('SELECT COUNT(*) FROM course_submissions')->fetchColumn(),
    'total_activities'  => (int) $pdo->query('SELECT COUNT(*) FROM course_activities')->fetchColumn(),
];

$actFilterMode = (string) ($_GET['act_mode'] ?? '');
$actFilterStatus = (string) ($_GET['act_status'] ?? '');
$actSearch = trim((string) ($_GET['act_q'] ?? ''));
$actSql = "SELECT a.id, a.title, a.mode, a.status, a.published_at, a.updated_at,
                  c.title AS course_title, c.slug AS course_slug, c.code AS course_code,
                  u.name AS author_name
           FROM course_activities a
           JOIN courses c ON c.id = a.course_id
           LEFT JOIN users u ON u.id = a.created_by
           WHERE 1=1";
$actParams = [];
if ($actFilterMode !== '' && in_array($actFilterMode, portal_activity_modes(), true)) {
    $actSql .= ' AND a.mode = ?';
    $actParams[] = $actFilterMode;
}
if ($actFilterStatus !== '' && in_array($actFilterStatus, ['draft', 'published', 'archived'], true)) {
    $actSql .= ' AND a.status = ?';
    $actParams[] = $actFilterStatus;
}
if ($actSearch !== '') {
    $actSql .= ' AND (a.title LIKE ? OR c.title LIKE ? OR u.name LIKE ?)';
    $like = '%' . $actSearch . '%';
    $actParams[] = $like;
    $actParams[] = $like;
    $actParams[] = $like;
}
$actSql .= ' ORDER BY a.updated_at DESC, a.id DESC LIMIT 100';
$actStmt = $pdo->prepare($actSql);
$actStmt->execute($actParams);
$adminActivities = $actStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$activityAuditStmt = $pdo->query(
    "SELECT ae.id, ae.action, ae.created_at, ae.activity_id, a.title AS activity_title,
            c.title AS course_title, u.name AS actor_name
     FROM activity_audit_events ae
     LEFT JOIN course_activities a ON a.id = ae.activity_id
     LEFT JOIN courses c ON c.id = ae.course_id
     LEFT JOIN users u ON u.id = ae.user_id
     ORDER BY ae.id DESC LIMIT 40"
);
$activityAuditRows = $activityAuditStmt ? ($activityAuditStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

$badgeCount = (int) $pdo->query('SELECT COUNT(*) FROM gamification_badges WHERE enabled = 1')->fetchColumn();
$templateCount = (int) $pdo->query('SELECT COUNT(*) FROM activity_templates')->fetchColumn();

$integrityPolicy    = portal_external_ai_policy();
$integrityKeySet    = portal_gptzero_key_configured();
$safeBrowsingKeySet = portal_site_setting_has('google_safe_browsing_api_key')
    || trim((string) getenv('GOOGLE_SAFE_BROWSING_API_KEY')) !== '';
$integrityCourses   = $pdo->query(
    'SELECT id, title, code, status, status_label, accent, external_ai_detection FROM courses ORDER BY title ASC'
)->fetchAll();

$integrityPolicyLabel = [
    'disabled' => 'Disabled',
    'site_wide' => 'Site-wide',
    'per_module' => 'Selected modules',
][$integrityPolicy] ?? 'Disabled';
$integrityPolicyIncomplete = $integrityPolicy !== 'disabled' && !$integrityKeySet;
if ($integrityPolicy === 'disabled') {
    $integrityPolicySummary = 'Internal integrity checks still run.';
    $integrityPolicyBadge = 'Disabled';
    $integrityPolicyBadgeClass = 'admin-badge--draft';
} elseif ($integrityPolicyIncomplete && $integrityPolicy === 'site_wide') {
    $integrityPolicySummary = 'Add a GPTZero API key to activate site-wide checks.';
    $integrityPolicyBadge = 'Configuration incomplete';
    $integrityPolicyBadgeClass = 'admin-badge--warning';
} elseif ($integrityPolicyIncomplete && $integrityPolicy === 'per_module') {
    $integrityPolicySummary = 'Add a GPTZero API key to activate selected-module checks.';
    $integrityPolicyBadge = 'Configuration incomplete';
    $integrityPolicyBadgeClass = 'admin-badge--warning';
} elseif ($integrityPolicy === 'site_wide') {
    $integrityPolicySummary = 'GPTZero checks run for every submission.';
    $integrityPolicyBadge = 'Enabled';
    $integrityPolicyBadgeClass = 'admin-badge--open';
} else {
    $integrityPolicySummary = 'GPTZero checks run only for selected modules.';
    $integrityPolicyBadge = 'Enabled';
    $integrityPolicyBadgeClass = 'admin-badge--open';
}

$dbSecurityWarning = portal_db_security_warning();
$showDeveloperSecurity = portal_is_owner() && portal_show_developer_security();
$systemNeedsDevReview  = portal_system_needs_developer_review();

$secPeriod   = (string) ($_GET['sec_period'] ?? '24h');
$secReviewed = (string) ($_GET['sec_reviewed'] ?? 'all');
$secSeverity = (string) ($_GET['sec_severity'] ?? 'all');
$secType     = (string) ($_GET['sec_type'] ?? 'all');
$secIp       = trim((string) ($_GET['sec_ip'] ?? ''));
$secIdsRaw   = trim((string) ($_GET['sec_ids'] ?? ''));
$secIds      = array_values(array_filter(array_map('intval', preg_split('/\s*,\s*/', $secIdsRaw) ?: [])));
if (!in_array($secPeriod, ['24h', '7d', '30d'], true)) {
    $secPeriod = '24h';
}
if (strlen($secIp) > 64) {
    $secIp = substr($secIp, 0, 64);
}

$securityStats     = portal_security_dashboard_stats($secPeriod);
$securityEvents    = portal_security_events_filtered($secPeriod, $secReviewed, $secSeverity, $secType, $secIp, 100, $secIds);
$securityIpSummary = portal_security_ip_summary($secPeriod, 15);
$securityIncidents = portal_security_detect_incidents($secPeriod);
$trustedProxies    = (string) portal_site_setting_get('trusted_proxies', '');
$securityFilterParams = [
    'sec_period'   => $secPeriod,
    'sec_reviewed' => $secReviewed,
    'sec_severity' => $secSeverity,
    'sec_type'     => $secType,
    'sec_ip'       => $secIp,
    'sec_ids'      => $secIdsRaw,
];
$securityUserProfiles = [];
foreach ($securityEvents as $profileEvent) {
    $profileUserId = (int) ($profileEvent['user_id'] ?? 0);
    if ($profileUserId <= 0) {
        $profileUsername = trim((string) ($profileEvent['username'] ?? ''));
        if ($profileUsername !== '') {
            $resolved = portal_find_user($profileUsername);
            $profileUserId = $resolved ? (int) $resolved['id'] : 0;
        }
    }
    if ($profileUserId <= 0 || isset($securityUserProfiles[$profileUserId])) {
        continue;
    }
    $snapshot = portal_security_user_profile($profileUserId, $secPeriod);
    if ($snapshot !== null) {
        $canAct = $profileUserId !== (int) $currentUser['id']
            && (string) ($snapshot['role'] ?? '') !== 'owner'
            && (!in_array((string) ($snapshot['role'] ?? ''), ['admin'], true) || $isOwner);
        $snapshot['can_act'] = $canAct;
        $securityUserProfiles[$profileUserId] = $snapshot;
    }
}
$securityTypes   = [
    'failed_login', 'login_throttled', 'csrf_failed', 'unauthorised_admin_access',
    'unauthorised_course_access', 'forbidden_download', 'blocked_upload',
    'unsafe_rich_text_removed', 'role_changed', 'user_deleted', 'user_updated',
    'course_archived', 'course_restored', 'grade_changed', 'profile_updated',
    'password_changed', 'account_status_changed',
];

$sectionTitles = [
    'dashboard'   => 'Dashboard',
    'users'       => 'Manage Users',
    'courses'     => 'Course Management',
    'enrollments' => 'Enrolments',
    'invites'     => 'Student Invites',
    'activities'  => 'Activity Management',
    'integrity'   => 'Integrity & Link Safety',
    'security'    => 'Security Activity',
];

$navItems = [
    ['key' => 'dashboard',   'label' => 'Dashboard',               'icon' => 'sparkles'],
    ['key' => 'users',       'label' => 'Manage Users',            'icon' => 'users'],
    ['key' => 'courses',     'label' => 'Course Management',       'icon' => 'book-open'],
    ['key' => 'enrollments', 'label' => 'Enrolments',              'icon' => 'folder'],
    ['key' => 'invites',     'label' => 'Student Invites',         'icon' => 'users'],
    ['key' => 'activities',  'label' => 'Activity Management',     'icon' => 'activity'],
    ['key' => 'integrity',   'label' => 'Integrity & Link Safety', 'icon' => 'shield'],
    ['key' => 'security',    'label' => 'Security Activity',       'icon' => 'lock'],
];

$adminUrl = static function (string $targetSection, array $extra = []) use ($adminSections): string {
    $params = array_merge(['section' => $targetSection], array_filter($extra, static fn($v) => $v !== null && $v !== ''));
    return 'admin.php?' . http_build_query($params);
};

$page_title       = 'Admin | ' . portal_school_name();
$active_page      = 'admin';
$page_eyebrow     = 'Administration';
$page_heading     = $sectionTitles[$section] ?? 'Dashboard';
$page_description = $section === 'dashboard'
    ? 'Review priorities, system health, and portal activity.'
    : 'Manage ' . strtolower($sectionTitles[$section] ?? 'portal settings') . '.';

ob_start();
?>
<div class="admin-shell">
    <header class="admin-topbar">
        <div class="admin-topbar-main">
            <nav class="admin-breadcrumb" aria-label="Breadcrumb">
                <a href="<?= portal_escape($adminUrl('dashboard')) ?>">Admin</a>
                <span aria-hidden="true">/</span>
                <span><?= portal_escape($sectionTitles[$section] ?? 'Dashboard') ?></span>
            </nav>
        </div>
    </header>

    <?php if ($flash): ?>
    <div class="admin-flash <?= $flash[0] === 'success' ? 'success' : 'error' ?>">
        <?php if ($flash[0] === 'success'): ?>
            <span><?= portal_escape($flash[1]) ?></span>
        <?php else: ?>
            <?= portal_icon('lock', 'admin-flash-icon') ?>
            <span><?= portal_escape($flash[1]) ?></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="admin-body">
        <nav class="admin-sidebar" aria-label="Admin sections">
            <?php foreach ($navItems as $item): ?>
            <a class="admin-sidebar-link<?= $section === $item['key'] ? ' is-active' : '' ?>"
               href="<?= portal_escape($adminUrl($item['key'])) ?>">
                <?= portal_icon($item['icon'], 'admin-sidebar-icon') ?>
                <span><?= portal_escape($item['label']) ?></span>
            </a>
            <?php endforeach; ?>
        </nav>

        <div class="admin-main">

            <!-- Dashboard -->
            <section id="admin-section-dashboard" class="admin-section<?= $section === 'dashboard' ? ' is-active' : '' ?>">
                <div class="admin-attention-grid">
                    <a class="admin-attention-card<?= (int) $securityStats['active_alerts'] > 0 ? ' admin-attention-card--alert' : '' ?>" href="<?= portal_escape($adminUrl('security', ['sec_reviewed' => 'unreviewed', 'sec_severity' => 'medium'])) ?>">
                        <span>Security alerts</span>
                        <strong><?= (int) $securityStats['active_alerts'] ?></strong>
                        <small><?= (int) $securityStats['active_alerts'] > 0 ? 'Review unresolved medium/high events' : 'No unresolved alerts' ?></small>
                    </a>
                    <a class="admin-attention-card<?= (int) $securityStats['failed_logins'] > 0 ? ' admin-attention-card--warning' : '' ?>" href="<?= portal_escape($adminUrl('security', ['sec_type' => 'failed_login'])) ?>">
                        <span>Failed logins</span>
                        <strong><?= (int) $securityStats['failed_logins'] ?></strong>
                        <small>In the last 24 hours</small>
                    </a>
                    <a class="admin-attention-card<?= $integrityPolicyIncomplete || !$safeBrowsingKeySet ? ' admin-attention-card--warning' : '' ?>" href="<?= portal_escape($adminUrl('integrity')) ?>">
                        <span>Integrity setup</span>
                        <strong><?= $integrityPolicyIncomplete || !$safeBrowsingKeySet ? 'Review' : 'Ready' ?></strong>
                        <small><?= $integrityPolicyIncomplete ? 'AI policy needs a GPTZero key' : (!$safeBrowsingKeySet ? 'Safe Browsing key is missing' : 'External checks are configured') ?></small>
                    </a>
                    <a class="admin-attention-card<?= $stats['draft_courses'] > 0 ? ' admin-attention-card--warning' : '' ?>" href="<?= portal_escape($adminUrl('courses', ['course_status' => 'draft'])) ?>">
                        <span>Draft courses</span>
                        <strong><?= $stats['draft_courses'] ?></strong>
                        <small><?= $stats['draft_courses'] > 0 ? 'Courses still need publishing' : 'No courses awaiting publication' ?></small>
                    </a>
                    <?php if ($systemNeedsDevReview): ?>
                    <a class="admin-attention-card admin-attention-card--alert" href="<?= portal_escape($adminUrl('security')) ?>">
                        <span>System review</span>
                        <strong>Needed</strong>
                        <small>Developer security review is required</small>
                    </a>
                    <?php endif; ?>
                </div>

                <div class="admin-stat-grid admin-stat-grid--inventory">
                    <article class="admin-stat-card admin-stat-card--priority">
                        <p class="admin-stat-label">Total users</p>
                        <strong class="admin-stat-value"><?= $stats['total_users'] ?></strong>
                    </article>
                    <article class="admin-stat-card">
                        <p class="admin-stat-label">Students</p>
                        <strong class="admin-stat-value"><?= $stats['students'] ?></strong>
                    </article>
                    <article class="admin-stat-card">
                        <p class="admin-stat-label">Teachers</p>
                        <strong class="admin-stat-value"><?= $stats['teachers'] ?></strong>
                    </article>
                    <article class="admin-stat-card">
                        <p class="admin-stat-label">Course supervisors</p>
                        <strong class="admin-stat-value"><?= $stats['course_supervisors'] ?></strong>
                    </article>
                    <article class="admin-stat-card">
                        <p class="admin-stat-label">Admins</p>
                        <strong class="admin-stat-value"><?= $stats['admins'] + $stats['owners'] ?></strong>
                    </article>
                    <article class="admin-stat-card admin-stat-card--priority">
                        <p class="admin-stat-label">Total courses</p>
                        <strong class="admin-stat-value"><?= $stats['total_courses'] ?></strong>
                    </article>
                    <article class="admin-stat-card">
                        <p class="admin-stat-label">Open courses</p>
                        <strong class="admin-stat-value"><?= $stats['open_courses'] ?></strong>
                    </article>
                    <article class="admin-stat-card">
                        <p class="admin-stat-label">Closed</p>
                        <strong class="admin-stat-value"><?= $stats['closed_courses'] ?></strong>
                    </article>
                    <article class="admin-stat-card">
                        <p class="admin-stat-label">Archived</p>
                        <strong class="admin-stat-value"><?= $stats['archived_courses'] ?></strong>
                    </article>
                    <article class="admin-stat-card admin-stat-card--priority">
                        <p class="admin-stat-label">Enrolments</p>
                        <strong class="admin-stat-value"><?= $stats['total_enrollments'] ?></strong>
                    </article>
                    <article class="admin-stat-card">
                        <p class="admin-stat-label">Submissions</p>
                        <strong class="admin-stat-value"><?= $stats['total_submissions'] ?></strong>
                    </article>
                </div>

                <?php
                $receiptResult = $_SESSION['admin_receipt_result'] ?? null;
                unset($_SESSION['admin_receipt_result']);
                ?>
                <article class="admin-card">
                    <header class="admin-card-header">
                        <div>
                            <p class="eyebrow">Support</p>
                            <h3>Find submission by receipt</h3>
                            <p class="admin-card-lead">Paste a student receipt number to locate a submission if work appears missing.</p>
                        </div>
                    </header>
                    <form method="post" action="<?= portal_escape($adminUrl('dashboard')) ?>" class="admin-filter-row">
                        <?= portal_csrf_field() ?>
                        <input type="hidden" name="action" value="lookup_submission_receipt">
                        <label class="admin-search admin-field--grow">
                            <span class="visually-hidden">Receipt number</span>
                            <input type="text" name="receipt_number" required autocomplete="off" spellcheck="false"
                                   placeholder="RIEO-…" pattern="[Rr][Ii][Ee][Oo]-[A-Fa-f0-9]{32}">
                        </label>
                        <button type="submit" class="admin-btn admin-btn--primary">Look up</button>
                    </form>
                    <?php if (is_array($receiptResult)): ?>
                    <div class="admin-receipt-result">
                        <p><strong>Receipt:</strong> <?= portal_escape((string) ($receiptResult['receipt_number'] ?? '')) ?></p>
                        <p><strong>Student:</strong> <?= portal_escape((string) ($receiptResult['student_name'] ?? '')) ?>
                            (<?= portal_escape((string) ($receiptResult['student_username'] ?? '')) ?>)</p>
                        <p><strong>Course:</strong> <?= portal_escape((string) ($receiptResult['course_title'] ?? '')) ?></p>
                        <p><strong>Assignment:</strong> <?= portal_escape((string) ($receiptResult['assignment_title'] ?? '')) ?></p>
                        <p><strong>File:</strong> <?= portal_escape((string) ($receiptResult['filename'] ?? '')) ?>
                            <?php if (!empty($receiptResult['declared_file_type'])): ?>
                            · <?= portal_escape((string) $receiptResult['declared_file_type']) ?>
                            <?php endif; ?>
                        </p>
                        <p><strong>Submitted:</strong>
                            <?= portal_escape((string) ($receiptResult['submitted_at'] ?? '')) ?>
                        </p>
                        <?php if (!empty($receiptResult['file_sha256'])): ?>
                        <p><strong>SHA-256:</strong> <code><?= portal_escape((string) $receiptResult['file_sha256']) ?></code></p>
                        <?php endif; ?>
                        <p><strong>Grade:</strong>
                            <?= $receiptResult['score'] !== null && $receiptResult['score'] !== ''
                                ? portal_escape((string) (int) $receiptResult['score']) . '/100'
                                : 'Not graded' ?>
                        </p>
                        <div class="admin-table-actions">
                            <a class="admin-btn admin-btn--secondary admin-btn--sm"
                               href="course.php?course=<?= portal_escape((string) ($receiptResult['course_slug'] ?? '')) ?>">Open course</a>
                            <a class="admin-btn admin-btn--primary admin-btn--sm"
                               href="download.php?sub=<?= (int) ($receiptResult['id'] ?? 0) ?>">Download file</a>
                        </div>
                    </div>
                    <?php endif; ?>
                </article>

                <div class="admin-dashboard-grid">
                    <article class="admin-card">
                        <header class="admin-card-header">
                            <div>
                                <p class="eyebrow">Quick links</p>
                                <h3>Common tasks</h3>
                            </div>
                        </header>
                        <div class="admin-quick-links">
                            <a class="admin-btn admin-btn--secondary" href="<?= portal_escape($adminUrl('users')) ?>">Add or manage users</a>
                            <a class="admin-btn admin-btn--secondary" href="<?= portal_escape($adminUrl('courses')) ?>">Manage courses</a>
                            <a class="admin-btn admin-btn--secondary" href="<?= portal_escape($adminUrl('enrollments')) ?>">Manage enrolments</a>
                            <a class="admin-btn admin-btn--secondary" href="<?= portal_escape($adminUrl('integrity')) ?>">Integrity settings</a>
                        </div>
                    </article>

                    <article class="admin-card admin-card--role-guide">
                        <details class="admin-role-guide-details">
                            <summary class="admin-role-guide-summary">
                                <div>
                                    <p class="eyebrow">Role guide</p>
                                    <h3>Permissions overview</h3>
                                    <p class="admin-card-lead">System roles and course assignments.</p>
                                </div>
                            </summary>

                        <div class="admin-role-guide-section">
                            <h4 class="admin-role-guide-heading">System roles</h4>
                            <div class="admin-role-guide-grid">
                                <article class="admin-role-card">
                                    <span class="admin-badge admin-badge--owner">Owner</span>
                                    <p>Full system control. Can manage accounts, roles, courses, and high-risk actions.</p>
                                </article>
                                <article class="admin-role-card">
                                    <span class="admin-badge admin-badge--admin">Admin</span>
                                    <p>Manages users, enrolments, courses, integrity settings, and admin workflows.</p>
                                </article>
                                <article class="admin-role-card">
                                    <span class="admin-badge admin-badge--teacher">Teacher</span>
                                    <p>Teaching account. Can be assigned to modules as Course Teacher or Course Supervisor.</p>
                                </article>
                                <article class="admin-role-card">
                                    <span class="admin-badge admin-badge--student">Student</span>
                                    <p>Learner account. Accesses enrolled courses and submits work.</p>
                                </article>
                            </div>
                        </div>

                        <div class="admin-role-guide-section">
                            <h4 class="admin-role-guide-heading">Course assignments</h4>
                            <div class="admin-role-guide-grid admin-role-guide-grid--two">
                                <article class="admin-role-card">
                                    <span class="admin-badge admin-badge--supervisor">Course Supervisor</span>
                                    <p>Course-level assignment. Can manage the assigned module and oversee teaching activity for that module.</p>
                                </article>
                                <article class="admin-role-card">
                                    <span class="admin-badge admin-badge--teacher">Course Teacher</span>
                                    <p>Course-level assignment. Can manage materials, folders, announcements, discussions, and submissions on assigned modules.</p>
                                </article>
                            </div>
                        </div>

                        <p class="admin-role-guide-note">Course assignments are set per module. A teacher can be a Course Supervisor on one module and a Course Teacher on another.</p>
                        </details>
                    </article>
                </div>
            </section>

            <!-- Users -->
            <section id="admin-section-users" class="admin-section<?= $section === 'users' ? ' is-active' : '' ?>">
                <article class="admin-card">
                    <header class="admin-card-header">
                        <div>
                            <p class="eyebrow">New account</p>
                            <h3>Add a user</h3>
                        </div>
                    </header>
                    <form class="admin-form-grid" method="post" action="<?= portal_escape($adminUrl('users')) ?>">
                        <?= portal_csrf_field() ?>
                        <input type="hidden" name="action" value="create_user">
                        <label class="admin-field">
                            <span>Full name</span>
                            <input type="text" name="name" required placeholder="e.g. Jane Smith">
                        </label>
                        <label class="admin-field">
                            <span>Username</span>
                            <input type="text" name="username" required placeholder="e.g. jsmith">
                        </label>
                        <label class="admin-field">
                            <span>Email</span>
                            <input type="email" name="email" required placeholder="student@rieo.edu">
                        </label>
                        <label class="admin-field">
                            <span>Year group</span>
                            <select name="year">
                                <?php foreach ($yearGroupOptions as $yr): ?>
                                <option value="<?= portal_escape($yr) ?>"<?= $yr === 'Year 11' ? ' selected' : '' ?>><?= portal_escape($yr) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="admin-field">
                            <span>Password</span>
                            <span class="admin-password-wrap">
                                <input type="password" name="password" required minlength="8" placeholder="Min. 8 characters, letter + number" autocomplete="new-password">
                                <button type="button" class="admin-toggle-pass" data-toggle-password aria-label="Show password" aria-pressed="false">
                                    <span class="admin-toggle-pass__icon admin-toggle-pass__icon--show" aria-hidden="true"><?= portal_icon('eye', 'icon-sm') ?></span>
                                    <span class="admin-toggle-pass__icon admin-toggle-pass__icon--hide" aria-hidden="true" hidden><?= portal_icon('eye-off', 'icon-sm') ?></span>
                                </button>
                            </span>
                        </label>
                        <?php if ($isOwner): ?>
                        <label class="admin-field">
                            <span>Role</span>
                            <select name="role">
                                <option value="student" selected>Student</option>
                                <option value="teacher">Teacher</option>
                                <option value="admin">Admin</option>
                            </select>
                        </label>
                        <?php else: ?>
                        <label class="admin-field">
                            <span>Role</span>
                            <select name="role">
                                <option value="student" selected>Student</option>
                                <option value="teacher">Teacher</option>
                            </select>
                        </label>
                        <?php endif; ?>
                        <div class="admin-form-actions admin-form-actions--full">
                            <button type="submit" class="admin-btn admin-btn--primary">Create account</button>
                        </div>
                    </form>
                </article>

                <article class="admin-card">
                    <header class="admin-card-header">
                        <div>
                            <p class="eyebrow">Accounts</p>
                            <h3>All users</h3>
                        </div>
                        <span class="chip"><?= count($filteredUsers) ?> shown</span>
                    </header>

                    <form class="admin-filter-row" method="get" action="admin.php">
                        <input type="hidden" name="section" value="users">
                        <label class="admin-search">
                            <span class="visually-hidden">Search users</span>
                            <input type="search" name="user_q" value="<?= portal_escape($userQuery) ?>" placeholder="Search name, email, or username">
                        </label>
                        <label class="admin-field admin-field--inline">
                            <span>Role</span>
                            <select name="user_role" onchange="this.form.submit()">
                                <option value="all"<?= $userRole === 'all' ? ' selected' : '' ?>>All roles</option>
                                <option value="owner"<?= $userRole === 'owner' ? ' selected' : '' ?>>Owner</option>
                                <option value="admin"<?= $userRole === 'admin' ? ' selected' : '' ?>>Admin</option>
                                <option value="teacher"<?= $userRole === 'teacher' ? ' selected' : '' ?>>Teacher</option>
                                <option value="student"<?= $userRole === 'student' ? ' selected' : '' ?>>Student</option>
                            </select>
                        </label>
                        <label class="admin-field admin-field--inline">
                            <span>Year</span>
                            <select name="user_year" onchange="this.form.submit()">
                                <option value="all"<?= $userYear === 'all' ? ' selected' : '' ?>>All years</option>
                                <?php foreach ($yearGroupOptions as $yr): ?>
                                <option value="<?= portal_escape($yr) ?>"<?= $userYear === $yr ? ' selected' : '' ?>><?= portal_escape($yr) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="submit" class="admin-btn admin-btn--secondary">Search</button>
                    </form>

                    <div class="admin-table-wrap">
                        <table class="admin-table admin-table--users">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Year</th>
                                    <th>Enrolments</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($filteredUsers as $u): ?>
                                <?php
                                    $isSelf = (int) $u['id'] === (int) $currentUser['id'];
                                    $canManageUser = !$isSelf
                                        && $u['role'] !== 'owner'
                                        && ($isOwner || !in_array($u['role'], ['admin', 'owner'], true));
                                    $canChangeRole = $canManageUser
                                        && ($isOwner || in_array($u['role'], ['student', 'teacher'], true));
                                ?>
                                <tr>
                                    <td data-label="User">
                                        <div class="admin-table-user">
                                            <div class="admin-avatar admin-avatar--sm"><?= portal_escape($u['initials']) ?></div>
                                            <div>
                                                <strong><?= portal_escape($u['name']) ?></strong>
                                            </div>
                                        </div>
                                    </td>
                                    <td data-label="Username"><?= portal_escape($u['username']) ?></td>
                                    <td data-label="Email"><?= portal_escape($u['email']) ?></td>
                                    <td data-label="Role"><span class="admin-badge admin-badge--<?= portal_escape($u['role']) ?>"><?= portal_escape(ucfirst($u['role'])) ?></span></td>
                                    <td data-label="Year"><?= portal_escape($u['year']) ?></td>
                                    <td data-label="Enrolments"><?= (int) ($enrollmentCounts[(int) $u['id']] ?? 0) ?></td>
                                    <td data-label="Actions">
                                        <div class="admin-table-actions">
                                            <?php if ($canManageUser): ?>
                                            <a class="admin-btn admin-btn--primary admin-btn--sm" href="<?= portal_escape($adminUrl('users', ['edit_user' => (int) $u['id']])) ?>">Edit</a>
                                            <?php endif; ?>
                                            <a class="admin-btn admin-btn--secondary admin-btn--sm" href="<?= portal_escape($adminUrl('enrollments', ['enroll' => (int) $u['id']])) ?>">Enrolments</a>
                                            <?php if ($canChangeRole): ?>
                                            <form method="post" action="<?= portal_escape($adminUrl('users')) ?>" class="admin-inline-form">
                                                <?= portal_csrf_field() ?>
                                                <input type="hidden" name="action" value="change_role">
                                                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                                <select name="role" class="admin-role-select" onchange="this.form.submit()" title="Change role">
                                                    <option value="student"<?= $u['role'] === 'student' ? ' selected' : '' ?>>Student</option>
                                                    <option value="teacher"<?= $u['role'] === 'teacher' ? ' selected' : '' ?>>Teacher</option>
                                                    <?php if ($isOwner): ?>
                                                    <option value="admin"<?= $u['role'] === 'admin' ? ' selected' : '' ?>>Admin</option>
                                                    <?php endif; ?>
                                                </select>
                                            </form>
                                            <?php endif; ?>
                                            <?php if ($canManageUser && ($isOwner || $u['role'] === 'student' || $u['role'] === 'teacher')): ?>
                                            <form method="post" action="<?= portal_escape($adminUrl('users')) ?>" class="admin-inline-form"
                                                  onsubmit="return confirm('Delete account for <?= portal_escape($u['name']) ?>?')">
                                                <?= portal_csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                                <button type="submit" class="admin-btn admin-btn--danger admin-btn--sm">Delete</button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>

            <!-- Courses -->
            <section id="admin-section-courses" class="admin-section<?= $section === 'courses' ? ' is-active' : '' ?>">
                <article class="admin-card">
                    <header class="admin-card-header">
                        <div>
                            <p class="eyebrow">Modules</p>
                            <h3>Course management</h3>
                            <p class="admin-card-lead">Create, edit, archive, and duplicate course spaces. Closed courses stay locked for students until you open them or the scheduled open time.</p>
                        </div>
                        <button type="button" class="admin-btn admin-btn--primary" data-admin-open="create-course-panel">
                            <?= portal_icon('plus', 'icon-sm') ?> Add course
                        </button>
                    </header>

                    <form class="admin-filter-row" method="get" action="admin.php">
                        <input type="hidden" name="section" value="courses">
                        <label class="admin-search">
                            <span class="visually-hidden">Search courses</span>
                            <input type="search" name="course_q" value="<?= portal_escape($courseQuery) ?>" placeholder="Search title, code, or slug">
                        </label>
                        <label class="admin-field admin-field--inline">
                            <span>Status</span>
                            <select name="course_status">
                                <option value="all"<?= $courseStatus === 'all' ? ' selected' : '' ?>>All</option>
                                <option value="open"<?= $courseStatus === 'open' ? ' selected' : '' ?>>Open</option>
                                <option value="closed"<?= $courseStatus === 'closed' ? ' selected' : '' ?>>Closed</option>
                                <option value="draft"<?= $courseStatus === 'draft' ? ' selected' : '' ?>>Draft</option>
                                <option value="archived"<?= $courseStatus === 'archived' ? ' selected' : '' ?>>Archived</option>
                            </select>
                        </label>
                        <label class="admin-field admin-field--inline">
                            <span>Year</span>
                            <select name="course_year">
                                <option value="all">All years</option>
                                <?php foreach ($courseYearOptions as $yr): ?>
                                <option value="<?= portal_escape($yr) ?>"<?= $courseYear === $yr ? ' selected' : '' ?>><?= portal_escape($yr) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="submit" class="admin-btn admin-btn--secondary">Filter</button>
                    </form>

                    <div class="admin-table-wrap">
                        <table class="admin-table admin-table--courses">
                            <thead>
                                <tr>
                                    <th>Course</th>
                                    <th>Code</th>
                                    <th>Year</th>
                                    <th>Term</th>
                                    <th>Status</th>
                                    <th>Schedule</th>
                                    <th>Enrolled</th>
                                    <th>Staff</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($filteredAdminCourses as $c): ?>
                                <?php
                                    $statusKey = (string) $c['status'];
                                    $badgeClass = portal_course_status_badge_class($statusKey);
                                    $opensLabel = portal_course_opens_label((string) ($c['opens_at'] ?? ''));
                                    $archivesLabel = portal_course_opens_label((string) ($c['archives_at'] ?? ''));
                                    $statusHint = match ($statusKey) {
                                        'draft' => 'Staff only — students cannot see this course',
                                        'closed' => $opensLabel !== '' ? 'Locked until ' . $opensLabel : 'Locked — students cannot enter yet',
                                        'open' => $archivesLabel !== '' ? 'Open until ' . $archivesLabel : 'Students can enter',
                                        'archived' => 'Finished — kept for records',
                                        default => '',
                                    };
                                ?>
                                <tr class="admin-courses-tr admin-courses-tr--<?= portal_escape($statusKey) ?>" data-course-status="<?= portal_escape($statusKey) ?>">
                                    <td data-label="Course">
                                        <div class="admin-course-cell">
                                            <span class="admin-course-accent" style="background:<?= portal_escape((string) $c['accent']) ?>"></span>
                                            <div>
                                                <strong><?= portal_escape((string) $c['title']) ?></strong>
                                                <span class="admin-table-meta"><?= portal_escape((string) $c['slug']) ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td data-label="Code"><?= portal_escape((string) $c['code']) ?></td>
                                    <td data-label="Year"><?= portal_escape((string) $c['year_group']) ?></td>
                                    <td data-label="Term"><?= portal_escape((string) $c['term']) ?></td>
                                    <td data-label="Status">
                                        <div class="admin-status-cell">
                                            <span class="admin-badge <?= portal_escape($badgeClass) ?>"><?= portal_escape((string) $c['status_label']) ?></span>
                                            <?php if ($statusHint !== ''): ?>
                                                <span class="admin-table-meta"><?= portal_escape($statusHint) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td data-label="Schedule">
                                        <span><?= portal_escape((string) $c['meeting']) ?></span>
                                        <span class="admin-table-meta"><?= portal_escape((string) $c['room']) ?></span>
                                    </td>
                                    <td data-label="Enrolled"><?= (int) $c['enrollment_count'] ?></td>
                                    <td data-label="Staff"><?= (int) $c['assigned_staff_count'] ?></td>
                                    <td data-label="Actions">
                                        <div class="admin-table-actions">
                                            <a class="admin-btn admin-btn--secondary admin-btn--sm" href="course.php?course=<?= portal_escape((string) $c['slug']) ?>">View</a>
                                            <a class="admin-btn admin-btn--secondary admin-btn--sm" href="<?= portal_escape($adminUrl('courses', ['edit' => (int) $c['id']])) ?>">Edit</a>
                                            <a class="admin-btn admin-btn--secondary admin-btn--sm" href="<?= portal_escape($adminUrl('courses', ['duplicate' => (int) $c['id']])) ?>">Duplicate</a>
                                            <?php if ($c['status'] === 'archived'): ?>
                                            <form method="post" action="<?= portal_escape($adminUrl('courses')) ?>" class="admin-inline-form">
                                                <?= portal_csrf_field() ?>
                                                <input type="hidden" name="action" value="restore_course">
                                                <input type="hidden" name="course_id" value="<?= (int) $c['id'] ?>">
                                                <button type="submit" class="admin-btn admin-btn--primary admin-btn--sm">Restore</button>
                                            </form>
                                            <?php else: ?>
                                            <form method="post" action="<?= portal_escape($adminUrl('courses')) ?>" class="admin-inline-form"
                                                  onsubmit="return confirm('Archive <?= portal_escape((string) $c['title']) ?>? All data will be kept.')">
                                                <?= portal_csrf_field() ?>
                                                <input type="hidden" name="action" value="archive_course">
                                                <input type="hidden" name="course_id" value="<?= (int) $c['id'] ?>">
                                                <button type="submit" class="admin-btn admin-btn--secondary admin-btn--sm">Archive</button>
                                            </form>
                                            <?php endif; ?>
                                            <?php if ($isOwner): ?>
                                            <form method="post" action="<?= portal_escape($adminUrl('courses')) ?>" class="admin-inline-form"
                                                  onsubmit="return confirm('Permanently delete this course? Only empty courses can be removed.')">
                                                <?= portal_csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_course">
                                                <input type="hidden" name="course_id" value="<?= (int) $c['id'] ?>">
                                                <button type="submit" class="admin-btn admin-btn--danger admin-btn--sm">Delete</button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if ($filteredAdminCourses === []): ?>
                                <tr><td colspan="9" class="admin-table-empty">No courses match your filters.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>

            <!-- Activities -->
            <section id="admin-section-activities" class="admin-section<?= $section === 'activities' ? ' is-active' : '' ?>">
                <div class="admin-stat-grid">
                    <article class="admin-stat-card admin-stat-card--priority">
                        <p class="admin-stat-label">Activities</p>
                        <strong class="admin-stat-value"><?= (int) ($stats['total_activities'] ?? 0) ?></strong>
                    </article>
                    <article class="admin-stat-card">
                        <p class="admin-stat-label">Enabled badges</p>
                        <strong class="admin-stat-value"><?= (int) $badgeCount ?></strong>
                    </article>
                    <article class="admin-stat-card">
                        <p class="admin-stat-label">Templates</p>
                        <strong class="admin-stat-value"><?= (int) $templateCount ?></strong>
                    </article>
                </div>

                <article class="admin-card">
                    <header class="admin-card-header">
                        <div>
                            <p class="eyebrow">Site overview</p>
                            <h3>Activities across courses</h3>
                            <p class="admin-card-lead">Search and filter published or draft activities. Open the builder or results for a course you manage. Student attempt details remain course-scoped.</p>
                        </div>
                    </header>
                    <form class="admin-filter-row" method="get" action="admin.php">
                        <input type="hidden" name="section" value="activities">
                        <label class="admin-field admin-field--inline admin-field--grow">
                            <span>Search</span>
                            <input type="search" name="act_q" value="<?= portal_escape($actSearch) ?>" placeholder="Title, course, or author">
                        </label>
                        <label class="admin-field admin-field--inline">
                            <span>Mode</span>
                            <select name="act_mode">
                                <option value="">All modes</option>
                                <?php foreach (portal_activity_modes() as $mode): ?>
                                <option value="<?= portal_escape($mode) ?>"<?= $actFilterMode === $mode ? ' selected' : '' ?>><?= portal_escape(portal_activity_mode_label($mode)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="admin-field admin-field--inline">
                            <span>Status</span>
                            <select name="act_status">
                                <option value="">All statuses</option>
                                <?php foreach (['draft', 'published', 'archived'] as $st): ?>
                                <option value="<?= $st ?>"<?= $actFilterStatus === $st ? ' selected' : '' ?>><?= portal_escape(ucfirst($st)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="submit" class="button button--sm">Filter</button>
                    </form>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Activity</th>
                                    <th>Course</th>
                                    <th>Mode</th>
                                    <th>Status</th>
                                    <th>Author</th>
                                    <th>Updated</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($adminActivities as $row): ?>
                                <tr>
                                    <td><?= portal_escape((string) $row['title']) ?></td>
                                    <td><?= portal_escape((string) ($row['course_code'] ?: $row['course_title'])) ?></td>
                                    <td><span class="activity-mode-pill activity-mode-pill--<?= portal_escape((string) $row['mode']) ?>"><?= portal_escape(portal_activity_mode_label((string) $row['mode'])) ?></span></td>
                                    <td><?= portal_escape(ucfirst((string) $row['status'])) ?></td>
                                    <td><?= portal_escape((string) ($row['author_name'] ?: '—')) ?></td>
                                    <td><?= portal_escape((string) $row['updated_at']) ?></td>
                                    <td class="admin-table-actions">
                                        <a class="inline-action" href="activity-builder.php?id=<?= (int) $row['id'] ?>">Builder</a>
                                        <a class="inline-action" href="activity-results.php?id=<?= (int) $row['id'] ?>">Results</a>
                                        <a class="inline-action" href="course.php?course=<?= urlencode((string) $row['course_slug']) ?>">Course</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if ($adminActivities === []): ?>
                                <tr><td colspan="7" class="admin-table-empty">No activities match your filters.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="admin-card">
                    <header class="admin-card-header">
                        <div>
                            <p class="eyebrow">Audit</p>
                            <h3>Recent activity changes</h3>
                            <p class="admin-card-lead">Staff create, publish, and marking actions. Attempt tokens and full student answers are not stored here.</p>
                        </div>
                    </header>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>When</th>
                                    <th>Actor</th>
                                    <th>Action</th>
                                    <th>Activity</th>
                                    <th>Course</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activityAuditRows as $row): ?>
                                <tr>
                                    <td><?= portal_escape((string) $row['created_at']) ?></td>
                                    <td><?= portal_escape((string) ($row['actor_name'] ?: '—')) ?></td>
                                    <td><?= portal_escape((string) $row['action']) ?></td>
                                    <td><?= portal_escape((string) ($row['activity_title'] ?: ('#' . (int) $row['activity_id']))) ?></td>
                                    <td><?= portal_escape((string) ($row['course_title'] ?: '—')) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if ($activityAuditRows === []): ?>
                                <tr><td colspan="5" class="admin-table-empty">No activity audit events yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="admin-card-lead" style="margin-top:1rem;">
                        Site-wide question bank: <a href="question-bank.php">Open question bank</a>.
                        External web originality remains optional and shows as not configured until a provider is set.
                    </p>
                </article>
            </section>

            <!-- Enrolments -->
            <section id="admin-section-enrollments" class="admin-section<?= $section === 'enrollments' ? ' is-active' : '' ?>">
                <article class="admin-card">
                    <header class="admin-card-header">
                        <div>
                            <p class="eyebrow">Course access</p>
                            <h3>Manage enrolments</h3>
                            <p class="admin-card-lead">Select a user, then tick the modules they should access. Teachers are saved as course assignments (not student enrolments).</p>
                        </div>
                    </header>

                    <form class="admin-filter-row" method="get" action="admin.php">
                        <input type="hidden" name="section" value="enrollments">
                        <label class="admin-field admin-field--inline admin-field--grow">
                            <span>Select user</span>
                            <select name="enroll" onchange="this.form.submit()">
                                <option value="">Choose a user…</option>
                                <?php foreach ($users as $u): ?>
                                <option value="<?= (int) $u['id'] ?>"<?= $enrollTargetId === (int) $u['id'] ? ' selected' : '' ?>>
                                    <?= portal_escape($u['name']) ?> (<?= portal_escape($u['username']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </form>

                    <?php if ($enrollTarget): ?>
                    <div class="admin-enroll-target">
                        <div class="admin-table-user">
                            <div class="admin-avatar"><?= portal_escape($enrollTarget['initials']) ?></div>
                            <div>
                                <strong><?= portal_escape($enrollTarget['name']) ?></strong>
                                <span class="admin-table-meta"><?= portal_escape($enrollTarget['email']) ?> · <?= count($enrolledIds) ?> of <?= count($allCourses) ?> courses<?= $enrollTargetIsTeacher ? ' · teacher assignments' : '' ?></span>
                            </div>
                        </div>
                    </div>

                    <form method="post" action="<?= portal_escape($adminUrl('enrollments', ['enroll' => $enrollTargetId])) ?>">
                        <?= portal_csrf_field() ?>
                        <input type="hidden" name="action" value="save_enrollments">
                        <input type="hidden" name="user_id" value="<?= (int) $enrollTarget['id'] ?>">

                        <label class="admin-search admin-search--block">
                            <span class="visually-hidden">Filter courses</span>
                            <input type="search" id="enroll-course-filter" placeholder="Filter courses by title or code" value="<?= portal_escape($enrollCourseQ) ?>">
                        </label>

                        <div class="admin-enroll-grid" id="enroll-course-grid">
                            <?php foreach ($allCourses as $course): ?>
                            <?php
                                $checked = in_array((int) $course['id'], $enrolledIds, true);
                                $cStatus = (string) $course['status'];
                                $cBadge = portal_course_status_badge_class($cStatus);
                            ?>
                            <label class="admin-enroll-item<?= $checked ? ' enrolled' : '' ?>"
                                   data-enroll-search="<?= portal_escape(strtolower($course['title'] . ' ' . $course['code'])) ?>">
                                <input type="checkbox" name="course_ids[]" value="<?= (int) $course['id'] ?>"<?= $checked ? ' checked' : '' ?>>
                                <span class="admin-enroll-accent" style="background:<?= portal_escape($course['accent']) ?>"></span>
                                <div class="admin-enroll-body">
                                    <div class="admin-enroll-text">
                                        <strong><?= portal_escape($course['title']) ?></strong>
                                        <span><?= portal_escape($course['code']) ?></span>
                                    </div>
                                    <span class="admin-badge <?= portal_escape($cBadge) ?>"><?= portal_escape($course['status_label']) ?></span>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="admin-form-actions">
                            <button type="submit" class="admin-btn admin-btn--primary"><?= $enrollTargetIsTeacher ? 'Save course assignments' : 'Save enrolments' ?></button>
                            <a href="<?= portal_escape($adminUrl('enrollments')) ?>" class="admin-btn admin-btn--secondary">Clear selection</a>
                        </div>
                    </form>
                    <?php else: ?>
                    <p class="admin-card-lead">Choose a user above to manage their course enrolments.</p>
                    <?php endif; ?>
                </article>
            </section>

            <!-- Student invites -->
            <section id="admin-section-invites" class="admin-section<?= $section === 'invites' ? ' is-active' : '' ?>">
                <?php if ($inviteLinkOnce !== ''): ?>
                <article class="admin-card">
                    <header class="admin-card-header">
                        <div>
                            <p class="eyebrow">Share once</p>
                            <h3>Invite link</h3>
                            <p class="admin-card-lead">Copy this link now — it will not be shown again. Only the invited email can create an account with it.</p>
                        </div>
                    </header>
                    <label class="admin-field admin-field--full">
                        <span>Invite URL</span>
                        <input type="text" id="invite-link-once" readonly value="<?= portal_escape($inviteLinkOnce) ?>" onclick="this.select()">
                    </label>
                    <div class="admin-form-actions">
                        <button type="button" class="admin-btn admin-btn--primary" id="invite-copy-btn" data-copy-target="invite-link-once">Copy link</button>
                    </div>
                </article>
                <?php endif; ?>

                <article class="admin-card">
                    <header class="admin-card-header">
                        <div>
                            <p class="eyebrow">New invite</p>
                            <h3>Invite a student</h3>
                            <p class="admin-card-lead">Single-use, email-bound signup. Pick one or more courses below, then create the invite. Optional name/year locks apply at signup.</p>
                        </div>
                    </header>
                    <form class="admin-invite-form" method="post" action="<?= portal_escape($adminUrl('invites')) ?>" id="invite-create-form">
                        <?= portal_csrf_field() ?>
                        <input type="hidden" name="action" value="create_invite">

                        <div class="admin-form-grid">
                            <label class="admin-field">
                                <span>Student email</span>
                                <input type="email" name="email" required placeholder="student@school.edu" autocomplete="off">
                            </label>
                            <label class="admin-field">
                                <span>Expires in</span>
                                <select name="expires_days">
                                    <option value="1">1 day</option>
                                    <option value="3">3 days</option>
                                    <option value="7" selected>7 days</option>
                                    <option value="14">14 days</option>
                                    <option value="30">30 days</option>
                                </select>
                            </label>
                            <label class="admin-field">
                                <span>Lock display name <small>(optional)</small></span>
                                <input type="text" name="locked_name" maxlength="200" placeholder="Leave blank to let them choose">
                            </label>
                            <label class="admin-field">
                                <span>Lock year group <small>(optional)</small></span>
                                <select name="locked_year">
                                    <option value="">Unlocked — student chooses</option>
                                    <?php foreach ($yearGroupOptions as $yr): ?>
                                    <option value="<?= portal_escape($yr) ?>"><?= portal_escape($yr) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>

                        <div class="admin-invite-courses" data-invite-courses>
                            <div class="admin-invite-courses-label-row">
                                <span>Enrol into courses</span>
                                <span class="admin-invite-courses-required">Required</span>
                            </div>

                            <button type="button"
                                    class="admin-invite-courses-toggle"
                                    id="invite-courses-toggle"
                                    aria-expanded="false"
                                    aria-controls="invite-courses-panel">
                                <span class="admin-invite-courses-toggle-copy">
                                    <strong data-invite-courses-title>Choose modules</strong>
                                    <small data-invite-courses-summary>Click to select one or more courses for this invite</small>
                                </span>
                                <span class="admin-invite-courses-meta">
                                    <span class="admin-invite-courses-badge" data-invite-selected-count>0</span>
                                    <span class="admin-invite-courses-chevron" aria-hidden="true"></span>
                                </span>
                            </button>

                            <div class="admin-invite-courses-chips" data-invite-courses-chips hidden></div>

                            <div class="admin-invite-courses-panel" id="invite-courses-panel" hidden>
                                <div class="admin-invite-courses-panel-inner">
                                    <div class="admin-invite-courses-toolbar">
                                        <label class="admin-search admin-search--block admin-invite-courses-filter">
                                            <span class="visually-hidden">Filter courses</span>
                                            <input type="search" id="invite-course-filter" placeholder="Filter by title or code" autocomplete="off">
                                        </label>
                                        <div class="admin-invite-courses-actions">
                                            <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm" data-invite-select-all>Select all</button>
                                            <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm" data-invite-clear-all>Clear</button>
                                        </div>
                                    </div>
                                    <div class="admin-enroll-grid" id="invite-course-grid">
                                        <?php foreach ($inviteCourses as $ic): ?>
                                        <?php
                                            $cStatus = (string) ($ic['status'] ?? '');
                                            $cBadge = portal_course_status_badge_class($cStatus);
                                        ?>
                                        <label class="admin-enroll-item"
                                               data-invite-course
                                               data-course-title="<?= portal_escape((string) $ic['title']) ?>"
                                               data-enroll-search="<?= portal_escape(strtolower(($ic['title'] ?? '') . ' ' . ($ic['code'] ?? ''))) ?>">
                                            <input type="checkbox" name="course_ids[]" value="<?= (int) $ic['id'] ?>">
                                            <span class="admin-enroll-accent" style="background:<?= portal_escape((string) ($ic['accent'] ?? '#c1202f')) ?>"></span>
                                            <div class="admin-enroll-body">
                                                <div class="admin-enroll-text">
                                                    <strong><?= portal_escape((string) $ic['title']) ?></strong>
                                                    <span><?= portal_escape((string) $ic['code']) ?></span>
                                                </div>
                                                <span class="admin-badge <?= portal_escape($cBadge) ?>"><?= portal_escape((string) ($ic['status_label'] ?? $cStatus)) ?></span>
                                            </div>
                                        </label>
                                        <?php endforeach; ?>
                                        <?php if ($inviteCourses === []): ?>
                                        <p class="admin-card-lead">No courses available yet. Create a course first.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="admin-form-actions">
                            <button type="submit" class="admin-btn admin-btn--primary">Create invite</button>
                        </div>
                    </form>
                </article>

                <article class="admin-card">
                    <header class="admin-card-header">
                        <div>
                            <p class="eyebrow">Pending</p>
                            <h3>Open invites</h3>
                        </div>
                        <span class="chip"><?= count($pendingInvites) ?></span>
                    </header>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Email</th>
                                    <th>Courses</th>
                                    <th>Locks</th>
                                    <th>Expires</th>
                                    <th>Invited by</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingInvites as $inv): ?>
                                <?php
                                    $lockBits = [];
                                    if (trim((string) ($inv['locked_name'] ?? '')) !== '') {
                                        $lockBits[] = 'Name';
                                    }
                                    if (trim((string) ($inv['locked_year'] ?? '')) !== '') {
                                        $lockBits[] = 'Year';
                                    }
                                    $courseCount = (int) ($inv['course_count'] ?? 0);
                                    $courseList = (array) ($inv['course_titles'] ?? []);
                                ?>
                                <tr>
                                    <td><?= portal_escape((string) $inv['email']) ?></td>
                                    <td>
                                        <?php if ($courseCount <= 1): ?>
                                            <strong><?= portal_escape((string) ($courseList[0] ?? $inv['course_title'] ?? '—')) ?></strong>
                                            <span class="admin-table-meta"><?= portal_escape((string) ($inv['course_code'] ?? '')) ?></span>
                                        <?php else: ?>
                                            <strong><?= $courseCount ?> courses</strong>
                                            <span class="admin-table-meta"><?= portal_escape(implode(' · ', array_slice($courseList, 0, 3))) ?><?= $courseCount > 3 ? '…' : '' ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $lockBits === [] ? '—' : portal_escape(implode(', ', $lockBits)) ?></td>
                                    <td><?= portal_escape(date('j M Y H:i', (int) $inv['expires_at'])) ?></td>
                                    <td><?= portal_escape((string) ($inv['invited_by_name'] ?? '—')) ?></td>
                                    <td>
                                        <form method="post" action="<?= portal_escape($adminUrl('invites')) ?>" onsubmit="return confirm('Revoke this invite?');">
                                            <?= portal_csrf_field() ?>
                                            <input type="hidden" name="action" value="revoke_invite">
                                            <input type="hidden" name="invite_id" value="<?= (int) $inv['id'] ?>">
                                            <button type="submit" class="admin-btn admin-btn--secondary admin-btn--sm">Revoke</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if ($pendingInvites === []): ?>
                                <tr><td colspan="6" class="admin-table-empty">No pending invites.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>

            <!-- Integrity -->
            <section id="admin-section-integrity" class="admin-section<?= $section === 'integrity' ? ' is-active' : '' ?>">
                <div class="admin-status-row admin-integrity-summary">
                    <article class="admin-card admin-card--compact admin-summary-card">
                        <p class="admin-stat-label">External AI policy</p>
                        <strong><?= portal_escape($integrityPolicyLabel) ?></strong>
                        <p><?= portal_escape($integrityPolicySummary) ?></p>
                        <span class="admin-badge <?= portal_escape($integrityPolicyBadgeClass) ?>"><?= portal_escape($integrityPolicyBadge) ?></span>
                    </article>
                    <article class="admin-card admin-card--compact admin-summary-card">
                        <p class="admin-stat-label">GPTZero API key</p>
                        <strong><?= $integrityKeySet ? 'Configured' : 'Missing key' ?></strong>
                        <p><?= $integrityKeySet ? 'External AI checks available.' : 'External AI checks unavailable.' ?></p>
                        <span class="admin-badge <?= $integrityKeySet ? 'admin-badge--open' : 'admin-badge--archived' ?>"><?= $integrityKeySet ? 'Configured' : 'Missing key' ?></span>
                    </article>
                    <article class="admin-card admin-card--compact admin-summary-card">
                        <p class="admin-stat-label">Google Safe Browsing</p>
                        <strong><?= $safeBrowsingKeySet ? 'Configured' : 'Missing key' ?></strong>
                        <p><?= $safeBrowsingKeySet ? 'External link checks enabled.' : 'External link checks unavailable.' ?></p>
                        <span class="admin-badge <?= $safeBrowsingKeySet ? 'admin-badge--open' : 'admin-badge--archived' ?>"><?= $safeBrowsingKeySet ? 'Configured' : 'Missing key' ?></span>
                    </article>
                </div>

                <article class="admin-card">
                    <header class="admin-card-header">
                        <div>
                            <p class="eyebrow">Integrity</p>
                            <h3>Integrity and link safety</h3>
                            <p class="admin-card-lead">GPTZero is used for optional external AI detection when configured. Google Safe Browsing checks external course links before users open them.</p>
                        </div>
                    </header>

                    <form method="post" action="<?= portal_escape($adminUrl('integrity')) ?>" class="admin-integrity-form">
                        <?= portal_csrf_field() ?>
                        <input type="hidden" name="action" value="save_integrity_settings">

                        <div class="admin-key-grid">
                            <section class="admin-key-card" aria-labelledby="gptzero-key-title">
                                <div class="admin-key-card__header">
                                    <div>
                                        <h4 id="gptzero-key-title">GPTZero API Key</h4>
                                        <p>GPTZero external AI checks use this key when the policy allows them.</p>
                                    </div>
                                    <span class="admin-badge <?= $integrityKeySet ? 'admin-badge--open' : 'admin-badge--archived' ?>"><?= $integrityKeySet ? 'Configured' : 'Missing key' ?></span>
                                </div>
                                <label class="admin-field" for="gptzero-api-key">
                                    <span class="visually-hidden">GPTZero API Key</span>
                                    <input id="gptzero-api-key" type="password" name="gptzero_api_key" autocomplete="new-password" placeholder="Paste your GPTZero API key" data-gptzero-saved-key="<?= $integrityKeySet ? '1' : '0' ?>">
                                </label>
                                <small class="admin-field-hint">Leave blank to keep the current key. Keys are stored in the site database and never shown after saving.</small>
                                <?php if ($integrityKeySet): ?>
                                <label class="admin-remove-key">
                                    <input type="checkbox" name="clear_gptzero_api_key" value="1" data-remove-key>
                                    <span>
                                        <strong>Remove saved GPTZero key</strong>
                                        <small>This saved key will be removed when you save settings.</small>
                                    </span>
                                </label>
                                <?php endif; ?>
                            </section>

                            <section class="admin-key-card" aria-labelledby="safe-browsing-key-title">
                                <div class="admin-key-card__header">
                                    <div>
                                        <h4 id="safe-browsing-key-title">Google Safe Browsing API Key</h4>
                                        <p>Used server-side before users open external course links.</p>
                                    </div>
                                    <span class="admin-badge <?= $safeBrowsingKeySet ? 'admin-badge--open' : 'admin-badge--archived' ?>"><?= $safeBrowsingKeySet ? 'Configured' : 'Missing key' ?></span>
                                </div>
                                <label class="admin-field" for="google-safe-browsing-api-key">
                                    <span class="visually-hidden">Google Safe Browsing API Key</span>
                                    <input id="google-safe-browsing-api-key" type="password" name="google_safe_browsing_api_key" autocomplete="new-password" placeholder="Paste your Google Safe Browsing API key">
                                </label>
                                <small class="admin-field-hint">Used server-side before users open external course links.</small>
                                <?php if ($safeBrowsingKeySet): ?>
                                <label class="admin-remove-key">
                                    <input type="checkbox" name="clear_google_safe_browsing_api_key" value="1" data-remove-key>
                                    <span>
                                        <strong>Remove saved Google Safe Browsing key</strong>
                                        <small>This saved key will be removed when you save settings.</small>
                                    </span>
                                </label>
                                <?php endif; ?>
                            </section>
                        </div>

                        <fieldset class="admin-policy-fieldset">
                            <legend>When should external AI detection run?</legend>
                            <label class="admin-policy-card">
                                <input type="radio" name="external_ai_policy" value="disabled"<?= $integrityPolicy === 'disabled' ? ' checked' : '' ?>>
                                <span class="admin-policy-card__body">
                                    <strong>Disabled</strong>
                                    <small>Internal integrity checks still run. No GPTZero API call is made.</small>
                                </span>
                            </label>
                            <label class="admin-policy-card">
                                <input type="radio" name="external_ai_policy" value="site_wide"<?= $integrityPolicy === 'site_wide' ? ' checked' : '' ?>>
                                <span class="admin-policy-card__body">
                                    <strong>Site-wide</strong>
                                    <small>Run GPTZero checks for every submission when an API key is configured.</small>
                                    <small class="admin-key-required-warning<?= $integrityKeySet ? ' is-hidden' : '' ?>" data-gptzero-required-warning>Requires GPTZero API key.</small>
                                </span>
                            </label>
                            <label class="admin-policy-card">
                                <input type="radio" name="external_ai_policy" value="per_module"<?= $integrityPolicy === 'per_module' ? ' checked' : '' ?>>
                                <span class="admin-policy-card__body">
                                    <strong>Selected modules</strong>
                                    <small>Only selected modules can use GPTZero checks.</small>
                                    <small class="admin-key-required-warning<?= $integrityKeySet ? ' is-hidden' : '' ?>" data-gptzero-required-warning>Requires GPTZero API key.</small>
                                </span>
                            </label>
                        </fieldset>

                        <p class="admin-policy-help" id="admin-ai-policy-help" aria-live="polite">
                            <?= $integrityPolicy === 'disabled'
                                ? 'External GPTZero checks are disabled. Internal integrity checks still run.'
                                : ($integrityPolicy === 'site_wide'
                                    ? ($integrityKeySet ? 'GPTZero checks will run for every submission.' : 'Add a GPTZero API key before site-wide checks can run.')
                                    : ($integrityKeySet ? 'Choose which modules can use GPTZero external AI detection.' : 'A GPTZero API key is required before selected-module checks can run.')) ?>
                        </p>

                        <div class="admin-ai-modules admin-collapse<?= $integrityPolicy === 'per_module' ? ' admin-collapse--open is-visible' : ' is-hidden' ?>" id="admin-ai-modules"<?= $integrityPolicy === 'per_module' ? '' : ' hidden' ?>>
                            <p class="admin-key-required-warning admin-key-required-warning--block<?= $integrityKeySet ? ' is-hidden' : '' ?>" data-gptzero-module-warning>A GPTZero API key is required before selected-module checks can run.</p>
                            <p class="admin-field-hint">Choose which modules can use GPTZero external AI detection.</p>
                            <div class="admin-ai-module-grid">
                                <?php foreach ($integrityCourses as $ic): ?>
                                <?php
                                    $checked = (int) ($ic['external_ai_detection'] ?? 0) === 1;
                                    $accent = (string) ($ic['accent'] ?? '#c1202f');
                                    if (!portal_valid_course_accent($accent)) {
                                        $accent = '#c1202f';
                                    }
                                    $status = trim((string) ($ic['status'] ?? ''));
                                    $statusLabel = trim((string) ($ic['status_label'] ?? ''));
                                    if ($statusLabel === '' && $status !== '') {
                                        $statusLabel = ucfirst($status);
                                    }
                                    $statusClass = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($status !== '' ? $status : $statusLabel));
                                ?>
                                <label class="admin-ai-module-card<?= $checked ? ' is-selected' : '' ?>" style="--course-accent: <?= portal_escape($accent) ?>">
                                    <input type="checkbox" name="external_ai_courses[]" value="<?= (int) $ic['id'] ?>"<?= $checked ? ' checked' : '' ?>>
                                    <span class="admin-ai-module-accent" aria-hidden="true"></span>
                                    <span class="admin-ai-module-check" aria-hidden="true"></span>
                                    <span class="admin-ai-module-text">
                                        <strong><?= portal_escape((string) $ic['title']) ?></strong>
                                        <small><?= portal_escape((string) $ic['code']) ?></small>
                                    </span>
                                    <?php if ($statusLabel !== ''): ?>
                                    <span class="admin-course-status admin-course-status--<?= portal_escape((string) $statusClass) ?>"><?= portal_escape($statusLabel) ?></span>
                                    <?php endif; ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="admin-form-actions">
                            <button type="submit" class="admin-btn admin-btn--primary admin-btn--save"><?= portal_icon('settings', 'icon-sm') ?> Save settings</button>
                        </div>
                    </form>
                </article>
            </section>

            <!-- Security Activity -->
            <section id="admin-section-security" class="admin-section<?= $section === 'security' ? ' is-active' : '' ?>">
                <?php if ($systemNeedsDevReview): ?>
                <div class="admin-flash error">
                    <?= portal_icon('lock', 'admin-flash-icon') ?>
                    <span>System configuration requires developer review. Contact your system developer if this message persists.</span>
                </div>
                <?php endif; ?>

                <div class="admin-stat-grid admin-stat-grid--security">
                    <article class="admin-stat-card">
                        <p class="admin-stat-label">Active alerts</p>
                        <strong class="admin-stat-value"><?= (int) $securityStats['active_alerts'] ?></strong>
                        <p class="admin-stat-caption"><?= $securityStats['active_alerts'] === 0 ? 'No unresolved alerts' : 'Medium/high events need review' ?></p>
                    </article>
                    <article class="admin-stat-card">
                        <p class="admin-stat-label">Failed logins</p>
                        <strong class="admin-stat-value"><?= (int) $securityStats['failed_logins'] ?></strong>
                        <p class="admin-stat-caption"><?= $securityStats['failed_logins'] === 0 ? 'No failed logins in period' : $securityStats['failed_logins'] . ' in selected period' ?></p>
                    </article>
                    <article class="admin-stat-card">
                        <p class="admin-stat-label">Blocked access</p>
                        <strong class="admin-stat-value"><?= (int) $securityStats['blocked_access'] ?></strong>
                        <p class="admin-stat-caption"><?= $securityStats['blocked_access'] === 0 ? 'No blocked access attempts' : 'Admin, course, or download blocks' ?></p>
                    </article>
                    <article class="admin-stat-card">
                        <p class="admin-stat-label">Grade changes</p>
                        <strong class="admin-stat-value"><?= (int) ($securityStats['grade_changes'] ?? 0) ?></strong>
                        <p class="admin-stat-caption">Who marked or changed submission grades</p>
                    </article>
                    <article class="admin-stat-card">
                        <p class="admin-stat-label">Profile changes</p>
                        <strong class="admin-stat-value"><?= (int) ($securityStats['profile_changes'] ?? 0) ?></strong>
                        <p class="admin-stat-caption">Profile edits and password changes</p>
                    </article>
                    <article class="admin-stat-card">
                        <p class="admin-stat-label">Admin actions</p>
                        <strong class="admin-stat-value"><?= (int) $securityStats['admin_actions'] ?></strong>
                        <p class="admin-stat-caption">Role changes, deletions, status changes</p>
                    </article>
                </div>

                <article class="admin-card">
                    <header class="admin-card-header">
                        <div>
                            <p class="eyebrow">Detection</p>
                            <h3>Flagged patterns</h3>
                            <p class="admin-card-lead">First-party heuristics over existing security events (credential stuffing, account targeting, repeated lockouts, multi-vector probing).</p>
                        </div>
                    </header>
                    <?php if ($securityIncidents === []): ?>
                        <p class="admin-card-lead" style="margin:0;">No breach patterns matched in this period.</p>
                    <?php else: ?>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Severity</th>
                                    <th>Pattern</th>
                                    <th>Summary</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($securityIncidents as $incident): ?>
                                <?php
                                    $incidentIds = implode(',', array_map('intval', $incident['event_ids'] ?? []));
                                    $incidentFilter = array_filter([
                                        'sec_period' => $secPeriod,
                                        'sec_ids' => $incidentIds,
                                        'sec_ip' => (string) ($incident['ip'] ?? ''),
                                    ], static fn($v) => $v !== null && $v !== '');
                                ?>
                                <tr>
                                    <td><span class="admin-severity admin-severity--<?= portal_escape((string) $incident['severity']) ?>"><?= portal_escape(ucfirst((string) $incident['severity'])) ?></span></td>
                                    <td><strong><?= portal_escape((string) $incident['label']) ?></strong></td>
                                    <td><?= portal_escape((string) $incident['summary']) ?></td>
                                    <td>
                                        <div class="admin-inline-actions">
                                            <a class="admin-btn admin-btn--secondary admin-btn--sm" href="<?= portal_escape($adminUrl('security', $incidentFilter)) ?>">View events</a>
                                            <form method="post" action="<?= portal_escape($adminUrl('security', $securityFilterParams)) ?>" class="admin-inline-form">
                                                <?= portal_csrf_field() ?>
                                                <input type="hidden" name="action" value="bulk_security_action">
                                                <input type="hidden" name="bulk_action" value="mark_reviewed">
                                                <input type="hidden" name="sec_period" value="<?= portal_escape($secPeriod) ?>">
                                                <input type="hidden" name="sec_reviewed" value="<?= portal_escape($secReviewed) ?>">
                                                <input type="hidden" name="sec_severity" value="<?= portal_escape($secSeverity) ?>">
                                                <input type="hidden" name="sec_type" value="<?= portal_escape($secType) ?>">
                                                <input type="hidden" name="sec_ip" value="<?= portal_escape($secIp) ?>">
                                                <input type="hidden" name="sec_ids" value="<?= portal_escape($secIdsRaw) ?>">
                                                <?php foreach (($incident['event_ids'] ?? []) as $incidentEventId): ?>
                                                <input type="hidden" name="event_ids[]" value="<?= (int) $incidentEventId ?>">
                                                <?php endforeach; ?>
                                                <button type="submit" class="admin-btn admin-btn--secondary admin-btn--sm">Mark this incident reviewed</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </article>

                <article class="admin-card">
                    <header class="admin-card-header">
                        <div>
                            <p class="eyebrow">Correlation</p>
                            <h3>Most active IPs</h3>
                            <p class="admin-card-lead">Top source addresses in the selected period, ranked by event volume.</p>
                        </div>
                    </header>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>IP</th>
                                    <th>Events</th>
                                    <th>Types</th>
                                    <th>Usernames</th>
                                    <th>Unreviewed</th>
                                    <th>Last seen</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($securityIpSummary as $ipRow): ?>
                                <?php $ipValue = (string) ($ipRow['ip_address'] ?? ''); ?>
                                <tr>
                                    <td>
                                        <a href="<?= portal_escape($adminUrl('security', [
                                            'sec_period' => $secPeriod,
                                            'sec_ip' => $ipValue,
                                        ])) ?>"><?= portal_escape($ipValue) ?></a>
                                    </td>
                                    <td><?= (int) ($ipRow['event_count'] ?? 0) ?></td>
                                    <td><?= (int) ($ipRow['distinct_event_types'] ?? 0) ?></td>
                                    <td><?= (int) ($ipRow['distinct_usernames'] ?? 0) ?></td>
                                    <td><?= (int) ($ipRow['unreviewed_count'] ?? 0) ?></td>
                                    <td><?= portal_escape(date('j M Y H:i', strtotime((string) ($ipRow['last_seen'] ?? 'now')))) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if ($securityIpSummary === []): ?>
                                <tr><td colspan="6" class="admin-table-empty">No IP activity in this period.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="admin-card">
                    <header class="admin-card-header">
                        <div>
                            <p class="eyebrow">Activity log</p>
                            <h3>Recent security events</h3>
                            <p class="admin-card-lead">Suspicious sign-ins, blocked access, grade and profile changes, and admin actions. Click a username or <strong>Take action</strong> to review the account without leaving this page.</p>
                        </div>
                        <form method="post" action="<?= portal_escape($adminUrl('security', $securityFilterParams)) ?>" class="admin-inline-form">
                            <?= portal_csrf_field() ?>
                            <input type="hidden" name="action" value="mark_security_low_info_reviewed">
                            <input type="hidden" name="sec_period" value="<?= portal_escape($secPeriod) ?>">
                            <input type="hidden" name="sec_reviewed" value="<?= portal_escape($secReviewed) ?>">
                            <input type="hidden" name="sec_severity" value="<?= portal_escape($secSeverity) ?>">
                            <input type="hidden" name="sec_type" value="<?= portal_escape($secType) ?>">
                            <input type="hidden" name="sec_ip" value="<?= portal_escape($secIp) ?>">
                            <button type="submit" class="admin-btn admin-btn--secondary admin-btn--sm">Mark low/info reviewed</button>
                        </form>
                    </header>

                    <form class="admin-filter-row" method="get" action="admin.php">
                        <input type="hidden" name="section" value="security">
                        <label class="admin-field admin-field--inline">
                            <span>Period</span>
                            <select name="sec_period">
                                <option value="24h"<?= $secPeriod === '24h' ? ' selected' : '' ?>>Last 24 hours</option>
                                <option value="7d"<?= $secPeriod === '7d' ? ' selected' : '' ?>>Last 7 days</option>
                                <option value="30d"<?= $secPeriod === '30d' ? ' selected' : '' ?>>Last 30 days</option>
                            </select>
                        </label>
                        <label class="admin-field admin-field--inline">
                            <span>Reviewed</span>
                            <select name="sec_reviewed">
                                <option value="all"<?= $secReviewed === 'all' ? ' selected' : '' ?>>All</option>
                                <option value="unreviewed"<?= $secReviewed === 'unreviewed' ? ' selected' : '' ?>>Unreviewed</option>
                                <option value="reviewed"<?= $secReviewed === 'reviewed' ? ' selected' : '' ?>>Reviewed</option>
                            </select>
                        </label>
                        <label class="admin-field admin-field--inline">
                            <span>Severity</span>
                            <select name="sec_severity">
                                <option value="all"<?= $secSeverity === 'all' ? ' selected' : '' ?>>All</option>
                                <option value="info"<?= $secSeverity === 'info' ? ' selected' : '' ?>>Info</option>
                                <option value="low"<?= $secSeverity === 'low' ? ' selected' : '' ?>>Low</option>
                                <option value="medium"<?= $secSeverity === 'medium' ? ' selected' : '' ?>>Medium</option>
                                <option value="high"<?= $secSeverity === 'high' ? ' selected' : '' ?>>High</option>
                            </select>
                        </label>
                        <label class="admin-field admin-field--inline">
                            <span>Event type</span>
                            <select name="sec_type">
                                <option value="all"<?= $secType === 'all' ? ' selected' : '' ?>>All types</option>
                                <?php foreach ($securityTypes as $typeKey): ?>
                                <option value="<?= portal_escape($typeKey) ?>"<?= $secType === $typeKey ? ' selected' : '' ?>><?= portal_escape(portal_security_event_type_label($typeKey)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="admin-field admin-field--inline">
                            <span>IP</span>
                            <input type="text" name="sec_ip" value="<?= portal_escape($secIp) ?>" placeholder="Exact IP" autocomplete="off" spellcheck="false">
                        </label>
                        <?php if ($secIdsRaw !== ''): ?>
                        <input type="hidden" name="sec_ids" value="<?= portal_escape($secIdsRaw) ?>">
                        <?php endif; ?>
                        <button type="submit" class="admin-btn admin-btn--secondary">Apply filters</button>
                        <?php if ($secIp !== '' || $secIdsRaw !== ''): ?>
                        <a class="admin-btn admin-btn--secondary" href="<?= portal_escape($adminUrl('security', ['sec_period' => $secPeriod])) ?>">Clear IP / incident filter</a>
                        <?php endif; ?>
                    </form>

                    <form method="post" action="<?= portal_escape($adminUrl('security', $securityFilterParams)) ?>" id="security-bulk-form" class="admin-bulk-form">
                        <?= portal_csrf_field() ?>
                        <input type="hidden" name="action" value="bulk_security_action">
                        <input type="hidden" name="sec_period" value="<?= portal_escape($secPeriod) ?>">
                        <input type="hidden" name="sec_reviewed" value="<?= portal_escape($secReviewed) ?>">
                        <input type="hidden" name="sec_severity" value="<?= portal_escape($secSeverity) ?>">
                        <input type="hidden" name="sec_type" value="<?= portal_escape($secType) ?>">
                        <input type="hidden" name="sec_ip" value="<?= portal_escape($secIp) ?>">
                        <input type="hidden" name="sec_ids" value="<?= portal_escape($secIdsRaw) ?>">
                        <input type="hidden" name="select_all_matching" id="security-select-all-matching" value="0">

                        <div class="admin-filter-row admin-bulk-bar">
                            <label class="admin-field admin-field--inline">
                                <span>Bulk action</span>
                                <select name="bulk_action">
                                    <option value="mark_reviewed">Mark reviewed</option>
                                </select>
                            </label>
                            <button type="submit" class="admin-btn admin-btn--primary">Apply</button>
                            <label class="admin-check-inline" id="security-select-matching-wrap" hidden>
                                <input type="checkbox" id="security-select-matching-toggle">
                                <span>Select all matching this filter (up to 500)</span>
                            </label>
                        </div>
                    </form>

                    <div class="admin-table-wrap">
                        <table class="admin-table" id="security-events-table">
                            <thead>
                                <tr>
                                    <th>
                                        <input type="checkbox" id="security-select-page" title="Select all on this page" aria-label="Select all on this page">
                                    </th>
                                    <th>Date / time</th>
                                    <th>Severity</th>
                                    <th>Event</th>
                                    <th>User</th>
                                    <th>IP</th>
                                    <th>Route</th>
                                    <th>Details</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($securityEvents as $event): ?>
                                <?php
                                    $evSeverity = (string) $event['severity'];
                                    $isReviewed = (int) ($event['reviewed'] ?? 0) === 1;
                                    $evUser = (string) ($event['username'] ?? '');
                                    $evUserId = (int) ($event['user_id'] ?? 0);
                                    if ($evUser === '' && $evUserId > 0) {
                                        $evUser = 'User #' . $evUserId;
                                    }
                                    if ($evUser === '') {
                                        $evUser = '—';
                                    }
                                    $evIp = trim((string) ($event['ip_address'] ?? ''));
                                    $evType = (string) ($event['event_type'] ?? '');
                                    // Admin-audit events store the staff actor in user_id — not the account to discipline.
                                    $adminActorEventTypes = [
                                        'role_changed', 'user_deleted', 'user_updated',
                                        'course_archived', 'course_restored', 'account_status_changed',
                                    ];
                                    $targetUser = null;
                                    if ($evUserId > 0) {
                                        $targetUser = portal_find_user_by_id($evUserId);
                                    } elseif ($evUser !== '' && $evUser !== '—') {
                                        $targetUser = portal_find_user($evUser);
                                        if ($targetUser) {
                                            $evUserId = (int) $targetUser['id'];
                                        }
                                    }
                                    $canActOnTarget = $targetUser !== null
                                        && $evUserId > 0
                                        && $evUserId !== (int) $currentUser['id']
                                        && (string) ($targetUser['role'] ?? '') !== 'owner'
                                        && !in_array($evType, $adminActorEventTypes, true)
                                        && (!in_array((string) ($targetUser['role'] ?? ''), ['admin'], true) || $isOwner);
                                    $showAccountActions = $canActOnTarget;
                                    $targetStatus = portal_user_account_status($targetUser);
                                    if ($targetUser && $evUser === '—') {
                                        $evUser = (string) ($targetUser['username'] ?: $targetUser['name'] ?: ('User #' . $evUserId));
                                    }
                                ?>
                                <tr>
                                    <td data-label="Select">
                                        <input type="checkbox" class="security-event-check" form="security-bulk-form" name="event_ids[]" value="<?= (int) $event['id'] ?>" <?= $isReviewed ? 'disabled' : '' ?>>
                                    </td>
                                    <td data-label="Date / time"><?= portal_escape(date('j M Y H:i', strtotime((string) $event['created_at']))) ?></td>
                                    <td data-label="Severity"><span class="admin-severity admin-severity--<?= portal_escape($evSeverity) ?>"><?= portal_escape(ucfirst($evSeverity)) ?></span></td>
                                    <td data-label="Event"><?= portal_escape(portal_security_event_type_label($evType)) ?></td>
                                    <td data-label="User">
                                        <?php if ($targetUser && $showAccountActions): ?>
                                        <button type="button" class="admin-user-link" data-security-profile="<?= (int) $evUserId ?>" data-security-event="<?= (int) $event['id'] ?>">
                                            <?= portal_escape($evUser) ?>
                                        </button>
                                        <?php elseif ($targetUser): ?>
                                        <button type="button" class="admin-user-link" data-security-profile="<?= (int) $evUserId ?>">
                                            <?= portal_escape($evUser) ?>
                                        </button>
                                        <?php else: ?>
                                        <?= portal_escape($evUser) ?>
                                        <?php endif; ?>
                                        <?php if ($targetUser && $targetStatus !== 'active'): ?>
                                            <span class="admin-badge admin-badge--draft"><?= portal_escape(portal_account_status_label($targetStatus)) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="IP">
                                        <?php if ($evIp !== '' && $evIp !== 'unknown'): ?>
                                        <a class="security-ip-link" href="<?= portal_escape($adminUrl('security', [
                                            'sec_period' => $secPeriod,
                                            'sec_ip' => $evIp,
                                        ])) ?>"><?= portal_escape($evIp) ?></a>
                                        <?php else: ?>
                                        <span class="admin-table-meta">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Route"><code class="admin-route-code"><?= portal_escape((string) $event['route']) ?></code></td>
                                    <td data-label="Details"><?= portal_escape((string) $event['details']) ?></td>
                                    <td data-label="Status">
                                        <?php if ($isReviewed): ?>
                                        <span class="admin-badge admin-badge--open">Reviewed</span>
                                        <?php else: ?>
                                        <span class="admin-badge admin-badge--draft">Open</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Action">
                                        <div class="admin-inline-actions">
                                            <?php if (!$isReviewed): ?>
                                            <form method="post" action="<?= portal_escape($adminUrl('security', $securityFilterParams)) ?>" class="admin-inline-form">
                                                <?= portal_csrf_field() ?>
                                                <input type="hidden" name="action" value="mark_security_event_reviewed">
                                                <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                                                <input type="hidden" name="sec_period" value="<?= portal_escape($secPeriod) ?>">
                                                <input type="hidden" name="sec_reviewed" value="<?= portal_escape($secReviewed) ?>">
                                                <input type="hidden" name="sec_severity" value="<?= portal_escape($secSeverity) ?>">
                                                <input type="hidden" name="sec_type" value="<?= portal_escape($secType) ?>">
                                                <input type="hidden" name="sec_ip" value="<?= portal_escape($secIp) ?>">
                                                <input type="hidden" name="sec_ids" value="<?= portal_escape($secIdsRaw) ?>">
                                                <button type="submit" class="admin-btn admin-btn--secondary admin-btn--sm">Mark reviewed</button>
                                            </form>
                                            <?php endif; ?>
                                            <?php if ($showAccountActions && $targetUser): ?>
                                            <button type="button" class="admin-btn admin-btn--primary admin-btn--sm" data-security-profile="<?= (int) $evUserId ?>" data-security-event="<?= (int) $event['id'] ?>">
                                                Take action
                                            </button>
                                            <?php elseif ($targetUser): ?>
                                            <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm" data-security-profile="<?= (int) $evUserId ?>">
                                                View profile
                                            </button>
                                            <?php elseif ($isReviewed): ?>
                                            <span class="admin-table-meta">—</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if ($securityEvents === []): ?>
                                <tr><td colspan="10" class="admin-table-empty">No security events found for these filters.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>

                <script type="application/json" id="security-user-profiles-data"><?= json_encode($securityUserProfiles, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}' ?></script>
                <template id="security-profile-action-template">
                    <form method="post" action="<?= portal_escape($adminUrl('security', $securityFilterParams)) ?>" class="admin-inline-form security-profile-action-form">
                        <?= portal_csrf_field() ?>
                        <input type="hidden" name="action" value="security_account_action">
                        <input type="hidden" name="account_action" value="">
                        <input type="hidden" name="target_user_id" value="">
                        <input type="hidden" name="reason" value="">
                        <input type="hidden" name="sec_period" value="<?= portal_escape($secPeriod) ?>">
                        <input type="hidden" name="sec_reviewed" value="<?= portal_escape($secReviewed) ?>">
                        <input type="hidden" name="sec_severity" value="<?= portal_escape($secSeverity) ?>">
                        <input type="hidden" name="sec_type" value="<?= portal_escape($secType) ?>">
                        <input type="hidden" name="sec_ip" value="<?= portal_escape($secIp) ?>">
                        <input type="hidden" name="sec_ids" value="<?= portal_escape($secIdsRaw) ?>">
                        <button type="submit" class="admin-btn admin-btn--secondary"></button>
                    </form>
                </template>

                <article class="admin-card">
                    <header class="admin-card-header">
                        <div>
                            <p class="eyebrow">Deployment</p>
                            <h3>Trusted proxies</h3>
                            <p class="admin-card-lead">Only needed behind a reverse proxy/load balancer. REMOTE_ADDR must match a listed proxy before X-Forwarded-For is trusted.</p>
                        </div>
                    </header>
                    <form method="post" action="<?= portal_escape($adminUrl('security')) ?>" class="admin-filter-row">
                        <?= portal_csrf_field() ?>
                        <input type="hidden" name="action" value="save_trusted_proxies">
                        <label class="admin-field admin-field--grow">
                            <span>Trusted proxy IPs / CIDRs</span>
                            <input type="text" name="trusted_proxies" value="<?= portal_escape($trustedProxies) ?>" placeholder="10.0.0.1, 192.168.0.0/24">
                        </label>
                        <button type="submit" class="admin-btn admin-btn--secondary">Save</button>
                    </form>
                </article>

                <?php if ($showDeveloperSecurity): ?>
                <article class="admin-card admin-card--diagnostics">
                    <header class="admin-card-header">
                        <div>
                            <p class="eyebrow">Developer only</p>
                            <h3>Developer diagnostics</h3>
                            <p class="admin-card-lead">Technical configuration checks. Not shown to regular admins.</p>
                        </div>
                    </header>
                    <div class="admin-status-row">
                        <article class="admin-card admin-card--compact">
                            <p class="admin-stat-label">Database storage</p>
                            <span class="admin-badge <?= portal_db_is_in_webroot() ? 'admin-badge--archived' : 'admin-badge--open' ?>">
                                <?= portal_db_is_in_webroot() ? 'Needs relocation' : 'Outside web root' ?>
                            </span>
                            <p class="admin-field-hint"><?= getenv('PORTAL_DB_PATH') !== false && trim((string) getenv('PORTAL_DB_PATH')) !== '' ? 'Using PORTAL_DB_PATH' : 'Using default database location' ?></p>
                        </article>
                        <article class="admin-card admin-card--compact">
                            <p class="admin-stat-label">Upload protection</p>
                            <span class="admin-badge admin-badge--open">Active</span>
                            <p class="admin-field-hint">Upload and database folders are blocked from direct browser access.</p>
                        </article>
                        <article class="admin-card admin-card--compact">
                            <p class="admin-stat-label">Rich text sanitizer</p>
                            <span class="admin-badge admin-badge--open">Enabled</span>
                        </article>
                        <article class="admin-card admin-card--compact">
                            <p class="admin-stat-label">CSRF protection</p>
                            <span class="admin-badge admin-badge--open">Enabled</span>
                        </article>
                    </div>
                    <?php if ($dbSecurityWarning): ?>
                    <div class="admin-flash error admin-flash--compact">
                        <?= portal_icon('lock', 'admin-flash-icon') ?>
                        <span><?= portal_escape($dbSecurityWarning) ?></span>
                    </div>
                    <?php endif; ?>
                    <ul class="admin-checklist">
                        <li>Move the SQLite database outside the public web folder using <code>PORTAL_DB_PATH</code> in production.</li>
                        <li>Confirm <code>/database/portal.db</code> returns 403 or denied in the browser.</li>
                        <li>Never commit <code>INITIAL_OWNER_PASSWORD.txt</code> or API keys to version control.</li>
                        <li>Run automated security tests: <code>npm run test:security</code></li>
                        <li>Run rich-text XSS checks: <code>php tests/security_rich_text_check.php</code></li>
                    </ul>
                </article>
                <?php elseif (portal_is_owner()): ?>
                <article class="admin-card admin-card--muted">
                    <p class="admin-card-lead">Developer diagnostics are hidden. Set <code>PORTAL_SHOW_DEVELOPER_SECURITY=1</code> in your environment to view technical configuration checks.</p>
                </article>
                <?php else: ?>
                <article class="admin-card admin-card--muted">
                    <p class="admin-card-lead">Developer diagnostics hidden. Contact the system developer for configuration checks.</p>
                </article>
                <?php endif; ?>
            </section>

        </div>
    </div>

    <div class="admin-profile-overlay" id="security-profile-overlay" hidden>
        <div class="admin-profile-backdrop" data-security-profile-close></div>
        <aside class="admin-profile-panel" role="dialog" aria-modal="true" aria-labelledby="security-profile-title" tabindex="-1">
            <header class="admin-profile-panel-header">
                <div>
                    <p class="eyebrow">Account review</p>
                    <h3 id="security-profile-title">User profile</h3>
                </div>
                <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm" data-security-profile-close>Close</button>
            </header>
            <div class="admin-profile-panel-body" id="security-profile-body"></div>
            <div class="admin-profile-panel-actions" id="security-profile-actions" hidden>
                <p class="admin-profile-actions-label">Take action</p>
                <div class="admin-profile-action-grid" id="security-profile-action-grid"></div>
            </div>
        </aside>
    </div>
</div>

<?php
$canEditOpenedUser = $editUser !== null
    && (int) $editUser['id'] !== (int) $currentUser['id']
    && $editUser['role'] !== 'owner'
    && ($isOwner || !in_array($editUser['role'], ['admin', 'owner'], true));
?>
<?php if ($canEditOpenedUser): ?>
<dialog class="admin-panel" id="edit-user-panel" open>
    <form method="post" action="<?= portal_escape($adminUrl('users')) ?>" class="admin-panel-form">
        <?= portal_csrf_field() ?>
        <input type="hidden" name="action" value="update_user">
        <input type="hidden" name="user_id" value="<?= (int) $editUser['id'] ?>">
        <header class="admin-panel-header">
            <h3>Edit <?= portal_escape((string) $editUser['name']) ?></h3>
            <a class="admin-panel-close" href="<?= portal_escape($adminUrl('users')) ?>" aria-label="Close">&times;</a>
        </header>
        <div class="admin-form-grid">
            <label class="admin-field">
                <span>Full name</span>
                <input type="text" name="name" required maxlength="150" value="<?= portal_escape((string) $editUser['name']) ?>">
            </label>
            <label class="admin-field">
                <span>Username</span>
                <input type="text" name="username" required maxlength="80" value="<?= portal_escape((string) $editUser['username']) ?>">
            </label>
            <label class="admin-field">
                <span>Email</span>
                <input type="email" name="email" required maxlength="190" value="<?= portal_escape((string) $editUser['email']) ?>">
            </label>
            <label class="admin-field">
                <span>Year group</span>
                <select name="year">
                    <?php foreach ($yearGroupOptions as $yr): ?>
                    <option value="<?= portal_escape($yr) ?>"<?= (string) $editUser['year'] === $yr ? ' selected' : '' ?>><?= portal_escape($yr) ?></option>
                    <?php endforeach; ?>
                    <?php if (!in_array((string) $editUser['year'], $yearGroupOptions, true)): ?>
                    <option value="<?= portal_escape((string) $editUser['year']) ?>" selected><?= portal_escape((string) $editUser['year']) ?></option>
                    <?php endif; ?>
                </select>
            </label>
            <label class="admin-field">
                <span>Role</span>
                <select name="role">
                    <option value="student"<?= $editUser['role'] === 'student' ? ' selected' : '' ?>>Student</option>
                    <option value="teacher"<?= $editUser['role'] === 'teacher' ? ' selected' : '' ?>>Teacher</option>
                    <?php if ($isOwner): ?>
                    <option value="admin"<?= $editUser['role'] === 'admin' ? ' selected' : '' ?>>Admin</option>
                    <?php endif; ?>
                </select>
            </label>
            <label class="admin-field admin-field--full">
                <span>New password <em class="admin-field-hint-inline">(optional — leave blank to keep current)</em></span>
                <span class="admin-password-wrap">
                    <input type="password" name="new_password" minlength="8" autocomplete="new-password" placeholder="Min. 8 characters, letter + number">
                    <button type="button" class="admin-toggle-pass" data-toggle-password aria-label="Show password" aria-pressed="false">
                        <span class="admin-toggle-pass__icon admin-toggle-pass__icon--show" aria-hidden="true"><?= portal_icon('eye', 'icon-sm') ?></span>
                        <span class="admin-toggle-pass__icon admin-toggle-pass__icon--hide" aria-hidden="true" hidden><?= portal_icon('eye-off', 'icon-sm') ?></span>
                    </button>
                </span>
            </label>
            <label class="admin-field admin-field--full">
                <span>Confirm new password</span>
                <span class="admin-password-wrap">
                    <input type="password" name="confirm_password" minlength="8" autocomplete="new-password" placeholder="Repeat new password">
                    <button type="button" class="admin-toggle-pass" data-toggle-password aria-label="Show password" aria-pressed="false">
                        <span class="admin-toggle-pass__icon admin-toggle-pass__icon--show" aria-hidden="true"><?= portal_icon('eye', 'icon-sm') ?></span>
                        <span class="admin-toggle-pass__icon admin-toggle-pass__icon--hide" aria-hidden="true" hidden><?= portal_icon('eye-off', 'icon-sm') ?></span>
                    </button>
                </span>
            </label>
        </div>
        <p class="admin-field-hint admin-field-hint--panel">
            <a href="<?= portal_escape($adminUrl('enrollments', ['enroll' => (int) $editUser['id']])) ?>">Manage enrolments for this user</a>
        </p>
        <footer class="admin-panel-footer">
            <a class="admin-btn admin-btn--secondary" href="<?= portal_escape($adminUrl('users')) ?>">Cancel</a>
            <button type="submit" class="admin-btn admin-btn--primary">Save changes</button>
        </footer>
    </form>
</dialog>
<?php endif; ?>

<?php
$adminRenderScheduleRow = static function (?array $slot = null) use ($courseWeekdays): void {
    $slotId = (int) ($slot['id'] ?? 0);
    $day = (string) ($slot['day_of_week'] ?? 'Monday');
    $start = (string) ($slot['start_time'] ?? '');
    $end = (string) ($slot['end_time'] ?? '');
    $room = (string) ($slot['room'] ?? '');
    $notes = (string) ($slot['notes'] ?? '');
    ?>
    <div class="admin-schedule-row">
        <input type="hidden" name="schedule_id[]" value="<?= $slotId > 0 ? $slotId : '' ?>">
        <label class="admin-field">
            <span>Day</span>
            <select name="schedule_day[]">
                <?php foreach ($courseWeekdays as $weekday): ?>
                    <option value="<?= portal_escape($weekday) ?>"<?= $day === $weekday ? ' selected' : '' ?>><?= portal_escape($weekday) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="admin-field">
            <span>Start</span>
            <input type="time" name="schedule_start[]" value="<?= portal_escape($start) ?>">
        </label>
        <label class="admin-field">
            <span>End</span>
            <input type="time" name="schedule_end[]" value="<?= portal_escape($end) ?>">
        </label>
        <label class="admin-field">
            <span>Room / link</span>
            <input type="text" name="schedule_room[]" maxlength="500" value="<?= portal_escape($room) ?>" placeholder="Room 12 or https://zoom.us/j/...">
        </label>
        <label class="admin-field">
            <span>Notes</span>
            <input type="text" name="schedule_notes[]" maxlength="300" value="<?= portal_escape($notes) ?>">
        </label>
        <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm" data-schedule-remove>Remove</button>
    </div>
    <?php
};
?>

<!-- Create course panel -->
<dialog class="admin-panel" id="create-course-panel">
    <form method="post" action="<?= portal_escape($adminUrl('courses')) ?>" class="admin-panel-form">
        <?= portal_csrf_field() ?>
        <input type="hidden" name="action" value="create_course">
        <header class="admin-panel-header">
            <h3>Add course / module</h3>
            <button type="button" class="admin-panel-close" data-admin-close aria-label="Close">&times;</button>
        </header>
        <div class="admin-form-grid" data-course-identity>
            <label class="admin-field"><span>Course title</span><input type="text" name="title" required data-course-title></label>
            <label class="admin-field"><span>Academic year</span>
                <select name="year_group" required data-course-year>
                    <?php foreach ($academicYearOptions as $yr): ?>
                    <option value="<?= portal_escape($yr) ?>"<?= $yr === $currentAcademicYear ? ' selected' : '' ?>><?= portal_escape($yr) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="admin-field"><span>Term</span><input type="text" name="term" value="Full year"></label>
            <label class="admin-field">
                <span>Full title</span>
                <input type="text" data-course-full-title-preview readonly tabindex="-1" placeholder="Generated from title and year">
            </label>
            <label class="admin-field">
                <span>Course code</span>
                <input type="text" data-course-code-preview readonly tabindex="-1" placeholder="Generated from title and year">
            </label>
            <label class="admin-field">
                <span>URL slug</span>
                <input type="text" data-course-slug-preview readonly tabindex="-1" placeholder="Generated from title and year">
            </label>
            <p class="admin-field-hint admin-field-hint--panel admin-field--full">The course code is built from the subject, academic year, and the next available number (for example BIO-2526-01). Teachers cannot type their own code or year.</p>
            <label class="admin-field admin-field--full"><span>Summary</span><textarea name="summary" rows="3"></textarea></label>
            <label class="admin-field"><span>Status</span>
                <select name="status" data-course-status>
                    <option value="draft" selected>Draft — staff only</option>
                    <option value="closed">Closed — locked for students</option>
                    <option value="open">Open — students can enter</option>
                    <option value="archived">Archived</option>
                </select>
            </label>
            <div class="admin-opens-at admin-field--full" data-opens-at hidden>
                <label class="admin-field">
                    <span>Open from</span>
                    <input type="datetime-local" name="opens_at">
                </label>
                <p class="admin-field-hint admin-field-hint--panel">Students cannot enter until this date and time. Leave empty to keep the course locked until you switch it to Open.</p>
            </div>
            <div class="admin-opens-at admin-field--full" data-archives-at hidden>
                <label class="admin-field">
                    <span>Archive after</span>
                    <input type="datetime-local" name="archives_at">
                </label>
                <p class="admin-field-hint admin-field-hint--panel">The course stays open until this date and time, then moves to Archived. Leave empty to keep it open until you archive it yourself.</p>
            </div>
            <label class="admin-field"><span>Accent colour</span>
                <div class="admin-accent-picker" data-accent-picker>
                    <input type="color" value="#c1202f" aria-label="Pick accent colour">
                    <input type="text" name="accent" value="#c1202f" pattern="#[0-9a-fA-F]{6}" maxlength="7" spellcheck="false">
                </div>
            </label>
            <div class="admin-schedule-editor admin-field--full" data-schedule-editor>
                <p class="admin-schedule-label">Weekly timetable</p>
                <p class="admin-field-hint admin-field-hint--panel">These slots are the same as the course calendar. Add one row per class meeting.</p>
                <div data-schedule-rows>
                    <?php $adminRenderScheduleRow(null); ?>
                </div>
                <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm" data-schedule-add>Add another slot</button>
            </div>
            <label class="admin-field admin-field--full"><span>Notice</span><textarea name="notice" rows="2"></textarea></label>
        </div>
        <footer class="admin-panel-footer">
            <button type="button" class="admin-btn admin-btn--secondary" data-admin-close>Cancel</button>
            <button type="submit" class="admin-btn admin-btn--primary">Create course</button>
        </footer>
    </form>
</dialog>

<?php if ($editCourse): ?>
<dialog class="admin-panel" id="edit-course-panel" open>
    <form method="post" action="<?= portal_escape($adminUrl('courses')) ?>" class="admin-panel-form">
        <?= portal_csrf_field() ?>
        <input type="hidden" name="action" value="update_course">
        <input type="hidden" name="course_id" value="<?= (int) $editCourse['id'] ?>">
        <header class="admin-panel-header">
            <h3>Edit course</h3>
            <a class="admin-panel-close" href="<?= portal_escape($adminUrl('courses')) ?>" aria-label="Close">&times;</a>
        </header>
        <?php
        $editYear = portal_valid_academic_year((string) ($editCourse['year_group'] ?? ''))
            ? (string) $editCourse['year_group']
            : $currentAcademicYear;
        $editYearOptions = $academicYearOptions;
        if (!in_array($editYear, $editYearOptions, true)) {
            array_unshift($editYearOptions, $editYear);
        }
        ?>
        <p class="admin-field-hint admin-field-hint--panel">Slug: <code><?= portal_escape((string) $editCourse['slug']) ?></code> (not editable — preserves links and uploads)</p>
        <div class="admin-form-grid" data-course-identity>
            <label class="admin-field"><span>Course title</span><input type="text" name="title" required data-course-title value="<?= portal_escape((string) $editCourse['title']) ?>"></label>
            <label class="admin-field"><span>Academic year</span>
                <select name="year_group" required data-course-year>
                    <?php foreach ($editYearOptions as $yr): ?>
                    <option value="<?= portal_escape($yr) ?>"<?= $yr === $editYear ? ' selected' : '' ?>><?= portal_escape($yr) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="admin-field"><span>Term</span><input type="text" name="term" value="<?= portal_escape((string) $editCourse['term']) ?>"></label>
            <label class="admin-field">
                <span>Full title</span>
                <input type="text" data-course-full-title-preview readonly tabindex="-1" value="<?= portal_escape(portal_course_full_title((string) $editCourse['title'], $editYear)) ?>">
            </label>
            <label class="admin-field">
                <span>Course code</span>
                <input type="text" data-course-code-preview readonly tabindex="-1" value="<?= portal_escape((string) $editCourse['code']) ?>">
            </label>
            <p class="admin-field-hint admin-field-hint--panel admin-field--full">Full title and code update automatically if the title or academic year changes.</p>
            <label class="admin-field admin-field--full"><span>Summary</span><textarea name="summary" rows="3"><?= portal_escape((string) $editCourse['summary']) ?></textarea></label>
            <label class="admin-field"><span>Status</span>
                <select name="status" data-course-status>
                    <option value="draft"<?= $editCourse['status'] === 'draft' ? ' selected' : '' ?>>Draft — staff only</option>
                    <option value="closed"<?= $editCourse['status'] === 'closed' ? ' selected' : '' ?>>Closed — locked for students</option>
                    <option value="open"<?= $editCourse['status'] === 'open' ? ' selected' : '' ?>>Open — students can enter</option>
                    <option value="archived"<?= $editCourse['status'] === 'archived' ? ' selected' : '' ?>>Archived</option>
                </select>
            </label>
            <div class="admin-opens-at admin-field--full" data-opens-at<?= $editCourse['status'] === 'closed' ? '' : ' hidden' ?>>
                <label class="admin-field">
                    <span>Open from</span>
                    <input type="datetime-local" name="opens_at" value="<?= portal_escape(portal_course_opens_at_input_value((string) ($editCourse['opens_at'] ?? ''))) ?>">
                </label>
                <p class="admin-field-hint admin-field-hint--panel">Students cannot enter until this date and time. Leave empty to keep the course locked until you switch it to Open.</p>
            </div>
            <div class="admin-opens-at admin-field--full" data-archives-at<?= $editCourse['status'] === 'open' ? '' : ' hidden' ?>>
                <label class="admin-field">
                    <span>Archive after</span>
                    <input type="datetime-local" name="archives_at" value="<?= portal_escape(portal_course_opens_at_input_value((string) ($editCourse['archives_at'] ?? ''))) ?>">
                </label>
                <p class="admin-field-hint admin-field-hint--panel">The course stays open until this date and time, then moves to Archived. Leave empty to keep it open until you archive it yourself.</p>
            </div>
            <label class="admin-field"><span>Accent colour</span>
                <div class="admin-accent-picker" data-accent-picker>
                    <input type="color" value="<?= portal_escape(portal_valid_course_accent((string) $editCourse['accent']) ? (string) $editCourse['accent'] : '#c1202f') ?>" aria-label="Pick accent colour">
                    <input type="text" name="accent" value="<?= portal_escape(portal_valid_course_accent((string) $editCourse['accent']) ? (string) $editCourse['accent'] : '#c1202f') ?>" pattern="#[0-9a-fA-F]{6}" maxlength="7" spellcheck="false">
                </div>
            </label>
            <div class="admin-schedule-editor admin-field--full" data-schedule-editor>
                <p class="admin-schedule-label">Weekly timetable</p>
                <p class="admin-field-hint admin-field-hint--panel">These slots are the same as the course calendar. Add one row per class meeting.</p>
                <div data-schedule-rows>
                    <?php
                    if ($editCourseSchedule === []) {
                        $adminRenderScheduleRow(null);
                    } else {
                        foreach ($editCourseSchedule as $slot) {
                            $adminRenderScheduleRow($slot);
                        }
                    }
                    ?>
                </div>
                <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm" data-schedule-add>Add another slot</button>
            </div>
            <label class="admin-field admin-field--full"><span>Notice</span><textarea name="notice" rows="2"><?= portal_escape((string) $editCourse['notice']) ?></textarea></label>
        </div>
        <footer class="admin-panel-footer">
            <a href="<?= portal_escape($adminUrl('courses')) ?>" class="admin-btn admin-btn--secondary">Cancel</a>
            <button type="submit" class="admin-btn admin-btn--primary">Save changes</button>
        </footer>
    </form>
</dialog>
<?php endif; ?>

<?php if ($duplicateCourse): ?>
<dialog class="admin-panel" id="duplicate-course-panel" open>
    <form method="post" action="<?= portal_escape($adminUrl('courses')) ?>" class="admin-panel-form">
        <?= portal_csrf_field() ?>
        <input type="hidden" name="action" value="duplicate_course">
        <input type="hidden" name="source_course_id" value="<?= (int) $duplicateCourse['id'] ?>">
        <header class="admin-panel-header">
            <h3>Duplicate course</h3>
            <a class="admin-panel-close" href="<?= portal_escape($adminUrl('courses')) ?>" aria-label="Close">&times;</a>
        </header>
        <?php
        $dupYear = portal_academic_year_allowed((string) ($duplicateCourse['year_group'] ?? ''))
            ? (string) $duplicateCourse['year_group']
            : $currentAcademicYear;
        ?>
        <p class="admin-field-hint admin-field-hint--panel">Copying metadata from <strong><?= portal_escape((string) $duplicateCourse['title']) ?></strong>. Enrolments, submissions, and materials are not copied. The new code and slug are generated from the title and academic year.</p>
        <div class="admin-form-grid" data-course-identity data-course-year-fixed="<?= portal_escape($dupYear) ?>">
            <label class="admin-field"><span>New course title</span><input type="text" name="title" required data-course-title value="<?= portal_escape((string) $duplicateCourse['title'] . ' (copy)') ?>"></label>
            <label class="admin-field"><span>Academic year</span>
                <input type="text" value="<?= portal_escape($dupYear) ?>" readonly tabindex="-1">
            </label>
            <label class="admin-field">
                <span>Full title</span>
                <input type="text" data-course-full-title-preview readonly tabindex="-1" placeholder="Generated from title and year">
            </label>
            <label class="admin-field">
                <span>Course code</span>
                <input type="text" data-course-code-preview readonly tabindex="-1" placeholder="Generated from title and year">
            </label>
            <label class="admin-field">
                <span>URL slug</span>
                <input type="text" data-course-slug-preview readonly tabindex="-1" placeholder="Generated from title and year">
            </label>
        </div>
        <footer class="admin-panel-footer">
            <a href="<?= portal_escape($adminUrl('courses')) ?>" class="admin-btn admin-btn--secondary">Cancel</a>
            <button type="submit" class="admin-btn admin-btn--primary">Create duplicate</button>
        </footer>
    </form>
</dialog>
<?php endif; ?>

<script>
(function () {
    document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var wrap = btn.closest('.admin-password-wrap');
            var input = wrap ? wrap.querySelector('input') : null;
            if (!input) return;
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            var iconShow = btn.querySelector('.admin-toggle-pass__icon--show');
            var iconHide = btn.querySelector('.admin-toggle-pass__icon--hide');
            if (iconShow) iconShow.hidden = show;
            if (iconHide) iconHide.hidden = !show;
        });
    });

    document.querySelectorAll('[data-admin-open]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-admin-open');
            var dlg = document.getElementById(id);
            if (!dlg || typeof dlg.showModal !== 'function') return;
            try {
                if (!dlg.open) dlg.showModal();
            } catch (err) {
                // Ignore InvalidStateError if already open.
            }
        });
    });

    document.querySelectorAll('[data-accent-picker]').forEach(function (wrap) {
        var picker = wrap.querySelector('input[type="color"]');
        var text = wrap.querySelector('input[name="accent"]');
        if (!picker || !text) return;

        function normaliseHex(value) {
            var raw = String(value || '').trim();
            if (/^#[0-9a-fA-F]{6}$/.test(raw)) return '#' + raw.slice(1).toLowerCase();
            if (/^#[0-9a-fA-F]{3}$/.test(raw)) {
                return '#' + raw.charAt(1) + raw.charAt(1) + raw.charAt(2) + raw.charAt(2) + raw.charAt(3) + raw.charAt(3);
            }
            return '';
        }

        picker.addEventListener('input', function () {
            text.value = picker.value;
        });
        text.addEventListener('input', function () {
            var hex = normaliseHex(text.value);
            if (hex) picker.value = hex;
        });
        text.addEventListener('blur', function () {
            var hex = normaliseHex(text.value);
            if (hex) {
                text.value = hex;
                picker.value = hex;
            }
        });
    });

    document.querySelectorAll('[data-admin-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var dlg = btn.closest('dialog');
            if (dlg) dlg.close();
        });
    });

    function syncOpensAt(form) {
        if (!form) return;
        var status = form.querySelector('[data-course-status]');
        if (!status) return;
        var opensWrap = form.querySelector('[data-opens-at]');
        var archivesWrap = form.querySelector('[data-archives-at]');
        if (opensWrap) opensWrap.hidden = status.value !== 'closed';
        if (archivesWrap) archivesWrap.hidden = status.value !== 'open';
    }

    document.querySelectorAll('.admin-panel-form [data-course-status]').forEach(function (select) {
        var form = select.closest('form');
        syncOpensAt(form);
        select.addEventListener('change', function () { syncOpensAt(form); });
    });

    var prefixMap = <?= json_encode(portal_course_code_prefix_map(), JSON_UNESCAPED_UNICODE) ?>;
    var stopWords = { a: 1, an: 1, the: 1, of: 1, and: 1, as: 1, for: 1, to: 1, in: 1, with: 1 };

    function courseTitlePrefix(title) {
        var n = String(title || '').toLowerCase().replace(/\s+/g, ' ').trim();
        if (!n) return '';
        var keys = Object.keys(prefixMap).sort(function (a, b) { return b.length - a.length; });
        for (var i = 0; i < keys.length; i++) {
            var key = keys[i];
            if (n === key || n.indexOf(key + ' ') === 0) return prefixMap[key];
        }
        var words = n.split(' ').map(function (word) {
            return word.replace(/[^a-z0-9]/g, '');
        }).filter(function (word) {
            return word && !stopWords[word];
        });
        if (!words.length) return 'CRS';
        if (words.length >= 2) {
            return words.slice(0, 4).map(function (word) { return word.charAt(0).toUpperCase(); }).join('');
        }
        var single = words[0];
        return single.length <= 4 ? single.toUpperCase() : single.slice(0, 3).toUpperCase();
    }

    function compactYear(year) {
        return String(year || '').replace('/', '');
    }

    function slugifyTitle(title) {
        var slug = String(title || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        return slug || 'course';
    }

    function syncCourseIdentity(root) {
        var title = root.querySelector('[data-course-title]');
        var yearField = root.querySelector('[data-course-year]');
        var year = root.getAttribute('data-course-year-fixed') || (yearField ? yearField.value : '');
        var prefix = courseTitlePrefix(title ? title.value : '');
        var compact = compactYear(year);
        var codeEl = root.querySelector('[data-course-code-preview]');
        var slugEl = root.querySelector('[data-course-slug-preview]');
        var fullTitleEl = root.querySelector('[data-course-full-title-preview]');
        var originalTitle = root.getAttribute('data-original-title');
        var originalYear = root.getAttribute('data-original-year');
        var originalCode = root.getAttribute('data-original-code');
        var unchanged = originalCode && title && title.value === originalTitle && year === originalYear;
        if (codeEl) {
            if (unchanged) codeEl.value = originalCode;
            else if (prefix && compact) codeEl.value = prefix + '-' + compact + '-…';
            else codeEl.value = '';
        }
        if (slugEl && compact) slugEl.value = slugifyTitle(title ? title.value : '') + '-' + compact;
        if (fullTitleEl) {
            var courseTitle = title ? String(title.value || '').trim() : '';
            fullTitleEl.value = courseTitle && year ? year + ' — ' + courseTitle : '';
        }
    }

    document.querySelectorAll('[data-course-identity]').forEach(function (root) {
        var title = root.querySelector('[data-course-title]');
        var yearField = root.querySelector('[data-course-year]');
        var codeEl = root.querySelector('[data-course-code-preview]');
        if (title && yearField && codeEl && codeEl.value) {
            root.setAttribute('data-original-title', title.value);
            root.setAttribute('data-original-year', yearField.value);
            root.setAttribute('data-original-code', codeEl.value);
        }
        syncCourseIdentity(root);
        if (title) title.addEventListener('input', function () { syncCourseIdentity(root); });
        if (yearField) yearField.addEventListener('change', function () { syncCourseIdentity(root); });
    });

    document.querySelectorAll('[data-schedule-editor]').forEach(function (editor) {
        var rows = editor.querySelector('[data-schedule-rows]');
        var addBtn = editor.querySelector('[data-schedule-add]');
        if (!rows) return;

        function bindRemove(row) {
            var btn = row.querySelector('[data-schedule-remove]');
            if (!btn) return;
            btn.addEventListener('click', function () {
                if (rows.querySelectorAll('.admin-schedule-row').length <= 1) {
                    row.querySelectorAll('input:not([type="hidden"]), select').forEach(function (field) {
                        field.value = '';
                    });
                    var idField = row.querySelector('input[name="schedule_id[]"]');
                    if (idField) idField.value = '';
                    var dayField = row.querySelector('select[name="schedule_day[]"]');
                    if (dayField && dayField.options.length) dayField.selectedIndex = 0;
                    return;
                }
                row.remove();
            });
        }

        rows.querySelectorAll('.admin-schedule-row').forEach(bindRemove);

        if (addBtn) {
            addBtn.addEventListener('click', function () {
                var source = rows.querySelector('.admin-schedule-row');
                if (!source) return;
                var clone = source.cloneNode(true);
                clone.querySelectorAll('input, select').forEach(function (field) {
                    if (field.name === 'schedule_id[]') {
                        field.value = '';
                        return;
                    }
                    if (field.tagName === 'SELECT') {
                        field.selectedIndex = 0;
                        return;
                    }
                    field.value = '';
                });
                rows.appendChild(clone);
                bindRemove(clone);
            });
        }
    });

    // Backdrop click + ensure no stuck modal top-layer on admin.
    document.querySelectorAll('dialog.admin-panel').forEach(function (dlg) {
        dlg.addEventListener('click', function (event) {
            if (event.target === dlg) dlg.close();
        });
    });

    document.querySelectorAll('dialog.admin-panel[open]').forEach(function (dlg) {
        // Native `open` is non-modal and can leave a confusing overlay feel.
        // Promote to a real modal so Escape/backdrop dismissal always works.
        try {
            dlg.removeAttribute('open');
            dlg.showModal();
        } catch (err) {
            dlg.setAttribute('open', '');
        }
    });

    window.addEventListener('pageshow', function () {
        document.body.classList.remove('admin-profile-open', 'nav-open');
        var profile = document.getElementById('security-profile-overlay');
        if (profile) {
            profile.hidden = true;
            profile.classList.remove('is-open');
        }
    });

    var syncModules = function () {};
    var gptZeroKeyInput = document.getElementById('gptzero-api-key');
    var gptZeroRemoveInput = document.querySelector('input[name="clear_gptzero_api_key"]');
    var gptZeroSavedKey = !!gptZeroKeyInput && gptZeroKeyInput.getAttribute('data-gptzero-saved-key') === '1';

    function restartValidationShake(node) {
        if (!node || !node.classList) return;
        node.classList.remove('is-validation-shaking');
        void node.offsetWidth;
        node.classList.add('is-validation-shaking');
    }

    function hasEffectiveGptZeroKey() {
        var typedKey = gptZeroKeyInput && gptZeroKeyInput.value.trim() !== '';
        var removingSavedKey = gptZeroRemoveInput && gptZeroRemoveInput.checked;
        return typedKey || (gptZeroSavedKey && !removingSavedKey);
    }

    function selectedExternalPolicy() {
        var selected = document.querySelector('input[name="external_ai_policy"]:checked');
        return selected ? selected.value : 'disabled';
    }

    function syncGptZeroWarnings() {
        var hasKey = hasEffectiveGptZeroKey();
        document.querySelectorAll('[data-gptzero-required-warning], [data-gptzero-module-warning]').forEach(function (warning) {
            warning.classList.toggle('is-hidden', hasKey);
        });
    }

    if (gptZeroKeyInput) {
        gptZeroKeyInput.addEventListener('input', function () {
            syncGptZeroWarnings();
            syncModules();
        });
    }

    var integrityForm = document.querySelector('.admin-integrity-form');
    if (integrityForm) {
        integrityForm.addEventListener('submit', function (event) {
            var policy = selectedExternalPolicy();
            if ((policy !== 'site_wide' && policy !== 'per_module') || hasEffectiveGptZeroKey()) return;

            event.preventDefault();
            syncGptZeroWarnings();
            var help = document.getElementById('admin-ai-policy-help');
            if (help) {
                help.textContent = policy === 'site_wide'
                    ? 'Add a GPTZero API key before site-wide checks can run.'
                    : 'A GPTZero API key is required before selected-module checks can run.';
                restartValidationShake(help);
            }
            if (gptZeroKeyInput) {
                restartValidationShake(gptZeroKeyInput);
                restartValidationShake(gptZeroKeyInput.closest('.admin-field'));
                try {
                    gptZeroKeyInput.focus({ preventScroll: false });
                } catch (err) {
                    gptZeroKeyInput.focus();
                }
            }
            var selectedCard = document.querySelector('input[name="external_ai_policy"]:checked');
            restartValidationShake(selectedCard ? selectedCard.closest('.admin-policy-card') : null);
            document.querySelectorAll('[data-gptzero-required-warning], [data-gptzero-module-warning]').forEach(function (warning) {
                restartValidationShake(warning);
            });
        });
    }

    var modules = document.getElementById('admin-ai-modules');
    if (modules) {
        var help = document.getElementById('admin-ai-policy-help');
        var messages = {
            disabled: 'External GPTZero checks are disabled. Internal integrity checks still run.',
            site_wide: 'GPTZero checks will run for every submission.',
            site_wide_missing: 'Add a GPTZero API key before site-wide checks can run.',
            per_module: 'Choose which modules can use GPTZero external AI detection.',
            per_module_missing: 'A GPTZero API key is required before selected-module checks can run.'
        };

        syncModules = function () {
            var sel = document.querySelector('input[name="external_ai_policy"]:checked');
            var showModules = !!sel && sel.value === 'per_module';
            var hasKey = hasEffectiveGptZeroKey();
            if (help && sel) {
                if (sel.value === 'site_wide' && !hasKey) {
                    help.textContent = messages.site_wide_missing;
                } else if (sel.value === 'per_module' && !hasKey) {
                    help.textContent = messages.per_module_missing;
                } else if (messages[sel.value]) {
                    help.textContent = messages[sel.value];
                }
            }
            modules.classList.toggle('admin-collapse--open', showModules);
            modules.classList.toggle('is-visible', showModules);
            modules.classList.toggle('is-hidden', !showModules);
            if (showModules) {
                modules.hidden = false;
            } else {
                window.setTimeout(function () {
                    if (!modules.classList.contains('admin-collapse--open')) {
                        modules.hidden = true;
                    }
                }, 190);
            }
        };
        document.querySelectorAll('input[name="external_ai_policy"]').forEach(function (radio) {
            radio.addEventListener('change', syncModules);
        });
        syncGptZeroWarnings();
        syncModules();
    }

    document.querySelectorAll('.admin-ai-module-card input[type="checkbox"]').forEach(function (box) {
        function syncCard() {
            var card = box.closest('.admin-ai-module-card');
            if (card) card.classList.toggle('is-selected', box.checked);
        }
        box.addEventListener('change', syncCard);
        syncCard();
    });

    document.querySelectorAll('[data-remove-key]').forEach(function (box) {
        function syncRemove() {
            var row = box.closest('.admin-remove-key');
            if (row) row.classList.toggle('is-selected', box.checked);
            syncGptZeroWarnings();
            syncModules();
        }
        box.addEventListener('change', syncRemove);
        syncRemove();
    });

    var enrollFilter = document.getElementById('enroll-course-filter');
    var enrollGrid = document.getElementById('enroll-course-grid');
    if (enrollFilter && enrollGrid) {
        enrollFilter.addEventListener('input', function () {
            var q = enrollFilter.value.trim().toLowerCase();
            enrollGrid.querySelectorAll('.admin-enroll-item').forEach(function (item) {
                var hay = item.getAttribute('data-enroll-search') || '';
                item.hidden = q !== '' && hay.indexOf(q) === -1;
            });
        });
        if (enrollFilter.value.trim() !== '') {
            enrollFilter.dispatchEvent(new Event('input'));
        }
    }

    (function initInviteCourses() {
        var root = document.querySelector('[data-invite-courses]');
        if (!root) return;

        var toggle = document.getElementById('invite-courses-toggle');
        var panel = document.getElementById('invite-courses-panel');
        var filter = document.getElementById('invite-course-filter');
        var grid = document.getElementById('invite-course-grid');
        var form = document.getElementById('invite-create-form');
        var titleEl = root.querySelector('[data-invite-courses-title]');
        var summary = root.querySelector('[data-invite-courses-summary]');
        var countEl = root.querySelector('[data-invite-selected-count]');
        var chips = root.querySelector('[data-invite-courses-chips]');
        var selectAll = root.querySelector('[data-invite-select-all]');
        var clearAll = root.querySelector('[data-invite-clear-all]');

        function boxes() {
            return root.querySelectorAll('input[name="course_ids[]"]');
        }

        function selectedBoxes() {
            return Array.prototype.filter.call(boxes(), function (box) { return box.checked; });
        }

        function courseTitle(box) {
            var item = box.closest('[data-invite-course]');
            if (!item) return 'Course';
            return item.getAttribute('data-course-title')
                || ((item.querySelector('strong') || {}).textContent || 'Course').trim();
        }

        function syncSelection() {
            var selected = selectedBoxes();
            var n = selected.length;
            var open = root.classList.contains('is-open');
            root.classList.toggle('has-selection', n > 0);
            if (countEl) countEl.textContent = String(n);

            boxes().forEach(function (box) {
                var item = box.closest('[data-invite-course]');
                if (item) item.classList.toggle('is-selected', box.checked);
            });

            if (titleEl) {
                titleEl.textContent = n === 0 ? 'Choose modules' : (n === 1 ? '1 course selected' : n + ' courses selected');
            }
            if (summary) {
                if (n === 0) {
                    summary.textContent = open
                        ? 'Tick every module this student should join'
                        : 'Open to select one or more courses';
                } else if (open) {
                    summary.textContent = 'You can add or remove courses below';
                } else {
                    summary.textContent = 'Click to review or change the selection';
                }
            }

            if (chips) {
                chips.innerHTML = '';
                if (n === 0) {
                    chips.hidden = true;
                } else {
                    chips.hidden = false;
                    selected.slice(0, 6).forEach(function (box) {
                        var chip = document.createElement('span');
                        chip.className = 'admin-invite-chip';
                        var label = document.createElement('span');
                        label.textContent = courseTitle(box);
                        chip.appendChild(label);
                        chips.appendChild(chip);
                    });
                    if (n > 6) {
                        var more = document.createElement('span');
                        more.className = 'admin-invite-chip';
                        more.innerHTML = '<span>+' + (n - 6) + ' more</span>';
                        chips.appendChild(more);
                    }
                }
            }
        }

        function setOpen(open, opts) {
            if (!toggle || !panel) return;
            opts = opts || {};
            if (open) {
                panel.hidden = false;
                // Force reflow so the open transition runs after unhiding.
                void panel.offsetHeight;
                root.classList.add('is-open');
                toggle.setAttribute('aria-expanded', 'true');
                panel.classList.add('is-open');
                syncSelection();
                if (filter && opts.focusFilter) {
                    window.setTimeout(function () { filter.focus(); }, 220);
                }
            } else {
                root.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
                panel.classList.remove('is-open');
                syncSelection();
                window.setTimeout(function () {
                    if (!root.classList.contains('is-open')) {
                        panel.hidden = true;
                    }
                }, 280);
            }
        }

        if (toggle) {
            toggle.addEventListener('click', function () {
                setOpen(!root.classList.contains('is-open'), { focusFilter: true });
            });
        }

        if (filter && grid) {
            filter.addEventListener('input', function () {
                var q = filter.value.trim().toLowerCase();
                grid.querySelectorAll('[data-invite-course]').forEach(function (item) {
                    var hay = item.getAttribute('data-enroll-search') || '';
                    item.hidden = q !== '' && hay.indexOf(q) === -1;
                });
            });
        }

        boxes().forEach(function (box) {
            box.addEventListener('change', syncSelection);
        });

        if (selectAll) {
            selectAll.addEventListener('click', function () {
                boxes().forEach(function (box) {
                    var item = box.closest('[data-invite-course]');
                    if (item && !item.hidden) box.checked = true;
                });
                syncSelection();
            });
        }

        if (clearAll) {
            clearAll.addEventListener('click', function () {
                boxes().forEach(function (box) { box.checked = false; });
                syncSelection();
            });
        }

        if (form) {
            form.addEventListener('submit', function (event) {
                if (selectedBoxes().length === 0) {
                    event.preventDefault();
                    setOpen(true);
                    root.classList.add('is-validation-shaking');
                    window.setTimeout(function () {
                        root.classList.remove('is-validation-shaking');
                    }, 400);
                    if (summary) summary.textContent = 'Select at least one course before creating the invite';
                }
            });
        }

        // Start collapsed — expands only when the teacher clicks.
        setOpen(false, { focusFilter: false });
    })();

    var securityPageCheck = document.getElementById('security-select-page');
    var securityMatchingWrap = document.getElementById('security-select-matching-wrap');
    var securityMatchingToggle = document.getElementById('security-select-matching-toggle');
    var securityMatchingField = document.getElementById('security-select-all-matching');
    var securityRowChecks = document.querySelectorAll('.security-event-check:not([disabled])');
    if (securityPageCheck && securityMatchingWrap && securityMatchingField) {
        securityPageCheck.addEventListener('change', function () {
            var checked = securityPageCheck.checked;
            securityRowChecks.forEach(function (box) {
                box.checked = checked;
            });
            securityMatchingWrap.hidden = !checked;
            if (!checked && securityMatchingToggle) {
                securityMatchingToggle.checked = false;
                securityMatchingField.value = '0';
            }
        });
        if (securityMatchingToggle) {
            securityMatchingToggle.addEventListener('change', function () {
                securityMatchingField.value = securityMatchingToggle.checked ? '1' : '0';
            });
        }
    }

    var profileDataNode = document.getElementById('security-user-profiles-data');
    var profileOverlay = document.getElementById('security-profile-overlay');
    var profileBody = document.getElementById('security-profile-body');
    var profileTitle = document.getElementById('security-profile-title');
    var profileActions = document.getElementById('security-profile-actions');
    var profileActionGrid = document.getElementById('security-profile-action-grid');
    var profileActionTemplate = document.getElementById('security-profile-action-template');
    var profileData = {};
    if (profileDataNode) {
        try {
            profileData = JSON.parse(profileDataNode.textContent || '{}') || {};
        } catch (err) {
            profileData = {};
        }
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function eventTypeLabel(type) {
        return String(type || '')
            .replace(/_/g, ' ')
            .replace(/\b\w/g, function (ch) { return ch.toUpperCase(); });
    }

    function closeSecurityProfile() {
        if (!profileOverlay) return;
        profileOverlay.hidden = true;
        profileOverlay.classList.remove('is-open');
        document.body.classList.remove('admin-profile-open');
    }

    function openSecurityProfile(userId, eventId) {
        if (!profileOverlay || !profileBody || !profileTitle) return;
        var profile = profileData[String(userId)] || profileData[userId];
        if (!profile) return;

        profileTitle.textContent = profile.name || profile.username || ('User #' + userId);
        var counts = profile.event_counts || {};
        var recent = Array.isArray(profile.recent_events) ? profile.recent_events : [];
        var recentHtml = recent.length
            ? '<ul class="admin-profile-event-list">' + recent.map(function (ev) {
                return '<li><strong>' + escapeHtml(eventTypeLabel(ev.event_type)) + '</strong>'
                    + ' <span class="admin-severity admin-severity--' + escapeHtml(ev.severity || 'info') + '">'
                    + escapeHtml((ev.severity || 'info').charAt(0).toUpperCase() + (ev.severity || 'info').slice(1))
                    + '</span>'
                    + '<span class="admin-table-meta">' + escapeHtml(ev.created_at || '') + '</span>'
                    + '<p>' + escapeHtml(ev.details || '') + '</p></li>';
            }).join('') + '</ul>'
            : '<p class="admin-card-lead">No recent security events for this account.</p>';

        profileBody.innerHTML = ''
            + '<div class="admin-profile-identity">'
            +   '<div class="admin-profile-avatar">' + escapeHtml(profile.initials || '?') + '</div>'
            +   '<div>'
            +     '<p class="admin-profile-name">' + escapeHtml(profile.name || profile.username || '') + '</p>'
            +     '<p class="admin-table-meta">@' + escapeHtml(profile.username || '') + ' · ' + escapeHtml(profile.role || '') + '</p>'
            +     '<span class="admin-badge admin-badge--' + (profile.account_status === 'active' ? 'open' : 'draft') + '">'
            +       escapeHtml(profile.account_status_label || 'Active')
            +     '</span>'
            +   '</div>'
            + '</div>'
            + '<dl class="admin-profile-meta">'
            +   '<div><dt>Email</dt><dd>' + escapeHtml(profile.email || '—') + '</dd></div>'
            +   '<div><dt>Year</dt><dd>' + escapeHtml(profile.year || '—') + '</dd></div>'
            +   '<div><dt>Programme</dt><dd>' + escapeHtml(profile.programme || '—') + '</dd></div>'
            +   '<div><dt>Enrolments</dt><dd>' + escapeHtml(String(profile.enrollment_count || 0)) + '</dd></div>'
            +   '<div><dt>Events (' + escapeHtml(String(counts.total || 0)) + ' this period)</dt><dd>'
            +     escapeHtml(String(counts.unreviewed || 0)) + ' unreviewed · '
            +     escapeHtml(String(counts.high || 0)) + ' high'
            +   '</dd></div>'
            + '</dl>'
            + '<div class="admin-profile-recent"><h4>Recent security activity</h4>' + recentHtml + '</div>';

        if (profileActions && profileActionGrid && profileActionTemplate) {
            profileActionGrid.innerHTML = '';
            if (profile.can_act) {
                var actions = [
                    { key: 'mute', label: 'Mute discussions' },
                    { key: 'restrict', label: 'Restrict submissions' },
                    { key: 'ban', label: 'Ban account', danger: true },
                    { key: 'activate', label: 'Clear restrictions' },
                    { key: 'delete', label: 'Delete account', danger: true }
                ];
                actions.forEach(function (action) {
                    if (action.key === 'activate' && profile.account_status === 'active') return;
                    var node = profileActionTemplate.content.cloneNode(true);
                    var form = node.querySelector('form');
                    var button = node.querySelector('button');
                    form.querySelector('input[name="account_action"]').value = action.key;
                    form.querySelector('input[name="target_user_id"]').value = String(profile.id);
                    form.querySelector('input[name="reason"]').value = eventId
                        ? ('From security event #' + eventId)
                        : 'From security profile panel';
                    button.textContent = action.label;
                    if (action.danger) button.classList.add('admin-btn--danger');
                    form.addEventListener('submit', function (ev) {
                        var msg = action.key === 'delete'
                            ? 'Delete this account permanently?'
                            : 'Apply “' + action.label + '” to ' + (profile.username || profile.name) + '?';
                        if (!window.confirm(msg)) ev.preventDefault();
                    });
                    profileActionGrid.appendChild(node);
                });
                profileActions.hidden = false;
            } else {
                profileActions.hidden = true;
            }
        }

        profileOverlay.classList.add('is-open');
        profileOverlay.hidden = false;
        document.body.classList.add('admin-profile-open');
        var panel = profileOverlay.querySelector('.admin-profile-panel');
        if (panel) panel.focus();
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) return;
        var openBtn = target.closest('[data-security-profile]');
        if (openBtn) {
            event.preventDefault();
            openSecurityProfile(
                openBtn.getAttribute('data-security-profile'),
                openBtn.getAttribute('data-security-event') || ''
            );
            return;
        }
        if (target.closest('[data-security-profile-close]')) {
            closeSecurityProfile();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeSecurityProfile();
        }
    });
})();

(function () {
    var btn = document.getElementById('invite-copy-btn');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-copy-target') || 'invite-link-once';
        var input = document.getElementById(id);
        if (!input) return;
        input.select();
        input.setSelectionRange(0, input.value.length);
        var done = function () {
            var prev = btn.textContent;
            btn.textContent = 'Copied';
            setTimeout(function () { btn.textContent = prev; }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(input.value).then(done).catch(function () {
                try { document.execCommand('copy'); done(); } catch (e) {}
            });
        } else {
            try { document.execCommand('copy'); done(); } catch (e) {}
        }
    });
})();
</script>
<?php
$page_content = ob_get_clean();

require __DIR__ . '/../layout.php';
