<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
portal_require_login();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$mediaId = (int) ($_GET['id'] ?? 0);
if ($mediaId <= 0) {
    http_response_code(400);
    exit('Bad request.');
}

$db = portal_db();
$stmt = $db->prepare('SELECT * FROM activity_media WHERE id = ?');
$stmt->execute([$mediaId]);
$media = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$media) {
    http_response_code(404);
    exit('Media not found.');
}

$courseId = (int) $media['course_id'];
if (!portal_can_access_course($courseId) && !portal_can_manage_course($courseId)) {
    portal_log_security_event('activity_media_denied', 'medium', 'media_id=' . $mediaId);
    http_response_code(403);
    exit('Access denied.');
}

// Media must belong to an accessible activity when linked, or be course-scoped for managers.
$activityId = (int) ($media['activity_id'] ?? 0);
$activity = null;
if ($activityId > 0) {
    $activity = portal_activity_find($activityId);
    if ($activity === null || (int) $activity['course_id'] !== $courseId) {
        http_response_code(404);
        exit('Media not found.');
    }
    if (($activity['status'] ?? '') === 'draft' && !portal_can_manage_course($courseId)) {
        http_response_code(403);
        exit('Access denied.');
    }
} elseif (!portal_can_manage_course($courseId)) {
    http_response_code(403);
    exit('Access denied.');
}

if (!portal_can_manage_course($courseId)
    && portal_course_content_blocked_for_student($courseId, (int) (portal_current_user()['id'] ?? 0), $activity)) {
    portal_log_security_event('activity_media_denied', 'medium', 'gated media_id=' . $mediaId);
    http_response_code(403);
    exit('This module is not available yet.');
}

$path = portal_activity_media_path_safe((string) $media['storage_path']);
if ($path === null) {
    portal_log_security_event('activity_media_path_reject', 'high', 'media_id=' . $mediaId);
    http_response_code(404);
    exit('Media not found.');
}

$mime = (string) ($media['mime_type'] ?: 'application/octet-stream');
$filename = (string) ($media['original_filename'] ?: ('media-' . $mediaId));
$filenameSafe = str_replace(['"', "\r", "\n"], '', $filename);
$size = (int) filesize($path);
$mediaType = (string) ($media['media_type'] ?? '');
$inline = in_array($mediaType, ['image', 'audio', 'video'], true)
    || str_starts_with($mime, 'image/')
    || str_starts_with($mime, 'audio/')
    || str_starts_with($mime, 'video/');

header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
header(
    ($inline ? 'Content-Disposition: inline' : 'Content-Disposition: attachment')
    . '; filename="' . $filenameSafe . '"'
);
header('Cache-Control: private, max-age=3600');
if ($inline && str_starts_with($mime, 'image/')) {
    header('Accept-Ranges: none');
} else {
    header('Accept-Ranges: bytes');
}

$start = 0;
$end = $size > 0 ? $size - 1 : 0;
$length = $size;
$allowRange = !($inline && str_starts_with($mime, 'image/'));

$range = $allowRange ? (string) ($_SERVER['HTTP_RANGE'] ?? '') : '';
if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
    if ($m[1] !== '') {
        $start = (int) $m[1];
    }
    if ($m[2] !== '') {
        $end = (int) $m[2];
    }
    if ($end >= $size) {
        $end = $size - 1;
    }
    if ($start > $end || $start < 0 || $size <= 0) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }
    $length = $end - $start + 1;
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}

header('Content-Length: ' . $length);

$fp = fopen($path, 'rb');
if ($fp === false) {
    http_response_code(500);
    exit('Could not read media.');
}

if ($start > 0) {
    fseek($fp, $start);
}

$remaining = $length;
while ($remaining > 0 && !feof($fp)) {
    $chunk = fread($fp, min(8192, $remaining));
    if ($chunk === false || $chunk === '') {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
    if (connection_aborted()) {
        break;
    }
}

fclose($fp);
exit;
