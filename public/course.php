<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../course_catalog.php';
require_once __DIR__ . '/../includes/course/url_safety.php';
require_once __DIR__ . '/../includes/course/schema.php';

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

if (!portal_can_manage_course((int) $course['id']) && !portal_course_student_may_enter($course)) {
    portal_redirect('courses.php');
}

$courseId = (int) $course['id'];
$currentUser = portal_current_user();
$preEnrollBlocks = !portal_can_manage_course($courseId)
    && portal_pre_enroll_blocks_student($course, (int) ($currentUser['id'] ?? 0));
$preEnrollActivity = portal_pre_enroll_activity($course);
$showExternalAiSlotOption = portal_show_submission_external_ai_option($courseId);
$externalAiSiteWide = portal_external_ai_configured() && portal_external_ai_policy() === 'site_wide';

// ── CSRF token ────────────────────────────────────────────────────────────────
if (empty($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['_csrf'];

$courseFlash = $_SESSION['course_flash'] ?? null;
unset($_SESSION['course_flash']);

portal_course_ensure_schema();

require __DIR__ . '/../includes/course/post_actions.php';
require __DIR__ . '/../includes/course/queries.php';

ob_start();
require __DIR__ . '/../partials/course/page.php';
require __DIR__ . '/../partials/course/overlays.php';
require __DIR__ . '/../partials/course/scripts.php';
$page_content = ob_get_clean();

require __DIR__ . '/../layout.php';
