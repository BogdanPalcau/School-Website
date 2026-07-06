<?php
declare(strict_types=1);

date_default_timezone_set('Europe/London');

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Harden the session cookie: not readable by JS, sent same-site, and
    // marked Secure automatically when the request is served over HTTPS.
    $portalCookieSecure = (
        (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
    );
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $portalCookieSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ── Utilities ─────────────────────────────────────────────────────────────────

if (!function_exists('portal_escape')) {
    function portal_escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('portal_render_rich_text')) {
    function portal_render_rich_text(string $body): string
    {
        if ($body === '') return '';
        $allowed = '<p><br><strong><b><em><i><u><s><h1><h2><h3><ul><ol><li><blockquote><span>';
        $clean   = strip_tags($body, $allowed);
        // Strip any event handler or javascript: attributes that sneak through
        $clean   = preg_replace('/\s*on\w+\s*=\s*["\'][^"\']*["\']/i', '', $clean) ?? $clean;
        $clean   = preg_replace('/\s*javascript\s*:/i', '', $clean) ?? $clean;
        return $clean;
    }
}

if (!function_exists('portal_school_name')) {
    function portal_school_name(): string
    {
        return 'Rangoon International Education Online';
    }
}

if (!function_exists('portal_school_short_name')) {
    function portal_school_short_name(): string
    {
        return 'RIEO';
    }
}

if (!function_exists('portal_icon')) {
    function portal_icon(string $name, string $class = 'icon'): string
    {
        $icons = [
            'book-open'  => '<path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/>',
            'calendar'   => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
            'clock'      => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
            'megaphone'  => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
            'sparkles'   => '<path d="m12 3-1.9 5.8L4 11l6.1 2.2L12 19l1.9-5.8L20 11l-6.1-2.2L12 3z"/><path d="M5 3v4"/><path d="M3 5h4"/><path d="M19 17v4"/><path d="M17 19h4"/>',
            'settings'   => '<path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1A2 2 0 1 1 4.2 17l.1-.1A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.6-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.3 7A2 2 0 1 1 7.1 4.2l.1.1a1.7 1.7 0 0 0 1.9.3 1.7 1.7 0 0 0 1-1.6V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1A2 2 0 1 1 19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.1a2 2 0 1 1 0 4H21a1.7 1.7 0 0 0-1.6 1z"/>',
            'log-out'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
            'user'       => '<path d="M19 21a7 7 0 0 0-14 0"/><circle cx="12" cy="8" r="4"/>',
            'lock'       => '<rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
            'arrow-right'=> '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
            'shield'     => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'users'         => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'folder'        => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
            'file'          => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"/><polyline points="14 2 14 8 20 8"/>',
            'presentation'  => '<rect x="3" y="4" width="18" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 18v3"/><path d="M8 9h8"/><path d="M8 13h5"/>',
            'link'          => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
            'upload'        => '<polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/>',
            'plus'          => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
            'trash'         => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
            'chevron-down'  => '<polyline points="6 9 12 15 18 9"/>',
            'grip'          => '<circle cx="9" cy="5" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="19" r="1"/>',
            'download'      => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
            'edit'          => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
        ];

        $body = $icons[$name] ?? $icons['book-open'];
        return '<svg class="' . portal_escape($class) . '" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
    }
}

if (!function_exists('portal_presentation_extensions')) {
    function portal_presentation_extensions(): array
    {
        return ['ppt', 'pptx', 'pps', 'ppsx', 'pot', 'potx', 'odp'];
    }
}

if (!function_exists('portal_supported_upload_extensions')) {
    function portal_supported_upload_extensions(): array
    {
        return array_merge(['doc', 'docx', 'xlsx', 'pdf', 'txt'], portal_presentation_extensions());
    }
}

if (!function_exists('portal_is_presentation_file')) {
    function portal_is_presentation_file(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, portal_presentation_extensions(), true);
    }
}

if (!function_exists('portal_supported_upload_hint')) {
    function portal_supported_upload_hint(): string
    {
        return '.doc .docx .xlsx .pdf .txt .ppt .pptx .pps .ppsx .pot .potx .odp';
    }
}

if (!function_exists('portal_submission_deadline_info')) {
    /**
     * @return array{has_deadline: bool, text: string, state: string, passed: bool, timestamp?: int}
     */
    function portal_submission_deadline_info(string $deadlineRaw): array
    {
        if (trim($deadlineRaw) === '') {
            return [
                'has_deadline' => false,
                'text' => 'No deadline set',
                'state' => 'none',
                'passed' => false,
            ];
        }

        $ts = strtotime($deadlineRaw);
        if ($ts === false) {
            return [
                'has_deadline' => false,
                'text' => 'No deadline set',
                'state' => 'none',
                'passed' => false,
            ];
        }

        $passed = time() > $ts;
        $text = date('j M Y H:i', $ts);
        if ($passed) {
            return [
                'has_deadline' => true,
                'text' => $text,
                'state' => 'closed',
                'passed' => true,
                'timestamp' => $ts,
            ];
        }

        $hoursLeft = ($ts - time()) / 3600;

        return [
            'has_deadline' => true,
            'text' => $text,
            'state' => $hoursLeft <= 48 ? 'soon' : 'open',
            'passed' => false,
            'timestamp' => $ts,
        ];
    }
}

if (!function_exists('portal_render_submission_deadline')) {
    function portal_render_submission_deadline(string $deadlineRaw, string $modifier = ''): string
    {
        $info = portal_submission_deadline_info($deadlineRaw);
        $classes = 'sub-slot-deadline sub-slot-deadline--' . $info['state'];
        if ($modifier !== '') {
            $classes .= ' ' . $modifier;
        }

        ob_start();
        ?>
        <div class="<?= portal_escape($classes) ?>">
            <span class="sub-slot-deadline-icon"><?= portal_icon('clock', 'icon-xs') ?></span>
            <span class="sub-slot-deadline-body">
                <span class="sub-slot-deadline-label">Due date</span>
                <strong class="sub-slot-deadline-value"><?= portal_escape($info['text']) ?></strong>
            </span>
            <?php if ($info['passed']): ?>
                <span class="sub-slot-deadline-tag sub-slot-deadline-tag--closed">Closed</span>
            <?php elseif ($info['state'] === 'soon'): ?>
                <span class="sub-slot-deadline-tag sub-slot-deadline-tag--soon">Due soon</span>
            <?php endif; ?>
        </div>
        <?php
        return trim((string) ob_get_clean());
    }
}

// ── Database ──────────────────────────────────────────────────────────────────

if (!function_exists('portal_db')) {
    function portal_db(): PDO
    {
        static $pdo = null;

        if ($pdo === null) {
            $path = __DIR__ . '/database/portal.db';
            $pdo  = new PDO('sqlite:' . $path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA foreign_keys = ON');
        }

        return $pdo;
    }
}

// ── Users ─────────────────────────────────────────────────────────────────────

if (!function_exists('portal_find_user')) {
    function portal_find_user(string $identifier): ?array
    {
        $needle = strtolower(trim($identifier));
        $stmt   = portal_db()->prepare(
            "SELECT * FROM users WHERE LOWER(username) = ? OR LOWER(email) = ? LIMIT 1"
        );
        $stmt->execute([$needle, $needle]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}

if (!function_exists('portal_find_user_by_id')) {
    function portal_find_user_by_id(int $id): ?array
    {
        $stmt = portal_db()->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}

if (!function_exists('portal_all_users')) {
    function portal_all_users(): array
    {
        return portal_db()
            ->query("SELECT * FROM users ORDER BY role ASC, name ASC")
            ->fetchAll();
    }
}

if (!function_exists('portal_default_student')) {
    function portal_default_student(): array
    {
        return [
            'id'        => 0,
            'name'      => 'Student',
            'year'      => 'Year group',
            'programme' => 'Student pathway',
            'initials'  => 'ST',
            'role'      => 'student',
        ];
    }
}

// ── Session / Auth ────────────────────────────────────────────────────────────

if (!function_exists('portal_is_logged_in')) {
    function portal_is_logged_in(): bool
    {
        return isset($_SESSION['portal_user']) && is_array($_SESSION['portal_user']);
    }
}

if (!function_exists('portal_current_user')) {
    function portal_current_user(): array
    {
        if (!portal_is_logged_in()) {
            return portal_default_student();
        }

        return $_SESSION['portal_user'];
    }
}

if (!function_exists('portal_current_user_role')) {
    function portal_current_user_role(): string
    {
        $user = portal_current_user();

        // Always prefer the role from the database when we have a user id.
        // This avoids stale session values showing the wrong account type.
        if (isset($user['id']) && (int) $user['id'] > 0) {
            $stmt = portal_db()->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([(int) $user['id']]);
            $dbRole = (string) ($stmt->fetchColumn() ?: '');

            if ($dbRole !== '') {
                $_SESSION['portal_user']['role'] = $dbRole;
                return $dbRole;
            }
        }

        if (isset($user['role']) && $user['role'] !== '') {
            return $user['role'];
        }

        return 'student';
    }
}

if (!function_exists('portal_is_admin')) {
    function portal_is_admin(): bool
    {
        return in_array(portal_current_user_role(), ['admin', 'owner'], true);
    }
}

if (!function_exists('portal_is_owner')) {
    function portal_is_owner(): bool
    {
        return portal_current_user_role() === 'owner';
    }
}

if (!function_exists('portal_require_admin')) {
    function portal_require_admin(): void
    {
        portal_require_login();

        if (!portal_is_admin()) {
            portal_redirect('courses.php');
        }
    }
}

if (!function_exists('portal_enrolled_course_ids')) {
    function portal_enrolled_course_ids(int $user_id): array
    {
        $stmt = portal_db()->prepare(
            "SELECT course_id FROM enrollments WHERE user_id = ?"
        );
        $stmt->execute([$user_id]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}

if (!function_exists('portal_login_time_text')) {
    function portal_login_time_text(): string
    {
        $value = $_SESSION['portal_login_at'] ?? '';

        if (!is_string($value) || $value === '') {
            return 'Today';
        }

        return $value;
    }
}

if (!function_exists('portal_redirect')) {
    function portal_redirect(string $location): never
    {
        header('Location: ' . $location);
        exit;
    }
}

if (!function_exists('portal_store_intended_path')) {
    function portal_store_intended_path(): void
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';

        if ($requestUri !== '' && !str_contains($requestUri, '/login.php')) {
            $_SESSION['portal_intended_path'] = $requestUri;
        }
    }
}

if (!function_exists('portal_consume_intended_path')) {
    function portal_consume_intended_path(): string
    {
        $default = 'courses.php';
        $target  = $_SESSION['portal_intended_path'] ?? $default;
        unset($_SESSION['portal_intended_path']);

        if (!is_string($target) || $target === '' || str_contains($target, '://') || str_starts_with($target, '//')) {
            return $default;
        }

        return $target;
    }
}

if (!function_exists('portal_require_login')) {
    function portal_require_login(): void
    {
        if (!portal_is_logged_in()) {
            portal_store_intended_path();
            portal_redirect('login.php');
        }
    }
}

if (!function_exists('portal_attempt_login')) {
    function portal_attempt_login(string $identifier, string $password): bool
    {
        $user = portal_find_user($identifier);

        if ($user === null || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);

        $_SESSION['portal_user'] = [
            'id'        => (int) $user['id'],
            'username'  => $user['username'],
            'email'     => $user['email'],
            'name'      => $user['name'],
            'year'      => $user['year'],
            'programme' => $user['programme'],
            'initials'  => $user['initials'],
            'role'      => $user['role'],
        ];
        $_SESSION['portal_login_at'] = date('l j F Y \a\t H:i');

        return true;
    }
}

if (!function_exists('portal_logout')) {
    function portal_logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }

        session_destroy();
    }
}

// ── CSRF protection helpers ───────────────────────────────────────────────────

if (!function_exists('portal_csrf_token')) {
    function portal_csrf_token(): string
    {
        if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }
}

if (!function_exists('portal_csrf_field')) {
    function portal_csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . portal_escape(portal_csrf_token()) . '">';
    }
}

if (!function_exists('portal_verify_csrf')) {
    function portal_verify_csrf(): bool
    {
        $token = $_POST['_token'] ?? '';
        return is_string($token)
            && $token !== ''
            && !empty($_SESSION['_csrf'])
            && hash_equals((string) $_SESSION['_csrf'], $token);
    }
}

// ── Course access control (enrollment / management) ───────────────────────────

if (!function_exists('portal_can_access_course')) {
    function portal_can_access_course(int $courseId): bool
    {
        if ($courseId <= 0 || !portal_is_logged_in()) {
            return false;
        }
        // Admins, owners, and teachers assigned to the course can always enter.
        if (portal_can_manage_course($courseId)) {
            return true;
        }
        // Otherwise the user must be enrolled.
        $user = portal_current_user();
        $stmt = portal_db()->prepare(
            "SELECT 1 FROM enrollments WHERE user_id = ? AND course_id = ? LIMIT 1"
        );
        $stmt->execute([(int) $user['id'], $courseId]);
        return (bool) $stmt->fetchColumn();
    }
}

// ── Login brute-force throttling (per client IP) ──────────────────────────────

if (!function_exists('portal_client_ip')) {
    function portal_client_ip(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return $ip !== '' ? $ip : 'unknown';
    }
}

if (!function_exists('portal_login_is_locked')) {
    function portal_login_is_locked(string $ip, int $maxAttempts = 8, int $windowSeconds = 900): bool
    {
        try {
            $stmt = portal_db()->prepare(
                "SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > ?"
            );
            $stmt->execute([$ip, time() - $windowSeconds]);
            return (int) $stmt->fetchColumn() >= $maxAttempts;
        } catch (\PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('portal_login_record_failure')) {
    function portal_login_record_failure(string $ip): void
    {
        try {
            portal_db()
                ->prepare("INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)")
                ->execute([$ip, time()]);
        } catch (\PDOException $e) {}
    }
}

if (!function_exists('portal_login_clear_attempts')) {
    function portal_login_clear_attempts(string $ip): void
    {
        try {
            portal_db()
                ->prepare("DELETE FROM login_attempts WHERE ip = ?")
                ->execute([$ip]);
        } catch (\PDOException $e) {}
    }
}

// ── Upload content-type validation ────────────────────────────────────────────

if (!function_exists('portal_upload_mime_ok')) {
    function portal_upload_mime_ok(string $tmpPath, string $ext): bool
    {
        // If we cannot inspect the file, fall back to the extension whitelist
        // plus the upload-directory .htaccess that blocks script execution.
        if ($tmpPath === '' || !is_file($tmpPath) || !class_exists('finfo')) {
            return true;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmpPath);
        if ($mime === '') {
            return true;
        }

        $zip = 'application/zip';
        $ole = ['application/x-ole-storage', 'application/vnd.ms-office', 'application/CDFV2'];

        $allowed = [
            'pdf'  => ['application/pdf'],
            'txt'  => ['text/plain', 'text/csv', 'application/csv'],
            'png'  => ['image/png'],
            'jpg'  => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'gif'  => ['image/gif'],
            'webp' => ['image/webp'],
            'doc'  => array_merge(['application/msword'], $ole),
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', $zip],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $zip],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', $zip],
            'ppsx' => ['application/vnd.openxmlformats-officedocument.presentationml.slideshow', $zip],
            'potx' => ['application/vnd.openxmlformats-officedocument.presentationml.template', $zip],
            'odp'  => ['application/vnd.oasis.opendocument.presentation', $zip],
            'ppt'  => array_merge(['application/vnd.ms-powerpoint'], $ole),
            'pps'  => array_merge(['application/vnd.ms-powerpoint'], $ole),
            'pot'  => array_merge(['application/vnd.ms-powerpoint'], $ole),
        ];

        // Unknown extension: extension whitelist already handled this elsewhere.
        if (!isset($allowed[$ext])) {
            return true;
        }

        return in_array($mime, $allowed[$ext], true);
    }
}

// ── Protect sensitive directories from direct web access ──────────────────────

if (!function_exists('portal_protect_sensitive_paths')) {
    function portal_protect_sensitive_paths(): void
    {
        // Apache 2.4 directive that denies all direct HTTP access to a folder.
        $deny = "Require all denied\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";

        foreach (['database', 'uploads'] as $folder) {
            $dir = __DIR__ . DIRECTORY_SEPARATOR . $folder;
            if (!is_dir($dir)) {
                continue;
            }
            $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
            if (!is_file($htaccess)) {
                @file_put_contents($htaccess, $deny);
            }
        }
    }
}

// ── Auto-initialise database on first run ─────────────────────────────────────
if (!file_exists(__DIR__ . '/database/portal.db')) {
    require_once __DIR__ . '/db_init.php';
}

// ── Schema migrations (idempotent — safe to run on every request) ─────────────
if (!function_exists('portal_run_migrations')) {
    function portal_run_migrations(): void
    {
        $db = portal_db();

        // ── Add teacher role to users table if not already present ────────────
        $tableSQL = (string) ($db->query(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='users'"
        )->fetchColumn() ?: '');
        if ($tableSQL !== '' && strpos($tableSQL, "'teacher'") === false) {
            $db->exec('PRAGMA foreign_keys = OFF');
            $db->exec("
                CREATE TABLE IF NOT EXISTS _users_new (
                    id            INTEGER PRIMARY KEY AUTOINCREMENT,
                    username      TEXT    NOT NULL UNIQUE COLLATE NOCASE,
                    email         TEXT    NOT NULL UNIQUE COLLATE NOCASE,
                    password_hash TEXT    NOT NULL,
                    name          TEXT    NOT NULL,
                    year          TEXT    NOT NULL DEFAULT 'Year 11',
                    programme     TEXT    NOT NULL DEFAULT 'General',
                    initials      TEXT    NOT NULL DEFAULT 'ST',
                    role          TEXT    NOT NULL DEFAULT 'student'
                                          CHECK(role IN ('owner','admin','teacher','student')),
                    created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
                )
            ");
            $db->exec("INSERT INTO _users_new SELECT * FROM users");
            $db->exec("DROP TABLE users");
            $db->exec("ALTER TABLE _users_new RENAME TO users");
            $db->exec('PRAGMA foreign_keys = ON');
        }

        // ── Course teachers (junction: teacher accounts → courses) ─────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS course_teachers (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
                user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                assigned_at TEXT    NOT NULL DEFAULT (datetime('now')),
                UNIQUE(course_id, user_id)
            )
        ");

        // ── Course announcements (writable by assigned teachers and admins) ────
        $db->exec("
            CREATE TABLE IF NOT EXISTS course_announcements (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
                user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                title       TEXT    NOT NULL,
                body        TEXT    NOT NULL DEFAULT '',
                created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");

        // ── Course folders and items ───────────────────────────────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS course_folders (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
                title       TEXT    NOT NULL,
                description TEXT    NOT NULL DEFAULT '',
                locked      INTEGER NOT NULL DEFAULT 0,
                sort_order  INTEGER NOT NULL DEFAULT 0,
                created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $folderCols = array_column($db->query("PRAGMA table_info(course_folders)")->fetchAll(), 'name');
        if (!in_array('locked', $folderCols, true)) {
            $db->exec("ALTER TABLE course_folders ADD COLUMN locked INTEGER NOT NULL DEFAULT 0");
        }
        $db->exec("
            CREATE TABLE IF NOT EXISTS course_folder_items (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                folder_id   INTEGER NOT NULL REFERENCES course_folders(id) ON DELETE CASCADE,
                course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
                type        TEXT    NOT NULL DEFAULT 'document'
                                    CHECK(type IN ('document','link','submission')),
                title       TEXT    NOT NULL,
                description TEXT    NOT NULL DEFAULT '',
                url         TEXT    NOT NULL DEFAULT '',
                file_path   TEXT    NOT NULL DEFAULT '',
                file_name   TEXT    NOT NULL DEFAULT '',
                sort_order  INTEGER NOT NULL DEFAULT 0,
                created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");

        // ── Add file_path / file_name to existing course_folder_items if absent ──
        $cols = array_column($db->query("PRAGMA table_info(course_folder_items)")->fetchAll(), 'name');
        if (!in_array('file_path', $cols, true)) {
            $db->exec("ALTER TABLE course_folder_items ADD COLUMN file_path TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('file_name', $cols, true)) {
            $db->exec("ALTER TABLE course_folder_items ADD COLUMN file_name TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('submission_deadline', $cols, true)) {
            $db->exec("ALTER TABLE course_folder_items ADD COLUMN submission_deadline TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('submission_ai_detection', $cols, true)) {
            $db->exec("ALTER TABLE course_folder_items ADD COLUMN submission_ai_detection INTEGER NOT NULL DEFAULT 0");
        }

        // ── Class schedule ────────────────────────────────────────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS course_schedule (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
                day_of_week TEXT    NOT NULL,
                start_time  TEXT    NOT NULL DEFAULT '',
                end_time    TEXT    NOT NULL DEFAULT '',
                room        TEXT    NOT NULL DEFAULT '',
                notes       TEXT    NOT NULL DEFAULT '',
                sort_order  INTEGER NOT NULL DEFAULT 0
            )
        ");

        // ── Discussion forum ──────────────────────────────────────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS course_discussion_topics (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
                user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                title       TEXT    NOT NULL,
                body        TEXT    NOT NULL DEFAULT '',
                created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS course_discussion_replies (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                topic_id    INTEGER NOT NULL REFERENCES course_discussion_topics(id) ON DELETE CASCADE,
                course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
                user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                body        TEXT    NOT NULL,
                created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");

        // ── Groups ────────────────────────────────────────────────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS course_groups (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
                title       TEXT    NOT NULL,
                description TEXT    NOT NULL DEFAULT '',
                max_members INTEGER NOT NULL DEFAULT 0,
                created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS course_group_members (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                group_id  INTEGER NOT NULL REFERENCES course_groups(id) ON DELETE CASCADE,
                user_id   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                joined_at TEXT    NOT NULL DEFAULT (datetime('now')),
                UNIQUE(group_id, user_id)
            )
        ");

        // ── Submission review annotations (Turnitin-style comments) ────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS course_submission_annotations (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                submission_id INTEGER NOT NULL REFERENCES course_submissions(id) ON DELETE CASCADE,
                course_id     INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
                author_id     INTEGER REFERENCES users(id) ON DELETE SET NULL,
                anchor_type   TEXT NOT NULL DEFAULT 'text',
                range_start   INTEGER,
                range_end     INTEGER,
                quote         TEXT NOT NULL DEFAULT '',
                pos_x         REAL,
                pos_y         REAL,
                comment       TEXT NOT NULL DEFAULT '',
                created_at    TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_submission_annotations ON course_submission_annotations(submission_id)");

        // ── Login attempt log (brute-force throttling, per IP) ─────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS login_attempts (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                ip           TEXT    NOT NULL,
                attempted_at INTEGER NOT NULL
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts(ip, attempted_at)");

        // ── Announcement read tracking ─────────────────────────────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS announcement_reads (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                announcement_id INTEGER NOT NULL REFERENCES course_announcements(id) ON DELETE CASCADE,
                read_at         TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE(user_id, announcement_id)
            )
        ");

        // ── Course tab visibility settings ────────────────────────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS course_tab_settings (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
                tab_key     TEXT    NOT NULL,
                enabled     INTEGER NOT NULL DEFAULT 1,
                UNIQUE(course_id, tab_key)
            )
        ");

        // ── Student submission files ───────────────────────────────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS course_submissions (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                item_id      INTEGER NOT NULL REFERENCES course_folder_items(id) ON DELETE CASCADE,
                course_id    INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
                user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                filename     TEXT    NOT NULL,
                filepath     TEXT    NOT NULL,
                filesize     INTEGER NOT NULL DEFAULT 0,
                submitted_at TEXT    NOT NULL DEFAULT (datetime('now')),
                score        INTEGER,
                feedback     TEXT    NOT NULL DEFAULT '',
                marked_at    TEXT    NOT NULL DEFAULT '',
                marked_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
                ai_status    TEXT    NOT NULL DEFAULT '',
                ai_score     REAL,
                ai_report    TEXT    NOT NULL DEFAULT '',
                ai_checked_at TEXT   NOT NULL DEFAULT '',
                receipt_number TEXT NOT NULL DEFAULT '',
                file_sha256  TEXT NOT NULL DEFAULT '',
                submission_text TEXT NOT NULL DEFAULT '',
                text_word_count INTEGER NOT NULL DEFAULT 0,
                similarity_status TEXT NOT NULL DEFAULT '',
                similarity_score REAL,
                similarity_report TEXT NOT NULL DEFAULT '',
                similarity_checked_at TEXT NOT NULL DEFAULT '',
                process_edit_seconds INTEGER NOT NULL DEFAULT 0,
                process_paste_events INTEGER NOT NULL DEFAULT 0,
                process_pasted_chars INTEGER NOT NULL DEFAULT 0,
                eula_accepted_at TEXT NOT NULL DEFAULT '',
                UNIQUE(item_id, user_id)
            )
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS course_submission_versions (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                submission_id INTEGER REFERENCES course_submissions(id) ON DELETE CASCADE,
                item_id      INTEGER NOT NULL REFERENCES course_folder_items(id) ON DELETE CASCADE,
                course_id    INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
                user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                filename     TEXT NOT NULL DEFAULT '',
                filesize     INTEGER NOT NULL DEFAULT 0,
                file_sha256  TEXT NOT NULL DEFAULT '',
                text_word_count INTEGER NOT NULL DEFAULT 0,
                receipt_number TEXT NOT NULL DEFAULT '',
                similarity_status TEXT NOT NULL DEFAULT '',
                similarity_score REAL,
                process_edit_seconds INTEGER NOT NULL DEFAULT 0,
                process_paste_events INTEGER NOT NULL DEFAULT 0,
                process_pasted_chars INTEGER NOT NULL DEFAULT 0,
                submitted_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS integrity_eula_acceptances (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                version     TEXT NOT NULL,
                accepted_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE(user_id, version)
            )
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS integrity_sentence_index (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                sentence_hash    TEXT    NOT NULL,
                sentence_preview TEXT    NOT NULL DEFAULT '',
                source_type      TEXT    NOT NULL,
                source_id        INTEGER NOT NULL,
                source_label     TEXT    NOT NULL DEFAULT '',
                course_id        INTEGER,
                indexed_at       TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_integrity_sentence_hash ON integrity_sentence_index(sentence_hash)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_integrity_sentence_source ON integrity_sentence_index(source_type, source_id)');
        $submissionCols = array_column($db->query("PRAGMA table_info(course_submissions)")->fetchAll(), 'name');
        $submissionAdds = [
            'score'         => "ALTER TABLE course_submissions ADD COLUMN score INTEGER",
            'feedback'      => "ALTER TABLE course_submissions ADD COLUMN feedback TEXT NOT NULL DEFAULT ''",
            'marked_at'     => "ALTER TABLE course_submissions ADD COLUMN marked_at TEXT NOT NULL DEFAULT ''",
            'marked_by'     => "ALTER TABLE course_submissions ADD COLUMN marked_by INTEGER REFERENCES users(id) ON DELETE SET NULL",
            'ai_status'     => "ALTER TABLE course_submissions ADD COLUMN ai_status TEXT NOT NULL DEFAULT ''",
            'ai_score'      => "ALTER TABLE course_submissions ADD COLUMN ai_score REAL",
            'ai_report'     => "ALTER TABLE course_submissions ADD COLUMN ai_report TEXT NOT NULL DEFAULT ''",
            'ai_checked_at' => "ALTER TABLE course_submissions ADD COLUMN ai_checked_at TEXT NOT NULL DEFAULT ''",
            'receipt_number' => "ALTER TABLE course_submissions ADD COLUMN receipt_number TEXT NOT NULL DEFAULT ''",
            'file_sha256' => "ALTER TABLE course_submissions ADD COLUMN file_sha256 TEXT NOT NULL DEFAULT ''",
            'submission_text' => "ALTER TABLE course_submissions ADD COLUMN submission_text TEXT NOT NULL DEFAULT ''",
            'text_word_count' => "ALTER TABLE course_submissions ADD COLUMN text_word_count INTEGER NOT NULL DEFAULT 0",
            'similarity_status' => "ALTER TABLE course_submissions ADD COLUMN similarity_status TEXT NOT NULL DEFAULT ''",
            'similarity_score' => "ALTER TABLE course_submissions ADD COLUMN similarity_score REAL",
            'similarity_report' => "ALTER TABLE course_submissions ADD COLUMN similarity_report TEXT NOT NULL DEFAULT ''",
            'similarity_checked_at' => "ALTER TABLE course_submissions ADD COLUMN similarity_checked_at TEXT NOT NULL DEFAULT ''",
            'process_edit_seconds' => "ALTER TABLE course_submissions ADD COLUMN process_edit_seconds INTEGER NOT NULL DEFAULT 0",
            'process_paste_events' => "ALTER TABLE course_submissions ADD COLUMN process_paste_events INTEGER NOT NULL DEFAULT 0",
            'process_pasted_chars' => "ALTER TABLE course_submissions ADD COLUMN process_pasted_chars INTEGER NOT NULL DEFAULT 0",
            'eula_accepted_at' => "ALTER TABLE course_submissions ADD COLUMN eula_accepted_at TEXT NOT NULL DEFAULT ''",
        ];
        foreach ($submissionAdds as $col => $sql) {
            if (!in_array($col, $submissionCols, true)) {
                $db->exec($sql);
            }
        }
    }
}

portal_run_migrations();
portal_protect_sensitive_paths();

// ── Teacher / course-manager permission helpers ───────────────────────────────

if (!function_exists('portal_is_teacher')) {
    function portal_is_teacher(): bool
    {
        return portal_current_user_role() === 'teacher';
    }
}

if (!function_exists('portal_assigned_course_ids')) {
    function portal_assigned_course_ids(): array
    {
        if (!portal_is_logged_in()) {
            return [];
        }
        $user = portal_current_user();
        $stmt = portal_db()->prepare(
            "SELECT course_id FROM course_teachers WHERE user_id = ?"
        );
        $stmt->execute([(int) $user['id']]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}

if (!function_exists('portal_uploads_base')) {
    function portal_uploads_base(): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
    }
}

if (!function_exists('portal_enabled_tab_keys')) {
    function portal_enabled_tab_keys(int $courseId): ?array
    {
        $db      = portal_db();
        $cntStmt = $db->prepare("SELECT COUNT(*) FROM course_tab_settings WHERE course_id = ?");
        $cntStmt->execute([$courseId]);
        if ((int) $cntStmt->fetchColumn() === 0) {
            return null; // no settings saved → show all tabs
        }
        $stmt = $db->prepare("SELECT tab_key FROM course_tab_settings WHERE course_id = ? AND enabled = 1");
        $stmt->execute([$courseId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

if (!function_exists('portal_can_manage_course')) {
    function portal_can_manage_course(int $courseId): bool
    {
        if (portal_is_admin()) {
            return true;
        }
        if (!portal_is_teacher()) {
            return false;
        }
        return in_array($courseId, portal_assigned_course_ids(), true);
    }
}

require_once __DIR__ . '/integrity.php';
