<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../course_catalog.php';

portal_require_admin();

$currentUser = portal_current_user();
$isOwner     = portal_is_owner();
$pdo         = portal_db();

$adminSections = ['dashboard', 'users', 'courses', 'enrollments', 'integrity', 'security'];
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
        $programme = trim((string) ($_POST['programme'] ?? 'General'));
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
                    $name, $year, $programme, $initials, $newRole,
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
        $programme = trim((string) ($_POST['programme'] ?? 'General'));
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

        $pdo->prepare('UPDATE users SET username = ?, email = ?, name = ?, year = ?, programme = ?, initials = ?, role = ? WHERE id = ?')
            ->execute([$username, $email, $name, $year, $programme, $initials, $newRole, $targetId]);

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
            $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$newRole, $targetId]);
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
        } else {
            $pdo->prepare('DELETE FROM enrollments WHERE user_id = ?')->execute([$targetId]);
            $stmtE = $pdo->prepare('INSERT OR IGNORE INTO enrollments (user_id, course_id) VALUES (?,?)');
            foreach ($courseIds as $cid) {
                if ($cid > 0) {
                    $stmtE->execute([$targetId, $cid]);
                }
            }
            $_SESSION['admin_flash'] = ['success', "Enrolments for {$target['name']} saved."];
        }
        $redirectSection('enrollments', ['enroll' => $targetId > 0 ? $targetId : null]);
    }

    if ($action === 'create_course') {
        $title       = trim((string) ($_POST['title'] ?? ''));
        $fullTitle   = trim((string) ($_POST['full_title'] ?? ''));
        $code        = trim((string) ($_POST['code'] ?? ''));
        $slug        = strtolower(trim((string) ($_POST['slug'] ?? '')));
        $summary     = trim((string) ($_POST['summary'] ?? ''));
        $yearGroup   = trim((string) ($_POST['year_group'] ?? '25/26'));
        $term        = trim((string) ($_POST['term'] ?? 'Full year'));
        $status      = (string) ($_POST['status'] ?? 'draft');
        $statusLabel = trim((string) ($_POST['status_label'] ?? ''));
        $accent      = trim((string) ($_POST['accent'] ?? '#c1202f'));
        $meeting     = trim((string) ($_POST['meeting'] ?? 'TBA'));
        $room        = trim((string) ($_POST['room'] ?? 'TBA'));
        $notice      = trim((string) ($_POST['notice'] ?? ''));

        if (!in_array($status, ['open', 'draft', 'archived'], true)) {
            $status = 'draft';
        }
        if ($statusLabel === '') {
            $statusLabel = portal_course_status_label($status);
        }

        if ($title === '' || $fullTitle === '' || $code === '') {
            $_SESSION['admin_flash'] = ['error', 'Title, full title, and course code are required.'];
        } elseif ($slug === '' || !portal_valid_course_slug($slug)) {
            $_SESSION['admin_flash'] = ['error', 'Slug must use lowercase letters, numbers, and hyphens only.'];
        } elseif (!portal_valid_course_accent($accent)) {
            $_SESSION['admin_flash'] = ['error', 'Accent must be a valid hex colour like #c1202f.'];
        } elseif (portal_course_slug_taken($slug)) {
            $_SESSION['admin_flash'] = ['error', 'That slug is already in use.'];
        } elseif (portal_course_code_taken($code)) {
            $_SESSION['admin_flash'] = ['error', 'That course code is already in use.'];
        } else {
            try {
                $pdo->prepare("
                    INSERT INTO courses
                        (slug, code, title, full_title, summary, year_group, term, status, status_label,
                         accent, meeting, room, notice, student_count)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0)
                ")->execute([
                    $slug, $code, $title, $fullTitle, $summary, $yearGroup, $term,
                    $status, $statusLabel, $accent, $meeting, $room, $notice,
                ]);
                $_SESSION['admin_flash'] = ['success', "Course “{$title}” created."];
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
        $fullTitle   = trim((string) ($_POST['full_title'] ?? ''));
        $code        = trim((string) ($_POST['code'] ?? ''));
        $summary     = trim((string) ($_POST['summary'] ?? ''));
        $yearGroup   = trim((string) ($_POST['year_group'] ?? ''));
        $term        = trim((string) ($_POST['term'] ?? ''));
        $status      = (string) ($_POST['status'] ?? 'draft');
        $statusLabel = trim((string) ($_POST['status_label'] ?? ''));
        $accent      = trim((string) ($_POST['accent'] ?? '#c1202f'));
        $meeting     = trim((string) ($_POST['meeting'] ?? ''));
        $room        = trim((string) ($_POST['room'] ?? ''));
        $notice      = trim((string) ($_POST['notice'] ?? ''));

        if (!$course) {
            $_SESSION['admin_flash'] = ['error', 'Course not found.'];
        } elseif ($title === '' || $fullTitle === '' || $code === '') {
            $_SESSION['admin_flash'] = ['error', 'Title, full title, and course code are required.'];
        } elseif (!in_array($status, ['open', 'draft', 'archived'], true)) {
            $_SESSION['admin_flash'] = ['error', 'Invalid status.'];
        } elseif (!portal_valid_course_accent($accent)) {
            $_SESSION['admin_flash'] = ['error', 'Accent must be a valid hex colour like #c1202f.'];
        } elseif (portal_course_code_taken($code, $courseId)) {
            $_SESSION['admin_flash'] = ['error', 'That course code is already in use.'];
        } else {
            if ($statusLabel === '') {
                $statusLabel = portal_course_status_label($status);
            }
            $pdo->prepare("
                UPDATE courses SET
                    title = ?, full_title = ?, code = ?, summary = ?, year_group = ?, term = ?,
                    status = ?, status_label = ?, accent = ?, meeting = ?, room = ?, notice = ?
                WHERE id = ?
            ")->execute([
                $title, $fullTitle, $code, $summary, $yearGroup, $term,
                $status, $statusLabel, $accent, $meeting, $room, $notice, $courseId,
            ]);
            $_SESSION['admin_flash'] = ['success', "Course “{$title}” updated."];
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
        $fullTitle = trim((string) ($_POST['full_title'] ?? ''));
        $code      = trim((string) ($_POST['code'] ?? ''));
        $slug      = strtolower(trim((string) ($_POST['slug'] ?? '')));

        if (!$source) {
            $_SESSION['admin_flash'] = ['error', 'Source course not found.'];
        } elseif ($title === '' || $fullTitle === '' || $code === '') {
            $_SESSION['admin_flash'] = ['error', 'Title, full title, and course code are required.'];
        } elseif ($slug === '' || !portal_valid_course_slug($slug)) {
            $_SESSION['admin_flash'] = ['error', 'Slug must use lowercase letters, numbers, and hyphens only.'];
        } elseif (portal_course_slug_taken($slug)) {
            $_SESSION['admin_flash'] = ['error', 'That slug is already in use.'];
        } elseif (portal_course_code_taken($code)) {
            $_SESSION['admin_flash'] = ['error', 'That course code is already in use.'];
        } else {
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
                    (string) $source['year_group'],
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
        ]);
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

// ── Page data ─────────────────────────────────────────────────────────────────
$users              = portal_all_users();
$adminCourses       = portal_admin_course_rows();
$allCourses         = portal_course_catalog();
$enrollmentCounts   = portal_user_enrollment_counts();
$enrollTarget       = $enrollTargetId > 0 ? portal_find_user_by_id($enrollTargetId) : null;
$enrolledIds        = $enrollTarget ? portal_enrolled_course_ids($enrollTargetId) : [];
$editCourse         = $editCourseId > 0 ? portal_find_course_by_id($editCourseId) : null;
$duplicateCourse    = $duplicateCourseId > 0 ? portal_find_course_by_id($duplicateCourseId) : null;
$editUser           = $editUserId > 0 ? portal_find_user_by_id($editUserId) : null;
$yearGroupOptions   = portal_year_group_options();

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
        $haystack = implode(' ', [$u['name'], $u['email'], $u['username'], $u['year'], $u['programme']]);
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
    'archived_courses'  => count(array_filter($adminCourses, fn($c) => $c['status'] === 'archived')),
    'draft_courses'     => count(array_filter($adminCourses, fn($c) => $c['status'] === 'draft')),
    'total_enrollments' => (int) $pdo->query('SELECT COUNT(*) FROM enrollments')->fetchColumn(),
    'total_submissions' => (int) $pdo->query('SELECT COUNT(*) FROM course_submissions')->fetchColumn(),
];

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
if (!in_array($secPeriod, ['24h', '7d', '30d'], true)) {
    $secPeriod = '24h';
}

$securityStats   = portal_security_dashboard_stats($secPeriod);
$securityEvents  = portal_security_events_filtered($secPeriod, $secReviewed, $secSeverity, $secType, 100);
$securityTypes   = [
    'failed_login', 'login_throttled', 'csrf_failed', 'unauthorised_admin_access',
    'unauthorised_course_access', 'forbidden_download', 'blocked_upload',
    'unsafe_rich_text_removed', 'role_changed', 'user_deleted', 'course_archived', 'course_restored',
];

$sectionTitles = [
    'dashboard'   => 'Dashboard',
    'users'       => 'Manage Users',
    'courses'     => 'Course Management',
    'enrollments' => 'Enrolments',
    'integrity'   => 'Integrity & Link Safety',
    'security'    => 'Security Activity',
];

$navItems = [
    ['key' => 'dashboard',   'label' => 'Dashboard',               'icon' => 'sparkles'],
    ['key' => 'users',       'label' => 'Manage Users',            'icon' => 'users'],
    ['key' => 'courses',     'label' => 'Course Management',       'icon' => 'book-open'],
    ['key' => 'enrollments', 'label' => 'Enrolments',              'icon' => 'folder'],
    ['key' => 'integrity',   'label' => 'Integrity & Link Safety', 'icon' => 'shield'],
    ['key' => 'security',    'label' => 'Security Activity',     'icon' => 'lock'],
];

$adminUrl = static function (string $targetSection, array $extra = []) use ($adminSections): string {
    $params = array_merge(['section' => $targetSection], array_filter($extra, static fn($v) => $v !== null && $v !== ''));
    return 'admin.php?' . http_build_query($params);
};

$page_title       = 'Admin | ' . portal_school_name();
$active_page      = 'admin';
$page_eyebrow     = 'Administration';
$page_heading     = 'Admin';
$page_description = '';

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
            <h2 class="admin-topbar-title"><?= portal_escape($sectionTitles[$section] ?? 'Dashboard') ?></h2>
        </div>
        <div class="admin-topbar-user">
            <div class="admin-topbar-user-text">
                <strong><?= portal_escape($currentUser['name']) ?></strong>
                <span class="admin-badge admin-badge--<?= portal_escape($currentUser['role']) ?>"><?= portal_escape(ucfirst($currentUser['role'])) ?></span>
            </div>
            <div class="admin-avatar" aria-hidden="true"><?= portal_escape($currentUser['initials'] ?? 'AD') ?></div>
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
                <div class="admin-stat-grid">
                    <article class="admin-stat-card admin-stat-card--priority">
                        <p class="admin-stat-label">Total users</p>
                        <strong class="admin-stat-value"><?= $stats['total_users'] ?></strong>
                    </article>
                    <article class="admin-stat-card admin-stat-card--priority">
                        <p class="admin-stat-label">Students</p>
                        <strong class="admin-stat-value"><?= $stats['students'] ?></strong>
                    </article>
                    <article class="admin-stat-card admin-stat-card--priority">
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
                            <span>Programme</span>
                            <input type="text" name="programme" value="General" placeholder="e.g. Sciences pathway">
                        </label>
                        <label class="admin-field">
                            <span>Password</span>
                            <input type="password" name="password" required minlength="8" placeholder="Min. 8 characters, letter + number">
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
                        <table class="admin-table">
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
                                    <td>
                                        <div class="admin-table-user">
                                            <div class="admin-avatar admin-avatar--sm"><?= portal_escape($u['initials']) ?></div>
                                            <div>
                                                <strong><?= portal_escape($u['name']) ?></strong>
                                                <span class="admin-table-meta"><?= portal_escape((string) $u['programme']) ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= portal_escape($u['username']) ?></td>
                                    <td><?= portal_escape($u['email']) ?></td>
                                    <td><span class="admin-badge admin-badge--<?= portal_escape($u['role']) ?>"><?= portal_escape(ucfirst($u['role'])) ?></span></td>
                                    <td><?= portal_escape($u['year']) ?></td>
                                    <td><?= (int) ($enrollmentCounts[(int) $u['id']] ?? 0) ?></td>
                                    <td>
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
                            <p class="admin-card-lead">Create, edit, archive, and duplicate course spaces without editing the database manually.</p>
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
                                    $badgeClass = in_array($statusKey, ['open', 'draft', 'archived'], true)
                                        ? 'admin-badge--' . $statusKey : 'admin-badge--draft';
                                ?>
                                <tr>
                                    <td>
                                        <div class="admin-course-cell">
                                            <span class="admin-course-accent" style="background:<?= portal_escape((string) $c['accent']) ?>"></span>
                                            <div>
                                                <strong><?= portal_escape((string) $c['title']) ?></strong>
                                                <span class="admin-table-meta"><?= portal_escape((string) $c['slug']) ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= portal_escape((string) $c['code']) ?></td>
                                    <td><?= portal_escape((string) $c['year_group']) ?></td>
                                    <td><?= portal_escape((string) $c['term']) ?></td>
                                    <td><span class="admin-badge <?= portal_escape($badgeClass) ?>"><?= portal_escape((string) $c['status_label']) ?></span></td>
                                    <td>
                                        <span><?= portal_escape((string) $c['meeting']) ?></span>
                                        <span class="admin-table-meta"><?= portal_escape((string) $c['room']) ?></span>
                                    </td>
                                    <td><?= (int) $c['enrollment_count'] ?></td>
                                    <td><?= (int) $c['assigned_staff_count'] ?></td>
                                    <td>
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

            <!-- Enrolments -->
            <section id="admin-section-enrollments" class="admin-section<?= $section === 'enrollments' ? ' is-active' : '' ?>">
                <article class="admin-card">
                    <header class="admin-card-header">
                        <div>
                            <p class="eyebrow">Course access</p>
                            <h3>Manage enrolments</h3>
                            <p class="admin-card-lead">Select a user, then tick the modules they should access.</p>
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
                                <span class="admin-table-meta"><?= portal_escape($enrollTarget['email']) ?> · <?= count($enrolledIds) ?> of <?= count($allCourses) ?> courses</span>
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
                                $cBadge = in_array($cStatus, ['open', 'draft', 'archived'], true) ? 'admin-badge--' . $cStatus : 'admin-badge--draft';
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
                            <button type="submit" class="admin-btn admin-btn--primary">Save enrolments</button>
                            <a href="<?= portal_escape($adminUrl('enrollments')) ?>" class="admin-btn admin-btn--secondary">Clear selection</a>
                        </div>
                    </form>
                    <?php else: ?>
                    <p class="admin-card-lead">Choose a user above to manage their course enrolments.</p>
                    <?php endif; ?>
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
                        <p class="admin-stat-label">Blocked uploads</p>
                        <strong class="admin-stat-value"><?= (int) $securityStats['blocked_uploads'] ?></strong>
                        <p class="admin-stat-caption"><?= $securityStats['blocked_uploads'] === 0 ? 'No dangerous uploads detected' : 'Invalid type or content rejected' ?></p>
                    </article>
                    <article class="admin-stat-card">
                        <p class="admin-stat-label">Unsafe content</p>
                        <strong class="admin-stat-value"><?= (int) $securityStats['unsafe_content'] ?></strong>
                        <p class="admin-stat-caption"><?= $securityStats['unsafe_content'] === 0 ? 'No unsafe HTML detected' : 'Dangerous markup removed' ?></p>
                    </article>
                    <article class="admin-stat-card">
                        <p class="admin-stat-label">Admin actions</p>
                        <strong class="admin-stat-value"><?= (int) $securityStats['admin_actions'] ?></strong>
                        <p class="admin-stat-caption">Role changes, deletions, archive/restore</p>
                    </article>
                </div>

                <article class="admin-card">
                    <header class="admin-card-header">
                        <div>
                            <p class="eyebrow">Activity log</p>
                            <h3>Recent security events</h3>
                            <p class="admin-card-lead">Suspicious sign-ins, blocked access, rejected uploads, and important admin actions.</p>
                        </div>
                        <form method="post" action="<?= portal_escape($adminUrl('security', [
                            'sec_period' => $secPeriod,
                            'sec_reviewed' => $secReviewed,
                            'sec_severity' => $secSeverity,
                            'sec_type' => $secType,
                        ])) ?>" class="admin-inline-form">
                            <?= portal_csrf_field() ?>
                            <input type="hidden" name="action" value="mark_security_low_info_reviewed">
                            <input type="hidden" name="sec_period" value="<?= portal_escape($secPeriod) ?>">
                            <input type="hidden" name="sec_reviewed" value="<?= portal_escape($secReviewed) ?>">
                            <input type="hidden" name="sec_severity" value="<?= portal_escape($secSeverity) ?>">
                            <input type="hidden" name="sec_type" value="<?= portal_escape($secType) ?>">
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
                        <button type="submit" class="admin-btn admin-btn--secondary">Apply filters</button>
                    </form>

                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Date / time</th>
                                    <th>Severity</th>
                                    <th>Event</th>
                                    <th>User</th>
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
                                    if ($evUser === '' && !empty($event['user_id'])) {
                                        $evUser = 'User #' . (int) $event['user_id'];
                                    }
                                    if ($evUser === '') {
                                        $evUser = '—';
                                    }
                                ?>
                                <tr>
                                    <td><?= portal_escape(date('j M Y H:i', strtotime((string) $event['created_at']))) ?></td>
                                    <td><span class="admin-severity admin-severity--<?= portal_escape($evSeverity) ?>"><?= portal_escape(ucfirst($evSeverity)) ?></span></td>
                                    <td><?= portal_escape(portal_security_event_type_label((string) $event['event_type'])) ?></td>
                                    <td><?= portal_escape($evUser) ?></td>
                                    <td><code class="admin-route-code"><?= portal_escape((string) $event['route']) ?></code></td>
                                    <td><?= portal_escape((string) $event['details']) ?></td>
                                    <td>
                                        <?php if ($isReviewed): ?>
                                        <span class="admin-badge admin-badge--open">Reviewed</span>
                                        <?php else: ?>
                                        <span class="admin-badge admin-badge--draft">Open</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$isReviewed): ?>
                                        <form method="post" action="<?= portal_escape($adminUrl('security', [
                                            'sec_period' => $secPeriod,
                                            'sec_reviewed' => $secReviewed,
                                            'sec_severity' => $secSeverity,
                                            'sec_type' => $secType,
                                        ])) ?>" class="admin-inline-form">
                                            <?= portal_csrf_field() ?>
                                            <input type="hidden" name="action" value="mark_security_event_reviewed">
                                            <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                                            <input type="hidden" name="sec_period" value="<?= portal_escape($secPeriod) ?>">
                                            <input type="hidden" name="sec_reviewed" value="<?= portal_escape($secReviewed) ?>">
                                            <input type="hidden" name="sec_severity" value="<?= portal_escape($secSeverity) ?>">
                                            <input type="hidden" name="sec_type" value="<?= portal_escape($secType) ?>">
                                            <button type="submit" class="admin-btn admin-btn--secondary admin-btn--sm">Mark reviewed</button>
                                        </form>
                                        <?php else: ?>
                                        <span class="admin-table-meta">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if ($securityEvents === []): ?>
                                <tr><td colspan="8" class="admin-table-empty">No security events found for these filters.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
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
                <span>Programme</span>
                <input type="text" name="programme" maxlength="120" value="<?= portal_escape((string) $editUser['programme']) ?>">
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
                <input type="password" name="new_password" minlength="8" autocomplete="new-password" placeholder="Min. 8 characters, letter + number">
            </label>
            <label class="admin-field admin-field--full">
                <span>Confirm new password</span>
                <input type="password" name="confirm_password" minlength="8" autocomplete="new-password" placeholder="Repeat new password">
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

<!-- Create course panel -->
<dialog class="admin-panel" id="create-course-panel">
    <form method="post" action="<?= portal_escape($adminUrl('courses')) ?>" class="admin-panel-form">
        <?= portal_csrf_field() ?>
        <input type="hidden" name="action" value="create_course">
        <header class="admin-panel-header">
            <h3>Add course / module</h3>
            <button type="button" class="admin-panel-close" data-admin-close aria-label="Close">&times;</button>
        </header>
        <div class="admin-form-grid">
            <label class="admin-field"><span>Course title</span><input type="text" name="title" required></label>
            <label class="admin-field"><span>Full title</span><input type="text" name="full_title" required></label>
            <label class="admin-field"><span>Course code</span><input type="text" name="code" required placeholder="BIO-2526-01"></label>
            <label class="admin-field"><span>Slug</span><input type="text" name="slug" required pattern="[a-z0-9]+(-[a-z0-9]+)*" placeholder="biology-2526"></label>
            <label class="admin-field admin-field--full"><span>Summary</span><textarea name="summary" rows="3"></textarea></label>
            <label class="admin-field"><span>Year group</span><input type="text" name="year_group" value="25/26"></label>
            <label class="admin-field"><span>Term</span><input type="text" name="term" value="Full year"></label>
            <label class="admin-field"><span>Status</span>
                <select name="status">
                    <option value="draft" selected>Draft</option>
                    <option value="open">Open</option>
                    <option value="archived">Archived</option>
                </select>
            </label>
            <label class="admin-field"><span>Status label</span><input type="text" name="status_label" placeholder="Auto from status"></label>
            <label class="admin-field"><span>Accent colour</span><input type="text" name="accent" value="#c1202f" pattern="#[0-9a-fA-F]{6}"></label>
            <label class="admin-field"><span>Meeting time</span><input type="text" name="meeting" value="TBA"></label>
            <label class="admin-field"><span>Room</span><input type="text" name="room" value="TBA"></label>
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
        <p class="admin-field-hint admin-field-hint--panel">Slug: <code><?= portal_escape((string) $editCourse['slug']) ?></code> (not editable — preserves links and uploads)</p>
        <div class="admin-form-grid">
            <label class="admin-field"><span>Course title</span><input type="text" name="title" required value="<?= portal_escape((string) $editCourse['title']) ?>"></label>
            <label class="admin-field"><span>Full title</span><input type="text" name="full_title" required value="<?= portal_escape((string) $editCourse['full_title']) ?>"></label>
            <label class="admin-field"><span>Course code</span><input type="text" name="code" required value="<?= portal_escape((string) $editCourse['code']) ?>"></label>
            <label class="admin-field admin-field--full"><span>Summary</span><textarea name="summary" rows="3"><?= portal_escape((string) $editCourse['summary']) ?></textarea></label>
            <label class="admin-field"><span>Year group</span><input type="text" name="year_group" value="<?= portal_escape((string) $editCourse['year_group']) ?>"></label>
            <label class="admin-field"><span>Term</span><input type="text" name="term" value="<?= portal_escape((string) $editCourse['term']) ?>"></label>
            <label class="admin-field"><span>Status</span>
                <select name="status">
                    <option value="open"<?= $editCourse['status'] === 'open' ? ' selected' : '' ?>>Open</option>
                    <option value="draft"<?= $editCourse['status'] === 'draft' ? ' selected' : '' ?>>Draft</option>
                    <option value="archived"<?= $editCourse['status'] === 'archived' ? ' selected' : '' ?>>Archived</option>
                </select>
            </label>
            <label class="admin-field"><span>Status label</span><input type="text" name="status_label" value="<?= portal_escape((string) $editCourse['status_label']) ?>"></label>
            <label class="admin-field"><span>Accent colour</span><input type="text" name="accent" value="<?= portal_escape((string) $editCourse['accent']) ?>" pattern="#[0-9a-fA-F]{6}"></label>
            <label class="admin-field"><span>Meeting time</span><input type="text" name="meeting" value="<?= portal_escape((string) $editCourse['meeting']) ?>"></label>
            <label class="admin-field"><span>Room</span><input type="text" name="room" value="<?= portal_escape((string) $editCourse['room']) ?>"></label>
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
        <p class="admin-field-hint admin-field-hint--panel">Copying metadata from <strong><?= portal_escape((string) $duplicateCourse['title']) ?></strong>. Enrolments, submissions, and materials are not copied.</p>
        <div class="admin-form-grid">
            <label class="admin-field"><span>New course title</span><input type="text" name="title" required value="<?= portal_escape((string) $duplicateCourse['title'] . ' (copy)') ?>"></label>
            <label class="admin-field"><span>New full title</span><input type="text" name="full_title" required value="<?= portal_escape((string) $duplicateCourse['full_title'] . ' (copy)') ?>"></label>
            <label class="admin-field"><span>New course code</span><input type="text" name="code" required placeholder="NEW-CODE-01"></label>
            <label class="admin-field"><span>New slug</span><input type="text" name="slug" required pattern="[a-z0-9]+(-[a-z0-9]+)*" placeholder="new-slug-2526"></label>
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
    document.querySelectorAll('[data-admin-open]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-admin-open');
            var dlg = document.getElementById(id);
            if (dlg && typeof dlg.showModal === 'function') dlg.showModal();
        });
    });

    document.querySelectorAll('[data-admin-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var dlg = btn.closest('dialog');
            if (dlg) dlg.close();
        });
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
})();
</script>
<?php
$page_content = ob_get_clean();

require __DIR__ . '/../layout.php';
