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
            ]);
            foreach ($programFiles as $base) {
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

$allowDownload = (bool) ($item['allow_download'] ?? 0);
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($displayName) ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; overflow: hidden; }
body {
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    display: flex; flex-direction: column; background: #1c1c2e;
}

/* ── Toolbar ─────────────────────────────────────────────────────────────── */
.vt {
    display: flex; align-items: center; gap: 8px;
    padding: 0 14px; height: 52px; flex-shrink: 0;
    background: #1c1c2e; border-bottom: 1px solid rgba(255,255,255,0.08);
}
.vt-back {
    display: flex; align-items: center; gap: 5px;
    color: rgba(255,255,255,0.65); text-decoration: none;
    font-size: 0.82rem; font-weight: 600; padding: 6px 10px;
    border-radius: 7px; transition: background .14s, color .14s;
    white-space: nowrap; flex-shrink: 0;
}
.vt-back:hover { background: rgba(255,255,255,0.1); color: #fff; }
.vt-sep { width: 1px; height: 26px; background: rgba(255,255,255,0.12); flex-shrink: 0; }
.vt-name {
    font-size: 0.9rem; font-weight: 600; color: #fff;
    flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.vt-ext {
    font-size: 0.65rem; font-weight: 800; letter-spacing: .07em;
    background: rgba(193,32,47,0.7); color: #fff;
    padding: 2px 8px; border-radius: 5px; flex-shrink: 0;
}

/* ── Zoom controls ───────────────────────────────────────────────────────── */
.vt-zoom {
    display: flex; align-items: center; gap: 2px;
    background: rgba(255,255,255,0.08); border-radius: 8px; padding: 3px;
    flex-shrink: 0;
}
.vt-zbtn {
    width: 30px; height: 30px; border: none; background: transparent; color: rgba(255,255,255,.8);
    cursor: pointer; border-radius: 6px; font-size: 1.05rem;
    display: flex; align-items: center; justify-content: center; transition: background .12s;
}
.vt-zbtn:hover { background: rgba(255,255,255,0.18); color: #fff; }
.vt-zpct { min-width: 46px; text-align: center; font-size: 0.8rem; font-weight: 700; color: rgba(255,255,255,.85); }

/* ── Download button ─────────────────────────────────────────────────────── */
.vt-dl {
    display: flex; align-items: center; gap: 6px;
    padding: 7px 14px; border-radius: 8px;
    background: rgba(255,255,255,0.12); color: rgba(255,255,255,.9);
    text-decoration: none; font-size: 0.82rem; font-weight: 600;
    transition: background .14s; white-space: nowrap; flex-shrink: 0;
}
.vt-dl:hover { background: rgba(255,255,255,0.22); color: #fff; }
.vt-dl svg { width: 14px; height: 14px; }

/* ── Content area ────────────────────────────────────────────────────────── */
.vc { flex: 1; overflow: hidden; position: relative; }

/* PDF */
.vc-pdf { width: 100%; height: 100%; border: none; display: block; }

/* Office scroll wrapper */
.vc-scroll {
    width: 100%; height: 100%; overflow: auto;
    background: #404040; padding: 28px 20px;
}
.vc-zoom-wrap { transform-origin: top center; transition: transform .15s ease; }

/* ── DOCX paper ──────────────────────────────────────────────────────────── */
.docx-paper {
    background: #fff; max-width: 816px; margin: 0 auto;
    padding: 80px 96px; min-height: 1056px;
    box-shadow: 0 4px 28px rgba(0,0,0,0.35);
    font-family: "Calibri", "Georgia", serif; font-size: 12pt; line-height: 1.6; color: #111;
}
.docx-paper h1 { font-size: 2em; margin: 1em 0 .5em; }
.docx-paper h2 { font-size: 1.5em; margin: .9em 0 .4em; }
.docx-paper h3 { font-size: 1.2em; margin: .7em 0 .3em; }
.docx-paper p  { margin: .5em 0; }
.docx-paper table { border-collapse: collapse; width: 100%; margin: 1em 0; }
.docx-paper td, .docx-paper th { border: 1px solid #ccc; padding: 6px 10px; }
.docx-paper img { max-width: 100%; height: auto; }
.docx-paper ul, .docx-paper ol { padding-left: 2em; margin: .5em 0; }

/* ── XLSX ────────────────────────────────────────────────────────────────── */
.xlsx-wrap { display: flex; flex-direction: column; height: 100%; }
.xlsx-tabs { display: flex; background: #333; overflow-x: auto; flex-shrink: 0; }
.xlsx-tab {
    padding: 7px 18px; font-size: 0.8rem; font-weight: 600; cursor: pointer;
    color: rgba(255,255,255,.55); border-bottom: 2px solid transparent;
    white-space: nowrap; transition: color .12s;
}
.xlsx-tab:hover { color: rgba(255,255,255,.85); }
.xlsx-tab.active { color: #fff; border-bottom-color: #c1202f; }
.xlsx-body { flex: 1; overflow: auto; background: #fff; }
.xlsx-tbl { border-collapse: collapse; font-size: 0.82rem; }
.xlsx-tbl th {
    background: #f3f3f3; font-weight: 700; border: 1px solid #d8d8d8;
    padding: 5px 10px; min-width: 80px; text-align: center; position: sticky; top: 0; z-index: 1;
}
.xlsx-tbl td { border: 1px solid #e4e4e4; padding: 4px 9px; min-width: 80px; white-space: nowrap; }
.xlsx-rn { background: #f3f3f3; color: #777; text-align: center; font-weight: 600; position: sticky; left: 0; }

/* ── PPTX deck ───────────────────────────────────────────────────────────── */
.pptx-wrap { display: flex; flex-direction: column; height: 100%; }
.pptx-nav {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 16px; background: #252535; flex-shrink: 0;
}
.pptx-nbtn {
    padding: 5px 14px; background: rgba(255,255,255,0.12); border: none; color: rgba(255,255,255,.85);
    border-radius: 6px; cursor: pointer; font-size: 0.82rem; font-weight: 600; transition: background .12s;
}
.pptx-nbtn:hover:not(:disabled) { background: rgba(255,255,255,0.22); color: #fff; }
.pptx-nbtn:disabled { opacity: .3; cursor: default; }
.pptx-cnt { font-size: 0.82rem; color: rgba(255,255,255,.6); flex: 1; text-align: center; }
.pptx-thumbs {
    display: flex; gap: 8px; padding: 10px 16px; background: #1a1a2a;
    overflow-x: auto; flex-shrink: 0;
}
.pptx-thumb {
    flex-shrink: 0; width: 100px; aspect-ratio: 16/9;
    border: 2px solid rgba(255,255,255,0.12); border-radius: 4px;
    background: #2a2a3a; cursor: pointer;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 3px; padding: 4px 6px; transition: border-color .14s;
}
.pptx-thumb.active  { border-color: #c1202f; }
.pptx-thumb:hover   { border-color: rgba(193,32,47,.55); }
.pptx-thumb-n       { font-size: 0.68rem; font-weight: 700; color: rgba(255,255,255,.45); }
.pptx-thumb.active .pptx-thumb-n { color: rgba(255,255,255,.9); }
.pptx-thumb-t       { font-size: 0.52rem; color: rgba(255,255,255,.3); text-align: center; overflow: hidden; max-height: 22px; line-height: 1.2; }
.pptx-thumb.active .pptx-thumb-t { color: rgba(255,255,255,.6); }

.pptx-viewport {
    flex: 1; overflow: auto; background: #2d2d3e;
    display: flex; align-items: flex-start; justify-content: center; padding: 32px;
}

/* Each slide mimics a real 16:9 PowerPoint slide */
.pptx-slide {
    background: #fff;
    width: 900px;
    aspect-ratio: 16/9;
    display: none; flex-direction: column;
    overflow: hidden;
    border-radius: 2px;
    box-shadow: 0 8px 44px rgba(0,0,0,0.55);
    position: relative;
}
.pptx-slide.active { display: flex; }

/* Coloured header strip — uses --sa (slide accent) extracted from theme */
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

/* Thin accent bar for slides that have no title */
.pptx-slide-bar { height: 5px; background: var(--sa, #1e3a5f); flex-shrink: 0; }

/* Content area */
.pptx-slide-body-wrap {
    flex: 1; padding: 18px 44px 28px;
    display: flex; flex-direction: column; overflow: hidden;
}
.pptx-slide-body { font-size: 1rem; line-height: 1.6; color: #1a1a1a; }
.pptx-slide-body ul  { padding-left: 1.3em; margin: 0; }
.pptx-slide-body li  { margin-bottom: 5px; }

/* Slide number badge — bottom-right corner */
.pptx-slide-n {
    position: absolute; bottom: 7px; right: 11px;
    font-size: 0.62rem; color: rgba(0,0,0,.28); font-weight: 600;
}

/* ── Loading / error ─────────────────────────────────────────────────────── */
.vw-loading {
    display: flex; align-items: center; justify-content: center;
    gap: 12px; padding: 60px 20px; color: #999; font-size: 0.9rem;
}
.vw-spinner {
    width: 22px; height: 22px; border: 3px solid #555;
    border-top-color: #c1202f; border-radius: 50%;
    animation: vspin .65s linear infinite; flex-shrink: 0;
}
@keyframes vspin { to { transform: rotate(360deg); } }
.vw-error { padding: 32px; color: #e55; font-size: 0.9rem; }
.vw-warning {
    padding: 9px 16px;
    background: #3a3320;
    color: #f5d784;
    font-size: 0.82rem;
    border-bottom: 1px solid rgba(255,255,255,0.08);
}
</style>
</head>
<body>

<!-- ── Toolbar ──────────────────────────────────────────────────────────── -->
<div class="vt">
    <a class="vt-back" href="<?= htmlspecialchars($backUrl) ?>"
       onclick="if(history.length>1){history.back();return false;}">
        ← Back
    </a>
    <div class="vt-sep"></div>
    <span class="vt-name"><?= htmlspecialchars($displayName) ?></span>
    <span class="vt-ext"><?= strtoupper(htmlspecialchars($ext)) ?></span>

    <?php if ($ext !== 'pdf' && !($isPresentation && $canConvertPresentation)): ?>
    <div class="vt-zoom" id="vt-zoom">
        <button class="vt-zbtn" id="vz-out" title="Zoom out">−</button>
        <span class="vt-zpct" id="vz-pct">100%</span>
        <button class="vt-zbtn" id="vz-in"  title="Zoom in">+</button>
        <button class="vt-zbtn" id="vz-rst" title="Reset" style="font-size:.75rem;font-weight:800;">1:1</button>
    </div>
    <?php endif; ?>

    <?php if ($canDownload): ?>
    <a class="vt-dl" href="<?= htmlspecialchars($downloadUrl) ?>">
        <svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 12l-4-4h2.5V2h3v6H12L8 12z"/><path d="M2 13h12v1.5H2z"/></svg>
        Download
    </a>
    <?php endif; ?>
</div>

<!-- ── Content ─────────────────────────────────────────────────────────── -->
<div class="vc">

<?php if ($ext === 'pdf'): ?>
    <iframe class="vc-pdf" src="<?= htmlspecialchars($fileUrl) ?>"></iframe>

<?php elseif ($isPresentation && $canConvertPresentation): ?>
    <iframe class="vc-pdf" src="<?= htmlspecialchars($presentationPdfUrl) ?>"></iframe>

<?php elseif ($ext === 'docx'): ?>
    <div class="vc-scroll" id="vc-scroll">
        <div class="vc-zoom-wrap" id="vc-zoom">
            <div class="docx-paper" id="vc-body">
                <div class="vw-loading"><div class="vw-spinner"></div>Loading document…</div>
            </div>
        </div>
    </div>

<?php elseif ($ext === 'xlsx'): ?>
    <div class="xlsx-wrap">
        <div class="xlsx-tabs" id="xlsx-tabs"></div>
        <div class="vc-scroll xlsx-body" id="vc-scroll" style="padding:0;">
            <div class="vc-zoom-wrap" id="vc-zoom">
                <div id="vc-body">
                    <div class="vw-loading"><div class="vw-spinner"></div>Loading spreadsheet…</div>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($ext === 'pptx'): ?>
    <div class="pptx-wrap">
        <div class="vw-warning">Showing a text fallback. Install LibreOffice on the server to preserve the full slide design.</div>
        <div class="pptx-nav">
            <button class="pptx-nbtn" id="pp-prev">← Prev</button>
            <span class="pptx-cnt" id="pp-cnt">Loading…</span>
            <button class="pptx-nbtn" id="pp-next">Next →</button>
            <div class="vt-zoom" id="vt-zoom" style="margin-left:auto;">
                <button class="vt-zbtn" id="vz-out">−</button>
                <span class="vt-zpct" id="vz-pct">100%</span>
                <button class="vt-zbtn" id="vz-in">+</button>
                <button class="vt-zbtn" id="vz-rst" style="font-size:.75rem;font-weight:800;">1:1</button>
            </div>
        </div>
        <div class="pptx-thumbs" id="pp-thumbs"></div>
        <div class="pptx-viewport">
            <div class="vc-zoom-wrap" id="vc-zoom">
                <div id="vc-body">
                    <div class="vw-loading"><div class="vw-spinner"></div>Loading presentation…</div>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="vc-scroll">
        <div class="vw-error">
            Inline preview is not available for .<?= htmlspecialchars($ext) ?> files. Use Download to open it locally.
        </div>
    </div>
<?php endif; ?>

</div><!-- /.vc -->

<?php if ($ext !== 'pdf' && !($isPresentation && $canConvertPresentation)): ?>
<script src="https://cdn.jsdelivr.net/npm/mammoth@1/mammoth.browser.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"></script>
<script>
(function () {
    'use strict';
    const FILE_URL = <?= json_encode($fileUrl) ?>;
    const EXT      = <?= json_encode($ext) ?>;

    // ── Zoom ─────────────────────────────────────────────────────────────────
    const zoomWrap = document.getElementById('vc-zoom');
    const zPct     = document.getElementById('vz-pct');
    let zoom = 1.0;

    function applyZoom() {
        if (!zoomWrap) return;
        zoomWrap.style.transform = `scale(${zoom})`;
        if (zPct) zPct.textContent = Math.round(zoom * 100) + '%';
    }
    function setZoom(z) { zoom = Math.max(0.35, Math.min(4.0, z)); applyZoom(); }

    document.getElementById('vz-in') ?.addEventListener('click', () => setZoom(zoom + 0.15));
    document.getElementById('vz-out')?.addEventListener('click', () => setZoom(zoom - 0.15));
    document.getElementById('vz-rst')?.addEventListener('click', () => setZoom(1.0));

    document.addEventListener('wheel', e => {
        if (e.ctrlKey) { e.preventDefault(); setZoom(zoom + (e.deltaY < 0 ? 0.1 : -0.1)); }
    }, { passive: false });

    // ── Fetch ────────────────────────────────────────────────────────────────
    async function fetchBuf() {
        const r = await fetch(FILE_URL);
        if (!r.ok) { showErr(await r.text() || 'Could not load file.'); return null; }
        return r.arrayBuffer();
    }
    function showErr(msg) {
        const el = document.getElementById('vc-body');
        if (el) el.innerHTML = `<div class="vw-error">⚠ ${msg}</div>`;
    }
    function esc(s) {
        return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── DOCX ─────────────────────────────────────────────────────────────────
    if (EXT === 'docx') {
        (async () => {
            const buf = await fetchBuf(); if (!buf) return;
            const res = await mammoth.convertToHtml({ arrayBuffer: buf });
            const el  = document.getElementById('vc-body');
            if (el) el.innerHTML = res.value || '<p style="color:#999;padding:20px"><em>This document appears to be empty.</em></p>';
        })().catch(e => showErr(e.message));
    }

    // ── XLSX ─────────────────────────────────────────────────────────────────
    if (EXT === 'xlsx') {
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

            tabsEl.addEventListener('click', e => {
                const t = e.target.closest('.xlsx-tab[data-i]');
                if (!t) return;
                tabsEl.querySelectorAll('.xlsx-tab').forEach(x => x.classList.remove('active'));
                t.classList.add('active');
                bodyEl.innerHTML = renderSheet(sheets[+t.dataset.i]);
            });
        })().catch(e => showErr(e.message));
    }

    // ── PPTX ─────────────────────────────────────────────────────────────────
    if (EXT === 'pptx') {
        let slides = [], cur = 0, accent = '#1e3a5f';

        (async () => {
            const buf = await fetchBuf(); if (!buf) return;
            const zip = await JSZip.loadAsync(buf);

            // Try to pull the accent1 colour from the presentation theme
            accent = await themeAccent(zip);

            slides = await extractSlides(zip);

            const bodyEl   = document.getElementById('vc-body');
            const thumbsEl = document.getElementById('pp-thumbs');
            const cntEl    = document.getElementById('pp-cnt');
            const prevBtn  = document.getElementById('pp-prev');
            const nextBtn  = document.getElementById('pp-next');

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
            }

            prevBtn.addEventListener('click',  () => go(cur - 1));
            nextBtn.addEventListener('click',  () => go(cur + 1));
            thumbsEl.addEventListener('click', e => {
                const t = e.target.closest('.pptx-thumb[data-i]');
                if (t) go(+t.dataset.i);
            });
            document.addEventListener('keydown', e => {
                if (e.key === 'ArrowRight' || e.key === 'ArrowDown') go(cur + 1);
                if (e.key === 'ArrowLeft'  || e.key === 'ArrowUp')   go(cur - 1);
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
                // Header + content layout
                return `<div class="pptx-slide" data-i="${i}" ${style}>
                    <div class="pptx-slide-hdr"><div class="pptx-slide-title">${esc(s.title)}</div></div>
                    <div class="pptx-slide-body-wrap"><div class="pptx-slide-body">${body || emptyBody}</div></div>
                    <div class="pptx-slide-n">${i + 1}</div>
                </div>`;
            } else {
                // Content-only: thin accent bar at top, centred body
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
                // accent1 is the primary branding colour in most themes
                const m = xml.match(/<a:accent1\b[^>]*>\s*<a:srgbClr\s+val="([0-9A-Fa-f]{6})"/);
                if (m) return '#' + m[1];
                // fall back to dk2 (dark colour 2)
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
})();
</script>
<?php endif; ?>
</body>
</html>
