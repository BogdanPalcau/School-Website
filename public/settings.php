<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../customization.php';

portal_require_login();

$meId = (int) (portal_current_user()['id'] ?? 0);
$meDb = $meId > 0 ? portal_find_user_by_id($meId) : null;

if ($meDb === null) {
    portal_logout();
    portal_redirect('login.php');
}

$me = $meDb;
$db = portal_db();
$role = (string) ($me['role'] ?? 'student');
$isStudent = $role === 'student';
$isOwner = $role === 'owner';
$canEditEmail = $isStudent || $isOwner;
$prefs = portal_user_preferences($meId);
$customPrefs = portal_customization_preferences($meId);

$flash = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!portal_verify_csrf()) {
        $_SESSION['settings_flash'] = ['error', 'Your session expired. Please try again.'];
        portal_redirect('settings.php');
    }

    $action = isset($_POST['reset_customization'])
        ? 'reset_customization'
        : (string) ($_POST['action'] ?? '');

    if ($action === 'update_profile') {
        $name = substr(trim((string) ($_POST['name'] ?? '')), 0, 150);
        $email = strtolower(substr(trim((string) ($_POST['email'] ?? '')), 0, 190));
        $year = (string) ($me['year'] ?? '');

        if ($name === '') {
            $_SESSION['settings_flash'] = ['error', 'Name cannot be empty.'];
            portal_redirect('settings.php');
        }

        if ($canEditEmail) {
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $_SESSION['settings_flash'] = ['error', 'Enter a valid email address.'];
                portal_redirect('settings.php');
            }
            $dup = $db->prepare("SELECT id FROM users WHERE LOWER(email) = ? AND id != ? LIMIT 1");
            $dup->execute([$email, $meId]);
            if ($dup->fetch()) {
                $_SESSION['settings_flash'] = ['error', 'That email is already used by another account.'];
                portal_redirect('settings.php');
            }
            $currentEmail = strtolower(trim((string) ($me['email'] ?? '')));
            if ($email !== $currentEmail) {
                $emailConfirmPassword = (string) ($_POST['email_confirm_password'] ?? '');
                if ($emailConfirmPassword === '') {
                    $_SESSION['settings_flash'] = ['error', 'Enter your current password to change your email.'];
                    portal_redirect('settings.php');
                }
                if (!password_verify($emailConfirmPassword, (string) ($me['password_hash'] ?? ''))) {
                    $_SESSION['settings_flash'] = ['error', 'Current password is incorrect.'];
                    portal_redirect('settings.php');
                }
            }
        } else {
            $email = (string) $me['email'];
        }

        $parts = preg_split('/\s+/', $name) ?: [];
        $initials = strtoupper(
            substr($parts[0] ?? 'S', 0, 1) . substr($parts[1] ?? 'T', 0, 1)
        );

        $db->prepare("UPDATE users SET name = ?, year = ?, initials = ?, email = ? WHERE id = ?")
           ->execute([$name, $year, $initials, $email, $meId]);

        $changes = [];
        if ($name !== (string) ($me['name'] ?? '')) {
            $changes[] = 'name';
        }
        if ($canEditEmail && strtolower(trim((string) ($me['email'] ?? ''))) !== $email) {
            $changes[] = 'email';
        }
        portal_log_security_event(
            'profile_updated',
            'info',
            'Profile updated' . ($changes !== [] ? ': ' . implode(', ', $changes) : ''),
            $meId
        );

        $fresh = portal_find_user_by_id($meId);
        if ($fresh) {
            $_SESSION['portal_user'] = [
                'id'        => (int) $fresh['id'],
                'username'  => $fresh['username'],
                'email'     => $fresh['email'],
                'name'      => $fresh['name'],
                'year'      => $fresh['year'],
                'initials'  => $fresh['initials'],
                'role'      => $fresh['role'],
            ];
        }
        $_SESSION['settings_flash'] = ['success', 'Profile updated.'];
        portal_redirect('settings.php#tab-profile');
    }

    if ($action === 'change_password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $newPass = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        $row = portal_find_user_by_id($meId);
        $ruleError = portal_password_validate($newPass);

        if (!$row || !password_verify($current, $row['password_hash'])) {
            $_SESSION['settings_flash'] = ['error', 'Current password is incorrect.'];
        } elseif ($ruleError !== '') {
            $_SESSION['settings_flash'] = ['error', $ruleError];
        } elseif ($newPass !== $confirm) {
            $_SESSION['settings_flash'] = ['error', 'New passwords do not match.'];
        } elseif (password_verify($newPass, $row['password_hash'])) {
            $_SESSION['settings_flash'] = ['error', 'Choose a password that is different from your current one.'];
        } else {
            $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
               ->execute([password_hash($newPass, PASSWORD_DEFAULT), $meId]);
            portal_log_security_event('password_changed', 'medium', 'Password changed from settings', $meId);
            $_SESSION['settings_flash'] = ['success', 'Password changed successfully.'];
        }
        portal_redirect('settings.php#tab-security');
    }

    if ($action === 'update_notifications') {
        portal_save_user_preferences($meId, [
            'notify_grades'        => isset($_POST['notify_grades']) ? 1 : 0,
            'notify_qa'            => isset($_POST['notify_qa']) ? 1 : 0,
            'notify_announcements' => isset($_POST['notify_announcements']) ? 1 : 0,
            'notify_events'        => isset($_POST['notify_events']) ? 1 : 0,
        ]);
        $_SESSION['settings_flash'] = ['success', 'Notification preferences saved.'];
        portal_redirect('settings.php#tab-notifications');
    }

    if ($action === 'update_customization') {
        $currentCustomization = portal_customization_preferences($meId);
        portal_save_customization_preferences($meId, [
            'theme'              => $_POST['theme'] ?? '',
            'accent'             => $_POST['accent'] ?? '',
            'text_size'          => $_POST['text_size'] ?? '',
            'density'            => $_POST['density'] ?? '',
            'font_style'         => $_POST['font_style'] ?? '',
            'line_spacing'       => $_POST['line_spacing'] ?? '',
            'corner_style'       => $_POST['corner_style'] ?? '',
            'background_style'   => $_POST['background_style'] ?? '',
            'page_width'         => $_POST['page_width'] ?? '',
            'sidebar_style'      => $_POST['sidebar_style'] ?? '',
            'course_view'        => $_POST['course_view'] ?? '',
            'dashboard_focus'    => $_POST['dashboard_focus'] ?? '',
            'reduced_motion'     => isset($_POST['reduced_motion']) ? 1 : 0,
            'high_contrast'      => isset($_POST['high_contrast']) ? 1 : 0,
            'show_continue'      => isset($_POST['show_continue']) ? 1 : 0,
            'show_quick_access'  => isset($_POST['show_quick_access']) ? 1 : 0,
            'show_bulletin'      => isset($_POST['show_bulletin']) ? 1 : 0,
            'favorite_course_ids' => $currentCustomization['favorite_course_ids'],
        ]);
        $_SESSION['settings_flash'] = ['success', 'Your appearance and layout preferences were saved.'];
        portal_redirect('settings.php#tab-customization');
    }

    if ($action === 'reset_customization') {
        $defaults = portal_customization_defaults();
        $currentCustomization = portal_customization_preferences($meId);
        $defaults['favorite_course_ids'] = $currentCustomization['favorite_course_ids'];
        portal_save_customization_preferences($meId, $defaults);
        $_SESSION['settings_flash'] = ['success', 'Customization settings were restored to their defaults.'];
        portal_redirect('settings.php#tab-customization');
    }

    portal_redirect('settings.php');
}

if (isset($_SESSION['settings_flash'])) {
    $flash = $_SESSION['settings_flash'];
    unset($_SESSION['settings_flash']);
}

$me = portal_find_user_by_id($meId) ?? $me;
$prefs = portal_user_preferences($meId);
$customPrefs = portal_customization_preferences($meId);

$page_title = 'Settings | ' . portal_school_name();
$active_page = 'settings';
$page_eyebrow = 'Account';
$page_heading = 'Settings';
$page_description = 'A quiet place to keep your details and preferences up to date.';

ob_start();
?>
<section class="settings-page">

    <?php if ($flash): ?>
    <div class="settings-toast <?= $flash[0] === 'success' ? 'is-success' : 'is-error' ?>" role="status" aria-live="polite">
        <?= portal_escape($flash[1]) ?>
    </div>
    <?php endif; ?>

    <header class="settings-identity">
        <div class="settings-identity-main">
            <div class="settings-avatar"><?= portal_escape((string) $me['initials']) ?></div>
            <div>
                <p class="settings-identity-name"><?= portal_escape((string) $me['name']) ?></p>
                <p class="settings-identity-line">
                    <span class="settings-pill"><?= portal_escape(ucfirst($role)) ?></span>
                    <span><?= portal_escape((string) $me['username']) ?></span>
                    <span class="settings-dot" aria-hidden="true">·</span>
                    <span>Signed in <?= portal_escape(portal_login_time_text()) ?></span>
                </p>
            </div>
        </div>
        <a class="settings-text-link" href="logout.php">Sign out</a>
    </header>

    <div class="settings-workspace">
    <nav class="settings-section-nav" aria-label="Settings sections" role="tablist" data-settings-nav>
        <a href="#tab-profile" role="tab" aria-controls="profile" data-settings-tab="profile">
            <?= portal_icon('user', 'settings-section-icon') ?>
            <span class="settings-section-copy"><strong>Profile</strong><small>Personal details</small></span>
        </a>
        <a href="#tab-notifications" role="tab" aria-controls="notifications" data-settings-tab="notifications">
            <?= portal_icon('bell', 'settings-section-icon') ?>
            <span class="settings-section-copy"><strong>Notifications</strong><small>Portal alerts</small></span>
        </a>
        <a href="#tab-customization" role="tab" aria-controls="customization" data-settings-tab="customization">
            <?= portal_icon('settings', 'settings-section-icon') ?>
            <span class="settings-section-copy"><strong>Appearance</strong><small>Look and layout</small></span>
        </a>
        <a href="#tab-security" role="tab" aria-controls="security" data-settings-tab="security">
            <?= portal_icon('lock', 'settings-section-icon') ?>
            <span class="settings-section-copy"><strong>Password</strong><small>Account security</small></span>
        </a>
    </nav>

    <div class="settings-shell">
        <section class="settings-block" id="profile" role="tabpanel" data-settings-panel="profile">
            <div class="settings-block-head">
                <h3>Profile</h3>
                <p>What others see across the portal.</p>
            </div>

            <form method="POST" class="settings-form" novalidate>
                <?= portal_csrf_field() ?>
                <input type="hidden" name="action" value="update_profile">

                <div class="settings-grid">
                    <label class="settings-field<?= $canEditEmail ? '' : ' settings-field--wide' ?>">
                        <span>Display name</span>
                        <input class="settings-input" type="text" name="name"
                               value="<?= portal_escape((string) $me['name']) ?>" required maxlength="150" autocomplete="name">
                    </label>

                    <?php if ($canEditEmail): ?>
                    <label class="settings-field">
                        <span>Email</span>
                        <input class="settings-input" type="email" name="email"
                               value="<?= portal_escape((string) $me['email']) ?>" required maxlength="190" autocomplete="email">
                    </label>
                    <label class="settings-field settings-field--wide">
                        <span>Current password</span>
                        <input class="settings-input" type="password" name="email_confirm_password"
                               autocomplete="current-password">
                        <small class="settings-hint">Required if you change your email</small>
                    </label>
                    <?php endif; ?>
                </div>

                <div class="settings-meta-list">
                    <div>
                        <span>Username</span>
                        <strong><?= portal_escape((string) $me['username']) ?></strong>
                    </div>
                    <?php if (!$canEditEmail): ?>
                    <div>
                        <span>Email</span>
                        <strong><?= portal_escape((string) $me['email']) ?></strong>
                        <em>Ask an admin to change this</em>
                    </div>
                    <?php endif; ?>
                    <div>
                        <span>Role</span>
                        <strong><?= portal_escape(ucfirst($role)) ?></strong>
                    </div>
                </div>

                <div class="settings-actions">
                    <button type="submit" class="settings-btn">Save profile</button>
                </div>
            </form>
        </section>

        <section class="settings-block" id="notifications" role="tabpanel" data-settings-panel="notifications">
            <div class="settings-block-head">
                <h3>Notifications</h3>
                <p>Personal alerts in Communication.</p>
            </div>

            <form method="POST" class="settings-form">
                <?= portal_csrf_field() ?>
                <input type="hidden" name="action" value="update_notifications">

                <div class="settings-toggles">
                    <label class="settings-toggle">
                        <span>
                            <strong>Grade updates</strong>
                            <small>When marked work is returned</small>
                        </span>
                        <input type="checkbox" name="notify_grades" value="1"<?= !empty($prefs['notify_grades']) ? ' checked' : '' ?>>
                    </label>
                    <label class="settings-toggle">
                        <span>
                            <strong>Q&amp;A and discussion replies</strong>
                            <small>When a teacher answers your lesson question, or someone replies in a discussion you started or joined</small>
                        </span>
                        <input type="checkbox" name="notify_qa" value="1"<?= !empty($prefs['notify_qa']) ? ' checked' : '' ?>>
                    </label>
                    <label class="settings-toggle">
                        <span>
                            <strong>Announcement alerts</strong>
                            <small>Personal notes tied to bulletin updates</small>
                        </span>
                        <input type="checkbox" name="notify_announcements" value="1"<?= !empty($prefs['notify_announcements']) ? ' checked' : '' ?>>
                    </label>
                    <label class="settings-toggle">
                        <span>
                            <strong>Event alerts</strong>
                            <small>When school or course events are created, updated, or cancelled</small>
                        </span>
                        <input type="checkbox" name="notify_events" value="1"<?= !empty($prefs['notify_events']) ? ' checked' : '' ?>>
                    </label>
                </div>

                <div class="settings-actions">
                    <button type="submit" class="settings-btn">Save alerts</button>
                </div>
            </form>
        </section>

        <section class="settings-block" id="customization" role="tabpanel" data-settings-panel="customization">
            <div class="settings-block-head">
                <h3>Appearance &amp; layout</h3>
                <p>Personalize your view without changing course content or important deadlines.</p>
            </div>

            <form method="POST" class="settings-form" id="customization-form">
                <?= portal_csrf_field() ?>
                <input type="hidden" name="action" value="update_customization">
                <input type="hidden" name="theme" value="light">

                <div class="settings-custom-nav" role="tablist" aria-label="Appearance categories" data-custom-nav>
                    <button type="button" role="tab" data-custom-tab="colour">
                        <span>Colours</span>
                        <small>Accessible portal palettes</small>
                    </button>
                    <button type="button" role="tab" data-custom-tab="style">
                        <span>Style</span>
                        <small>Type, surfaces and sidebar</small>
                    </button>
                    <button type="button" role="tab" data-custom-tab="reading">
                        <span>Reading</span>
                        <small>Text and accessibility</small>
                    </button>
                    <button type="button" role="tab" data-custom-tab="layout">
                        <span>Dashboard &amp; courses</span>
                        <small>Cards, order and widgets</small>
                    </button>
                </div>

                <section class="settings-live-preview" aria-labelledby="customization-preview-title">
                    <div class="settings-live-preview-head">
                        <div>
                            <span class="settings-live-preview-kicker">Live preview</span>
                            <h4 id="customization-preview-title">See changes before saving</h4>
                        </div>
                        <div class="settings-preview-head-actions">
                            <span class="settings-preview-status" data-preview-status>Saved settings</span>
                            <button type="button" class="settings-preview-collapse" data-preview-collapse aria-expanded="true">Hide preview</button>
                        </div>
                    </div>

                    <div class="settings-preview-stage">
                        <aside class="settings-preview-sidebar" aria-hidden="true">
                            <span class="settings-preview-brand">RIEO</span>
                            <span class="settings-preview-nav is-active"></span>
                            <span class="settings-preview-nav"></span>
                            <span class="settings-preview-nav"></span>
                        </aside>

                        <div class="settings-preview-canvas">
                            <div class="settings-preview-copy">
                                <span>Student workspace</span>
                                <strong>Your dashboard preview</strong>
                                <small>Typography, colour, spacing and corners update here and on this page.</small>
                            </div>

                            <div class="settings-preview-dashboard">
                                <div class="settings-preview-card settings-preview-card--priorities" data-preview-order="priorities">
                                    <span>Priorities</span>
                                    <strong>2 items due</strong>
                                </div>
                                <div class="settings-preview-card settings-preview-card--schedule" data-preview-order="schedule">
                                    <span>Schedule</span>
                                    <strong>3 classes today</strong>
                                </div>
                            </div>

                            <div class="settings-preview-courses" aria-label="Course layout preview">
                                <span class="settings-preview-course"><i></i><b>Mathematics</b></span>
                                <span class="settings-preview-course"><i></i><b>Biology</b></span>
                                <span class="settings-preview-course"><i></i><b>English</b></span>
                            </div>

                            <div class="settings-preview-widgets">
                                <span data-preview-widget="continue">Continue watching</span>
                                <span data-preview-widget="quick">Quick access</span>
                                <span data-preview-widget="bulletin">Bulletin</span>
                            </div>
                        </div>
                    </div>

                    <div class="settings-live-preview-foot">
                        <p data-preview-note>Click any option below. Nothing changes permanently until you save.</p>
                        <button type="button" class="settings-preview-revert" data-preview-revert disabled>Revert preview</button>
                    </div>
                </section>

                <fieldset class="settings-subsection" data-custom-panel="colour">
                    <legend class="settings-subsection-head"><span>Portal colour palette</span><small>Choose one</small></legend>
                    <div class="settings-accessibility-note" role="note">
                        <strong>Colour that stays readable</strong>
                        <span>Every palette uses AA-tested core text and button colours. Increase contrast remains available with every choice.</span>
                    </div>
                    <div class="settings-choice-grid settings-choice-grid--palettes">
                        <?php foreach ([
                            'crimson' => ['Standard crimson', 'The original school portal', '#f7f4f2', '#9f172a', '#5b0d18'],
                            'coral' => ['Coral sunset', 'Warm coral with soft peach surfaces', '#fff4f2', '#b42318', '#7a271a'],
                            'amber' => ['Golden amber', 'Warm colour with dark, clear text', '#fff7ed', '#92400e', '#6b2e0c'],
                            'olive' => ['Citrus olive', 'Earthy lime with calm surfaces', '#f7fee7', '#4d7c0f', '#365314'],
                            'forest' => ['Forest green', 'Calm green with fresh surfaces', '#eef8f2', '#137548', '#0b4a30'],
                            'teal' => ['Fresh teal', 'Clear teal with airy surfaces', '#edf9f8', '#0f766e', '#134e4a'],
                            'cyan' => ['Arctic cyan', 'Bright cyan with crisp surfaces', '#ecfeff', '#0e7490', '#155e75'],
                            'ocean' => ['Ocean blue', 'Focused blue with cool surfaces', '#eef4ff', '#1d4ed8', '#1e3a8a'],
                            'indigo' => ['Indigo night', 'Deep indigo with cool surfaces', '#eef2ff', '#4338ca', '#312e81'],
                            'violet' => ['Royal violet', 'Bold purple with soft lavender', '#f5f2ff', '#6d28d9', '#4c1d95'],
                            'berry' => ['Berry bloom', 'Lively berry with soft pink surfaces', '#fdf2fa', '#a21caf', '#701a75'],
                            'slate' => ['Calm slate', 'Low-distraction blue-grey', '#f1f5f9', '#334155', '#0f172a'],
                        ] as $value => [$label, $description, $paletteBg, $palettePrimary, $paletteStrong]): ?>
                        <label class="settings-choice settings-choice--palette">
                            <input type="radio" name="accent" value="<?= $value ?>"<?= $customPrefs['accent'] === $value ? ' checked' : '' ?>>
                            <span class="settings-swatch settings-swatch--palette"
                                  style="--palette-bg:<?= portal_escape($paletteBg) ?>;--palette-primary:<?= portal_escape($palettePrimary) ?>;--palette-strong:<?= portal_escape($paletteStrong) ?>"
                                  aria-hidden="true"><i></i><i></i><i></i></span>
                            <strong><?= portal_escape($label) ?></strong>
                            <small><?= portal_escape($description) ?></small>
                            <span class="settings-palette-contrast"><b>AA</b> core contrast</span>
                            <span class="settings-choice-state" aria-hidden="true">✓ Selected</span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <div class="settings-subsection" data-custom-panel="reading">
                    <div class="settings-subsection-head">
                        <span>Quick accessibility setup</span>
                        <small>Apply a helpful combination, then adjust anything below</small>
                    </div>
                    <div class="settings-accessibility-presets">
                        <button type="button" data-accessibility-preset="reading">
                            <strong>Reading comfort</strong>
                            <small>Large text, relaxed lines and comfortable spacing</small>
                            <span data-preset-status>Apply</span>
                        </button>
                        <button type="button" data-accessibility-preset="clarity">
                            <strong>High clarity</strong>
                            <small>Comfortable text with stronger borders and focus rings</small>
                            <span data-preset-status>Apply</span>
                        </button>
                        <button type="button" data-accessibility-preset="calm">
                            <strong>Calm interface</strong>
                            <small>Reduced motion with comfortable spacing</small>
                            <span data-preset-status>Apply</span>
                        </button>
                    </div>
                </div>

                <fieldset class="settings-subsection" data-custom-panel="reading">
                    <legend class="settings-subsection-head"><span>Text size</span><small>Choose one</small></legend>
                    <div class="settings-choice-grid">
                        <?php foreach ([
                            'standard' => ['Standard', '100% · original size'],
                            'comfortable' => ['Comfortable', '106.25% · gently enlarged'],
                            'large' => ['Large', '112.5% · easier reading'],
                        ] as $value => [$label, $description]): ?>
                        <label class="settings-choice">
                            <input type="radio" name="text_size" value="<?= $value ?>"<?= $customPrefs['text_size'] === $value ? ' checked' : '' ?>>
                            <strong><?= portal_escape($label) ?></strong>
                            <small><?= portal_escape($description) ?></small>
                            <span class="settings-choice-state" aria-hidden="true">✓ Selected</span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <fieldset class="settings-subsection" data-custom-panel="reading">
                    <legend class="settings-subsection-head"><span>Spacing</span><small>Choose one</small></legend>
                    <div class="settings-choice-grid">
                        <?php foreach ([
                            'compact' => ['Compact', 'More information on screen'],
                            'comfortable' => ['Standard', 'The original portal spacing'],
                        ] as $value => [$label, $description]): ?>
                        <label class="settings-choice">
                            <input type="radio" name="density" value="<?= $value ?>"<?= $customPrefs['density'] === $value ? ' checked' : '' ?>>
                            <strong><?= portal_escape($label) ?></strong>
                            <small><?= portal_escape($description) ?></small>
                            <span class="settings-choice-state" aria-hidden="true">✓ Selected</span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <fieldset class="settings-subsection" data-custom-panel="style">
                    <legend class="settings-subsection-head"><span>Typeface</span><small>Choose one</small></legend>
                    <div class="settings-choice-grid settings-choice-grid--fonts">
                        <?php foreach ([
                            'standard' => ['Standard', 'Balanced and familiar'],
                            'system' => ['Device default', 'Fast and familiar on your device'],
                            'modern' => ['Modern', 'Clean geometric Manrope'],
                            'clear' => ['Clear reading', 'Wide, distinct letter shapes'],
                            'friendly' => ['Friendly', 'Softer rounded letterforms'],
                            'classic' => ['Classic', 'Editorial Fraunces headings'],
                            'bookish' => ['Bookish', 'Traditional Georgia headings'],
                        ] as $value => [$label, $description]): ?>
                        <label class="settings-choice settings-choice--font settings-choice--font-<?= portal_escape($value) ?>">
                            <input type="radio" name="font_style" value="<?= $value ?>"<?= $customPrefs['font_style'] === $value ? ' checked' : '' ?>>
                            <span class="settings-font-sample" aria-hidden="true">Aa</span>
                            <strong><?= portal_escape($label) ?></strong>
                            <small><?= portal_escape($description) ?></small>
                            <span class="settings-choice-state" aria-hidden="true">✓ Selected</span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <fieldset class="settings-subsection" data-custom-panel="reading">
                    <legend class="settings-subsection-head"><span>Reading line spacing</span><small>Choose one</small></legend>
                    <div class="settings-choice-grid settings-choice-grid--2">
                        <label class="settings-choice">
                            <input type="radio" name="line_spacing" value="standard"<?= $customPrefs['line_spacing'] === 'standard' ? ' checked' : '' ?>>
                            <strong>Standard</strong>
                            <small>The original text spacing</small>
                            <span class="settings-choice-state" aria-hidden="true">✓ Selected</span>
                        </label>
                        <label class="settings-choice">
                            <input type="radio" name="line_spacing" value="relaxed"<?= $customPrefs['line_spacing'] === 'relaxed' ? ' checked' : '' ?>>
                            <strong>Relaxed</strong>
                            <small>More space between lines in reading content</small>
                            <span class="settings-choice-state" aria-hidden="true">✓ Selected</span>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="settings-subsection" data-custom-panel="style">
                    <legend class="settings-subsection-head"><span>Card corners</span><small>Choose one</small></legend>
                    <div class="settings-choice-grid">
                        <?php foreach ([
                            'standard' => ['Standard', 'The original corner shape'],
                            'rounded' => ['Soft', 'Gently rounded cards'],
                        ] as $value => [$label, $description]): ?>
                        <label class="settings-choice settings-choice--corner-<?= portal_escape($value) ?>">
                            <input type="radio" name="corner_style" value="<?= $value ?>"<?= $customPrefs['corner_style'] === $value ? ' checked' : '' ?>>
                            <strong><?= portal_escape($label) ?></strong>
                            <small><?= portal_escape($description) ?></small>
                            <span class="settings-choice-state" aria-hidden="true">✓ Selected</span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <fieldset class="settings-subsection" data-custom-panel="style">
                    <legend class="settings-subsection-head"><span>Background detail</span><small>Choose one</small></legend>
                    <div class="settings-choice-grid">
                        <?php foreach ([
                            'standard' => ['Original grid', 'The portal’s subtle line texture'],
                            'glow' => ['Soft colour glow', 'Gentle palette colour around page edges'],
                            'plain' => ['Plain', 'No background pattern'],
                        ] as $value => [$label, $description]): ?>
                        <label class="settings-choice settings-choice--background-<?= portal_escape($value) ?>">
                            <input type="radio" name="background_style" value="<?= $value ?>"<?= $customPrefs['background_style'] === $value ? ' checked' : '' ?>>
                            <strong><?= portal_escape($label) ?></strong>
                            <small><?= portal_escape($description) ?></small>
                            <span class="settings-choice-state" aria-hidden="true">✓ Selected</span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <fieldset class="settings-subsection" data-custom-panel="style">
                    <legend class="settings-subsection-head"><span>Sidebar tone</span><small>Choose one</small></legend>
                    <div class="settings-choice-grid">
                        <?php foreach ([
                            'standard' => ['Palette gradient', 'Matches your selected colour'],
                            'deep' => ['Deep tone', 'A darker version of your selected colour'],
                        ] as $value => [$label, $description]): ?>
                        <label class="settings-choice settings-choice--sidebar-<?= portal_escape($value) ?>">
                            <input type="radio" name="sidebar_style" value="<?= $value ?>"<?= $customPrefs['sidebar_style'] === $value ? ' checked' : '' ?>>
                            <strong><?= portal_escape($label) ?></strong>
                            <small><?= portal_escape($description) ?></small>
                            <span class="settings-choice-state" aria-hidden="true">✓ Selected</span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <fieldset class="settings-subsection" data-custom-panel="layout">
                    <legend class="settings-subsection-head"><span>Course layout</span><small>Choose one</small></legend>
                    <div class="settings-choice-grid settings-choice-grid--2">
                        <label class="settings-choice">
                            <input type="radio" name="course_view" value="list"<?= $customPrefs['course_view'] === 'list' ? ' checked' : '' ?>>
                            <strong>List</strong>
                            <small>Wide rows with more course detail</small>
                            <span class="settings-choice-state" aria-hidden="true">✓ Selected</span>
                        </label>
                        <label class="settings-choice">
                            <input type="radio" name="course_view" value="grid"<?= $customPrefs['course_view'] === 'grid' ? ' checked' : '' ?>>
                            <strong>Grid</strong>
                            <small>Two course cards per row on larger screens</small>
                            <span class="settings-choice-state" aria-hidden="true">✓ Selected</span>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="settings-subsection" data-custom-panel="layout">
                    <legend class="settings-subsection-head"><span>Dashboard order</span><small>Choose one</small></legend>
                    <div class="settings-choice-grid settings-choice-grid--2">
                        <label class="settings-choice">
                            <input type="radio" name="dashboard_focus" value="priorities"<?= $customPrefs['dashboard_focus'] === 'priorities' ? ' checked' : '' ?>>
                            <strong><?= $isStudent ? 'Priorities first' : 'To do first' ?></strong>
                            <small><?= $isStudent ? 'Deadlines and returned work before the schedule' : 'Work queues before the schedule' ?></small>
                            <span class="settings-choice-state" aria-hidden="true">✓ Selected</span>
                        </label>
                        <label class="settings-choice">
                            <input type="radio" name="dashboard_focus" value="schedule"<?= $customPrefs['dashboard_focus'] === 'schedule' ? ' checked' : '' ?>>
                            <strong>Schedule first</strong>
                            <small>Today’s classes before work queues</small>
                            <span class="settings-choice-state" aria-hidden="true">✓ Selected</span>
                        </label>
                    </div>
                </fieldset>

                <div class="settings-subsection" data-custom-panel="reading">
                    <div class="settings-subsection-head">
                        <span>Accessibility</span>
                        <small>Turn on as many as you want</small>
                    </div>
                    <div class="settings-toggles">
                        <label class="settings-toggle">
                            <span><strong>Reduce motion</strong><small>Minimize animation and transitions</small></span>
                            <input type="checkbox" name="reduced_motion" value="1"<?= !empty($customPrefs['reduced_motion']) ? ' checked' : '' ?>>
                        </label>
                        <label class="settings-toggle">
                            <span><strong>Increase contrast</strong><small>Stronger borders, text and focus indicators</small></span>
                            <input type="checkbox" name="high_contrast" value="1"<?= !empty($customPrefs['high_contrast']) ? ' checked' : '' ?>>
                        </label>
                    </div>
                </div>

                <div class="settings-subsection" data-custom-panel="layout">
                    <div class="settings-subsection-head">
                        <span>Optional dashboard widgets</span>
                        <small>Turn on as many as you want</small>
                    </div>
                    <div class="settings-toggles">
                        <label class="settings-toggle">
                            <span><strong>Continue watching</strong><small>Show your recent video lessons on the dashboard</small></span>
                            <input type="checkbox" name="show_continue" value="1"<?= !empty($customPrefs['show_continue']) ? ' checked' : '' ?>>
                        </label>
                        <label class="settings-toggle">
                            <span><strong>Course quick access</strong><small>Show course shortcuts on the dashboard</small></span>
                            <input type="checkbox" name="show_quick_access" value="1"<?= !empty($customPrefs['show_quick_access']) ? ' checked' : '' ?>>
                        </label>
                        <label class="settings-toggle">
                            <span><strong>Bulletin widget</strong><small>Show the latest school updates on the dashboard</small></span>
                            <input type="checkbox" name="show_bulletin" value="1"<?= !empty($customPrefs['show_bulletin']) ? ' checked' : '' ?>>
                        </label>
                    </div>
                </div>

                <div class="settings-actions">
                    <button type="submit" class="settings-btn">Save customization</button>
                    <button type="submit" class="settings-btn settings-btn--secondary" name="reset_customization" value="1">Restore defaults</button>
                </div>
            </form>
        </section>

        <section class="settings-block" id="security" role="tabpanel" data-settings-panel="security">
            <div class="settings-block-head">
                <h3>Password</h3>
                <p>At least 8 characters, with a letter and a number.</p>
            </div>

            <form method="POST" class="settings-form" id="settings-password-form" novalidate>
                <?= portal_csrf_field() ?>
                <input type="hidden" name="action" value="change_password">

                <div class="settings-grid settings-grid--security">
                    <label class="settings-field settings-field--wide">
                        <span>Current password</span>
                        <span class="settings-password-wrap">
                            <input class="settings-input" type="password" name="current_password" required autocomplete="current-password">
                            <button type="button" class="settings-toggle-pass" data-toggle-password aria-label="Show password">Show</button>
                        </span>
                    </label>

                    <label class="settings-field">
                        <span>New password</span>
                        <span class="settings-password-wrap">
                            <input class="settings-input" type="password" name="new_password" id="settings-new-password"
                                   required minlength="8" autocomplete="new-password"
                                   aria-describedby="settings-pass-meter-label">
                            <button type="button" class="settings-toggle-pass" data-toggle-password aria-label="Show password">Show</button>
                        </span>
                        <div class="settings-strength" aria-hidden="true">
                            <span data-strength-bar></span>
                            <span data-strength-bar></span>
                            <span data-strength-bar></span>
                            <span data-strength-bar></span>
                        </div>
                        <span class="settings-strength-label" id="settings-pass-meter-label" data-strength-label></span>
                    </label>

                    <label class="settings-field">
                        <span>Confirm</span>
                        <span class="settings-password-wrap">
                            <input class="settings-input" type="password" name="confirm_password" required minlength="8" autocomplete="new-password">
                            <button type="button" class="settings-toggle-pass" data-toggle-password aria-label="Show password">Show</button>
                        </span>
                    </label>
                </div>

                <div class="settings-actions">
                    <button type="submit" class="settings-btn">Update password</button>
                </div>
            </form>
        </section>
    </div>
    </div>
</section>

<script>
(function () {
    'use strict';

    var settingsPage = document.querySelector('.settings-page');
    var settingsNav = document.querySelector('[data-settings-nav]');
    var settingsTabs = Array.from(document.querySelectorAll('[data-settings-tab]'));
    var settingsPanels = Array.from(document.querySelectorAll('[data-settings-panel]'));
    var validSettingsPanels = settingsPanels.map(function (panel) { return panel.getAttribute('data-settings-panel'); });

    function settingsPanelFromHash() {
        var value = window.location.hash.replace('#', '');
        return value.indexOf('tab-') === 0 ? value.slice(4) : value;
    }

    function activateSettingsPanel(panelName, updateHash) {
        if (validSettingsPanels.indexOf(panelName) === -1) panelName = 'profile';
        var activeTab = null;
        settingsTabs.forEach(function (tab) {
            var active = tab.getAttribute('data-settings-tab') === panelName;
            if (active) activeTab = tab;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            tab.setAttribute('tabindex', active ? '0' : '-1');
        });
        settingsPanels.forEach(function (panel) {
            var active = panel.getAttribute('data-settings-panel') === panelName;
            panel.hidden = !active;
            panel.classList.toggle('is-active', active);
        });
        if (settingsPage) settingsPage.setAttribute('data-active-settings-panel', panelName);
        if (updateHash && window.history && window.history.pushState) {
            window.history.pushState(null, '', '#tab-' + panelName);
        }
        if (settingsNav && activeTab) {
            window.requestAnimationFrame(function () {
                if (settingsNav.scrollWidth <= settingsNav.clientWidth) return;
                var targetLeft = activeTab.offsetLeft - ((settingsNav.clientWidth - activeTab.offsetWidth) / 2);
                var maxLeft = settingsNav.scrollWidth - settingsNav.clientWidth;
                settingsNav.scrollTo({
                    left: Math.max(0, Math.min(maxLeft, targetLeft)),
                    behavior: updateHash ? 'smooth' : 'auto'
                });
            });
        }
    }

    settingsTabs.forEach(function (tab) {
        tab.addEventListener('click', function (event) {
            event.preventDefault();
            activateSettingsPanel(tab.getAttribute('data-settings-tab'), true);
        });
        tab.addEventListener('keydown', function (event) {
            if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
            event.preventDefault();
            var index = settingsTabs.indexOf(tab);
            var direction = event.key === 'ArrowRight' ? 1 : -1;
            var next = settingsTabs[(index + direction + settingsTabs.length) % settingsTabs.length];
            activateSettingsPanel(next.getAttribute('data-settings-tab'), true);
            next.focus();
        });
    });

    window.addEventListener('hashchange', function () {
        activateSettingsPanel(settingsPanelFromHash(), false);
    });
    activateSettingsPanel(settingsPanelFromHash() || 'profile', false);

    var customizationForm = document.getElementById('customization-form');
    if (customizationForm) {
        var customTabs = Array.from(customizationForm.querySelectorAll('[data-custom-tab]'));
        var customPanels = Array.from(customizationForm.querySelectorAll('[data-custom-panel]'));
        var previewBox = customizationForm.querySelector('.settings-live-preview');
        var previewCollapse = customizationForm.querySelector('[data-preview-collapse]');
        var previewStatus = customizationForm.querySelector('[data-preview-status]');
        var previewNote = customizationForm.querySelector('[data-preview-note]');
        var previewRevert = customizationForm.querySelector('[data-preview-revert]');
        var previewRoot = document.documentElement;
        var radioNames = [
            'accent', 'text_size', 'density', 'font_style', 'corner_style',
            'line_spacing', 'background_style', 'sidebar_style', 'course_view',
            'dashboard_focus'
        ];
        var toggleNames = [
            'reduced_motion', 'high_contrast', 'show_continue',
            'show_quick_access', 'show_bulletin'
        ];

        function activateCustomCategory(category) {
            if (['colour', 'style', 'reading', 'layout'].indexOf(category) === -1) category = 'colour';
            customTabs.forEach(function (tab) {
                var active = tab.getAttribute('data-custom-tab') === category;
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
                tab.setAttribute('tabindex', active ? '0' : '-1');
            });
            customPanels.forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-custom-panel') !== category;
            });
            try {
                window.sessionStorage.setItem('portal-custom-category', category);
            } catch (error) {
                // Storage is optional; the controls still work without it.
            }
        }

        customTabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                activateCustomCategory(tab.getAttribute('data-custom-tab'));
            });
            tab.addEventListener('keydown', function (event) {
                if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
                event.preventDefault();
                var index = customTabs.indexOf(tab);
                var direction = event.key === 'ArrowRight' ? 1 : -1;
                var next = customTabs[(index + direction + customTabs.length) % customTabs.length];
                activateCustomCategory(next.getAttribute('data-custom-tab'));
                next.focus();
            });
        });

        var initialCustomCategory = 'colour';
        try {
            initialCustomCategory = window.sessionStorage.getItem('portal-custom-category') || 'colour';
        } catch (error) {
            initialCustomCategory = 'colour';
        }
        activateCustomCategory(initialCustomCategory);

        function setPreviewCollapsed(collapsed) {
            if (!previewBox || !previewCollapse) return;
            previewBox.classList.toggle('is-collapsed', collapsed);
            previewCollapse.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            previewCollapse.textContent = collapsed ? 'Show preview' : 'Hide preview';
        }

        if (previewCollapse) {
            previewCollapse.addEventListener('click', function () {
                setPreviewCollapsed(!previewBox.classList.contains('is-collapsed'));
            });
            setPreviewCollapsed(window.matchMedia('(max-width: 760px)').matches);
        }

        function readPreviewState() {
            var state = {};
            radioNames.forEach(function (name) {
                var checked = customizationForm.querySelector('input[name="' + name + '"]:checked');
                state[name] = checked ? checked.value : '';
            });
            toggleNames.forEach(function (name) {
                var input = customizationForm.querySelector('input[name="' + name + '"]');
                state[name] = !!(input && input.checked);
            });
            return state;
        }

        var savedPreviewState = readPreviewState();

        function applyPreviewState() {
            var state = readPreviewState();
            previewRoot.setAttribute('data-accent', state.accent);
            previewRoot.setAttribute('data-text-size', state.text_size);
            previewRoot.setAttribute('data-density', state.density);
            previewRoot.setAttribute('data-font-style', state.font_style);
            previewRoot.setAttribute('data-line-spacing', state.line_spacing);
            previewRoot.setAttribute('data-corner-style', state.corner_style);
            previewRoot.setAttribute('data-background-style', state.background_style);
            previewRoot.setAttribute('data-sidebar-style', state.sidebar_style);
            previewRoot.setAttribute('data-course-view', state.course_view);
            previewRoot.setAttribute('data-dashboard-focus', state.dashboard_focus);
            previewRoot.setAttribute('data-motion', state.reduced_motion ? 'reduced' : 'normal');
            previewRoot.setAttribute('data-contrast', state.high_contrast ? 'high' : 'standard');
            previewRoot.setAttribute('data-show-continue', state.show_continue ? '1' : '0');
            previewRoot.setAttribute('data-show-quick-access', state.show_quick_access ? '1' : '0');
            previewRoot.setAttribute('data-show-bulletin', state.show_bulletin ? '1' : '0');

            var dirty = JSON.stringify(state) !== JSON.stringify(savedPreviewState);
            if (previewStatus) {
                previewStatus.textContent = dirty ? 'Unsaved preview' : 'Saved settings';
                previewStatus.classList.toggle('is-dirty', dirty);
            }
            if (previewNote) {
                previewNote.textContent = dirty
                    ? 'Preview only — press Save customization to keep these changes.'
                    : 'Click any option below. Nothing changes permanently until you save.';
            }
            if (previewRevert) {
                previewRevert.disabled = !dirty;
            }
        }

        var presetButtons = Array.from(customizationForm.querySelectorAll('[data-accessibility-preset]'));

        function clearPresetStatus() {
            presetButtons.forEach(function (button) {
                button.classList.remove('is-applied');
                var status = button.querySelector('[data-preset-status]');
                if (status) status.textContent = 'Apply';
            });
        }

        customizationForm.querySelectorAll('input[type="radio"], input[type="checkbox"]').forEach(function (input) {
            input.addEventListener('change', function () {
                clearPresetStatus();
                applyPreviewState();
            });
        });

        var accessibilityPresets = {
            reading: {
                text_size: 'large',
                line_spacing: 'relaxed',
                density: 'comfortable'
            },
            clarity: {
                text_size: 'comfortable',
                density: 'comfortable',
                high_contrast: true
            },
            calm: {
                density: 'comfortable',
                background_style: 'plain',
                reduced_motion: true
            }
        };

        presetButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var preset = accessibilityPresets[button.getAttribute('data-accessibility-preset')];
                if (!preset) return;
                Object.keys(preset).forEach(function (name) {
                    var value = preset[name];
                    if (typeof value === 'boolean') {
                        var checkbox = customizationForm.querySelector('input[name="' + name + '"]');
                        if (checkbox) checkbox.checked = value;
                        return;
                    }
                    var radio = customizationForm.querySelector(
                        'input[name="' + name + '"][value="' + value + '"]'
                    );
                    if (radio) radio.checked = true;
                });
                clearPresetStatus();
                button.classList.add('is-applied');
                var status = button.querySelector('[data-preset-status]');
                if (status) status.textContent = 'Applied';
                applyPreviewState();
            });
        });

        if (previewRevert) {
            previewRevert.addEventListener('click', function () {
                clearPresetStatus();
                radioNames.forEach(function (name) {
                    customizationForm.querySelectorAll('input[name="' + name + '"]').forEach(function (input) {
                        input.checked = input.value === savedPreviewState[name];
                    });
                });
                toggleNames.forEach(function (name) {
                    var input = customizationForm.querySelector('input[name="' + name + '"]');
                    if (input) input.checked = !!savedPreviewState[name];
                });
                applyPreviewState();
            });
        }
    }

    document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var wrap = btn.closest('.settings-password-wrap');
            var input = wrap ? wrap.querySelector('input') : null;
            if (!input) return;
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.textContent = show ? 'Hide' : 'Show';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    });

    var newPass = document.getElementById('settings-new-password');
    var bars = document.querySelectorAll('[data-strength-bar]');
    var label = document.querySelector('[data-strength-label]');
    if (!newPass || !bars.length || !label) return;

    function scorePassword(value) {
        var score = 0;
        if (value.length >= 8) score += 1;
        if (value.length >= 12) score += 1;
        if (/[A-Za-z]/.test(value) && /[0-9]/.test(value)) score += 1;
        if (/[^A-Za-z0-9]/.test(value) || (/[A-Z]/.test(value) && /[a-z]/.test(value) && /[0-9]/.test(value))) score += 1;
        return Math.min(4, score);
    }

    function paintStrength() {
        var value = newPass.value || '';
        var score = value === '' ? 0 : scorePassword(value);
        var texts = ['', 'Weak', 'Fair', 'Good', 'Strong'];
        bars.forEach(function (bar, index) {
            bar.className = '';
            bar.setAttribute('data-strength-bar', '');
            if (index < score) {
                bar.classList.add('is-on', 'is-level-' + score);
            }
        });
        label.textContent = texts[score] || '';
    }

    newPass.addEventListener('input', paintStrength);
    paintStrength();
})();
</script>
<?php
$page_content = ob_get_clean();
require __DIR__ . '/../layout.php';
