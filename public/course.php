<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../course_catalog.php';

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
    $_SESSION['course_flash'] = ['error', 'You do not have access to that course.'];
    portal_redirect('courses.php');
}

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
] as $sql) {
    try { portal_db()->exec($sql); } catch (\PDOException $e) {}
}

if (!function_exists('portal_extract_submission_text')) {
    function portal_extract_submission_text(string $absPath, string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === 'txt') {
            return trim((string) @file_get_contents($absPath));
        }
        if ($ext === 'docx' && class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($absPath) === true) {
                $xml = (string) $zip->getFromName('word/document.xml');
                $zip->close();
                if ($xml !== '') {
                    $xml = preg_replace('/<\/w:p>/', "\n", $xml) ?? $xml;
                    return trim(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                }
            }
        }
        return '';
    }
}

if (!function_exists('portal_zero_gpt_detection')) {
    function portal_zero_gpt_detection(string $text): array
    {
        $apiKey = trim((string) getenv('ZEROGPT_API_KEY'));
        if ($apiKey === '') {
            return ['status' => 'not_configured', 'score' => null, 'report' => 'AI detection is enabled for this slot, but ZEROGPT_API_KEY is not configured on the server.'];
        }
        if ($text === '') {
            return ['status' => 'no_text', 'score' => null, 'report' => 'No readable text could be extracted from this submission for AI detection.'];
        }
        if (!function_exists('curl_init')) {
            return ['status' => 'error', 'score' => null, 'report' => 'PHP cURL is not enabled, so AI detection could not run.'];
        }

        $limitedText = function_exists('mb_substr') ? mb_substr($text, 0, 15000) : substr($text, 0, 15000);
        $payload = json_encode(['text' => $limitedText], JSON_UNESCAPED_UNICODE);
        $ch = curl_init('https://api.zerogpt.org/api/v1/developer/ai-detection');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $apiKey,
                'ApiKey: ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $body = (string) curl_exec($ch);
        $err = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === '' || $http >= 400) {
            return ['status' => 'error', 'score' => null, 'report' => $err !== '' ? $err : 'ZeroGPT returned HTTP ' . $http . '.'];
        }

        $data = json_decode($body, true);
        $score = null;
        $paths = [
            ['data', 'fakePercentage'],
            ['data', 'aiPercentage'],
            ['data', 'is_gpt_generated'],
            ['fakePercentage'],
            ['aiPercentage'],
            ['score'],
        ];
        foreach ($paths as $path) {
            $cursor = $data;
            foreach ($path as $key) {
                if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
                    $cursor = null;
                    break;
                }
                $cursor = $cursor[$key];
            }
            if (is_numeric($cursor)) {
                $score = (float) $cursor;
                break;
            }
        }

        return [
            'status' => 'checked',
            'score' => $score,
            'report' => function_exists('mb_substr') ? mb_substr($body, 0, 4000) : substr($body, 0, 4000),
        ];
    }
}

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

    // ── Admin-only: assign / remove teacher ───────────────────────────────────
    if (portal_is_admin()) {
        if ($action === 'assign_teacher') {
            $uid = (int) ($_POST['user_id'] ?? 0);
            $chk = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'teacher'");
            $chk->execute([$uid]);
            if ($chk->fetch()) {
                $db->prepare("INSERT OR IGNORE INTO course_teachers (course_id, user_id) VALUES (?,?)")
                   ->execute([$courseId, $uid]);
            }
        } elseif ($action === 'remove_teacher') {
            $uid = (int) ($_POST['user_id'] ?? 0);
            $db->prepare("DELETE FROM course_teachers WHERE course_id = ? AND user_id = ?")
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

        } elseif ($action === 'create_item') {
            $folderId = (int) ($_POST['folder_id'] ?? 0);
            $type     = (string) ($_POST['type'] ?? 'document');
            if (!in_array($type, ['document', 'link', 'submission'], true)) {
                $type = 'document';
            }
            $title    = substr(trim((string) ($_POST['title'] ?? '')), 0, 200);
            $desc     = substr(trim((string) ($_POST['description'] ?? '')), 0, 500);
            $url      = substr(trim((string) ($_POST['url'] ?? '')), 0, 2000);
            $submissionDeadline = '';
            $submissionAiDetection = 0;
            if ($url !== '' && !preg_match('/^https?:\/\//i', $url)) {
                $url = '';
            }
            $filePath = '';
            $fileName = '';
            $createItemError = null;

            // Handle file upload for document type
            if ($type === 'document' && isset($_FILES['file'])) {
                $fileError = (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE);
                $fileSize = (int) ($_FILES['file']['size'] ?? 0);
                $ext = strtolower(pathinfo((string) ($_FILES['file']['name'] ?? ''), PATHINFO_EXTENSION));

                if ($fileError !== UPLOAD_ERR_OK) {
                    $createItemError = $uploadErrorMessage($fileError);
                } elseif (!in_array($ext, portal_supported_upload_extensions(), true)) {
                    $createItemError = 'Unsupported file type. Use ' . portal_supported_upload_hint() . '.';
                } elseif (!portal_upload_mime_ok((string) ($_FILES['file']['tmp_name'] ?? ''), $ext)) {
                    $createItemError = 'This file content does not match its extension. Please upload a genuine document.';
                } elseif ($fileSize <= 0) {
                    $createItemError = 'Uploaded file is empty (0 bytes). Please export/download it again and re-upload.';
                } elseif ($fileSize > $maxUploadBytes) {
                    $createItemError = 'File is too large. Maximum allowed size is 40 MB.';
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
            } elseif ($type === 'document' && $url === '') {
                $createItemError = 'Please upload a file or provide a URL for this document item.';
            } elseif ($type === 'submission') {
                $deadlineRaw = trim((string) ($_POST['submission_deadline'] ?? ''));
                $deadlineTs = $deadlineRaw !== '' ? strtotime($deadlineRaw) : false;
                if ($deadlineTs === false) {
                    $createItemError = 'Please set a valid deadline for this submission slot.';
                } else {
                    $submissionDeadline = date('Y-m-d H:i:s', $deadlineTs);
                    $submissionAiDetection = isset($_POST['submission_ai_detection']) && $_POST['submission_ai_detection'] === '1' ? 1 : 0;
                    $url = '';
                }
            }

            $chk = $db->prepare("SELECT id FROM course_folders WHERE id = ? AND course_id = ?");
            $chk->execute([$folderId, $courseId]);
            if ($createItemError !== null) {
                $_SESSION['course_flash'] = ['error', $createItemError];
            } elseif ($chk->fetch() && $title !== '') {
                $allowDl = (isset($_POST['allow_download']) && $_POST['allow_download'] === '1') ? 1 : 0;
                $db->prepare(
                    "INSERT INTO course_folder_items
                     (folder_id, course_id, type, title, description, url, file_path, file_name, allow_download, submission_deadline, submission_ai_detection)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)"
                )->execute([$folderId, $courseId, $type, $title, $desc, $url, $filePath, $fileName, $allowDl, $submissionDeadline, $submissionAiDetection]);
                $_SESSION['course_flash'] = ['success', 'Item added successfully.'];
            } else {
                $_SESSION['course_flash'] = ['error', 'Could not add item. Check the folder and title, then try again.'];
            }

        } elseif ($action === 'update_item_settings') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $itemStmt = $db->prepare("SELECT * FROM course_folder_items WHERE id = ? AND course_id = ?");
            $itemStmt->execute([$itemId, $courseId]);
            $itemRow = $itemStmt->fetch();
            if ($itemRow) {
                $title = substr(trim((string) ($_POST['title'] ?? '')), 0, 200);
                $desc = substr(trim((string) ($_POST['description'] ?? '')), 0, 500);
                $url = substr(trim((string) ($_POST['url'] ?? '')), 0, 2000);
                $allowDl = isset($_POST['allow_download']) && $_POST['allow_download'] === '1' ? 1 : 0;
                $deadline = (string) ($itemRow['submission_deadline'] ?? '');
                $aiDetection = (int) ($itemRow['submission_ai_detection'] ?? 0);
                $error = null;

                if ($title === '') {
                    $error = 'Item title is required.';
                }

                if ($error === null && $itemRow['type'] === 'link') {
                    if ($url === '' || !preg_match('/^https?:\/\//i', $url)) {
                        $error = 'Please enter a valid link URL.';
                    }
                } elseif ($error === null && $itemRow['type'] === 'document' && $itemRow['file_path'] === '') {
                    if ($url === '' || !preg_match('/^https?:\/\//i', $url)) {
                        $error = 'Please enter a valid file URL.';
                    }
                } elseif ($itemRow['type'] !== 'link') {
                    $url = (string) ($itemRow['url'] ?? '');
                }

                if ($error === null && $itemRow['type'] === 'submission') {
                    $deadlineRaw = trim((string) ($_POST['submission_deadline'] ?? ''));
                    $deadlineTs = $deadlineRaw !== '' ? strtotime($deadlineRaw) : false;
                    if ($deadlineTs === false) {
                        $error = 'Please set a valid deadline for this submission slot.';
                    } else {
                        $deadline = date('Y-m-d H:i:s', $deadlineTs);
                        $aiDetection = isset($_POST['submission_ai_detection']) && $_POST['submission_ai_detection'] === '1' ? 1 : 0;
                    }
                }

                if ($error !== null) {
                    $_SESSION['course_flash'] = ['error', $error];
                } else {
                    $db->prepare(
                        "UPDATE course_folder_items
                         SET title = ?, description = ?, url = ?, allow_download = ?,
                             submission_deadline = ?, submission_ai_detection = ?
                         WHERE id = ? AND course_id = ?"
                    )->execute([$title, $desc, $url, $allowDl, $deadline, $aiDetection, $itemId, $courseId]);
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

        } elseif ($action === 'post_announcement') {
            $title = substr(trim((string) ($_POST['title'] ?? '')), 0, 200);
            $body  = substr(trim((string) ($_POST['body'] ?? '')), 0, 2000);
            if ($title !== '') {
                $db->prepare(
                    "INSERT INTO course_announcements (course_id, user_id, title, body) VALUES (?,?,?,?)"
                )->execute([$courseId, (int) $me['id'], $title, $body]);
                $newAnnId = (int) $db->lastInsertId();
                if ($newAnnId > 0) {
                    $db->prepare("INSERT OR IGNORE INTO announcement_reads (user_id, announcement_id) VALUES (?,?)")
                       ->execute([(int) $me['id'], $newAnnId]);
                }
            }

        } elseif ($action === 'delete_announcement') {
            $annId = (int) ($_POST['announcement_id'] ?? 0);
            if (portal_is_admin()) {
                $db->prepare("DELETE FROM course_announcements WHERE id = ? AND course_id = ?")
                   ->execute([$annId, $courseId]);
            } else {
                $db->prepare(
                    "DELETE FROM course_announcements WHERE id = ? AND course_id = ? AND user_id = ?"
                )->execute([$annId, $courseId, (int) $me['id']]);
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
                $db->prepare(
                    "UPDATE course_submissions
                     SET score = ?, feedback = ?, marked_at = datetime('now'), marked_by = ?
                     WHERE id = ? AND course_id = ?"
                )->execute([$score, $feedback, (int) $me['id'], $subId, $courseId]);
                $_SESSION['course_flash'] = ['success', 'Submission marked.'];
            }

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
                $db->prepare("DELETE FROM course_submissions WHERE id = ? AND course_id = ?")
                   ->execute([$subId, $courseId]);
            }

        } elseif ($action === 'create_schedule_slot') {
            $day   = substr(trim((string)($_POST['day_of_week'] ?? '')), 0, 20);
            $start = substr(trim((string)($_POST['start_time'] ?? '')), 0, 10);
            $end   = substr(trim((string)($_POST['end_time'] ?? '')), 0, 10);
            $room  = substr(trim((string)($_POST['room'] ?? '')), 0, 100);
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
            $room   = substr(trim((string)($_POST['room'] ?? '')), 0, 100);
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
            $title = substr(trim((string)($_POST['title'] ?? '')), 0, 200);
            $body  = substr(trim((string)($_POST['body'] ?? '')), 0, 3000);
            if ($title !== '') {
                $db->prepare(
                    "INSERT INTO course_discussion_topics (course_id, user_id, title, body) VALUES (?,?,?,?)"
                )->execute([$courseId, (int)$me['id'], $title, $body]);
            }

        } elseif ($action === 'delete_topic') {
            $topicId = (int)($_POST['topic_id'] ?? 0);
            $db->prepare("DELETE FROM course_discussion_topics WHERE id = ? AND course_id = ?")
               ->execute([$topicId, $courseId]);

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
        $topicId = (int)($_POST['topic_id'] ?? 0);
        $body    = substr(trim((string)($_POST['body'] ?? '')), 0, 3000);
        // Verify topic belongs to this course
        $tChk = $db->prepare("SELECT id FROM course_discussion_topics WHERE id = ? AND course_id = ?");
        $tChk->execute([$topicId, $courseId]);
        if ($tChk->fetch() && $body !== '') {
            $db->prepare(
                "INSERT INTO course_discussion_replies (topic_id, course_id, user_id, body) VALUES (?,?,?,?)"
            )->execute([$topicId, $courseId, (int)$me['id'], $body]);
        }
    }

    if ($action === 'delete_reply') {
        $replyId = (int)($_POST['reply_id'] ?? 0);
        if (portal_can_manage_course($courseId)) {
            $db->prepare("DELETE FROM course_discussion_replies WHERE id = ? AND course_id = ?")
               ->execute([$replyId, $courseId]);
        } else {
            $db->prepare(
                "DELETE FROM course_discussion_replies WHERE id = ? AND course_id = ? AND user_id = ?"
            )->execute([$replyId, $courseId, (int)$me['id']]);
        }
    }

    // ── Students: join / leave group ─────────────────────────────────────────
    if ($action === 'join_group') {
        $gid = (int)($_POST['group_id'] ?? 0);
        // Check max_members
        $gStmt = $db->prepare("SELECT max_members FROM course_groups WHERE id = ? AND course_id = ?");
        $gStmt->execute([$gid, $courseId]);
        $gRow = $gStmt->fetch();
        if ($gRow) {
            $maxM = (int)$gRow['max_members'];
            $canJoin = true;
            if ($maxM > 0) {
                $cntStmt = $db->prepare("SELECT COUNT(*) FROM course_group_members WHERE group_id = ?");
                $cntStmt->execute([$gid]);
                $canJoin = $cntStmt->fetchColumn() < $maxM;
            }
            if ($canJoin) {
                $db->prepare("INSERT OR IGNORE INTO course_group_members (group_id, user_id) VALUES (?,?)")
                   ->execute([$gid, (int)$me['id']]);
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
    }

    // ── Student: submit work to a submission slot ─────────────────────────────
    if ($action === 'submit_work') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $slotChk = $db->prepare(
            "SELECT id, submission_deadline, submission_ai_detection
             FROM course_folder_items
             WHERE id = ? AND course_id = ? AND type = 'submission'"
        );
        $slotChk->execute([$itemId, $courseId]);
        $slot = $slotChk->fetch();
        if ($slot && isset($_FILES['submission_file'])) {
            $subError = (int) ($_FILES['submission_file']['error'] ?? UPLOAD_ERR_NO_FILE);
            $subSize = (int) ($_FILES['submission_file']['size'] ?? 0);
            $ext = strtolower(pathinfo((string) ($_FILES['submission_file']['name'] ?? ''), PATHINFO_EXTENSION));
            $deadlineTs = $slot['submission_deadline'] !== '' ? strtotime((string) $slot['submission_deadline']) : false;

            if ($subError !== UPLOAD_ERR_OK) {
                $_SESSION['course_flash'] = ['error', $uploadErrorMessage($subError)];
            } elseif ($deadlineTs !== false && time() > $deadlineTs) {
                $_SESSION['course_flash'] = ['error', 'This submission deadline has passed. Ask your teacher if you need an extension.'];
            } elseif (!in_array($ext, portal_supported_upload_extensions(), true)) {
                $_SESSION['course_flash'] = ['error', 'Unsupported file type. Use ' . portal_supported_upload_hint() . '.'];
            } elseif (!portal_upload_mime_ok((string) ($_FILES['submission_file']['tmp_name'] ?? ''), $ext)) {
                $_SESSION['course_flash'] = ['error', 'This file content does not match its extension. Please upload a genuine document.'];
            } elseif ($subSize <= 0) {
                $_SESSION['course_flash'] = ['error', 'Uploaded file is empty (0 bytes). Please export/download it again and re-upload.'];
            } elseif ($subSize > $maxUploadBytes) {
                $_SESSION['course_flash'] = ['error', 'File is too large. Maximum allowed size is 40 MB.'];
            } else {
                $uid = (int) $me['id'];
                // Remove old file if re-submitting
                $prevStmt = $db->prepare(
                    "SELECT filepath FROM course_submissions WHERE item_id = ? AND user_id = ?"
                );
                $prevStmt->execute([$itemId, $uid]);
                $prev = $prevStmt->fetch();
                if ($prev && $prev['filepath'] !== '') {
                    $abs = portal_uploads_base() . DIRECTORY_SEPARATOR . $prev['filepath'];
                    if (is_file($abs)) @unlink($abs);
                }
                $dir = portal_uploads_base() . DIRECTORY_SEPARATOR . 'submissions'
                     . DIRECTORY_SEPARATOR . $itemId . DIRECTORY_SEPARATOR . $uid;
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $safe    = bin2hex(random_bytes(16)) . '.' . $ext;
                $relPath = 'submissions' . DIRECTORY_SEPARATOR . $itemId
                         . DIRECTORY_SEPARATOR . $uid . DIRECTORY_SEPARATOR . $safe;
                if (move_uploaded_file($_FILES['submission_file']['tmp_name'],
                    $dir . DIRECTORY_SEPARATOR . $safe)
                ) {
                    $db->prepare(
                        "INSERT INTO course_submissions
                         (item_id, course_id, user_id, filename, filepath, filesize)
                         VALUES (?,?,?,?,?,?)
                         ON CONFLICT(item_id, user_id) DO UPDATE
                         SET filename=excluded.filename, filepath=excluded.filepath,
                             filesize=excluded.filesize, submitted_at=datetime('now'),
                             score=NULL, feedback='', marked_at='', marked_by=NULL,
                             ai_status='', ai_score=NULL, ai_report='', ai_checked_at=''"
                    )->execute([
                        $itemId, $courseId, $uid,
                        substr((string) $_FILES['submission_file']['name'], 0, 255),
                        $relPath,
                        (int) $_FILES['submission_file']['size'],
                    ]);

                    if (!empty($slot['submission_ai_detection'])) {
                        $savedName = substr((string) $_FILES['submission_file']['name'], 0, 255);
                        $text = portal_extract_submission_text($dir . DIRECTORY_SEPARATOR . $safe, $savedName);
                        $ai = portal_zero_gpt_detection($text);
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
                    $_SESSION['course_flash'] = ['success', 'Submission uploaded successfully.'];
                } else {
                    $_SESSION['course_flash'] = ['error', 'Upload failed while saving your submission. Please try again.'];
                }
            }
        } else {
            $_SESSION['course_flash'] = ['error', 'Please choose a file before submitting.'];
        }
    }

    $rBase = 'course.php?course=' . urlencode($slug);
    if ($action === 'mark_submission') {
        portal_redirect($rBase . '&section=gradebook');
    } elseif (in_array($action, ['create_schedule_slot','update_schedule_slot','delete_schedule_slot'])) {
        // Rebuild courses.meeting from current slots so the hero banner stays in sync
        $meetingStmt = $db->prepare(
            "SELECT day_of_week, start_time, end_time FROM course_schedule WHERE course_id = ? ORDER BY sort_order ASC, id ASC"
        );
        $meetingStmt->execute([$courseId]);
        $meetingSlots = $meetingStmt->fetchAll();
        if (empty($meetingSlots)) {
            $meetingText = 'TBA';
        } else {
            $meetingParts = [];
            foreach ($meetingSlots as $ms) {
                $d = substr($ms['day_of_week'], 0, 3);
                $t = $ms['start_time'] !== '' ? $ms['start_time'] : '';
                if ($ms['end_time'] !== '') $t .= '–' . $ms['end_time'];
                $meetingParts[] = $d . ($t !== '' ? ' ' . $t : '');
            }
            $meetingText = implode(', ', $meetingParts);
        }
        $db->prepare("UPDATE courses SET meeting = ? WHERE id = ?")->execute([$meetingText, $courseId]);
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

// Assigned teachers for this course (with user info)
$_tStmt = $_db->prepare(
    "SELECT u.id, u.name, u.initials, u.email
     FROM course_teachers ct
     JOIN users u ON u.id = ct.user_id
     WHERE ct.course_id = ?
     ORDER BY u.name ASC"
);
$_tStmt->execute([$courseId]);
$courseTeachers = $_tStmt->fetchAll();

// Teachers not yet assigned (for admin "+" dropdown)
$courseTeacherIds = array_column($courseTeachers, 'id');
$availableTeachers = [];
if (portal_is_admin()) {
    $_atStmt = $_db->query("SELECT id, name, initials FROM users WHERE role = 'teacher' ORDER BY name ASC");
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

if (portal_can_manage_course($courseId)) {
    $_gradeStmt = $_db->prepare(
        "SELECT cs.*, cfi.title AS slot_title, cfi.submission_deadline,
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
        "SELECT cs.*, cfi.title AS slot_title, cfi.submission_deadline
         FROM course_submissions cs
         JOIN course_folder_items cfi ON cfi.id = cs.item_id
         WHERE cs.course_id = ? AND cs.user_id = ?
         ORDER BY cs.submitted_at DESC"
    );
    $_gradeStmt->execute([$courseId, (int) $_me['id']]);
}
$submissionGradebook = $_gradeStmt->fetchAll();

// Class schedule
$_schStmt = $_db->prepare(
    "SELECT * FROM course_schedule WHERE course_id = ? ORDER BY sort_order ASC, id ASC"
);
$_schStmt->execute([$courseId]);
$courseSchedule = $_schStmt->fetchAll();

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

// Live badge counts — show only unread count
foreach ($tabs as &$_tab) {
    if ($_tab['key'] === 'announcements') {
        $_tab['badge'] = count($unreadAnnouncements);
        break;
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
            <?= portal_escape((string) $courseFlash[1]) ?>
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

        <div class="course-hero-meta">
            <span><?= portal_escape($currentSection['label']) ?></span>
            <span><?= portal_escape($course['term']) ?></span>
            <span><?= portal_escape($course['meeting']) ?></span>
            <span>Online</span>
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
                'gradebook'     => 'Gradebook',
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
        <div class="stack">
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
                            <label class="folder-form-label" style="flex-direction:row;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
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
                                            <label class="folder-form-label" style="flex-direction:row;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
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
                                                    : ($item['type'] === 'submission' ? 'Submission slot' : ucfirst($item['type']));
                                                $itemLocked = !empty($item['locked']);
                                            ?>
                                            <div class="folder-item folder-item--<?= portal_escape($itemKindClass) ?><?= portal_can_manage_course($courseId) ? ' folder-item--managed' : '' ?><?= ($itemLocked && !portal_can_manage_course($courseId)) ? ' folder-item--student-locked' : '' ?>"
                                                 data-item-id="<?= (int) $item['id'] ?>"
                                                 data-folder-id="<?= (int) $folder['id'] ?>">
                                                <?php if (portal_can_manage_course($courseId)): ?>
                                                    <span class="item-drag-handle" title="Drag to reorder">
                                                        <?= portal_icon('grip', 'grip-icon') ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($isPresentation): ?>
                                                    <?= portal_icon('presentation', 'item-type-icon') ?>
                                                <?php elseif ($item['type'] === 'document'): ?>
                                                    <?= portal_icon('file', 'item-type-icon') ?>
                                                <?php elseif ($item['type'] === 'link'): ?>
                                                    <?= portal_icon('link', 'item-type-icon') ?>
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
                                                        ?>
                                                        <div class="file-item-row">
                                                            <a href="view.php?item=<?= (int)$item['id'] ?>" class="file-view-link" target="_blank">
                                                                <?= portal_icon($isPresentation ? 'presentation' : 'file', 'icon-xs') ?>
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
                                                    <?php elseif ($item['url'] !== ''): ?>
                                                        <a class="item-url-link" href="<?= portal_escape($item['url']) ?>" target="_blank" rel="noopener noreferrer">
                                                            <?= portal_icon('link', 'icon-xs') ?>
                                                            <?= portal_escape($item['title']) ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <strong><?= portal_escape($item['title']) ?></strong>
                                                    <?php endif; ?>
                                                    <?php if ($item['description'] !== ''): ?>
                                                        <p><?= portal_escape($item['description']) ?></p>
                                                    <?php endif; ?>
                                                    <span class="item-type-badge item-type-badge--<?= portal_escape($itemKindClass) ?>">
                                                        <?= portal_escape($itemKindLabel) ?>
                                                    </span>

                                                    <?php if (portal_can_manage_course($courseId)): ?>
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
                                                                        <input type="url" name="url" maxlength="2000" value="<?= portal_escape($item['url']) ?>" placeholder="https://...">
                                                                    </label>
                                                                <?php endif; ?>
                                                                <?php if ($item['type'] === 'submission'): ?>
                                                                    <div class="folder-form-row">
                                                                        <label class="folder-form-label">
                                                                            <span>Deadline</span>
                                                                            <input type="datetime-local" name="submission_deadline" required value="<?= portal_escape($itemDeadlineValue) ?>">
                                                                        </label>
                                                                        <label class="folder-form-label" style="flex-direction:row;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
                                                                            <input type="checkbox" name="submission_ai_detection" value="1" <?= !empty($item['submission_ai_detection']) ? 'checked' : '' ?>>
                                                                            Use AI detection <small style="font-weight:400">(ZeroGPT)</small>
                                                                        </label>
                                                                    </div>
                                                                <?php endif; ?>
                                                                <?php if ($item['type'] === 'document' && $item['file_path'] !== ''): ?>
                                                                    <label class="folder-form-label" style="flex-direction:row;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
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
                                                            $deadlineText = $item['submission_deadline'] !== ''
                                                                ? date('j M Y H:i', strtotime((string) $item['submission_deadline']))
                                                                : 'No deadline set';
                                                            $deadlinePassed = $item['submission_deadline'] !== '' && time() > strtotime((string) $item['submission_deadline']);
                                                        ?>
                                                        <div class="submission-slot-meta">
                                                            <span>Deadline: <strong><?= portal_escape($deadlineText) ?></strong></span>
                                                            <?php if (!empty($item['submission_ai_detection'])): ?>
                                                                <span>AI detection on</span>
                                                            <?php endif; ?>
                                                            <?php if ($deadlinePassed): ?>
                                                                <span class="is-overdue">Closed</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if (portal_can_manage_course($courseId)): ?>
                                                            <?php $subs = $slotSubmissions[(int)$item['id']] ?? []; ?>
                                                            <?php if (!empty($subs)): ?>
                                                            <div class="submission-list">
                                                                <p class="submission-list-head"><?= count($subs) ?> submission<?= count($subs) !== 1 ? 's' : '' ?></p>
                                                                <?php foreach ($subs as $sub): ?>
                                                                <div class="submission-row">
                                                                    <div class="course-staff-avatar sub-avatar"><?= portal_escape($sub['student_initials']) ?></div>
                                                                    <div class="submission-row-info">
                                                                        <strong><?= portal_escape($sub['student_name']) ?></strong>
                                                                        <a href="download.php?sub=<?= (int)$sub['id'] ?>" class="sub-download">
                                                                            <?= portal_icon('download', 'icon-xs') ?>
                                                                            <?= portal_escape($sub['filename']) ?>
                                                                        </a>
                                                                        <span class="sub-date"><?= portal_escape(date('j M Y H:i', strtotime($sub['submitted_at']))) ?></span>
                                                                        <?php if ($sub['ai_status'] !== ''): ?>
                                                                            <span class="sub-ai">AI: <?= portal_escape($sub['ai_status']) ?><?= $sub['ai_score'] !== null ? ' (' . portal_escape((string) round((float)$sub['ai_score'], 1)) . '%)' : '' ?></span>
                                                                        <?php endif; ?>
                                                                        <?php if ($sub['score'] !== null): ?>
                                                                            <span class="sub-mark">Marked: <?= (int)$sub['score'] ?>/100</span>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    <form method="POST" class="sub-mark-form">
                                                                        <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                                                        <input type="hidden" name="action" value="mark_submission">
                                                                        <input type="hidden" name="submission_id" value="<?= (int)$sub['id'] ?>">
                                                                        <input type="number" name="score" min="0" max="100" value="<?= $sub['score'] !== null ? (int)$sub['score'] : '' ?>" placeholder="0-100" required>
                                                                        <input type="text" name="feedback" maxlength="2000" value="<?= portal_escape($sub['feedback'] ?? '') ?>" placeholder="Feedback">
                                                                        <button type="submit" class="button button--sm">Mark</button>
                                                                    </form>
                                                                    <form method="POST" class="sub-delete-form">
                                                                        <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                                                        <input type="hidden" name="action" value="delete_submission">
                                                                        <input type="hidden" name="submission_id" value="<?= (int)$sub['id'] ?>">
                                                                        <button type="submit" class="btn-icon-danger" title="Remove submission">
                                                                            <?= portal_icon('trash', 'icon-sm') ?>
                                                                        </button>
                                                                    </form>
                                                                </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                            <?php else: ?>
                                                                <p class="sub-empty">No submissions yet.</p>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <?php $mySub = $mySubmissions[(int)$item['id']] ?? null; ?>
                                                            <?php if ($mySub): ?>
                                                                <div class="my-submission">
                                                                    <?= portal_icon('file', 'icon-xs') ?>
                                                                    <span>Submitted: <strong><?= portal_escape($mySub['filename']) ?></strong></span>
                                                                    <span class="sub-date"><?= portal_escape(date('j M Y H:i', strtotime($mySub['submitted_at']))) ?></span>
                                                                    <?php if ($mySub['score'] !== null): ?>
                                                                        <span class="sub-mark"><?= (int)$mySub['score'] ?>/100</span>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if ($deadlinePassed): ?>
                                                                <p class="sub-empty">This submission slot is closed.</p>
                                                            <?php else: ?>
                                                                <form method="POST" enctype="multipart/form-data" class="submit-work-form">
                                                                    <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                                                    <input type="hidden" name="action" value="submit_work">
                                                                    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                                                    <label class="submit-file-label">
                                                                        <input type="file" name="submission_file" accept=".docx,.xlsx,.pdf,.txt,.ppt,.pptx,.pps,.ppsx,.pot,.potx,.odp" required>
                                                                        <span class="submit-hint"><?= portal_escape(portal_supported_upload_hint()) ?> - max 40 MB</span>
                                                                    </label>
                                                                    <button type="submit" class="button button--sm"><?= $mySub ? 'Re-submit' : 'Submit work' ?></button>
                                                                </form>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
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
                                                        <button type="button"
                                                                class="folder-settings-button settings-toggle"
                                                                data-settings-target="item-settings-<?= (int)$item['id'] ?>"
                                                                aria-label="Item settings">
                                                            <?= portal_icon('settings', 'icon-sm') ?>
                                                        </button>
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
                                                        <option value="link">Link</option>
                                                        <option value="submission">Submission slot</option>
                                                    </select>
                                                </label>
                                                <label class="folder-form-label">
                                                    <span>Title</span>
                                                    <input type="text" name="title" required maxlength="200" placeholder="Item name">
                                                </label>
                                            </div>
                                            <div class="folder-form-row item-file-group">
                                                <label class="folder-form-label">
                                                    <span>Upload file <small>(<?= portal_escape(portal_supported_upload_hint()) ?> - max 40 MB)</small></span>
                                                    <input type="file" name="file" accept=".docx,.xlsx,.pdf,.txt,.ppt,.pptx,.pps,.ppsx,.pot,.potx,.odp">
                                                </label>
                                                <label class="folder-form-label item-url-group">
                                                    <span>Or paste URL <small>(optional)</small></span>
                                                    <input type="url" name="url" maxlength="2000" placeholder="https://...">
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
                                                    <span>Deadline</span>
                                                    <input type="datetime-local" name="submission_deadline">
                                                </label>
                                                <label class="folder-form-label" style="flex-direction:row;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
                                                    <input type="checkbox" name="submission_ai_detection" value="1">
                                                    Use AI detection <small style="font-weight:400">(requires ZeroGPT API key)</small>
                                                </label>
                                            </div>
                                            <label class="folder-form-label" style="flex-direction:row;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
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
                                <span>Join link <small>(optional)</small></span>
                                <input type="url" name="room" maxlength="500" placeholder="https://zoom.us/j/...">
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
                                    <span class="slot-online-label">Online</span>
                                    <?php if ($slot['notes'] !== ''): ?>
                                        <em><?= portal_escape($slot['notes']) ?></em>
                                    <?php endif; ?>
                                    <?php
                                        $joinUrl = (string)($slot['room'] ?? '');
                                        $isValidUrl = $joinUrl !== '' && preg_match('/^https?:\/\//i', $joinUrl);
                                    ?>
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
                                            <span>Join link <small>(optional)</small></span>
                                            <input type="url" name="room" maxlength="500" value="<?= portal_escape($slot['room']) ?>" placeholder="https://zoom.us/j/...">
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
                            <label class="folder-form-label">
                                <span>Message <small>(optional)</small></span>
                                <div class="quill-wrap"><div class="quill-editor" data-target="ann-body"></div></div>
                                <textarea name="body" id="ann-body" class="rich-textarea" maxlength="20000" hidden></textarea>
                            </label>
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
                            <label class="folder-form-label">
                                <span>Opening message <small>(optional)</small></span>
                                <div class="quill-wrap"><div class="quill-editor" data-target="topic-body"></div></div>
                                <textarea name="body" id="topic-body" class="rich-textarea" maxlength="20000" hidden></textarea>
                            </label>
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
                                    <span>by <?= portal_escape($topic['author_name']) ?> · <?= portal_escape(date('j M Y', strtotime($topic['created_at']))) ?></span>
                                </div>
                                <span class="chip"><?= (int)$topic['reply_count'] ?> <?= (int)$topic['reply_count'] === 1 ? 'reply' : 'replies' ?></span>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </article>
                <?php endif; ?>
            <?php elseif ($sectionKey === 'gradebook'): ?>
                <article class="card-shell">
                    <div class="section-head">
                        <div>
                            <p class="eyebrow">Course gradebook</p>
                            <h3 class="card-title">Marks and feedback</h3>
                        </div>
                        <span class="chip"><?= count($submissionGradebook) ?> submission<?= count($submissionGradebook) === 1 ? '' : 's' ?></span>
                    </div>

                    <div class="deadline-list">
                        <?php if (empty($submissionGradebook)): ?>
                            <article class="deadline-item">
                                <div>
                                    <p class="eyebrow">No marks yet</p>
                                    <h3>Submission marks will appear here</h3>
                                    <p>Once students submit work and teachers mark it, scores and feedback are shown in this gradebook.</p>
                                </div>
                            </article>
                        <?php else: ?>
                            <?php foreach ($submissionGradebook as $grade): ?>
                                <article class="deadline-item">
                                    <div>
                                        <p class="eyebrow">
                                            <?= $grade['score'] !== null ? 'Marked' : 'Awaiting mark' ?>
                                            <?php if (portal_can_manage_course($courseId) && isset($grade['student_name'])): ?>
                                                · <?= portal_escape($grade['student_name']) ?>
                                            <?php endif; ?>
                                        </p>
                                        <h3><?= portal_escape($grade['slot_title']) ?></h3>
                                        <p>
                                            Submitted <?= portal_escape(date('j M Y H:i', strtotime($grade['submitted_at']))) ?>
                                            <?php if (($grade['feedback'] ?? '') !== ''): ?>
                                                · <?= portal_escape($grade['feedback']) ?>
                                            <?php endif; ?>
                                        </p>
                                        <?php if (($grade['ai_status'] ?? '') !== ''): ?>
                                            <p class="grade-ai-note">
                                                AI check: <?= portal_escape($grade['ai_status']) ?><?= $grade['ai_score'] !== null ? ' (' . portal_escape((string) round((float)$grade['ai_score'], 1)) . '%)' : '' ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="resource-score">
                                        <strong><?= $grade['score'] !== null ? (int)$grade['score'] . '/100' : '--/100' ?></strong>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </article>

                <article class="schedule-note">
                    <h3>Results stay in one place</h3>
                    <p>Submission scores are tied directly to this course gradebook as soon as a teacher marks the uploaded work.</p>
                </article>
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
                        <?php if ($group['description'] !== ''): ?>
                            <p class="group-desc"><?= portal_escape($group['description']) ?></p>
                        <?php endif; ?>

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

        <aside class="stack">
            <article class="card-shell">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Course staff</p>
                        <h3 class="card-title">Who is teaching</h3>
                    </div>
                </div>

                <div class="course-staff-list">
                    <?php if (empty($courseTeachers)): ?>
                        <p class="folder-empty-note" style="padding:4px 0;">No teachers assigned yet.</p>
                    <?php else: ?>
                        <?php foreach ($courseTeachers as $teacher): ?>
                            <div class="course-staff-item">
                                <div class="course-staff-avatar teacher-avatar"><?= portal_escape($teacher['initials']) ?></div>
                                <div class="course-staff-info">
                                    <h4><?= portal_escape($teacher['name']) ?></h4>
                                    <p>Teacher</p>
                                    <span class="admin-role-badge role-teacher" style="margin-top:4px;font-size:0.68rem;">Can manage course</span>
                                </div>
                                <?php if (portal_is_admin()): ?>
                                    <form method="POST" class="staff-remove-form">
                                        <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                        <input type="hidden" name="action" value="remove_teacher">
                                        <input type="hidden" name="user_id" value="<?= (int) $teacher['id'] ?>">
                                        <button type="submit" class="btn-icon-danger" title="Remove teacher from course">
                                            <?= portal_icon('trash', 'icon-sm') ?>
                                        </button>
                                    </form>
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
                                <span>Assign teacher</span>
                            </summary>
                            <form method="POST" class="folder-admin-form folder-admin-form--inner">
                                <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                <input type="hidden" name="action" value="assign_teacher">
                                <label class="folder-form-label">
                                    <span>Select teacher</span>
                                    <select name="user_id">
                                        <?php foreach ($availableTeachers as $t): ?>
                                            <option value="<?= (int) $t['id'] ?>"><?= portal_escape($t['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <button type="submit" class="button">Assign</button>
                            </form>
                        </details>
                    <?php elseif (empty($courseTeachers)): ?>
                        <p class="folder-empty-note" style="margin-top:10px;">No teacher accounts exist yet. Create one in the admin panel.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </article>

            <article class="card-shell">
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

            <article class="card-shell">
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
</section>

<?php if (!empty($unreadAnnouncements)): ?>
<!-- ── Unread announcements notification ─────────────────────────────────────── -->
<div id="ann-notification" class="ann-notify-overlay" role="dialog" aria-modal="true" aria-label="New announcements">
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

<!-- ── File viewer modal ───────────────────────────────────────────────────── -->
<div id="file-viewer" class="viewer-overlay" hidden role="dialog" aria-modal="true" aria-label="File viewer">
    <div class="viewer-box">
        <div class="viewer-header">
            <span class="viewer-filename" id="viewer-filename"></span>
            <button class="viewer-close" id="viewer-close" aria-label="Close viewer">×</button>
        </div>
        <div class="viewer-body" id="viewer-body">
            <p class="viewer-loading">Loading…</p>
        </div>
    </div>
</div>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2/dist/quill.snow.css">
<script src="https://cdn.jsdelivr.net/npm/quill@2/dist/quill.js"></script>
<script src="https://cdn.jsdelivr.net/npm/mammoth@1/mammoth.browser.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"></script>

<div id="portal-page-data"
     data-slug="<?= portal_escape($slug) ?>"
     data-csrf="<?= portal_escape($csrfToken) ?>"
     data-can-manage="<?= portal_can_manage_course($courseId) ? '1' : '0' ?>"
     hidden></div>

<?php if (portal_can_manage_course($courseId)): ?>
<div class="reorder-mode-badge" id="reorder-mode-badge" hidden>
    Moving mode — drag to rearrange folders and items
</div>
<?php endif; ?>
<script>
(function () {
    // ── .docx / .xlsx / .pptx / PDF inline viewer ───────────────────────────
    const viewerOverlay = document.getElementById('file-viewer');
    const viewerBody    = document.getElementById('viewer-body');
    const viewerName    = document.getElementById('viewer-filename');
    const viewerClose   = document.getElementById('viewer-close');

    if (viewerOverlay) {
        viewerClose.addEventListener('click', closeViewer);
        viewerOverlay.addEventListener('click', e => { if (e.target === viewerOverlay) closeViewer(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeViewer(); });
    }

    function closeViewer() {
        viewerOverlay.hidden = true;
        viewerBody.innerHTML = '';
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function renderSheetToTable(sheet) {
        const rows = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: '' });
        if (!rows.length) {
            return '<p class="viewer-error">This workbook sheet is empty.</p>';
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

    async function extractPptxSlides(arrayBuffer) {
        const zip = await JSZip.loadAsync(arrayBuffer);
        const relsFile = zip.file('ppt/_rels/presentation.xml.rels');

        if (!relsFile) {
            return [];
        }

        const relsXml = await relsFile.async('text');
        const relsDoc = new DOMParser().parseFromString(relsXml, 'application/xml');
        const relNodes = Array.from(relsDoc.getElementsByTagName('Relationship'));
        const relMap = new Map(relNodes.map(node => [node.getAttribute('Id'), node.getAttribute('Target') || '']));

        const presentationFile = zip.file('ppt/presentation.xml');
        if (!presentationFile) {
            return [];
        }

        const presentationXml = await presentationFile.async('text');
        const presentationDoc = new DOMParser().parseFromString(presentationXml, 'application/xml');
        const slideIdNodes = Array.from(presentationDoc.getElementsByTagName('p:sldId'));
        const relIds = slideIdNodes
            .map(node => node.getAttribute('r:id'))
            .filter(Boolean);

        const slides = [];
        for (const relId of relIds) {
            const target = relMap.get(relId);
            if (!target) {
                continue;
            }

            const cleanTarget = target.replace(/^\//, '').replace(/^\.\.\//, '');
            const slidePath = cleanTarget.startsWith('ppt/') ? cleanTarget : 'ppt/' + cleanTarget;
            const slideFile = zip.file(slidePath);
            if (!slideFile) {
                continue;
            }

            const slideXml = await slideFile.async('text');
            const slideDoc = new DOMParser().parseFromString(slideXml, 'application/xml');
            const textNodes = Array.from(slideDoc.getElementsByTagName('a:t'));
            const lines = textNodes.map(node => (node.textContent || '').trim()).filter(Boolean);
            slides.push(lines);
        }

        return slides;
    }

    window.openFileViewer = async function(itemId, filename, ext) {
        const url = 'download.php?item=' + encodeURIComponent(itemId) + '&view=1';
        if (!viewerOverlay) {
            window.location.href = url;
            return;
        }
        viewerOverlay.hidden = false;
        viewerName.textContent = filename;
        viewerBody.innerHTML = '<p class="viewer-loading">Loading…</p>';

        try {
            if (ext === 'pdf') {
                viewerBody.innerHTML = '<iframe src="' + url + '" class="viewer-iframe"></iframe>';
            } else if (ext === 'docx') {
                const resp   = await fetch(url);
                if (!resp.ok) {
                    const message = await resp.text();
                    viewerBody.innerHTML = '<p class="viewer-error">' + escapeHtml(message || 'Could not load file.') + '</p>';
                    return;
                }
                const buffer = await resp.arrayBuffer();
                const result = await mammoth.convertToHtml({ arrayBuffer: buffer });
                viewerBody.innerHTML = '<div class="docx-content">' + result.value + '</div>';
            } else if (ext === 'xlsx') {
                const resp   = await fetch(url);
                if (!resp.ok) {
                    const message = await resp.text();
                    viewerBody.innerHTML = '<p class="viewer-error">' + escapeHtml(message || 'Could not load file.') + '</p>';
                    return;
                }
                const buffer = await resp.arrayBuffer();
                const wb     = XLSX.read(buffer, { type: 'array' });
                const firstSheetName = wb.SheetNames[0];
                const sheet = wb.Sheets[firstSheetName];
                if (!sheet) {
                    viewerBody.innerHTML = '<p class="viewer-error">Could not read this workbook.</p>';
                    return;
                }

                viewerBody.innerHTML = '<div class="viewer-sheet-head">Sheet: ' + escapeHtml(firstSheetName) + '</div>'
                    + renderSheetToTable(sheet);
            } else if (ext === 'pptx') {
                const resp   = await fetch(url);
                if (!resp.ok) {
                    const message = await resp.text();
                    viewerBody.innerHTML = '<p class="viewer-error">' + escapeHtml(message || 'Could not load file.') + '</p>';
                    return;
                }
                const buffer = await resp.arrayBuffer();
                const slides = await extractPptxSlides(buffer);
                if (!slides.length) {
                    viewerBody.innerHTML = '<p class="viewer-error">Could not read slide text from this presentation.</p>';
                    return;
                }

                viewerBody.innerHTML = slides.map((lines, idx) => {
                    const safeLines = lines.length
                        ? lines.map(line => '<li>' + escapeHtml(line) + '</li>').join('')
                        : '<li><em>(No text content found on this slide)</em></li>';
                    return '<section class="pptx-slide">'
                        + '<h4>Slide ' + (idx + 1) + '</h4>'
                        + '<ul>' + safeLines + '</ul>'
                        + '</section>';
                }).join('');
            } else {
                viewerBody.innerHTML = '<p class="viewer-error">Preview not available for this file type. Please download to view.</p>';
            }
        } catch (err) {
            viewerBody.innerHTML = '<p class="viewer-error">Could not load file.</p>';
        }
    };

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

    document.querySelectorAll('.item-type-select').forEach(sel => {
        const update = () => {
            const form     = sel.closest('form');
            const fileGrp  = form.querySelector('.item-file-group');
            const urlGrp   = form.querySelector('.item-url-group');
            const subGrp   = form.querySelector('.item-submission-group');
            const dlOpt    = form.querySelector('input[name="allow_download"]')?.closest('label');
            const type     = sel.value;
            if (fileGrp) fileGrp.style.display  = type === 'link' || type === 'submission' ? 'none' : '';
            if (urlGrp)  urlGrp.style.display   = type === 'submission' ? 'none' : '';
            if (subGrp)  subGrp.style.display    = type === 'submission' ? '' : 'none';
            if (dlOpt)   dlOpt.style.display     = type === 'document' ? '' : 'none';
        };
        sel.addEventListener('change', update);
        update();
    });

    // ── Rich text editors (Quill) ─────────────────────────────────────────────
    if (typeof Quill !== 'undefined') {
        const toolbarOptions = [
            [{ header: [2, 3, false] }],
            ['bold', 'italic', 'underline', 'strike'],
            [{ list: 'ordered' }, { list: 'bullet' }],
            ['blockquote'],
            ['clean'],
        ];

        document.querySelectorAll('.quill-editor[data-target]').forEach(container => {
            const targetId = container.dataset.target;
            const textarea = document.getElementById(targetId);
            if (!textarea) return;

            const quill = new Quill(container, {
                theme: 'snow',
                placeholder: 'Write something…',
                modules: { toolbar: toolbarOptions },
            });

            // Sync to hidden textarea on every change
            quill.on('text-change', () => {
                textarea.value = quill.root.innerHTML === '<p><br></p>' ? '' : quill.root.innerHTML;
            });

            // Also sync on form submit in case text-change didn't fire
            const form = textarea.closest('form');
            if (form) {
                form.addEventListener('submit', () => {
                    textarea.value = quill.root.innerHTML === '<p><br></p>' ? '' : quill.root.innerHTML;
                });
            }
        });
    }

    // ── Unread announcement notification ──────────────────────────────────────
    (function () {
        const overlay  = document.getElementById('ann-notification');
        if (!overlay) return;

        const pd    = document.getElementById('portal-page-data');
        const slug  = pd?.dataset.slug  ?? '';
        const token = pd?.dataset.csrf  ?? '';

        async function markAndClose() {
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
            overlay.classList.add('ann-notify--out');
            const hideOverlay = () => { overlay.hidden = true; };
            overlay.addEventListener('animationend', hideOverlay, { once: true });
            setTimeout(hideOverlay, 400); // fallback if animationend never fires
        }

        document.getElementById('ann-mark-read')?.addEventListener('click', markAndClose);
        document.getElementById('ann-notify-close')?.addEventListener('click', markAndClose);
        overlay.addEventListener('click', e => { if (e.target === overlay) markAndClose(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') markAndClose(); });

        // Animate in
        requestAnimationFrame(() => overlay.classList.add('ann-notify--in'));
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

    // ── Drag-to-reorder folders and items ────────────────────────────────────
    const stack     = document.getElementById('folder-stack');
    const modeBadge = document.getElementById('reorder-mode-badge');
    if (!stack) return;

    let reorderMode  = false;
    let dragFolderEl = null;
    let dragItemEl   = null;

    // Folder summaries and item drag handles become draggable
    stack.querySelectorAll('.folder-summary').forEach(s => s.setAttribute('draggable', 'true'));
    stack.querySelectorAll('.item-drag-handle').forEach(h => h.setAttribute('draggable', 'true'));

    function enterReorderMode() {
        reorderMode = true;
        stack.classList.add('folder-stack--reordering');
        if (modeBadge) modeBadge.hidden = false;
    }

    function exitReorderMode() {
        reorderMode = false;
        stack.classList.remove('folder-stack--reordering');
        if (modeBadge) modeBadge.hidden = true;
    }

    stack.querySelectorAll('.folder-drag-handle, .item-drag-handle').forEach(handle => {
        handle.addEventListener('mousedown', () => {
            if (!reorderMode) enterReorderMode();
        });
    });

    // Click anywhere outside the folder stack to exit reorder mode
    document.addEventListener('click', e => {
        if (reorderMode && !stack.contains(e.target)) exitReorderMode();
    });

    stack.addEventListener('dragstart', e => {
        if (!reorderMode) { e.preventDefault(); return; }

        // Item drag: triggered from .item-drag-handle
        const itemHandle = e.target.closest('.item-drag-handle');
        if (itemHandle) {
            const item = itemHandle.closest('.folder-item');
            if (!item) { e.preventDefault(); return; }
            dragItemEl   = item;
            dragFolderEl = null;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', 'item:' + item.dataset.itemId);
            requestAnimationFrame(() => item.classList.add('is-dragging'));
            return;
        }

        // Folder drag: must not start inside folder body
        if (e.target.closest('.folder-body')) { e.preventDefault(); return; }
        const row = e.target.closest('.folder-row');
        if (!row) { e.preventDefault(); return; }
        dragFolderEl = row;
        dragItemEl   = null;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', 'folder:' + row.dataset.folderId);
        requestAnimationFrame(() => row.classList.add('is-dragging'));
    });

    stack.addEventListener('dragend', () => {
        if (dragFolderEl) {
            dragFolderEl.classList.remove('is-dragging');
            saveFolderOrder();
        }
        if (dragItemEl) {
            dragItemEl.classList.remove('is-dragging');
            saveItemPosition(dragItemEl);
        }
        dragFolderEl = null;
        dragItemEl   = null;
    });

    stack.addEventListener('dragover', e => {
        e.preventDefault();

        if (dragFolderEl) {
            const target = e.target.closest('.folder-row');
            if (!target || target === dragFolderEl) return;
            const { top, height } = target.getBoundingClientRect();
            if (e.clientY < top + height / 2) {
                stack.insertBefore(dragFolderEl, target);
            } else {
                stack.insertBefore(dragFolderEl, target.nextSibling);
            }
            return;
        }

        if (dragItemEl) {
            const targetItem  = e.target.closest('.folder-item');
            const targetItems = e.target.closest('.folder-items');
            if (targetItem && targetItem !== dragItemEl) {
                const { top, height } = targetItem.getBoundingClientRect();
                if (e.clientY < top + height / 2) {
                    targetItem.parentNode.insertBefore(dragItemEl, targetItem);
                } else {
                    targetItem.parentNode.insertBefore(dragItemEl, targetItem.nextSibling);
                }
            } else if (targetItems && !targetItems.contains(dragItemEl)) {
                const targetCard = targetItems.closest('details.folder-card');
                if (!targetCard || targetCard.open) {
                    targetItems.appendChild(dragItemEl);
                }
            }
        }
    });

    function saveFolderOrder() {
        const ids   = Array.from(stack.querySelectorAll('.folder-row')).map(r => r.dataset.folderId);
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
})();
</script>
<?php
$page_content = ob_get_clean();

require __DIR__ . '/../layout.php';
