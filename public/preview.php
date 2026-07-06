<?php
/**
 * Submission document preview.
 *
 * ?sub=<id>           – serve a formatted preview of a student submission.
 *
 * Supported cases:
 *   PDF              → served inline via browser PDF renderer.
 *   DOCX/DOC/PPT/PPTX/ODP/POTX etc.
 *                    → converted to PDF by LibreOffice (cached) then served inline.
 *   Images           → served inline.
 *   TXT              → wrapped in a minimal HTML page.
 *   Anything else    → returns 415 with a download link.
 *
 * The converted PDF is cached in uploads/preview_cache/ keyed by the file's
 * SHA-256 so re-submitting the same file reuses the cached version.
 */
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

portal_require_login();

$db = portal_db();
$me = portal_current_user();

$subId = (int) ($_GET['sub'] ?? 0);
if ($subId <= 0) {
    http_response_code(400);
    exit('Bad request.');
}

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
$canAccess = portal_can_manage_course($courseId) || (int) $sub['user_id'] === (int) $me['id'];
if (!$canAccess) {
    http_response_code(403);
    exit('Access denied.');
}

$absPath  = portal_uploads_base() . DIRECTORY_SEPARATOR . (string) $sub['filepath'];
$filename = (string) $sub['filename'];
$ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

if (!is_file($absPath)) {
    http_response_code(404);
    exit('File not found on disk.');
}

// ── Helper ────────────────────────────────────────────────────────────────────
function portal_preview_send_pdf(string $absPath, string $label): never
{
    $size = (int) filesize($absPath);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . rawurlencode($label) . '"');
    header('Content-Length: ' . $size);
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    while (ob_get_level() > 0) ob_end_clean();
    readfile($absPath);
    exit;
}

function portal_preview_send_image(string $absPath, string $ext, string $label): never
{
    $mimeMap = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
    $mime = $mimeMap[$ext] ?? 'image/png';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . rawurlencode($label) . '"');
    header('Content-Length: ' . filesize($absPath));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    while (ob_get_level() > 0) ob_end_clean();
    readfile($absPath);
    exit;
}

function portal_preview_cache_dir(): string
{
    $dir = portal_uploads_base() . DIRECTORY_SEPARATOR . 'preview_cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        // Block direct web access
        @file_put_contents($dir . DIRECTORY_SEPARATOR . '.htaccess', "Deny from all\n");
    }
    return $dir;
}

function portal_preview_convert_to_pdf(string $absPath, string $sha256, string $ext): ?string
{
    if ($sha256 !== '') {
        $cached = portal_preview_cache_dir() . DIRECTORY_SEPARATOR . $sha256 . '.pdf';
        if (is_file($cached)) {
            return $cached;
        }
    }

    $converter = portal_soffice_converter();
    if ($converter === null || !function_exists('proc_open')) {
        return null;
    }

    $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'portal-prev-' . bin2hex(random_bytes(6));
    if (!mkdir($tmpDir, 0755, true)) {
        return null;
    }

    $converterArg = preg_match('/[\\\\\\/]/', $converter) ? escapeshellarg($converter) : $converter;
    $cmd = $converterArg
        . ' --headless --nologo --nofirststartwizard --convert-to pdf --outdir '
        . escapeshellarg($tmpDir) . ' ' . escapeshellarg($absPath)
        . ' 2>&1';

    $output = [];
    $code = 1;
    exec($cmd, $output, $code);

    $base = pathinfo($absPath, PATHINFO_FILENAME);
    $expected = $tmpDir . DIRECTORY_SEPARATOR . $base . '.pdf';
    $generated = is_file($expected) ? $expected : (glob($tmpDir . DIRECTORY_SEPARATOR . '*.pdf')[0] ?? null);

    $result = null;
    if ($code === 0 && $generated !== null && is_file($generated)) {
        if ($sha256 !== '') {
            $dest = portal_preview_cache_dir() . DIRECTORY_SEPARATOR . $sha256 . '.pdf';
            @rename($generated, $dest);
            $generated = $dest;
        }
        $result = $generated;
    }

    // Cleanup tmp
    foreach (glob($tmpDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) @unlink($f);
    @rmdir($tmpDir);

    return $result;
}

// ── Dispatch by file type ─────────────────────────────────────────────────────

// PDFs → serve directly
if ($ext === 'pdf') {
    portal_preview_send_pdf($absPath, $filename);
}

// Images → serve directly
if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
    portal_preview_send_image($absPath, $ext, $filename);
}

// Plain text → wrap in a simple page
if ($ext === 'txt') {
    $content = (string) file_get_contents($absPath);
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Frame-Options: SAMEORIGIN');
    header('Cache-Control: private, max-age=3600');
    echo '<!doctype html><html><head><meta charset="utf-8">'
        . '<style>body{font-family:Georgia,serif;line-height:1.8;padding:36px 48px;max-width:820px;margin:0 auto;color:#1a1a1a;background:#fff;}'
        . 'pre{white-space:pre-wrap;word-wrap:break-word;font:inherit;}</style></head>'
        . '<body><pre>' . htmlspecialchars($content, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</pre></body></html>';
    exit;
}

// Office formats → try LibreOffice conversion to PDF
$officeExts = ['doc', 'docx', 'ppt', 'pptx', 'pps', 'ppsx', 'pot', 'potx', 'odp', 'odt', 'ods'];
if (in_array($ext, $officeExts, true)) {
    $sha256 = (string) ($sub['file_sha256'] ?? '');
    $pdf = portal_preview_convert_to_pdf($absPath, $sha256, $ext);

    if ($pdf !== null && is_file($pdf)) {
        portal_preview_send_pdf($pdf, pathinfo($filename, PATHINFO_FILENAME) . '.pdf');
    }

    // LibreOffice not available or conversion failed → show friendly fallback page
    $converterAvail = portal_soffice_converter() !== null;
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Frame-Options: SAMEORIGIN');
    echo '<!doctype html><html><head><meta charset="utf-8">'
        . '<style>body{font-family:sans-serif;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;margin:0;gap:16px;color:#555;background:#f8f7f6;}'
        . 'a{background:#8b1122;color:#fff;padding:10px 22px;border-radius:8px;text-decoration:none;font-weight:700;}'
        . 'p{max-width:440px;text-align:center;}</style></head><body>';
    echo '<p><strong>' . htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') . '</strong></p>';
    if (!$converterAvail) {
        echo '<p>Formatted preview requires LibreOffice. Install it and set <code>PORTAL_SOFFICE_PATH</code> in your <code>.env</code> file, or download the file directly.</p>';
    } else {
        echo '<p>The document could not be converted to a preview. Download it to view it in your office application.</p>';
    }
    echo '<a href="download.php?sub=' . $subId . '">Download ' . htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') . '</a>';
    echo '</body></html>';
    exit;
}

// Fallback for anything else
http_response_code(415);
header('Content-Type: text/html; charset=UTF-8');
echo '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:sans-serif;padding:32px;color:#555;}</style></head><body>';
echo '<p>No preview available for this file type (<code>' . htmlspecialchars($ext, ENT_QUOTES, 'UTF-8') . '</code>).</p>';
echo '<p><a href="download.php?sub=' . $subId . '">Download the file</a></p>';
echo '</body></html>';
