<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if (portal_is_logged_in()) {
    portal_redirect('courses.php');
}

$layout_variant = 'auth';
$page_title = 'Login | ' . portal_school_name();
$auth_eyebrow = 'Student access';
$auth_heading = portal_school_name();
$auth_description = 'Sign in to see your lessons, deadlines, school updates, and everything coming up this week.';

$identifier = '';
$error = '';
$loggedOut = isset($_GET['logged_out']) && $_GET['logged_out'] === '1';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $identifier = trim((string) ($_POST['identifier'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $clientIp = portal_client_ip();

    if (portal_login_is_locked($clientIp)) {
        portal_log_security_event('login_throttled', 'medium', 'Too many failed sign-in attempts from this location');
        $error = 'Too many failed sign-in attempts. Please wait about 15 minutes and try again.';
    } elseif ($identifier === '' || $password === '') {
        $error = 'Enter your username or email and your password.';
    } elseif (!portal_attempt_login($identifier, $password)) {
        portal_login_record_failure($clientIp);
        $safeId = substr(preg_replace('/\s+/', ' ', $identifier) ?? $identifier, 0, 80);
        portal_log_security_event(
            'failed_login',
            'medium',
            'Failed login for username: ' . $safeId,
            null
        );
        $error = 'That username or password does not look right.';
    } else {
        portal_login_clear_attempts($clientIp);
        portal_redirect(portal_consume_intended_path());
    }
}

ob_start();
?>
<div class="login-intro">
    <p class="eyebrow">Sign in</p>
    <h2>Welcome back</h2>
    <p class="login-copy">Enter your school username and password to open your dashboard.</p>
</div>

<?php if ($loggedOut): ?>
    <div class="auth-message success"><span>You have been signed out.</span></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="auth-message error">
        <?= portal_icon('lock', 'auth-message-icon') ?>
        <span><?= portal_escape($error) ?></span>
    </div>
<?php endif; ?>

<form class="login-form" method="post" action="login.php" novalidate>
    <label class="login-field">
        <span>Username or email</span>
        <span class="login-input">
            <?= portal_icon('user', 'field-icon') ?>
            <input type="text" name="identifier" value="<?= portal_escape($identifier) ?>" autocomplete="username" required>
        </span>
    </label>

    <label class="login-field">
        <span>Password</span>
        <span class="login-input">
            <?= portal_icon('lock', 'field-icon') ?>
            <input type="password" name="password" autocomplete="current-password" required>
        </span>
    </label>

    <button class="login-button" type="submit">
        <span>Sign in</span>
        <?= portal_icon('arrow-right', 'button-icon') ?>
    </button>
</form>
<?php
$page_content = ob_get_clean();

require __DIR__ . '/../layout.php';
