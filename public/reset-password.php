<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if (portal_is_logged_in()) {
    portal_redirect('dashboard.php');
}

$layout_variant = 'auth';
$page_title = 'Choose a new password | ' . portal_school_name();
$auth_eyebrow = 'Account recovery';
$auth_heading = portal_school_name();
$auth_description = 'Set a new password for your portal account.';

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';
$tokenValid = portal_password_reset_find_valid($token) !== null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!portal_verify_csrf()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $token = trim((string) ($_POST['token'] ?? ''));
        $newPass = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if ($newPass !== $confirm) {
            $error = 'New passwords do not match.';
            $tokenValid = portal_password_reset_find_valid($token) !== null;
        } else {
            $consumeError = portal_password_reset_consume($token, $newPass);
            if ($consumeError !== null) {
                $error = $consumeError;
                $tokenValid = portal_password_reset_find_valid($token) !== null;
            } else {
                $_SESSION['login_flash'] = ['success', 'Password updated. You can sign in with your new password.'];
                portal_redirect('login.php');
            }
        }
    }
}

ob_start();
?>
<div class="login-stack">
    <div class="login-intro">
        <p class="eyebrow">Reset password</p>
        <h2>Choose a new password</h2>
        <p class="login-copy">Use at least 8 characters, including a letter and a number.</p>
    </div>

    <?php if (!$tokenValid): ?>
        <div class="auth-message error">
            <?= portal_icon('lock', 'auth-message-icon') ?>
            <span><?= portal_escape($error !== '' ? $error : 'This reset link is invalid or has expired. Request a new one.') ?></span>
        </div>
        <p class="login-meta">
            <a href="forgot-password.php">Request a new reset link</a>
            <span aria-hidden="true">·</span>
            <a href="login.php">Back to sign in</a>
        </p>
    <?php else: ?>
        <?php if ($error !== ''): ?>
        <div class="auth-message error">
            <?= portal_icon('lock', 'auth-message-icon') ?>
            <span><?= portal_escape($error) ?></span>
        </div>
        <?php endif; ?>

        <form class="login-form" method="post" action="reset-password.php" novalidate>
            <?= portal_csrf_field() ?>
            <input type="hidden" name="token" value="<?= portal_escape($token) ?>">

            <label class="login-field">
                <span>New password</span>
                <span class="login-input">
                    <?= portal_icon('lock', 'field-icon') ?>
                    <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
                </span>
            </label>

            <label class="login-field">
                <span>Confirm password</span>
                <span class="login-input">
                    <?= portal_icon('lock', 'field-icon') ?>
                    <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password">
                </span>
            </label>

            <button class="login-button" type="submit">
                <span>Update password</span>
                <?= portal_icon('arrow-right', 'button-icon') ?>
            </button>
        </form>

        <p class="login-meta">
            <a href="login.php">Back to sign in</a>
        </p>
    <?php endif; ?>
</div>
<?php
$page_content = ob_get_clean();

require __DIR__ . '/../layout.php';
