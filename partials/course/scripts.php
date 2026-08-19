<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2/dist/quill.snow.css">
<script>
/* Inlined so Chrome cannot serve a stale/missing assets/upload-dropzone.js */
<?php
$portalUploadDndJs = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'upload-dropzone.js';
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
<script src="assets/course-page.js?v=20260819split"></script>
