<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

portal_require_login();

$db  = portal_db();
$me  = portal_current_user();

// ── Helper: send a file with correct headers ──────────────────────────────────
$inlineView = isset($_GET['view']) && $_GET['view'] === '1';

function portal_send_file(string $absPath, string $displayName, bool $inline = false): never
{
    if (!is_file($absPath)) {
        http_response_code(404);
        exit('File not found.');
    }

    $size = (int) filesize($absPath);
    if ($size <= 0) {
        http_response_code(409);
        exit('This uploaded file is empty (0 bytes). Please re-upload the document.');
    }

    $ext      = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
    $mimeMap  = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'txt'  => 'text/plain; charset=UTF-8',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'pps'  => 'application/vnd.ms-powerpoint',
        'ppsx' => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
        'pot'  => 'application/vnd.ms-powerpoint',
        'potx' => 'application/vnd.openxmlformats-officedocument.presentationml.template',
        'odp'  => 'application/vnd.oasis.opendocument.presentation',
        'mp4'  => 'video/mp4',
        'm4v'  => 'video/mp4',
        'webm' => 'video/webm',
        'ogv'  => 'video/ogg',
        'ogg'  => 'video/ogg',
        'mov'  => 'video/quicktime',
    ];
    $mime        = $mimeMap[$ext] ?? 'application/octet-stream';
    $isVideo     = str_starts_with($mime, 'video/');
    $disposition = $inline ? 'inline' : 'attachment';
    $safeName    = str_replace(["\r", "\n", '"'], ['', '', "'"], $displayName !== '' ? $displayName : basename($absPath));
    $encodedName = rawurlencode($safeName);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"; filename*=UTF-8\'\'' . $encodedName);
    header('Cache-Control: private, no-cache');

    // Videos need Range support so the <video> element can seek/scrub.
    if ($isVideo) {
        header('Accept-Ranges: bytes');

        $start = 0;
        $end   = $size - 1;
        $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');

        if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m)) {
            $reqStart = $m[1] === '' ? null : (int) $m[1];
            $reqEnd   = $m[2] === '' ? null : (int) $m[2];

            if ($reqStart === null && $reqEnd !== null) {
                // Suffix range: last N bytes.
                $start = max(0, $size - $reqEnd);
                $end   = $size - 1;
            } else {
                $start = $reqStart ?? 0;
                $end   = $reqEnd ?? ($size - 1);
            }

            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }
            $end = min($end, $size - 1);

            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }

        $length = $end - $start + 1;
        header('Content-Length: ' . $length);

        $fh = fopen($absPath, 'rb');
        if ($fh === false) {
            http_response_code(500);
            exit('Could not open file.');
        }
        fseek($fh, $start);
        $remaining = $length;
        while ($remaining > 0 && !feof($fh)) {
            $chunk = (int) min(8192, $remaining);
            echo fread($fh, $chunk);
            $remaining -= $chunk;
            flush();
        }
        fclose($fh);
        exit;
    }

    header('Content-Length: ' . $size);
    readfile($absPath);
    exit;
}

// ── Download a teacher-uploaded material item ─────────────────────────────────
if (isset($_GET['item'])) {
    $itemId = (int) $_GET['item'];

    $stmt = $db->prepare(
        "SELECT cfi.*, cf.locked AS folder_locked, c.id AS course_id
         FROM course_folder_items cfi
         JOIN course_folders cf ON cf.id = cfi.folder_id
         JOIN courses c ON c.id = cf.course_id
         WHERE cfi.id = ? AND cfi.file_path != ''"
    );
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();

    if (!$item) {
        http_response_code(404);
        exit('Item not found.');
    }

    $courseId = (int) $item['course_id'];

    // Permission: admin/teacher assigned to this course, or enrolled student
    $canAccess = portal_is_admin() || portal_can_manage_course($courseId);
    if (!$canAccess) {
        $enr = $db->prepare("SELECT id FROM enrollments WHERE user_id = ? AND course_id = ?");
        $enr->execute([(int) $me['id'], $courseId]);
        $canAccess = (bool) $enr->fetch();
    }

    if (!$canAccess) {
        portal_log_security_event('forbidden_download', 'medium', 'Blocked download of course material');
        http_response_code(403);
        exit('Access denied.');
    }

    $canManage = portal_is_admin() || portal_can_manage_course($courseId);
    if (!$canManage && portal_folder_item_content_locked($item)) {
        portal_log_security_event(
            'forbidden_download',
            'medium',
            'Blocked download of locked course material item ' . $itemId
        );
        http_response_code(403);
        exit('This material is locked.');
    }

    // Students may only download if the teacher enabled it (inline view is always allowed)
    $canDownload = $canManage;
    if (!$canDownload && !$inlineView) {
        if (empty($item['allow_download'])) {
            http_response_code(403);
            exit('Download is disabled for this file. Ask your teacher to enable it.');
        }
    }

    $abs  = portal_uploads_base() . DIRECTORY_SEPARATOR . $item['file_path'];
    $name = $item['file_name'] !== '' ? $item['file_name'] : basename($item['file_path']);
    portal_send_file($abs, $name, $inlineView);
}

// ── Download a student submission ─────────────────────────────────────────────
if (isset($_GET['sub'])) {
    $subId = (int) $_GET['sub'];

    $stmt = $db->prepare(
        "SELECT cs.*, c.id AS course_id
         FROM course_submissions cs
         JOIN courses c ON c.id = cs.course_id
         WHERE cs.id = ?"
    );
    $stmt->execute([$subId]);
    $sub = $stmt->fetch();

    if (!$sub) {
        http_response_code(404);
        exit('Submission not found.');
    }

    $courseId = (int) $sub['course_id'];

    // Permission: course managers see all; students only see their own
    $canAccess = portal_can_manage_course($courseId)
              || (int) $sub['user_id'] === (int) $me['id'];

    if (!$canAccess) {
        portal_log_security_event('forbidden_download', 'medium', 'Blocked download of submission file');
        http_response_code(403);
        exit('Access denied.');
    }

    $abs  = portal_uploads_base() . DIRECTORY_SEPARATOR . $sub['filepath'];
    portal_send_file($abs, $sub['filename'], $inlineView);
}

http_response_code(400);
exit('Bad request.');
