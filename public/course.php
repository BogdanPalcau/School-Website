<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../course_catalog.php';

// Stop Chrome from reusing a stale HTML/JS bundle for this page.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}

portal_require_login();

$slug = (string) ($_GET['course'] ?? '');
$course = portal_find_course($slug);

if ($course === null) {
    portal_redirect('courses.php');
}

// ── Access control: only enrolled students or course managers may enter ───────
// This guard runs before any GET rendering or POST action handling, so a direct
// URL to a course the user is not part of is rejected for every request method.
if (!portal_can_access_course((int) $course['id'])) {
    portal_log_security_event(
        'unauthorised_course_access',
        'medium',
        'Blocked access to course: ' . substr((string) $course['slug'], 0, 80)
    );
    $_SESSION['course_flash'] = ['error', 'You do not have access to that course.'];
    portal_redirect('courses.php');
}

$courseId = (int) $course['id'];
$showExternalAiSlotOption = portal_show_submission_external_ai_option($courseId);
$externalAiSiteWide = portal_external_ai_configured() && portal_external_ai_policy() === 'site_wide';

// ── CSRF token ────────────────────────────────────────────────────────────────
if (empty($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['_csrf'];

$courseFlash = $_SESSION['course_flash'] ?? null;
unset($_SESSION['course_flash']);

// Safe migration: add allow_download if not yet present
try {
    portal_db()->exec("ALTER TABLE course_folder_items ADD COLUMN allow_download TINYINT(1) NOT NULL DEFAULT 0");
} catch (\PDOException $e) {}
try {
    portal_db()->exec("CREATE TABLE IF NOT EXISTS announcement_reads (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        announcement_id INTEGER NOT NULL REFERENCES course_announcements(id) ON DELETE CASCADE,
        read_at         TEXT NOT NULL DEFAULT (datetime('now')),
        UNIQUE(user_id, announcement_id)
    )");
} catch (\PDOException $e) {}
try {
    portal_db()->exec("ALTER TABLE course_folders ADD COLUMN locked INTEGER NOT NULL DEFAULT 0");
} catch (\PDOException $e) {}
try {
    portal_db()->exec("ALTER TABLE course_folder_items ADD COLUMN locked INTEGER NOT NULL DEFAULT 0");
} catch (\PDOException $e) {}
foreach ([
    "ALTER TABLE course_folder_items ADD COLUMN submission_deadline TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE course_folder_items ADD COLUMN submission_ai_detection INTEGER NOT NULL DEFAULT 0",
    "ALTER TABLE course_folder_items ADD COLUMN submission_max_attempts INTEGER NOT NULL DEFAULT 0",
    "ALTER TABLE course_folder_items ADD COLUMN submission_weight REAL NOT NULL DEFAULT 100",
] as $sql) {
    try { portal_db()->exec($sql); } catch (\PDOException $e) {}
}
foreach ([
    "ALTER TABLE course_submissions ADD COLUMN score INTEGER",
    "ALTER TABLE course_submissions ADD COLUMN feedback TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE course_submissions ADD COLUMN marked_at TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE course_submissions ADD COLUMN marked_by INTEGER REFERENCES users(id) ON DELETE SET NULL",
    "ALTER TABLE course_submissions ADD COLUMN ai_status TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE course_submissions ADD COLUMN ai_score REAL",
    "ALTER TABLE course_submissions ADD COLUMN ai_report TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE course_submissions ADD COLUMN ai_checked_at TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE course_submissions ADD COLUMN receipt_number TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE course_submissions ADD COLUMN file_sha256 TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE course_submissions ADD COLUMN submission_text TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE course_submissions ADD COLUMN text_word_count INTEGER NOT NULL DEFAULT 0",
    "ALTER TABLE course_submissions ADD COLUMN similarity_status TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE course_submissions ADD COLUMN similarity_score REAL",
    "ALTER TABLE course_submissions ADD COLUMN similarity_report TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE course_submissions ADD COLUMN similarity_checked_at TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE course_submissions ADD COLUMN process_edit_seconds INTEGER NOT NULL DEFAULT 0",
    "ALTER TABLE course_submissions ADD COLUMN process_paste_events INTEGER NOT NULL DEFAULT 0",
    "ALTER TABLE course_submissions ADD COLUMN process_pasted_chars INTEGER NOT NULL DEFAULT 0",
    "ALTER TABLE course_submissions ADD COLUMN eula_accepted_at TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE course_submissions ADD COLUMN grade_seen_at TEXT NOT NULL DEFAULT ''",
] as $sql) {
    try { portal_db()->exec($sql); } catch (\PDOException $e) {}
}
try {
    portal_db()->exec("
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
} catch (\PDOException $e) {}
try {
    portal_db()->exec("
        CREATE TABLE IF NOT EXISTS integrity_eula_acceptances (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            version     TEXT NOT NULL,
            accepted_at TEXT NOT NULL DEFAULT (datetime('now')),
            UNIQUE(user_id, version)
        )
    ");
} catch (\PDOException $e) {}
try {
    portal_db()->exec("
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
    portal_db()->exec("CREATE INDEX IF NOT EXISTS idx_submission_annotations ON course_submission_annotations(submission_id)");
} catch (\PDOException $e) {}

function portal_course_normalize_external_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('/^https?:\/\//i', $url)
        && preg_match('/^(?:www\.)?[a-z0-9][a-z0-9-]*(?:\.[a-z0-9][a-z0-9-]*)+(?:[\/?#].*)?$/i', $url)) {
        $url = 'https://' . $url;
    }
    if (!preg_match('/^https?:\/\//i', $url)) {
        return '';
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }
    return $url;
}

function portal_google_safe_browsing_api_key(): string
{
    $fromDb = function_exists('portal_site_setting_get') ? portal_site_setting_get('google_safe_browsing_api_key', '') : '';
    if ($fromDb !== '') {
        return $fromDb;
    }
    return trim((string) getenv('GOOGLE_SAFE_BROWSING_API_KEY'));
}

function portal_course_google_safe_browsing_url_check(string $url): array
{
    $url = portal_course_normalize_external_url($url);
    if ($url === '') {
        return ['status' => 'invalid', 'configured' => false, 'message' => 'This is not a valid external URL.'];
    }

    $apiKey = portal_google_safe_browsing_api_key();
    if ($apiKey === '') {
        return ['status' => 'unchecked', 'configured' => false, 'message' => 'Google Safe Browsing is not configured, so this link could not be verified automatically.'];
    }
    if (!function_exists('curl_init')) {
        return ['status' => 'unchecked', 'configured' => true, 'message' => 'Google Safe Browsing could not be contacted because cURL is unavailable on this server.'];
    }

    $ch = curl_init('https://safebrowsing.googleapis.com/v4/threatMatches:find?key=' . rawurlencode($apiKey));
    $payload = json_encode([
        'client' => [
            'clientId' => 'schoolwebsite',
            'clientVersion' => '1.0',
        ],
        'threatInfo' => [
            'threatTypes' => [
                'MALWARE',
                'SOCIAL_ENGINEERING',
                'UNWANTED_SOFTWARE',
                'POTENTIALLY_HARMFUL_APPLICATION',
            ],
            'platformTypes' => ['ANY_PLATFORM'],
            'threatEntryTypes' => ['URL'],
            'threatEntries' => [
                ['url' => $url],
            ],
        ],
    ], JSON_UNESCAPED_SLASHES);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw = (string) curl_exec($ch);
    $err = curl_error($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err !== '' || $http < 200 || $http >= 300) {
        return [
            'status' => 'unchecked',
            'configured' => true,
            'message' => 'Google Safe Browsing could not be reached. Treat this external link with caution.',
            'http' => $http,
        ];
    }

    $json = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($json)) {
        return [
            'status' => 'unchecked',
            'configured' => true,
            'message' => 'Google Safe Browsing returned an unreadable response. Treat this external link with caution.',
            'http' => $http,
        ];
    }

    $matches = is_array($json['matches'] ?? null) ? $json['matches'] : [];
    if (empty($matches)) {
        return [
            'status' => 'safe',
            'configured' => true,
            'message' => 'Google Safe Browsing did not report safety threats for this URL.',
            'threat_types' => [],
        ];
    }

    $threatTypes = array_values(array_unique(array_filter(array_map(
        static fn($match): string => is_array($match) ? (string) ($match['threatType'] ?? '') : '',
        $matches
    ))));
    $status = in_array('MALWARE', $threatTypes, true) ? 'malicious' : 'suspicious';
    $message = !empty($threatTypes)
        ? 'Google Safe Browsing reports a possible threat: ' . implode(', ', $threatTypes) . '.'
        : 'Google Safe Browsing reports a possible safety threat for this URL.';
    return [
        'status' => $status,
        'configured' => true,
        'message' => $message,
        'threat_types' => $threatTypes,
    ];
}

// Integrity helpers live in ../integrity.php (loaded via bootstrap.php).

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['_token'] ?? '');
    if (!hash_equals($csrfToken, $token)) {
        portal_redirect('course.php?course=' . urlencode($slug));
    }

    $action   = (string) ($_POST['action'] ?? '');
    $courseId = (int) $course['id'];
    $db       = portal_db();
    $me       = portal_current_user();
    $maxUploadBytes = 40 * 1024 * 1024;
    $maxVideoUploadBytes = 400 * 1024 * 1024;

    $uploadErrorMessage = static function (int $code): string {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large for the server/upload form limit.',
            UPLOAD_ERR_PARTIAL => 'Upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server upload temp directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded file.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension blocked the upload.',
            default => 'Upload failed due to an unknown error.',
        };
    };

    if ($action === 'check_external_link') {
        $url = portal_course_normalize_external_url((string) ($_POST['url'] ?? ''));
        if ($url === '') {
            portal_json_response(['ok' => false, 'error' => 'Invalid external link.'], 400);
        }
        $verdict = portal_course_google_safe_browsing_url_check($url);
        portal_json_response([
            'ok' => true,
            'url' => $url,
            'host' => (string) (parse_url($url, PHP_URL_HOST) ?: $url),
            'verdict' => $verdict,
        ]);
    }

    // ── AJAX: reorder folders (JSON, exits immediately) ──────────────────────
    if ($action === 'reorder_folders' && portal_can_manage_course($courseId)) {
        $order = json_decode((string) ($_POST['order'] ?? '[]'), true);
        if (is_array($order)) {
            $upd = $db->prepare(
                "UPDATE course_folders SET sort_order = ? WHERE id = ? AND course_id = ?"
            );
            foreach ($order as $i => $fid) {
                $upd->execute([$i * 10, (int) $fid, $courseId]);
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── AJAX: reorder items within a folder ──────────────────────────────────
    if ($action === 'reorder_items' && portal_can_manage_course($courseId)) {
        $folderId = (int) ($_POST['folder_id'] ?? 0);
        $order    = json_decode((string) ($_POST['order'] ?? '[]'), true);
        if (is_array($order) && $folderId > 0) {
            $upd = $db->prepare(
                "UPDATE course_folder_items SET sort_order = ? WHERE id = ? AND folder_id = ? AND course_id = ?"
            );
            foreach ($order as $i => $iid) {
                $upd->execute([$i * 10, (int) $iid, $folderId, $courseId]);
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── AJAX: toggle folder lock state ───────────────────────────────────────
    if ($action === 'toggle_folder_lock' && portal_can_manage_course($courseId)) {
        $folderId = (int) ($_POST['folder_id'] ?? 0);
        if ($folderId > 0) {
            $row = $db->prepare("SELECT locked FROM course_folders WHERE id = ? AND course_id = ?");
            $row->execute([$folderId, $courseId]);
            $current = $row->fetch();
            if ($current) {
                $newLocked = $current['locked'] ? 0 : 1;
                $db->prepare("UPDATE course_folders SET locked = ? WHERE id = ? AND course_id = ?")
                   ->execute([$newLocked, $folderId, $courseId]);
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'locked' => $newLocked]);
                exit;
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => false]);
        exit;
    }

    // ── AJAX: toggle item lock state ─────────────────────────────────────────
    if ($action === 'toggle_item_lock' && portal_can_manage_course($courseId)) {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        if ($itemId > 0) {
            $row = $db->prepare("SELECT locked FROM course_folder_items WHERE id = ? AND course_id = ?");
            $row->execute([$itemId, $courseId]);
            $current = $row->fetch();
            if ($current) {
                $newLocked = $current['locked'] ? 0 : 1;
                $db->prepare("UPDATE course_folder_items SET locked = ? WHERE id = ? AND course_id = ?")
                   ->execute([$newLocked, $itemId, $courseId]);
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'locked' => $newLocked]);
                exit;
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => false]);
        exit;
    }

    // ── AJAX: move item to another folder ────────────────────────────────────
    if ($action === 'move_item' && portal_can_manage_course($courseId)) {
        $itemId     = (int) ($_POST['item_id'] ?? 0);
        $toFolderId = (int) ($_POST['folder_id'] ?? 0);
        $itemChk    = $db->prepare("SELECT id FROM course_folder_items WHERE id = ? AND course_id = ?");
        $itemChk->execute([$itemId, $courseId]);
        $folderChk  = $db->prepare("SELECT id FROM course_folders WHERE id = ? AND course_id = ?");
        $folderChk->execute([$toFolderId, $courseId]);
        if ($itemChk->fetch() && $folderChk->fetch()) {
            $maxOrd = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM course_folder_items WHERE folder_id = ?");
            $maxOrd->execute([$toFolderId]);
            $newOrder = (int) $maxOrd->fetchColumn() + 10;
            $db->prepare("UPDATE course_folder_items SET folder_id = ?, sort_order = ? WHERE id = ? AND course_id = ?")
               ->execute([$toFolderId, $newOrder, $itemId, $courseId]);
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── AJAX: mark announcements as read ─────────────────────────────────────
    if ($action === 'mark_announcements_read') {
        $ids = array_filter(array_map('intval', (array)($_POST['announcement_ids'] ?? [])));
        if (!empty($ids)) {
            $ins  = $db->prepare("INSERT OR IGNORE INTO announcement_reads (user_id, announcement_id) VALUES (?,?)");
            $chk  = $db->prepare("SELECT id FROM course_announcements WHERE id = ? AND course_id = ?");
            foreach ($ids as $aid) {
                $chk->execute([$aid, $courseId]);
                if ($chk->fetch()) {
                    $ins->execute([(int) $me['id'], $aid]);
                }
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Admin-only: assign / remove / update course staff ─────────────────────
    if (portal_is_admin()) {
        if ($action === 'assign_teacher') {
            $uid = (int) ($_POST['user_id'] ?? 0);
            $assignmentRole = (string) ($_POST['assignment_role'] ?? 'teacher');
            if (!portal_valid_assignment_role($assignmentRole)) {
                $assignmentRole = 'teacher';
            }
            $chk = $db->prepare(
                "SELECT id FROM users WHERE id = ? AND role IN ('owner', 'admin', 'teacher')"
            );
            $chk->execute([$uid]);
            if ($chk->fetch()) {
                $db->prepare("
                    INSERT INTO course_teachers (course_id, user_id, assignment_role)
                    VALUES (?,?,?)
                    ON CONFLICT(course_id, user_id) DO UPDATE SET assignment_role = excluded.assignment_role
                ")->execute([$courseId, $uid, $assignmentRole]);
            }
        } elseif ($action === 'change_assignment_role') {
            $uid = (int) ($_POST['user_id'] ?? 0);
            $assignmentRole = (string) ($_POST['assignment_role'] ?? 'teacher');
            if (!portal_valid_assignment_role($assignmentRole)) {
                $assignmentRole = 'teacher';
            }
            $db->prepare("
                UPDATE course_teachers SET assignment_role = ?
                WHERE course_id = ? AND user_id = ?
            ")->execute([$assignmentRole, $courseId, $uid]);
        } elseif ($action === 'remove_teacher') {
            $uid = (int) ($_POST['user_id'] ?? 0);
            $db->prepare('DELETE FROM course_teachers WHERE course_id = ? AND user_id = ?')
               ->execute([$courseId, $uid]);
        }
    }

    // ── Course managers (admin OR assigned teacher) ───────────────────────────
    if (portal_can_manage_course($courseId)) {

        if ($action === 'create_folder') {
            $title = substr(trim((string) ($_POST['title'] ?? '')), 0, 200);
            $desc  = substr(trim((string) ($_POST['description'] ?? '')), 0, 500);
            $locked = isset($_POST['locked']) && $_POST['locked'] === '1' ? 1 : 0;
            if ($title !== '') {
                $db->prepare("INSERT INTO course_folders (course_id, title, description, locked) VALUES (?,?,?,?)")
                   ->execute([$courseId, $title, $desc, $locked]);
            }

        } elseif ($action === 'update_folder_settings') {
            $folderId = (int) ($_POST['folder_id'] ?? 0);
            $title = substr(trim((string) ($_POST['title'] ?? '')), 0, 200);
            $desc = substr(trim((string) ($_POST['description'] ?? '')), 0, 500);
            $locked = isset($_POST['locked']) && $_POST['locked'] === '1' ? 1 : 0;
            if ($folderId > 0 && $title !== '') {
                $db->prepare("UPDATE course_folders SET title = ?, description = ?, locked = ? WHERE id = ? AND course_id = ?")
                   ->execute([$title, $desc, $locked, $folderId, $courseId]);
                $_SESSION['course_flash'] = ['success', 'Folder settings saved.'];
            }

        } elseif ($action === 'delete_folder') {
            $folderId = (int) ($_POST['folder_id'] ?? 0);
            $folderItemIds = [];
            if ($folderId > 0) {
                $idStmt = $db->prepare(
                    "SELECT id FROM course_folder_items WHERE folder_id = ? AND course_id = ?"
                );
                $idStmt->execute([$folderId, $courseId]);
                $folderItemIds = array_map('intval', $idStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
            }
            // Delete any uploaded files in this folder
            $fiStmt = $db->prepare(
                "SELECT file_path FROM course_folder_items WHERE folder_id = ? AND course_id = ? AND file_path != ''"
            );
            $fiStmt->execute([$folderId, $courseId]);
            foreach ($fiStmt->fetchAll(PDO::FETCH_COLUMN) as $_fp) {
                $abs = portal_uploads_base() . DIRECTORY_SEPARATOR . $_fp;
                if (is_file($abs)) @unlink($abs);
            }
            // Delete any submission files in this folder's slots
            $ssStmt = $db->prepare(
                "SELECT cs.filepath FROM course_submissions cs
                 JOIN course_folder_items cfi ON cfi.id = cs.item_id
                 WHERE cfi.folder_id = ? AND cfi.course_id = ? AND cs.course_id = ?"
            );
            $ssStmt->execute([$folderId, $courseId, $courseId]);
            foreach ($ssStmt->fetchAll(PDO::FETCH_COLUMN) as $_sp) {
                $abs = portal_uploads_base() . DIRECTORY_SEPARATOR . $_sp;
                if (is_file($abs)) @unlink($abs);
            }
            $db->prepare("DELETE FROM course_folders WHERE id = ? AND course_id = ?")
               ->execute([$folderId, $courseId]);
            foreach ($folderItemIds as $deletedItemId) {
                if ($deletedItemId > 0) {
                    portal_notifications_unsend('lesson-viewer.php?item=' . $deletedItemId, true, 'lesson_answer');
                }
            }

        } elseif ($action === 'create_item') {
            $folderId = (int) ($_POST['folder_id'] ?? 0);
            $type     = (string) ($_POST['type'] ?? 'document');
            if (!in_array($type, ['document', 'link', 'submission', 'video', 'activity'], true)) {
                $type = 'document';
            }
            $title    = substr(trim((string) ($_POST['title'] ?? '')), 0, 200);
            $desc     = substr(trim((string) ($_POST['description'] ?? '')), 0, 500);
            $url      = portal_course_normalize_external_url(substr(trim((string) ($_POST['url'] ?? '')), 0, 2000));
            $submissionDeadline = '';
            $submissionAiDetection = 0;
            $submissionMaxAttempts = 0;
            $submissionWeight = 100.0;
            $filePath = '';
            $fileName = '';
            $createItemError = null;

            if ($type === 'activity') {
                $chk = $db->prepare("SELECT id FROM course_folders WHERE id = ? AND course_id = ?");
                $chk->execute([$folderId, $courseId]);
                if (!$chk->fetch() || $title === '') {
                    $_SESSION['course_flash'] = ['error', 'Could not add activity. Check the folder and title, then try again.'];
                } else {
                    $mode = (string) ($_POST['activity_mode'] ?? 'quiz');
                    if (!in_array($mode, portal_activity_modes(), true)) {
                        $mode = 'quiz';
                    }
                    $created = portal_activity_create($courseId, $folderId, $title, $mode, (int) ($me['id'] ?? 0));
                    if (!empty($created['ok']) && !empty($created['activity_id'])) {
                        portal_redirect('activity-builder.php?id=' . (int) $created['activity_id']);
                    }
                    $_SESSION['course_flash'] = ['error', (string) ($created['error'] ?? 'Could not create activity.')];
                }
            } else {
            // Handle file upload for document/video types. A video item may instead point
            // at an external link (YouTube/Vimeo) — only enter the upload branch when a
            // file was actually attempted, so a video-link-only submission falls through
            // to the external-video validation below instead of failing with "no file".
            $videoFileAttempted = $type === 'video'
                && isset($_FILES['file'])
                && (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            $documentFileAttempted = $type === 'document' && isset($_FILES['file']);

            if ($documentFileAttempted || $videoFileAttempted) {
                $isVideoUpload = $type === 'video';
                $fileError = (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE);
                $fileSize = (int) ($_FILES['file']['size'] ?? 0);
                $ext = strtolower(pathinfo((string) ($_FILES['file']['name'] ?? ''), PATHINFO_EXTENSION));
                $allowedExts = $isVideoUpload ? portal_video_extensions() : portal_supported_upload_extensions();
                $sizeLimit = $isVideoUpload ? $maxVideoUploadBytes : $maxUploadBytes;
                $sizeLimitLabel = $isVideoUpload ? '400 MB' : '40 MB';

                if ($fileError !== UPLOAD_ERR_OK) {
                    $createItemError = $uploadErrorMessage($fileError);
                } elseif (!in_array($ext, $allowedExts, true)) {
                    $createItemError = $isVideoUpload
                        ? 'Unsupported video format. Use ' . portal_supported_video_upload_hint() . '.'
                        : 'Unsupported file type. Use ' . portal_supported_upload_hint() . '.';
                } elseif ($fileSize <= 0) {
                    $createItemError = 'Uploaded file is empty (0 bytes). Please export/download it again and re-upload.';
                } elseif (!portal_upload_mime_ok((string) ($_FILES['file']['tmp_name'] ?? ''), $ext)) {
                    $createItemError = $isVideoUpload
                        ? 'This file content does not match its extension. Please upload a genuine video file.'
                        : 'This file content does not match its extension. Please upload a genuine document.';
                } elseif ($fileSize > $sizeLimit) {
                    $createItemError = 'File is too large. Maximum allowed size is ' . $sizeLimitLabel . '.';
                } else {
                    $dir = portal_uploads_base() . DIRECTORY_SEPARATOR . 'courses' . DIRECTORY_SEPARATOR . $courseId;
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $safe = bin2hex(random_bytes(16)) . '.' . $ext;
                    if (move_uploaded_file($_FILES['file']['tmp_name'], $dir . DIRECTORY_SEPARATOR . $safe)) {
                        $filePath = 'courses' . DIRECTORY_SEPARATOR . $courseId . DIRECTORY_SEPARATOR . $safe;
                        $fileName = substr((string) $_FILES['file']['name'], 0, 255);
                        $url      = ''; // file takes precedence
                    } else {
                        $createItemError = 'Upload failed while saving the file. Please try again.';
                    }
                }
            } elseif ($type === 'video' && $url !== '') {
                $videoMeta = portal_parse_external_video_url($url);
                if ($videoMeta === null) {
                    $createItemError = 'That link is not a supported video source. Please use a ' . portal_supported_video_source_hint() . ', or upload a video file.';
                } else {
                    $url = $videoMeta['watch_url'];
                }
            } elseif ($type === 'link' && $url === '') {
                $createItemError = 'Please enter a valid link URL.';
            } elseif ($type === 'document' && $url === '') {
                $createItemError = 'Please upload a file or provide a URL for this document item.';
            } elseif ($type === 'video' && $filePath === '') {
                $createItemError = 'Please upload a video file or paste a ' . portal_supported_video_source_hint() . '.';
            } elseif ($type === 'submission') {
                $deadlineRaw = trim((string) ($_POST['submission_deadline'] ?? ''));
                if ($deadlineRaw === '') {
                    $submissionDeadline = '';
                } else {
                    $deadlineTs = strtotime($deadlineRaw);
                    if ($deadlineTs === false) {
                        $createItemError = 'Please enter a valid deadline, or leave it blank for no deadline.';
                    } elseif ($deadlineTs <= time()) {
                        $createItemError = 'Deadline must be in the future, or leave it blank for no deadline.';
                    } else {
                        $submissionDeadline = date('Y-m-d H:i:s', $deadlineTs);
                    }
                }
                if ($createItemError === null) {
                    $submissionAiDetection = isset($_POST['submission_ai_detection']) && $_POST['submission_ai_detection'] === '1' ? 1 : 0;
                    $submissionMaxAttempts = (int) ($_POST['submission_max_attempts'] ?? 0);
                    if (!in_array($submissionMaxAttempts, [0, 1, 2], true)) {
                        $submissionMaxAttempts = 0;
                    }
                    $submissionWeight = portal_normalize_submission_weight($_POST['submission_weight'] ?? 100);
                    $weightFit = portal_course_gradebook_weight_fits($courseId, $submissionWeight);
                    if (empty($weightFit['ok'])) {
                        $createItemError = (string) ($weightFit['error'] ?? 'Gradebook weights cannot exceed 100%.');
                    }
                    $url = '';
                }
            }

            $chk = $db->prepare("SELECT id FROM course_folders WHERE id = ? AND course_id = ?");
            $chk->execute([$folderId, $courseId]);
            if ($createItemError !== null) {
                if (
                    str_contains($createItemError, 'Unsupported file type')
                    || str_contains($createItemError, 'Unsupported video format')
                    || str_contains($createItemError, 'does not match')
                    || str_contains($createItemError, 'too large')
                    || str_contains($createItemError, 'blocked the upload')
                ) {
                    portal_log_blocked_upload($createItemError);
                }
                $_SESSION['course_flash'] = ['error', $createItemError];
            } elseif ($chk->fetch() && $title !== '') {
                $allowDl = (isset($_POST['allow_download']) && $_POST['allow_download'] === '1') ? 1 : 0;
                $db->prepare(
                    "INSERT INTO course_folder_items
                     (folder_id, course_id, type, title, description, url, file_path, file_name, allow_download, submission_deadline, submission_ai_detection, submission_max_attempts, submission_weight)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
                )->execute([$folderId, $courseId, $type, $title, $desc, $url, $filePath, $fileName, $allowDl, $submissionDeadline, $submissionAiDetection, $submissionMaxAttempts, $submissionWeight]);
                $_SESSION['course_flash'] = ['success', 'Item added successfully.'];
            } else {
                $_SESSION['course_flash'] = ['error', 'Could not add item. Check the folder and title, then try again.'];
            }
            } // end non-activity create_item

        } elseif ($action === 'update_item_settings') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $itemStmt = $db->prepare("SELECT * FROM course_folder_items WHERE id = ? AND course_id = ?");
            $itemStmt->execute([$itemId, $courseId]);
            $itemRow = $itemStmt->fetch();
            if ($itemRow) {
                $title = substr(trim((string) ($_POST['title'] ?? '')), 0, 200);
                $desc = substr(trim((string) ($_POST['description'] ?? '')), 0, 500);
                $url = portal_course_normalize_external_url(substr(trim((string) ($_POST['url'] ?? '')), 0, 2000));
                $allowDl = isset($_POST['allow_download']) && $_POST['allow_download'] === '1' ? 1 : 0;
                $deadline = (string) ($itemRow['submission_deadline'] ?? '');
                $aiDetection = (int) ($itemRow['submission_ai_detection'] ?? 0);
                $maxAttempts = (int) ($itemRow['submission_max_attempts'] ?? 0);
                $submissionWeight = portal_normalize_submission_weight($itemRow['submission_weight'] ?? 100);
                $error = null;

                if ($title === '') {
                    $error = 'Item title is required.';
                }

                if ($error === null && $itemRow['type'] === 'link') {
                    if ($url === '') {
                        $error = 'Please enter a valid link URL.';
                    }
                } elseif ($error === null && $itemRow['type'] === 'document' && $itemRow['file_path'] === '') {
                    if ($url === '') {
                        $error = 'Please enter a valid file URL.';
                    }
                } elseif ($error === null && $itemRow['type'] === 'video' && $itemRow['file_path'] === '') {
                    $videoMeta = $url !== '' ? portal_parse_external_video_url($url) : null;
                    if ($videoMeta === null) {
                        $error = 'Please use a ' . portal_supported_video_source_hint() . '.';
                    } else {
                        $url = $videoMeta['watch_url'];
                    }
                } elseif ($itemRow['type'] !== 'link') {
                    $url = (string) ($itemRow['url'] ?? '');
                }

                if ($error === null && $itemRow['type'] === 'submission') {
                    $deadlineRaw = trim((string) ($_POST['submission_deadline'] ?? ''));
                    $previousDeadline = trim((string) ($itemRow['submission_deadline'] ?? ''));
                    $previousTs = $previousDeadline !== '' ? strtotime($previousDeadline) : false;
                    if ($deadlineRaw === '') {
                        $deadline = '';
                    } else {
                        $deadlineTs = strtotime($deadlineRaw);
                        if ($deadlineTs === false) {
                            $error = 'Please enter a valid deadline, or leave it blank for no deadline.';
                        } elseif ($deadlineTs <= time()) {
                            // Teachers may close a slot by moving the deadline slightly into the
                            // past. Reject absurd historical dates (e.g. 2006) on new changes.
                            $oldestAllowedPast = time() - (7 * 24 * 3600);
                            $unchangedPast = $previousTs !== false && abs($deadlineTs - $previousTs) < 60;
                            if ($deadlineTs < $oldestAllowedPast && !$unchangedPast) {
                                $error = 'Deadline must be in the future, leave blank for none, or set a recent time (within 7 days) to close submissions.';
                            } else {
                                $deadline = date('Y-m-d H:i:s', $deadlineTs);
                            }
                        } else {
                            $deadline = date('Y-m-d H:i:s', $deadlineTs);
                        }
                    }
                    if ($error === null) {
                        $aiDetection = isset($_POST['submission_ai_detection']) && $_POST['submission_ai_detection'] === '1' ? 1 : 0;
                        $maxAttempts = (int) ($_POST['submission_max_attempts'] ?? 0);
                        if (!in_array($maxAttempts, [0, 1, 2], true)) {
                            $maxAttempts = 0;
                        }
                        $submissionWeight = portal_normalize_submission_weight($_POST['submission_weight'] ?? 100);
                        $weightFit = portal_course_gradebook_weight_fits(
                            $courseId,
                            $submissionWeight,
                            ['exclude_item_id' => $itemId]
                        );
                        if (empty($weightFit['ok'])) {
                            $error = (string) ($weightFit['error'] ?? 'Gradebook weights cannot exceed 100%.');
                        }
                    }
                }

                if ($error !== null) {
                    $_SESSION['course_flash'] = ['error', $error];
                } else {
                    $db->prepare(
                        "UPDATE course_folder_items
                         SET title = ?, description = ?, url = ?, allow_download = ?,
                             submission_deadline = ?, submission_ai_detection = ?, submission_max_attempts = ?, submission_weight = ?
                         WHERE id = ? AND course_id = ?"
                    )->execute([$title, $desc, $url, $allowDl, $deadline, $aiDetection, $maxAttempts, $submissionWeight, $itemId, $courseId]);
                    $_SESSION['course_flash'] = ['success', 'Item settings saved.'];
                }
            }

        } elseif ($action === 'toggle_download' && portal_can_manage_course($courseId)) {
            $itemId  = (int) ($_POST['item_id'] ?? 0);
            $current = $db->prepare("SELECT allow_download FROM course_folder_items WHERE id = ? AND course_id = ?");
            $current->execute([$itemId, $courseId]);
            $row    = $current->fetch();
            $newVal = 0;
            if ($row) {
                $newVal = $row['allow_download'] ? 0 : 1;
                $db->prepare("UPDATE course_folder_items SET allow_download = ? WHERE id = ? AND course_id = ?")
                   ->execute([$newVal, $itemId, $courseId]);
            }
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'allowed' => $newVal]);
            exit;

        } elseif ($action === 'delete_item') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            // Delete uploaded material file
            $fpStmt = $db->prepare(
                "SELECT file_path FROM course_folder_items WHERE id = ? AND course_id = ?"
            );
            $fpStmt->execute([$itemId, $courseId]);
            $fpRow = $fpStmt->fetch();
            if ($fpRow && $fpRow['file_path'] !== '') {
                $abs = portal_uploads_base() . DIRECTORY_SEPARATOR . $fpRow['file_path'];
                if (is_file($abs)) @unlink($abs);
            }
            // Delete any student submission files for this slot
            $subStmt = $db->prepare("SELECT filepath FROM course_submissions WHERE item_id = ? AND course_id = ?");
            $subStmt->execute([$itemId, $courseId]);
            foreach ($subStmt->fetchAll(PDO::FETCH_COLUMN) as $_sp) {
                $abs = portal_uploads_base() . DIRECTORY_SEPARATOR . $_sp;
                if (is_file($abs)) @unlink($abs);
            }
            $db->prepare("DELETE FROM course_folder_items WHERE id = ? AND course_id = ?")
               ->execute([$itemId, $courseId]);
            if ($itemId > 0) {
                portal_notifications_unsend('lesson-viewer.php?item=' . $itemId, true, 'lesson_answer');
            }

        } elseif ($action === 'post_announcement') {
            $title = substr(trim((string) ($_POST['title'] ?? '')), 0, 200);
            $body  = substr(portal_sanitize_rich_text(trim((string) ($_POST['body'] ?? ''))), 0, 2000);
            if ($title !== '') {
                $db->prepare(
                    "INSERT INTO course_announcements (course_id, user_id, title, body) VALUES (?,?,?,?)"
                )->execute([$courseId, (int) $me['id'], $title, $body]);
                $newAnnId = (int) $db->lastInsertId();
                if ($newAnnId > 0) {
                    $posterId = (int) $me['id'];
                    $db->prepare("INSERT OR IGNORE INTO announcement_reads (user_id, announcement_id) VALUES (?,?)")
                       ->execute([$posterId, $newAnnId]);

                    // Personal alerts for enrolled students + course managers
                    // (respects notify_announcements; skips the poster).
                    $recipientIds = [];
                    $enrolledStmt = $db->prepare(
                        "SELECT user_id FROM enrollments WHERE course_id = ?"
                    );
                    $enrolledStmt->execute([$courseId]);
                    foreach ($enrolledStmt->fetchAll(PDO::FETCH_COLUMN) as $studentId) {
                        $studentId = (int) $studentId;
                        if ($studentId > 0) {
                            $recipientIds[$studentId] = true;
                        }
                    }
                    $managerStmt = $db->prepare(
                        "SELECT user_id FROM course_teachers WHERE course_id = ?"
                    );
                    $managerStmt->execute([$courseId]);
                    foreach ($managerStmt->fetchAll(PDO::FETCH_COLUMN) as $managerId) {
                        $managerId = (int) $managerId;
                        if ($managerId > 0) {
                            $recipientIds[$managerId] = true;
                        }
                    }
                    foreach ($db->query(
                        "SELECT id FROM users WHERE role IN ('owner','admin')"
                    )->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
                        $adminId = (int) $adminId;
                        if ($adminId > 0) {
                            $recipientIds[$adminId] = true;
                        }
                    }
                    $annLink = 'course.php?course=' . urlencode((string) $course['slug'])
                        . '&section=announcements&ann=' . $newAnnId;
                    $courseTitle = (string) ($course['title'] ?? 'Module');
                    $notifTitle = 'New announcement in “' . substr($courseTitle, 0, 80) . '”';
                    $plainBody = trim(preg_replace('/\s+/u', ' ', strip_tags($body)) ?? '');
                    $snippet = $title . ($plainBody !== '' ? ' — ' . substr($plainBody, 0, 100) : '');
                    foreach (array_keys($recipientIds) as $rid) {
                        $rid = (int) $rid;
                        if ($rid <= 0 || $rid === $posterId) {
                            continue;
                        }
                        portal_notify_user(
                            $rid,
                            'announcement',
                            $notifTitle,
                            substr($snippet, 0, 200),
                            $annLink,
                            $courseId
                        );
                    }
                }
            }

        } elseif ($action === 'delete_announcement') {
            $annId = (int) ($_POST['announcement_id'] ?? 0);
            $annMeta = null;
            if ($annId > 0) {
                $annMetaStmt = $db->prepare(
                    "SELECT id, title FROM course_announcements WHERE id = ? AND course_id = ?"
                );
                $annMetaStmt->execute([$annId, $courseId]);
                $annMeta = $annMetaStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            $annDeleted = false;
            if ($annMeta) {
                if (portal_is_admin()) {
                    $delAnn = $db->prepare("DELETE FROM course_announcements WHERE id = ? AND course_id = ?");
                    $delAnn->execute([$annId, $courseId]);
                    $annDeleted = $delAnn->rowCount() > 0;
                } else {
                    $delAnn = $db->prepare(
                        "DELETE FROM course_announcements WHERE id = ? AND course_id = ? AND user_id = ?"
                    );
                    $delAnn->execute([$annId, $courseId, (int) $me['id']]);
                    $annDeleted = $delAnn->rowCount() > 0;
                }
            }
            if ($annDeleted && $annMeta) {
                $slug = (string) ($course['slug'] ?? '');
                $annLink = 'course.php?course=' . urlencode($slug)
                    . '&section=announcements&ann=' . $annId;
                portal_notifications_unsend($annLink, true, 'announcement');
                // Legacy alerts (no ann= id in the link) matched by course + body prefix.
                $annTitle = trim((string) ($annMeta['title'] ?? ''));
                if ($annTitle !== '') {
                    $legacyLink = 'course.php?course=' . urlencode($slug) . '&section=announcements';
                    $db->prepare(
                        "DELETE FROM portal_notifications
                         WHERE type = 'announcement' AND course_id = ? AND link = ?
                           AND (body = ? OR body LIKE ?)"
                    )->execute([$courseId, $legacyLink, $annTitle, $annTitle . ' —%']);
                }
            }

        } elseif ($action === 'save_tab_settings') {
            $allKeys     = ['content','calendar','announcements','discussions','gradebook','groups'];
            $enabledRaw  = (array) ($_POST['tab_keys'] ?? []);
            $enabledRaw[] = 'content'; // always on
            $db->prepare("DELETE FROM course_tab_settings WHERE course_id = ?")->execute([$courseId]);
            $insT = $db->prepare(
                "INSERT INTO course_tab_settings (course_id, tab_key, enabled) VALUES (?,?,?)"
            );
            foreach ($allKeys as $k) {
                $insT->execute([$courseId, $k, in_array($k, $enabledRaw, true) ? 1 : 0]);
            }

        } elseif ($action === 'mark_submission') {
            $subId = (int) ($_POST['submission_id'] ?? 0);
            $score = max(0, min(100, (int) ($_POST['score'] ?? 0)));
            $feedback = substr(trim((string) ($_POST['feedback'] ?? '')), 0, 2000);
            $chk = $db->prepare("SELECT id FROM course_submissions WHERE id = ? AND course_id = ?");
            $chk->execute([$subId, $courseId]);
            if ($chk->fetch()) {
                $before = $db->prepare(
                    'SELECT score, feedback, user_id FROM course_submissions WHERE id = ? AND course_id = ?'
                );
                $before->execute([$subId, $courseId]);
                $prev = $before->fetch(PDO::FETCH_ASSOC) ?: [];
                $db->prepare(
                    "UPDATE course_submissions
                     SET score = ?, feedback = ?, marked_at = datetime('now'), marked_by = ?, grade_seen_at = ''
                     WHERE id = ? AND course_id = ?"
                )->execute([$score, $feedback, (int) $me['id'], $subId, $courseId]);
                $prevScore = $prev['score'] ?? null;
                $studentId = (int) ($prev['user_id'] ?? 0);
                portal_log_security_event(
                    'grade_changed',
                    'medium',
                    'Marked submission #' . $subId
                        . ' (course #' . $courseId
                        . ', student #' . $studentId
                        . ') score '
                        . ($prevScore === null || $prevScore === '' ? 'none' : (string) $prevScore)
                        . '→' . $score
                );
                if ($studentId > 0) {
                    $slotTitleStmt = $db->prepare(
                        "SELECT cfi.title
                         FROM course_submissions cs
                         JOIN course_folder_items cfi ON cfi.id = cs.item_id
                         WHERE cs.id = ? AND cs.course_id = ?"
                    );
                    $slotTitleStmt->execute([$subId, $courseId]);
                    $slotTitle = trim((string) ($slotTitleStmt->fetchColumn() ?: 'Assignment'));
                    if ($slotTitle === '') {
                        $slotTitle = 'Assignment';
                    }
                    $gradeLink = 'course.php?course=' . rawurlencode($slug) . '&section=gradebook';
                    portal_notify_grade_returned(
                        $studentId,
                        $courseId,
                        'Grade returned: ' . $slotTitle,
                        'You scored ' . $score . '% on ' . $slotTitle . '.',
                        $gradeLink,
                        'submission:' . $subId . ':' . $score
                    );
                }
                if (portal_is_fetch_request()) {
                    portal_json_response([
                        'ok'            => true,
                        'submission_id' => $subId,
                        'score'         => $score,
                        'feedback'      => $feedback,
                    ]);
                }
                $_SESSION['course_flash'] = ['success', 'Submission marked.'];
            } elseif (portal_is_fetch_request()) {
                portal_json_response(['ok' => false, 'error' => 'Submission not found.'], 404);
            }

        } elseif ($action === 'save_annotation' && portal_can_manage_course($courseId)) {
            header('Content-Type: application/json');
            $subId = (int) ($_POST['submission_id'] ?? 0);
            $chk = $db->prepare("SELECT id FROM course_submissions WHERE id = ? AND course_id = ?");
            $chk->execute([$subId, $courseId]);
            if (!$chk->fetch()) {
                echo json_encode(['ok' => false, 'error' => 'Submission not found.']);
                exit;
            }

            $annId      = (int) ($_POST['annotation_id'] ?? 0);
            $anchorType = (string) ($_POST['anchor_type'] ?? 'text');
            if (!in_array($anchorType, ['text', 'image', 'general'], true)) {
                $anchorType = 'text';
            }
            $comment = trim((string) ($_POST['comment'] ?? ''));
            $comment = mb_substr($comment, 0, 2000);
            if ($comment === '') {
                echo json_encode(['ok' => false, 'error' => 'Comment cannot be empty.']);
                exit;
            }
            $quote      = mb_substr((string) ($_POST['quote'] ?? ''), 0, 2000);
            $rangeStart = isset($_POST['range_start']) && $_POST['range_start'] !== '' ? (int) $_POST['range_start'] : null;
            $rangeEnd   = isset($_POST['range_end']) && $_POST['range_end'] !== '' ? (int) $_POST['range_end'] : null;
            $posX       = isset($_POST['pos_x']) && $_POST['pos_x'] !== '' ? max(0.0, min(100.0, (float) $_POST['pos_x'])) : null;
            $posY       = isset($_POST['pos_y']) && $_POST['pos_y'] !== '' ? max(0.0, min(100.0, (float) $_POST['pos_y'])) : null;

            if ($annId > 0) {
                $own = $db->prepare(
                    "SELECT id FROM course_submission_annotations WHERE id = ? AND submission_id = ? AND course_id = ?"
                );
                $own->execute([$annId, $subId, $courseId]);
                if ($own->fetch()) {
                    $db->prepare(
                        "UPDATE course_submission_annotations
                         SET comment = ?, updated_at = datetime('now')
                         WHERE id = ?"
                    )->execute([$comment, $annId]);
                }
            } else {
                $db->prepare(
                    "INSERT INTO course_submission_annotations
                     (submission_id, course_id, author_id, anchor_type, range_start, range_end, quote, pos_x, pos_y, comment)
                     VALUES (?,?,?,?,?,?,?,?,?,?)"
                )->execute([$subId, $courseId, (int) $me['id'], $anchorType, $rangeStart, $rangeEnd, $quote, $posX, $posY, $comment]);
                $annId = (int) $db->lastInsertId();
            }

            $row = $db->prepare(
                "SELECT a.*, u.name AS author_name
                 FROM course_submission_annotations a
                 LEFT JOIN users u ON u.id = a.author_id
                 WHERE a.id = ?"
            );
            $row->execute([$annId]);
            echo json_encode(['ok' => true, 'annotation' => $row->fetch() ?: null]);
            exit;

        } elseif ($action === 'delete_annotation' && portal_can_manage_course($courseId)) {
            header('Content-Type: application/json');
            $annId = (int) ($_POST['annotation_id'] ?? 0);
            $db->prepare(
                "DELETE FROM course_submission_annotations WHERE id = ? AND course_id = ?"
            )->execute([$annId, $courseId]);
            echo json_encode(['ok' => true]);
            exit;

        } elseif ($action === 'delete_submission') {
            $subId = (int) ($_POST['submission_id'] ?? 0);
            $sStmt = $db->prepare(
                "SELECT filepath FROM course_submissions WHERE id = ? AND course_id = ?"
            );
            $sStmt->execute([$subId, $courseId]);
            $sRow = $sStmt->fetch();
            if ($sRow) {
                $abs = portal_uploads_base() . DIRECTORY_SEPARATOR . $sRow['filepath'];
                if (is_file($abs)) @unlink($abs);
                // Removed submissions should not remain active plagiarism sources.
                $db->prepare("DELETE FROM integrity_sentence_index WHERE source_type = 'submission' AND source_id = ?")
                   ->execute([$subId]);
                $db->prepare("DELETE FROM course_submission_annotations WHERE submission_id = ? AND course_id = ?")
                   ->execute([$subId, $courseId]);
                $db->prepare("DELETE FROM course_submissions WHERE id = ? AND course_id = ?")
                   ->execute([$subId, $courseId]);
            }

        } elseif ($action === 'create_schedule_slot') {
            $day   = substr(trim((string)($_POST['day_of_week'] ?? '')), 0, 20);
            $start = substr(trim((string)($_POST['start_time'] ?? '')), 0, 10);
            $end   = substr(trim((string)($_POST['end_time'] ?? '')), 0, 10);
            $room  = substr(trim((string)($_POST['room'] ?? '')), 0, 500);
            $notes = substr(trim((string)($_POST['notes'] ?? '')), 0, 300);
            $days  = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
            if ($day !== '' && in_array($day, $days, true)) {
                $db->prepare(
                    "INSERT INTO course_schedule (course_id, day_of_week, start_time, end_time, room, notes)
                     VALUES (?,?,?,?,?,?)"
                )->execute([$courseId, $day, $start, $end, $room, $notes]);
            }

        } elseif ($action === 'update_schedule_slot') {
            $slotId = (int)($_POST['slot_id'] ?? 0);
            $day    = substr(trim((string)($_POST['day_of_week'] ?? '')), 0, 20);
            $start  = substr(trim((string)($_POST['start_time'] ?? '')), 0, 10);
            $end    = substr(trim((string)($_POST['end_time'] ?? '')), 0, 10);
            $room   = substr(trim((string)($_POST['room'] ?? '')), 0, 500);
            $notes  = substr(trim((string)($_POST['notes'] ?? '')), 0, 300);
            $days   = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
            if ($slotId > 0 && $day !== '' && in_array($day, $days, true)) {
                $db->prepare(
                    "UPDATE course_schedule SET day_of_week = ?, start_time = ?, end_time = ?, room = ?, notes = ?
                     WHERE id = ? AND course_id = ?"
                )->execute([$day, $start, $end, $room, $notes, $slotId, $courseId]);
            }

        } elseif ($action === 'delete_schedule_slot') {
            $slotId = (int)($_POST['slot_id'] ?? 0);
            $db->prepare("DELETE FROM course_schedule WHERE id = ? AND course_id = ?")
               ->execute([$slotId, $courseId]);

        } elseif ($action === 'create_topic') {
            if (portal_user_is_muted($me)) {
                $_SESSION['course_flash'] = ['error', 'Your account is muted and cannot start discussions.'];
            } else {
            $title = substr(trim((string)($_POST['title'] ?? '')), 0, 200);
            $body  = substr(portal_sanitize_rich_text(trim((string)($_POST['body'] ?? ''))), 0, 3000);
            if ($title !== '') {
                $db->prepare(
                    "INSERT INTO course_discussion_topics (course_id, user_id, title, body) VALUES (?,?,?,?)"
                )->execute([$courseId, (int)$me['id'], $title, $body]);
            }
            }

        } elseif ($action === 'delete_topic') {
            $topicId = (int)($_POST['topic_id'] ?? 0);
            $db->prepare("DELETE FROM course_discussion_topics WHERE id = ? AND course_id = ?")
               ->execute([$topicId, $courseId]);
            if ($topicId > 0) {
                $topicLink = 'course.php?course=' . urlencode((string) $course['slug'])
                    . '&section=discussions&topic=' . $topicId;
                portal_notifications_unsend($topicLink, true, 'discussion_reply');
            }

        } elseif ($action === 'create_group') {
            $title  = substr(trim((string)($_POST['title'] ?? '')), 0, 150);
            $desc   = substr(trim((string)($_POST['description'] ?? '')), 0, 400);
            $maxM   = max(0, (int)($_POST['max_members'] ?? 0));
            if ($title !== '') {
                $db->prepare(
                    "INSERT INTO course_groups (course_id, title, description, max_members) VALUES (?,?,?,?)"
                )->execute([$courseId, $title, $desc, $maxM]);
            }

        } elseif ($action === 'delete_group') {
            $gid = (int)($_POST['group_id'] ?? 0);
            $db->prepare("DELETE FROM course_groups WHERE id = ? AND course_id = ?")
               ->execute([$gid, $courseId]);

        } elseif ($action === 'update_course_description') {
            $summary = substr(trim((string)($_POST['summary'] ?? '')), 0, 500);
            if ($summary !== '') {
                $db->prepare("UPDATE courses SET summary = ? WHERE id = ?")
                   ->execute([$summary, $courseId]);
            }
        }
    }

    // ── Any logged-in user: reply to topic ───────────────────────────────────
    if ($action === 'post_reply') {
        if (portal_user_is_muted($me)) {
            $_SESSION['course_flash'] = ['error', 'Your account is muted and cannot reply to discussions.'];
        } else {
        $topicId = (int)($_POST['topic_id'] ?? 0);
        $body    = substr(portal_sanitize_rich_text(trim((string)($_POST['body'] ?? ''))), 0, 3000);
        // Verify topic belongs to this course
        $tChk = $db->prepare(
            "SELECT id, user_id, title FROM course_discussion_topics WHERE id = ? AND course_id = ?"
        );
        $tChk->execute([$topicId, $courseId]);
        $topicRow = $tChk->fetch();
        if ($topicRow && $body !== '') {
            $posterId = (int) $me['id'];

            // Soft de-dupe for double-clicks: two identical POSTs can arrive
            // before the browser navigates away. Same user + topic + body
            // within 10 seconds counts as one reply (no extra notifications).
            $dupStmt = $db->prepare(
                "SELECT id FROM course_discussion_replies
                 WHERE topic_id = ? AND user_id = ? AND body = ?
                   AND created_at >= datetime('now', '-10 seconds')
                 LIMIT 1"
            );
            $dupStmt->execute([$topicId, $posterId, $body]);
            $isDuplicateReply = (bool) $dupStmt->fetchColumn();

            if (!$isDuplicateReply) {
                $db->prepare(
                    "INSERT INTO course_discussion_replies (topic_id, course_id, user_id, body) VALUES (?,?,?,?)"
                )->execute([$topicId, $courseId, $posterId, $body]);
                $newReplyId = (int) $db->lastInsertId();

                // Personal alerts for thread starter, prior participants, and
                // course managers (notify_qa). Poster is excluded below.
                $recipientIds = [];
                $topicAuthorId = (int) $topicRow['user_id'];
                if ($topicAuthorId > 0 && $topicAuthorId !== $posterId) {
                    $recipientIds[$topicAuthorId] = true;
                }
                $partStmt = $db->prepare(
                    "SELECT DISTINCT user_id FROM course_discussion_replies
                     WHERE topic_id = ? AND user_id != ?"
                );
                $partStmt->execute([$topicId, $posterId]);
                foreach ($partStmt->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                    $pid = (int) $pid;
                    if ($pid > 0) {
                        $recipientIds[$pid] = true;
                    }
                }
                $managerStmt = $db->prepare(
                    "SELECT user_id FROM course_teachers WHERE course_id = ?"
                );
                $managerStmt->execute([$courseId]);
                foreach ($managerStmt->fetchAll(PDO::FETCH_COLUMN) as $managerId) {
                    $managerId = (int) $managerId;
                    if ($managerId > 0 && $managerId !== $posterId) {
                        $recipientIds[$managerId] = true;
                    }
                }
                // Site owners/admins manage every course — include them so staff
                // dashboards receive the same personal alerts students do.
                foreach ($db->query(
                    "SELECT id FROM users WHERE role IN ('owner','admin')"
                )->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
                    $adminId = (int) $adminId;
                    if ($adminId > 0 && $adminId !== $posterId) {
                        $recipientIds[$adminId] = true;
                    }
                }

                if (!empty($recipientIds) && $newReplyId > 0) {
                    $posterName = trim((string) ($me['name'] ?? 'Someone')) ?: 'Someone';
                    $topicTitle = (string) $topicRow['title'];
                    $discLink = 'course.php?course=' . urlencode((string) $course['slug'])
                        . '&section=discussions&topic=' . $topicId
                        . '&reply=' . $newReplyId;
                    $plainBody = trim(preg_replace('/\s+/u', ' ', strip_tags($body)) ?? '');
                    $snippet = substr($plainBody !== '' ? $plainBody : 'New reply in the discussion.', 0, 160);
                    $notifTitle = $posterName . ' replied in “' . substr($topicTitle, 0, 80) . '”';
                    foreach (array_keys($recipientIds) as $rid) {
                        portal_notify_user(
                            (int) $rid,
                            'discussion_reply',
                            $notifTitle,
                            $snippet,
                            $discLink,
                            $courseId
                        );
                    }
                }
            }
        }
        }
    }

    if ($action === 'delete_reply') {
        $replyId = (int)($_POST['reply_id'] ?? 0);
        $replyMeta = null;
        if ($replyId > 0) {
            $replyMetaStmt = $db->prepare(
                "SELECT id, topic_id, body FROM course_discussion_replies WHERE id = ? AND course_id = ?"
            );
            $replyMetaStmt->execute([$replyId, $courseId]);
            $replyMeta = $replyMetaStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $replyDeleted = false;
        if ($replyMeta) {
            if (portal_can_manage_course($courseId)) {
                $delReply = $db->prepare("DELETE FROM course_discussion_replies WHERE id = ? AND course_id = ?");
                $delReply->execute([$replyId, $courseId]);
                $replyDeleted = $delReply->rowCount() > 0;
            } else {
                $delReply = $db->prepare(
                    "DELETE FROM course_discussion_replies WHERE id = ? AND course_id = ? AND user_id = ?"
                );
                $delReply->execute([$replyId, $courseId, (int)$me['id']]);
                $replyDeleted = $delReply->rowCount() > 0;
            }
        }
        if ($replyDeleted && $replyMeta) {
            $topicId = (int) ($replyMeta['topic_id'] ?? 0);
            $topicBase = 'course.php?course=' . urlencode((string) $course['slug'])
                . '&section=discussions&topic=' . $topicId;
            portal_notifications_unsend($topicBase . '&reply=' . $replyId, false, 'discussion_reply');
            // Legacy alerts (no reply= id): match the same topic link + body snippet.
            $plainBody = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($replyMeta['body'] ?? ''))) ?? '');
            $snippet = substr($plainBody !== '' ? $plainBody : 'New reply in the discussion.', 0, 160);
            $db->prepare(
                "DELETE FROM portal_notifications
                 WHERE type = 'discussion_reply' AND course_id = ? AND link = ? AND body = ?"
            )->execute([$courseId, $topicBase, $snippet]);
        }
    }

    // ── Students: join / leave group ─────────────────────────────────────────
    if ($action === 'join_group') {
        $gid = (int)($_POST['group_id'] ?? 0);
        $uid = (int)$me['id'];
        $gStmt = $db->prepare("SELECT max_members FROM course_groups WHERE id = ? AND course_id = ?");
        $gStmt->execute([$gid, $courseId]);
        $gRow = $gStmt->fetch();
        if (!$gRow) {
            $_SESSION['course_flash'] = ['error', 'That group could not be found.'];
        } else {
            $alreadyIn = $db->prepare(
                "SELECT cgm.group_id
                 FROM course_group_members cgm
                 JOIN course_groups cg ON cg.id = cgm.group_id
                 WHERE cg.course_id = ? AND cgm.user_id = ?
                 LIMIT 1"
            );
            $alreadyIn->execute([$courseId, $uid]);
            $existingGroupId = (int) ($alreadyIn->fetchColumn() ?: 0);

            if ($existingGroupId > 0 && $existingGroupId !== $gid) {
                $_SESSION['course_flash'] = ['error', 'You are already in a group for this module. Leave it before joining another.'];
            } elseif ($existingGroupId === $gid) {
                $_SESSION['course_flash'] = ['success', 'You are already in that group.'];
            } else {
                $maxM = (int)$gRow['max_members'];
                $canJoin = true;
                if ($maxM > 0) {
                    $cntStmt = $db->prepare("SELECT COUNT(*) FROM course_group_members WHERE group_id = ?");
                    $cntStmt->execute([$gid]);
                    $canJoin = ((int) $cntStmt->fetchColumn()) < $maxM;
                }
                if (!$canJoin) {
                    $_SESSION['course_flash'] = ['error', 'That group is full.'];
                } else {
                    $db->prepare("INSERT OR IGNORE INTO course_group_members (group_id, user_id) VALUES (?,?)")
                       ->execute([$gid, $uid]);
                    $_SESSION['course_flash'] = ['success', 'You joined the group.'];
                }
            }
        }
    }

    if ($action === 'leave_group') {
        $gid = (int)($_POST['group_id'] ?? 0);
        $db->prepare(
            "DELETE FROM course_group_members
             WHERE group_id = ?
               AND user_id = ?
               AND EXISTS (
                   SELECT 1 FROM course_groups
                   WHERE course_groups.id = course_group_members.group_id
                     AND course_groups.course_id = ?
               )"
        )->execute([$gid, (int)$me['id'], $courseId]);
        $_SESSION['course_flash'] = ['success', 'You left the group.'];
    }

    // ── Teacher: re-run originality / AI checks on a submission ───────────────
    if ($action === 'rerun_integrity' && portal_can_manage_course($courseId)) {
        $submissionId = (int) ($_POST['submission_id'] ?? 0);
        if ($submissionId > 0) {
            $owner = $db->prepare("SELECT course_id FROM course_submissions WHERE id = ?");
            $owner->execute([$submissionId]);
            $ownerRow = $owner->fetch();
            if ($ownerRow && (int) $ownerRow['course_id'] === $courseId) {
                portal_rerun_submission_integrity($db, $submissionId, true);
                $_SESSION['course_flash'] = ['success', 'Originality and AI checks were re-run for this submission.'];
            }
        }
    }

    // ── Student: submit work to a submission slot ─────────────────────────────
    if ($action === 'submit_work') {
        $submitJsonExit = static function (bool $ok, array $data = [], string $error = '') use ($slug): void {
            if (portal_is_fetch_request()) {
                portal_json_response($ok ? array_merge(['ok' => true], $data) : ['ok' => false, 'error' => $error]);
            }
            portal_redirect('course.php?course=' . urlencode($slug));
        };

        $itemId = (int) ($_POST['item_id'] ?? 0);
        $uid = (int) $me['id'];

        // Authorisation: must be able to access course; students submit work.
        if (!portal_can_access_course($courseId) || portal_current_user_role() !== 'student') {
            portal_log_security_event('unauthorised_course_access', 'medium', 'Blocked submit_work');
            $_SESSION['course_flash'] = ['error', 'You are not allowed to submit work for this course.'];
            $submitJsonExit(false, [], 'You are not allowed to submit work for this course.');
        }

        if (portal_user_is_restricted($me)) {
            $_SESSION['course_flash'] = ['error', 'Your account is restricted and cannot submit coursework.'];
            $submitJsonExit(false, [], 'Your account is restricted and cannot submit coursework.');
        }

        $slotChk = $db->prepare(
            "SELECT cfi.id, cfi.title, cfi.submission_deadline, cfi.submission_ai_detection,
                    cfi.submission_max_attempts, cfi.description, cfi.locked,
                    COALESCE(cf.locked, 0) AS folder_locked
             FROM course_folder_items cfi
             LEFT JOIN course_folders cf ON cf.id = cfi.folder_id
             WHERE cfi.id = ? AND cfi.course_id = ? AND cfi.type = 'submission'"
        );
        $slotChk->execute([$itemId, $courseId]);
        $slot = $slotChk->fetch();
        if (!$slot) {
            $_SESSION['course_flash'] = ['error', 'Submission slot not found.'];
            $submitJsonExit(false, [], 'Submission slot not found.');
        }

        if (portal_folder_item_content_locked($slot)) {
            portal_log_security_event(
                'unauthorised_course_access',
                'medium',
                'Blocked submit_work on locked item ' . $itemId
            );
            $_SESSION['course_flash'] = ['error', 'This submission is locked by your teacher.'];
            $submitJsonExit(false, [], 'This submission is locked by your teacher.');
        }

        $maxAttempts = (int) ($slot['submission_max_attempts'] ?? 0);
        $attemptsUsed = 0;
        if ($maxAttempts > 0) {
            $attemptsStmt = $db->prepare(
                "SELECT COUNT(*) FROM course_submission_versions WHERE item_id = ? AND user_id = ?"
            );
            $attemptsStmt->execute([$itemId, $uid]);
            $attemptsUsed = (int) $attemptsStmt->fetchColumn();
        }

        $pastedText = portal_integrity_normalize_text((string) ($_POST['submission_text'] ?? ''));
        $fileField = isset($_FILES['submission_file']) && is_array($_FILES['submission_file'])
            ? $_FILES['submission_file']
            : null;
        $subError = (int) ($fileField['error'] ?? UPLOAD_ERR_NO_FILE);
        $hasFile = $fileField !== null && $subError !== UPLOAD_ERR_NO_FILE;
        $declaredType = strtolower(trim((string) ($_POST['submission_type'] ?? '')));
        if (!$hasFile && $pastedText !== '') {
            $declaredType = 'txt';
        }

        $deadlineTs = $slot['submission_deadline'] !== '' ? strtotime((string) $slot['submission_deadline']) : false;
        $processEditSeconds = max(0, min(86400, (int) ($_POST['process_edit_seconds'] ?? 0)));
        $processPasteEvents = max(0, min(1000, (int) ($_POST['process_paste_events'] ?? 0)));
        $processPastedChars = max(0, min(1000000, (int) ($_POST['process_pasted_chars'] ?? 0)));

        $validated = null;
        $originalName = 'pasted-text-submission.txt';
        $ext = 'txt';
        $subSize = 0;
        $tmpPath = '';

        if ($deadlineTs !== false && time() > $deadlineTs) {
            $_SESSION['course_flash'] = ['error', 'This submission deadline has passed. Ask your teacher if you need an extension.'];
            $submitJsonExit(false, [], 'This submission deadline has passed. Ask your teacher if you need an extension.');
        } elseif (!$hasFile && $pastedText === '') {
            $_SESSION['course_flash'] = ['error', 'Upload a document or paste your submission text before submitting.'];
            $submitJsonExit(false, [], 'Upload a document or paste your submission text before submitting.');
        } elseif ($hasFile) {
            $validated = portal_validate_submission_upload($fileField, $declaredType);
            if (!$validated['ok']) {
                $reason = (string) ($validated['reason'] ?? 'upload_error');
                $phpDetail = $subError !== UPLOAD_ERR_OK ? $uploadErrorMessage($subError) : '';
                portal_log_blocked_upload_reason($reason, $phpDetail);
                $msg = (string) ($validated['public_message'] ?? portal_submission_upload_generic_message());
                $_SESSION['course_flash'] = ['error', $msg];
                $submitJsonExit(false, [], $msg);
            }
            $originalName = (string) $validated['display_name'];
            $ext = (string) $validated['extension'];
            $subSize = (int) $validated['size'];
            $tmpPath = (string) $validated['tmp_path'];
            $declaredType = (string) $validated['declared_type'];
        } elseif ($declaredType !== 'txt') {
            portal_log_blocked_upload_reason('missing_type');
            $msg = portal_submission_type_mismatch_message();
            $_SESSION['course_flash'] = ['error', $msg];
            $submitJsonExit(false, [], $msg);
        }

        // Pre-check content length before replacing an existing submission.
        $extractableExts = ['txt', 'doc', 'docx', 'pdf'];
        $precheckText = $pastedText;
        if ($hasFile && in_array($ext, $extractableExts, true) && $tmpPath !== '' && is_file($tmpPath)) {
            $precheckFileText = portal_extract_submission_text($tmpPath, $originalName);
            $precheckText = trim($pastedText . "\n\n" . $precheckFileText);
        }
        $precheckWordCount = count(portal_integrity_words($precheckText));
        $precheckCharCount = mb_strlen(portal_integrity_normalize_text($precheckText));
        $minWords = portal_submission_min_words();
        $minChars = portal_submission_min_chars();
        $shouldCheckLength = !$hasFile || (in_array($ext, $extractableExts, true) && $precheckCharCount > 0);
        $submissionTooShort = $shouldCheckLength
            && ($precheckWordCount < $minWords || $precheckCharCount < $minChars);

        if ($submissionTooShort) {
            $msg = 'Your submission is too short (' . $precheckWordCount . ' word' . ($precheckWordCount === 1 ? '' : 's')
                 . '). Please write a more complete response of at least ' . $minWords . ' words before submitting.';
            $_SESSION['course_flash'] = ['error', $msg];
            $submitJsonExit(false, [], $msg);
        }
        if ($maxAttempts > 0 && $attemptsUsed >= $maxAttempts) {
            $msg = 'You have already used all ' . $maxAttempts . ' allowed submission attempt' . ($maxAttempts === 1 ? '' : 's') . ' for this assignment.';
            $_SESSION['course_flash'] = ['error', $msg];
            $submitJsonExit(false, [], $msg);
        }

        $prevStmt = $db->prepare(
            "SELECT id, filepath FROM course_submissions WHERE item_id = ? AND user_id = ?"
        );
        $prevStmt->execute([$itemId, $uid]);
        $prev = $prevStmt->fetch();
        $prevAbs = '';
        if ($prev && $prev['filepath'] !== '') {
            $prevAbs = portal_uploads_base() . DIRECTORY_SEPARATOR . $prev['filepath'];
        }

        $dir = portal_submissions_storage_dir($itemId, $uid);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            portal_log_blocked_upload_reason('move_failed', 'mkdir');
            $_SESSION['course_flash'] = ['error', portal_submission_upload_generic_message()];
            $submitJsonExit(false, [], portal_submission_upload_generic_message());
        }

        $safe = portal_new_submission_storage_name($ext);
        $savedAbs = $dir . DIRECTORY_SEPARATOR . $safe;
        if (is_file($savedAbs)) {
            // Collision — generate again once.
            $safe = portal_new_submission_storage_name($ext);
            $savedAbs = $dir . DIRECTORY_SEPARATOR . $safe;
        }
        if (is_file($savedAbs)) {
            portal_log_blocked_upload_reason('move_failed', 'exists');
            $_SESSION['course_flash'] = ['error', portal_submission_upload_generic_message()];
            $submitJsonExit(false, [], portal_submission_upload_generic_message());
        }

        $relPath = 'submissions' . DIRECTORY_SEPARATOR . $itemId
                 . DIRECTORY_SEPARATOR . $uid . DIRECTORY_SEPARATOR . $safe;
        $receiptNumber = portal_generate_unique_receipt_number($db);
        $tempHash = ($hasFile && $tmpPath !== '') ? hash_file('sha256', $tmpPath) : '';

        $saved = false;
        if ($hasFile) {
            $saved = move_uploaded_file($tmpPath, $savedAbs);
        } else {
            $saved = file_put_contents($savedAbs, $pastedText) !== false;
            $subSize = is_file($savedAbs) ? (int) filesize($savedAbs) : 0;
        }

        if (!$saved) {
            portal_log_blocked_upload_reason('move_failed');
            $_SESSION['course_flash'] = ['error', portal_submission_upload_generic_message()];
            $submitJsonExit(false, [], portal_submission_upload_generic_message());
        }

        @chmod($savedAbs, 0640);

        $fileHash = is_file($savedAbs) ? (string) hash_file('sha256', $savedAbs) : '';
        if ($hasFile && $tempHash !== '' && $fileHash !== '' && !hash_equals($tempHash, $fileHash)) {
            @unlink($savedAbs);
            portal_log_blocked_upload_reason('move_failed', 'hash_mismatch');
            $_SESSION['course_flash'] = ['error', portal_submission_upload_generic_message()];
            $submitJsonExit(false, [], portal_submission_upload_generic_message());
        }

        $extraction = $hasFile
            ? portal_extract_submission_text_detailed($savedAbs, $originalName)
            : [
                'text' => '',
                'extractor' => 'paste',
                'char_count' => 0,
                'word_count' => 0,
                'confidence' => 'high',
                'note' => '',
            ];
        $fileText = (string) ($extraction['text'] ?? '');
        $combinedText = portal_integrity_normalize_text(trim($pastedText . "\n\n" . $fileText));
        if ($combinedText !== '' && function_exists('mb_substr')) {
            $combinedText = mb_substr($combinedText, 0, 200000);
        } elseif ($combinedText !== '') {
            $combinedText = substr($combinedText, 0, 200000);
        }
        if ($pastedText !== '' && count(portal_integrity_words($pastedText)) >= 25) {
            $extraction['confidence'] = 'high';
            $extraction['note'] = '';
        } elseif (!$hasFile) {
            $extraction['confidence'] = 'high';
            $extraction['extractor'] = 'paste';
        }

        $fileMetadata = is_file($savedAbs)
            ? portal_extract_submission_file_metadata($savedAbs, $originalName)
            : ['available' => false, 'format' => $ext];
        $slotInstructions = trim((string) ($slot['description'] ?? ''));
        $submissionContext = [
            'course_id' => $courseId,
            'submission_ai_detection' => (int) ($slot['submission_ai_detection'] ?? 0),
            'process_edit_seconds' => $processEditSeconds,
            'process_paste_events' => $processPasteEvents,
            'process_pasted_chars' => $processPastedChars,
            'file_metadata' => $fileMetadata,
            'student_name' => (string) ($me['name'] ?? ''),
            'extraction' => $extraction,
            'slot_instructions' => $slotInstructions,
        ];
        $similarity = portal_integrity_check_similarity(
            $db,
            $combinedText,
            $courseId,
            $itemId,
            $uid,
            $fileHash,
            null,
            $submissionContext
        );

        $dbOk = false;
        $submissionId = 0;
        try {
            $db->beginTransaction();

            if ($prev && (int) ($prev['id'] ?? 0) > 0) {
                $prevSubId = (int) $prev['id'];
                $db->prepare("DELETE FROM integrity_sentence_index WHERE source_type = 'submission' AND source_id = ?")
                   ->execute([$prevSubId]);
                $db->prepare("DELETE FROM course_submission_annotations WHERE submission_id = ? AND course_id = ?")
                   ->execute([$prevSubId, $courseId]);
            }

            $db->prepare(
                "INSERT INTO course_submissions
                 (item_id, course_id, user_id, filename, filepath, filesize,
                  receipt_number, file_sha256, submission_text, text_word_count,
                  similarity_status, similarity_score, similarity_report, similarity_checked_at,
                  process_edit_seconds, process_paste_events, process_pasted_chars, declared_file_type)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,datetime('now'),?,?,?,?)
                 ON CONFLICT(item_id, user_id) DO UPDATE
                 SET filename=excluded.filename, filepath=excluded.filepath,
                     filesize=excluded.filesize, submitted_at=datetime('now'),
                     receipt_number=excluded.receipt_number,
                     file_sha256=excluded.file_sha256,
                     submission_text=excluded.submission_text,
                     text_word_count=excluded.text_word_count,
                     similarity_status=excluded.similarity_status,
                     similarity_score=excluded.similarity_score,
                     similarity_report=excluded.similarity_report,
                     similarity_checked_at=datetime('now'),
                     process_edit_seconds=excluded.process_edit_seconds,
                     process_paste_events=excluded.process_paste_events,
                     process_pasted_chars=excluded.process_pasted_chars,
                     declared_file_type=excluded.declared_file_type,
                     score=NULL, feedback='', marked_at='', marked_by=NULL, grade_seen_at='',
                     ai_status='', ai_score=NULL, ai_report='', ai_checked_at=''"
            )->execute([
                $itemId, $courseId, $uid,
                $originalName,
                $relPath,
                $subSize,
                $receiptNumber,
                $fileHash,
                $combinedText,
                (int) $similarity['word_count'],
                $similarity['status'],
                $similarity['score'],
                $similarity['report'],
                $processEditSeconds,
                $processPasteEvents,
                $processPastedChars,
                $declaredType,
            ]);

            $subIdStmt = $db->prepare("SELECT id FROM course_submissions WHERE item_id = ? AND user_id = ?");
            $subIdStmt->execute([$itemId, $uid]);
            $submissionId = (int) ($subIdStmt->fetchColumn() ?: 0);

            if ($submissionId > 0) {
                $db->prepare(
                    "INSERT INTO course_submission_versions
                     (submission_id, item_id, course_id, user_id, filename, filesize, file_sha256,
                      text_word_count, receipt_number, similarity_status, similarity_score,
                      process_edit_seconds, process_paste_events, process_pasted_chars)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                )->execute([
                    $submissionId,
                    $itemId,
                    $courseId,
                    $uid,
                    $originalName,
                    $subSize,
                    $fileHash,
                    (int) $similarity['word_count'],
                    $receiptNumber,
                    $similarity['status'],
                    $similarity['score'],
                    $processEditSeconds,
                    $processPasteEvents,
                    $processPastedChars,
                ]);
            }

            $db->commit();
            $dbOk = true;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if (is_file($savedAbs) && !@unlink($savedAbs)) {
                portal_log_security_event('blocked_upload', 'medium', 'Cleanup failed after DB error');
            }
            portal_log_blocked_upload_reason('move_failed', 'db');
            $_SESSION['course_flash'] = ['error', portal_submission_upload_generic_message()];
            $submitJsonExit(false, [], portal_submission_upload_generic_message());
        }

        if ($dbOk && $prevAbs !== '' && is_file($prevAbs) && realpath($prevAbs) !== realpath($savedAbs)) {
            @unlink($prevAbs);
        }

        if ($submissionId > 0) {
            $studentName = (string) ($me['name'] ?? 'Student');
            portal_integrity_index_document(
                $db,
                $combinedText,
                'submission',
                $submissionId,
                $studentName . ' - submission #' . $submissionId,
                $courseId
            );
        }

        if (portal_external_ai_should_run([
            'course_id' => $courseId,
            'submission_ai_detection' => (int) ($slot['submission_ai_detection'] ?? 0),
        ])) {
            $isPptxSubmission = in_array((string) ($extraction['extractor'] ?? ''), ['pptx', 'pptx-soffice'], true)
                || in_array($ext, ['ppt', 'pptx', 'pps', 'ppsx'], true)
                || $declaredType === 'pptx';
            if ($isPptxSubmission) {
                $db->prepare(
                    "UPDATE course_submissions
                     SET ai_status = ?, ai_score = NULL, ai_report = ?, ai_checked_at = datetime('now')
                     WHERE item_id = ? AND user_id = ?"
                )->execute([
                    'disabled',
                    'AI detection is disabled for PowerPoint submissions.',
                    $itemId,
                    $uid,
                ]);
            } else {
                $ai = portal_gptzero_detection($combinedText);
                $db->prepare(
                    "UPDATE course_submissions
                     SET ai_status = ?, ai_score = ?, ai_report = ?, ai_checked_at = datetime('now')
                     WHERE item_id = ? AND user_id = ?"
                )->execute([
                    $ai['status'],
                    $ai['score'],
                    $ai['report'],
                    $itemId,
                    $uid,
                ]);
            }
        }

        $submittedAt = '';
        if ($submissionId > 0) {
            $subAtStmt = $db->prepare("SELECT submitted_at FROM course_submissions WHERE id = ?");
            $subAtStmt->execute([$submissionId]);
            $submittedAt = (string) ($subAtStmt->fetchColumn() ?: '');
        }
        $submittedAtLabel = $submittedAt !== '' ? date('j M Y H:i', strtotime($submittedAt)) : '';
        $hashPrefix = $fileHash !== '' ? strtoupper(substr($fileHash, 0, 12)) : '';

        $_SESSION['course_flash'] = ['success', 'Submission received.'];

        $submitJsonExit(true, [
            'submission_id'      => $submissionId,
            'item_id'            => $itemId,
            'filename'           => $originalName,
            'declared_type'      => $declaredType,
            'submitted_at'       => $submittedAt,
            'submitted_at_label' => $submittedAtLabel,
            'receipt_number'     => $receiptNumber,
            'file_sha256_prefix' => $hashPrefix,
            'student_name'       => (string) ($me['name'] ?? ''),
            'student_username'   => (string) ($me['username'] ?? ''),
            'course_title'       => (string) ($course['title'] ?? ''),
            'assignment_title'   => (string) ($slot['title'] ?? 'Assignment'),
            'is_resubmit'        => (bool) $prev,
            'message'            => 'Submission received.',
            'max_attempts'       => $maxAttempts,
            'attempts_used'      => $maxAttempts > 0 ? $attemptsUsed + 1 : 0,
            'attempts_reached'   => $maxAttempts > 0 && ($attemptsUsed + 1) >= $maxAttempts,
        ]);
    }

    $rBase = 'course.php?course=' . urlencode($slug);
    if ($action === 'create_item' && portal_is_fetch_request()) {
        $flash = $_SESSION['course_flash'] ?? null;
        $ok = is_array($flash) && ($flash[0] ?? '') === 'success';
        $message = is_array($flash) ? (string) ($flash[1] ?? '') : '';
        // Keep success flash so the redirected page can show the toast.
        if (!$ok) {
            unset($_SESSION['course_flash']);
        }
        portal_json_response([
            'ok' => $ok,
            'message' => $message,
            'error' => $ok ? '' : ($message !== '' ? $message : 'Could not add item.'),
            'redirect' => $ok ? ($rBase . '&section=content') : '',
        ]);
    }
    if (portal_is_fetch_request() && in_array($action, ['mark_submission', 'submit_work'], true)) {
        portal_json_response(['ok' => false, 'error' => 'Unexpected response.'], 500);
    }
    if (in_array($action, ['mark_submission'], true)) {
        portal_redirect($rBase . '&section=gradebook');
    } elseif (in_array($action, ['create_schedule_slot','update_schedule_slot','delete_schedule_slot'])) {
        portal_redirect($rBase . '&section=calendar');
    } elseif ($action === 'update_course_description') {
        $retSec = (string)($_POST['return_section'] ?? 'content');
        $validSecs = ['content','calendar','announcements','discussions','gradebook','groups'];
        portal_redirect($rBase . '&section=' . (in_array($retSec, $validSecs, true) ? $retSec : 'content'));
    } elseif (in_array($action, ['post_announcement','delete_announcement'])) {
        portal_redirect($rBase . '&section=announcements');
    } elseif (in_array($action, ['create_topic','delete_topic'])) {
        portal_redirect($rBase . '&section=discussions');
    } elseif (in_array($action, ['post_reply','delete_reply'])) {
        $tid = (int)($_POST['topic_id'] ?? 0);
        portal_redirect($rBase . '&section=discussions' . ($tid > 0 ? '&topic=' . $tid : ''));
    } elseif (in_array($action, ['create_group','delete_group','join_group','leave_group'])) {
        portal_redirect($rBase . '&section=groups');
    } else {
        portal_redirect($rBase . '&section=content');
    }
}

// ── DB queries for this course ────────────────────────────────────────────────
$courseId = (int) $course['id'];
$_db = portal_db();
$_me = portal_current_user();

// Folders + items
$_fStmt = $_db->prepare(
    "SELECT * FROM course_folders WHERE course_id = ? ORDER BY sort_order ASC, id ASC"
);
$_fStmt->execute([$courseId]);
$courseFolders = $_fStmt->fetchAll();

foreach ($courseFolders as &$_folder) {
    $_iStmt = $_db->prepare(
        "SELECT * FROM course_folder_items WHERE folder_id = ? ORDER BY sort_order ASC, id ASC"
    );
    $_iStmt->execute([$_folder['id']]);
    $_folder['items'] = $_iStmt->fetchAll();
}
unset($_folder);

// Open lesson Q&A counts (teachers) — keyed by video item id
$videoOpenQuestionCounts = [];
if (portal_can_manage_course($courseId)) {
    $_oqStmt = $_db->prepare(
        "SELECT item_id, COUNT(*) AS open_count
         FROM course_video_questions
         WHERE course_id = ? AND answer = ''
         GROUP BY item_id"
    );
    $_oqStmt->execute([$courseId]);
    foreach ($_oqStmt->fetchAll() as $_oq) {
        $videoOpenQuestionCounts[(int) $_oq['item_id']] = (int) $_oq['open_count'];
    }
}

$submissionModals = '';

// Assigned course staff for this course (course-level teacher / supervisor)
$_tStmt = $_db->prepare(
    "SELECT u.id, u.name, u.initials, u.email, ct.assignment_role
     FROM course_teachers ct
     JOIN users u ON u.id = ct.user_id
     WHERE ct.course_id = ?
     ORDER BY ct.assignment_role DESC, u.name ASC"
);
$_tStmt->execute([$courseId]);
$courseTeachers = $_tStmt->fetchAll();

// Staff accounts (owner / admin / teacher) not yet assigned to this course
$courseTeacherIds = array_column($courseTeachers, 'id');
$availableTeachers = [];
if (portal_is_admin()) {
    $_atStmt = $_db->query(
        "SELECT id, name, initials, role FROM users
         WHERE role IN ('owner', 'admin', 'teacher')
         ORDER BY
            CASE role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END,
            name ASC"
    );
    foreach ($_atStmt->fetchAll() as $_t) {
        if (!in_array((int) $_t['id'], $courseTeacherIds, true)) {
            $availableTeachers[] = $_t;
        }
    }
}

// Announcements (newest first)
$_aStmt = $_db->prepare(
    "SELECT ca.*, u.name AS author_name, u.initials AS author_initials
     FROM course_announcements ca
     JOIN users u ON u.id = ca.user_id
     WHERE ca.course_id = ?
     ORDER BY ca.created_at DESC"
);
$_aStmt->execute([$courseId]);
$courseAnnouncements = $_aStmt->fetchAll();

// Which announcements has the current user already read?
$_readStmt = $_db->prepare(
    "SELECT ar.announcement_id FROM announcement_reads ar
     JOIN course_announcements ca ON ca.id = ar.announcement_id
     WHERE ar.user_id = ? AND ca.course_id = ?"
);
$_readStmt->execute([(int) $_me['id'], $courseId]);
$_readAnnIds    = array_map('intval', $_readStmt->fetchAll(PDO::FETCH_COLUMN));
$unreadAnnouncements = array_values(
    array_filter($courseAnnouncements, fn($a) => !in_array((int) $a['id'], $_readAnnIds, true))
);

// Submissions: teachers see all per slot; students see only their own
$slotSubmissions = []; // item_id → [ submissions ]
$mySubmissions   = []; // item_id → single submission row
if (portal_can_manage_course($courseId)) {
    $_ssStmt = $_db->prepare(
        "SELECT cs.*, u.name AS student_name, u.initials AS student_initials
         FROM course_submissions cs
         JOIN users u ON u.id = cs.user_id
         WHERE cs.course_id = ?
         ORDER BY cs.submitted_at DESC"
    );
    $_ssStmt->execute([$courseId]);
    foreach ($_ssStmt->fetchAll() as $_sub) {
        $slotSubmissions[(int) $_sub['item_id']][] = $_sub;
    }
} else {
    $_myStmt = $_db->prepare(
        "SELECT * FROM course_submissions WHERE course_id = ? AND user_id = ?"
    );
    $_myStmt->execute([$courseId, (int) $_me['id']]);
    foreach ($_myStmt->fetchAll() as $_sub) {
        $mySubmissions[(int) $_sub['item_id']] = $_sub;
    }
}

// Review annotations grouped by submission id
$submissionAnnotations = []; // submission_id → [ annotations ]
try {
    if (portal_can_manage_course($courseId)) {
        $_anStmt = $_db->prepare(
            "SELECT a.*, u.name AS author_name
             FROM course_submission_annotations a
             LEFT JOIN users u ON u.id = a.author_id
             WHERE a.course_id = ?
             ORDER BY a.id ASC"
        );
        $_anStmt->execute([$courseId]);
    } else {
        $_anStmt = $_db->prepare(
            "SELECT a.*, u.name AS author_name
             FROM course_submission_annotations a
             LEFT JOIN users u ON u.id = a.author_id
             JOIN course_submissions cs ON cs.id = a.submission_id
             WHERE a.course_id = ? AND cs.user_id = ?
             ORDER BY a.id ASC"
        );
        $_anStmt->execute([$courseId, (int) $_me['id']]);
    }
    foreach ($_anStmt->fetchAll() as $_an) {
        $submissionAnnotations[(int) $_an['submission_id']][] = $_an;
    }
} catch (\PDOException $e) {
    $submissionAnnotations = [];
}

if (portal_can_manage_course($courseId)) {
    $_gradeStmt = $_db->prepare(
        "SELECT cs.*, cfi.title AS slot_title, cfi.submission_deadline, cfi.submission_weight,
                u.name AS student_name, u.initials AS student_initials
         FROM course_submissions cs
         JOIN course_folder_items cfi ON cfi.id = cs.item_id
         JOIN users u ON u.id = cs.user_id
         WHERE cs.course_id = ?
         ORDER BY cfi.title ASC, u.name ASC"
    );
    $_gradeStmt->execute([$courseId]);
} else {
    $_gradeStmt = $_db->prepare(
        "SELECT cs.*, cfi.title AS slot_title, cfi.submission_deadline, cfi.submission_weight
         FROM course_submissions cs
         JOIN course_folder_items cfi ON cfi.id = cs.item_id
         WHERE cs.course_id = ? AND cs.user_id = ?
         ORDER BY cs.submitted_at DESC"
    );
    $_gradeStmt->execute([$courseId, (int) $_me['id']]);
}
$submissionGradebook = $_gradeStmt->fetchAll();

// Activities and quizzes share the course gradebook with uploaded assignments.
// Students only receive rows after the teacher has explicitly released them.
if (!portal_can_manage_course($courseId)) {
    foreach (portal_activity_gradebook_rows($courseId, (int) $_me['id']) as $_activityGrade) {
        $submissionGradebook[] = [
            'id' => (int) ($_activityGrade['attempt_id'] ?? 0),
            'item_id' => 0,
            'score' => $_activityGrade['score'],
            'marked_at' => (string) ($_activityGrade['marked_at'] ?? ''),
            'submitted_at' => (string) ($_activityGrade['submitted_at'] ?? ''),
            'slot_title' => (string) ($_activityGrade['title'] ?? 'Activity'),
            'submission_deadline' => '',
            'submission_weight' => $_activityGrade['submission_weight'] ?? 100,
            'grade_source' => 'activity',
            'activity_id' => (int) ($_activityGrade['activity_id'] ?? 0),
            'attempt_id' => (int) ($_activityGrade['attempt_id'] ?? 0),
        ];
    }
}

$submissionVersions = [];
if (portal_can_manage_course($courseId)) {
    $_versionStmt = $_db->prepare(
        "SELECT csv.*, u.name AS student_name, u.initials AS student_initials
         FROM course_submission_versions csv
         JOIN users u ON u.id = csv.user_id
         WHERE csv.course_id = ?
         ORDER BY csv.submitted_at DESC"
    );
    $_versionStmt->execute([$courseId]);
} else {
    $_versionStmt = $_db->prepare(
        "SELECT * FROM course_submission_versions
         WHERE course_id = ? AND user_id = ?
         ORDER BY submitted_at DESC"
    );
    $_versionStmt->execute([$courseId, (int) $_me['id']]);
}
foreach ($_versionStmt->fetchAll() as $_version) {
    $submissionVersions[(int) $_version['submission_id']][] = $_version;
}

// Class schedule
$_schStmt = $_db->prepare(
    "SELECT * FROM course_schedule WHERE course_id = ? ORDER BY sort_order ASC, id ASC"
);
$_schStmt->execute([$courseId]);
$courseSchedule = $_schStmt->fetchAll();
$courseScheduleSummary = portal_format_course_schedule_summary(
    $courseSchedule,
    (string) ($course['meeting'] ?? ''),
    (string) ($course['room'] ?? '')
);
$course['meeting'] = $courseScheduleSummary['meeting'];
$course['room'] = $courseScheduleSummary['room'];
$course['location_mode'] = $courseScheduleSummary['mode'];
$courseUpcomingEvents = portal_events_for_course($courseId, 20, true);

// Discussion topics (with reply count)
$_topicId = (int) ($_GET['topic'] ?? 0);
$_dtStmt = $_db->prepare(
    "SELECT dt.*, u.name AS author_name, u.initials AS author_initials,
            (SELECT COUNT(*) FROM course_discussion_replies r WHERE r.topic_id = dt.id) AS reply_count
     FROM course_discussion_topics dt
     JOIN users u ON u.id = dt.user_id
     WHERE dt.course_id = ?
     ORDER BY dt.created_at DESC"
);
$_dtStmt->execute([$courseId]);
$dbTopics = $_dtStmt->fetchAll();

$dbCurrentTopic = null;
$dbReplies      = [];
if ($_topicId > 0) {
    $_ctStmt = $_db->prepare(
        "SELECT dt.*, u.name AS author_name, u.initials AS author_initials
         FROM course_discussion_topics dt
         JOIN users u ON u.id = dt.user_id
         WHERE dt.id = ? AND dt.course_id = ?"
    );
    $_ctStmt->execute([$_topicId, $courseId]);
    $dbCurrentTopic = $_ctStmt->fetch() ?: null;

    if ($dbCurrentTopic) {
        $_rStmt = $_db->prepare(
            "SELECT dr.*, u.name AS author_name, u.initials AS author_initials
             FROM course_discussion_replies dr
             JOIN users u ON u.id = dr.user_id
             WHERE dr.topic_id = ?
             ORDER BY dr.created_at ASC"
        );
        $_rStmt->execute([$_topicId]);
        $dbReplies = $_rStmt->fetchAll();
    }
}

// Groups (with member count and current user's membership)
$_grpStmt = $_db->prepare(
    "SELECT cg.*,
            (SELECT COUNT(*) FROM course_group_members m WHERE m.group_id = cg.id) AS member_count
     FROM course_groups cg
     WHERE cg.course_id = ?
     ORDER BY cg.id ASC"
);
$_grpStmt->execute([$courseId]);
$dbGroups = $_grpStmt->fetchAll();

// Current user's group memberships for this course
$_myGrpStmt = $_db->prepare(
    "SELECT cgm.group_id FROM course_group_members cgm
     JOIN course_groups cg ON cg.id = cgm.group_id
     WHERE cg.course_id = ? AND cgm.user_id = ?"
);
$_myGrpStmt->execute([$courseId, (int)$_me['id']]);
$myGroupIds = array_map('intval', $_myGrpStmt->fetchAll(PDO::FETCH_COLUMN));

// Group members per group (for teacher view)
$groupMembers = [];
if (portal_can_manage_course($courseId)) {
    $_gmStmt = $_db->prepare(
        "SELECT cgm.group_id, u.name, u.initials
         FROM course_group_members cgm
         JOIN users u ON u.id = cgm.user_id
         JOIN course_groups cg ON cg.id = cgm.group_id
         WHERE cg.course_id = ?
         ORDER BY u.name ASC"
    );
    $_gmStmt->execute([$courseId]);
    foreach ($_gmStmt->fetchAll() as $_gm) {
        $groupMembers[(int)$_gm['group_id']][] = $_gm;
    }
}

// Tab visibility settings
$_enabledKeys = portal_enabled_tab_keys($courseId);

$requestedSection = (string) ($_GET['section'] ?? 'content');
$_allTabDefs      = portal_course_tab_definitions($course);

// Filter by enabled tabs
if ($_enabledKeys !== null) {
    if (!in_array('content', $_enabledKeys)) {
        $_enabledKeys[] = 'content';
    }
    $_allTabDefs = array_values(
        array_filter($_allTabDefs, fn($t) => in_array($t['key'], $_enabledKeys))
    );
}
$validSections = array_column($_allTabDefs, 'key');
$sectionKey    = in_array($requestedSection, $validSections, true) ? $requestedSection : 'content';
$tabs          = portal_course_tab_definitions($course, $sectionKey);
if ($_enabledKeys !== null) {
    $tabs = array_values(array_filter($tabs, fn($t) => in_array($t['key'], $_enabledKeys)));
}

// Auto-mark announcements as read when the user opens the Announcements tab
if ($sectionKey === 'announcements' && !empty($unreadAnnouncements)) {
    $_ins = $_db->prepare("INSERT OR IGNORE INTO announcement_reads (user_id, announcement_id) VALUES (?,?)");
    foreach ($unreadAnnouncements as $_ua) {
        $_ins->execute([(int) $_me['id'], (int) $_ua['id']]);
    }
    $unreadAnnouncements = [];
}

// When a student opens Grades (or a deep-linked returned review), clear those
// marks from the dashboard "Returned grades" queue.
if (!portal_can_manage_course($courseId)) {
    $_activityGradeBadgeStmt = $_db->prepare(
        "SELECT COUNT(*)
         FROM activity_attempts
         WHERE course_id = ? AND user_id = ?
           AND status = 'released'
           AND (grade_seen_at = '' OR grade_seen_at IS NULL)"
    );
    $_activityGradeBadgeStmt->execute([$courseId, (int) $_me['id']]);
    $unreadCourseGradeCount = (int) $_activityGradeBadgeStmt->fetchColumn();

    $openReviewRaw = (string) ($_GET['open_review'] ?? '');
    if (preg_match('/^rvw-(\d+)$/', $openReviewRaw, $openReviewMatch)) {
        $_db->prepare(
            "UPDATE course_submissions
             SET grade_seen_at = datetime('now')
             WHERE id = ? AND course_id = ? AND user_id = ?
               AND marked_at != ''
               AND (grade_seen_at = '' OR grade_seen_at IS NULL)"
        )->execute([(int) $openReviewMatch[1], $courseId, (int) $_me['id']]);
    }
    if ($sectionKey === 'gradebook') {
        $_db->prepare(
            "UPDATE course_submissions
             SET grade_seen_at = datetime('now')
             WHERE course_id = ? AND user_id = ?
               AND marked_at != ''
               AND score IS NOT NULL
               AND (grade_seen_at = '' OR grade_seen_at IS NULL)"
        )->execute([$courseId, (int) $_me['id']]);
        $_db->prepare(
            "UPDATE activity_attempts
             SET grade_seen_at = datetime('now')
             WHERE course_id = ? AND user_id = ?
               AND status = 'released'
               AND (grade_seen_at = '' OR grade_seen_at IS NULL)"
        )->execute([$courseId, (int) $_me['id']]);
        $unreadCourseGradeCount = 0;
    }
}

// Live badge counts — show only unread count
foreach ($tabs as &$_tab) {
    if ($_tab['key'] === 'announcements') {
        $_tab['badge'] = count($unreadAnnouncements);
    } elseif ($_tab['key'] === 'gradebook' && !portal_can_manage_course($courseId)) {
        $_tab['badge'] = (int) ($unreadCourseGradeCount ?? 0);
    }
}
unset($_tab);

$currentSection = $tabs[0];

foreach ($tabs as $tab) {
    if ($tab['key'] === $sectionKey) {
        $currentSection = $tab;
        break;
    }
}

$tabLookup = [];

foreach ($tabs as $tab) {
    $tabLookup[$tab['key']] = $tab['href'];
}

$page_title = $course['title'] . ' - ' . $currentSection['label'] . ' | ' . portal_school_name();
$active_page = 'courses';
$page_eyebrow = 'Course section';
$page_heading = $course['title'];
$page_description = $currentSection['label'] . ' | ' . $course['full_title'] . ' | ' . $course['meeting'];

ob_start();
?>
<section class="course-detail-page">
    <?php if (is_array($courseFlash) && isset($courseFlash[0], $courseFlash[1])): ?>
        <div class="admin-flash <?= $courseFlash[0] === 'success' ? 'success' : 'error' ?>" style="margin-bottom:12px;">
            <?php if ($courseFlash[0] === 'success'): ?>
                <span><?= portal_escape((string) $courseFlash[1]) ?></span>
            <?php else: ?>
                <?= portal_icon('lock', 'admin-flash-icon') ?>
                <span><?= portal_escape((string) $courseFlash[1]) ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <article class="course-hero-banner" style="--course-accent: <?= portal_escape($course['accent']) ?>;">
        <div class="course-hero-top">
            <a class="course-breadcrumb" href="courses.php">All courses</a>
            <span class="course-status-pill<?= $course['status'] === 'open' ? ' active' : '' ?>"><?= portal_escape($course['status_label']) ?></span>
        </div>

        <p class="course-list-code"><?= portal_escape($course['code']) ?></p>
        <h3><?= portal_escape($course['full_title']) ?></h3>

        <div class="course-hero-desc-row">
            <p><?= portal_escape($course['summary']) ?></p>
            <?php if (portal_can_manage_course($courseId)): ?>
            <button type="button"
                    class="settings-toggle course-desc-edit-btn"
                    data-settings-target="course-desc-form"
                    title="Edit description"
                    aria-label="Edit course description">
                <?= portal_icon('edit', 'icon-sm') ?>
            </button>
            <?php endif; ?>
        </div>

        <?php if (portal_can_manage_course($courseId)): ?>
        <div class="settings-panel course-desc-panel" id="course-desc-form" hidden>
            <form method="POST" class="folder-admin-form">
                <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                <input type="hidden" name="action" value="update_course_description">
                <input type="hidden" name="return_section" value="<?= portal_escape($sectionKey) ?>">
                <label class="folder-form-label">
                    <span>Course description</span>
                    <textarea name="summary" required maxlength="500" rows="3"
                              class="course-desc-textarea"><?= portal_escape($course['summary']) ?></textarea>
                </label>
                <div class="button-row">
                    <button type="submit" class="button button--sm">Save</button>
                    <button type="button" class="button-secondary button--sm settings-toggle"
                            data-settings-target="course-desc-form">Cancel</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php
            $heroMetaLine = array_values(array_filter([
                trim((string) ($course['term'] ?? '')),
                trim((string) ($course['meeting'] ?? '')),
                trim((string) ($course['room'] ?? '')),
                ((int) ($course['student_count'] ?? 0)) . ' students',
            ], static fn(string $part): bool => $part !== ''));
        ?>
        <p class="course-hero-meta-line"><?= portal_escape(implode(' · ', $heroMetaLine)) ?></p>
        <div class="course-hero-meta">
            <span><?= portal_escape($currentSection['label']) ?></span>
            <span><?= portal_escape($course['term']) ?></span>
            <span><?= portal_escape($course['meeting']) ?></span>
            <span><?= portal_escape($course['room']) ?></span>
            <span><?= (int) $course['student_count'] ?> students</span>
        </div>
    </article>

    <nav class="course-subnav" aria-label="Course sections">
        <?php foreach ($tabs as $tab): ?>
            <a class="course-tab<?= !empty($tab['active']) ? ' active' : '' ?>" href="<?= portal_escape($tab['href']) ?>">
                <span><?= portal_escape($tab['label']) ?></span>
                <?php if (!empty($tab['badge'])): ?>
                    <span class="course-tab-badge"><?= (int) $tab['badge'] ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
        <?php if (portal_can_manage_course($courseId)): ?>
            <button class="course-tab course-tab--settings" id="tab-settings-btn" type="button" aria-expanded="false" aria-controls="tab-settings-panel">
                <?= portal_icon('settings', 'nav-icon') ?>
            </button>
        <?php endif; ?>
    </nav>

    <?php if (portal_can_manage_course($courseId)): ?>
    <div class="tab-settings-panel" id="tab-settings-panel" hidden>
        <form method="POST" class="tab-settings-form">
            <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
            <input type="hidden" name="action" value="save_tab_settings">
            <span class="tab-settings-label">Visible sections:</span>
            <?php
            $allTabMeta = [
                'content'       => 'Content',
                'calendar'      => 'Calendar',
                'announcements' => 'Announcements',
                'discussions'   => 'Discussions',
                'gradebook'     => 'Grades',
                'groups'        => 'Groups',
            ];
            ?>
            <?php foreach ($allTabMeta as $tKey => $tLabel): ?>
                <?php $isOn = $_enabledKeys === null || in_array($tKey, $_enabledKeys); ?>
                <label class="tab-toggle<?= $tKey === 'content' ? ' tab-toggle--locked' : '' ?>">
                    <input type="checkbox" name="tab_keys[]" value="<?= portal_escape($tKey) ?>"
                        <?= $isOn ? 'checked' : '' ?>
                        <?= $tKey === 'content' ? 'disabled' : '' ?>>
                    <?= portal_escape($tLabel) ?>
                </label>
            <?php endforeach; ?>
            <button type="submit" class="button button--sm">Save</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="course-detail-layout">
        <div class="stack course-main-stack<?= $sectionKey === 'content' ? ' course-main-stack--content' : '' ?>">
            <?php if ($sectionKey === 'content'): ?>
                <?php if (portal_can_manage_course($courseId)): ?>
                    <details class="folder-admin-panel">
                        <summary class="folder-admin-trigger">
                            <?= portal_icon('plus', 'icon-sm') ?>
                            <span>New folder</span>
                        </summary>
                        <form method="POST" class="folder-admin-form">
                            <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                            <input type="hidden" name="action" value="create_folder">
                            <div class="folder-form-row">
                                <label class="folder-form-label">
                                    <span>Folder name</span>
                                    <input type="text" name="title" required maxlength="200" placeholder="e.g. Assessment 1">
                                </label>
                                <label class="folder-form-label">
                                    <span>Description <small>(optional)</small></span>
                                    <input type="text" name="description" maxlength="500" placeholder="Brief description shown under the folder name">
                                </label>
                            </div>
                            <label class="folder-form-label folder-form-check">
                                <input type="checkbox" name="locked" value="1">
                                Lock this folder <small style="font-weight:400">(students can see the folder, but not its contents)</small>
                            </label>
                            <button type="submit" class="button">Create folder</button>
                        </form>
                    </details>
                <?php endif; ?>

                <?php if (empty($courseFolders)): ?>
                    <?php if (portal_can_manage_course($courseId)): ?>
                        <article class="folder-empty-state">
                            <?= portal_icon('folder', 'folder-empty-icon') ?>
                            <p>No folders yet. Create the first folder above to organise course materials.</p>
                        </article>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="folder-stack" id="folder-stack">
                    <?php foreach ($courseFolders as $folder): ?>
                        <?php $folderLocked = !empty($folder['locked']); ?>
                        <div class="folder-row" data-folder-id="<?= (int) $folder['id'] ?>">
                        <?php if (portal_can_manage_course($courseId)): ?>
                            <div class="course-move-btns" data-course-move="folder">
                                <button type="button" class="course-move-btn" data-course-move-dir="up" aria-label="Move folder up" title="Move up">
                                    <?= portal_icon('chevron-up', 'icon-sm') ?>
                                </button>
                                <button type="button" class="course-move-btn" data-course-move-dir="down" aria-label="Move folder down" title="Move down">
                                    <?= portal_icon('chevron-down', 'icon-sm') ?>
                                </button>
                            </div>
                        <?php endif; ?>
                        <details class="folder-card<?= portal_can_manage_course($courseId) ? ' folder-card--managed' : '' ?><?= $folderLocked ? ' folder-card--locked' : '' ?>">
                            <summary class="folder-summary">
                                <?php if (portal_can_manage_course($courseId)): ?>
                                    <span class="folder-drag-handle" title="Drag to reorder">
                                        <?= portal_icon('grip', 'grip-icon') ?>
                                    </span>
                                <?php endif; ?>
                                <span class="folder-status-dot"></span>
                                <?= portal_icon('folder', 'folder-icon') ?>
                                <div class="folder-info">
                                    <h3><?= portal_escape($folder['title']) ?></h3>
                                    <?php if ($folder['description'] !== ''): ?>
                                        <p><?= portal_escape($folder['description']) ?></p>
                                    <?php endif; ?>
                                    <?php if ($folderLocked): ?>
                                        <span class="folder-lock-badge">Locked</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (portal_can_manage_course($courseId)): ?>
                                    <button type="button"
                                            class="folder-lock-toggle<?= $folderLocked ? ' is-locked' : '' ?>"
                                            data-folder-id="<?= (int)$folder['id'] ?>"
                                            title="<?= $folderLocked ? 'Unlock folder' : 'Lock folder' ?>"
                                            aria-label="<?= $folderLocked ? 'Unlock folder' : 'Lock folder' ?>">
                                        <?= portal_icon('lock', 'icon-sm') ?>
                                    </button>
                                    <button type="button"
                                            class="folder-settings-button settings-toggle"
                                            data-settings-target="folder-settings-<?= (int)$folder['id'] ?>"
                                            aria-label="Folder settings">
                                        <?= portal_icon('settings', 'icon-sm') ?>
                                    </button>
                                <?php endif; ?>
                                <?= portal_icon('chevron-down', 'folder-chevron') ?>
                            </summary>

                            <div class="folder-body">
                                <?php if (portal_can_manage_course($courseId)): ?>
                                    <div class="settings-panel folder-settings-panel" id="folder-settings-<?= (int)$folder['id'] ?>" hidden>
                                        <form method="POST" class="folder-admin-form folder-admin-form--inner">
                                            <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                            <input type="hidden" name="action" value="update_folder_settings">
                                            <input type="hidden" name="folder_id" value="<?= (int)$folder['id'] ?>">
                                            <div class="folder-form-row">
                                                <label class="folder-form-label">
                                                    <span>Folder name</span>
                                                    <input type="text" name="title" required maxlength="200" value="<?= portal_escape($folder['title']) ?>">
                                                </label>
                                                <label class="folder-form-label">
                                                    <span>Description <small>(optional)</small></span>
                                                    <input type="text" name="description" maxlength="500" value="<?= portal_escape($folder['description']) ?>">
                                                </label>
                                            </div>
                                            <label class="folder-form-label folder-form-check">
                                                <input type="checkbox" name="locked" value="1" <?= $folderLocked ? 'checked' : '' ?>>
                                                Lock folder <small style="font-weight:400">(students cannot open the contents)</small>
                                            </label>
                                            <button type="submit" class="button button--sm">Save folder settings</button>
                                        </form>
                                    </div>
                                <?php endif; ?>

                                <?php if ($folderLocked && !portal_can_manage_course($courseId)): ?>
                                    <p class="folder-locked-note">This folder is locked by your teacher.</p>
                                <?php elseif (portal_can_manage_course($courseId) || !empty($folder['items'])): ?>
                                    <div class="folder-items" data-folder-id="<?= (int) $folder['id'] ?>">
                                        <?php foreach ($folder['items'] as $item): ?>
                                            <?php
                                                $itemFileName = $item['file_name'] !== '' ? $item['file_name'] : $item['file_path'];
                                                $isPresentation = $item['type'] === 'document' && portal_is_presentation_file((string) $itemFileName);
                                                $itemKindClass = $isPresentation ? 'presentation' : $item['type'];
                                                $itemKindLabel = $isPresentation
                                                    ? 'Presentation'
                                                    : ($item['type'] === 'submission' ? 'Submission slot'
                                                        : ($item['type'] === 'activity' ? 'Activity' : ucfirst($item['type'])));
                                                $itemLocked = !empty($item['locked']);
                                                $itemExternalUrl = (!$itemLocked && $item['type'] === 'link')
                                                    ? portal_course_normalize_external_url((string) $item['url'])
                                                    : '';
                                                $activityRow = null;
                                                $activitySummary = null;
                                                if ($item['type'] === 'activity') {
                                                    $activityRow = portal_activity_find_by_item((int) $item['id']);
                                                    // Draft, archived, and orphaned activity slots are staff-only.
                                                    // Do not leak their title or even their existence to students.
                                                    if (!portal_can_manage_course($courseId)
                                                        && (!$activityRow || (string) ($activityRow['status'] ?? '') !== 'published')) {
                                                        continue;
                                                    }
                                                    if ($activityRow) {
                                                        $activitySummary = portal_activity_student_card_summary(
                                                            $activityRow,
                                                            (int) (portal_current_user()['id'] ?? 0)
                                                        );
                                                        $itemKindLabel = portal_activity_mode_label((string) $activityRow['mode']);
                                                    }
                                                }
                                            ?>
                                            <div class="folder-item folder-item--<?= portal_escape($itemKindClass) ?><?= portal_can_manage_course($courseId) ? ' folder-item--managed' : '' ?><?= ($itemLocked && !portal_can_manage_course($courseId)) ? ' folder-item--student-locked' : '' ?><?= $itemExternalUrl !== '' ? ' folder-item--external-link' : '' ?>"
                                                 data-item-id="<?= (int) $item['id'] ?>"
                                                 data-folder-id="<?= (int) $folder['id'] ?>"
                                                 <?php if ($itemExternalUrl !== ''): ?>
                                                 data-safe-external-link="1"
                                                 data-safe-url="<?= portal_escape($itemExternalUrl) ?>"
                                                 role="link"
                                                 tabindex="0"
                                                 <?php endif; ?>>
                                                <?php if (portal_can_manage_course($courseId)): ?>
                                                    <span class="item-drag-handle" title="Drag to reorder">
                                                        <?= portal_icon('grip', 'grip-icon') ?>
                                                    </span>
                                                    <div class="course-move-btns" data-course-move="item">
                                                        <button type="button" class="course-move-btn" data-course-move-dir="up" aria-label="Move item up" title="Move up">
                                                            <?= portal_icon('chevron-up', 'icon-sm') ?>
                                                        </button>
                                                        <button type="button" class="course-move-btn" data-course-move-dir="down" aria-label="Move item down" title="Move down">
                                                            <?= portal_icon('chevron-down', 'icon-sm') ?>
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($isPresentation): ?>
                                                    <?= portal_icon('presentation', 'item-type-icon') ?>
                                                <?php elseif ($item['type'] === 'document'): ?>
                                                    <?= portal_icon('file', 'item-type-icon') ?>
                                                <?php elseif ($item['type'] === 'video'): ?>
                                                    <?= portal_icon('video', 'item-type-icon') ?>
                                                <?php elseif ($item['type'] === 'link'): ?>
                                                    <?= portal_icon('link', 'item-type-icon') ?>
                                                <?php elseif ($item['type'] === 'activity'): ?>
                                                    <?= portal_icon('activity', 'item-type-icon') ?>
                                                <?php else: ?>
                                                    <?= portal_icon('upload', 'item-type-icon') ?>
                                                <?php endif; ?>

                                                <div class="folder-item-info">
                                                    <?php if ($itemLocked && !portal_can_manage_course($courseId)): ?>
                                                        <div class="item-locked-state">
                                                            <span class="item-locked-title"><?= portal_escape($item['title']) ?></span>
                                                            <span class="item-locked-badge">
                                                                <?= portal_icon('lock', 'icon-xs') ?>
                                                                Locked
                                                            </span>
                                                        </div>
                                                    <?php else: ?>
                                                    <?php if ($item['file_path'] !== ''): ?>
                                                        <?php
                                                            $fExt  = strtolower(pathinfo((string) $itemFileName, PATHINFO_EXTENSION));
                                                            $canDl = !portal_can_manage_course($courseId) && !empty($item['allow_download']);
                                                            $isVideoItem = $item['type'] === 'video';
                                                            $itemViewerUrl = $isVideoItem
                                                                ? 'lesson-viewer.php?item=' . (int) $item['id']
                                                                : 'view.php?item=' . (int) $item['id'];
                                                        ?>
                                                        <div class="file-item-row">
                                                            <a href="<?= portal_escape($itemViewerUrl) ?>"
                                                               class="file-view-link"
                                                               <?php if ($isVideoItem): ?>target="_blank"<?php else: ?>data-doc-viewer="1"<?php endif; ?>>
                                                                <?= portal_icon($isVideoItem ? 'video' : ($isPresentation ? 'presentation' : 'file'), 'icon-xs') ?>
                                                                <?= portal_escape($item['title']) ?>
                                                                <span class="file-ext-badge"><?= portal_escape(strtoupper($fExt)) ?></span>
                                                            </a>
                                                            <?php if ($canDl): ?>
                                                            <a href="download.php?item=<?= (int)$item['id'] ?>" class="btn-file-dl" title="Download" download>
                                                                <?= portal_icon('download', 'icon-xs') ?>
                                                            </a>
                                                            <?php endif; ?>
                                                            <?php if (portal_can_manage_course($courseId)): ?>
                                                            <button type="button"
                                                                    class="btn-dl-toggle<?= !empty($item['allow_download']) ? ' is-enabled' : '' ?>"
                                                                    data-item-id="<?= (int)$item['id'] ?>"
                                                                    title="<?= !empty($item['allow_download']) ? 'Students can download — click to disable' : 'Students cannot download — click to enable' ?>">
                                                                <?= portal_icon('download', 'icon-xs') ?>
                                                            </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php elseif ($item['type'] === 'video' && $item['url'] !== ''): ?>
                                                        <?php $itemVideoMeta = portal_parse_external_video_url((string) $item['url']); ?>
                                                        <div class="file-item-row">
                                                            <a href="lesson-viewer.php?item=<?= (int) $item['id'] ?>" class="file-view-link" target="_blank">
                                                                <?= portal_icon('video', 'icon-xs') ?>
                                                                <?= portal_escape($item['title']) ?>
                                                                <span class="file-ext-badge"><?= portal_escape($itemVideoMeta['label'] ?? 'Video') ?></span>
                                                            </a>
                                                        </div>
                                                    <?php elseif ($item['url'] !== ''): ?>
                                                        <a class="item-url-link"
                                                           href="<?= portal_escape($itemExternalUrl !== '' ? $itemExternalUrl : (string) $item['url']) ?>"
                                                           target="_blank"
                                                           rel="noopener noreferrer"
                                                           data-safe-external-link="1"
                                                           data-safe-url="<?= portal_escape($itemExternalUrl !== '' ? $itemExternalUrl : (string) $item['url']) ?>">
                                                            <?= portal_icon('link', 'icon-xs') ?>
                                                            <?= portal_escape($item['title']) ?>
                                                        </a>
                                                    <?php elseif ($item['type'] === 'activity' && $activityRow): ?>
                                                        <a class="file-view-link" href="activity.php?id=<?= (int) $activityRow['id'] ?>">
                                                            <?= portal_icon('activity', 'icon-xs') ?>
                                                            <?= portal_escape((string) ($activityRow['title'] ?: $item['title'])) ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <strong><?= portal_escape($item['title']) ?></strong>
                                                    <?php endif; ?>
                                                    <?php if ($item['description'] !== ''): ?>
                                                        <p><?= portal_escape($item['description']) ?></p>
                                                    <?php endif; ?>
                                                    <?php if ($item['type'] === 'activity' && $activityRow && $activitySummary): ?>
                                                        <?php $activityCanManage = portal_can_manage_course($courseId); ?>
                                                        <div class="activity-slot-card">
                                                            <div class="activity-slot-row">
                                                                <div class="activity-slot-meta">
                                                                    <span class="activity-mode-pill activity-mode-pill--<?= portal_escape((string) $activitySummary['mode']) ?>">
                                                                        <?= portal_escape((string) $activitySummary['mode_label']) ?>
                                                                    </span>
                                                                    <?php if ($activityCanManage): ?>
                                                                        <?php $activityStatusKey = (string) ($activitySummary['status'] ?? 'draft'); ?>
                                                                        <span class="activity-status activity-status--<?= portal_escape($activityStatusKey === 'published' ? 'completed' : ($activityStatusKey === 'closed' ? 'submitted' : 'not-started')) ?>">
                                                                            <?= portal_escape(ucfirst($activityStatusKey)) ?>
                                                                        </span>
                                                                    <?php else: ?>
                                                                        <span class="activity-status activity-status--<?= portal_escape(str_replace('_', '-', (string) ($activitySummary['student_status'] ?? 'not-started'))) ?>">
                                                                            <?= portal_escape(ucwords(str_replace('_', ' ', (string) ($activitySummary['student_status'] ?? 'not_started')))) ?>
                                                                        </span>
                                                                    <?php endif; ?>
                                                                    <?php if (!empty($activitySummary['estimated_minutes'])): ?>
                                                                        <span class="activity-slot-note"><?= (int) $activitySummary['estimated_minutes'] ?> min</span>
                                                                    <?php endif; ?>
                                                                    <?php if ($activitySummary['attempts_remaining'] !== null): ?>
                                                                        <span class="activity-slot-note"><?= (int) $activitySummary['attempts_remaining'] ?> left</span>
                                                                    <?php endif; ?>
                                                                    <?php if (!$activityCanManage && $activitySummary['best_percentage'] !== null): ?>
                                                                        <span class="activity-slot-note activity-slot-note--score"><?= portal_escape((string) round((float) $activitySummary['best_percentage'], 1)) ?>%</span>
                                                                    <?php endif; ?>
                                                                    <?php if (!$activityCanManage && !empty($activitySummary['xp_enabled']) && (int) ($activitySummary['xp_amount'] ?? 0) > 0): ?>
                                                                        <span class="activity-slot-note activity-slot-note--xp">+<?= (int) $activitySummary['xp_amount'] ?> XP</span>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <div class="activity-slot-links">
                                                                    <?php if ($activityCanManage): ?>
                                                                        <a class="activity-slot-cta" href="activity-results.php?id=<?= (int) $activityRow['id'] ?>">Submissions</a>
                                                                        <a href="activity-builder.php?id=<?= (int) $activityRow['id'] ?>">Edit</a>
                                                                        <a href="activity.php?id=<?= (int) $activityRow['id'] ?>">Preview</a>
                                                                    <?php elseif ($activitySummary['in_progress_attempt_id']): ?>
                                                                        <a class="activity-slot-cta" href="activity.php?id=<?= (int) $activityRow['id'] ?>&amp;resume=1">Resume</a>
                                                                    <?php elseif ($activitySummary['can_start']): ?>
                                                                        <a class="activity-slot-cta" href="activity.php?id=<?= (int) $activityRow['id'] ?>">Start</a>
                                                                    <?php elseif (in_array($activitySummary['student_status'] ?? '', ['submitted', 'completed', 'awaiting_marking'], true)): ?>
                                                                        <a href="activity.php?id=<?= (int) $activityRow['id'] ?>"><?= portal_escape((string) ($activitySummary['primary_action'] ?? 'View')) ?></a>
                                                                    <?php else: ?>
                                                                        <span class="activity-slot-disabled"><?= portal_escape((string) ($activitySummary['primary_action'] ?? 'Unavailable')) ?></span>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php
                                                        $openQs = ($item['type'] === 'video')
                                                            ? (int) ($videoOpenQuestionCounts[(int) $item['id']] ?? 0)
                                                            : 0;
                                                    ?>
                                                    <?php if ($item['type'] !== 'submission' && $item['type'] !== 'activity' || $openQs > 0): ?>
                                                    <div class="folder-item-meta">
                                                        <?php if ($item['type'] !== 'submission' && $item['type'] !== 'activity'): ?>
                                                        <span class="item-type-badge item-type-badge--<?= portal_escape($itemKindClass) ?>">
                                                            <?= portal_escape($itemKindLabel) ?>
                                                        </span>
                                                        <?php endif; ?>
                                                        <?php if ($openQs > 0 && portal_can_manage_course($courseId)): ?>
                                                        <a class="video-open-q-badge" href="lesson-viewer.php?item=<?= (int) $item['id'] ?>#qa-open" title="Review open student questions">
                                                            <?= portal_icon('megaphone', 'icon-xs') ?>
                                                            <?= $openQs ?> open
                                                        </a>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php endif; ?>

                                                    <?php if (portal_can_manage_course($courseId) && $item['type'] !== 'submission' && $item['type'] !== 'activity'): ?>
                                                        <?php
                                                            $itemDeadlineValue = $item['submission_deadline'] !== ''
                                                                ? date('Y-m-d\TH:i', strtotime((string) $item['submission_deadline']))
                                                                : '';
                                                        ?>
                                                        <div class="settings-panel item-settings-panel" id="item-settings-<?= (int)$item['id'] ?>" hidden>
                                                            <form method="POST" class="folder-admin-form folder-admin-form--inner item-settings-form">
                                                                <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                                                <input type="hidden" name="action" value="update_item_settings">
                                                                <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                                                                <div class="folder-form-row">
                                                                    <label class="folder-form-label">
                                                                        <span>Title</span>
                                                                        <input type="text" name="title" required maxlength="200" value="<?= portal_escape($item['title']) ?>">
                                                                    </label>
                                                                    <label class="folder-form-label">
                                                                        <span>Description <small>(optional)</small></span>
                                                                        <input type="text" name="description" maxlength="500" value="<?= portal_escape($item['description']) ?>">
                                                                    </label>
                                                                </div>
                                                                <?php if ($item['type'] === 'link' || ($item['type'] === 'document' && $item['file_path'] === '')): ?>
                                                                    <label class="folder-form-label">
                                                                        <span>URL</span>
                                                                        <input type="text" inputmode="url" autocomplete="url" name="url" maxlength="2000" value="<?= portal_escape($item['url']) ?>" placeholder="https://... or www.example.com">
                                                                    </label>
                                                                <?php elseif ($item['type'] === 'video' && $item['file_path'] === ''): ?>
                                                                    <label class="folder-form-label">
                                                                        <span>Video link <small>(YouTube or Vimeo only)</small></span>
                                                                        <input type="text" inputmode="url" autocomplete="url" name="url" maxlength="2000" value="<?= portal_escape($item['url']) ?>" placeholder="https://www.youtube.com/watch?v=...">
                                                                    </label>
                                                                <?php endif; ?>
                                                                <?php if (($item['type'] === 'document' || $item['type'] === 'video') && $item['file_path'] !== ''): ?>
                                                                    <label class="folder-form-label folder-form-check">
                                                                        <input type="checkbox" name="allow_download" value="1" <?= !empty($item['allow_download']) ? 'checked' : '' ?>>
                                                                        Allow students to download this file
                                                                    </label>
                                                                <?php endif; ?>
                                                                <button type="submit" class="button button--sm">Save item settings</button>
                                                            </form>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if ($item['type'] === 'submission'): ?>
                                                        <?php
                                                            $itemDeadlineValue = $item['submission_deadline'] !== ''
                                                                ? date('Y-m-d\TH:i', strtotime((string) $item['submission_deadline']))
                                                                : '';
                                                            $deadlineInfo = portal_submission_deadline_info((string) $item['submission_deadline']);
                                                            $deadlinePassed = $deadlineInfo['passed'];
                                                            $modalId = 'sub-slot-modal-' . (int) $item['id'];
                                                            $slotEditPanelId = 'sub-slot-edit-' . (int) $item['id'];
                                                            $slotMaxAttempts = (int) ($item['submission_max_attempts'] ?? 0);
                                                            $slotWeightLabel = portal_format_submission_weight($item['submission_weight'] ?? 100);
                                                            if (portal_can_manage_course($courseId)) {
                                                                $subs = $slotSubmissions[(int)$item['id']] ?? [];
                                                            } else {
                                                                $mySub = $mySubmissions[(int)$item['id']] ?? null;
                                                                $mySubAttemptsUsed = $mySub ? count($submissionVersions[(int) $mySub['id']] ?? []) : 0;
                                                                $attemptsReached = $slotMaxAttempts > 0 && $mySubAttemptsUsed >= $slotMaxAttempts;
                                                            }
                                                        ?>
                                                        <div class="sub-slot-card"
                                                             data-sub-modal="<?= portal_escape($modalId) ?>"
                                                             data-item-id="<?= (int) $item['id'] ?>"
                                                             role="button"
                                                             tabindex="0"
                                                             aria-label="Open submission details for <?= portal_escape($item['title']) ?>">
                                                            <?= portal_render_submission_deadline((string) $item['submission_deadline']) ?>
                                                            <?php if (portal_can_manage_course($courseId)): ?>
                                                                <div class="sub-slot-card-row sub-slot-card-row--manage">
                                                                    <div class="sub-slot-card-meta-line">
                                                                        <span class="sub-slot-file">
                                                                            <?= portal_icon('upload', 'icon-xs') ?>
                                                                            <span><?= count($subs) ?> submission<?= count($subs) !== 1 ? 's' : '' ?></span>
                                                                        </span>
                                                                        <?php if ($slotMaxAttempts > 0): ?>
                                                                        <span class="sub-slot-attempts-limit">Max <?= $slotMaxAttempts ?> attempt<?= $slotMaxAttempts === 1 ? '' : 's' ?></span>
                                                                        <?php endif; ?>
                                                                        <span class="sub-slot-weight">Weight <?= portal_escape($slotWeightLabel) ?></span>
                                                                    </div>
                                                                    <button type="button"
                                                                            class="button button--sm sub-slot-card-edit"
                                                                            data-sub-open-edit="<?= portal_escape($modalId) ?>"
                                                                            data-sub-open-edit-form="1"
                                                                            title="Edit slot">
                                                                        <?= portal_icon('edit', 'icon-xs') ?>
                                                                        Edit slot
                                                                    </button>
                                                                </div>
                                                            <?php else: ?>
                                                                <div class="sub-slot-card-row" data-sub-card-row>
                                                                    <?php if ($mySub): ?>
                                                                        <span class="sub-slot-file" data-sub-card-file>
                                                                            <?= portal_icon('file', 'icon-xs') ?>
                                                                            <span><?= portal_escape($mySub['filename']) ?></span>
                                                                        </span>
                                                                        <?php if ($mySub['score'] !== null): ?>
                                                                            <span class="sub-slot-status sub-slot-status--graded" data-sub-card-status><?= (int)$mySub['score'] ?>%</span>
                                                                        <?php else: ?>
                                                                            <span class="sub-slot-status sub-slot-status--pending" data-sub-card-status>Not graded</span>
                                                                        <?php endif; ?>
                                                                    <?php else: ?>
                                                                        <span class="sub-slot-file sub-slot-file--empty" data-sub-card-file>
                                                                            <?= portal_icon('upload', 'icon-xs') ?>
                                                                            <span><?= $deadlinePassed ? 'No submission' : 'Not submitted yet' ?></span>
                                                                        </span>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if (!portal_can_manage_course($courseId) && $mySub): ?>
                                                            <p class="sub-slot-card-meta" data-sub-card-meta>
                                                                Submitted <?= portal_escape(date('j M Y H:i', strtotime($mySub['submitted_at']))) ?>
                                                            </p>
                                                            <?php elseif (!portal_can_manage_course($courseId)): ?>
                                                            <p class="sub-slot-card-meta is-hidden" data-sub-card-meta></p>
                                                            <?php endif; ?>
                                                            <?php if (!portal_can_manage_course($courseId) && $slotMaxAttempts > 0): ?>
                                                            <p class="sub-slot-attempts-note" data-sub-attempts-note data-max-attempts="<?= $slotMaxAttempts ?>">
                                                                Attempt <?= min($mySubAttemptsUsed, $slotMaxAttempts) ?> of <?= $slotMaxAttempts ?> used
                                                            </p>
                                                            <?php endif; ?>
                                                        </div>

                                                        <?php ob_start(); ?>
                                                        <div id="<?= portal_escape($modalId) ?>"
                                                             class="sub-slot-overlay"
                                                             data-item-id="<?= (int) $item['id'] ?>"
                                                             hidden
                                                             role="dialog"
                                                             aria-modal="true"
                                                             aria-labelledby="<?= portal_escape($modalId) ?>-title"
                                                             ondragenter="if(window.portalUploadDragOver){window.portalUploadDragOver(event);}else{event.preventDefault();}"
                                                             ondragover="if(window.portalUploadDragOver){window.portalUploadDragOver(event);}else{event.preventDefault();if(event.dataTransfer)event.dataTransfer.dropEffect='copy';}"
                                                             ondrop="if(window.portalUploadDrop){window.portalUploadDrop(event);}else{event.preventDefault();}">
                                                            <div class="sub-slot-dialog"
                                                                 ondragenter="event.preventDefault();"
                                                                 ondragover="event.preventDefault();if(event.dataTransfer)event.dataTransfer.dropEffect='copy';"
                                                                 ondrop="if(window.portalUploadDrop){window.portalUploadDrop(event);}else{event.preventDefault();}">
                                                                <header class="sub-slot-dialog-header">
                                                                    <div class="sub-slot-dialog-heading">
                                                                        <p class="eyebrow">Submission</p>
                                                                        <h3 id="<?= portal_escape($modalId) ?>-title"><?= portal_escape($item['title']) ?></h3>
                                                                        <?= portal_render_submission_deadline((string) $item['submission_deadline'], 'sub-slot-deadline--header') ?>
                                                                        <?php if ($slotMaxAttempts > 0): ?>
                                                                        <p class="sub-slot-attempts-note sub-slot-attempts-note--header" <?= portal_can_manage_course($courseId) ? '' : 'data-sub-attempts-note data-max-attempts="' . $slotMaxAttempts . '"' ?>>
                                                                            <?= portal_can_manage_course($courseId) ? 'Max ' . $slotMaxAttempts . ' attempt' . ($slotMaxAttempts === 1 ? '' : 's') . ' per student' : 'Attempt ' . min($mySubAttemptsUsed, $slotMaxAttempts) . ' of ' . $slotMaxAttempts . ' used' ?>
                                                                        </p>
                                                                        <?php endif; ?>
                                                                        <?php if (portal_can_manage_course($courseId)): ?>
                                                                        <p class="sub-slot-attempts-note sub-slot-attempts-note--header">Weight <?= portal_escape($slotWeightLabel) ?></p>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    <div class="sub-slot-dialog-header-actions">
                                                                        <?php if (portal_can_manage_course($courseId)): ?>
                                                                        <button type="button"
                                                                                class="button button--sm settings-toggle"
                                                                                data-settings-target="<?= portal_escape($slotEditPanelId) ?>"
                                                                                aria-label="Edit slot">
                                                                            <?= portal_icon('edit', 'icon-xs') ?>
                                                                            Edit slot
                                                                        </button>
                                                                        <?php endif; ?>
                                                                        <button type="button" class="sub-slot-dialog-close" aria-label="Close">&times;</button>
                                                                    </div>
                                                                </header>

                                                                <div class="sub-slot-dialog-body">
                                                                <?php if (portal_can_manage_course($courseId)): ?>
                                                                    <div class="settings-panel sub-slot-edit-panel" id="<?= portal_escape($slotEditPanelId) ?>" data-sub-slot-edit-panel hidden>
                                                                        <form method="POST" class="folder-admin-form folder-admin-form--inner">
                                                                            <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                                                            <input type="hidden" name="action" value="update_item_settings">
                                                                            <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                                                                            <div class="folder-form-row">
                                                                                <label class="folder-form-label">
                                                                                    <span>Title</span>
                                                                                    <input type="text" name="title" required maxlength="200" value="<?= portal_escape($item['title']) ?>">
                                                                                </label>
                                                                                <label class="folder-form-label">
                                                                                    <span>Description <small>(optional)</small></span>
                                                                                    <input type="text" name="description" maxlength="500" value="<?= portal_escape($item['description']) ?>">
                                                                                </label>
                                                                            </div>
                                                                            <div class="folder-form-row">
                                                                                <label class="folder-form-label">
                                                                                    <span>Deadline <small>(optional — leave blank for none)</small></span>
                                                                                    <input type="datetime-local" name="submission_deadline" value="<?= portal_escape($itemDeadlineValue) ?>">
                                                                                </label>
                                                                                <label class="folder-form-label">
                                                                                    <span>Grade weight <small>(%)</small></span>
                                                                                    <input type="number" name="submission_weight" min="0" max="100" step="0.01" inputmode="decimal" value="<?= portal_escape(portal_format_submission_weight($item['submission_weight'] ?? 100, false)) ?>">
                                                                                </label>
                                                                                <label class="folder-form-label">
                                                                                    <span>Re-submissions allowed</span>
                                                                                    <select name="submission_max_attempts">
                                                                                        <option value="0" <?= (int) ($item['submission_max_attempts'] ?? 0) === 0 ? 'selected' : '' ?>>Unlimited</option>
                                                                                        <option value="1" <?= (int) ($item['submission_max_attempts'] ?? 0) === 1 ? 'selected' : '' ?>>1 (no resubmission)</option>
                                                                                        <option value="2" <?= (int) ($item['submission_max_attempts'] ?? 0) === 2 ? 'selected' : '' ?>>2</option>
                                                                                    </select>
                                                                                </label>
                                                                                <?php if ($showExternalAiSlotOption): ?>
                                                                                <label class="folder-form-label folder-form-check">
                                                                                    <input type="checkbox" name="submission_ai_detection" value="1" <?= !empty($item['submission_ai_detection']) ? 'checked' : '' ?>>
                                                                                    External AI detection <small style="font-weight:400">(GPTZero for this assignment)</small>
                                                                                </label>
                                                                                <?php elseif ($externalAiSiteWide): ?>
                                                                                <p class="submit-hint" style="margin:0;">External AI detection is enabled site-wide for all submissions.</p>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                            <div class="button-row">
                                                                                <button type="submit" class="button button--sm">Save slot settings</button>
                                                                                <button type="button"
                                                                                        class="button-secondary button--sm settings-toggle"
                                                                                        data-settings-target="<?= portal_escape($slotEditPanelId) ?>">Cancel</button>
                                                                            </div>
                                                                        </form>
                                                                    </div>
                                                                    <?php if (!empty($subs)): ?>
                                                                        <p class="sub-modal-count"><?= count($subs) ?> submission<?= count($subs) !== 1 ? 's' : '' ?></p>
                                                                        <?php foreach ($subs as $sub): ?>
                                                                        <article class="sub-modal-entry sub-modal-entry--row" data-submission-id="<?= (int) $sub['id'] ?>">
                                                                            <div class="sub-modal-entry-who">
                                                                                <div class="course-staff-avatar sub-avatar"><?= portal_escape($sub['student_initials']) ?></div>
                                                                                <div>
                                                                                    <strong><?= portal_escape($sub['student_name']) ?></strong>
                                                                                    <span class="sub-date"><?= portal_escape(date('j M Y H:i', strtotime($sub['submitted_at']))) ?> &middot; <?= portal_escape($sub['filename']) ?></span>
                                                                                </div>
                                                                            </div>
                                                                            <div class="sub-modal-entry-end">
                                                                                <?php if ($sub['score'] !== null): ?>
                                                                                    <span class="sub-modal-grade sub-modal-grade--marked"><?= (int)$sub['score'] ?><small>/100</small></span>
                                                                                <?php else: ?>
                                                                                    <span class="sub-modal-grade sub-modal-grade--pending">Not graded</span>
                                                                                <?php endif; ?>
                                                                                <button type="button" class="button button--sm" data-review-open="rvw-<?= (int)$sub['id'] ?>">Open review</button>
                                                                                <form method="POST" class="sub-delete-form" onsubmit="return confirm('Remove this submission?');">
                                                                                    <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                                                                    <input type="hidden" name="action" value="delete_submission">
                                                                                    <input type="hidden" name="submission_id" value="<?= (int)$sub['id'] ?>">
                                                                                    <button type="submit" class="btn-icon-danger" title="Remove submission">
                                                                                        <?= portal_icon('trash', 'icon-sm') ?>
                                                                                    </button>
                                                                                </form>
                                                                            </div>
                                                                        </article>
                                                                        <?php endforeach; ?>
                                                                    <?php else: ?>
                                                                        <p class="sub-empty">No submissions yet.</p>
                                                                    <?php endif; ?>

                                                                <?php else: ?>
                                                                    <div class="sub-modal-student" data-student-sub-modal>
                                                                    <div class="sub-modal-mine<?= $mySub ? ' is-visible' : '' ?>" data-sub-mine>
                                                                        <div class="sub-modal-mine-info">
                                                                            <span class="sub-slot-file" data-sub-filename><?= portal_icon('file', 'icon-xs') ?><span><?= $mySub ? portal_escape($mySub['filename']) : '' ?></span></span>
                                                                            <span class="sub-modal-mine-meta" data-sub-date><?= $mySub ? 'Submitted ' . portal_escape(date('j M Y H:i', strtotime($mySub['submitted_at']))) : '' ?></span>
                                                                        </div>
                                                                        <div class="sub-modal-mine-end">
                                                                            <span class="sub-modal-grade<?= ($mySub && $mySub['score'] !== null) ? ' sub-modal-grade--marked' : ' sub-modal-grade--pending' ?>" data-sub-grade-badge><?php if ($mySub && $mySub['score'] !== null): ?><?= (int)$mySub['score'] ?><small>/100</small><?php else: ?>Not graded<?php endif; ?></span>
                                                                            <?php if ($mySub): ?>
                                                                            <button type="button" class="button button--sm" data-review-open="rvw-<?= (int)$mySub['id'] ?>" data-sub-review-btn>Open review</button>
                                                                            <?php else: ?>
                                                                            <button type="button" class="button button--sm is-hidden" data-review-open="" data-sub-review-btn>Open review</button>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>
                                                                    <div class="sub-submit-success is-hidden" data-sub-success role="status"></div>

                                                                    <?php if ($deadlinePassed): ?>
                                                                        <?php if (!$mySub): ?>
                                                                            <p class="sub-empty sub-empty--closed">This submission slot is closed.</p>
                                                                        <?php endif; ?>
                                                                    <?php elseif ($attemptsReached): ?>
                                                                        <p class="sub-empty sub-empty--closed">You have used all <?= $slotMaxAttempts ?> allowed submission attempt<?= $slotMaxAttempts === 1 ? '' : 's' ?> for this assignment.</p>
                                                                    <?php else: ?>
                                                                    <section class="sub-modal-section sub-modal-section--submit" data-sub-submit-section>
                                                                        <p class="sub-empty sub-empty--closed is-hidden" data-sub-attempts-closed-msg><?= $slotMaxAttempts > 0 ? 'You have used all ' . $slotMaxAttempts . ' allowed submission attempt' . ($slotMaxAttempts === 1 ? '' : 's') . ' for this assignment.' : '' ?></p>
                                                                        <div data-sub-submit-fields>
                                                                        <h4 class="sub-modal-section-title" data-sub-submit-title><?= $mySub ? 'Re-submit work' : 'Submit work' ?></h4>
                                                                        <form method="POST" enctype="multipart/form-data" class="submit-work-form submit-work-form--modal" data-item-id="<?= (int) $item['id'] ?>">
                                                                            <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                                                            <input type="hidden" name="action" value="submit_work">
                                                                            <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                                                            <input type="hidden" name="process_edit_seconds" value="0">
                                                                            <input type="hidden" name="process_paste_events" value="0">
                                                                            <input type="hidden" name="process_pasted_chars" value="0">
                                                                            <div class="sub-submit-error" data-sub-error role="alert"></div>
                                                                            <label class="submit-file-label">
                                                                                <span class="submit-hint">1. Choose file type <small>(or skip — dropping a file detects it)</small></span>
                                                                                <select name="submission_type" required data-sub-type-select>
                                                                                    <option value="">Select type…</option>
                                                                                    <?php foreach (portal_submission_type_labels() as $typeKey => $typeLabel): ?>
                                                                                    <option value="<?= portal_escape($typeKey) ?>"><?= portal_escape($typeLabel) ?></option>
                                                                                    <?php endforeach; ?>
                                                                                </select>
                                                                            </label>
                                                                            <p class="sub-pptx-note is-hidden" data-sub-pptx-note role="note">
                                                                                <strong>PowerPoint note:</strong>
                                                                                Teachers review presentations by downloading the file and opening it in PowerPoint (or similar). There is no in-browser slide preview. AI detection is disabled for this file type.
                                                                            </p>
                                                                            <div class="submit-file-label">
                                                                                <span class="submit-hint" id="sub-file-hint-<?= (int) $item['id'] ?>">2. Drop or choose a file (max 40 MB)</span>
                                                                                <div class="upload-dropzone" data-upload-dropzone data-upload-autodetect-type
                                                                                     aria-labelledby="sub-file-hint-<?= (int) $item['id'] ?>"
                                                                                     ondragenter="event.preventDefault(); this.classList.add('is-dragover');"
                                                                                     ondragover="event.preventDefault(); event.stopPropagation(); if(event.dataTransfer)event.dataTransfer.dropEffect='copy'; this.classList.add('is-dragover');"
                                                                                     ondragleave="this.classList.remove('is-dragover');"
                                                                                     ondrop="event.preventDefault(); event.stopPropagation(); this.classList.remove('is-dragover'); if(window.portalUploadDrop)window.portalUploadDrop(event);">
                                                                                    <input type="file" name="submission_file" id="sub-file-<?= (int) $item['id'] ?>"
                                                                                           data-sub-file-input data-upload-input>
                                                                                    <div class="upload-dropzone-ui">
                                                                                        <strong class="upload-dropzone-title">Drop a file here</strong>
                                                                                        <span class="upload-dropzone-sub">Type is filled in automatically if empty</span>
                                                                                        <label class="upload-dropzone-browse" for="sub-file-<?= (int) $item['id'] ?>">Browse files</label>
                                                                                        <div class="upload-dropzone-file-row is-hidden" data-upload-file-row>
                                                                                            <span class="upload-dropzone-file" data-upload-filename></span>
                                                                                            <button type="button" class="upload-dropzone-clear" data-upload-clear title="Remove file" aria-label="Remove file">
                                                                                                <?= portal_icon('trash', 'icon-xs') ?>
                                                                                            </button>
                                                                                        </div>
                                                                                    </div>
                                                                                    <div class="upload-progress is-hidden" data-upload-progress aria-live="polite">
                                                                                        <div class="upload-progress-track">
                                                                                            <div class="upload-progress-bar" data-upload-progress-bar></div>
                                                                                        </div>
                                                                                        <span class="upload-progress-label" data-upload-progress-label>0%</span>
                                                                                    </div>
                                                                                    <p class="upload-dropzone-error is-hidden" data-upload-error role="alert"></p>
                                                                                </div>
                                                                            </div>
                                                                            <label class="submit-file-label">
                                                                                <span class="submit-hint">Or paste your text</span>
                                                                                <textarea name="submission_text" rows="6" maxlength="200000" placeholder="Paste your work here if you are not uploading a file." data-sub-text-counter data-min-words="<?= (int) portal_submission_min_words() ?>"></textarea>
                                                                                <span class="submit-word-count" data-sub-word-count></span>
                                                                            </label>
                                                                            <div class="sub-receipt-card is-hidden" data-sub-receipt-card hidden>
                                                                                <p class="sub-receipt-card__eyebrow">Submission receipt</p>
                                                                                <p class="sub-receipt-card__number" data-sub-receipt-number></p>
                                                                                <dl class="sub-receipt-card__meta">
                                                                                    <div><dt>Student</dt><dd data-sub-receipt-student></dd></div>
                                                                                    <div><dt>Course</dt><dd data-sub-receipt-course></dd></div>
                                                                                    <div><dt>Assignment</dt><dd data-sub-receipt-assignment></dd></div>
                                                                                    <div><dt>File</dt><dd data-sub-receipt-file></dd></div>
                                                                                    <div><dt>Type</dt><dd data-sub-receipt-type></dd></div>
                                                                                    <div><dt>Submitted</dt><dd data-sub-receipt-when></dd></div>
                                                                                    <div><dt>File fingerprint</dt><dd data-sub-receipt-hash></dd></div>
                                                                                </dl>
                                                                                <div class="sub-receipt-card__actions">
                                                                                    <button type="button" class="button button--sm" data-sub-receipt-copy>Copy receipt</button>
                                                                                    <button type="button" class="button button--sm button--ghost" data-sub-receipt-print>Print</button>
                                                                                </div>
                                                                                <p class="sub-receipt-card__note">Keep this receipt. Admins can use it to find your submission if anything goes missing.</p>
                                                                            </div>
                                                                            <button type="submit" class="button" data-sub-submit-btn><?= $mySub ? 'Re-submit' : 'Submit work' ?></button>
                                                                        </form>
                                                                        </div>
                                                                    </section>
                                                                    <?php endif; ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <?php
                                                            // Dedicated Turnitin-style review overlays
                                                            $reviewList = portal_can_manage_course($courseId)
                                                                ? $subs
                                                                : ($mySub ? [$mySub] : []);
                                                            $isTeacherReview = portal_can_manage_course($courseId);
                                                        ?>
                                                        <?php foreach ($reviewList as $reviewSub): ?>
                                                            <?php
                                                                $reviewAnns = $submissionAnnotations[(int) $reviewSub['id']] ?? [];
                                                                $reviewWho  = $isTeacherReview ? (string) ($reviewSub['student_name'] ?? '') : 'Your submission';
                                                            ?>
                                                            <div id="rvw-<?= (int) $reviewSub['id'] ?>" class="rvw-overlay" hidden role="dialog" aria-modal="true">
                                                                <div class="rvw-dialog">
                                                                    <header class="rvw-dialog-header">
                                                                        <div class="rvw-dialog-heading">
                                                                            <p class="eyebrow">Assignment review</p>
                                                                            <h3><?= portal_escape($item['title']) ?></h3>
                                                                            <p class="rvw-dialog-sub"><?= portal_escape($reviewWho) ?> &middot; <?= portal_escape((string) $reviewSub['filename']) ?> &middot; <?= portal_escape(date('j M Y H:i', strtotime((string) $reviewSub['submitted_at']))) ?></p>
                                                                        </div>
                                                                        <div class="rvw-dialog-actions">
                                                                            <?php if ($isTeacherReview): ?>
                                                                                <form method="POST" class="sub-rerun-form">
                                                                                    <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                                                                    <input type="hidden" name="action" value="rerun_integrity">
                                                                                    <input type="hidden" name="submission_id" value="<?= (int) $reviewSub['id'] ?>">
                                                                                    <button type="submit" class="button button--sm button--ghost">Re-run checks</button>
                                                                                </form>
                                                                            <?php endif; ?>
                                                                            <button type="button" class="rvw-close" aria-label="Close">&times;</button>
                                                                        </div>
                                                                    </header>
                                                                    <?= portal_render_submission_review($reviewSub, $isTeacherReview, $item, $reviewAnns, $csrfToken) ?>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                        <?php $submissionModals .= ob_get_clean(); ?>
                                                    <?php endif; ?>
                                                    <?php endif; // end locked check ?>
                                                </div>

                                                <?php if (portal_can_manage_course($courseId)): ?>
                                                    <div class="folder-item-actions">
                                                        <button type="button"
                                                                class="folder-lock-toggle<?= $itemLocked ? ' is-locked' : '' ?>"
                                                                data-item-id="<?= (int)$item['id'] ?>"
                                                                title="<?= $itemLocked ? 'Unlock item' : 'Lock item' ?>"
                                                                aria-label="<?= $itemLocked ? 'Unlock item' : 'Lock item' ?>">
                                                            <?= portal_icon('lock', 'icon-sm') ?>
                                                        </button>
                                                        <?php if ($item['type'] === 'submission'): ?>
                                                        <button type="button"
                                                                class="folder-settings-button"
                                                                data-sub-open-edit="sub-slot-modal-<?= (int)$item['id'] ?>"
                                                                data-sub-open-edit-form="1"
                                                                title="Edit slot"
                                                                aria-label="Edit slot">
                                                            <?= portal_icon('edit', 'icon-sm') ?>
                                                        </button>
                                                        <?php elseif ($item['type'] === 'activity' && $activityRow): ?>
                                                        <a class="folder-settings-button"
                                                           href="activity-builder.php?id=<?= (int) $activityRow['id'] ?>"
                                                           title="Edit activity"
                                                           aria-label="Edit activity">
                                                            <?= portal_icon('edit', 'icon-sm') ?>
                                                        </a>
                                                        <?php else: ?>
                                                        <button type="button"
                                                                class="folder-settings-button settings-toggle"
                                                                data-settings-target="item-settings-<?= (int)$item['id'] ?>"
                                                                aria-label="Item settings">
                                                            <?= portal_icon('settings', 'icon-sm') ?>
                                                        </button>
                                                        <?php endif; ?>
                                                        <form method="POST" class="folder-item-delete-form">
                                                            <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                                            <input type="hidden" name="action" value="delete_item">
                                                            <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                                            <button type="submit" class="btn-icon-danger" title="Delete item">
                                                                <?= portal_icon('trash', 'icon-sm') ?>
                                                            </button>
                                                        </form>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="folder-empty-note">No items in this folder yet.</p>
                                <?php endif; ?>

                                <?php if (portal_can_manage_course($courseId)): ?>
                                    <details class="folder-admin-panel folder-admin-panel--inner">
                                        <summary class="folder-admin-trigger folder-admin-trigger--sm">
                                            <?= portal_icon('plus', 'icon-sm') ?>
                                            <span>Add item</span>
                                        </summary>
                                        <form method="POST" enctype="multipart/form-data" class="folder-admin-form folder-admin-form--inner">
                                            <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                            <input type="hidden" name="action" value="create_item">
                                            <input type="hidden" name="folder_id" value="<?= (int) $folder['id'] ?>">
                                            <div class="folder-form-row">
                                                <label class="folder-form-label">
                                                    <span>Type</span>
                                                    <select name="type" class="item-type-select">
                                                        <option value="document">File upload</option>
                                                        <option value="video">Video</option>
                                                        <option value="link">Link</option>
                                                        <option value="submission">Submission slot</option>
                                                        <option value="activity">Activity</option>
                                                    </select>
                                                </label>
                                                <label class="folder-form-label">
                                                    <span>Title</span>
                                                    <input type="text" name="title" required maxlength="200" placeholder="Item name">
                                                </label>
                                            </div>
                                            <div class="folder-form-row item-activity-group" style="display:none;">
                                                <label class="folder-form-label">
                                                    <span>Activity mode</span>
                                                    <select name="activity_mode">
                                                        <option value="practice">Practice</option>
                                                        <option value="quiz" selected>Quiz</option>
                                                        <option value="challenge">Challenge</option>
                                                        <option value="assessment">Assessment</option>
                                                        <option value="survey">Survey</option>
                                                        <option value="flashcard">Flashcards</option>
                                                    </select>
                                                </label>
                                            </div>
                                            <div class="folder-form-row item-file-group">
                                                <div class="folder-form-label" style="grid-column:1/-1;">
                                                    <span class="item-file-label">Upload file <small>(<?= portal_escape(portal_supported_upload_hint()) ?> - max 40 MB)</small></span>
                                                    <div class="upload-dropzone" data-upload-dropzone>
                                                        <input type="file" name="file" id="item-file-input" class="item-file-input" data-upload-input
                                                               accept=".doc,.docx,.xlsx,.pdf,.txt,.ppt,.pptx,.pps,.ppsx,.pot,.potx,.odp"
                                                               data-doc-accept=".doc,.docx,.xlsx,.pdf,.txt,.ppt,.pptx,.pps,.ppsx,.pot,.potx,.odp"
                                                               data-doc-hint="Upload file <small>(<?= portal_escape(portal_supported_upload_hint()) ?> - max 40 MB)</small>"
                                                               data-video-accept=".mp4,.webm,.mov,.m4v,.ogv"
                                                               data-video-hint="Upload a video file <small>(<?= portal_escape(portal_supported_video_upload_hint()) ?>) — or paste a link below</small>">
                                                        <div class="upload-dropzone-ui">
                                                            <strong class="upload-dropzone-title">Drop a file here</strong>
                                                            <span class="upload-dropzone-sub">or use Browse</span>
                                                            <label class="upload-dropzone-browse" for="item-file-input">Browse files</label>
                                                            <div class="upload-dropzone-file-row is-hidden" data-upload-file-row>
                                                                <span class="upload-dropzone-file" data-upload-filename></span>
                                                                <button type="button" class="upload-dropzone-clear" data-upload-clear title="Remove file" aria-label="Remove file">
                                                                    <?= portal_icon('trash', 'icon-xs') ?>
                                                                </button>
                                                            </div>
                                                        </div>
                                                        <div class="upload-progress is-hidden" data-upload-progress aria-live="polite">
                                                            <div class="upload-progress-track">
                                                                <div class="upload-progress-bar" data-upload-progress-bar></div>
                                                            </div>
                                                            <span class="upload-progress-label" data-upload-progress-label>0%</span>
                                                        </div>
                                                        <p class="upload-dropzone-error is-hidden" data-upload-error role="alert"></p>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="folder-form-row item-url-group">
                                                <label class="folder-form-label" style="grid-column:1/-1;">
                                                    <span class="item-url-label">Or paste URL <small>(optional)</small></span>
                                                    <input type="text" inputmode="url" autocomplete="url" name="url" maxlength="2000" placeholder="https://... or www.example.com"
                                                           data-doc-placeholder="https://... or www.example.com"
                                                           data-video-placeholder="https://www.youtube.com/watch?v=... or https://vimeo.com/...">
                                                </label>
                                            </div>
                                            <div class="folder-form-row">
                                                <label class="folder-form-label" style="grid-column:1/-1;">
                                                    <span>Description <small>(optional)</small></span>
                                                    <input type="text" name="description" maxlength="500" placeholder="Short note for students">
                                                </label>
                                            </div>
                                            <div class="folder-form-row item-submission-group">
                                                <label class="folder-form-label">
                                                    <span>Deadline <small>(optional — leave blank for none)</small></span>
                                                    <input type="datetime-local" name="submission_deadline" min="<?= portal_escape(date('Y-m-d\TH:i')) ?>">
                                                </label>
                                                <label class="folder-form-label">
                                                    <span>Grade weight <small>(%)</small></span>
                                                    <input type="number" name="submission_weight" min="0" max="100" step="0.01" inputmode="decimal" value="100">
                                                </label>
                                                <label class="folder-form-label">
                                                    <span>Re-submissions allowed</span>
                                                    <select name="submission_max_attempts">
                                                        <option value="0">Unlimited</option>
                                                        <option value="1">1 (no resubmission)</option>
                                                        <option value="2">2</option>
                                                    </select>
                                                </label>
                                                <?php if ($showExternalAiSlotOption): ?>
                                                <label class="folder-form-label folder-form-check">
                                                    <input type="checkbox" name="submission_ai_detection" value="1">
                                                    External AI detection <small style="font-weight:400">(GPTZero for this assignment)</small>
                                                </label>
                                                <?php elseif ($externalAiSiteWide): ?>
                                                <p class="submit-hint" style="margin:0;">External AI detection is enabled site-wide for all submissions.</p>
                                                <?php endif; ?>
                                            </div>
                                            <label class="folder-form-label folder-form-check">
                                                <input type="checkbox" name="allow_download" value="1">
                                                Allow students to download this file <small style="font-weight:400">(off by default)</small>
                                            </label>
                                            <button type="submit" class="button">Add</button>
                                        </form>
                                    </details>

                                    <?php if (portal_is_admin()): ?>
                                    <form method="POST" class="folder-delete-form">
                                        <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete_folder">
                                        <input type="hidden" name="folder_id" value="<?= (int) $folder['id'] ?>">
                                        <button type="submit" class="btn-danger-sm">
                                            <?= portal_icon('trash', 'icon-sm') ?> Delete folder
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </details>
                        </div><!-- /.folder-row -->
                    <?php endforeach; ?>
                    </div><!-- /#folder-stack -->
                <?php endif; ?>
            <?php elseif ($sectionKey === 'calendar'): ?>
                <?php if (portal_can_manage_course($courseId)): ?>
                <details class="folder-admin-panel">
                    <summary class="folder-admin-trigger">
                        <?= portal_icon('plus', 'icon-sm') ?> <span>Add schedule slot</span>
                    </summary>
                    <form method="POST" class="folder-admin-form">
                        <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                        <input type="hidden" name="action" value="create_schedule_slot">
                        <div class="folder-form-row">
                            <label class="folder-form-label">
                                <span>Day</span>
                                <select name="day_of_week">
                                    <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $d): ?>
                                        <option><?= $d ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="folder-form-label">
                                <span>Start time</span>
                                <input type="time" name="start_time">
                            </label>
                            <label class="folder-form-label">
                                <span>End time</span>
                                <input type="time" name="end_time">
                            </label>
                            <label class="folder-form-label">
                                <span>Location / join link <small>(optional)</small></span>
                                <input type="text" name="room" maxlength="500" placeholder="Room 12 or https://zoom.us/j/...">
                            </label>
                        </div>
                        <label class="folder-form-label">
                            <span>Notes <small>(optional)</small></span>
                            <input type="text" name="notes" maxlength="300" placeholder="Any extra info">
                        </label>
                        <button type="submit" class="button">Add</button>
                    </form>
                </details>
                <?php endif; ?>

                <article class="card-shell">
                    <div class="section-head">
                        <div>
                            <p class="eyebrow">Weekly schedule</p>
                            <h3 class="card-title">When this class meets</h3>
                        </div>
                        <span class="chip"><?= count($courseSchedule) ?> slot<?= count($courseSchedule) !== 1 ? 's' : '' ?></span>
                    </div>
                    <?php if (empty($courseSchedule)): ?>
                        <p style="margin:0;color:var(--muted);font-size:.9rem;">No schedule set yet.</p>
                    <?php else: ?>
                    <div class="schedule-grid">
                        <?php foreach ($courseSchedule as $slot): ?>
                        <div class="schedule-slot-wrap">
                            <div class="schedule-slot-card">
                                <div class="slot-day-badge"><?= portal_escape(substr($slot['day_of_week'], 0, 3)) ?></div>
                                <div class="slot-detail">
                                    <strong><?= portal_escape($slot['start_time']) ?><?= $slot['end_time'] ? ' – ' . portal_escape($slot['end_time']) : '' ?></strong>
                                    <?php
                                        $joinUrl = trim((string) ($slot['room'] ?? ''));
                                        $isValidUrl = portal_schedule_slot_is_online($joinUrl);
                                    ?>
                                    <?php if ($isValidUrl): ?>
                                        <span class="slot-online-label">Online</span>
                                    <?php elseif ($joinUrl !== ''): ?>
                                        <span class="slot-online-label"><?= portal_escape($joinUrl) ?></span>
                                    <?php else: ?>
                                        <span class="slot-online-label">Location TBA</span>
                                    <?php endif; ?>
                                    <?php if ($slot['notes'] !== ''): ?>
                                        <em><?= portal_escape($slot['notes']) ?></em>
                                    <?php endif; ?>
                                    <?php if ($isValidUrl): ?>
                                        <a class="slot-join-link" href="<?= portal_escape($joinUrl) ?>" target="_blank" rel="noopener noreferrer">
                                            Join session →
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <?php if (portal_can_manage_course($courseId)): ?>
                                <div class="slot-actions">
                                    <button type="button"
                                            class="settings-toggle btn-icon"
                                            data-settings-target="edit-slot-<?= (int)$slot['id'] ?>"
                                            title="Edit slot"
                                            aria-label="Edit schedule slot">
                                        <?= portal_icon('edit', 'icon-sm') ?>
                                    </button>
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete_schedule_slot">
                                        <input type="hidden" name="slot_id" value="<?= (int)$slot['id'] ?>">
                                        <button type="submit" class="btn-icon-danger"><?= portal_icon('trash','icon-sm') ?></button>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php if (portal_can_manage_course($courseId)): ?>
                            <div class="settings-panel slot-edit-panel" id="edit-slot-<?= (int)$slot['id'] ?>" hidden>
                                <form method="POST" class="folder-admin-form folder-admin-form--inner">
                                    <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                    <input type="hidden" name="action" value="update_schedule_slot">
                                    <input type="hidden" name="slot_id" value="<?= (int)$slot['id'] ?>">
                                    <div class="folder-form-row">
                                        <label class="folder-form-label">
                                            <span>Day</span>
                                            <select name="day_of_week">
                                                <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $d): ?>
                                                    <option<?= $slot['day_of_week'] === $d ? ' selected' : '' ?>><?= $d ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="folder-form-label">
                                            <span>Start time</span>
                                            <input type="time" name="start_time" value="<?= portal_escape($slot['start_time']) ?>">
                                        </label>
                                        <label class="folder-form-label">
                                            <span>End time</span>
                                            <input type="time" name="end_time" value="<?= portal_escape($slot['end_time']) ?>">
                                        </label>
                                        <label class="folder-form-label">
                                            <span>Location / join link <small>(optional)</small></span>
                                            <input type="text" name="room" maxlength="500" value="<?= portal_escape($slot['room']) ?>" placeholder="Room 12 or https://zoom.us/j/...">
                                        </label>
                                    </div>
                                    <label class="folder-form-label">
                                        <span>Notes <small>(optional)</small></span>
                                        <input type="text" name="notes" maxlength="300" value="<?= portal_escape($slot['notes']) ?>">
                                    </label>
                                    <div class="button-row">
                                        <button type="submit" class="button button--sm">Save</button>
                                        <button type="button"
                                                class="button-secondary button--sm settings-toggle"
                                                data-settings-target="edit-slot-<?= (int)$slot['id'] ?>">Cancel</button>
                                    </div>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </article>

                <article class="card-shell">
                    <div class="section-head">
                        <div>
                            <p class="eyebrow">One-off activities</p>
                            <h3 class="card-title">Upcoming events</h3>
                        </div>
                        <span class="chip"><?= count($courseUpcomingEvents) ?></span>
                    </div>
                    <?php if (empty($courseUpcomingEvents)): ?>
                        <p style="margin:0;color:var(--muted);font-size:.9rem;">No upcoming course events yet.</p>
                    <?php else: ?>
                    <div class="ev-list">
                        <?php foreach ($courseUpcomingEvents as $courseEvent): ?>
                        <?php
                            $evCancelled = (string) ($courseEvent['status'] ?? '') === 'cancelled';
                            $evChips = portal_event_chip_parts($courseEvent);
                        ?>
                        <article class="ev-item<?= $evCancelled ? ' ev-item--cancelled' : '' ?>">
                            <div class="ev-date">
                                <strong><?= portal_escape($evChips['day']) ?></strong>
                                <span><?= portal_escape($evChips['month']) ?></span>
                            </div>
                            <div class="ev-body">
                                <h3><?= portal_escape((string) $courseEvent['title']) ?></h3>
                                <p class="event-location">
                                    <?= portal_escape(portal_event_format_time_range($courseEvent)) ?>
                                    · <?= portal_escape(portal_event_place_label($courseEvent)) ?>
                                    <?php if ($evCancelled): ?> · Cancelled<?php endif; ?>
                                </p>
                                <a class="inline-action" href="events.php?event=<?= (int) $courseEvent['id'] ?>">View details</a>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </article>
            <?php elseif ($sectionKey === 'announcements'): ?>
                <?php if (portal_can_manage_course($courseId)): ?>
                    <details class="folder-admin-panel">
                        <summary class="folder-admin-trigger">
                            <?= portal_icon('megaphone', 'icon-sm') ?>
                            <span>Post announcement</span>
                        </summary>
                        <form method="POST" class="folder-admin-form">
                            <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                            <input type="hidden" name="action" value="post_announcement">
                            <label class="folder-form-label">
                                <span>Title</span>
                                <input type="text" name="title" required maxlength="200" placeholder="Announcement title">
                            </label>
                            <div class="folder-form-label">
                                <span>Message <small>(optional)</small></span>
                                <div class="quill-wrap"><div class="quill-editor" data-target="ann-body"></div></div>
                                <textarea name="body" id="ann-body" class="rich-textarea" maxlength="20000" hidden></textarea>
                            </div>
                            <button type="submit" class="button">Post</button>
                        </form>
                    </details>
                <?php endif; ?>

                <article class="card-shell">
                    <div class="section-head">
                        <div>
                            <p class="eyebrow">Course announcements</p>
                            <h3 class="card-title">Latest notices</h3>
                        </div>
                        <span class="chip"><?= count($courseAnnouncements) ?> posts</span>
                    </div>

                    <?php if (empty($courseAnnouncements)): ?>
                        <p style="margin:0;color:var(--muted);font-size:0.9rem;">No announcements yet.</p>
                    <?php else: ?>
                    <div class="simple-list">
                        <?php foreach ($courseAnnouncements as $ann): ?>
                            <article class="simple-list-item ann-item">
                                <time>
                                    <div class="course-staff-avatar ann-avatar"><?= portal_escape($ann['author_initials']) ?></div>
                                    <strong><?= portal_escape($ann['author_name']) ?></strong>
                                    <span><?= portal_escape(date('j M Y', strtotime($ann['created_at']))) ?></span>
                                </time>
                                <div class="simple-list-copy">
                                    <h3><?= portal_escape($ann['title']) ?></h3>
                                    <?php if ($ann['body'] !== ''): ?>
                                        <div class="rich-body"><?= portal_render_rich_text($ann['body']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php if (portal_can_manage_course($courseId) && (portal_is_admin() || (int)$ann['user_id'] === (int)(portal_current_user()['id'] ?? 0))): ?>
                                    <form method="POST" class="ann-delete-form">
                                        <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete_announcement">
                                        <input type="hidden" name="announcement_id" value="<?= (int) $ann['id'] ?>">
                                        <button type="submit" class="btn-icon-danger" title="Delete">
                                            <?= portal_icon('trash', 'icon-sm') ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </article>
            <?php elseif ($sectionKey === 'discussions'): ?>
                <?php if ($dbCurrentTopic): ?>
                    <!-- ── Single topic view ────────────────────────────────── -->
                    <a class="forum-back-link" href="course.php?course=<?= urlencode($slug) ?>&section=discussions">
                        ← Back to discussions
                    </a>
                    <article class="card-shell forum-topic-header">
                        <div class="forum-topic-meta">
                            <div class="course-staff-avatar ann-avatar"><?= portal_escape($dbCurrentTopic['author_initials']) ?></div>
                            <div>
                                <strong><?= portal_escape($dbCurrentTopic['author_name']) ?></strong>
                                <span class="sub-date"><?= portal_escape(date('j M Y', strtotime($dbCurrentTopic['created_at']))) ?></span>
                            </div>
                            <?php if (portal_can_manage_course($courseId)): ?>
                            <form method="POST" style="margin:0;margin-left:auto;">
                                <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                <input type="hidden" name="action" value="delete_topic">
                                <input type="hidden" name="topic_id" value="<?= (int)$dbCurrentTopic['id'] ?>">
                                <button type="submit" class="btn-danger-sm"><?= portal_icon('trash','icon-sm') ?> Delete topic</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <h2 class="forum-topic-title"><?= portal_escape($dbCurrentTopic['title']) ?></h2>
                        <?php if ($dbCurrentTopic['body'] !== ''): ?>
                            <div class="forum-topic-body rich-body"><?= portal_render_rich_text($dbCurrentTopic['body']) ?></div>
                        <?php endif; ?>
                    </article>

                    <?php foreach ($dbReplies as $reply): ?>
                    <article class="forum-reply-card">
                        <div class="course-staff-avatar ann-avatar reply-avatar"><?= portal_escape($reply['author_initials']) ?></div>
                        <div class="forum-reply-content">
                            <div class="forum-reply-head">
                                <strong><?= portal_escape($reply['author_name']) ?></strong>
                                <span class="sub-date"><?= portal_escape(date('j M Y H:i', strtotime($reply['created_at']))) ?></span>
                            </div>
                            <div class="rich-body"><?= portal_render_rich_text($reply['body']) ?></div>
                        </div>
                        <?php $canDelReply = portal_can_manage_course($courseId) || (int)$reply['user_id'] === (int)$_me['id']; ?>
                        <?php if ($canDelReply): ?>
                        <form method="POST" style="margin:0;align-self:start;">
                            <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete_reply">
                            <input type="hidden" name="reply_id" value="<?= (int)$reply['id'] ?>">
                            <input type="hidden" name="topic_id" value="<?= (int)$dbCurrentTopic['id'] ?>">
                            <button type="submit" class="btn-icon-danger"><?= portal_icon('trash','icon-sm') ?></button>
                        </form>
                        <?php endif; ?>
                    </article>
                    <?php endforeach; ?>

                    <article class="card-shell forum-reply-form-card">
                        <p class="eyebrow">Post a reply</p>
                        <form method="POST" class="forum-reply-form">
                            <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                            <input type="hidden" name="action" value="post_reply">
                            <input type="hidden" name="topic_id" value="<?= (int)$dbCurrentTopic['id'] ?>">
                            <div class="quill-wrap"><div class="quill-editor" data-target="reply-body"></div></div>
                            <textarea name="body" id="reply-body" class="rich-textarea" maxlength="20000" hidden></textarea>
                            <button type="submit" class="button">Post reply</button>
                        </form>
                    </article>

                <?php else: ?>
                    <!-- ── Topic list view ─────────────────────────────────── -->
                    <?php if (portal_can_manage_course($courseId)): ?>
                    <details class="folder-admin-panel">
                        <summary class="folder-admin-trigger">
                            <?= portal_icon('plus', 'icon-sm') ?> <span>New topic</span>
                        </summary>
                        <form method="POST" class="folder-admin-form">
                            <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                            <input type="hidden" name="action" value="create_topic">
                            <label class="folder-form-label">
                                <span>Topic title</span>
                                <input type="text" name="title" required maxlength="200" placeholder="e.g. Chapter 3 discussion">
                            </label>
                            <div class="folder-form-label">
                                <span>Opening message <small>(optional)</small></span>
                                <div class="quill-wrap"><div class="quill-editor" data-target="topic-body"></div></div>
                                <textarea name="body" id="topic-body" class="rich-textarea" maxlength="20000" hidden></textarea>
                            </div>
                            <button type="submit" class="button">Create topic</button>
                        </form>
                    </details>
                    <?php endif; ?>

                    <article class="card-shell">
                        <div class="section-head">
                            <div>
                                <p class="eyebrow">Discussions</p>
                                <h3 class="card-title">Topics</h3>
                            </div>
                            <span class="chip"><?= count($dbTopics) ?></span>
                        </div>
                        <?php if (empty($dbTopics)): ?>
                            <p style="margin:0;color:var(--muted);font-size:.9rem;">No topics yet.</p>
                        <?php else: ?>
                        <div class="forum-topic-list">
                            <?php foreach ($dbTopics as $topic): ?>
                            <a class="forum-topic-row" href="course.php?course=<?= urlencode($slug) ?>&section=discussions&topic=<?= (int)$topic['id'] ?>">
                                <div class="forum-topic-row-avatar course-staff-avatar"><?= portal_escape($topic['author_initials']) ?></div>
                                <div class="forum-topic-row-info">
                                    <strong><?= portal_escape($topic['title']) ?></strong>
                                    <span>by <?= portal_escape($topic['author_name']) ?> &middot; <?= portal_escape(date('j M Y', strtotime($topic['created_at']))) ?></span>
                                </div>
                                <span class="chip"><?= (int)$topic['reply_count'] ?> <?= (int)$topic['reply_count'] === 1 ? 'reply' : 'replies' ?></span>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </article>
                <?php endif; ?>
            <?php elseif ($sectionKey === 'gradebook'): ?>
                <?php
                    $gbIsStaff = portal_can_manage_course($courseId);
                    $gbMarked = array_values(array_filter(
                        $submissionGradebook,
                        static fn(array $g): bool => $g['score'] !== null && trim((string) ($g['marked_at'] ?? '')) !== ''
                    ));
                    $gbPending = array_values(array_filter(
                        $submissionGradebook,
                        static fn(array $g): bool => $g['score'] === null || trim((string) ($g['marked_at'] ?? '')) === ''
                    ));
                    $gbAvg = null;
                    if (!empty($gbMarked)) {
                        $gbAvg = portal_weighted_grade_average($gbMarked);
                    }

                    $gbGrouped = [];
                    foreach ($submissionGradebook as $grade) {
                        $slot = (string) $grade['slot_title'];
                        $gbGrouped[$slot][] = $grade;
                    }
                ?>
                <section class="gb-shell">
                    <header class="gb-header">
                        <div>
                            <p class="eyebrow"><?= $gbIsStaff ? 'Course gradebook' : 'Your grades' ?></p>
                            <h3 class="card-title"><?= $gbIsStaff ? 'Marks and feedback' : 'Grades for this module' ?></h3>
                            <p class="gb-header-copy"><?= $gbIsStaff
                                ? 'Track submissions and marks for this module.'
                                : 'Individual marks and feedback for assignments in this module.' ?></p>
                        </div>
                        <div class="gb-stat-row">
                            <div class="gb-stat">
                                <span>Marked</span>
                                <strong><?= count($gbMarked) ?></strong>
                            </div>
                            <div class="gb-stat">
                                <span>Awaiting</span>
                                <strong><?= count($gbPending) ?></strong>
                            </div>
                            <div class="gb-stat gb-stat--accent">
                                <span>Weighted avg</span>
                                <strong><?= $gbAvg !== null ? $gbAvg . '%' : '—' ?></strong>
                            </div>
                        </div>
                    </header>

                    <?php if (empty($submissionGradebook)): ?>
                        <div class="gb-empty">
                            <p class="eyebrow">No marks yet</p>
                            <h3>Nothing in the gradebook</h3>
                            <p><?= $gbIsStaff
                                ? 'When students submit work and you mark it, results appear here.'
                                : 'After you submit work and it is marked, your scores will show here.' ?></p>
                        </div>
                    <?php else: ?>
                        <div class="gb-slot-stack">
                            <?php if ($gbIsStaff): ?>
                                <?php foreach ($gbGrouped as $slotTitle => $rows): ?>
                                    <?php $slotWeightLabel = portal_format_submission_weight($rows[0]['submission_weight'] ?? 100); ?>
                                    <div class="gb-slot-group">
                                        <div class="gb-slot-group-head">
                                            <div class="gb-slot-title">
                                                <h4><?= portal_escape($slotTitle) ?></h4>
                                                <span>Weight <?= portal_escape($slotWeightLabel) ?></span>
                                            </div>
                                            <div class="gb-slot-meta">
                                                <span class="chip"><?= count($rows) ?> submission<?= count($rows) === 1 ? '' : 's' ?></span>
                                            </div>
                                        </div>
                                        <div class="gb-slot-grid">
                                            <?php foreach ($rows as $grade): ?>
                                                <?php
                                                    $isMarked = $grade['score'] !== null && trim((string) ($grade['marked_at'] ?? '')) !== '';
                                                    $submittedTs = portal_db_timestamp((string) $grade['submitted_at']);
                                                    $reviewId = 'rvw-' . (int) $grade['id'];
                                                ?>
                                                <div class="sub-slot-card"
                                                     data-review-open="<?= portal_escape($reviewId) ?>"
                                                     role="button"
                                                     tabindex="0"
                                                     aria-label="Open review for <?= portal_escape((string) ($grade['student_name'] ?? 'student')) ?>">
                                                    <?= portal_render_submission_deadline((string) ($grade['submission_deadline'] ?? '')) ?>
                                                    <div class="sub-slot-card-row">
                                                        <span class="sub-slot-file">
                                                            <span class="course-staff-avatar sub-avatar"><?= portal_escape((string) ($grade['student_initials'] ?? '?')) ?></span>
                                                            <span>
                                                                <strong><?= portal_escape((string) ($grade['student_name'] ?? 'Student')) ?></strong>
                                                                <small><?= portal_escape($submittedTs ? date('j M Y H:i', $submittedTs) : '') ?><?= trim((string) ($grade['filename'] ?? '')) !== '' ? ' · ' . portal_escape((string) $grade['filename']) : '' ?></small>
                                                            </span>
                                                        </span>
                                                        <?php if ($isMarked): ?>
                                                            <span class="sub-slot-status sub-slot-status--graded"><?= (int) $grade['score'] ?>%</span>
                                                        <?php else: ?>
                                                            <span class="sub-slot-status sub-slot-status--pending">Not graded</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="sub-slot-card-row">
                                                        <button type="button" class="button button--sm" data-review-open="<?= portal_escape($reviewId) ?>">Open review</button>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="gb-slot-grid">
                                    <?php foreach ($submissionGradebook as $grade): ?>
                                        <?php
                                            $isMarked = $grade['score'] !== null && trim((string) ($grade['marked_at'] ?? '')) !== '';
                                            $submittedTs = portal_db_timestamp((string) $grade['submitted_at']);
                                            $reviewId = 'rvw-' . (int) $grade['id'];
                                            $weightLabel = portal_format_submission_weight($grade['submission_weight'] ?? 100);
                                            $isActivityGrade = ($grade['grade_source'] ?? '') === 'activity';
                                            $activityResultHref = 'activity-results.php?id=' . (int) ($grade['activity_id'] ?? 0)
                                                . '&attempt=' . (int) ($grade['attempt_id'] ?? $grade['id']);
                                        ?>
                                        <<?= $isActivityGrade ? 'a' : 'div' ?> class="sub-slot-card"
                                             <?php if ($isActivityGrade): ?>href="<?= portal_escape($activityResultHref) ?>"<?php endif; ?>
                                             <?php if (!$isActivityGrade): ?>
                                             data-review-open="<?= portal_escape($reviewId) ?>"
                                             role="button"
                                             tabindex="0"
                                             <?php endif; ?>
                                             aria-label="Open result for <?= portal_escape((string) $grade['slot_title']) ?>">
                                            <?= portal_render_submission_deadline((string) ($grade['submission_deadline'] ?? '')) ?>
                                            <div class="sub-slot-card-row">
                                                <span class="sub-slot-file">
                                                    <?= portal_icon($isActivityGrade ? 'award' : 'file', 'icon-xs') ?>
                                                    <span>
                                                        <strong><?= portal_escape((string) $grade['slot_title']) ?></strong>
                                                        <small><?= portal_escape($submittedTs ? 'Submitted ' . date('j M Y H:i', $submittedTs) . ' | ' : '') ?>Weight <?= portal_escape($weightLabel) ?></small>
                                                    </span>
                                                </span>
                                                <?php if ($isMarked): ?>
                                                    <span class="sub-slot-status sub-slot-status--graded"><?= (int) $grade['score'] ?>%</span>
                                                <?php else: ?>
                                                    <span class="sub-slot-status sub-slot-status--pending">Not graded</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="sub-slot-card-row">
                                                <?php if ($isActivityGrade): ?>
                                                    <span class="button button--sm">View result</span>
                                                <?php else: ?>
                                                    <button type="button" class="button button--sm" data-review-open="<?= portal_escape($reviewId) ?>">Open review</button>
                                                <?php endif; ?>
                                            </div>
                                        </<?= $isActivityGrade ? 'a' : 'div' ?>>
                                    <?php endforeach; ?>
                                </div>
                                <p class="gb-footnote">See marks from every module on <a href="grades.php">My grades</a>.</p>
                            <?php endif; ?>
                        </div>

                        <?php
                            // Same review overlays as Content section — open in place from gradebook cards.
                            ob_start();
                            $isTeacherReview = $gbIsStaff;
                            foreach ($submissionGradebook as $reviewSub):
                                if (($reviewSub['grade_source'] ?? '') === 'activity') {
                                    continue;
                                }
                                $reviewAnns = $submissionAnnotations[(int) $reviewSub['id']] ?? [];
                                $reviewWho  = $isTeacherReview ? (string) ($reviewSub['student_name'] ?? '') : 'Your submission';
                                $itemForReview = [
                                    'id' => (int) ($reviewSub['item_id'] ?? 0),
                                    'title' => (string) ($reviewSub['slot_title'] ?? 'Submission'),
                                    'submission_deadline' => (string) ($reviewSub['submission_deadline'] ?? ''),
                                ];
                                $submittedLabel = portal_db_timestamp((string) ($reviewSub['submitted_at'] ?? ''));
                        ?>
                            <div id="rvw-<?= (int) $reviewSub['id'] ?>" class="rvw-overlay" hidden role="dialog" aria-modal="true">
                                <div class="rvw-dialog">
                                    <header class="rvw-dialog-header">
                                        <div class="rvw-dialog-heading">
                                            <p class="eyebrow">Assignment review</p>
                                            <h3><?= portal_escape((string) $itemForReview['title']) ?></h3>
                                            <p class="rvw-dialog-sub"><?= portal_escape($reviewWho) ?> &middot; <?= portal_escape((string) ($reviewSub['filename'] ?? '')) ?> &middot; <?= portal_escape($submittedLabel ? date('j M Y H:i', $submittedLabel) : '') ?></p>
                                        </div>
                                        <div class="rvw-dialog-actions">
                                            <?php if ($isTeacherReview): ?>
                                                <form method="POST" class="sub-rerun-form">
                                                    <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="rerun_integrity">
                                                    <input type="hidden" name="submission_id" value="<?= (int) $reviewSub['id'] ?>">
                                                    <button type="submit" class="button button--sm button--ghost">Re-run checks</button>
                                                </form>
                                            <?php endif; ?>
                                            <button type="button" class="rvw-close" aria-label="Close">&times;</button>
                                        </div>
                                    </header>
                                    <?= portal_render_submission_review($reviewSub, $isTeacherReview, $itemForReview, $reviewAnns, $csrfToken) ?>
                                </div>
                            </div>
                        <?php
                            endforeach;
                            $submissionModals .= ob_get_clean();
                        ?>
                    <?php endif; ?>
                </section>
            <?php else: ?>
                <!-- ── Groups section ──────────────────────────────────────── -->
                <?php if (portal_can_manage_course($courseId)): ?>
                <details class="folder-admin-panel">
                    <summary class="folder-admin-trigger">
                        <?= portal_icon('plus', 'icon-sm') ?> <span>Create group</span>
                    </summary>
                    <form method="POST" class="folder-admin-form">
                        <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                        <input type="hidden" name="action" value="create_group">
                        <div class="folder-form-row">
                            <label class="folder-form-label">
                                <span>Group name</span>
                                <input type="text" name="title" required maxlength="150" placeholder="e.g. Project Group A">
                            </label>
                            <label class="folder-form-label">
                                <span>Max members <small>(0 = unlimited)</small></span>
                                <input type="number" name="max_members" value="0" min="0" max="999">
                            </label>
                        </div>
                        <label class="folder-form-label">
                            <span>Description <small>(optional)</small></span>
                            <input type="text" name="description" maxlength="400" placeholder="What this group is for">
                        </label>
                        <button type="submit" class="button">Create</button>
                    </form>
                </details>
                <?php endif; ?>

                <?php if (empty($dbGroups)): ?>
                    <article class="folder-empty-state">
                        <?= portal_icon('users', 'folder-empty-icon') ?>
                        <p>No groups yet<?= portal_can_manage_course($courseId) ? '. Create one above.' : '.' ?></p>
                    </article>
                <?php else: ?>
                <?php $alreadyInAGroup = !empty($myGroupIds) && !portal_can_manage_course($courseId); ?>
                <?php if ($alreadyInAGroup): ?>
                    <p class="group-single-note">You can only be in one group on this module. Leave your current group before joining another.</p>
                <?php endif; ?>
                <div class="groups-grid">
                    <?php foreach ($dbGroups as $group): ?>
                    <?php
                        $gid       = (int)$group['id'];
                        $memberCnt = (int)$group['member_count'];
                        $maxM      = (int)$group['max_members'];
                        $isMember  = in_array($gid, $myGroupIds, true);
                        $isFull    = $maxM > 0 && $memberCnt >= $maxM;
                        $members   = $groupMembers[$gid] ?? [];
                    ?>
                    <article class="group-card">
                        <div class="group-card-head">
                            <h3><?= portal_escape($group['title']) ?></h3>
                            <span class="chip"><?= $memberCnt ?><?= $maxM > 0 ? ' / ' . $maxM : '' ?></span>
                        </div>
                        <p class="group-desc<?= trim((string) $group['description']) === '' ? ' is-empty' : '' ?>"><?= portal_escape((string) $group['description']) ?></p>

                        <?php if (portal_can_manage_course($courseId)): ?>
                            <?php if (!empty($members)): ?>
                            <div class="group-members-list">
                                <?php foreach ($members as $m): ?>
                                <span class="group-member-chip">
                                    <span class="course-staff-avatar sub-avatar"><?= portal_escape($m['initials']) ?></span>
                                    <?= portal_escape($m['name']) ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <form method="POST" class="group-delete-form">
                                <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                <input type="hidden" name="action" value="delete_group">
                                <input type="hidden" name="group_id" value="<?= $gid ?>">
                                <button type="submit" class="btn-danger-sm"><?= portal_icon('trash','icon-sm') ?> Delete</button>
                            </form>
                        <?php elseif ($isMember): ?>
                            <div class="group-joined-badge">✓ Joined</div>
                            <form method="POST">
                                <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                <input type="hidden" name="action" value="leave_group">
                                <input type="hidden" name="group_id" value="<?= $gid ?>">
                                <button type="submit" class="btn-danger-sm">Leave</button>
                            </form>
                        <?php elseif ($alreadyInAGroup): ?>
                            <span class="group-locked-badge">Leave your current group to join this one</span>
                        <?php elseif (!$isFull): ?>
                            <form method="POST">
                                <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                <input type="hidden" name="action" value="join_group">
                                <input type="hidden" name="group_id" value="<?= $gid ?>">
                                <button type="submit" class="button button--sm">Join</button>
                            </form>
                        <?php else: ?>
                            <span class="group-full-badge">Group full</span>
                        <?php endif; ?>
                    </article>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="course-aside-wrap">
            <?php if (!empty($courseTeachers) || !empty($course['updates'])): ?>
            <div class="course-mobile-peek">
                <?php if (!empty($courseTeachers)): ?>
                <div class="course-staff-peek">
                    <div class="course-staff-peek-avatars" aria-hidden="true">
                        <?php foreach (array_slice($courseTeachers, 0, 4) as $teacher): ?>
                            <?php
                                $_peekRole = (string) ($teacher['assignment_role'] ?? 'teacher');
                                $_peekSuper = $_peekRole === 'supervisor';
                            ?>
                            <span class="course-staff-peek-avatar <?= $_peekSuper ? 'supervisor-avatar' : 'teacher-avatar' ?>"><?= portal_escape($teacher['initials']) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="course-staff-peek-copy">
                        <strong>Taught by</strong>
                        <span><?= portal_escape(implode(', ', array_column(array_slice($courseTeachers, 0, 3), 'name'))) ?><?= count($courseTeachers) > 3 ? '…' : '' ?></span>
                    </div>
                </div>
                <?php endif; ?>
                <p class="course-update-peek">See More course info for the full staff list and latest updates.</p>
            </div>
            <?php endif; ?>
            <input type="checkbox" id="course-about-toggle" class="course-about-toggle">
            <label for="course-about-toggle" class="course-about-toggle-label">
                <span class="course-about-toggle-copy">
                    <strong>More course info</strong>
                    <small>Full staff list, updates &amp; structure</small>
                </span>
                <?= portal_icon('chevron-down', 'course-about-chevron') ?>
            </label>
        <aside class="stack course-aside">
            <article class="card-shell">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Course staff</p>
                        <h3 class="card-title">Who is teaching</h3>
                    </div>
                </div>

                <div class="course-staff-list">
                    <?php if (empty($courseTeachers)): ?>
                        <p class="folder-empty-note" style="padding:4px 0;">No course staff assigned yet.</p>
                    <?php else: ?>
                        <?php foreach ($courseTeachers as $teacher): ?>
                            <?php
                                $_assignRole = (string) ($teacher['assignment_role'] ?? 'teacher');
                                $_staffIsSupervisor = $_assignRole === 'supervisor';
                                $_assignLabel = portal_course_assignment_role_label($_assignRole);
                            ?>
                            <div class="course-staff-item">
                                <div class="course-staff-avatar <?= $_staffIsSupervisor ? 'supervisor-avatar' : 'teacher-avatar' ?>"><?= portal_escape($teacher['initials']) ?></div>
                                <div class="course-staff-info">
                                    <h4><?= portal_escape($teacher['name']) ?></h4>
                                    <span class="admin-badge <?= $_staffIsSupervisor ? 'admin-badge--supervisor' : 'admin-badge--teacher' ?>"><?= portal_escape($_assignLabel) ?></span>
                                </div>
                                <?php if (portal_is_admin()): ?>
                                    <div class="course-staff-admin-actions">
                                        <form method="POST" class="staff-role-form">
                                            <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                            <input type="hidden" name="action" value="change_assignment_role">
                                            <input type="hidden" name="user_id" value="<?= (int) $teacher['id'] ?>">
                                            <select name="assignment_role" class="admin-role-select" onchange="this.form.submit()" title="Change assignment">
                                                <option value="teacher"<?= $_assignRole === 'teacher' ? ' selected' : '' ?>>Course Teacher</option>
                                                <option value="supervisor"<?= $_assignRole === 'supervisor' ? ' selected' : '' ?>>Course Supervisor</option>
                                            </select>
                                        </form>
                                        <form method="POST" class="staff-remove-form">
                                            <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                            <input type="hidden" name="action" value="remove_teacher">
                                            <input type="hidden" name="user_id" value="<?= (int) $teacher['id'] ?>">
                                            <button type="submit" class="btn-icon-danger" title="Remove from course">
                                                <?= portal_icon('trash', 'icon-sm') ?>
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if (portal_is_admin()): ?>
                    <?php if (!empty($availableTeachers)): ?>
                        <details class="folder-admin-panel" style="margin-top:14px;">
                            <summary class="folder-admin-trigger folder-admin-trigger--sm">
                                <?= portal_icon('plus', 'icon-sm') ?>
                                <span>Assign course staff</span>
                            </summary>
                            <form method="POST" class="folder-admin-form folder-admin-form--inner">
                                <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                <input type="hidden" name="action" value="assign_teacher">
                                <label class="folder-form-label">
                                    <span>Select staff account</span>
                                    <select name="user_id">
                                        <?php foreach ($availableTeachers as $t): ?>
                                            <option value="<?= (int) $t['id'] ?>">
                                                <?= portal_escape($t['name']) ?>
                                                (<?= portal_escape(ucfirst((string) ($t['role'] ?? 'teacher'))) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="folder-form-label">
                                    <span>Assignment on this module</span>
                                    <select name="assignment_role">
                                        <option value="teacher">Course Teacher</option>
                                        <option value="supervisor">Course Supervisor</option>
                                    </select>
                                </label>
                                <button type="submit" class="button button--sm">Assign</button>
                            </form>
                        </details>
                    <?php elseif (empty($courseTeachers)): ?>
                        <p class="folder-empty-note" style="margin-top:10px;">No staff accounts are available to assign. Create an owner, admin, or teacher in Admin → Manage Users.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </article>

            <article class="card-shell course-aside-focus">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Section focus</p>
                        <h3 class="card-title"><?= portal_escape($currentSection['label']) ?></h3>
                    </div>
                </div>
                <p class="section-copy"><?= portal_escape($currentSection['description']) ?></p>
                <div class="button-row">
                    <a class="button" href="<?= portal_escape($tabLookup['content']) ?>">Main content</a>
                    <a class="button-secondary" href="<?= portal_escape($tabLookup['calendar']) ?>">Course calendar</a>
                </div>
            </article>

            <article class="card-shell" id="course-updates">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Latest updates</p>
                        <h3 class="card-title">What changed</h3>
                    </div>
                </div>

                <div class="course-update-list">
                    <?php foreach ($course['updates'] as $update): ?>
                        <article class="course-update-item">
                            <p><?= portal_escape($update) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="card-shell course-aside-structure">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Course structure</p>
                        <h3 class="card-title">Built to expand</h3>
                    </div>
                </div>
                <article class="schedule-note">
                    <p>The course space is set up to grow over time. New folders and teaching materials can be added later without changing the student navigation.</p>
                </article>
            </article>
        </aside>
        </div>
    </div>
</section>

<?php if (!empty($submissionModals)): ?>
<?= $submissionModals ?>
<?php endif; ?>

<div id="external-link-warning" class="external-link-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="external-link-title">
    <div class="external-link-dialog">
        <header class="external-link-header">
            <div>
                <p class="eyebrow">External source</p>
                <h3 id="external-link-title">You are about to leave this website</h3>
            </div>
            <button type="button" class="external-link-close" aria-label="Cancel">&times;</button>
        </header>
        <div class="external-link-body">
            <p class="external-link-message" data-external-link-message>Checking this link with Google Safe Browsing...</p>
            <p class="external-link-url" data-external-link-url></p>
            <div class="external-link-verdict" data-external-link-verdict>Checking...</div>
        </div>
        <footer class="external-link-actions">
            <button type="button" class="button button--ghost" data-external-link-cancel>Cancel</button>
            <button type="button" class="button" data-external-link-continue disabled>Continue</button>
        </footer>
    </div>
</div>

<?php if (!empty($unreadAnnouncements)): ?>
<!-- ── Unread announcements notification ─────────────────────────────────────── -->
<div id="ann-notification" class="ann-notify-overlay" hidden role="dialog" aria-modal="true" aria-label="New announcements">
    <div class="ann-notify-box">
        <div class="ann-notify-header">
            <div>
                <p class="eyebrow">New</p>
                <h3>Announcement<?= count($unreadAnnouncements) !== 1 ? 's' : '' ?></h3>
            </div>
            <button class="ann-notify-close" id="ann-notify-close" aria-label="Dismiss">×</button>
        </div>
        <div class="ann-notify-body">
            <?php foreach ($unreadAnnouncements as $ann): ?>
            <div class="ann-notify-item" data-ann-id="<?= (int) $ann['id'] ?>">
                <strong><?= portal_escape($ann['title']) ?></strong>
                <?php if ($ann['body'] !== ''): ?>
                    <div class="rich-body"><?= portal_render_rich_text($ann['body']) ?></div>
                <?php endif; ?>
                <span class="ann-notify-meta"><?= portal_escape(date('j M Y', strtotime($ann['created_at']))) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="ann-notify-footer">
            <button class="button" id="ann-mark-read">Mark as read</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Document viewer overlay (same-tab, smooth — mirrors the assignment review dialog) ── -->
<div id="doc-viewer-overlay" class="docviewer-overlay" hidden role="dialog" aria-modal="true" aria-label="Document viewer">
    <div class="docviewer-dialog">
        <header class="docviewer-dialog-header">
            <div class="docviewer-dialog-heading">
                <p class="eyebrow">Course document</p>
                <h3 id="doc-viewer-title">Document viewer</h3>
                <p class="docviewer-dialog-sub" id="doc-viewer-meta"></p>
            </div>
            <button type="button" class="docviewer-close" id="doc-viewer-close" aria-label="Close document viewer">
                <?= portal_icon('x', 'docviewer-close-icon') ?>
            </button>
        </header>
        <div class="docviewer-frame-wrap">
            <iframe id="doc-viewer-frame" class="docviewer-frame" title="Document viewer" allow="fullscreen" allowfullscreen tabindex="0"></iframe>
        </div>
    </div>
</div>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2/dist/quill.snow.css">
<script>
/* Inlined so Chrome cannot serve a stale/missing assets/upload-dropzone.js */
<?php
$portalUploadDndJs = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'upload-dropzone.js';
if (is_file($portalUploadDndJs)) {
    readfile($portalUploadDndJs);
} else {
    echo 'console.error("upload-dropzone.js missing");';
}
?>
</script>
<script src="https://cdn.jsdelivr.net/npm/quill@2/dist/quill.js"></script>
<script src="assets/portal-quill.js?v=20260713m"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/mammoth@1/mammoth.browser.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<div id="portal-page-data"
     data-slug="<?= portal_escape($slug) ?>"
     data-csrf="<?= portal_escape($csrfToken) ?>"
     data-can-manage="<?= portal_can_manage_course($courseId) ? '1' : '0' ?>"
     hidden></div>

<?php if (portal_can_manage_course($courseId)): ?>
<div class="reorder-mode-badge" id="reorder-mode-badge" hidden>
    <span>Moving mode — drag to rearrange folders and items</span>
    <button type="button" class="reorder-mode-done" id="reorder-mode-done">Done</button>
</div>
<?php endif; ?>
<script>
(function () {
    // External link safety warning (all users).
    const externalLinkModal = document.getElementById('external-link-warning');
    const externalLinkMessage = externalLinkModal?.querySelector('[data-external-link-message]');
    const externalLinkUrl = externalLinkModal?.querySelector('[data-external-link-url]');
    const externalLinkVerdict = externalLinkModal?.querySelector('[data-external-link-verdict]');
    const externalLinkContinue = externalLinkModal?.querySelector('[data-external-link-continue]');
    const externalLinkCancel = externalLinkModal?.querySelector('[data-external-link-cancel]');
    const externalLinkClose = externalLinkModal?.querySelector('.external-link-close');
    let pendingExternalUrl = '';

    function closeExternalLinkModal() {
        if (!externalLinkModal) return;
        externalLinkModal.classList.remove('external-link-overlay--in');
        window.setTimeout(() => {
            externalLinkModal.hidden = true;
            pendingExternalUrl = '';
        }, 160);
    }

    function openExternalLinkModal(url) {
        if (!externalLinkModal) return;
        pendingExternalUrl = url;
        externalLinkModal.hidden = false;
        externalLinkModal.classList.add('external-link-overlay--in');
        if (externalLinkMessage) {
            externalLinkMessage.textContent = 'You are about to visit an external source. External websites may expose you to unsafe content, tracking, or downloads.';
        }
        if (externalLinkUrl) externalLinkUrl.textContent = url;
        if (externalLinkVerdict) {
            externalLinkVerdict.className = 'external-link-verdict external-link-verdict--checking';
            externalLinkVerdict.textContent = 'Checking this URL with Google Safe Browsing...';
        }
        if (externalLinkContinue) {
            externalLinkContinue.disabled = false;
            externalLinkContinue.textContent = 'Continue';
        }
    }

    function applyExternalLinkVerdict(verdict) {
        if (!externalLinkVerdict || !externalLinkContinue) return;
        const status = verdict?.status || 'unchecked';
        const stats = verdict?.stats || null;
        const statText = stats
            ? ' Malicious: ' + (stats.malicious || 0) + ', suspicious: ' + (stats.suspicious || 0) + ', harmless: ' + (stats.harmless || 0) + '.'
            : '';
        externalLinkVerdict.className = 'external-link-verdict external-link-verdict--' + status;
        externalLinkVerdict.textContent = (verdict?.message || 'This link could not be verified automatically.') + statText;
        externalLinkContinue.disabled = status === 'invalid';
        externalLinkContinue.textContent = (status === 'malicious' || status === 'suspicious') ? 'Continue anyway' : 'Continue';
    }

    async function checkExternalLink(url) {
        const pd = document.getElementById('portal-page-data');
        const token = pd?.dataset.csrf || '';
        const slug = pd?.dataset.slug || new URLSearchParams(location.search).get('course') || '';
        const body = new URLSearchParams({ _token: token, action: 'check_external_link', url });
        const res = await fetch('course.php?course=' + encodeURIComponent(slug), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
            body: body.toString(),
        });
        return res.json();
    }

    document.addEventListener('click', e => {
        const trigger = e.target.closest('a[data-safe-external-link], .folder-item--external-link[data-safe-external-link]');
        if (!trigger || !externalLinkModal) return;
        const interactive = e.target.closest('button, input, select, textarea, summary, label, .item-drag-handle, .settings-panel');
        if (interactive) return;
        const url = trigger.dataset.safeUrl || trigger.href || trigger.getAttribute('href') || '';
        if (!url) return;
        e.preventDefault();
        openExternalLinkModal(url);
        checkExternalLink(url)
            .then(data => {
                if (!data.ok) {
                    applyExternalLinkVerdict({ status: 'unchecked', message: data.error || 'This link could not be verified automatically.' });
                    return;
                }
                if (data.url) {
                    pendingExternalUrl = data.url;
                    if (externalLinkUrl) externalLinkUrl.textContent = data.url;
                }
                applyExternalLinkVerdict(data.verdict || null);
            })
            .catch(() => {
                applyExternalLinkVerdict({ status: 'unchecked', message: 'This link could not be verified automatically. Treat it with caution.' });
            });
    });

    document.addEventListener('keydown', e => {
        const trigger = e.target.closest('[data-safe-external-link][role="link"]');
        if (!trigger || (e.key !== 'Enter' && e.key !== ' ')) return;
        e.preventDefault();
        trigger.click();
    });

    externalLinkContinue?.addEventListener('click', () => {
        if (!pendingExternalUrl) return;
        const url = pendingExternalUrl;
        closeExternalLinkModal();
        window.open(url, '_blank', 'noopener,noreferrer');
    });
    externalLinkCancel?.addEventListener('click', closeExternalLinkModal);
    externalLinkClose?.addEventListener('click', closeExternalLinkModal);
    externalLinkModal?.addEventListener('click', e => {
        if (e.target === externalLinkModal) closeExternalLinkModal();
    });
    // ── Document viewer overlay (same-tab, smooth) ──────────────────────────
    // Clicking a course document fades in a full-viewport lightbox (mirrors the
    // assignment-review dialog's open/close transition) with the redesigned
    // view.php loaded inside an iframe. Plain <a href> semantics are preserved
    // so ctrl/cmd/middle-click and "open in new tab" keep working natively.
    const docViewerOverlay = document.getElementById('doc-viewer-overlay');
    const docViewerFrame   = document.getElementById('doc-viewer-frame');
    const docViewerTitle   = document.getElementById('doc-viewer-title');
    const docViewerMeta    = document.getElementById('doc-viewer-meta');
    let docViewerLastFocus = null;

    function anyPortalOverlayOpen() {
        return !!document.querySelector('.rvw-overlay:not([hidden]), .sub-slot-overlay:not([hidden]), .docviewer-overlay:not([hidden])');
    }

    function focusDocViewerFrame() {
        if (!docViewerFrame || docViewerOverlay?.hidden) return;
        try { docViewerFrame.focus({ preventScroll: true }); } catch (_) {
            try { docViewerFrame.focus(); } catch (__) {}
        }
        try { docViewerFrame.contentWindow?.focus(); } catch (_) {}
    }

    function openDocViewer(url, link) {
        if (!docViewerOverlay || !docViewerFrame) { window.location.href = url; return; }
        docViewerLastFocus = document.activeElement;
        const extension = link?.querySelector('.file-ext-badge')?.textContent?.trim() || '';
        const title = Array.from(link?.childNodes || [])
            .filter(node => node.nodeType === Node.TEXT_NODE)
            .map(node => node.textContent.trim())
            .filter(Boolean)
            .join(' ') || link?.textContent?.replace(extension, '').trim() || 'Document viewer';
        if (docViewerTitle) docViewerTitle.textContent = title;
        if (docViewerMeta) docViewerMeta.textContent = extension ? extension + ' document' : '';
        try {
            const next = new URL(url, window.location.href);
            next.searchParams.set('embed', '1');
            docViewerFrame.src = next.pathname + next.search + next.hash;
        } catch (_) {
            docViewerFrame.src = url + (url.includes('?') ? '&' : '?') + 'embed=1';
        }
        docViewerOverlay.hidden = false;
        document.body.classList.add('sub-slot-body-lock');
        requestAnimationFrame(() => {
            docViewerOverlay.classList.add('docviewer-overlay--in');
            // Focus as soon as the dialog is visible so the next key/wheel
            // gesture goes to the viewer (not the course page underneath).
            focusDocViewerFrame();
        });
    }

    docViewerFrame?.addEventListener('load', () => {
        // Re-focus after the document finishes loading — setting src can
        // steal focus back to the parent during navigation.
        if (docViewerOverlay && !docViewerOverlay.hidden) {
            requestAnimationFrame(focusDocViewerFrame);
        }
    });

    function closeDocViewer() {
        if (!docViewerOverlay || docViewerOverlay.hidden) return;
        docViewerOverlay.classList.remove('docviewer-overlay--in');
        setTimeout(() => {
            docViewerOverlay.hidden = true;
            docViewerFrame.src = 'about:blank';
            if (!anyPortalOverlayOpen()) {
                document.body.classList.remove('sub-slot-body-lock');
            }
            if (docViewerLastFocus && typeof docViewerLastFocus.focus === 'function') docViewerLastFocus.focus();
        }, 220);
    }

    document.addEventListener('click', e => {
        const link = e.target.closest('.file-view-link[data-doc-viewer]');
        if (!link) return;
        if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        e.preventDefault();
        openDocViewer(link.href, link);
    });
    document.getElementById('doc-viewer-close')?.addEventListener('click', closeDocViewer);
    docViewerOverlay?.addEventListener('click', e => { if (e.target === docViewerOverlay) closeDocViewer(); });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && docViewerOverlay && !docViewerOverlay.hidden) closeDocViewer();
    });
    window.addEventListener('message', e => {
        if (e.origin !== window.location.origin) return;
        if (e.data && e.data.type === 'portal-doc-viewer-close') closeDocViewer();
    });

    // ── Shared client-side file renderers (used by the assignment review dialog) ─
    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function sanitizeDocHtml(html) {
        const raw = String(html || '');
        if (!raw.trim()) return '';
        if (window.DOMPurify && typeof DOMPurify.sanitize === 'function') {
            return DOMPurify.sanitize(raw, {
                USE_PROFILES: { html: true },
                FORBID_TAGS: ['script', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'link', 'meta', 'base'],
                ALLOW_DATA_ATTR: false,
            });
        }
        // Fail closed if the sanitizer CDN is blocked: never inject raw HTML.
        return '<p>' + escapeHtml(raw.replace(/<[^>]*>/g, ' ')) + '</p>';
    }

    // Split mammoth HTML into Letter-sized sheets (still one DOM tree so text
    // annotations keep working). Packs whole blocks; oversized blocks stay on
    // their own sheet rather than clipping mid-paragraph.
    function paginateReviewDocx(mount, html) {
        const PAGE_H = 1056;
        const empty = '<p class="rvw-doc-empty-msg">This document appears to be empty.</p>';
        const source = document.createElement('div');
        source.innerHTML = (html && String(html).trim()) ? html : empty;

        mount.innerHTML = '';
        mount.classList.add('rvw-docx-pages');

        function makePage(num) {
            const page = document.createElement('article');
            page.className = 'rvw-docx-page';
            page.dataset.page = String(num);
            const body = document.createElement('div');
            body.className = 'rvw-docx-page-body';
            const footer = document.createElement('div');
            footer.className = 'rvw-docx-page-num';
            footer.setAttribute('aria-hidden', 'true');
            footer.textContent = String(num);
            page.appendChild(body);
            page.appendChild(footer);
            return { page, body, footer };
        }

        let pageNum = 1;
        let current = makePage(pageNum);
        // Measure with unconstrained height while packing.
        current.page.style.height = 'auto';
        current.page.style.minHeight = '0';
        current.page.style.overflow = 'visible';
        mount.appendChild(current.page);

        function finalizePage(el) {
            el.style.height = '';
            el.style.minHeight = '';
            el.style.overflow = '';
            // A single oversized block (image/table) should grow the sheet rather than clip.
            if (el.scrollHeight > PAGE_H + 2) {
                el.style.height = 'auto';
                el.style.minHeight = PAGE_H + 'px';
                el.style.overflow = 'visible';
            }
        }

        const nodes = Array.from(source.childNodes);
        if (!nodes.length) {
            current.body.innerHTML = empty;
        }

        nodes.forEach(node => {
            current.body.appendChild(node);
            if (current.page.scrollHeight > PAGE_H && current.body.childNodes.length > 1) {
                current.body.removeChild(node);
                finalizePage(current.page);
                pageNum += 1;
                current = makePage(pageNum);
                current.page.style.height = 'auto';
                current.page.style.minHeight = '0';
                current.page.style.overflow = 'visible';
                mount.appendChild(current.page);
                current.body.appendChild(node);
            }
        });

        finalizePage(current.page);

        Array.from(mount.querySelectorAll('.rvw-docx-page')).forEach((el, i) => {
            const num = el.querySelector('.rvw-docx-page-num');
            if (num) num.textContent = String(i + 1);
            el.dataset.page = String(i + 1);
        });

        return mount.querySelectorAll('.rvw-docx-page').length;
    }

    function wireReviewPageNav(shell, opts) {
        if (!shell) return;
        const preservePage = !!(opts && opts.preservePage);
        const nav = shell.querySelector('[data-rvw-pagenav]');
        const scrollEl = shell.querySelector('.rvw-docx-scroll');
        if (!nav) return;

        const previousPage = (shell._rvwPageNav && shell._rvwPageNav.current) || 1;

        if (shell._rvwPageObserver) {
            shell._rvwPageObserver.disconnect();
            shell._rvwPageObserver = null;
        }

        function getPages() {
            return scrollEl ? Array.from(scrollEl.querySelectorAll('.rvw-docx-page')) : [];
        }

        const pages = getPages();
        if (!scrollEl || pages.length <= 1) {
            nav.hidden = true;
            shell._rvwPageNav = null;
            return;
        }

        const prevBtn = nav.querySelector('[data-rvw-page-prev]');
        const nextBtn = nav.querySelector('[data-rvw-page-next]');
        const input = nav.querySelector('[data-rvw-page-input]');
        const totalEl = nav.querySelector('[data-rvw-page-total]');
        let currentPage = 1;
        const pageCount = pages.length;

        nav.hidden = false;
        if (totalEl) totalEl.textContent = String(pageCount);
        if (input) {
            input.setAttribute('min', '1');
            input.setAttribute('max', String(pageCount));
            input.setAttribute('inputmode', 'numeric');
        }

        function setCurrentPage(n, syncInput) {
            if (!n || n < 1 || n > pageCount) return;
            currentPage = n;
            if (input && (syncInput || document.activeElement !== input)) {
                input.value = String(currentPage);
            }
            if (prevBtn) prevBtn.disabled = currentPage <= 1;
            if (nextBtn) nextBtn.disabled = currentPage >= pageCount;
        }

        function scrollToPage(n) {
            const livePages = getPages();
            const count = livePages.length || pageCount;
            if (!count) return;
            const target = Math.max(1, Math.min(count, Math.floor(Number(n)) || 1));
            const el = livePages[target - 1];
            if (el && scrollEl) {
                const pad = 8;
                const delta = el.getBoundingClientRect().top - scrollEl.getBoundingClientRect().top;
                const nextTop = Math.max(0, scrollEl.scrollTop + delta - pad);
                scrollEl.scrollTo({ top: nextTop, behavior: 'smooth' });
            }
            setCurrentPage(target, true);
        }

        function commitInputFromApi() {
            const api = shell._rvwPageNav;
            if (!api || !input) return;
            const raw = String(input.value || '').trim();
            const n = parseInt(raw, 10);
            if (raw === '' || isNaN(n) || n < 1 || n > api.pageCount) {
                input.value = String(api.current);
                return;
            }
            api.scrollToPage(n);
        }

        shell._rvwPageNav = {
            scrollToPage,
            pageCount,
            get current() { return currentPage; },
        };

        if (!nav.dataset.wired) {
            nav.dataset.wired = '1';
            prevBtn?.addEventListener('click', e => {
                e.preventDefault();
                e.stopPropagation();
                const api = shell._rvwPageNav;
                if (api) api.scrollToPage(api.current - 1);
            });
            nextBtn?.addEventListener('click', e => {
                e.preventDefault();
                e.stopPropagation();
                const api = shell._rvwPageNav;
                if (api) api.scrollToPage(api.current + 1);
            });
            input?.addEventListener('change', commitInputFromApi);
            input?.addEventListener('blur', commitInputFromApi);
            input?.addEventListener('keydown', e => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    commitInputFromApi();
                    input.blur();
                }
            });
            input?.addEventListener('input', () => {
                const cleaned = String(input.value || '').replace(/[^\d]/g, '');
                if (cleaned !== input.value) input.value = cleaned;
            });
        }

        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver(entries => {
                entries.forEach(entry => {
                    if (entry.isIntersecting && entry.intersectionRatio > 0.35) {
                        const live = getPages();
                        const idx = live.indexOf(entry.target);
                        if (idx >= 0) setCurrentPage(idx + 1, false);
                    }
                });
            }, { root: scrollEl, threshold: [0.35] });
            pages.forEach(el => observer.observe(el));
            shell._rvwPageObserver = observer;
        }

        setCurrentPage(preservePage ? Math.min(Math.max(1, previousPage), pageCount) : 1, true);
    }

    function renderSheetToTable(sheet) {
        const rows = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: '' });
        if (!rows.length) {
            return '<p class="rvw-doc-error">This workbook sheet is empty.</p>';
        }

        const maxCols = rows.reduce((max, row) => Math.max(max, Array.isArray(row) ? row.length : 0), 0);
        const header = '<tr>'
            + Array.from({ length: maxCols }, (_, i) => '<th>Col ' + (i + 1) + '</th>').join('')
            + '</tr>';

        const body = rows.map(row => {
            const cells = Array.from({ length: maxCols }, (_, i) => '<td>' + escapeHtml(row[i] ?? '') + '</td>').join('');
            return '<tr>' + cells + '</tr>';
        }).join('');

        return '<div class="xlsx-wrap"><table class="xlsx-table"><thead>' + header + '</thead><tbody>' + body + '</tbody></table></div>';
    }

    // ── Tab settings toggle ───────────────────────────────────────────────────
    const settingsBtn   = document.getElementById('tab-settings-btn');
    const settingsPanel = document.getElementById('tab-settings-panel');
    if (settingsBtn && settingsPanel) {
        settingsBtn.addEventListener('click', () => {
            const open = !settingsPanel.hidden;
            settingsPanel.hidden = open;
            settingsBtn.setAttribute('aria-expanded', String(!open));
            settingsBtn.classList.toggle('course-tab--active', !open);
        });
    }

    // ── Item type → show/hide fields ─────────────────────────────────────────
    document.querySelectorAll('.settings-toggle[data-settings-target]').forEach(btn => {
        btn.addEventListener('click', e => {
            e.preventDefault();
            e.stopPropagation();
            const target = document.getElementById(btn.dataset.settingsTarget);
            const card = btn.closest('details.folder-card');
            if (card) card.open = true;
            if (target) target.hidden = !target.hidden;
        });
    });

    // ── Auto-prepend https:// to bare domains in URL fields ──────────────────
    // These fields accept bare domains like "www.google.com" (the server
    // normalises and validates them properly), so we use type="text" instead
    // of the stricter native type="url" which would reject anything missing
    // a scheme. This just tidies the value up for display/consistency.
    (function () {
        const normalizeUrlValue = (raw) => {
            const value = raw.trim();
            if (value === '' || /^https?:\/\//i.test(value)) return value;
            if (/^[a-z0-9][a-z0-9-]*(?:\.[a-z0-9][a-z0-9-]*)+(?:[\/?#].*)?$/i.test(value)) {
                return 'https://' + value;
            }
            return value;
        };
        document.querySelectorAll('input[name="url"], input[name="room"]').forEach(input => {
            input.addEventListener('blur', () => {
                input.value = normalizeUrlValue(input.value);
            });
            const form = input.closest('form');
            if (form) {
                form.addEventListener('submit', () => {
                    input.value = normalizeUrlValue(input.value);
                }, true);
            }
        });
    })();

    // ── File upload dropzones + progress (desktop DnD, not folder reorder) ──
    function uploadHasFiles(dt) {
        if (!dt) return false;
        if (dt.files && dt.files.length > 0) return true;
        const types = dt.types;
        if (!types) return true; // some Windows sources omit types until drop
        if (typeof types.contains === 'function') {
            return types.contains('Files')
                || types.contains('application/x-moz-file')
                || types.length === 0;
        }
        const list = Array.from(types);
        if (list.length === 0) return true;
        return list.includes('Files')
            || list.includes('application/x-moz-file')
            || list.some(t => /file/i.test(String(t)));
    }

    function setUploadFilename(zone, name) {
        const el = zone?.querySelector('[data-upload-filename]');
        const row = zone?.querySelector('[data-upload-file-row]');
        if (el) el.textContent = name || '';
        if (row) row.classList.toggle('is-hidden', !name);
        else if (el) el.classList.toggle('is-hidden', !name);
    }

    function clearUploadFile(zone) {
        if (!zone) return;
        const input = zone.querySelector('[data-upload-input]');
        if (input) {
            input.value = '';
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }
        setUploadError(zone, '');
        setUploadProgress(zone, 0, false);
        syncUploadDropzoneState(zone);
    }

    function setUploadProgress(zone, pct, visible) {
        if (!zone) return;
        const wrap = zone.querySelector('[data-upload-progress]');
        const bar = zone.querySelector('[data-upload-progress-bar]');
        const label = zone.querySelector('[data-upload-progress-label]');
        if (!wrap) return;
        if (!visible) {
            wrap.classList.add('is-hidden');
            zone.classList.remove('is-uploading');
            if (bar) bar.style.width = '0%';
            if (label) label.textContent = '0%';
            return;
        }
        wrap.classList.remove('is-hidden');
        zone.classList.add('is-uploading');
        const safe = Math.max(0, Math.min(100, Math.round(pct || 0)));
        if (bar) bar.style.width = safe + '%';
        if (label) label.textContent = safe + '%';
    }

    function setUploadError(zone, msg) {
        const el = zone?.querySelector('[data-upload-error]');
        if (!el) return;
        if (msg) {
            el.textContent = msg;
            el.classList.remove('is-hidden');
        } else {
            el.textContent = '';
            el.classList.add('is-hidden');
        }
    }

    function assignFileToInput(input, file) {
        if (!input || !file) return false;
        try {
            const dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
            if (!input.files || input.files.length === 0) return false;
            input.dispatchEvent(new Event('change', { bubbles: true }));
            return true;
        } catch (_) {
            return false;
        }
    }

    function assignFileListToInput(input, fileList) {
        if (!input || !fileList || !fileList.length) return false;
        try {
            input.files = fileList;
            if (input.files && input.files.length) {
                input.dispatchEvent(new Event('change', { bubbles: true }));
                return true;
            }
        } catch (_) { /* fall through */ }
        return assignFileToInput(input, fileList[0]);
    }

    function syncUploadDropzoneState(zone) {
        if (!zone) return;
        const input = zone.querySelector('[data-upload-input]');
        zone.classList.toggle('is-disabled', !!(input && input.disabled));
        const file = input?.files?.[0];
        setUploadFilename(zone, file ? file.name : '');
    }

    function initUploadDropzones(scope) {
        (scope || document).querySelectorAll('[data-upload-dropzone]').forEach(zone => {
            if (zone.dataset.uploadBound === '1') return;
            zone.dataset.uploadBound = '1';
            const input = zone.querySelector('[data-upload-input]');
            if (!input) return;

            // accept= makes Chrome show the X cursor on OS file drags.
            input.removeAttribute('accept');
            delete input.dataset.uploadAcceptAll;

            zone.querySelector('[data-upload-clear]')?.addEventListener('click', e => {
                e.preventDefault();
                e.stopPropagation();
                clearUploadFile(zone);
            });
            input.addEventListener('change', () => {
                setUploadError(zone, '');
                syncUploadDropzoneState(zone);
            });
            syncUploadDropzoneState(zone);
        });
    }

    // OS file DnD lives in assets/upload-dropzone.js (loaded earlier) so a later
    // script error in this file cannot disable drag-and-drop.
    if (!window.__portalUploadDnd) {
      console.warn('upload-dropzone.js did not load — drag-and-drop may not work');
    }

    function xhrFormUpload(url, formData, onProgress) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', url);
            xhr.setRequestHeader('X-Requested-With', 'fetch');
            xhr.responseType = 'text';
            xhr.upload.onprogress = e => {
                if (e.lengthComputable && typeof onProgress === 'function') {
                    onProgress(Math.round((e.loaded / e.total) * 100));
                }
            };
            xhr.onload = () => {
                let data = null;
                try {
                    data = JSON.parse(xhr.responseText || '{}');
                } catch (_) {
                    reject(new Error('Invalid response'));
                    return;
                }
                resolve({ status: xhr.status, data });
            };
            xhr.onerror = () => reject(new Error('Network error'));
            xhr.send(formData);
        });
    }

    initUploadDropzones(document);

    document.querySelectorAll('form.folder-admin-form').forEach(form => {
        const actionInput = form.querySelector('input[name="action"]');
        if (!actionInput || actionInput.value !== 'create_item') return;
        form.addEventListener('submit', async e => {
            const fileInput = form.querySelector('.item-file-input');
            const hasFile = !!(fileInput && fileInput.files && fileInput.files.length);
            if (!hasFile) return;

            e.preventDefault();
            const zone = form.querySelector('[data-upload-dropzone]');
            const btn = form.querySelector('button[type="submit"]');
            const origLabel = btn ? btn.textContent : '';
            setUploadError(zone, '');
            setUploadProgress(zone, 0, true);
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Uploading…';
            }
            try {
                const body = new FormData(form);
                const { data } = await xhrFormUpload(
                    window.location.pathname + window.location.search,
                    body,
                    pct => setUploadProgress(zone, pct, true)
                );
                if (!data || !data.ok) {
                    setUploadProgress(zone, 0, false);
                    setUploadError(zone, (data && data.error) || 'Upload failed.');
                    return;
                }
                setUploadProgress(zone, 100, true);
                window.location.href = data.redirect || (window.location.pathname + window.location.search);
            } catch (_) {
                setUploadProgress(zone, 0, false);
                setUploadError(zone, 'Could not upload. Please try again.');
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = origLabel;
                }
            }
        });
    });

    document.querySelectorAll('.item-type-select').forEach(sel => {
        const update = () => {
            const form      = sel.closest('form');
            const fileGrp   = form.querySelector('.item-file-group');
            const fileInput = form.querySelector('.item-file-input');
            const fileLabel = form.querySelector('.item-file-label');
            const urlGrp    = form.querySelector('.item-url-group');
            const urlLabel  = form.querySelector('.item-url-label');
            const urlInput  = form.querySelector('input[name="url"]');
            const subGrp    = form.querySelector('.item-submission-group');
            const actGrp    = form.querySelector('.item-activity-group');
            const dlOpt     = form.querySelector('input[name="allow_download"]')?.closest('label');
            const type      = sel.value;
            const isVideo   = type === 'video';
            const isActivity = type === 'activity';
            if (fileGrp) fileGrp.style.display  = type === 'link' || type === 'submission' || isActivity ? 'none' : '';
            if (urlGrp)  urlGrp.style.display   = type === 'submission' || isActivity ? 'none' : '';
            if (subGrp)  subGrp.style.display    = type === 'submission' ? '' : 'none';
            if (actGrp)  actGrp.style.display    = isActivity ? '' : 'none';
            if (dlOpt)   dlOpt.style.display     = (type === 'document' || isVideo) ? '' : 'none';
            if (fileInput && fileLabel) {
                fileInput.setAttribute('accept', isVideo ? fileInput.dataset.videoAccept : fileInput.dataset.docAccept);
                fileLabel.innerHTML = isVideo ? fileInput.dataset.videoHint : fileInput.dataset.docHint;
            }
            if (urlLabel) {
                urlLabel.innerHTML = type === 'link'
                    ? 'Link URL <small>(required)</small>'
                    : (isVideo ? 'Or paste a video link <small>(YouTube or Vimeo only, for student safety)</small>' : 'Or paste URL <small>(optional)</small>');
            }
            if (urlInput) {
                urlInput.required = type === 'link';
                urlInput.placeholder = isVideo
                    ? (urlInput.dataset.videoPlaceholder || urlInput.placeholder)
                    : (urlInput.dataset.docPlaceholder || urlInput.placeholder);
            }
        };
        sel.addEventListener('change', update);
        update();
    });

    // ── Unread announcement notification ──────────────────────────────────────
    (function () {
        const overlay  = document.getElementById('ann-notification');
        if (!overlay) return;

        const pd    = document.getElementById('portal-page-data');
        const slug  = pd?.dataset.slug  ?? '';
        const token = pd?.dataset.csrf  ?? '';

        async function markAndClose() {
            if (overlay.hidden) return;
            const ids = [...overlay.querySelectorAll('[data-ann-id]')].map(el => el.dataset.annId);
            const params = new URLSearchParams({ _token: token, action: 'mark_announcements_read' });
            ids.forEach(id => params.append('announcement_ids[]', id));
            try {
                await fetch('course.php?course=' + encodeURIComponent(slug), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: params,
                });
            } catch (_) {}
            // Remove the unread badge from the Announcements tab
            document.querySelector('.course-tab[href*="section=announcements"] .course-tab-badge')?.remove();
            overlay.classList.remove('ann-notify--in');
            overlay.classList.add('ann-notify--out');
            const hideOverlay = () => {
                overlay.hidden = true;
                overlay.classList.remove('ann-notify--out');
            };
            overlay.addEventListener('animationend', hideOverlay, { once: true });
            setTimeout(hideOverlay, 400); // fallback if animationend never fires
        }

        document.getElementById('ann-mark-read')?.addEventListener('click', markAndClose);
        document.getElementById('ann-notify-close')?.addEventListener('click', markAndClose);
        overlay.addEventListener('click', e => { if (e.target === overlay) markAndClose(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') markAndClose(); });

        // Reveal only when ready to animate — never leave an invisible click trap.
        overlay.hidden = false;
        requestAnimationFrame(() => overlay.classList.add('ann-notify--in'));
    })();

    // ── Submission slot modal (all users) ───────────────────────────────────
    let openSubSlotModal = null;

    function openSubSlot(overlay) {
        if (!overlay) return;
        if (openSubSlotModal && openSubSlotModal !== overlay) closeSubSlot(openSubSlotModal);
        overlay.hidden = false;
        overlay.classList.add('sub-slot-overlay--in');
        document.body.classList.add('sub-slot-body-lock');
        openSubSlotModal = overlay;
    }

    function closeSubSlot(overlay) {
        if (!overlay) return;
        overlay.classList.remove('sub-slot-overlay--in');
        let closed = false;
        const finish = () => {
            if (closed) return;
            closed = true;
            overlay.hidden = true;
            if (openSubSlotModal === overlay) {
                openSubSlotModal = null;
                if (!anyPortalOverlayOpen()) {
                    document.body.classList.remove('sub-slot-body-lock');
                }
            }
        };
        overlay.addEventListener('transitionend', finish, { once: true });
        setTimeout(finish, 220);
    }

    document.querySelectorAll('.sub-slot-card[data-sub-modal]').forEach(card => {
        card.addEventListener('click', e => {
            if (e.target.closest('[data-sub-open-edit]')) return;
            const modal = document.getElementById(card.dataset.subModal || '');
            if (modal) openSubSlot(modal);
        });
        card.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                const modal = document.getElementById(card.dataset.subModal || '');
                if (modal) openSubSlot(modal);
            }
        });
    });

    document.querySelectorAll('[data-sub-open-edit]').forEach(btn => {
        btn.addEventListener('click', e => {
            e.preventDefault();
            e.stopPropagation();
            const modal = document.getElementById(btn.dataset.subOpenEdit || '');
            if (!modal) return;
            openSubSlot(modal);
            if (btn.dataset.subOpenEditForm === '1') {
                const panel = modal.querySelector('[data-sub-slot-edit-panel]');
                if (panel) panel.hidden = false;
            }
        });
    });

    document.querySelectorAll('.sub-slot-dialog-close').forEach(btn => {
        btn.addEventListener('click', () => {
            const overlay = btn.closest('.sub-slot-overlay');
            if (overlay) closeSubSlot(overlay);
        });
    });

    document.querySelectorAll('.sub-slot-overlay').forEach(overlay => {
        overlay.addEventListener('click', e => {
            if (e.target === overlay) closeSubSlot(overlay);
        });
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && openSubSlotModal) closeSubSlot(openSubSlotModal);
    });

    document.querySelectorAll('.submit-work-form').forEach(form => {
        const startedAt = Date.now();
        const textArea = form.querySelector('textarea[name="submission_text"]');
        const editField = form.querySelector('input[name="process_edit_seconds"]');
        const pasteField = form.querySelector('input[name="process_paste_events"]');
        const pastedCharsField = form.querySelector('input[name="process_pasted_chars"]');
        const typeSelect = form.querySelector('[data-sub-type-select]');
        const fileInput = form.querySelector('[data-sub-file-input]');
        let pasteEvents = 0;
        let pastedChars = 0;

        if (typeSelect && fileInput) {
            const allowedTypes = Array.from(typeSelect.options)
                .map(o => (o.value || '').toLowerCase())
                .filter(Boolean);

            const fileExt = (file) => {
                const name = String(file?.name || '');
                const dot = name.lastIndexOf('.');
                return dot >= 0 ? name.slice(dot + 1).toLowerCase() : '';
            };

            const applyDetectedType = (file) => {
                const zone = form.querySelector('[data-upload-dropzone]');
                if (!file) {
                    setUploadError(zone, '');
                    syncUploadDropzoneState(zone);
                    return true;
                }
                let ext = fileExt(file);
                // Normalize common aliases
                if (ext === 'jpeg') ext = allowedTypes.includes('jpeg') ? 'jpeg' : 'jpg';
                if (!allowedTypes.includes(ext)) {
                    fileInput.value = '';
                    setUploadError(zone, 'Unsupported file type. Use PDF, Word, PowerPoint, text, or an image.');
                    syncUploadDropzoneState(zone);
                    return false;
                }
                setUploadError(zone, '');
                // Always force the dropdown to the real extension (do not leave a
                // previously chosen type like PDF selected for a .docx file).
                typeSelect.value = ext;
                fileInput.removeAttribute('accept');
                syncUploadDropzoneState(zone);
                const pptxNote = form.querySelector('[data-sub-pptx-note]');
                if (pptxNote) pptxNote.classList.toggle('is-hidden', ext !== 'pptx');
                return true;
            };

            const syncType = () => {
                const type = (typeSelect.value || '').toLowerCase();
                fileInput.disabled = false;
                const file = fileInput.files?.[0];
                if (file && type) {
                    const ext = fileExt(file);
                    // File wins: if the chosen type disagrees with the file, fix the type.
                    if (ext !== type && allowedTypes.includes(ext)) {
                        typeSelect.value = ext;
                    } else if (ext !== type) {
                        fileInput.value = '';
                        setUploadError(form.querySelector('[data-upload-dropzone]'),
                            'Selected type does not match the file. Drop the file again or pick a matching type.');
                    }
                }
                fileInput.removeAttribute('accept');
                const zone = form.querySelector('[data-upload-dropzone]');
                syncUploadDropzoneState(zone);
                setUploadProgress(zone, 0, false);
                const pptxNote = form.querySelector('[data-sub-pptx-note]');
                if (pptxNote) pptxNote.classList.toggle('is-hidden', (typeSelect.value || '') !== 'pptx');
            };

            typeSelect.addEventListener('change', syncType);
            fileInput.addEventListener('change', () => {
                applyDetectedType(fileInput.files?.[0] || null);
                const pptxNote = form.querySelector('[data-sub-pptx-note]');
                if (pptxNote) {
                    pptxNote.classList.toggle('is-hidden', (typeSelect.value || '').toLowerCase() !== 'pptx');
                }
            });
            syncType();
        }

        if (textArea) {
            textArea.addEventListener('paste', e => {
                pasteEvents += 1;
                pastedChars += (e.clipboardData?.getData('text') || '').length;
                if (pasteField) pasteField.value = String(pasteEvents);
                if (pastedCharsField) pastedCharsField.value = String(pastedChars);
            });

            const counterEl = form.querySelector('[data-sub-word-count]');
            const minWords = parseInt(textArea.dataset.minWords || '0', 10);
            if (counterEl && minWords > 0) {
                const updateCounter = () => {
                    const words = (textArea.value.match(/[a-z0-9]+(?:'[a-z0-9]+)?/gi) || []).length;
                    counterEl.textContent = words + ' / ' + minWords + ' word' + (minWords === 1 ? '' : 's') + ' minimum';
                    counterEl.classList.toggle('submit-word-count--ok', words >= minWords);
                    counterEl.classList.toggle('submit-word-count--low', words > 0 && words < minWords);
                };
                textArea.addEventListener('input', updateCounter);
                updateCounter();
            }
        }

        form.addEventListener('submit', async e => {
            e.preventDefault();
            if (editField) {
                editField.value = String(Math.max(0, Math.round((Date.now() - startedAt) / 1000)));
            }
            if (pasteField) pasteField.value = String(pasteEvents);
            if (pastedCharsField) pastedCharsField.value = String(pastedChars);

            const hasPaste = !!(textArea && textArea.value.trim());
            const hasFile = !!(fileInput && fileInput.files && fileInput.files.length);
            if (hasFile && typeSelect && !typeSelect.value) {
                // Last-chance auto-detect if the type select was cleared.
                const name = String(fileInput.files[0].name || '');
                const dot = name.lastIndexOf('.');
                const ext = dot >= 0 ? name.slice(dot + 1).toLowerCase() : '';
                const match = Array.from(typeSelect.options).some(o => o.value === ext);
                if (match) {
                    typeSelect.value = ext;
                } else {
                    const errEl = form.querySelector('[data-sub-error]');
                    if (errEl) {
                        errEl.textContent = 'Unsupported file type. Use PDF, Word, PowerPoint, text, or an image.';
                        errEl.classList.add('is-visible');
                    }
                    return;
                }
            }
            if (!hasFile && !hasPaste) {
                const errEl = form.querySelector('[data-sub-error]');
                if (errEl) {
                    errEl.textContent = 'Upload a document or paste your submission text before submitting.';
                    errEl.classList.add('is-visible');
                }
                return;
            }

            const btn = form.querySelector('[data-sub-submit-btn]') || form.querySelector('button[type="submit"]');
            const origLabel = btn ? btn.textContent : '';
            const errEl = form.querySelector('[data-sub-error]');
            const zone = form.querySelector('[data-upload-dropzone]');
            if (errEl) {
                errEl.textContent = '';
                errEl.classList.remove('is-visible');
            }
            if (btn) {
                btn.disabled = true;
                btn.textContent = hasFile ? 'Uploading…' : 'Submitting…';
            }
            if (hasFile) setUploadProgress(zone, 0, true);

            try {
                // Disabled file inputs are omitted from FormData — re-enable briefly if needed.
                const wasDisabled = fileInput && fileInput.disabled;
                if (wasDisabled) fileInput.disabled = false;
                const body = new FormData(form);
                if (wasDisabled) fileInput.disabled = true;

                let data;
                if (hasFile) {
                    const result = await xhrFormUpload(
                        window.location.pathname + window.location.search,
                        body,
                        pct => setUploadProgress(zone, pct, true)
                    );
                    data = result.data;
                } else {
                    const res = await fetch(window.location.pathname + window.location.search, {
                        method: 'POST',
                        body,
                        headers: { 'X-Requested-With': 'fetch' }
                    });
                    data = await res.json();
                }
                if (!data.ok) {
                    setUploadProgress(zone, 0, false);
                    const msg = data.error || 'Submission failed.';
                    if (errEl) {
                        errEl.textContent = msg;
                        errEl.classList.add('is-visible');
                    } else {
                        alert(msg);
                    }
                    return;
                }
                if (hasFile) setUploadProgress(zone, 100, true);
                handleSubmitSuccess(form, data);
                setUploadProgress(zone, 0, false);
                setUploadFilename(zone, '');
            } catch (_) {
                setUploadProgress(zone, 0, false);
                const msg = 'Could not submit. Please try again.';
                if (errEl) {
                    errEl.textContent = msg;
                    errEl.classList.add('is-visible');
                } else {
                    alert(msg);
                }
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = origLabel;
                }
            }
        });
    });

    function escapeHtmlText(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function handleSubmitSuccess(form, data) {
        const itemId = data.item_id;
        const card = document.querySelector('.sub-slot-card[data-item-id="' + itemId + '"]');
        const modal = form.closest('.sub-slot-overlay')
            || document.querySelector('.sub-slot-overlay[data-item-id="' + itemId + '"]');

        if (card) {
            const row = card.querySelector('[data-sub-card-row]');
            if (row) {
                row.innerHTML = '<span class="sub-slot-file" data-sub-card-file><span>'
                    + escapeHtmlText(data.filename) + '</span></span>'
                    + '<span class="sub-slot-status sub-slot-status--pending" data-sub-card-status>Not graded</span>';
            }
            const meta = card.querySelector('[data-sub-card-meta]');
            if (meta) {
                meta.textContent = 'Submitted ' + (data.submitted_at_label || '');
                meta.classList.remove('is-hidden');
                meta.classList.add('is-visible');
            }
            card.classList.add('sub-slot-card--flash');
            window.setTimeout(() => card.classList.remove('sub-slot-card--flash'), 1200);
        }

        document.querySelectorAll('[data-sub-attempts-note][data-max-attempts]').forEach(el => {
            const cardOrModal = el.closest('.sub-slot-card, .sub-slot-overlay');
            const belongsToItem = cardOrModal && cardOrModal.dataset.itemId === String(itemId);
            if (belongsToItem && data.max_attempts) {
                el.textContent = 'Attempt ' + Math.min(data.attempts_used, data.max_attempts) + ' of ' + data.max_attempts + ' used';
            }
        });

        if (modal) {
            const mine = modal.querySelector('[data-sub-mine]');
            if (mine) {
                mine.classList.add('is-visible');
                const fnSpan = mine.querySelector('[data-sub-filename] span');
                if (fnSpan) fnSpan.textContent = data.filename || '';
                const dateEl = mine.querySelector('[data-sub-date]');
                if (dateEl) dateEl.textContent = 'Submitted ' + (data.submitted_at_label || '');
                const badge = mine.querySelector('[data-sub-grade-badge]');
                if (badge) {
                    badge.className = 'sub-modal-grade sub-modal-grade--pending';
                    badge.textContent = 'Not graded';
                }
                const reviewBtn = mine.querySelector('[data-sub-review-btn]');
                if (reviewBtn && data.submission_id) {
                    reviewBtn.dataset.reviewOpen = 'rvw-' + data.submission_id;
                    reviewBtn.dataset.reviewRefresh = '1';
                    reviewBtn.classList.remove('is-hidden');
                }
            }

            const success = modal.querySelector('[data-sub-success]');
            if (success) {
                success.textContent = '';
                const icon = document.createElement('span');
                icon.className = 'sub-submit-success-icon';
                icon.setAttribute('aria-hidden', 'true');
                icon.textContent = '✓';
                const msg = document.createElement('span');
                msg.textContent = data.message || 'Submission received.';
                success.appendChild(icon);
                success.appendChild(msg);
                success.classList.add('is-visible');
                window.setTimeout(() => success.classList.remove('is-visible'), 6000);
            }

            const receiptCard = modal.querySelector('[data-sub-receipt-card]') || form.querySelector('[data-sub-receipt-card]');
            if (receiptCard && data.receipt_number) {
                const setText = (sel, value) => {
                    const el = receiptCard.querySelector(sel);
                    if (el) el.textContent = value || '—';
                };
                setText('[data-sub-receipt-number]', data.receipt_number);
                setText('[data-sub-receipt-student]', (data.student_name || '') + (data.student_username ? ' (' + data.student_username + ')' : ''));
                setText('[data-sub-receipt-course]', data.course_title || '');
                setText('[data-sub-receipt-assignment]', data.assignment_title || '');
                setText('[data-sub-receipt-file]', data.filename || '');
                setText('[data-sub-receipt-type]', data.declared_type || '');
                setText('[data-sub-receipt-when]', data.submitted_at_label || '');
                setText('[data-sub-receipt-hash]', data.file_sha256_prefix ? (data.file_sha256_prefix + '…') : '—');
                receiptCard.hidden = false;
                receiptCard.classList.remove('is-hidden');

                const copyBtn = receiptCard.querySelector('[data-sub-receipt-copy]');
                if (copyBtn && !copyBtn.dataset.bound) {
                    copyBtn.dataset.bound = '1';
                    copyBtn.addEventListener('click', () => {
                        const num = receiptCard.querySelector('[data-sub-receipt-number]')?.textContent || '';
                        if (num && navigator.clipboard) {
                            navigator.clipboard.writeText(num).catch(() => {});
                        }
                    });
                }
                const printBtn = receiptCard.querySelector('[data-sub-receipt-print]');
                if (printBtn && !printBtn.dataset.bound) {
                    printBtn.dataset.bound = '1';
                    printBtn.addEventListener('click', () => {
                        document.body.classList.add('printing-sub-receipt');
                        receiptCard.classList.add('is-print-target');
                        window.print();
                        window.setTimeout(() => {
                            document.body.classList.remove('printing-sub-receipt');
                            receiptCard.classList.remove('is-print-target');
                        }, 300);
                    });
                }
            }

            const title = modal.querySelector('[data-sub-submit-title]');
            if (title) title.textContent = 'Re-submit work';
            const submitBtn = form.querySelector('[data-sub-submit-btn]');
            if (submitBtn) submitBtn.textContent = 'Re-submit';
            const keptType = form.querySelector('[data-sub-type-select]')?.value || '';
            form.reset();
            const typeSelect = form.querySelector('[data-sub-type-select]');
            const fileInput = form.querySelector('[data-sub-file-input]');
            if (typeSelect) {
                typeSelect.value = '';
                typeSelect.dispatchEvent(new Event('change'));
            }
            if (fileInput) {
                fileInput.value = '';
                fileInput.disabled = false;
                const zone = form.querySelector('[data-upload-dropzone]');
                syncUploadDropzoneState(zone);
            }
            void keptType;
            const section = form.closest('[data-sub-submit-section]');
            if (section) {
                section.classList.add('sub-modal-section--done');
                window.setTimeout(() => section.classList.remove('sub-modal-section--done'), 1200);

                if (data.attempts_reached) {
                    const fields = section.querySelector('[data-sub-submit-fields]');
                    const closedMsg = section.querySelector('[data-sub-attempts-closed-msg]');
                    if (fields) fields.classList.add('is-hidden');
                    if (closedMsg) closedMsg.classList.remove('is-hidden');
                }
            }
        }

        const shell = document.querySelector('.rvw-shell[data-submission-id="' + data.submission_id + '"]');
        if (shell) {
            const staleOverlay = shell.closest('.rvw-overlay');
            if (staleOverlay) {
                staleOverlay.remove();
            }
            delete shell.dataset.previewLoaded;
            const mount = shell.querySelector('[data-preview-mount]');
            if (mount) {
                mount.innerHTML = '<p class="rvw-doc-loading">Loading document…</p>';
            }
            clearAnnotateBaseIfExists(shell);
        }
    }

    function clearAnnotateBaseIfExists(shell) {
        const surface = shell.querySelector('[data-preview-mount]') || shell.querySelector('[data-annotate-surface]');
        if (!surface) return;
        delete surface.dataset.annotateBaseStored;
        delete surface.dataset.baseHtml;
        delete surface.dataset.baseText;
    }

    // ── Turnitin-style submission review (all users) ────────────────────────
    (function initReview() {
        const tokenInput = document.querySelector('input[name="_token"]');
        const csrf = tokenInput ? tokenInput.value : '';
        const courseParam = new URLSearchParams(location.search).get('course') || '';
        let openReview = null;

        function escapeHtml(s) {
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        // Badge digits live in the DOM but must not affect char offsets used for
        // annotation ranges (otherwise later highlights shift and clip letters).
        function isAnnotateMetaText(node) {
            return !!(node && node.parentElement && node.parentElement.closest('sup.rvw-hl-badge'));
        }

        function offsetInNode(root, node, offset) {
            let total = 0;
            const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
            let cur;
            while ((cur = walker.nextNode())) {
                if (isAnnotateMetaText(cur)) continue;
                if (cur === node) return total + offset;
                total += cur.textContent.length;
            }
            if (node === root) {
                let n = 0, sum = 0;
                for (const child of root.childNodes) {
                    if (n >= offset) break;
                    sum += child.textContent.length;
                    n++;
                }
                return sum;
            }
            return total;
        }

        function closeCommentOverlay() {
            document.querySelectorAll('.rvw-comment-overlay').forEach(el => el.remove());
            if (window._rvwCommentOverlayTimer) {
                clearTimeout(window._rvwCommentOverlayTimer);
                window._rvwCommentOverlayTimer = null;
            }
        }

        function positionCommentOverlay(pop, anchorEl) {
            if (!pop) return;
            const margin = 12;
            const popW = pop.offsetWidth || 300;
            const popH = pop.offsetHeight || 160;
            let x;
            let y;
            const rect = anchorEl && anchorEl.getBoundingClientRect
                ? anchorEl.getBoundingClientRect()
                : null;
            const anchorVisible = rect
                && rect.bottom > margin
                && rect.top < window.innerHeight - margin
                && rect.right > margin
                && rect.left < window.innerWidth - margin;

            if (anchorVisible) {
                // Prefer centered under the highlight; flip above if it would overflow.
                x = rect.left + (rect.width / 2) - (popW / 2);
                y = rect.bottom + 10;
                if (y + popH > window.innerHeight - margin) {
                    y = rect.top - popH - 10;
                }
            } else {
                // Anchor off-screen / missing: settle in a stable viewport spot.
                x = (window.innerWidth - popW) / 2;
                y = Math.max(margin, Math.round(window.innerHeight * 0.18));
            }

            x = Math.min(Math.max(margin, x), window.innerWidth - popW - margin);
            y = Math.min(Math.max(margin, y), window.innerHeight - popH - margin);
            pop.style.left = Math.round(x) + 'px';
            pop.style.top = Math.round(y) + 'px';
        }

        function showCommentOverlay(ann, anchorEl, opts) {
            closeCommentOverlay();
            if (!ann) return;
            const delay = opts && opts.delay > 0 ? opts.delay : 0;

            const mount = () => {
                const pop = document.createElement('div');
                pop.className = 'rvw-comment-overlay';
                pop.setAttribute('role', 'dialog');
                pop.setAttribute('aria-label', 'Comment');
                const quote = (ann.quote || '').trim();
                const quoteHtml = quote
                    ? '<p class="rvw-comment-overlay-quote">\u201C' + escapeHtml(quote.length > 140 ? quote.slice(0, 137) + '\u2026' : quote) + '\u201D</p>'
                    : '';
                const author = (ann.author || '').trim();
                pop.innerHTML = '<button type="button" class="rvw-comment-overlay-close" aria-label="Close comment" title="Close">'
                    + '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false">'
                    + '<path d="M6.7 6.7l10.6 10.6M17.3 6.7L6.7 17.3" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>'
                    + '</svg></button>'
                    + quoteHtml
                    + '<p class="rvw-comment-overlay-body">' + escapeHtml(ann.comment || '') + '</p>'
                    + (author ? '<p class="rvw-comment-overlay-author">' + escapeHtml(author) + '</p>' : '');
                document.body.appendChild(pop);

                positionCommentOverlay(pop, anchorEl);
                // Re-measure after layout so height-based flip is accurate.
                requestAnimationFrame(() => {
                    positionCommentOverlay(pop, anchorEl);
                    pop.classList.add('is-open');
                });
                pop.querySelector('.rvw-comment-overlay-close').addEventListener('click', e => {
                    e.stopPropagation();
                    closeCommentOverlay();
                });
            };

            if (delay) {
                window._rvwCommentOverlayTimer = setTimeout(() => {
                    window._rvwCommentOverlayTimer = null;
                    mount();
                }, delay);
            } else {
                mount();
            }
        }

        function openReviewOverlay(overlay) {
            if (!overlay) return;
            overlay.hidden = false;
            document.body.classList.add('sub-slot-body-lock');
            openReview = overlay;
            requestAnimationFrame(() => overlay.classList.add('rvw-overlay--in'));
            const shell = overlay.querySelector('.rvw-shell');
            if (shell) setReviewMobilePanel(shell, shell.getAttribute('data-mobile-panel') || 'results');
            if (shell && shell.dataset.previewLoaded === '1') wireReviewPageNav(shell);
        }

        function setReviewMobilePanel(shell, panel) {
            if (!shell) return;
            const next = panel === 'results' ? 'results' : 'doc';
            shell.setAttribute('data-mobile-panel', next);
            shell.querySelectorAll('.rvw-mobile-tab').forEach(tab => {
                const active = tab.getAttribute('data-rvw-mobile-panel') === next;
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
            });
        }

        document.addEventListener('click', e => {
            const tab = e.target.closest('[data-rvw-mobile-panel]');
            if (!tab) return;
            const shell = tab.closest('.rvw-shell');
            if (!shell) return;
            e.preventDefault();
            setReviewMobilePanel(shell, tab.getAttribute('data-rvw-mobile-panel'));
        });
        function reloadAndOpenReview(reviewId) {
            if (!reviewId) return;
            const url = new URL(window.location.href);
            url.searchParams.set('open_review', reviewId);
            if (!url.searchParams.has('section')) {
                url.searchParams.set('section', 'content');
            }
            window.location.href = url.toString();
        }
        function closeReviewOverlay(overlay) {
            if (!overlay) return;
            closeComposer();
            closeCommentOverlay();
            overlay.classList.remove('rvw-overlay--in');
            setTimeout(() => {
                overlay.hidden = true;
                if (openReview === overlay) openReview = null;
                if (!anyPortalOverlayOpen()) {
                    document.body.classList.remove('sub-slot-body-lock');
                }
            }, 200);
        }

        document.addEventListener('keydown', e => {
            if (!openReview || openReview.hidden) return;
            if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
            const tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
            if (tag === 'input' || tag === 'textarea' || tag === 'select' || (e.target && e.target.isContentEditable)) return;
            const shell = openReview.querySelector('.rvw-shell');
            const navApi = shell && shell._rvwPageNav;
            if (!navApi) return;
            e.preventDefault();
            if (e.key === 'ArrowLeft') navApi.scrollToPage(navApi.current - 1);
            else navApi.scrollToPage(navApi.current + 1);
        });

        document.addEventListener('click', e => {
            const btn = e.target.closest('[data-review-open]');
            if (!btn || !btn.dataset.reviewOpen) return;
            e.stopPropagation();
            if (btn.dataset.reviewRefresh === '1') {
                reloadAndOpenReview(btn.dataset.reviewOpen);
                return;
            }
            const overlay = document.getElementById(btn.dataset.reviewOpen);
            if (!overlay) {
                reloadAndOpenReview(btn.dataset.reviewOpen);
                return;
            }
            openReviewOverlay(overlay);
            const shell = overlay?.querySelector('.rvw-shell');
            if (shell) loadSubmissionPreview(shell);
        });
        document.addEventListener('keydown', e => {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            const card = e.target.closest('.sub-slot-card[data-review-open]');
            if (!card || e.target.closest('button, a, input, textarea, select')) return;
            e.preventDefault();
            card.click();
        });
        document.querySelectorAll('.rvw-overlay .rvw-close').forEach(btn => {
            btn.addEventListener('click', () => closeReviewOverlay(btn.closest('.rvw-overlay')));
        });
        document.querySelectorAll('.rvw-overlay').forEach(ov => {
            ov.addEventListener('click', e => { if (e.target === ov) closeReviewOverlay(ov); });
        });
        document.addEventListener('keydown', e => {
            if (e.key !== 'Escape') return;
            const pop = document.querySelector('.rvw-popover');
            if (pop) {
                closeComposer();
                return;
            }
            if (document.querySelector('.rvw-comment-overlay')) {
                closeCommentOverlay();
                return;
            }
            if (openReview) closeReviewOverlay(openReview);
        });
        document.addEventListener('pointerdown', e => {
            const overlay = document.querySelector('.rvw-comment-overlay');
            if (!overlay) return;
            if (overlay.contains(e.target)) return;
            if (e.target.closest('mark.rvw-hl, .rvw-comment, .rvw-pin, .rvw-popover')) return;
            closeCommentOverlay();
        });

        const requestedReview = new URLSearchParams(location.search).get('open_review') || '';
        if (requestedReview) {
            const overlay = document.getElementById(requestedReview);
            if (overlay) {
                openReviewOverlay(overlay);
                const shell = overlay.querySelector('.rvw-shell');
                if (shell) loadSubmissionPreview(shell);
                const clean = new URL(window.location.href);
                clean.searchParams.delete('open_review');
                history.replaceState(null, '', clean.toString());
            }
        }

        function showGradeView(block) {
            const view = block.querySelector('.rvw-grade-view');
            const form = block.querySelector('[data-grade-form]');
            if (view) view.classList.add('is-visible');
            if (form) form.classList.remove('is-visible');
        }

        function showGradeEdit(block) {
            const view = block.querySelector('.rvw-grade-view');
            const form = block.querySelector('[data-grade-form]');
            if (view) view.classList.remove('is-visible');
            if (form) form.classList.add('is-visible');
        }

        function applyGradePosted(block, score, feedback) {
            const scoreEl = block.querySelector('[data-grade-score-display]');
            if (scoreEl) scoreEl.innerHTML = String(score) + '<small>/100</small>';
            const fbView = block.querySelector('[data-grade-feedback-view]');
            const fbText = block.querySelector('[data-grade-feedback-text]');
            const noFb = block.querySelector('[data-grade-no-feedback]');
            if (feedback) {
                if (fbText) fbText.textContent = feedback;
                fbView?.classList.add('is-visible');
                noFb?.classList.remove('is-visible');
            } else {
                fbView?.classList.remove('is-visible');
                noFb?.classList.add('is-visible');
            }
            const posted = block.querySelector('.rvw-grade-posted');
            posted?.classList.add('is-posted-flash');
            window.setTimeout(() => posted?.classList.remove('is-posted-flash'), 1200);
            showGradeView(block);
            block.querySelector('.rvw-grade-cancel')?.classList.add('is-visible');
        }

        function updateGradeBadges(subId, score) {
            const entry = document.querySelector('.sub-modal-entry[data-submission-id="' + subId + '"]');
            const grade = entry?.querySelector('.sub-modal-grade');
            if (grade) {
                grade.className = 'sub-modal-grade sub-modal-grade--marked';
                grade.innerHTML = String(score) + '<small>/100</small>';
            }
            const shell = document.querySelector('.rvw-shell[data-submission-id="' + subId + '"]');
            if (shell && shell.dataset.canAnnotate !== '1') {
                const display = shell.querySelector('.rvw-grade-display');
                const big = shell.querySelector('.rvw-grade-big');
                const pending = shell.querySelector('.rvw-grade-pending');
                if (display && !big) {
                    display.innerHTML = '<span class="rvw-grade-big">' + String(score) + '<small>/100</small></span>';
                } else if (big) {
                    big.innerHTML = String(score) + '<small>/100</small>';
                }
                if (pending) pending.remove();
            }
        }

        document.querySelectorAll('[data-grade-block]').forEach(block => {
            const form = block.querySelector('[data-grade-form]');
            block.querySelector('.rvw-grade-edit')?.addEventListener('click', () => showGradeEdit(block));
            block.querySelector('.rvw-grade-cancel')?.addEventListener('click', () => showGradeView(block));
            if (!form) return;
            form.addEventListener('submit', async e => {
                e.preventDefault();
                const btn = form.querySelector('[data-grade-submit]');
                const orig = btn ? btn.textContent : '';
                if (btn) {
                    btn.disabled = true;
                    btn.textContent = 'Saving…';
                }
                try {
                    const res = await fetch(window.location.pathname + window.location.search, {
                        method: 'POST',
                        body: new FormData(form),
                        headers: { 'X-Requested-With': 'fetch' }
                    });
                    const data = await res.json();
                    if (data.ok) {
                        applyGradePosted(block, data.score, data.feedback);
                        const shell = block.closest('.rvw-shell');
                        if (shell?.dataset.submissionId) {
                            updateGradeBadges(shell.dataset.submissionId, data.score);
                        }
                    } else {
                        alert(data.error || 'Could not save grade.');
                    }
                } catch (_) {
                    alert('Could not save grade.');
                } finally {
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = orig;
                    }
                }
            });
        });

        function postAction(params) {
            const body = new URLSearchParams(params);
            body.set('_token', csrf);
            return fetch('course.php?course=' + encodeURIComponent(courseParam), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
                body: body.toString()
            }).then(r => r.json());
        }

        function normalizeAnn(a) {
            return {
                id: parseInt(a.id, 10),
                anchor_type: a.anchor_type,
                range_start: a.range_start != null ? parseInt(a.range_start, 10) : null,
                range_end: a.range_end != null ? parseInt(a.range_end, 10) : null,
                quote: a.quote || '',
                pos_x: a.pos_x != null ? parseFloat(a.pos_x) : null,
                pos_y: a.pos_y != null ? parseFloat(a.pos_y) : null,
                comment: a.comment || '',
                author: a.author_name || a.author || ''
            };
        }

        function closeComposer() {
            document.querySelectorAll('.rvw-popover').forEach(p => p.remove());
            closeCommentOverlay();
            document.querySelectorAll('[data-preview-mount], [data-annotate-surface]').forEach(surface => {
                clearPendingHighlight(surface);
            });
        }

        function clearPendingHighlight(surface) {
            if (!surface) return;
            surface.querySelectorAll('mark.rvw-hl--pending').forEach(mark => {
                const parent = mark.parentNode;
                if (!parent) return;
                while (mark.firstChild) parent.insertBefore(mark.firstChild, mark);
                parent.removeChild(mark);
                parent.normalize();
            });
        }

        function applyPendingHighlight(surface, start, end) {
            if (!surface) return;
            clearPendingHighlight(surface);
            const startPos = findTextPosition(surface, start);
            const endPos = findTextPosition(surface, end);
            if (!startPos || !endPos) return;

            const walker = document.createTreeWalker(surface, NodeFilter.SHOW_TEXT);
            const spanned = [];
            let node;
            let collecting = false;
            while ((node = walker.nextNode())) {
                if (isAnnotateMetaText(node)) continue;
                if (node === startPos.node) collecting = true;
                if (collecting) spanned.push(node);
                if (node === endPos.node) break;
            }

            spanned.forEach(n => {
                const from = (n === startPos.node) ? startPos.offset : 0;
                const to = (n === endPos.node) ? endPos.offset : n.textContent.length;
                if (to <= from) return;
                const range = document.createRange();
                range.setStart(n, from);
                range.setEnd(n, to);
                const mark = document.createElement('mark');
                mark.className = 'rvw-hl rvw-hl--pending';
                mark.dataset.pending = '1';
                try {
                    range.surroundContents(mark);
                } catch (_) { /* skip unclean wraps */ }
            });
        }

        function clearAnnotateBase(surface) {
            if (!surface) return;
            delete surface.dataset.annotateBaseStored;
            delete surface.dataset.baseHtml;
            delete surface.dataset.baseText;
        }

        function getAnnotateSurface(shell) {
            const mode = shell.dataset.previewMode || '';
            const mount = shell.querySelector('[data-preview-mount]');
            if (mount && (mode === 'docx' || mode === 'txt')) {
                if (mount.querySelector('.rvw-doc-loading')) return null;
                if (mount.textContent.trim()) return mount;
            }
            const plain = shell.querySelector('.rvw-text[data-annotate-surface]');
            if (plain) return plain;
            if (mode === 'pdf' || mode === 'office') {
                const layer = shell.querySelector('.rvw-text-layer[data-annotate-surface]');
                if (layer) return layer;
            }
            return null;
        }

        function findTextPosition(root, charIndex) {
            let offset = 0;
            const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
            let node;
            while ((node = walker.nextNode())) {
                if (isAnnotateMetaText(node)) continue;
                const len = node.textContent.length;
                if (offset + len >= charIndex) {
                    return { node, offset: charIndex - offset };
                }
                offset += len;
            }
            return null;
        }

        function wrapRangeInSurface(root, start, end, annId, badgeNum, onClick) {
            const startPos = findTextPosition(root, start);
            const endPos = findTextPosition(root, end);
            if (!startPos || !endPos) return;

            // Collect every text node spanned by the range first (read-only pass)
            // so a highlight that crosses paragraph/block boundaries never ends up
            // nesting a block element (e.g. <p>) inside the inline <mark>, which
            // corrupts the layout. Each text node gets its own <mark> instead.
            const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
            const spanned = [];
            let node;
            let collecting = false;
            while ((node = walker.nextNode())) {
                if (isAnnotateMetaText(node)) continue;
                if (node === startPos.node) collecting = true;
                if (collecting) spanned.push(node);
                if (node === endPos.node) break;
            }
            if (!spanned.length) return;

            const marks = [];
            spanned.forEach(n => {
                const from = (n === startPos.node) ? startPos.offset : 0;
                const to = (n === endPos.node) ? endPos.offset : n.textContent.length;
                if (to <= from) return;
                const range = document.createRange();
                range.setStart(n, from);
                range.setEnd(n, to);
                const mark = document.createElement('mark');
                mark.className = 'rvw-hl';
                mark.dataset.ann = annId;
                try {
                    range.surroundContents(mark);
                    marks.push(mark);
                } catch (_) { /* skip nodes that can't be wrapped cleanly */ }
            });

            if (!marks.length) return;

            // Start/mid/end classes keep multi-node wraps (e.g. bold run splits)
            // looking like one continuous highlight instead of separate boxes.
            marks.forEach((mark, i) => {
                if (i === 0) mark.classList.add('rvw-hl--start');
                if (i === marks.length - 1) mark.classList.add('rvw-hl--end');
                if (i > 0 && i < marks.length - 1) mark.classList.add('rvw-hl--mid');
            });

            // Keep the badge outside the <mark> so outlines/backgrounds don't
            // fragment around the superscript.
            if (badgeNum) {
                const badge = document.createElement('sup');
                badge.className = 'rvw-hl-badge';
                badge.textContent = String(badgeNum);
                badge.setAttribute('aria-hidden', 'true');
                const last = marks[marks.length - 1];
                if (last.parentNode) {
                    last.parentNode.insertBefore(badge, last.nextSibling);
                }
            }
            marks.forEach(mark => mark.addEventListener('click', onClick));
        }

        function storeAnnotateBase(surface) {
            if (!surface || surface.dataset.annotateBaseStored === '1') return;
            if (surface.querySelector('p, h1, h2, h3, pre, table, li')) {
                surface.dataset.baseHtml = surface.innerHTML;
            } else {
                surface.dataset.baseText = surface.textContent;
            }
            surface.dataset.annotateBaseStored = '1';
        }

        function restoreAnnotateBase(surface) {
            if (!surface) return;
            if (surface.dataset.baseHtml != null) {
                surface.innerHTML = surface.dataset.baseHtml;
            } else if (surface.dataset.baseText != null) {
                surface.textContent = surface.dataset.baseText;
            }
        }

        async function loadSubmissionPreview(shell) {
            if (!shell) return;
            if (shell.dataset.previewLoaded === '1') {
                wireReviewPageNav(shell);
                return;
            }

            const mode = shell.dataset.previewMode || '';
            const subId = shell.dataset.submissionId || '';
            const mount = shell.querySelector('[data-preview-mount]');
            const docEl = shell.querySelector('.rvw-doc');

            if (docEl && docEl.querySelector('.rvw-iframe')) {
                docEl.classList.add('rvw-doc--iframe');
            }

            if (!mount || !subId) {
                if (mode === 'pdf' || mode === 'office' || mode === 'viewer' || mode === 'pptx') {
                    shell.dataset.previewLoaded = '1';
                }
                return;
            }

            const url = 'download.php?sub=' + encodeURIComponent(subId) + '&view=1';
            const showErr = msg => {
                mount.innerHTML = '<p class="rvw-doc-error">' + escapeHtml(msg) + '</p>';
            };

            try {
                if (mode === 'docx') {
                    if (typeof mammoth === 'undefined') {
                        showErr('Document preview library failed to load. Please refresh the page.');
                        return;
                    }
                    const resp = await fetch(url);
                    if (!resp.ok) {
                        showErr('Could not load document.');
                        return;
                    }
                    const buf = await resp.arrayBuffer();
                    const result = await mammoth.convertToHtml({ arrayBuffer: buf });
                    const safeHtml = sanitizeDocHtml(result.value || '');
                    paginateReviewDocx(mount, safeHtml);
                } else if (mode === 'xlsx') {
                    if (typeof XLSX === 'undefined') {
                        showErr('Spreadsheet preview library failed to load. Please refresh the page.');
                        return;
                    }
                    const resp = await fetch(url);
                    if (!resp.ok) {
                        showErr('Could not load spreadsheet.');
                        return;
                    }
                    const buf = await resp.arrayBuffer();
                    const wb = XLSX.read(buf, { type: 'array' });
                    const sheetName = wb.SheetNames[0];
                    const sheet = sheetName ? wb.Sheets[sheetName] : null;
                    if (!sheet) {
                        showErr('Could not read this workbook.');
                        return;
                    }
                    mount.innerHTML = '<div class="viewer-sheet-head">Sheet: ' + escapeHtml(sheetName) + '</div>'
                        + renderSheetToTable(sheet);
                } else if (mode === 'pptx') {
                    // Download-only: no in-browser slide render.
                    shell.dataset.previewLoaded = '1';
                    return;
                } else if (mode === 'txt') {
                    const resp = await fetch(url);
                    if (!resp.ok) {
                        showErr('Could not load file.');
                        return;
                    }
                    const content = await resp.text();
                    mount.innerHTML = '<pre class="rvw-txt-pre">' + escapeHtml(content) + '</pre>';
                } else {
                    return;
                }
                const surface = getAnnotateSurface(shell);
                if (surface) {
                    clearAnnotateBase(surface);
                    storeAnnotateBase(surface);
                }
                shell.dispatchEvent(new CustomEvent('rvw-preview-loaded'));
                // Also call render directly in case the event listener isn't ready yet
                if (typeof shell._rvwRender === 'function') shell._rvwRender();
                // Wire page nav AFTER annotation render — render restores innerHTML and
                // would otherwise detach the page nodes the nav was holding.
                if (mode === 'docx') wireReviewPageNav(shell);
                shell.dataset.previewLoaded = '1';
            } catch (_) {
                showErr('Could not load preview.');
            }
        }

        document.querySelectorAll('.rvw-shell').forEach(shell => {
            const subId = shell.dataset.submissionId;
            const canAnnotate = shell.dataset.canAnnotate === '1';
            const dataEl = shell.querySelector('.rvw-annotations-data');
            let annotations = [];
            try { annotations = JSON.parse((dataEl && dataEl.textContent) || '[]'); } catch (_) { annotations = []; }

            const commentsEl = shell.querySelector('[data-comments]');
            const imageWrap = shell.querySelector('[data-image-layer]');
            const pinsEl = shell.querySelector('[data-pins]');

            const docEl = shell.querySelector('.rvw-doc');
            if (docEl && docEl.querySelector('.rvw-iframe')) {
                docEl.classList.add('rvw-doc--iframe');
            }

            const plainSurface = shell.querySelector('.rvw-text[data-annotate-surface]');
            if (plainSurface) storeAnnotateBase(plainSurface);

            function render() {
                renderComments();
                renderHighlights();
                if (pinsEl) renderPins();
                if (shell.dataset.previewMode === 'docx') wireReviewPageNav(shell, { preservePage: true });
            }

            function renderHighlights() {
                const surface = getAnnotateSurface(shell);
                if (!surface) return;
                storeAnnotateBase(surface);
                restoreAnnotateBase(surface);
                // Sort by position to assign sequential badge numbers
                const textAnns = annotations
                    .filter(a => a.anchor_type === 'text' && a.range_start != null && a.range_end != null && a.range_end > a.range_start)
                    .sort((a, b) => a.range_start - b.range_start);
                // Apply in reverse order so earlier wraps don't shift later offsets
                const reversed = textAnns.slice().sort((a, b) => b.range_start - a.range_start);
                reversed.forEach(a => {
                    const idx = textAnns.indexOf(a); // sequential index in doc order
                    const range = resolveAnnotationRange(surface, a);
                    wrapRangeInSurface(surface, range.start, range.end, String(a.id), idx + 1, () => focusComment(a.id));
                });
            }

            function annotatePlainText(surface) {
                let out = '';
                const walker = document.createTreeWalker(surface, NodeFilter.SHOW_TEXT);
                let node;
                while ((node = walker.nextNode())) {
                    if (isAnnotateMetaText(node)) continue;
                    out += node.textContent;
                }
                return out;
            }

            // Repair ranges saved while badge digits were wrongly counted in offsets.
            function resolveAnnotationRange(surface, ann) {
                let start = ann.range_start;
                let end = ann.range_end;
                const quote = String(ann.quote || '').replace(/\s+/g, ' ').trim();
                if (!quote || start == null || end == null) return { start, end };
                const text = annotatePlainText(surface);
                const sliced = text.slice(start, end).replace(/\s+/g, ' ').trim();
                if (sliced === quote) return { start, end };
                // Prefer a match near the stored start (typical off-by-N badge drift).
                const windowFrom = Math.max(0, start - 30);
                const windowTo = Math.min(text.length, end + 30);
                const windowText = text.slice(windowFrom, windowTo);
                const localIdx = windowText.indexOf(quote);
                if (localIdx >= 0) {
                    const fixed = windowFrom + localIdx;
                    return { start: fixed, end: fixed + quote.length };
                }
                const globalIdx = text.indexOf(quote);
                if (globalIdx >= 0) {
                    return { start: globalIdx, end: globalIdx + quote.length };
                }
                return { start, end };
            }

            function renderPins() {
                pinsEl.innerHTML = '';
                let n = 0;
                annotations.filter(a => a.anchor_type === 'image' && a.pos_x != null && a.pos_y != null).forEach(a => {
                    n++;
                    const pin = document.createElement('button');
                    pin.type = 'button';
                    pin.className = 'rvw-pin';
                    pin.dataset.ann = a.id;
                    pin.style.left = a.pos_x + '%';
                    pin.style.top = a.pos_y + '%';
                    pin.textContent = String(n);
                    pin.addEventListener('click', () => focusComment(a.id));
                    pinsEl.appendChild(pin);
                });
            }

            function renderComments() {
                commentsEl.innerHTML = '';
                if (!annotations.length) {
                    const p = document.createElement('p');
                    p.className = 'rvw-comments-empty';
                    p.textContent = canAnnotate
                        ? 'No comments yet. Select text or click the image to add one.'
                        : 'No comments from your teacher yet.';
                    commentsEl.appendChild(p);
                    return;
                }
                // Sequential badge numbers match highlight badges in the document
                const textAnnsOrdered = annotations
                    .filter(a => a.anchor_type === 'text' && a.range_start != null)
                    .sort((a, b) => a.range_start - b.range_start);
                annotations.forEach(a => {
                    const card = document.createElement('div');
                    card.className = 'rvw-comment';
                    card.dataset.ann = a.id;
                    let inner = '';
                    const badgeIdx = textAnnsOrdered.indexOf(a);
                    if (badgeIdx >= 0) {
                        inner += '<span class="rvw-comment-num" aria-hidden="true">' + (badgeIdx + 1) + '</span>';
                    }
                    if (a.quote) inner += '<p class="rvw-comment-quote">\u201C' + escapeHtml(a.quote) + '\u201D</p>';
                    inner += '<p class="rvw-comment-body">' + escapeHtml(a.comment) + '</p>';
                    inner += '<div class="rvw-comment-meta"><span class="rvw-comment-author">' + escapeHtml(a.author || '') + '</span>';
                    if (canAnnotate) {
                        inner += '<span class="rvw-comment-actions">'
                            + '<button type="button" class="rvw-comment-btn" data-edit="' + a.id + '">Edit</button>'
                            + '<button type="button" class="rvw-comment-btn rvw-comment-btn--danger" data-del="' + a.id + '">Delete</button>'
                            + '</span>';
                    }
                    inner += '</div>';
                    card.innerHTML = inner;
                    card.addEventListener('click', e => {
                        if (e.target.closest('[data-edit],[data-del]')) return;
                        focusComment(a.id);
                    });
                    commentsEl.appendChild(card);
                });
                if (canAnnotate) {
                    commentsEl.querySelectorAll('[data-del]').forEach(b =>
                        b.addEventListener('click', () => deleteAnnotation(parseInt(b.dataset.del, 10))));
                    commentsEl.querySelectorAll('[data-edit]').forEach(b =>
                        b.addEventListener('click', () => {
                            const a = annotations.find(x => x.id === parseInt(b.dataset.edit, 10));
                            if (a) openComposer({ existing: a });
                        }));
                }
            }

            function focusComment(id) {
                shell.querySelectorAll('.rvw-comment--active').forEach(el => el.classList.remove('rvw-comment--active'));
                shell.querySelectorAll('mark.rvw-hl--active').forEach(el => {
                    el.classList.remove('rvw-hl--active');
                    el.classList.remove('rvw-hl--pulse');
                });
                shell.querySelectorAll('.rvw-pin--active').forEach(el => el.classList.remove('rvw-pin--active'));

                const ann = annotations.find(a => String(a.id) === String(id));
                const card = commentsEl.querySelector('.rvw-comment[data-ann="' + id + '"]');
                if (card) {
                    card.classList.add('rvw-comment--active');
                    card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                }

                const marks = shell.querySelectorAll('mark.rvw-hl[data-ann="' + id + '"]');
                let anchorEl = null;
                let willScroll = false;
                if (marks.length) {
                    const mark = marks[0];
                    anchorEl = marks[marks.length - 1];
                    marks.forEach(m => m.classList.add('rvw-hl--active'));
                    // Scroll the document pane to the highlighted text
                    const scrollContainer = shell.querySelector('.rvw-docx-scroll') || shell.querySelector('.rvw-doc');
                    if (scrollContainer) {
                        const markRect = mark.getBoundingClientRect();
                        const containerRect = scrollContainer.getBoundingClientRect();
                        const offset = (markRect.top - containerRect.top) + scrollContainer.scrollTop
                                     - (scrollContainer.clientHeight / 2) + (markRect.height / 2);
                        const delta = Math.abs(offset - scrollContainer.scrollTop);
                        if (delta > 8) {
                            willScroll = true;
                            scrollContainer.scrollTo({ top: offset, behavior: 'smooth' });
                        }
                    } else {
                        mark.scrollIntoView({ block: 'center', behavior: 'smooth' });
                        willScroll = true;
                    }
                    // Pulse animation to draw the eye
                    marks.forEach(m => {
                        m.classList.add('rvw-hl--pulse');
                        m.addEventListener('animationend', () => m.classList.remove('rvw-hl--pulse'), { once: true });
                    });
                }

                const pin = shell.querySelector('.rvw-pin[data-ann="' + id + '"]');
                if (pin) {
                    pin.classList.add('rvw-pin--active');
                    if (!anchorEl) anchorEl = pin;
                }

                // Wait for smooth scroll so the overlay anchors to the final highlight position.
                if (ann) {
                    showCommentOverlay(ann, anchorEl || card, { delay: willScroll ? 340 : 0 });
                }
            }

            function saveAnnotation(payload) {
                return postAction(Object.assign({ action: 'save_annotation', submission_id: subId }, payload)).then(res => {
                    if (res && res.ok && res.annotation) {
                        const norm = normalizeAnn(res.annotation);
                        const idx = annotations.findIndex(a => a.id === norm.id);
                        if (idx >= 0) annotations[idx] = norm; else annotations.push(norm);
                        render();
                        focusComment(norm.id);
                    } else if (res && res.error) {
                        alert(res.error);
                    }
                }).catch(() => alert('Could not save the comment.'));
            }

            function deleteAnnotation(id) {
                if (!confirm('Delete this comment?')) return;
                postAction({ action: 'delete_annotation', annotation_id: id }).then(res => {
                    if (res && res.ok) {
                        annotations = annotations.filter(a => a.id !== id);
                        render();
                    }
                });
            }

            function openComposer(opts) {
                closeComposer();
                if (opts.anchor_type === 'text' && opts.start != null && opts.end != null) {
                    const surface = getAnnotateSurface(shell);
                    if (surface) applyPendingHighlight(surface, opts.start, opts.end);
                }
                const pop = document.createElement('div');
                pop.className = 'rvw-popover';
                const quoteHtml = opts.quote
                    ? '<p class="rvw-popover-quote">“' + escapeHtml(opts.quote.length > 120 ? opts.quote.slice(0, 117) + '…' : opts.quote) + '”</p>'
                    : '';
                pop.innerHTML = (opts.existing ? '' : quoteHtml)
                    + '<textarea placeholder="Write a comment\u2026" rows="3"></textarea>'
                    + '<div class="rvw-popover-actions">'
                    + '<button type="button" class="button button--sm button--ghost" data-cancel>Cancel</button>'
                    + '<button type="button" class="button button--sm" data-save>Save</button></div>';
                document.body.appendChild(pop);
                // Position near selection, flip above if near bottom of viewport.
                const popW = 280;
                const popH = 180;
                let x = opts.x != null ? opts.x : window.innerWidth / 2;
                let y = opts.y != null ? opts.y : window.innerHeight / 2;
                if (y + popH > window.innerHeight - 12) {
                    y = Math.max(12, (opts.y != null ? opts.y : y) - popH - 24);
                }
                x = Math.min(Math.max(12, x), window.innerWidth - popW - 12);
                y = Math.min(Math.max(12, y), window.innerHeight - popH - 12);
                pop.style.left = x + 'px';
                pop.style.top = y + 'px';
                requestAnimationFrame(() => pop.classList.add('is-open'));
                const ta = pop.querySelector('textarea');
                if (opts.existing) ta.value = opts.existing.comment;
                // Defer focus so the pending highlight paints before the caret moves.
                window.setTimeout(() => ta.focus(), 30);
                pop.querySelector('[data-cancel]').addEventListener('click', () => closeComposer());
                pop.querySelector('[data-save]').addEventListener('click', () => {
                    const val = ta.value.trim();
                    if (!val) { ta.focus(); return; }
                    const payload = { comment: val };
                    if (opts.existing) {
                        payload.annotation_id = opts.existing.id;
                    } else {
                        payload.anchor_type = opts.anchor_type;
                        if (opts.anchor_type === 'text') {
                            payload.range_start = opts.start;
                            payload.range_end = opts.end;
                            payload.quote = opts.quote;
                        } else if (opts.anchor_type === 'image') {
                            payload.pos_x = opts.pos_x;
                            payload.pos_y = opts.pos_y;
                        }
                    }
                    const saveBtn = pop.querySelector('[data-save]');
                    if (saveBtn) {
                        saveBtn.disabled = true;
                        saveBtn.textContent = 'Saving…';
                    }
                    saveAnnotation(payload).then(res => {
                        closeComposer();
                        if (!res || !res.ok) {
                            // Re-show pending if save failed so the teacher doesn't lose place.
                            if (opts.anchor_type === 'text') {
                                openComposer(opts);
                            }
                        }
                    });
                });
            }

            if (canAnnotate && docEl) {
                docEl.addEventListener('mouseup', () => {
                    window.setTimeout(() => {
                        if (document.querySelector('.rvw-popover')) return;
                        const surface = getAnnotateSurface(shell);
                        if (!surface) return;
                        const sel = window.getSelection();
                        if (!sel || sel.isCollapsed || sel.rangeCount === 0) return;
                        const range = sel.getRangeAt(0);
                        if (!surface.contains(range.startContainer) || !surface.contains(range.endContainer)) return;
                        // Ignore tiny / accidental clicks with no real drag.
                        const quote = sel.toString().replace(/\s+/g, ' ').trim();
                        if (quote.length < 2) return;
                        const start = offsetInNode(surface, range.startContainer, range.startOffset);
                        const end = offsetInNode(surface, range.endContainer, range.endOffset);
                        if (end <= start) return;
                        const rect = range.getBoundingClientRect();
                        // Keep visual selection via pending highlight; clear native selection
                        // so focus can move to the composer without losing the cue.
                        sel.removeAllRanges();
                        openComposer({
                            anchor_type: 'text',
                            start,
                            end,
                            quote,
                            x: rect.left,
                            y: rect.bottom + 10,
                        });
                    }, 10);
                });
            }

            shell.addEventListener('rvw-preview-loaded', () => render());
            shell._rvwRender = render;

            if (canAnnotate && imageWrap) {
                const img = imageWrap.querySelector('img');
                if (img) {
                    img.addEventListener('click', e => {
                        const rect = img.getBoundingClientRect();
                        const px = ((e.clientX - rect.left) / rect.width) * 100;
                        const py = ((e.clientY - rect.top) / rect.height) * 100;
                        openComposer({ anchor_type: 'image', pos_x: px.toFixed(2), pos_y: py.toFixed(2), x: e.clientX, y: e.clientY + 8 });
                    });
                }
            }

            render();
        });
    })();

    // ── Common setup ──────────────────────────────────────────────────────────
    const pageData = document.getElementById('portal-page-data');
    if (!pageData || pageData.dataset.canManage !== '1') return;

    // ── Folder lock toggle (AJAX, no page reload) ─────────────────────────────
    document.querySelectorAll('.folder-lock-toggle[data-folder-id]').forEach(btn => {
        btn.addEventListener('click', e => {
            e.preventDefault();
            e.stopPropagation(); // don't open/close the <details>
            if (btn.classList.contains('is-animating')) return;

            const folderId   = btn.dataset.folderId;
            const folderRow  = btn.closest('.folder-row');
            const folderCard = folderRow?.querySelector('details.folder-card');
            const folderInfo = folderRow?.querySelector('.folder-info');
            const settingsCb = folderRow?.querySelector('input[name="locked"]');
            const willLock   = !btn.classList.contains('is-locked');

            // Animate button
            btn.classList.add('is-animating');
            btn.addEventListener('animationend', () => btn.classList.remove('is-animating'), { once: true });

            // Optimistic UI update
            btn.classList.toggle('is-locked', willLock);
            btn.title = willLock ? 'Unlock folder' : 'Lock folder';
            btn.setAttribute('aria-label', btn.title);
            if (folderCard) folderCard.classList.toggle('folder-card--locked', willLock);
            if (settingsCb) settingsCb.checked = willLock;

            // Animate the lock badge
            const existingBadge = folderInfo?.querySelector('.folder-lock-badge');
            if (willLock && !existingBadge && folderInfo) {
                const badge = document.createElement('span');
                badge.className = 'folder-lock-badge';
                badge.textContent = 'Locked';
                badge.style.animation = 'badge-pop-in 250ms ease forwards';
                folderInfo.appendChild(badge);
            } else if (!willLock && existingBadge) {
                existingBadge.style.animation = 'badge-pop-out 180ms ease forwards';
                existingBadge.addEventListener('animationend', () => existingBadge.remove(), { once: true });
            }

            // AJAX
            const slug  = pageData.dataset.slug;
            const token = pageData.dataset.csrf;
            fetch('course.php?course=' + encodeURIComponent(slug), {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({ _token: token, action: 'toggle_folder_lock', folder_id: folderId }),
            }).then(r => r.json()).then(data => {
                if (!data.ok) {
                    // Revert on failure
                    btn.classList.toggle('is-locked', !willLock);
                    btn.title = !willLock ? 'Unlock folder' : 'Lock folder';
                    btn.setAttribute('aria-label', btn.title);
                    if (folderCard) folderCard.classList.toggle('folder-card--locked', !willLock);
                    if (settingsCb) settingsCb.checked = !willLock;
                }
            }).catch(() => {
                btn.classList.toggle('is-locked', !willLock);
                btn.title = !willLock ? 'Unlock folder' : 'Lock folder';
                btn.setAttribute('aria-label', btn.title);
                if (folderCard) folderCard.classList.toggle('folder-card--locked', !willLock);
                if (settingsCb) settingsCb.checked = !willLock;
            });
        });
    });

    // ── Item lock toggle ──────────────────────────────────────────────────────
    document.querySelectorAll('.folder-lock-toggle[data-item-id]').forEach(btn => {
        btn.addEventListener('click', e => {
            e.preventDefault();
            e.stopPropagation();
            if (btn.classList.contains('is-animating')) return;

            const itemId   = btn.dataset.itemId;
            const itemRow  = btn.closest('.folder-item');
            const willLock = !btn.classList.contains('is-locked');

            btn.classList.add('is-animating');
            btn.addEventListener('animationend', () => btn.classList.remove('is-animating'), { once: true });

            btn.classList.toggle('is-locked', willLock);
            btn.title = willLock ? 'Unlock item' : 'Lock item';
            btn.setAttribute('aria-label', btn.title);
            if (itemRow) itemRow.classList.toggle('folder-item--locked', willLock);

            const slug  = pageData.dataset.slug;
            const token = pageData.dataset.csrf;
            fetch('course.php?course=' + encodeURIComponent(slug), {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({ _token: token, action: 'toggle_item_lock', item_id: itemId }),
            }).then(r => r.json()).then(data => {
                if (!data.ok) {
                    btn.classList.toggle('is-locked', !willLock);
                    btn.title = !willLock ? 'Unlock item' : 'Lock item';
                    btn.setAttribute('aria-label', btn.title);
                    if (itemRow) itemRow.classList.toggle('folder-item--locked', !willLock);
                }
            }).catch(() => {
                btn.classList.toggle('is-locked', !willLock);
                btn.title = !willLock ? 'Unlock item' : 'Lock item';
                btn.setAttribute('aria-label', btn.title);
                if (itemRow) itemRow.classList.toggle('folder-item--locked', !willLock);
            });
        });
    });

    // ── Download permission toggle ────────────────────────────────────────────
    document.querySelectorAll('.btn-dl-toggle').forEach(btn => {
        btn.addEventListener('click', e => {
            e.preventDefault();
            e.stopPropagation();
            if (btn.classList.contains('is-animating')) return;

            const itemId    = btn.dataset.itemId;
            const willEnable = !btn.classList.contains('is-enabled');

            btn.classList.add('is-animating');
            btn.addEventListener('animationend', () => btn.classList.remove('is-animating'), { once: true });

            btn.classList.toggle('is-enabled', willEnable);
            btn.title = willEnable
                ? 'Students can download — click to disable'
                : 'Students cannot download — click to enable';

            const settingsCb = btn.closest('.folder-item')?.querySelector('input[name="allow_download"]');
            if (settingsCb) settingsCb.checked = willEnable;

            const slug  = pageData.dataset.slug;
            const token = pageData.dataset.csrf;
            fetch('course.php?course=' + encodeURIComponent(slug), {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({ _token: token, action: 'toggle_download', item_id: itemId }),
            }).then(r => r.json()).then(data => {
                if (!data.ok) {
                    btn.classList.toggle('is-enabled', !willEnable);
                    btn.title = !willEnable
                        ? 'Students can download — click to disable'
                        : 'Students cannot download — click to enable';
                    if (settingsCb) settingsCb.checked = !willEnable;
                }
            }).catch(() => {
                btn.classList.toggle('is-enabled', !willEnable);
                btn.title = !willEnable
                    ? 'Students can download — click to disable'
                    : 'Students cannot download — click to enable';
                if (settingsCb) settingsCb.checked = !willEnable;
            });
        });
    });

    // ── Pointer-drag to reorder folders and items (not HTML5 DnD) ───────────
    const stack     = document.getElementById('folder-stack');
    const modeBadge = document.getElementById('reorder-mode-badge');
    const modeDone  = document.getElementById('reorder-mode-done');
    if (stack) {
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let reorderMode = false;
    let activePointerId = null;

    function isArrowReorderViewport() {
        return window.matchMedia('(max-width: 900px)').matches;
    }

    function enterReorderMode() {
        if (isArrowReorderViewport()) return;
        reorderMode = true;
        stack.classList.add('folder-stack--reordering');
        if (modeBadge) modeBadge.hidden = false;
    }

    function exitReorderMode() {
        reorderMode = false;
        activePointerId = null;
        stack.classList.remove('folder-stack--reordering');
        const slot = document.querySelector('.folder-reorder-slot, .folder-item-reorder-slot');
        document.querySelectorAll('.folder-row.is-dragging, .folder-item.is-dragging').forEach((el) => {
            if (slot && slot.parentNode) {
                slot.parentNode.insertBefore(el, slot);
            }
            el.classList.remove('is-dragging');
            el.style.cssText = '';
        });
        document.querySelectorAll('.folder-reorder-slot, .folder-item-reorder-slot').forEach((el) => el.remove());
        if (modeBadge) modeBadge.hidden = true;
    }

    function flipSiblings(parent, apply) {
        if (reduceMotion) {
            apply();
            return;
        }
        const nodes = Array.from(parent.children);
        const first = new Map(nodes.map((n) => [n, n.getBoundingClientRect()]));
        apply();
        nodes.forEach((n) => {
            const prev = first.get(n);
            if (!prev || !n.isConnected) return;
            const last = n.getBoundingClientRect();
            const dy = prev.top - last.top;
            if (Math.abs(dy) < 1) return;
            n.animate(
                [{ transform: 'translateY(' + dy + 'px)' }, { transform: 'none' }],
                { duration: 220, easing: 'cubic-bezier(0.22, 1, 0.36, 1)' }
            );
        });
    }

    function flipMove(el, apply) {
        flipSiblings(el.parentNode, apply);
    }

    function bindHandle(handle, kind) {
        handle.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
        });
        handle.addEventListener('pointerdown', (e) => {
            if (e.button !== 0 || isArrowReorderViewport()) return;
            const row = handle.closest('.folder-row');
            const item = handle.closest('.folder-item');
            const moving = kind === 'folder' ? row : item;
            const parent = kind === 'folder' ? stack : item?.closest('.folder-items');
            if (!moving || !parent) return;
            e.preventDefault();
            e.stopPropagation();
            enterReorderMode();
            activePointerId = e.pointerId;

            const rect = moving.getBoundingClientRect();
            const offsetX = e.clientX - rect.left;
            const offsetY = e.clientY - rect.top;
            const slot = document.createElement('div');
            slot.className = kind === 'folder' ? 'folder-reorder-slot' : 'folder-item-reorder-slot';
            slot.style.height = Math.round(rect.height) + 'px';
            parent.insertBefore(slot, moving.nextSibling);

            document.body.appendChild(moving);
            moving.classList.add('is-dragging');
            moving.style.width = rect.width + 'px';
            moving.style.left = rect.left + 'px';
            moving.style.top = rect.top + 'px';
            try { handle.setPointerCapture(e.pointerId); } catch (_) { /* ignore */ }

            const follow = (ev) => {
                moving.style.left = (ev.clientX - offsetX) + 'px';
                moving.style.top = (ev.clientY - offsetY) + 'px';
            };
            const moveSlotTo = (list, refNode) => {
                if (!list) return;
                if (refNode === slot) refNode = slot.nextElementSibling;
                if (refNode == null) {
                    if (slot.parentNode === list && list.lastElementChild === slot) return;
                    list.appendChild(slot);
                    return;
                }
                if (slot.parentNode === list && slot.nextElementSibling === refNode) return;
                list.insertBefore(slot, refNode);
            };
            const listAtPoint = (clientX, clientY) => {
                const lists = Array.from(stack.querySelectorAll('.folder-items'));
                for (const list of lists) {
                    const r = list.getBoundingClientRect();
                    if (clientX >= r.left && clientX <= r.right && clientY >= r.top && clientY <= r.bottom) {
                        return list;
                    }
                }
                return null;
            };
            const refFromY = (list, clientY) => {
                const items = Array.from(list.children).filter(
                    (n) => n !== slot && n !== moving && n.classList.contains('folder-item')
                );
                const slack = 8;
                for (const item of items) {
                    const r = item.getBoundingClientRect();
                    if (clientY < r.top + r.height / 2 - slack) return item;
                }
                return null;
            };
            const placeSlot = (ev) => {
                if (kind === 'folder') {
                    const others = Array.from(stack.children).filter(
                        (n) => n !== moving && n !== slot && n.classList.contains('folder-row')
                    );
                    for (const other of others) {
                        const r = other.getBoundingClientRect();
                        if (ev.clientY < r.top + r.height / 2) {
                            moveSlotTo(stack, other);
                            return;
                        }
                    }
                    moveSlotTo(stack, null);
                    return;
                }
                const list = listAtPoint(ev.clientX, ev.clientY) || slot.parentNode;
                if (!list || !list.classList || !list.classList.contains('folder-items')) return;
                moveSlotTo(list, refFromY(list, ev.clientY));
            };

            const onMove = (ev) => {
                if (ev.pointerId !== activePointerId) return;
                follow(ev);
                placeSlot(ev);
            };
            const onUp = (ev) => {
                if (ev.pointerId !== activePointerId) return;
                activePointerId = null;
                follow(ev);
                const from = moving.getBoundingClientRect();
                const dest = slot.parentNode || parent;
                dest.insertBefore(moving, slot);
                slot.remove();
                moving.classList.remove('is-dragging');
                moving.style.cssText = '';
                if (!reduceMotion) {
                    const to = moving.getBoundingClientRect();
                    const dx = from.left - to.left;
                    const dy = from.top - to.top;
                    if (Math.abs(dx) > 1 || Math.abs(dy) > 1) {
                        moving.animate(
                            [{ transform: 'translate(' + dx + 'px, ' + dy + 'px) scale(1.02)' }, { transform: 'none' }],
                            { duration: 260, easing: 'cubic-bezier(0.22, 1, 0.36, 1)' }
                        );
                    }
                }
                try { handle.releasePointerCapture(ev.pointerId); } catch (_) { /* ignore */ }
                handle.removeEventListener('pointermove', onMove);
                handle.removeEventListener('pointerup', onUp);
                handle.removeEventListener('pointercancel', onUp);
                if (kind === 'folder') saveFolderOrder();
                else saveItemPosition(moving);
                syncMoveButtons();
            };
            handle.addEventListener('pointermove', onMove);
            handle.addEventListener('pointerup', onUp);
            handle.addEventListener('pointercancel', onUp);
        });
    }

    stack.querySelectorAll('.folder-drag-handle').forEach((h) => bindHandle(h, 'folder'));
    stack.querySelectorAll('.item-drag-handle').forEach((h) => bindHandle(h, 'item'));

    modeDone?.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        exitReorderMode();
    });

    document.addEventListener('click', (e) => {
        if (!reorderMode || activePointerId !== null) return;
        if (stack.contains(e.target) || modeBadge?.contains(e.target)) return;
        exitReorderMode();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && reorderMode) exitReorderMode();
    });

    function saveFolderOrder() {
        const ids   = Array.from(stack.querySelectorAll(':scope > .folder-row')).map(r => r.dataset.folderId);
        const slug  = pageData.dataset.slug;
        const token = pageData.dataset.csrf;
        fetch('course.php?course=' + encodeURIComponent(slug), {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    new URLSearchParams({ _token: token, action: 'reorder_folders', order: JSON.stringify(ids) }),
        }).catch(() => {});
    }

    function saveItemPosition(itemEl) {
        const newFolderRow    = itemEl.closest('.folder-row');
        if (!newFolderRow) return;
        const newFolderId      = newFolderRow.dataset.folderId;
        const originalFolderId = itemEl.dataset.folderId;
        const itemId           = itemEl.dataset.itemId;
        const slug             = pageData.dataset.slug;
        const token            = pageData.dataset.csrf;

        if (newFolderId !== originalFolderId) {
            // Moved to a different folder
            itemEl.dataset.folderId = newFolderId;
            fetch('course.php?course=' + encodeURIComponent(slug), {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({ _token: token, action: 'move_item', item_id: itemId, folder_id: newFolderId }),
            }).catch(() => {});
        } else {
            // Reordered within the same folder
            const folderItems = itemEl.closest('.folder-items');
            if (!folderItems) return;
            const ids = Array.from(folderItems.querySelectorAll('.folder-item')).map(i => i.dataset.itemId);
            fetch('course.php?course=' + encodeURIComponent(slug), {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({ _token: token, action: 'reorder_items', folder_id: newFolderId, order: JSON.stringify(ids) }),
            }).catch(() => {});
        }
    }

    function syncMoveButtons() {
        const rows = Array.from(stack.querySelectorAll(':scope > .folder-row'));
        rows.forEach((row, i) => {
            const up = row.querySelector('[data-course-move="folder"] [data-course-move-dir="up"]');
            const down = row.querySelector('[data-course-move="folder"] [data-course-move-dir="down"]');
            if (up) up.disabled = i === 0;
            if (down) down.disabled = i === rows.length - 1;
        });
        stack.querySelectorAll('.folder-items').forEach((list) => {
            const items = Array.from(list.querySelectorAll(':scope > .folder-item'));
            items.forEach((item, i) => {
                const up = item.querySelector('[data-course-move="item"] [data-course-move-dir="up"]');
                const down = item.querySelector('[data-course-move="item"] [data-course-move-dir="down"]');
                if (up) up.disabled = i === 0;
                if (down) down.disabled = i === items.length - 1;
            });
        });
    }

    stack.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-course-move-dir]');
        if (!btn || !stack.contains(btn) || btn.disabled) return;
        e.preventDefault();
        e.stopPropagation();

        const dir = btn.getAttribute('data-course-move-dir');
        const folderRow = btn.closest('.folder-row');
        const itemEl = btn.closest('.folder-item');
        const moveKind = btn.closest('[data-course-move]')?.getAttribute('data-course-move');

        if (moveKind === 'folder' && folderRow) {
            const rows = Array.from(stack.querySelectorAll(':scope > .folder-row'));
            const idx = rows.indexOf(folderRow);
            const swap = dir === 'up' ? idx - 1 : idx + 1;
            if (idx < 0 || swap < 0 || swap >= rows.length) return;
            flipMove(folderRow, () => {
                if (dir === 'up') {
                    stack.insertBefore(folderRow, rows[swap]);
                } else {
                    stack.insertBefore(rows[swap], folderRow);
                }
            });
            saveFolderOrder();
            syncMoveButtons();
            btn.blur();
            return;
        }

        if (moveKind === 'item' && itemEl) {
            const list = itemEl.closest('.folder-items');
            if (!list) return;
            const items = Array.from(list.querySelectorAll(':scope > .folder-item'));
            const idx = items.indexOf(itemEl);
            const swap = dir === 'up' ? idx - 1 : idx + 1;
            if (idx < 0 || swap < 0 || swap >= items.length) return;
            flipMove(itemEl, () => {
                if (dir === 'up') {
                    list.insertBefore(itemEl, items[swap]);
                } else {
                    list.insertBefore(items[swap], itemEl);
                }
            });
            saveItemPosition(itemEl);
            syncMoveButtons();
            btn.blur();
        }
    }, true);

    syncMoveButtons();
    }
})();

// Guard discussion reply/topic forms against double-submit (double-click spam).
(function () {
    const guarded = new Set(['post_reply', 'create_topic']);
    document.querySelectorAll('form').forEach((form) => {
        const actionInput = form.querySelector('input[name="action"]');
        if (!actionInput || !guarded.has(actionInput.value)) return;

        form.addEventListener('submit', (e) => {
            if (form.dataset.submitting === '1') {
                e.preventDefault();
                e.stopImmediatePropagation();
                return;
            }
            form.dataset.submitting = '1';
            const btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                // Tiny delay keeps the disabled state from flashing if the
                // browser cancels navigation for any reason.
                window.setTimeout(() => {
                    if (document.body.contains(form) && form.dataset.submitting === '1') {
                        btn.disabled = false;
                        delete form.dataset.submitting;
                    }
                }, 4000);
            }
        }, true);
    });
})();
</script>
<?php
$page_content = ob_get_clean();

require __DIR__ . '/../layout.php';
