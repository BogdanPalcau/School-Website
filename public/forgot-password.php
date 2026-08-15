<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if (portal_is_logged_in()) {
    portal_redirect('dashboard.php');
}

$layout_variant = 'auth';
$page_title = 'Forgot password | ' . portal_school_name();
$auth_eyebrow = 'Account recovery';
$auth_heading = portal_school_name();
$auth_description = 'Request a link to choose a new password for your portal account.';

$email = '';
$error = '';
$done = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!portal_verify_csrf()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $clientIp = portal_client_ip();

        if (portal_password_reset_is_locked($clientIp)) {
            portal_log_security_event(
                'password_reset_throttled',
                'medium',
                'Too many password reset requests from this location'
            );
            $error = 'Too many reset requests. Please wait about 15 minutes and try again.';
        } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter the email address on your account.';
        } else {
            portal_password_reset_request($email, $clientIp);
            $done = true;
        }
    }
}

ob_start();
?>
<div class="login-stack">
    <div class="login-intro">
        <p class="eyebrow">Forgot password</p>
        <h2>Reset your password</h2>
        <p class="login-copy">Enter the email on your account. If it matches a portal user, we will send a reset link.</p>
    </div>

    <?php if ($done): ?>
        <div class="auth-message success">
            <span>If that email is on file, we sent a reset link. Check your inbox and spam folder.</span>
        </div>
        <p class="login-meta">
            <a href="login.php">Back to sign in</a>
        </p>
    <?php else: ?>
        <?php if ($error !== ''): ?>
        <div class="auth-message error">
            <?= portal_icon('lock', 'auth-message-icon') ?>
            <span><?= portal_escape($error) ?></span>
        </div>
        <?php endif; ?>

        <form class="login-form" method="post" action="forgot-password.php" novalidate>
            <?= portal_csrf_field() ?>
            <label class="login-field">
                <span>Email</span>
                <span class="login-input">
                    <?= portal_icon('mail', 'field-icon') ?>
                    <input type="email" name="email" value="<?= portal_escape($email) ?>" autocomplete="email" required maxlength="190">
                </span>
            </label>

            <button class="login-button" type="submit">
                <span>Send reset link</span>
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
