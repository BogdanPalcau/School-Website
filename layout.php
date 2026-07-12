<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Expected page variables:
 * - $page_title
 * - $layout_variant
 * - $active_page
 * - $page_eyebrow
 * - $page_heading
 * - $page_description
 * - $page_content
 * - $auth_eyebrow
 * - $auth_heading
 * - $auth_description
 *
 * Legacy camelCase names are still accepted as fallbacks.
 */
$page_title = $page_title ?? $pageTitle ?? portal_school_name() . ' Student Portal';
$layout_variant = $layout_variant ?? $layoutVariant ?? 'app';
$active_page = $active_page ?? $activePage ?? 'dashboard';
$page_eyebrow = $page_eyebrow ?? $pageEyebrow ?? 'Student workspace';
$page_heading = $page_heading ?? $pageHeading ?? 'Dashboard';
$page_description = $page_description ?? $pageDescription ?? 'Keep up with your classes, deadlines, and the things happening around school.';
$page_content = $page_content ?? $pageContent ?? '';
$auth_eyebrow = $auth_eyebrow ?? $authEyebrow ?? 'Student access';
$auth_heading = $auth_heading ?? $authHeading ?? portal_school_name();
$auth_description = $auth_description ?? $authDescription ?? 'Sign in to see your lessons, deadlines, school updates, and everything coming up this week.';

$navItems = [
    ['key' => 'dashboard',     'label' => 'Dashboard',     'href' => 'dashboard.php',     'icon' => 'home'],
    ['key' => 'courses',       'label' => 'Courses',       'href' => 'courses.php',       'icon' => 'book-open'],
    ['key' => 'grades',        'label' => portal_is_course_staff() || portal_is_admin() ? 'Marking' : 'Grades', 'href' => 'grades.php', 'icon' => 'award'],
    ['key' => 'timetable',     'label' => 'Timetable',     'href' => 'timetable.php',     'icon' => 'calendar'],
    ['key' => 'communication', 'label' => 'Communication', 'href' => 'communication.php', 'icon' => 'megaphone'],
    ['key' => 'events',        'label' => 'Events',        'href' => 'events.php',        'icon' => 'sparkles'],
    ['key' => 'settings',      'label' => 'Settings',      'href' => 'settings.php',      'icon' => 'settings'],
    ['key' => 'logout',        'label' => 'Logout',        'href' => 'logout.php',        'icon' => 'log-out'],
];

if (portal_is_admin()) {
    array_splice($navItems, -2, 0, [[
        'key'   => 'admin',
        'label' => 'Admin',
        'href'  => 'admin.php',
        'icon'  => 'shield',
    ]]);
}

$asset_version = '20260712q';
$logo_src = 'assets/rieo-crest.svg?v=' . $asset_version;
$style_src = '../style.css?v=' . $asset_version;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= portal_escape($page_title) ?></title>
    <link rel="stylesheet" href="<?= portal_escape($style_src) ?>">
</head>
<body<?= $layout_variant === 'auth' ? ' class="login-body"' : '' ?>>
    <div class="page-backdrop"></div>

    <?php if ($layout_variant === 'auth'): ?>
        <main class="login-shell">
            <section class="login-panel">
                <div class="login-brand">
                    <img class="auth-main-logo" src="<?= portal_escape($logo_src) ?>" width="56" height="56" alt="<?= portal_escape(portal_school_name()) ?> crest">
                    <div>
                        <strong><?= portal_escape(portal_school_short_name()) ?></strong>
                        <span><?= portal_escape($auth_heading) ?></span>
                    </div>
                </div>

                <?= $page_content ?>
            </section>
        </main>
    <?php else: ?>
        <div class="portal-shell">
            <aside class="sidebar">
                <div class="brand-block">
                    <img class="brand-logo" src="<?= portal_escape($logo_src) ?>" width="82" height="82" alt="<?= portal_escape(portal_school_name()) ?> crest">
                    <div>
                        <p class="brand-kicker"><?= portal_escape(portal_school_name()) ?></p>
                        <h1><?= portal_escape(portal_school_short_name()) ?></h1>
                    </div>
                </div>

                <nav class="sidebar-nav" aria-label="Sidebar">
                    <?php foreach ($navItems as $item): ?>
                        <?php $isActive = $item['key'] === $active_page; ?>
                        <a class="nav-link<?= $isActive ? ' active' : '' ?>" href="<?= portal_escape($item['href']) ?>">
                            <?= portal_icon($item['icon'], 'nav-icon') ?>
                            <span><?= portal_escape($item['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <?php
                    $__sidebarUser = portal_current_user();
                    $__sidebarRole = portal_current_user_role();
                    $__sidebarMeta = $__sidebarUser['year'] ?? '';

                    if ($__sidebarRole === 'owner') {
                        $__sidebarMeta = 'Account owner';
                    } elseif ($__sidebarRole === 'admin') {
                        $__sidebarMeta = 'Administrator';
                    } elseif ($__sidebarRole === 'teacher') {
                        $__sidebarMeta = 'Teacher account';
                    }
                ?>
                <div class="sidebar-user-card">
                    <p class="sidebar-logged-in-label">Signed in as</p>
                    <div class="sidebar-user-row">
                        <div class="student-avatar"><?= portal_escape($__sidebarUser['initials'] ?? 'ST') ?></div>
                        <div class="sidebar-user-info">
                            <strong><?= portal_escape($__sidebarUser['name'] ?? 'Student') ?></strong>
                            <span><?= portal_escape($__sidebarMeta) ?></span>
                            <span class="sidebar-role-badge sidebar-role-badge--<?= portal_escape($__sidebarRole) ?>"><?= portal_escape(ucfirst($__sidebarRole)) ?></span>
                        </div>
                    </div>
                    <div class="sidebar-user-actions">
                        <a class="sidebar-user-action" href="settings.php">Settings</a>
                        <a class="sidebar-user-action sidebar-user-action--danger" href="logout.php">Sign out</a>
                    </div>
                </div>
            </aside>

            <main class="main-panel">
                <header class="topbar">
                    <div>
                        <p class="eyebrow"><?= portal_escape($page_eyebrow) ?></p>
                        <h2><?= portal_escape($page_heading) ?></h2>
                        <p class="lead-copy"><?= portal_escape($page_description) ?></p>
                    </div>
                </header>

                <div class="page-content">
                    <?= $page_content ?>
                </div>
            </main>
        </div>
    <?php endif; ?>
    <script>
    (function () {
        function restartShake(node) {
            if (!node || !node.classList) return;
            node.classList.remove('is-validation-shaking');
            void node.offsetWidth;
            node.classList.add('is-validation-shaking');
        }

        function validationTarget(field) {
            if (!field || !field.closest) return field;
            return field.closest('.login-input, .admin-field, .settings-field, .folder-form-label, .submit-form, label') || field;
        }

        function showInvalidField(field, shouldReport) {
            if (!field) return;
            restartShake(field);
            restartShake(validationTarget(field));
            if (typeof field.focus === 'function') {
                try {
                    field.focus({ preventScroll: false });
                } catch (err) {
                    field.focus();
                }
            }
            if (shouldReport && typeof field.reportValidity === 'function') {
                field.reportValidity();
            }
        }

        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!(form instanceof HTMLFormElement) || form.hasAttribute('data-skip-smooth-validation')) return;
            if (form.checkValidity()) return;

            event.preventDefault();
            showInvalidField(form.querySelector(':invalid'), true);
        }, true);

        document.addEventListener('invalid', function (event) {
            showInvalidField(event.target, false);
        }, true);
    })();
    </script>
</body>
</html>
