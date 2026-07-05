<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

portal_require_login();

$me  = portal_current_user();
$meId = (int) ($me['id'] ?? 0);
$meDb = $meId > 0 ? portal_find_user_by_id($meId) : null;

if ($meDb === null) {
    portal_logout();
    portal_redirect('login.php');
}

$me = $meDb;
$db  = portal_db();

if (empty($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['_csrf'];

$flash = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['_token'] ?? '');
    if (!hash_equals($csrfToken, $token)) {
        portal_redirect('settings.php');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'update_profile') {
        $name = substr(trim((string) ($_POST['name'] ?? '')), 0, 150);
        $year = substr(trim((string) ($_POST['year'] ?? '')), 0, 50);

        if ($name === '') {
            $_SESSION['settings_flash'] = ['error', 'Name cannot be empty.'];
        } else {
            // Recompute initials
            $parts    = preg_split('/\s+/', $name) ?: [];
            $initials = strtoupper(
                substr($parts[0] ?? 'S', 0, 1) . substr($parts[1] ?? 'T', 0, 1)
            );

            $db->prepare("UPDATE users SET name = ?, year = ?, initials = ? WHERE id = ?")
               ->execute([$name, $year, $initials, (int) $me['id']]);

            // Refresh session
            $fresh = portal_find_user_by_id((int) $me['id']);
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
        }
        portal_redirect('settings.php');
    }

    if ($action === 'change_password') {
        $current  = (string) ($_POST['current_password'] ?? '');
        $newPass  = (string) ($_POST['new_password'] ?? '');
        $confirm  = (string) ($_POST['confirm_password'] ?? '');

        $row = portal_find_user_by_id((int) $me['id']);

        if (!$row || !password_verify($current, $row['password_hash'])) {
            $_SESSION['settings_flash'] = ['error', 'Current password is incorrect.'];
        } elseif (strlen($newPass) < 6) {
            $_SESSION['settings_flash'] = ['error', 'New password must be at least 6 characters.'];
        } elseif ($newPass !== $confirm) {
            $_SESSION['settings_flash'] = ['error', 'New passwords do not match.'];
        } else {
            $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
               ->execute([password_hash($newPass, PASSWORD_DEFAULT), (int) $me['id']]);
            $_SESSION['settings_flash'] = ['success', 'Password changed successfully.'];
        }
        portal_redirect('settings.php');
    }
}

if (isset($_SESSION['settings_flash'])) {
    $flash = $_SESSION['settings_flash'];
    unset($_SESSION['settings_flash']);
}

// Refresh user from DB
$me = portal_find_user_by_id((int) $me['id']) ?? $me;

$page_title       = 'Settings | ' . portal_school_name();
$active_page      = 'settings';
$page_eyebrow     = 'Account';
$page_heading     = 'Settings';
$page_description = 'Update your name, password, and account details.';

ob_start();
?>
<section class="settings-layout">

    <?php if ($flash): ?>
    <div class="admin-flash <?= $flash[0] === 'success' ? 'success' : 'error' ?>" style="grid-column:1/-1;">
        <?= portal_escape($flash[1]) ?>
    </div>
    <?php endif; ?>

    <!-- Profile card -->
    <article class="card-shell">
        <div class="section-head">
            <div>
                <p class="eyebrow">Profile</p>
                <h3 class="card-title">Your details</h3>
            </div>
            <div class="settings-avatar"><?= portal_escape($me['initials']) ?></div>
        </div>

        <form method="POST" class="settings-form-live">
            <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
            <input type="hidden" name="action" value="update_profile">

            <label class="settings-field">
                <span>Display name</span>
                <input class="settings-input" type="text" name="name"
                       value="<?= portal_escape($me['name']) ?>" required maxlength="150">
            </label>
            <label class="settings-field">
                <span>Year group</span>
                <input class="settings-input" type="text" name="year"
                       value="<?= portal_escape($me['year']) ?>" maxlength="50">
            </label>
            <label class="settings-field">
                <span>Username</span>
                <input class="settings-input" type="text" value="<?= portal_escape($me['username']) ?>" disabled>
            </label>
            <label class="settings-field">
                <span>Email</span>
                <input class="settings-input" type="email" value="<?= portal_escape($me['email']) ?>" disabled>
            </label>
            <label class="settings-field">
                <span>Role</span>
                <input class="settings-input" type="text" value="<?= portal_escape(ucfirst($me['role'])) ?>" disabled>
            </label>

            <div class="button-row">
                <button type="submit" class="button">Save changes</button>
            </div>
        </form>
    </article>

    <!-- Security card -->
    <div class="stack">
        <article class="card-shell">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Security</p>
                    <h3 class="card-title">Change password</h3>
                </div>
            </div>

            <form method="POST" class="settings-form-live">
                <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                <input type="hidden" name="action" value="change_password">

                <label class="settings-field">
                    <span>Current password</span>
                    <input class="settings-input" type="password" name="current_password" required autocomplete="current-password">
                </label>
                <label class="settings-field">
                    <span>New password</span>
                    <input class="settings-input" type="password" name="new_password" required minlength="6" autocomplete="new-password">
                </label>
                <label class="settings-field">
                    <span>Confirm new password</span>
                    <input class="settings-input" type="password" name="confirm_password" required autocomplete="new-password">
                </label>

                <div class="button-row">
                    <button type="submit" class="button">Change password</button>
                </div>
            </form>
        </article>

        <article class="card-shell">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Session</p>
                    <h3 class="card-title">Sign-in info</h3>
                </div>
            </div>
            <div class="settings-info-rows">
                <div class="settings-info-row">
                    <span>Last sign-in</span>
                    <strong><?= portal_escape(portal_login_time_text()) ?></strong>
                </div>
                <div class="settings-info-row">
                    <span>Programme</span>
                    <strong><?= portal_escape($me['programme']) ?></strong>
                </div>
            </div>
            <div class="button-row" style="margin-top:14px;">
                <a class="button-secondary" href="logout.php">Sign out</a>
            </div>
        </article>
    </div>

</section>
<?php
$page_content = ob_get_clean();
require __DIR__ . '/../layout.php';
