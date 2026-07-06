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
    ];
    $mime        = $mimeMap[$ext] ?? 'application/octet-stream';
    $disposition = $inline ? 'inline' : 'attachment';
    $safeName    = str_replace(["\r", "\n", '"'], ['', '', "'"], $displayName !== '' ? $displayName : basename($absPath));
    $encodedName = rawurlencode($safeName);

    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"; filename*=UTF-8\'\'' . $encodedName);
    header('Content-Length: ' . $size);
    header('Cache-Control: private, no-cache');

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    readfile($absPath);
    exit;
}

// ── Download a teacher-uploaded material item ─────────────────────────────────
if (isset($_GET['item'])) {
    $itemId = (int) $_GET['item'];

    $stmt = $db->prepare(
        "SELECT cfi.*, c.id AS course_id
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
        http_response_code(403);
        exit('Access denied.');
    }

    // Students may only download if the teacher enabled it (inline view is always allowed)
    $canDownload = portal_is_admin() || portal_can_manage_course($courseId);
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
        http_response_code(403);
        exit('Access denied.');
    }

    $abs  = portal_uploads_base() . DIRECTORY_SEPARATOR . $sub['filepath'];
    portal_send_file($abs, $sub['filename'], $inlineView);
}

http_response_code(400);
exit('Bad request.');
