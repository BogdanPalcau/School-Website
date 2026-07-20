<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
portal_require_login();

// Safe migration — add allow_download if not yet present
try {
    portal_db()->exec("ALTER TABLE course_folder_items ADD COLUMN allow_download TINYINT(1) NOT NULL DEFAULT 0");
} catch (\PDOException $e) {}

$db  = portal_db();
$me  = portal_current_user();

$itemId = (int) ($_GET['item'] ?? 0);
if (!$itemId) { http_response_code(400); exit('Bad request.'); }

$stmt = $db->prepare(
    "SELECT cfi.*, c.id AS course_id, c.slug AS course_slug, c.full_title AS course_title
     FROM course_folder_items cfi
     JOIN course_folders cf ON cf.id = cfi.folder_id
     JOIN courses c ON c.id = cf.course_id
     WHERE cfi.id = ? AND cfi.file_path != ''"
);
$stmt->execute([$itemId]);
$item = $stmt->fetch();

if (!$item) { http_response_code(404); exit('File not found.'); }

$courseId  = (int) $item['course_id'];
$canManage = portal_is_admin() || portal_can_manage_course($courseId);

$canAccess = $canManage;
if (!$canAccess) {
    $enr = $db->prepare("SELECT id FROM enrollments WHERE user_id = ? AND course_id = ?");
    $enr->execute([(int) $me['id'], $courseId]);
    $canAccess = (bool) $enr->fetch();
}
if (!$canAccess) { http_response_code(403); exit('Access denied.'); }

if (!function_exists('portal_view_presentation_converter')) {
    function portal_view_presentation_converter(): ?string
    {
        $envPath = trim((string) getenv('PORTAL_SOFFICE_PATH'));
        $candidates = [];
        if ($envPath !== '') {
            $candidates[] = $envPath;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $programFiles = array_filter([
                getenv('ProgramFiles') ?: '',
                getenv('ProgramFiles(x86)') ?: '',
                'C:\\Program Files',
                'C:\\Program Files (x86)',
            ]);
            foreach (array_unique($programFiles) as $base) {
                $candidates[] = $base . DIRECTORY_SEPARATOR . 'LibreOffice' . DIRECTORY_SEPARATOR . 'program' . DIRECTORY_SEPARATOR . 'soffice.com';
                $candidates[] = $base . DIRECTORY_SEPARATOR . 'LibreOffice' . DIRECTORY_SEPARATOR . 'program' . DIRECTORY_SEPARATOR . 'soffice.exe';
            }
        } else {
            $candidates[] = '/usr/bin/soffice';
            $candidates[] = '/usr/local/bin/soffice';
            $candidates[] = '/usr/bin/libreoffice';
            $candidates[] = '/usr/local/bin/libreoffice';
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        if (is_callable('shell_exec')) {
            $lookup = PHP_OS_FAMILY === 'Windows' ? 'where soffice 2>NUL' : 'command -v soffice 2>/dev/null';
            $found = trim((string) @shell_exec($lookup));
            if ($found !== '') {
                $first = strtok($found, "\r\n");
                if ($first !== false && $first !== '') {
                    return $first;
                }
            }
        }

        return null;
    }
}

if (!function_exists('portal_view_remove_dir')) {
    function portal_view_remove_dir(string $dir): void
    {
        if (!is_dir($dir) || strpos(basename($dir), 'portal-presentation-') !== 0) {
            return;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        ) as $path) {
            $path->isDir() ? @rmdir($path->getPathname()) : @unlink($path->getPathname());
        }
        @rmdir($dir);
    }
}

if (!function_exists('portal_view_presentation_pdf')) {
    function portal_view_presentation_pdf(string $source): array
    {
        if (!is_file($source) || filesize($source) <= 0) {
            return [null, 'The presentation file is missing or empty.'];
        }

        $converter = portal_view_presentation_converter();
        if ($converter === null) {
            return [null, 'Presentation preview needs LibreOffice on the server. Install LibreOffice, or set PORTAL_SOFFICE_PATH to soffice.exe.'];
        }
        if (!function_exists('exec')) {
            return [null, 'The server has disabled command execution, so presentations cannot be converted for preview.'];
        }

        $cacheDir = portal_uploads_base() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'presentations';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $cacheKey = hash('sha256', realpath($source) . '|' . filesize($source) . '|' . filemtime($source));
        $cachePdf = $cacheDir . DIRECTORY_SEPARATOR . $cacheKey . '.pdf';
        if (is_file($cachePdf) && filesize($cachePdf) > 0) {
            return [$cachePdf, null];
        }

        @set_time_limit(90);
        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'portal-presentation-' . bin2hex(random_bytes(8));
        if (!mkdir($tmpDir, 0755, true)) {
            return [null, 'Could not prepare a temporary folder for presentation preview.'];
        }

        $converterArg = preg_match('/[\\\\\\/]/', $converter) ? escapeshellarg($converter) : $converter;
        $cmd = $converterArg
            . ' --headless --nologo --nofirststartwizard --convert-to pdf --outdir '
            . escapeshellarg($tmpDir) . ' ' . escapeshellarg($source) . ' 2>&1';

        $output = [];
        $code = 1;
        exec($cmd, $output, $code);

        $expected = $tmpDir . DIRECTORY_SEPARATOR . pathinfo($source, PATHINFO_FILENAME) . '.pdf';
        $generated = is_file($expected) ? $expected : (glob($tmpDir . DIRECTORY_SEPARATOR . '*.pdf')[0] ?? null);

        if ($code !== 0 || $generated === null || !is_file($generated) || filesize($generated) <= 0) {
            portal_view_remove_dir($tmpDir);
            $detail = trim(implode(' ', array_slice($output, -3)));
            return [null, $detail !== '' ? 'Could not convert this presentation: ' . $detail : 'Could not convert this presentation.'];
        }

        if (!@rename($generated, $cachePdf)) {
            @copy($generated, $cachePdf);
            @unlink($generated);
        }
        portal_view_remove_dir($tmpDir);

        if (!is_file($cachePdf) || filesize($cachePdf) <= 0) {
            return [null, 'Could not save the converted presentation preview.'];
        }

        return [$cachePdf, null];
    }
}

if (!function_exists('portal_view_send_pdf')) {
    function portal_view_send_pdf(string $absPath, string $displayName): never
    {
        if (!is_file($absPath)) {
            http_response_code(404);
            exit('Preview not found.');
        }

        $safeName = str_replace(["\r", "\n", '"'], ['', '', "'"], $displayName);
        header('Content-Type: application/pdf');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline; filename="' . $safeName . '.pdf"');
        header('Content-Length: ' . filesize($absPath));
        header('Cache-Control: private, no-cache');

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        readfile($absPath);
        exit;
    }
}

// Use empty() — PDO/SQLite often returns "0" as a string, and (bool)"0" is true in PHP.
$allowDownload = !empty($item['allow_download']);
$canDownload   = $canManage || $allowDownload;
$displayName   = $item['file_name'] !== '' ? $item['file_name'] : basename($item['file_path']);
$ext           = strtolower(pathinfo($displayName, PATHINFO_EXTENSION));
$absPath       = portal_uploads_base() . DIRECTORY_SEPARATOR . $item['file_path'];
$isPresentation = portal_is_presentation_file($displayName);
$fileUrl       = 'download.php?item=' . $itemId . '&view=1';
$downloadUrl   = 'download.php?item=' . $itemId;
$backUrl       = 'course.php?course=' . urlencode((string) $item['course_slug']) . '&section=content';
$canConvertPresentation = $isPresentation && portal_view_presentation_converter() !== null;
$presentationPdfUrl = 'view.php?item=' . $itemId . '&presentation_pdf=1';

if ($isPresentation && isset($_GET['presentation_pdf'])) {
    [$pdfPath, $error] = portal_view_presentation_pdf($absPath);
    if ($pdfPath === null) {
        http_response_code(503);
        exit($error ?? 'Could not render this presentation.');
    }
    portal_view_send_pdf($pdfPath, pathinfo($displayName, PATHINFO_FILENAME));
}

// ── Which rendering engine handles this file? ──────────────────────────────
$engineKind = 'none';
$pdfSrcUrl  = '';
if ($ext === 'pdf') {
    $engineKind = 'pdf';
    $pdfSrcUrl  = $fileUrl;
} elseif ($isPresentation && $canConvertPresentation) {
    $engineKind = 'pdf';
    $pdfSrcUrl  = $presentationPdfUrl;
} elseif ($ext === 'docx') {
    $engineKind = 'docx';
} elseif ($ext === 'xlsx') {
    $engineKind = 'xlsx';
} elseif ($ext === 'pptx') {
    $engineKind = 'pptx';
}

$fileIcons = [
    'pdf'  => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"/><polyline points="14 2 14 8 20 8"/>',
    'docx' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"/><polyline points="14 2 14 8 20 8"/>',
    'xlsx' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 3v18"/>',
    'pptx' => '<rect x="3" y="4" width="18" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 18v3"/><path d="M8 9h8"/><path d="M8 13h5"/>',
];
$fileIcon = $isPresentation ? $fileIcons['pptx'] : ($fileIcons[$ext] ?? $fileIcons['pdf']);
$isEmbedHint = isset($_GET['embed']) && (string) $_GET['embed'] !== '0' && (string) $_GET['embed'] !== '';
?>
<!DOCTYPE html>
<html lang="en" class="<?= $isEmbedHint ? 'is-embedded' : '' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= htmlspecialchars($displayName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
<style>
:root {
    --primary: #c1202f;
    --primary-strong: #820f1a;
    --primary-soft: #ffd7db;
    --primary-soft-strong: #f7b1b8;
    --surface: #ffffff;
    --surface-muted: #fff8f7;
    --surface-alt: #fff1f0;
    --canvas: #f1ecea;
    --text: #281315;
    --muted: #715254;
    --border: rgba(130, 15, 26, 0.14);
    --radius-lg: 20px;
    --radius-md: 14px;
    --radius-sm: 10px;
    --shadow-page: 0 6px 24px rgba(40, 19, 21, 0.16);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; overflow: hidden; }
body {
    font-family: 'Manrope', system-ui, -apple-system, sans-serif;
    display: flex; flex-direction: column; background: var(--canvas); color: var(--text);
}
button { font-family: inherit; }

/* ── Toolbar ─────────────────────────────────────────────────────────────── */
.vt {
    display: flex; align-items: center; gap: 8px;
    padding: 0 16px; height: 56px; flex-shrink: 0;
    background: var(--surface); border-bottom: 1px solid var(--border);
    box-shadow: 0 1px 0 rgba(40,19,21,0.02);
}
.vt-back {
    display: flex; align-items: center; gap: 6px;
    color: var(--muted); background: transparent; border: 0; cursor: pointer;
    font-size: 0.85rem; font-weight: 700; padding: 8px 12px;
    border-radius: 9px; transition: background .14s, color .14s;
    white-space: nowrap; flex-shrink: 0; text-decoration: none;
}
.vt-back:hover { background: var(--primary-soft); color: var(--primary-strong); }
.vt-back svg { width: 15px; height: 15px; }
.vt-sep { width: 1px; height: 26px; background: var(--border); flex-shrink: 0; }

.vt-fileinfo { display: flex; align-items: center; gap: 8px; min-width: 0; overflow: hidden; }
.vt-fileicon { width: 18px; height: 18px; color: var(--primary); flex-shrink: 0; }
.vt-name {
    font-size: 0.92rem; font-weight: 700; color: var(--text);
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.vt-ext {
    font-size: 0.62rem; font-weight: 800; letter-spacing: .06em;
    background: var(--primary-soft); color: var(--primary-strong);
    padding: 2px 7px; border-radius: 5px; flex-shrink: 0;
}
.vt-spacer { flex: 1 1 auto; min-width: 8px; }

/* Icon buttons shared across the toolbar */
.vt-icon-btn {
    width: 36px; height: 36px; border: none; background: transparent; color: var(--muted);
    cursor: pointer; border-radius: 9px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; transition: background .12s, color .12s;
}
.vt-icon-btn:hover { background: var(--primary-soft); color: var(--primary-strong); }
.vt-icon-btn.is-active { background: var(--primary-soft-strong); color: var(--primary-strong); }
.vt-icon-btn svg { width: 17px; height: 17px; }
.vt-icon-btn[hidden] { display: none !important; }

/* Page navigation */
.vt-pagenav { display: flex; align-items: center; gap: 4px; flex-shrink: 0; }
.vt-pageinput {
    display: flex; align-items: center; gap: 4px; font-size: 0.8rem; font-weight: 700; color: var(--muted);
    background: var(--surface-alt); border-radius: 8px; padding: 4px 8px;
}
.vt-pageinput input {
    width: 34px; text-align: center; border: none; background: transparent; font: inherit; font-weight: 700;
    color: var(--text); -moz-appearance: textfield;
}
.vt-pageinput input:focus { outline: none; }

/* Zoom */
.vt-zoom { display: flex; align-items: center; gap: 1px; position: relative; flex-shrink: 0; }
.vt-zpct {
    min-width: 50px; text-align: center; font-size: 0.78rem; font-weight: 700; color: var(--muted);
    background: transparent; border: none; cursor: pointer; padding: 6px 4px; border-radius: 7px;
}
.vt-zpct:hover { background: var(--primary-soft); color: var(--primary-strong); }
.vt-zoom-menu {
    position: absolute; top: 42px; right: 0; z-index: 20;
    background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-sm);
    box-shadow: 0 14px 36px rgba(40,19,21,0.18); padding: 6px; min-width: 148px;
    display: flex; flex-direction: column; gap: 1px;
}
.vt-zoom-menu[hidden] { display: none !important; }
.vt-zoom-menu button {
    text-align: left; border: none; background: transparent; padding: 8px 10px; border-radius: 7px;
    font-size: 0.82rem; font-weight: 600; color: var(--text); cursor: pointer;
}
.vt-zoom-menu button:hover { background: var(--primary-soft); color: var(--primary-strong); }
.vt-zoom-menu hr { border: none; border-top: 1px solid var(--border); margin: 4px 2px; }

/* Download button */
.vt-dl {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 15px; border-radius: 9px;
    background: var(--primary); color: #fff;
    text-decoration: none; font-size: 0.82rem; font-weight: 700;
    transition: background .14s; white-space: nowrap; flex-shrink: 0;
}
.vt-dl:hover { background: var(--primary-strong); }
.vt-dl svg { width: 14px; height: 14px; }

/* ── Search bar (row 2) ─────────────────────────────────────────────────── */
.vt-search {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 16px; background: var(--surface-muted); border-bottom: 1px solid var(--border);
    flex-shrink: 0;
}
.vt-search[hidden] { display: none !important; }
.vt-search svg { width: 15px; height: 15px; color: var(--muted); flex-shrink: 0; }
.vt-search input {
    flex: 1; min-width: 0; border: none; background: transparent; font: inherit; font-size: 0.86rem;
    color: var(--text);
}
.vt-search input:focus { outline: none; }
.vt-search-count { font-size: 0.78rem; color: var(--muted); font-weight: 600; white-space: nowrap; }
.vt-search .vt-icon-btn { width: 30px; height: 30px; }
.vt-search .vt-icon-btn svg { width: 14px; height: 14px; }

/* ── Content area ────────────────────────────────────────────────────────── */
.vc { flex: 1; overflow: hidden; position: relative; display: flex; min-height: 0; }

/* Sidebar (PDF pages/outline) */
.vc-sidebar {
    width: 220px; flex-shrink: 0; background: var(--surface); border-right: 1px solid var(--border);
    display: flex; flex-direction: column; overflow: hidden;
    transition: width .18s ease, opacity .18s ease;
}
.vc-sidebar[hidden] { display: none !important; }
.vc-sidebar.is-collapsed { width: 0; opacity: 0; border-right-color: transparent; }
.vc-sidebar-tabs { display: flex; border-bottom: 1px solid var(--border); flex-shrink: 0; }
.vc-sidebar-tab {
    flex: 1; padding: 10px 6px; text-align: center; font-size: 0.76rem; font-weight: 700;
    color: var(--muted); background: transparent; border: none; cursor: pointer;
    border-bottom: 2px solid transparent; transition: color .12s, border-color .12s;
}
.vc-sidebar-tab:hover { color: var(--primary-strong); }
.vc-sidebar-tab.is-active { color: var(--primary-strong); border-bottom-color: var(--primary); }
.vc-sidebar-tab[hidden] { display: none !important; }
.vc-sidebar-panel { flex: 1; overflow-y: auto; padding: 12px; }
.vc-sidebar-panel[hidden] { display: none !important; }

.pdf-thumb {
    display: flex; flex-direction: column; align-items: center; gap: 6px;
    width: 100%; padding: 8px; margin-bottom: 8px; border-radius: var(--radius-sm);
    border: 2px solid var(--border); background: var(--surface-muted); cursor: pointer;
    transition: border-color .14s, background .14s;
}
.pdf-thumb:hover { border-color: var(--primary-soft-strong); }
.pdf-thumb.is-active { border-color: var(--primary); background: var(--primary-soft); }
.pdf-thumb-canvas-wrap { display: flex; justify-content: center; width: 100%; }
.pdf-thumb canvas { max-width: 100%; box-shadow: 0 2px 8px rgba(40,19,21,0.18); background: #fff; }
.pdf-thumb-n { font-size: 0.7rem; font-weight: 700; color: var(--muted); }
.pdf-thumb.is-active .pdf-thumb-n { color: var(--primary-strong); }

.pdf-outline-list { list-style: none; }
.pdf-outline-item {
    display: block; width: 100%; text-align: left; border: none; background: transparent;
    padding: 8px 10px; border-radius: 7px; font-size: 0.82rem; font-weight: 600; color: var(--text);
    cursor: pointer; transition: background .12s, color .12s;
}
.pdf-outline-item:hover { background: var(--primary-soft); color: var(--primary-strong); }
.vc-sidebar-empty { padding: 20px 10px; color: var(--muted); font-size: 0.82rem; text-align: center; }

/* Main viewer */
.vc-main { flex: 1; min-width: 0; overflow: hidden; position: relative; display: flex; flex-direction: column; }

/* PDF continuous scroll viewport */
.pdf-viewport { flex: 1; overflow: auto; background: var(--canvas); padding: 24px 0 28px; position: relative; }
.pdf-zoom-host {
    margin: 0 auto;
    overflow: hidden;
    /* width/height set in JS to match the visual (scaled) size */
}
.pdf-zoom-wrap { transform-origin: top left; width: max-content; margin: 0 auto; }
.pdf-pages { display: flex; flex-direction: column; align-items: center; gap: 22px; }
.pdf-page { position: relative; background: #fff; box-shadow: var(--shadow-page); border-radius: 3px; overflow: hidden; }
.pdf-page.is-placeholder { background: linear-gradient(180deg, #fff 0%, var(--surface-alt) 100%); }
.pdf-page.is-placeholder::after {
    content: '';
    position: absolute; inset: 0;
    background: linear-gradient(90deg, transparent, rgba(193,32,47,0.06), transparent);
    background-size: 200% 100%;
    animation: pdf-shimmer 1.4s ease-in-out infinite;
}
@keyframes pdf-shimmer {
    0% { background-position: 100% 0; }
    100% { background-position: -100% 0; }
}
.pdf-canvas { display: block; }
.textLayer {
    position: absolute; inset: 0; overflow: hidden; line-height: 1;
    text-align: initial; opacity: 1; transform-origin: 0 0;
}
.textLayer span, .textLayer br {
    color: transparent; position: absolute; white-space: pre; cursor: text;
    transform-origin: 0% 0%;
}
.textLayer ::selection { background: rgba(193, 32, 47, 0.35); }
.textLayer mark.pdf-hl { background: rgba(253, 224, 71, 0.65); color: transparent; border-radius: 2px; }
.textLayer mark.pdf-hl--active { background: rgba(253, 176, 45, 0.9); }

/* Office scroll wrapper (docx / xlsx / pptx-text) */
.vc-scroll { width: 100%; height: 100%; overflow: auto; background: var(--canvas); padding: 28px 20px; }
.vc-zoom-host {
    margin: 0 auto;
    overflow: hidden;
}
.vc-zoom-wrap { transform-origin: top left; width: max-content; margin: 0 auto; transition: transform .15s ease; }

/* ── DOCX pages ───────────────────────────────────────────────────────────
   Real pagination (text/table/list splitting across pages) is handled by the
   Paged.js engine, which renders one .pagedjs_page per page inside #vc-body.
   The @page CSS (size/margin/page-number) is injected at runtime — see the
   docx renderer below. These rules just theme the resulting pages. */
.vc-zoom-wrap .pagedjs_pages {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 24px;
    width: max-content;
    margin: 0 auto;
}
.vc-zoom-wrap .pagedjs_page {
    background: #fff !important;
    box-shadow: var(--shadow-page);
    border-radius: 3px;
    margin: 0 !important;
}
.vc-zoom-wrap .pagedjs_page mark.doc-hl { background: rgba(253, 224, 71, 0.65); border-radius: 2px; }
.vc-zoom-wrap .pagedjs_page mark.doc-hl--active { background: rgba(253, 176, 45, 0.9); }

/* Fallback: single continuous page, used only if Paged.js fails to load. */
.docx-page {
    width: 816px; max-width: 100%; box-sizing: border-box;
    background: #fff; margin: 0 auto;
    padding: 72px 88px; min-height: 1000px;
    box-shadow: var(--shadow-page);
    border-radius: 3px;
    font-family: "Calibri", "Georgia", serif; font-size: 12pt; line-height: 1.65; color: #1a1a1a;
}
.docx-page h1 { font-size: 2em; margin: 1em 0 .5em; }
.docx-page h2 { font-size: 1.5em; margin: .9em 0 .4em; }
.docx-page h3 { font-size: 1.2em; margin: .7em 0 .3em; }
.docx-page p  { margin: .5em 0; }
.docx-page table { border-collapse: collapse; width: 100%; margin: 1em 0; }
.docx-page td, .docx-page th { border: 1px solid #ccc; padding: 6px 10px; }
.docx-page img { max-width: 100%; height: auto; }
.docx-page ul, .docx-page ol { padding-left: 2em; margin: .5em 0; }
.docx-page mark.doc-hl { background: rgba(253, 224, 71, 0.65); border-radius: 2px; }
.docx-page mark.doc-hl--active { background: rgba(253, 176, 45, 0.9); }

/* Phone reading mode: fluid column — zoom grows text, still wraps to screen width. */
.docx-page--fluid {
    width: 100% !important;
    max-width: 100%;
    height: auto !important;
    min-height: 0;
    margin: 0;
    padding: 22px 18px 40px;
    border-radius: 0;
    box-shadow: none;
    overflow: visible;
    font-size: calc(11.5pt * var(--docx-zoom, 1));
    line-height: 1.7;
}
body.is-reading .vc-zoom-host,
body.is-reading .vc-zoom-wrap {
    width: 100% !important;
    max-width: 100%;
    height: auto !important;
    margin: 0;
    transform: none !important;
}
body.is-reading .vc-scroll {
    overflow-x: hidden;
    padding: 0;
}

/* ── XLSX ────────────────────────────────────────────────────────────────── */
.xlsx-wrap { display: flex; flex-direction: column; height: 100%; }
.xlsx-tabs { display: flex; background: var(--surface); border-bottom: 1px solid var(--border); overflow-x: auto; flex-shrink: 0; }
.xlsx-tab {
    padding: 9px 18px; font-size: 0.8rem; font-weight: 700; cursor: pointer;
    color: var(--muted); border-bottom: 2px solid transparent;
    white-space: nowrap; transition: color .12s;
}
.xlsx-tab:hover { color: var(--primary-strong); }
.xlsx-tab.active { color: var(--primary-strong); border-bottom-color: var(--primary); }
.xlsx-body { flex: 1; overflow: auto; background: #fff; }
.xlsx-tbl { border-collapse: collapse; font-size: 0.82rem; }
.xlsx-tbl th {
    background: var(--surface-alt); font-weight: 700; border: 1px solid var(--border);
    padding: 5px 10px; min-width: 80px; text-align: center; position: sticky; top: 0; z-index: 1;
}
.xlsx-tbl td { border: 1px solid #ececea; padding: 4px 9px; min-width: 80px; white-space: nowrap; }
.xlsx-rn { background: var(--surface-alt); color: var(--muted); text-align: center; font-weight: 700; position: sticky; left: 0; }
.xlsx-tbl mark.doc-hl { background: rgba(253, 224, 71, 0.65); border-radius: 2px; }
.xlsx-tbl mark.doc-hl--active { background: rgba(253, 176, 45, 0.9); }

/* ── PPTX deck (text fallback) ───────────────────────────────────────────── */
.pptx-wrap { display: flex; flex-direction: column; height: 100%; }
.pptx-thumbs {
    display: flex; gap: 8px; padding: 10px 16px; background: var(--surface-muted);
    overflow-x: auto; flex-shrink: 0; border-bottom: 1px solid var(--border);
}
.pptx-thumb {
    flex-shrink: 0; width: 100px; aspect-ratio: 16/9;
    border: 2px solid var(--border); border-radius: 6px;
    background: #fff; cursor: pointer;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 3px; padding: 4px 6px; transition: border-color .14s;
}
.pptx-thumb.active  { border-color: var(--primary); }
.pptx-thumb:hover   { border-color: var(--primary-soft-strong); }
.pptx-thumb-n       { font-size: 0.68rem; font-weight: 800; color: var(--muted); }
.pptx-thumb.active .pptx-thumb-n { color: var(--primary-strong); }
.pptx-thumb-t       { font-size: 0.52rem; color: var(--muted); text-align: center; overflow: hidden; max-height: 22px; line-height: 1.2; }
.pptx-thumb.active .pptx-thumb-t { color: var(--primary-strong); }

.pptx-viewport {
    flex: 1; overflow: auto; background: var(--canvas);
    display: flex; align-items: center; justify-content: center; padding: 28px 24px;
    position: relative; min-height: 0;
}
.pptx-stage {
    flex: 1; min-height: 0; position: relative; display: flex; flex-direction: column;
}

.pptx-slide {
    background: #fff;
    width: 900px;
    aspect-ratio: 16/9;
    display: none; flex-direction: column;
    overflow: hidden;
    border-radius: 4px;
    box-shadow: var(--shadow-page);
    position: relative;
}
.pptx-slide.active { display: flex; }

.pptx-slide-hdr {
    background: var(--sa, #1e3a5f);
    padding: 18px 44px 20px;
    flex-shrink: 0;
    display: flex; align-items: center;
    min-height: 30%;
}
.pptx-slide-title {
    color: #fff; font-size: 1.65rem; font-weight: 700;
    line-height: 1.25; letter-spacing: -.01em;
}
.pptx-slide-bar { height: 5px; background: var(--sa, #1e3a5f); flex-shrink: 0; }
.pptx-slide-body-wrap {
    flex: 1; padding: 18px 44px 28px;
    display: flex; flex-direction: column; overflow: hidden;
}
.pptx-slide-body { font-size: 1rem; line-height: 1.6; color: #1a1a1a; }
.pptx-slide-body ul  { padding-left: 1.3em; margin: 0; }
.pptx-slide-body li  { margin-bottom: 5px; }
.pptx-slide-body mark.doc-hl { background: rgba(253, 224, 71, 0.65); border-radius: 2px; }
.pptx-slide-body mark.doc-hl--active { background: rgba(253, 176, 45, 0.9); }
.pptx-slide-n {
    position: absolute; bottom: 7px; right: 11px;
    font-size: 0.62rem; color: rgba(0,0,0,.28); font-weight: 600;
}

/* Bottom slide controls (PPTX fallback + converted presentations) */
.slide-bar {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 14px;
    padding: 10px 16px 14px;
    background: var(--surface);
    border-top: 1px solid var(--border);
}
.slide-arrow {
    width: 44px; height: 44px;
    border: 1px solid var(--border);
    border-radius: 50%;
    background: var(--surface-alt);
    color: var(--primary-strong);
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: background .14s, color .14s, border-color .14s, box-shadow .14s;
}
.slide-arrow:hover:not(:disabled) {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
    box-shadow: 0 6px 16px rgba(193,32,47,0.22);
}
.slide-arrow:disabled { opacity: .32; cursor: default; }
.slide-arrow svg { width: 20px; height: 20px; }
.slide-chip {
    min-width: 118px;
    text-align: center;
    font-size: 0.84rem;
    font-weight: 700;
    color: var(--muted);
    padding: 8px 12px;
    border-radius: 999px;
    background: var(--surface-muted);
}

/* ── Loading / error / unsupported ───────────────────────────────────────── */
.vw-loading {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 14px; padding: 90px 20px; color: var(--muted); font-size: 0.9rem; font-weight: 600;
}
.vw-spinner {
    width: 26px; height: 26px; border: 3px solid var(--primary-soft);
    border-top-color: var(--primary); border-radius: 50%;
    animation: vspin .7s linear infinite; flex-shrink: 0;
}
@keyframes vspin { to { transform: rotate(360deg); } }
.vw-progress { font-size: 0.78rem; color: var(--muted); }
.vw-error, .vw-unsupported {
    max-width: 460px; margin: 60px auto; padding: 32px; text-align: center;
    background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg);
    box-shadow: var(--shadow-page);
}
.vw-unsupported h2 { font-family: 'Fraunces', serif; font-size: 1.2rem; margin-bottom: 8px; }
.vw-unsupported p { color: var(--muted); font-size: 0.9rem; margin-bottom: 18px; line-height: 1.6; }
.vw-error { color: #a3212f; font-size: 0.9rem; }
.vw-btn {
    display: inline-flex; align-items: center; gap: 8px; padding: 10px 22px;
    background: var(--primary); color: #fff; border-radius: 10px; text-decoration: none;
    font-weight: 700; font-size: 0.88rem; transition: background .14s;
}
.vw-btn:hover { background: var(--primary-strong); }
.vw-warning {
    padding: 9px 16px;
    background: #fff6e0;
    color: #8a6212;
    font-size: 0.82rem; font-weight: 600;
    border-bottom: 1px solid var(--border);
}

/* ── Fullscreen ───────────────────────────────────────────────────────────── */
body:fullscreen .vt-icon-btn#vt-fullscreen svg,
body:-webkit-full-screen .vt-icon-btn#vt-fullscreen svg { transform: scale(0.92); }

/* ── Print ────────────────────────────────────────────────────────────────── */
@media print {
    .vt, .vt-search, .slide-bar { display: none !important; }
    .vc-sidebar { display: none !important; }
    html, body { height: auto !important; overflow: visible !important; }
    .vc { overflow: visible !important; }
    .vc-main, .vc-scroll, .pdf-viewport { overflow: visible !important; height: auto !important; }
    .vc-zoom-wrap, .pdf-zoom-wrap { transform: none !important; }
    .vc-zoom-host, .pdf-zoom-host { width: auto !important; height: auto !important; overflow: visible !important; }
    .pagedjs_pages { gap: 0 !important; }
    .pagedjs_page, .docx-page {
        box-shadow: none !important;
        border-radius: 0 !important;
    }
}

/* ── Responsive ───────────────────────────────────────────────────────────── */
@media (max-width: 860px) {
    .vc-sidebar { position: absolute; z-index: 15; height: 100%; box-shadow: 8px 0 24px rgba(40,19,21,0.18); }
    .vt-pageinput { display: none; }
    .vt-name { max-width: 30vw; }
    .slide-arrow { width: 48px; height: 48px; }
    .slide-bar { gap: 10px; padding: 12px 12px 16px; }
    .pptx-viewport { padding: 12px; }
    .pdf-viewport, .vc-scroll, .pptx-viewport {
        -webkit-overflow-scrolling: touch;
        overscroll-behavior: contain;
    }
    .pdf-viewport { padding: 12px 0 16px; }
    .vc-scroll { padding: 14px 12px; }

    /* Course.php supplies the document title and close action on phones. */
    html.is-embedded, html.is-embedded body {
        height: 100%;
        height: 100dvh;
    }
    body.is-embedded .vt {
        height: 48px;
        padding: 0 8px;
        gap: 4px;
        justify-content: flex-end;
    }
    body.is-embedded .vt-back,
    body.is-embedded .vt-sep,
    body.is-embedded .vt-fileinfo,
    body.is-embedded .vt-ext,
    body.is-embedded #vt-print,
    body.is-embedded #vt-fullscreen,
    body.is-embedded #vt-sidebar-toggle { display: none !important; }
    body.is-embedded .vt-spacer { display: none; }
    body.is-embedded .vt-pagenav { margin-right: auto; }
    body.is-embedded .vt-icon-btn { width: 40px; height: 40px; }
    body.is-embedded .vt-zpct { min-width: 44px; padding: 8px 6px; }
    body.is-embedded .vt-dl {
        width: 40px;
        height: 40px;
        padding: 0;
        justify-content: center;
        font-size: 0;
        gap: 0;
    }
    body.is-embedded .vt-dl svg { width: 17px; height: 17px; }
    body.is-embedded .vc-scroll {
        padding: 8px 0 12px;
        overflow-x: hidden;
    }
    body.is-embedded .pdf-viewport {
        padding: 8px 0 12px;
        overflow-x: hidden;
    }
    body.is-embedded .vc-zoom-wrap .pagedjs_pages {
        gap: 14px;
    }
}
</style>
</head>
<body class="<?= $isEmbedHint ? 'is-embedded' : '' ?>" data-engine="<?= htmlspecialchars($engineKind) ?>"<?= $isPresentation ? ' data-presentation="1"' : '' ?>>

<!-- ── Toolbar ──────────────────────────────────────────────────────────── -->
<div class="vt" id="vt">
    <a class="vt-back" href="<?= htmlspecialchars($backUrl) ?>" id="vt-back">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
        <span id="vt-back-label">Back</span>
    </a>
    <div class="vt-sep"></div>
    <div class="vt-fileinfo">
        <svg class="vt-fileicon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $fileIcon ?></svg>
        <span class="vt-name" title="<?= htmlspecialchars($displayName) ?>"><?= htmlspecialchars($displayName) ?></span>
    </div>
    <span class="vt-ext"><?= strtoupper(htmlspecialchars($ext)) ?></span>

    <div class="vt-spacer"></div>

    <div class="vt-pagenav" id="vt-pagenav" hidden>
        <button type="button" class="vt-icon-btn" id="vp-prev" title="Previous page (←)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
        </button>
        <span class="vt-pageinput"><input id="vp-input" type="text" inputmode="numeric" value="1" aria-label="Page number"> / <span id="vp-total">1</span></span>
        <button type="button" class="vt-icon-btn" id="vp-next" title="Next page (→)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
        </button>
    </div>

    <button type="button" class="vt-icon-btn" id="vt-sidebar-toggle" hidden title="Show pages &amp; outline">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18"/></svg>
    </button>

    <?php if ($engineKind !== 'none'): ?>
    <button type="button" class="vt-icon-btn" id="vt-search-toggle" title="Search in document (Ctrl+F)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
    </button>

    <div class="vt-zoom" id="vt-zoom">
        <button type="button" class="vt-icon-btn" id="vz-out" title="Zoom out (-)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/></svg>
        </button>
        <button type="button" class="vt-zpct" id="vz-pct" title="Zoom options">100%</button>
        <button type="button" class="vt-icon-btn" id="vz-in" title="Zoom in (+)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        </button>
        <div class="vt-zoom-menu" id="vt-zoom-menu" hidden>
            <button type="button" data-zoom="fit-width">Fit width</button>
            <button type="button" data-zoom="fit-page">Fit page</button>
            <hr>
            <button type="button" data-zoom="0.5">50%</button>
            <button type="button" data-zoom="0.75">75%</button>
            <button type="button" data-zoom="1">100%</button>
            <button type="button" data-zoom="1.25">125%</button>
            <button type="button" data-zoom="1.5">150%</button>
            <button type="button" data-zoom="2">200%</button>
        </div>
    </div>

    <?php if ($canDownload): ?>
    <button type="button" class="vt-icon-btn" id="vt-print" title="Print">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
    </button>
    <?php endif; ?>

    <button type="button" class="vt-icon-btn" id="vt-fullscreen" title="Full screen (F)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg>
    </button>
    <?php endif; ?>

    <?php if ($canDownload): ?>
    <a class="vt-dl" href="<?= htmlspecialchars($downloadUrl) ?>">
        <svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 12l-4-4h2.5V2h3v6H12L8 12z"/><path d="M2 13h12v1.5H2z"/></svg>
        Download
    </a>
    <?php endif; ?>
</div>

<!-- ── Search bar ───────────────────────────────────────────────────────── -->
<div class="vt-search" id="vt-search" hidden>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
    <input id="vs-input" type="text" placeholder="Search in document" autocomplete="off">
    <span class="vt-search-count" id="vs-count"></span>
    <button type="button" class="vt-icon-btn" id="vs-prev" title="Previous match">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M18 15l-6-6-6 6"/></svg>
    </button>
    <button type="button" class="vt-icon-btn" id="vs-next" title="Next match">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
    </button>
    <button type="button" class="vt-icon-btn" id="vs-close" title="Close search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
    </button>
</div>

<?php if ($isPresentation && $ext === 'pptx' && !$canConvertPresentation): ?>
<div class="vw-warning">Showing a text-only fallback — install LibreOffice on the server to preserve the full slide design.</div>
<?php endif; ?>

<!-- ── Content ─────────────────────────────────────────────────────────── -->
<div class="vc" id="vc">

    <?php if ($engineKind === 'pdf'): ?>
    <div class="vc-sidebar is-collapsed" id="vc-sidebar" hidden>
        <div class="vc-sidebar-tabs">
            <button type="button" class="vc-sidebar-tab is-active" data-tab="thumbs">Pages</button>
            <button type="button" class="vc-sidebar-tab" data-tab="outline" id="vc-outline-tab" hidden>Outline</button>
        </div>
        <div class="vc-sidebar-panel" data-panel="thumbs" id="vc-thumbs"></div>
        <div class="vc-sidebar-panel" data-panel="outline" id="vc-outline" hidden></div>
    </div>
    <?php endif; ?>

    <div class="vc-main" id="vc-main">
        <?php if ($engineKind === 'pdf'): ?>
            <div class="pdf-viewport" id="pdf-viewport">
                <div class="pdf-zoom-host" id="pdf-zoom-host">
                    <div class="pdf-zoom-wrap" id="pdf-zoom-wrap">
                        <div class="pdf-pages" id="pdf-pages">
                            <div class="vw-loading" id="pdf-loading"><div class="vw-spinner"></div><span>Loading document…</span></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php if ($isPresentation): ?>
            <div class="slide-bar" role="navigation" aria-label="Slide navigation">
                <button type="button" class="slide-arrow" id="slide-prev" title="Previous slide (←)" aria-label="Previous slide">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
                </button>
                <div class="slide-chip" id="slide-chip">Slide 1</div>
                <button type="button" class="slide-arrow" id="slide-next" title="Next slide (→)" aria-label="Next slide">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                </button>
            </div>
            <?php endif; ?>

        <?php elseif ($engineKind === 'docx'): ?>
            <div class="vc-scroll" id="vc-scroll">
                <div class="vc-zoom-host" id="vc-zoom-host">
                    <div class="vc-zoom-wrap" id="vc-zoom">
                        <div id="vc-body">
                            <div class="vw-loading"><div class="vw-spinner"></div>Loading document…</div>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($engineKind === 'xlsx'): ?>
            <div class="xlsx-wrap">
                <div class="xlsx-tabs" id="xlsx-tabs"></div>
                <div class="vc-scroll xlsx-body" id="vc-scroll" style="padding:0;">
                    <div class="vc-zoom-host" id="vc-zoom-host">
                        <div class="vc-zoom-wrap" id="vc-zoom">
                            <div id="vc-body">
                                <div class="vw-loading"><div class="vw-spinner"></div>Loading spreadsheet…</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($engineKind === 'pptx'): ?>
            <div class="pptx-wrap">
                <div class="pptx-thumbs" id="pp-thumbs"></div>
                <div class="pptx-stage">
                    <div class="pptx-viewport">
                        <div class="vc-zoom-host" id="vc-zoom-host">
                            <div class="vc-zoom-wrap" id="vc-zoom">
                                <div id="vc-body">
                                    <div class="vw-loading"><div class="vw-spinner"></div>Loading presentation…</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="slide-bar" role="navigation" aria-label="Slide navigation">
                        <button type="button" class="slide-arrow" id="pp-prev" title="Previous slide (←)" aria-label="Previous slide">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
                        </button>
                        <div class="slide-chip" id="pp-cnt">Loading…</div>
                        <button type="button" class="slide-arrow" id="pp-next" title="Next slide (→)" aria-label="Next slide">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                        </button>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="vc-scroll">
                <div class="vw-unsupported">
                    <h2>Preview not available</h2>
                    <p>This <strong>.<?= htmlspecialchars($ext) ?></strong> file type can't be previewed in the browser yet.</p>
                    <?php if ($canDownload): ?>
                        <a class="vw-btn" href="<?= htmlspecialchars($downloadUrl) ?>">
                            <svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 12l-4-4h2.5V2h3v6H12L8 12z"/><path d="M2 13h12v1.5H2z"/></svg>
                            Download to view it locally
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($engineKind === 'pdf'): ?>
<script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js"></script>
<?php endif; ?>
<?php if (in_array($engineKind, ['docx', 'xlsx', 'pptx'], true)): ?>
<script src="https://cdn.jsdelivr.net/npm/mammoth@1/mammoth.browser.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"></script>
<?php endif; ?>
<script>
(function () {
    'use strict';
    const FILE_URL   = <?= json_encode($fileUrl) ?>;
    const PDF_URL    = <?= json_encode($pdfSrcUrl) ?>;
    const KIND       = <?= json_encode($engineKind) ?>;
    const BACK_URL   = <?= json_encode($backUrl) ?>;
    const EMBEDDED   = window.self !== window.top || document.body.classList.contains('is-embedded');
    const IS_MOBILE  = () => window.matchMedia('(max-width: 860px)').matches;

    // ── Embedded-mode back button becomes "Close" and messages the parent ──
    const backBtn = document.getElementById('vt-back');
    const backLbl = document.getElementById('vt-back-label');
    if (EMBEDDED) {
        document.documentElement.classList.add('is-embedded');
        document.body.classList.add('is-embedded');
    }
    if (EMBEDDED && backBtn) {
        backLbl.textContent = 'Close';
        backBtn.setAttribute('aria-label', 'Close document viewer');
        backBtn.setAttribute('href', BACK_URL);
        backBtn.addEventListener('click', e => {
            e.preventDefault();
            requestClose();
        });
    }
    function requestClose() {
        try { window.parent.postMessage({ type: 'portal-doc-viewer-close' }, window.location.origin); } catch (e) {}
    }

    // ── Fullscreen ───────────────────────────────────────────────────────────
    const fsBtn = document.getElementById('vt-fullscreen');
    fsBtn?.addEventListener('click', toggleFullscreen);
    function toggleFullscreen() {
        const target = EMBEDDED ? document.documentElement : (document.getElementById('vc') || document.documentElement);
        if (!document.fullscreenElement) {
            (target.requestFullscreen ? target.requestFullscreen() : Promise.resolve()).catch(() => {});
        } else {
            document.exitFullscreen?.().catch(() => {});
        }
    }
    document.addEventListener('fullscreenchange', () => {
        fsBtn?.classList.toggle('is-active', !!document.fullscreenElement);
    });

    // ── Print ────────────────────────────────────────────────────────────────
    document.getElementById('vt-print')?.addEventListener('click', () => {
        if (KIND === 'pdf' && PDF_URL) {
            printPdf();
        } else {
            window.print();
        }
    });
    function printPdf() {
        let frame = document.getElementById('pdf-print-frame');
        if (!frame) {
            frame = document.createElement('iframe');
            frame.id = 'pdf-print-frame';
            frame.style.position = 'fixed';
            frame.style.width = '0';
            frame.style.height = '0';
            frame.style.border = '0';
            frame.style.right = '0';
            frame.style.bottom = '0';
            document.body.appendChild(frame);
        }
        frame.onload = () => {
            try { frame.contentWindow.focus(); frame.contentWindow.print(); }
            catch (e) { window.open(PDF_URL, '_blank'); }
        };
        frame.src = PDF_URL;
    }

    // ── Search bar (generic shell; each renderer wires its own logic) ────────
    const searchBar   = document.getElementById('vt-search');
    const searchInput = document.getElementById('vs-input');
    const searchCount = document.getElementById('vs-count');
    let docSearch = { run() {}, next() {}, prev() {}, clear() {} };

    function toggleSearch(forceState) {
        if (!searchBar || KIND === 'none') return;
        const open = typeof forceState === 'boolean' ? forceState : searchBar.hidden;
        searchBar.hidden = !open;
        document.getElementById('vt-search-toggle')?.classList.toggle('is-active', open);
        if (open) {
            searchInput.focus();
            searchInput.select();
            if (searchInput.value) docSearch.run(searchInput.value);
        } else {
            docSearch.clear();
            updateSearchCount(0, -1, '');
        }
    }
    function updateSearchCount(total, index, term) {
        if (!searchCount) return;
        if (!term) { searchCount.textContent = ''; return; }
        searchCount.textContent = total ? (index + 1) + ' of ' + total : 'No results';
    }
    window.__docViewerUpdateSearchCount = updateSearchCount;

    document.getElementById('vt-search-toggle')?.addEventListener('click', () => toggleSearch());
    document.getElementById('vs-close')?.addEventListener('click', () => toggleSearch(false));
    document.getElementById('vs-next')?.addEventListener('click', () => docSearch.next());
    document.getElementById('vs-prev')?.addEventListener('click', () => docSearch.prev());
    let searchDebounce;
    searchInput?.addEventListener('input', () => {
        clearTimeout(searchDebounce);
        searchDebounce = setTimeout(() => docSearch.run(searchInput.value), 180);
    });
    searchInput?.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); e.shiftKey ? docSearch.prev() : docSearch.next(); }
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); toggleSearch(false); }
    });

    // ── Zoom menu open/close ──────────────────────────────────────────────────
    const zoomMenu = document.getElementById('vt-zoom-menu');
    document.getElementById('vz-pct')?.addEventListener('click', e => {
        e.stopPropagation();
        zoomMenu.hidden = !zoomMenu.hidden;
    });
    document.addEventListener('click', e => {
        if (zoomMenu && !zoomMenu.hidden && !e.target.closest('#vt-zoom')) zoomMenu.hidden = true;
    });

    // ── Global keyboard shortcuts ─────────────────────────────────────────────
    let zoomIn = () => {}, zoomOut = () => {};
    window.__docViewerSetZoomHandlers = (inFn, outFn) => { zoomIn = inFn; zoomOut = outFn; };

    // +/- buttons and Ctrl+scroll are wired once here and simply call whichever
    // zoomIn/zoomOut the active renderer (below) has registered.
    document.getElementById('vz-in') ?.addEventListener('click', () => zoomIn());
    document.getElementById('vz-out')?.addEventListener('click', () => zoomOut());
    document.addEventListener('wheel', e => {
        if (e.ctrlKey) { e.preventDefault(); e.deltaY < 0 ? zoomIn() : zoomOut(); }
    }, { passive: false });
    let pageNext = () => {}, pagePrev = () => {}, pageFirst = () => {}, pageLast = () => {};
    window.__docViewerSetPageHandlers = (n, p, f, l) => { pageNext = n; pagePrev = p; pageFirst = f; pageLast = l; };

    document.addEventListener('keydown', e => {
        const inField = !!e.target.closest('input, textarea, [contenteditable="true"]');
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'f') { e.preventDefault(); toggleSearch(true); return; }
        if (!inField && e.key === '/') { e.preventDefault(); toggleSearch(true); return; }

        if (e.key === 'Escape') {
            if (searchBar && !searchBar.hidden) { toggleSearch(false); return; }
            if (document.fullscreenElement) { document.exitFullscreen(); return; }
            if (EMBEDDED) { requestClose(); return; }
        }

        if (inField) return;

        if (e.key === 'ArrowRight' || e.key === 'PageDown') { pageNext(); }
        if (e.key === 'ArrowLeft' || e.key === 'PageUp') { pagePrev(); }
        if (e.key === 'Home') { pageFirst(); }
        if (e.key === 'End') { pageLast(); }
        if (e.key === '+' || e.key === '=') { e.preventDefault(); zoomIn(); }
        if (e.key === '-') { e.preventDefault(); zoomOut(); }
        if (e.key.toLowerCase() === 'f' && !e.ctrlKey && !e.metaKey && !e.altKey) { toggleFullscreen(); }
    });

    // ── Sidebar (PDF only) ───────────────────────────────────────────────────
    const sidebar = document.getElementById('vc-sidebar');
    document.getElementById('vt-sidebar-toggle')?.addEventListener('click', () => {
        if (!sidebar) return;
        sidebar.hidden = false;
        const collapsed = sidebar.classList.toggle('is-collapsed');
        document.getElementById('vt-sidebar-toggle')?.classList.toggle('is-active', !collapsed);
    });
    sidebar?.querySelectorAll('.vc-sidebar-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            sidebar.querySelectorAll('.vc-sidebar-tab').forEach(t => t.classList.toggle('is-active', t === tab));
            sidebar.querySelectorAll('.vc-sidebar-panel').forEach(p => {
                p.hidden = p.dataset.panel !== tab.dataset.tab;
            });
        });
    });

    // ── Fetch helpers shared by office renderers ─────────────────────────────
    async function fetchBuf() {
        const r = await fetch(FILE_URL);
        if (!r.ok) { showErr(await r.text() || 'Could not load file.'); return null; }
        return r.arrayBuffer();
    }
    function showErr(msg) {
        const el = document.getElementById('vc-body');
        if (el) el.innerHTML = `<div class="vw-error">⚠ ${esc(msg)}</div>`;
    }
    function esc(s) {
        return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function escRe(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

    // ── Generic "highlight matches inside a rendered HTML container" search ─
    // Used by DOCX / XLSX / PPTX-text renderers (walks text nodes, wraps matches
    // in <mark class="doc-hl">, and can step forward/back through them).
    function makeHtmlSearch(getRoot, onJump) {
        let matches = [];
        let index = -1;
        let term = '';

        function clear() {
            (getRoot() ? [getRoot()] : []).forEach(root => {
                root.querySelectorAll('mark.doc-hl').forEach(m => {
                    const parent = m.parentNode;
                    if (!parent) return;
                    parent.replaceChild(document.createTextNode(m.textContent), m);
                    parent.normalize();
                });
            });
            matches = [];
            index = -1;
        }

        function run(q) {
            clear();
            term = (q || '').trim();
            const root = getRoot();
            if (!term || !root) { window.__docViewerUpdateSearchCount(0, -1, term); return; }
            const re = new RegExp(escRe(term), 'ig');
            const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
                acceptNode(node) {
                    if (!node.nodeValue || !node.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
                    const tag = node.parentElement && node.parentElement.tagName;
                    if (tag === 'SCRIPT' || tag === 'STYLE') return NodeFilter.FILTER_REJECT;
                    return NodeFilter.FILTER_ACCEPT;
                }
            });
            const textNodes = [];
            let n;
            while ((n = walker.nextNode())) textNodes.push(n);

            textNodes.forEach(node => {
                const text = node.nodeValue;
                if (!re.test(text)) { re.lastIndex = 0; return; }
                re.lastIndex = 0;
                const frag = document.createDocumentFragment();
                let last = 0, m;
                while ((m = re.exec(text))) {
                    if (m.index > last) frag.appendChild(document.createTextNode(text.slice(last, m.index)));
                    const mark = document.createElement('mark');
                    mark.className = 'doc-hl';
                    mark.textContent = m[0];
                    frag.appendChild(mark);
                    matches.push(mark);
                    last = m.index + m[0].length;
                    if (m[0].length === 0) re.lastIndex++;
                }
                if (last < text.length) frag.appendChild(document.createTextNode(text.slice(last)));
                node.parentNode.replaceChild(frag, node);
            });

            window.__docViewerUpdateSearchCount(matches.length, matches.length ? 0 : -1, term);
            if (matches.length) goTo(0);
        }

        function goTo(i) {
            if (!matches.length) return;
            if (index >= 0 && matches[index]) matches[index].classList.remove('doc-hl--active');
            index = ((i % matches.length) + matches.length) % matches.length;
            const mark = matches[index];
            mark.classList.add('doc-hl--active');
            mark.scrollIntoView({ behavior: 'smooth', block: 'center' });
            window.__docViewerUpdateSearchCount(matches.length, index, term);
            if (onJump) onJump(mark);
        }

        return {
            run,
            next() { goTo(index + 1); },
            prev() { goTo(index - 1); },
            clear,
        };
    }

    // ── DOCX (paginated Letter pages) ────────────────────────────────────────
    if (KIND === 'docx') {
        const zoomWrap = document.getElementById('vc-zoom');
        const zoomHost = document.getElementById('vc-zoom-host');
        const scrollEl = document.getElementById('vc-scroll');
        const pagesRoot = document.getElementById('vc-body');
        // On phones, prefer a continuous reading column: zoom grows text size and
        // reflows within the screen width, so reading stays vertical-only.
        const readingMode = IS_MOBILE();
        let zoom = 1.0;
        let fitMode = readingMode ? 'reading' : ((EMBEDDED && IS_MOBILE()) ? 'fit-width' : 'custom');
        let currentPage = 1;
        let pageCount = 1;
        let pageEls = [];
        let pageScrollObserver = null;

        if (readingMode) {
            document.body.classList.add('is-reading');
            document.documentElement.classList.add('is-reading');
        }

        function applyZoom() {
            if (readingMode) {
                zoomWrap.style.transform = 'none';
                zoomHost.style.width = '100%';
                zoomHost.style.height = 'auto';
                zoomWrap.style.width = '100%';
                document.documentElement.style.setProperty('--docx-zoom', String(zoom));
                syncZoomLabel(zoom);
                return;
            }
            syncScaledHost(zoomWrap, zoomHost, zoom);
            syncZoomLabel(zoom);
        }
        function setZoom(z) {
            zoom = Math.max(readingMode ? 0.85 : 0.35, Math.min(readingMode ? 2.2 : 3, z));
            if (!readingMode) fitMode = 'custom';
            applyZoom();
        }
        function fitWidth() {
            if (readingMode) {
                fitMode = 'reading';
                setZoom(1);
                return;
            }
            fitMode = 'fit-width';
            officeFitWidth(zoomWrap, zoomHost, scrollEl, z => {
                zoom = z;
                applyZoom();
            });
        }
        window.__docViewerSetZoomHandlers(
            () => setZoom(zoom + (readingMode ? 0.15 : 0.1)),
            () => setZoom(zoom - (readingMode ? 0.15 : 0.1))
        );
        wireZoomMenuGeneric(
            z => setZoom(z),
            fitWidth,
            fitWidth
        );

        function setCurrentPage(n) {
            if (!n || n < 1 || n > pageCount) return;
            currentPage = n;
            const input = document.getElementById('vp-input');
            if (input && document.activeElement !== input) input.value = String(currentPage);
            const prev = document.getElementById('vp-prev');
            const next = document.getElementById('vp-next');
            if (prev) prev.disabled = currentPage <= 1;
            if (next) next.disabled = currentPage >= pageCount;
        }

        function scrollToPage(n) {
            n = Math.max(1, Math.min(pageCount, n));
            const el = pageEls[n - 1];
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            setCurrentPage(n);
        }

        function observePageScroll() {
            if (pageScrollObserver) pageScrollObserver.disconnect();
            if (readingMode || !('IntersectionObserver' in window) || !scrollEl) return;
            pageScrollObserver = new IntersectionObserver(entries => {
                entries.forEach(entry => {
                    if (entry.isIntersecting && entry.intersectionRatio > 0.35) {
                        const idx = pageEls.indexOf(entry.target);
                        if (idx >= 0) setCurrentPage(idx + 1);
                    }
                });
            }, { root: scrollEl, threshold: [0.35] });
            pageEls.forEach(el => pageScrollObserver.observe(el));
        }

        document.getElementById('vp-input')?.addEventListener('change', e => {
            const n = parseInt(e.target.value, 10);
            if (!isNaN(n)) scrollToPage(n);
        });
        document.getElementById('vp-prev')?.addEventListener('click', () => scrollToPage(currentPage - 1));
        document.getElementById('vp-next')?.addEventListener('click', () => scrollToPage(currentPage + 1));
        window.__docViewerSetPageHandlers(
            () => scrollToPage(currentPage + 1),
            () => scrollToPage(currentPage - 1),
            () => scrollToPage(1),
            () => scrollToPage(pageCount)
        );

        docSearch = makeHtmlSearch(
            () => pagesRoot,
            mark => {
                const page = mark.closest('.pagedjs_page, .docx-page');
                const idx = page ? pageEls.indexOf(page) : -1;
                if (idx >= 0) setCurrentPage(idx + 1);
            }
        );

        function finishPagination(count) {
            pageCount = Math.max(1, count);
            const totalEl = document.getElementById('vp-total');
            if (totalEl) totalEl.textContent = String(pageCount);
            const nav = document.getElementById('vt-pagenav');
            if (nav) nav.hidden = readingMode || pageCount <= 1;
            setCurrentPage(1);
            observePageScroll();
            if (readingMode) {
                applyZoom();
            } else if (fitMode === 'fit-width') {
                requestAnimationFrame(() => requestAnimationFrame(fitWidth));
            } else {
                applyZoom();
            }
        }

        const refit = debounce(() => {
            if (readingMode) applyZoom();
            else if (fitMode === 'fit-width') fitWidth();
        }, 180);
        window.addEventListener('resize', refit);
        window.visualViewport?.addEventListener('resize', refit);

        function renderReading(html) {
            const emptyMsg = '<p style="color:#999"><em>This document appears to be empty.</em></p>';
            pagesRoot.innerHTML = `<div class="docx-page docx-page--fluid">${(html && String(html).trim()) ? html : emptyMsg}</div>`;
            pageEls = Array.from(pagesRoot.querySelectorAll('.docx-page'));
            return pageEls.length || 1;
        }

        // Real, Word-like pagination (splitting text/tables/lists across page
        // boundaries, not just whole blocks) needs an actual layout engine —
        // Paged.js implements the CSS Paged Media spec for exactly this.
        async function paginateWithPagedJs(html) {
            const css = `
                @page {
                    size: 816px 1056px;
                    margin: 72px 88px 56px 88px;
                    @bottom-center {
                        content: counter(page);
                        font-family: "Manrope", system-ui, sans-serif;
                        font-size: 11px;
                        font-weight: 600;
                        color: #9a9a96;
                    }
                }
                html, body { margin: 0; padding: 0; }
                body {
                    font-family: "Calibri", "Georgia", serif;
                    font-size: 12pt; line-height: 1.65; color: #1a1a1a;
                }
                h1 { font-size: 2em; margin: 1em 0 .5em; }
                h2 { font-size: 1.5em; margin: .9em 0 .4em; }
                h3 { font-size: 1.2em; margin: .7em 0 .3em; }
                p { margin: .5em 0; orphans: 2; widows: 2; }
                table { border-collapse: collapse; width: 100%; margin: 1em 0; }
                tr { break-inside: avoid; }
                td, th { border: 1px solid #ccc; padding: 6px 10px; }
                img { max-width: 100%; height: auto; }
                ul, ol { padding-left: 2em; margin: .5em 0; }
                li { break-inside: avoid; }
                mark.doc-hl { background: rgba(253, 224, 71, 0.65); border-radius: 2px; }
                mark.doc-hl--active { background: rgba(253, 176, 45, 0.9); }
            `;
            const cssUrl = URL.createObjectURL(new Blob([css], { type: 'text/css' }));
            try {
                const { Previewer } = await import('https://cdn.jsdelivr.net/npm/pagedjs@0.4.3/dist/paged.esm.js');
                // Leave the loading spinner in place until pages are ready — the
                // Previewer appends its own .pagedjs_pages node alongside it.
                const flow = await new Previewer().preview(html, [cssUrl], pagesRoot);
                pagesRoot.querySelectorAll(':scope > .vw-loading').forEach(el => el.remove());
                pageEls = Array.from(pagesRoot.querySelectorAll('.pagedjs_page'));
                return flow.total || pageEls.length || 1;
            } finally {
                URL.revokeObjectURL(cssUrl);
            }
        }

        // Fallback if the pagination engine can't load (offline/CDN blocked):
        // a single continuous page, at least showing the full document.
        function paginateFallback(html) {
            return renderReading(html);
        }

        (async () => {
            const buf = await fetchBuf(); if (!buf) return;
            const res = await mammoth.convertToHtml({ arrayBuffer: buf });
            const html = (res.value && res.value.trim())
                ? res.value
                : '<p style="color:#999"><em>This document appears to be empty.</em></p>';
            let count;
            if (readingMode) {
                count = renderReading(html);
            } else {
                try {
                    count = await paginateWithPagedJs(html);
                } catch (err) {
                    console.error('Pagination engine failed, showing continuous view.', err);
                    count = paginateFallback(html);
                }
            }
            finishPagination(count);
        })().catch(e => showErr(e.message));
    }

    // ── XLSX ─────────────────────────────────────────────────────────────────
    if (KIND === 'xlsx') {
        const zoomWrap = document.getElementById('vc-zoom');
        const zoomHost = document.getElementById('vc-zoom-host');
        const scrollEl = document.getElementById('vc-scroll');
        let zoom = 1.0;
        let fitMode = 'custom';
        function applyZoom() { syncScaledHost(zoomWrap, zoomHost, zoom); syncZoomLabel(zoom); }
        function setZoom(z) { zoom = Math.max(0.35, Math.min(3, z)); applyZoom(); }
        function fitWidth() {
            fitMode = 'fit-width';
            officeFitWidth(zoomWrap, zoomHost, scrollEl, setZoom);
        }
        window.__docViewerSetZoomHandlers(
            () => { fitMode = 'custom'; setZoom(zoom + 0.1); },
            () => { fitMode = 'custom'; setZoom(zoom - 0.1); }
        );
        wireZoomMenuGeneric(
            z => { fitMode = 'custom'; setZoom(z); },
            fitWidth,
            fitWidth
        );

        docSearch = makeHtmlSearch(() => document.getElementById('vc-body'));

        (async () => {
            const buf = await fetchBuf(); if (!buf) return;
            const wb  = XLSX.read(buf, { type: 'array' });
            const tabsEl = document.getElementById('xlsx-tabs');
            const bodyEl = document.getElementById('vc-body');

            const sheets = wb.SheetNames.map(name => ({ name, sheet: wb.Sheets[name] }));
            if (!sheets.length) { showErr('No sheets found.'); return; }

            function renderSheet(s) {
                if (!s.sheet) return '<p style="padding:16px;color:#999">Empty sheet.</p>';
                const rows = XLSX.utils.sheet_to_json(s.sheet, { header: 1, defval: '' });
                if (!rows.length) return '<p style="padding:16px;color:#999">This sheet is empty.</p>';
                const maxC = rows.reduce((m, r) => Math.max(m, r.length), 0);
                const hdr  = '<tr><th></th>' + Array.from({length: maxC}, (_, i) => `<th>${String.fromCharCode(65 + (i % 26))}</th>`).join('') + '</tr>';
                const bdy  = rows.map((row, ri) =>
                    '<tr><td class="xlsx-rn">' + (ri + 1) + '</td>' +
                    Array.from({length: maxC}, (_, ci) => `<td>${esc(row[ci])}</td>`).join('') + '</tr>'
                ).join('');
                return `<table class="xlsx-tbl"><thead>${hdr}</thead><tbody>${bdy}</tbody></table>`;
            }

            tabsEl.innerHTML = sheets.map((s, i) =>
                `<div class="xlsx-tab${i === 0 ? ' active' : ''}" data-i="${i}">${esc(s.name)}</div>`
            ).join('');
            bodyEl.innerHTML = renderSheet(sheets[0]);
            if (EMBEDDED && IS_MOBILE()) fitWidth();
            else applyZoom();

            tabsEl.addEventListener('click', e => {
                const t = e.target.closest('.xlsx-tab[data-i]');
                if (!t) return;
                tabsEl.querySelectorAll('.xlsx-tab').forEach(x => x.classList.remove('active'));
                t.classList.add('active');
                bodyEl.innerHTML = renderSheet(sheets[+t.dataset.i]);
                if (fitMode === 'fit-width') fitWidth();
                else applyZoom();
                if (searchInput && searchInput.value) docSearch.run(searchInput.value);
            });
        })().catch(e => showErr(e.message));
    }

    // ── PPTX (text fallback) ─────────────────────────────────────────────────
    if (KIND === 'pptx') {
        let slides = [], cur = 0, accent = '#1e3a5f';
        const zoomWrap = document.getElementById('vc-zoom');
        const zoomHost = document.getElementById('vc-zoom-host');
        const scrollEl = document.querySelector('.pptx-viewport') || document.getElementById('vc-scroll');
        let zoom = 1.0;
        let fitMode = (EMBEDDED && IS_MOBILE()) ? 'fit-width' : 'custom';
        function applyZoom() { syncScaledHost(zoomWrap, zoomHost, zoom); syncZoomLabel(zoom); }
        function setZoom(z) { zoom = Math.max(0.35, Math.min(3, z)); applyZoom(); }
        function fitWidth() {
            fitMode = 'fit-width';
            officeFitWidth(zoomWrap, zoomHost, scrollEl, setZoom);
        }
        window.__docViewerSetZoomHandlers(
            () => { fitMode = 'custom'; setZoom(zoom + 0.1); },
            () => { fitMode = 'custom'; setZoom(zoom - 0.1); }
        );
        wireZoomMenuGeneric(
            z => { fitMode = 'custom'; setZoom(z); },
            fitWidth,
            fitWidth
        );

        docSearch = makeHtmlSearch(() => document.getElementById('vc-body'), mark => {
            const slideEl = mark.closest('.pptx-slide[data-i]');
            if (slideEl) go(+slideEl.dataset.i);
        });

        (async () => {
            const buf = await fetchBuf(); if (!buf) return;
            const zip = await JSZip.loadAsync(buf);
            accent = await themeAccent(zip);
            slides = await extractSlides(zip);

            const bodyEl   = document.getElementById('vc-body');
            const thumbsEl = document.getElementById('pp-thumbs');
            const cntEl    = document.getElementById('pp-cnt');
            const prevBtn  = document.getElementById('pp-prev');
            const nextBtn  = document.getElementById('pp-next');
            window.__docViewerSetPageHandlers(() => go(cur + 1), () => go(cur - 1), () => go(0), () => go(slides.length - 1));

            if (!slides.length) {
                bodyEl.innerHTML = `<div class="pptx-slide active" style="--sa:${accent};align-items:center;justify-content:center;"><p style="color:#bbb">This presentation has no slides.</p></div>`;
                cntEl.textContent = '0 slides';
                return;
            }

            bodyEl.innerHTML   = slides.map((s, i) => slideHtml(s, i, accent)).join('');
            thumbsEl.innerHTML = slides.map((s, i) =>
                `<div class="pptx-thumb${i === 0 ? ' active' : ''}" data-i="${i}">
                    <div class="pptx-thumb-n">Slide ${i + 1}</div>
                    ${s.title ? `<div class="pptx-thumb-t">${esc(s.title.slice(0, 28))}</div>` : ''}
                </div>`
            ).join('');

            function go(n) {
                cur = Math.max(0, Math.min(slides.length - 1, n));
                bodyEl.querySelectorAll('.pptx-slide').forEach((el, i) => el.classList.toggle('active', i === cur));
                thumbsEl.querySelectorAll('.pptx-thumb').forEach((el, i) => el.classList.toggle('active', i === cur));
                cntEl.textContent = `Slide ${cur + 1} of ${slides.length}`;
                prevBtn.disabled = cur === 0;
                nextBtn.disabled = cur === slides.length - 1;
                thumbsEl.querySelectorAll('.pptx-thumb')[cur]?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
                if (fitMode === 'fit-width') fitWidth();
                else applyZoom();
            }

            prevBtn.addEventListener('click',  () => go(cur - 1));
            nextBtn.addEventListener('click',  () => go(cur + 1));
            thumbsEl.addEventListener('click', e => {
                const t = e.target.closest('.pptx-thumb[data-i]');
                if (t) go(+t.dataset.i);
            });
            go(0);
        })().catch(e => showErr(e.message));

        function slideHtml(s, i, ac) {
            const style = `style="--sa:${ac}"`;
            const body  = s.body.length
                ? '<ul>' + s.body.map(l => `<li>${esc(l)}</li>`).join('') + '</ul>'
                : '';
            const emptyBody = '<p style="color:#bbb;font-style:italic;margin-top:8px">No text content on this slide</p>';

            if (s.title) {
                return `<div class="pptx-slide" data-i="${i}" ${style}>
                    <div class="pptx-slide-hdr"><div class="pptx-slide-title">${esc(s.title)}</div></div>
                    <div class="pptx-slide-body-wrap"><div class="pptx-slide-body">${body || emptyBody}</div></div>
                    <div class="pptx-slide-n">${i + 1}</div>
                </div>`;
            } else {
                return `<div class="pptx-slide" data-i="${i}" ${style}>
                    <div class="pptx-slide-bar"></div>
                    <div class="pptx-slide-body-wrap" style="justify-content:center;">
                        <div class="pptx-slide-body">${body || emptyBody}</div>
                    </div>
                    <div class="pptx-slide-n">${i + 1}</div>
                </div>`;
            }
        }

        async function themeAccent(zip) {
            try {
                const tf = zip.file('ppt/theme/theme1.xml');
                if (!tf) return '#1e3a5f';
                const xml = await tf.async('text');
                const m = xml.match(/<a:accent1\b[^>]*>\s*<a:srgbClr\s+val="([0-9A-Fa-f]{6})"/);
                if (m) return '#' + m[1];
                const m2 = xml.match(/<a:dk2\b[^>]*>\s*<a:srgbClr\s+val="([0-9A-Fa-f]{6})"/);
                if (m2) return '#' + m2[1];
            } catch (_) {}
            return '#1e3a5f';
        }

        async function extractSlides(zip) {
            const relsFile = zip.file('ppt/_rels/presentation.xml.rels');
            if (!relsFile) return [];
            const relsDoc = new DOMParser().parseFromString(await relsFile.async('text'), 'application/xml');
            const relMap  = new Map(Array.from(relsDoc.getElementsByTagName('Relationship'))
                .map(n => [n.getAttribute('Id'), n.getAttribute('Target') || '']));

            const presFile = zip.file('ppt/presentation.xml');
            if (!presFile) return [];
            const presDoc = new DOMParser().parseFromString(await presFile.async('text'), 'application/xml');
            const relIds  = Array.from(presDoc.getElementsByTagName('p:sldId'))
                .map(n => n.getAttribute('r:id')).filter(Boolean);

            const out = [];
            for (const id of relIds) {
                const target = relMap.get(id); if (!target) continue;
                const clean  = target.replace(/^\//, '').replace(/^\.\.\//, '');
                const path   = clean.startsWith('ppt/') ? clean : 'ppt/' + clean;
                const file   = zip.file(path); if (!file) continue;
                const doc    = new DOMParser().parseFromString(await file.async('text'), 'application/xml');
                const shapes = Array.from(doc.getElementsByTagName('p:sp'));

                let title = '';
                for (const sp of shapes) {
                    const ph = sp.getElementsByTagName('p:ph')[0];
                    if (ph && (ph.getAttribute('type') === 'title' || ph.getAttribute('type') === 'ctrTitle')) {
                        title = Array.from(sp.getElementsByTagName('a:t')).map(t => t.textContent).join(' ').trim();
                        break;
                    }
                }
                const body = [];
                for (const sp of shapes) {
                    const ph = sp.getElementsByTagName('p:ph')[0];
                    if (ph && (ph.getAttribute('type') === 'title' || ph.getAttribute('type') === 'ctrTitle')) continue;
                    Array.from(sp.getElementsByTagName('a:t')).forEach(t => {
                        const txt = t.textContent.trim();
                        if (txt) body.push(txt);
                    });
                }
                out.push({ title, body });
            }
            return out;
        }
    }

    // ── Shared: zoom % label + layout compensation for CSS scale() ───────────
    // transform:scale() does not shrink the layout box, so without a host that
    // clips to the visual size you get huge empty scroll areas below the content.
    function syncZoomLabel(z) {
        const pct = document.getElementById('vz-pct');
        if (pct) pct.textContent = Math.round(z * 100) + '%';
    }
    function syncScaledHost(wrap, host, scale) {
        if (!wrap || !host) return;
        host._syncScale = scale;
        wrap.style.transform = `scale(${scale})`;
        const apply = () => {
            const s = host._syncScale;
            // ResizeObserver reports wrap's true (unscaled) layout box, so this
            // stays correct even if content reflows later (fonts/images/search).
            host.style.width = Math.max(1, Math.ceil(wrap.offsetWidth * s)) + 'px';
            host.style.height = Math.max(1, Math.ceil(wrap.offsetHeight * s)) + 'px';
        };
        apply();
        // Keep the host size in sync as the (unscaled) content's real size changes,
        // e.g. late-loading fonts/images or search-highlight markup reflowing text.
        if (!host._syncObserver && 'ResizeObserver' in window) {
            host._syncObserver = new ResizeObserver(apply);
            host._syncObserver.observe(wrap);
        }
    }
    function officeFitWidth(wrap, host, scrollEl, setZoom) {
        if (!wrap || !host || !scrollEl || typeof setZoom !== 'function') return;
        // Measure the unscaled page width, then scale to the visible viewport.
        host.style.width = '';
        host.style.height = '';
        wrap.style.transform = 'none';
        const page = wrap.querySelector('.pagedjs_page, .docx-page, .pptx-slide, table.xlsx-tbl, .pptx-card') || wrap;
        const natural = Math.max(page.offsetWidth || 0, page.getBoundingClientRect().width || 0, 1);
        const avail = Math.max(1, scrollEl.clientWidth - (IS_MOBILE() ? 4 : 32));
        setZoom(Math.min(1.5, Math.max(0.35, avail / natural)));
    }
    function wireZoomMenuGeneric(apply, fitWidthFn, fitPageFn) {
        document.querySelectorAll('#vt-zoom-menu button[data-zoom]').forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('vt-zoom-menu').hidden = true;
                const val = btn.dataset.zoom;
                if (val === 'fit-width') {
                    if (typeof fitWidthFn === 'function') fitWidthFn();
                    else apply(1);
                } else if (val === 'fit-page') {
                    if (typeof fitPageFn === 'function') fitPageFn();
                    else if (typeof fitWidthFn === 'function') fitWidthFn();
                    else apply(1);
                } else {
                    apply(parseFloat(val));
                }
            });
        });
    }

    // ══════════════════════════════════════════════════════════════════════
    // PDF engine (pdf.js) — pages, thumbnails, outline, search, zoom/fit
    // ══════════════════════════════════════════════════════════════════════
    if (KIND === 'pdf') {
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js';

        const RENDER_SCALE = Math.min(3, Math.max(1.5, (window.devicePixelRatio || 1) * 1.5));
        const LAZY_BUFFER = 2; // pages ahead/behind the visible one to keep rendered
        const KEEP_DISTANCE = 6; // unload canvases farther than this from current page
        const zoomHost  = document.getElementById('pdf-zoom-host');
        const zoomWrap  = document.getElementById('pdf-zoom-wrap');
        const pagesEl   = document.getElementById('pdf-pages');
        const viewportEl = document.getElementById('pdf-viewport');

        let pdfDoc = null;
        let pageCount = 0;
        let pages = []; // { wrap, canvas, textLayer, page, textContent, state, renderTask, renderPromise }
        let viewScale = 1;
        let fitMode = 'fit-width';
        let currentPage = 1;
        let searchMatches = [];
        let searchIndex = -1;
        let searchGen = 0;
        let renderObserver = null;
        let pageScrollObserver = null;

        window.__docViewerSetPageHandlers(
            () => scrollToPage(currentPage + 1),
            () => scrollToPage(currentPage - 1),
            () => scrollToPage(1),
            () => scrollToPage(pageCount)
        );
        window.__docViewerSetZoomHandlers(() => zoomBy(0.1), () => zoomBy(-0.1));
        docSearch = { run: runSearch, next: () => goToMatch(searchIndex + 1), prev: () => goToMatch(searchIndex - 1), clear: clearSearchHighlights };
        document.querySelectorAll('#vt-zoom-menu button[data-zoom]').forEach(btn => {
            btn.addEventListener('click', () => {
                const val = btn.dataset.zoom;
                if (val === 'fit-width') { fitMode = 'fit-width'; applyFit(); }
                else if (val === 'fit-page') { fitMode = 'fit-page'; applyFit(); }
                else { fitMode = 'custom'; setViewScale(parseFloat(val)); }
            });
        });

        const refitViewport = debounce(() => {
            if (fitMode === 'fit-width' || fitMode === 'fit-page') applyFit();
        }, 200);
        window.addEventListener('resize', refitViewport);
        window.visualViewport?.addEventListener('resize', refitViewport);

        init();

        async function init() {
            try {
                pdfDoc = await pdfjsLib.getDocument({ url: PDF_URL, withCredentials: true }).promise;
            } catch (e) {
                pagesEl.innerHTML = `<div class="vw-error">⚠ Could not load this document. ${esc(e && e.message || '')}</div>`;
                return;
            }
            pageCount = pdfDoc.numPages;
            document.getElementById('vp-total').textContent = String(pageCount);
            document.getElementById('vt-pagenav').hidden = false;
            document.getElementById('vt-sidebar-toggle').hidden = false;

            const firstPage = await pdfDoc.getPage(1);
            const firstVp = firstPage.getViewport({ scale: RENDER_SCALE });
            pages = [];
            pagesEl.innerHTML = '';

            for (let i = 1; i <= pageCount; i++) {
                const wrap = document.createElement('div');
                wrap.className = 'pdf-page is-placeholder';
                wrap.dataset.pageNumber = String(i);
                wrap.style.width = Math.floor(firstVp.width) + 'px';
                wrap.style.height = Math.floor(firstVp.height) + 'px';
                const canvas = document.createElement('canvas');
                canvas.className = 'pdf-canvas';
                const textLayer = document.createElement('div');
                textLayer.className = 'textLayer';
                wrap.appendChild(canvas);
                wrap.appendChild(textLayer);
                pagesEl.appendChild(wrap);
                pages.push({
                    wrap, canvas, textLayer,
                    page: i === 1 ? firstPage : null,
                    textContent: null,
                    state: 'idle', // idle | rendering | ready
                    renderTask: null,
                    renderPromise: null,
                });
            }

            fitMode = 'fit-width';
            applyFit();
            observeVisibility();
            observePageScroll();
            // Kick off the first page (+buffer) immediately so the spinner isn't left hanging.
            await ensureRenderedRange(1, LAZY_BUFFER);
            setCurrentPage(1);
            buildThumbnailsLazy();
            buildOutline();
        }

        function observeVisibility() {
            if (!('IntersectionObserver' in window)) {
                // Fallback: render everything if IO is unavailable.
                for (let i = 0; i < pageCount; i++) ensureRendered(i);
                return;
            }
            renderObserver = new IntersectionObserver(entries => {
                entries.forEach(entry => {
                    if (!entry.isIntersecting) return;
                    const n = parseInt(entry.target.dataset.pageNumber, 10);
                    if (!isNaN(n)) ensureRenderedRange(n, LAZY_BUFFER);
                });
            }, {
                root: viewportEl,
                // Prefetch pages roughly one viewport ahead/behind.
                rootMargin: '120% 0px',
                threshold: 0.01,
            });
            pages.forEach(rec => renderObserver.observe(rec.wrap));
        }

        function ensureRenderedRange(centerPage, buffer) {
            const start = Math.max(1, centerPage - buffer);
            const end = Math.min(pageCount, centerPage + buffer);
            const jobs = [];
            for (let n = start; n <= end; n++) jobs.push(ensureRendered(n - 1));
            unloadFarPages(centerPage);
            return Promise.all(jobs);
        }

        function ensureRendered(i) {
            const rec = pages[i];
            if (!rec) return Promise.resolve();
            if (rec.state === 'ready') return Promise.resolve();
            if (rec.renderPromise) return rec.renderPromise;
            rec.renderPromise = renderPage(i).finally(() => { rec.renderPromise = null; });
            return rec.renderPromise;
        }

        async function renderPage(i) {
            const rec = pages[i];
            if (!rec || rec.state === 'ready') return;
            rec.state = 'rendering';
            try {
                const page = rec.page || (rec.page = await pdfDoc.getPage(i + 1));
                const viewport = page.getViewport({ scale: RENDER_SCALE });
                rec.canvas.width = Math.floor(viewport.width);
                rec.canvas.height = Math.floor(viewport.height);
                rec.wrap.style.width = Math.floor(viewport.width) + 'px';
                rec.wrap.style.height = Math.floor(viewport.height) + 'px';

                if (rec.renderTask) {
                    try { rec.renderTask.cancel(); } catch (e) {}
                    rec.renderTask = null;
                }
                const task = page.render({ canvasContext: rec.canvas.getContext('2d'), viewport });
                rec.renderTask = task;
                await task.promise;
                rec.renderTask = null;

                rec.textLayer.innerHTML = '';
                rec.textLayer.style.width = Math.floor(viewport.width) + 'px';
                rec.textLayer.style.height = Math.floor(viewport.height) + 'px';
                if (!rec.textContent) rec.textContent = await page.getTextContent();
                try {
                    await pdfjsLib.renderTextLayer({
                        textContentSource: rec.textContent,
                        container: rec.textLayer,
                        viewport,
                    }).promise;
                } catch (e) { /* text layer is a progressive enhancement */ }

                rec.wrap.classList.remove('is-placeholder');
                rec.state = 'ready';
                // Page sizes can differ — keep the scroll host clipped to the visual scale.
                syncScaledHost(zoomWrap, zoomHost, viewScale / RENDER_SCALE);
            } catch (e) {
                // Cancelled renders (from unload/scroll) are expected — leave as idle so they retry.
                if (e && e.name === 'RenderingCancelledException') {
                    rec.state = 'idle';
                    return;
                }
                rec.state = 'idle';
                console.warn('PDF page render failed', i + 1, e);
            }
        }

        function unloadFarPages(centerPage) {
            pages.forEach((rec, i) => {
                const pageNum = i + 1;
                if (Math.abs(pageNum - centerPage) <= KEEP_DISTANCE) return;
                if (rec.state === 'idle') return;
                if (rec.renderTask) {
                    try { rec.renderTask.cancel(); } catch (e) {}
                    rec.renderTask = null;
                }
                // Drop the heavy canvas bitmap but keep the sized shell for scroll layout.
                const ctx = rec.canvas.getContext('2d');
                ctx && ctx.clearRect(0, 0, rec.canvas.width, rec.canvas.height);
                rec.canvas.width = 0;
                rec.canvas.height = 0;
                rec.textLayer.innerHTML = '';
                rec.wrap.classList.add('is-placeholder');
                rec.state = 'idle';
                rec.renderPromise = null;
            });
        }

        function naturalSize(page) {
            const vp = page.getViewport({ scale: 1 });
            return { w: vp.width, h: vp.height };
        }

        function applyFit() {
            if (!pages.length || !pages[0].page) return;
            const nat = naturalSize(pages[0].page);
            const availW = viewportEl.clientWidth - 56;
            const availH = viewportEl.clientHeight - 56;
            let scale;
            if (fitMode === 'fit-page') scale = Math.min(availW / nat.w, availH / nat.h);
            else scale = availW / nat.w;
            setViewScale(scale, true);
        }

        function setViewScale(scale) {
            viewScale = Math.max(0.15, Math.min(4, scale));
            syncScaledHost(zoomWrap, zoomHost, viewScale / RENDER_SCALE);
            syncZoomLabel(viewScale);
        }

        function zoomBy(delta) {
            fitMode = 'custom';
            setViewScale(viewScale + delta);
        }

        async function scrollToPage(n) {
            n = Math.max(1, Math.min(pageCount, n));
            await ensureRenderedRange(n, LAZY_BUFFER);
            pages[n - 1]?.wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
            setCurrentPage(n);
        }

        function observePageScroll() {
            if (!('IntersectionObserver' in window)) return;
            pageScrollObserver = new IntersectionObserver(entries => {
                entries.forEach(entry => {
                    if (entry.isIntersecting && entry.intersectionRatio > 0.5) {
                        setCurrentPage(parseInt(entry.target.dataset.pageNumber, 10));
                    }
                });
            }, { root: viewportEl, threshold: [0.5] });
            pages.forEach(rec => pageScrollObserver.observe(rec.wrap));
        }

        function setCurrentPage(n) {
            if (!n) return;
            const changed = n !== currentPage;
            currentPage = n;
            if (changed) ensureRenderedRange(n, LAZY_BUFFER);
            const input = document.getElementById('vp-input');
            if (input && document.activeElement !== input) input.value = String(currentPage);
            document.querySelectorAll('.pdf-thumb').forEach(t => t.classList.toggle('is-active', parseInt(t.dataset.page, 10) === currentPage));
            const chip = document.getElementById('slide-chip');
            if (chip) chip.textContent = 'Slide ' + currentPage + ' of ' + pageCount;
            const sPrev = document.getElementById('slide-prev');
            const sNext = document.getElementById('slide-next');
            if (sPrev) sPrev.disabled = currentPage <= 1;
            if (sNext) sNext.disabled = currentPage >= pageCount;
        }

        document.getElementById('vp-input')?.addEventListener('change', e => {
            const n = parseInt(e.target.value, 10);
            if (!isNaN(n)) scrollToPage(n);
        });
        document.getElementById('vp-prev')?.addEventListener('click', () => scrollToPage(currentPage - 1));
        document.getElementById('vp-next')?.addEventListener('click', () => scrollToPage(currentPage + 1));
        document.getElementById('slide-prev')?.addEventListener('click', () => scrollToPage(currentPage - 1));
        document.getElementById('slide-next')?.addEventListener('click', () => scrollToPage(currentPage + 1));

        async function buildThumbnailsLazy() {
            const thumbsEl = document.getElementById('vc-thumbs');
            if (!thumbsEl) return;
            thumbsEl.innerHTML = '';
            for (let i = 1; i <= pageCount; i++) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'pdf-thumb' + (i === 1 ? ' is-active' : '');
                btn.dataset.page = String(i);
                btn.innerHTML = '<span class="pdf-thumb-canvas-wrap"><canvas></canvas></span><span class="pdf-thumb-n">' + i + '</span>';
                thumbsEl.appendChild(btn);
            }
            thumbsEl.addEventListener('click', e => {
                const btn = e.target.closest('.pdf-thumb[data-page]');
                if (btn) scrollToPage(parseInt(btn.dataset.page, 10));
            });
            // Render thumbnails in the background without blocking the main view.
            for (let i = 0; i < pageCount; i++) {
                try {
                    const page = pages[i].page || (pages[i].page = await pdfDoc.getPage(i + 1));
                    const canvas = thumbsEl.children[i]?.querySelector('canvas');
                    if (!canvas) continue;
                    const nat = naturalSize(page);
                    const thumbScale = 148 / nat.w;
                    const vp = page.getViewport({ scale: thumbScale });
                    canvas.width = vp.width; canvas.height = vp.height;
                    await page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
                } catch (e) {}
                await new Promise(r => setTimeout(r, 0));
            }
        }

        async function buildOutline() {
            let outline = null;
            try { outline = await pdfDoc.getOutline(); } catch (e) {}
            const tab = document.getElementById('vc-outline-tab');
            const panel = document.getElementById('vc-outline');
            if (!outline || !outline.length) { if (tab) tab.hidden = true; return; }
            if (tab) tab.hidden = false;
            panel.innerHTML = await renderOutlineList(outline, 0);
            panel.addEventListener('click', e => {
                const item = e.target.closest('.pdf-outline-item[data-page-index]');
                if (!item) return;
                const idx = parseInt(item.dataset.pageIndex, 10);
                if (!isNaN(idx)) scrollToPage(idx + 1);
            });
        }

        async function renderOutlineList(items, depth) {
            let html = '<ul class="pdf-outline-list">';
            for (const item of items) {
                let pageIndex = null;
                try {
                    let dest = item.dest;
                    if (typeof dest === 'string') dest = await pdfDoc.getDestination(dest);
                    if (Array.isArray(dest) && dest.length) pageIndex = await pdfDoc.getPageIndex(dest[0]);
                } catch (e) {}
                html += '<li><button type="button" class="pdf-outline-item" data-page-index="' + (pageIndex === null ? '' : pageIndex) + '" style="padding-left:' + (10 + depth * 16) + 'px">' + esc(item.title || 'Untitled') + '</button>';
                if (item.items && item.items.length) html += await renderOutlineList(item.items, depth + 1);
                html += '</li>';
            }
            html += '</ul>';
            return html;
        }

        function clearSearchHighlights() {
            searchGen++;
            pages.forEach(rec => {
                rec.textLayer.querySelectorAll('mark.pdf-hl').forEach(m => {
                    const parent = m.parentNode;
                    if (!parent) return;
                    parent.replaceChild(document.createTextNode(m.textContent), m);
                    parent.normalize();
                });
            });
            searchMatches = [];
            searchIndex = -1;
        }

        async function runSearch(term) {
            const gen = ++searchGen;
            // Clear highlights without bumping gen again (we already own this generation).
            pages.forEach(rec => {
                rec.textLayer.querySelectorAll('mark.pdf-hl').forEach(m => {
                    const parent = m.parentNode;
                    if (!parent) return;
                    parent.replaceChild(document.createTextNode(m.textContent), m);
                    parent.normalize();
                });
            });
            searchMatches = [];
            searchIndex = -1;

            const q = (term || '').trim().toLowerCase();
            if (!q) { window.__docViewerUpdateSearchCount(0, -1, ''); return; }

            // Scan text without forcing every canvas to paint; only render pages that match.
            const matchingPages = [];
            for (let i = 0; i < pageCount; i++) {
                if (gen !== searchGen) return;
                const rec = pages[i];
                const page = rec.page || (rec.page = await pdfDoc.getPage(i + 1));
                if (!rec.textContent) rec.textContent = await page.getTextContent();
                const pageText = rec.textContent.items.map(it => it.str).join(' ').toLowerCase();
                if (pageText.includes(q)) matchingPages.push(i);
            }

            for (const i of matchingPages) {
                if (gen !== searchGen) return;
                await ensureRendered(i);
                const rec = pages[i];
                rec.textLayer.querySelectorAll('span').forEach(span => {
                    const text = span.textContent;
                    if (!text || !text.toLowerCase().includes(q)) return;
                    const mark = document.createElement('mark');
                    mark.className = 'pdf-hl';
                    mark.textContent = text;
                    span.textContent = '';
                    span.appendChild(mark);
                    searchMatches.push({ pageIndex: i, el: mark });
                });
            }

            if (gen !== searchGen) return;
            window.__docViewerUpdateSearchCount(searchMatches.length, searchMatches.length ? 0 : -1, q);
            if (searchMatches.length) goToMatch(0);
        }

        async function goToMatch(i) {
            if (!searchMatches.length) return;
            if (searchIndex >= 0 && searchMatches[searchIndex]) searchMatches[searchIndex].el.classList.remove('pdf-hl--active');
            searchIndex = ((i % searchMatches.length) + searchMatches.length) % searchMatches.length;
            const m = searchMatches[searchIndex];
            await ensureRenderedRange(m.pageIndex + 1, LAZY_BUFFER);
            m.el.classList.add('pdf-hl--active');
            scrollToPage(m.pageIndex + 1);
            m.el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            window.__docViewerUpdateSearchCount(searchMatches.length, searchIndex, searchInput.value);
        }
    }

    function debounce(fn, ms) {
        let t;
        return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
    }
})();
</script>
</body>
</html>
