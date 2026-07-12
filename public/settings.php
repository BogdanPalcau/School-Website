<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

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
$isStaffAccount = in_array($role, ['teacher', 'admin', 'owner'], true);
$yearOptions = portal_year_group_options();
$prefs = portal_user_preferences($meId);

$flash = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!portal_verify_csrf()) {
        $_SESSION['settings_flash'] = ['error', 'Your session expired. Please try again.'];
        portal_redirect('settings.php');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'update_profile') {
        $name = substr(trim((string) ($_POST['name'] ?? '')), 0, 150);
        $programme = substr(trim((string) ($_POST['programme'] ?? '')), 0, 100);
        $year = substr(trim((string) ($_POST['year'] ?? '')), 0, 50);
        $email = strtolower(substr(trim((string) ($_POST['email'] ?? '')), 0, 190));

        if ($name === '') {
            $_SESSION['settings_flash'] = ['error', 'Name cannot be empty.'];
            portal_redirect('settings.php');
        }

        if ($isStudent) {
            if ($year === '' || (!in_array($year, $yearOptions, true) && $year !== (string) $me['year'])) {
                if (!in_array($year, $yearOptions, true)) {
                    $year = in_array((string) $me['year'], $yearOptions, true)
                        ? (string) $me['year']
                        : 'Other';
                }
            }
        } else {
            $year = (string) ($me['year'] ?? '');
        }

        if ($isOwner && $email !== '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $_SESSION['settings_flash'] = ['error', 'Enter a valid email address.'];
                portal_redirect('settings.php');
            }
            $dup = $db->prepare("SELECT id FROM users WHERE LOWER(email) = ? AND id != ? LIMIT 1");
            $dup->execute([$email, $meId]);
            if ($dup->fetch()) {
                $_SESSION['settings_flash'] = ['error', 'That email is already used by another account.'];
                portal_redirect('settings.php');
            }
        } else {
            $email = (string) $me['email'];
        }

        $parts = preg_split('/\s+/', $name) ?: [];
        $initials = strtoupper(
            substr($parts[0] ?? 'S', 0, 1) . substr($parts[1] ?? 'T', 0, 1)
        );

        $db->prepare("UPDATE users SET name = ?, year = ?, programme = ?, initials = ?, email = ? WHERE id = ?")
           ->execute([$name, $year, $programme, $initials, $email, $meId]);

        $fresh = portal_find_user_by_id($meId);
        if ($fresh) {
            $_SESSION['portal_user'] = [
                'id'        => (int) $fresh['id'],
                'username'  => $fresh['username'],
                'email'     => $fresh['email'],
                'name'      => $fresh['name'],
                'year'      => $fresh['year'],
                'programme' => $fresh['programme'],
                'initials'  => $fresh['initials'],
                'role'      => $fresh['role'],
            ];
        }
        $_SESSION['settings_flash'] = ['success', 'Profile updated.'];
        portal_redirect('settings.php#profile');
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
            $_SESSION['settings_flash'] = ['success', 'Password changed successfully.'];
        }
        portal_redirect('settings.php#security');
    }

    if ($action === 'update_notifications') {
        portal_save_user_preferences($meId, [
            'notify_grades'        => isset($_POST['notify_grades']) ? 1 : 0,
            'notify_qa'            => isset($_POST['notify_qa']) ? 1 : 0,
            'notify_announcements' => isset($_POST['notify_announcements']) ? 1 : 0,
        ]);
        $_SESSION['settings_flash'] = ['success', 'Notification preferences saved.'];
        portal_redirect('settings.php#notifications');
    }

    portal_redirect('settings.php');
}

if (isset($_SESSION['settings_flash'])) {
    $flash = $_SESSION['settings_flash'];
    unset($_SESSION['settings_flash']);
}

$me = portal_find_user_by_id($meId) ?? $me;
$prefs = portal_user_preferences($meId);
$currentYear = (string) ($me['year'] ?? '');
if ($currentYear !== '' && !in_array($currentYear, $yearOptions, true)) {
    $yearOptions[] = $currentYear;
}

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

    <div class="settings-shell">
        <section class="settings-block" id="profile">
            <div class="settings-block-head">
                <h3>Profile</h3>
                <p>What others see across the portal.</p>
            </div>

            <form method="POST" class="settings-form" novalidate>
                <?= portal_csrf_field() ?>
                <input type="hidden" name="action" value="update_profile">

                <div class="settings-grid">
                    <label class="settings-field">
                        <span>Display name</span>
                        <input class="settings-input" type="text" name="name"
                               value="<?= portal_escape((string) $me['name']) ?>" required maxlength="150" autocomplete="name">
                    </label>

                    <?php if ($isStudent): ?>
                    <label class="settings-field">
                        <span>Year group</span>
                        <select class="settings-input" name="year">
                            <?php foreach ($yearOptions as $opt): ?>
                                <option value="<?= portal_escape($opt) ?>"<?= $currentYear === $opt ? ' selected' : '' ?>><?= portal_escape($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php endif; ?>

                    <label class="settings-field<?= $isStudent ? '' : ' settings-field--wide' ?>">
                        <span><?= $isStaffAccount ? 'Subject focus' : 'Programme' ?></span>
                        <input class="settings-input" type="text" name="programme"
                               value="<?= portal_escape((string) ($me['programme'] ?? '')) ?>" maxlength="100"
                               placeholder="<?= $isStaffAccount ? 'e.g. Mathematics' : 'e.g. STEM pathway' ?>">
                    </label>

                    <?php if ($isOwner): ?>
                    <label class="settings-field settings-field--wide">
                        <span>Email</span>
                        <input class="settings-input" type="email" name="email"
                               value="<?= portal_escape((string) $me['email']) ?>" required maxlength="190" autocomplete="email">
                    </label>
                    <?php endif; ?>
                </div>

                <div class="settings-meta-list">
                    <div>
                        <span>Username</span>
                        <strong><?= portal_escape((string) $me['username']) ?></strong>
                    </div>
                    <?php if (!$isOwner): ?>
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

        <section class="settings-block" id="notifications">
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
                            <strong>Lesson Q&amp;A replies</strong>
                            <small>When a teacher answers your question</small>
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
                </div>

                <div class="settings-actions">
                    <button type="submit" class="settings-btn">Save alerts</button>
                </div>
            </form>
        </section>

        <section class="settings-block" id="security">
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
</section>

<script>
(function () {
    'use strict';

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
