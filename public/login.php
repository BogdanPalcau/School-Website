<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if (portal_is_logged_in()) {
    portal_redirect('dashboard.php');
}

$layout_variant = 'auth';
$page_title = 'Login | ' . portal_school_name();
$auth_eyebrow = 'Student access';
$auth_heading = portal_school_name();
$auth_description = 'Sign in to see your lessons, deadlines, school updates, and everything coming up this week.';

$identifier = '';
$error = '';
$loggedOut = isset($_GET['logged_out']) && $_GET['logged_out'] === '1';
$showForgot = isset($_GET['forgot']) && $_GET['forgot'] === '1';
$flash = null;
if (isset($_SESSION['login_flash']) && is_array($_SESSION['login_flash'])) {
    $flash = $_SESSION['login_flash'];
    unset($_SESSION['login_flash']);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $identifier = trim((string) ($_POST['identifier'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $clientIp = portal_client_ip();

    if (portal_login_is_locked($clientIp)) {
        $safeId = substr(preg_replace('/\s+/', ' ', $identifier) ?? $identifier, 0, 80);
        portal_log_security_event(
            'login_throttled',
            'medium',
            'Too many failed sign-in attempts from this location'
                . ($safeId !== '' ? ' (tried: ' . $safeId . ')' : ''),
            null,
            $safeId !== '' ? $safeId : null
        );
        $error = 'Too many failed sign-in attempts. Please wait about 15 minutes and try again.';
    } elseif ($identifier === '' || $password === '') {
        $error = 'Enter your username or email and your password.';
    } elseif (!portal_attempt_login($identifier, $password)) {
        portal_login_record_failure($clientIp);
        $safeId = substr(preg_replace('/\s+/', ' ', $identifier) ?? $identifier, 0, 80);
        $matched = $safeId !== '' ? portal_find_user($safeId) : null;
        portal_log_security_event(
            'failed_login',
            'medium',
            'Failed login for username: ' . $safeId,
            $matched !== null ? (int) $matched['id'] : null,
            $matched !== null ? (string) $matched['username'] : $safeId
        );
        $error = 'That username or password does not look right.';
    } else {
        portal_login_clear_attempts($clientIp);
        portal_redirect(portal_consume_intended_path());
    }
}

ob_start();
?>
<div class="login-deck<?= $showForgot ? ' is-forgot' : '' ?>" data-auth-deck>
    <div class="login-deck-viewport">
        <div class="login-deck-track">
            <div class="login-stack login-card-face" data-auth-panel="signin" <?= $showForgot ? 'aria-hidden="true" inert' : '' ?>>
                <span class="login-access-pill">
                    <?= portal_icon('lock') ?>
                    <span>Invitation-only portal</span>
                </span>

                <div class="login-intro">
                    <h2>Welcome back</h2>
                    <p class="login-copy">Enter your school username and password to open your dashboard.</p>
                    <p class="login-copy">Have an invite link? Open it from your email or your admin — accounts are invitation-only.</p>
                </div>

                <?php if ($loggedOut): ?>
                    <div class="auth-message success"><span>You have been signed out.</span></div>
                <?php endif; ?>

                <?php if ($flash !== null && ($flash[0] ?? '') === 'success'): ?>
                    <div class="auth-message success"><span><?= portal_escape((string) ($flash[1] ?? '')) ?></span></div>
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

                <p class="login-meta">
                    <a href="forgot-password.php" data-auth-show="forgot">Forgot password?</a>
                </p>

                <p class="login-note">Don't have login details? Accounts are set up by <?= portal_escape(portal_school_short_name()) ?> &mdash; ask your teacher or the school office.</p>
            </div>

            <div class="login-stack login-card-face" data-auth-panel="forgot" <?= $showForgot ? '' : 'aria-hidden="true" inert' ?>>
                <span class="login-access-pill">
                    <?= portal_icon('lock') ?>
                    <span>Password reset</span>
                </span>

                <div class="login-intro">
                    <h2>Forgot password</h2>
                    <p class="login-copy">Enter the email on your account. If it matches a portal user, we will send a reset link.</p>
                </div>

                <form class="login-form" method="post" action="forgot-password.php" novalidate>
                    <?= portal_csrf_field() ?>
                    <label class="login-field">
                        <span>Email</span>
                        <span class="login-input">
                            <?= portal_icon('mail', 'field-icon') ?>
                            <input type="email" name="email" autocomplete="email" required maxlength="190">
                        </span>
                    </label>

                    <button class="login-button" type="submit">
                        <span>Send reset link</span>
                        <?= portal_icon('arrow-right', 'button-icon') ?>
                    </button>
                </form>

                <p class="login-meta">
                    <a href="login.php" data-auth-show="signin">Back to sign in</a>
                </p>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var deck = document.querySelector('[data-auth-deck]');
    if (!deck) return;

    var viewport = deck.querySelector('.login-deck-viewport');
    var signin = deck.querySelector('[data-auth-panel="signin"]');
    var forgot = deck.querySelector('[data-auth-panel="forgot"]');
    if (!viewport || !signin || !forgot) return;

    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
        || document.documentElement.getAttribute('data-motion') === 'reduced';

    function setHeight() {
        viewport.style.height = Math.max(signin.scrollHeight, forgot.scrollHeight) + 'px';
    }

    function show(which, options) {
        var forgotMode = which === 'forgot';
        deck.classList.toggle('is-forgot', forgotMode);
        signin.setAttribute('aria-hidden', forgotMode ? 'true' : 'false');
        forgot.setAttribute('aria-hidden', forgotMode ? 'false' : 'true');
        if (forgotMode) {
            signin.setAttribute('inert', '');
            forgot.removeAttribute('inert');
        } else {
            forgot.setAttribute('inert', '');
            signin.removeAttribute('inert');
        }
        setHeight();

        var skipFocus = options && options.skipFocus;
        if (!skipFocus) {
            var field = forgotMode
                ? forgot.querySelector('input[name="email"]')
                : signin.querySelector('input[name="identifier"]');
            window.setTimeout(function () {
                if (field) field.focus();
            }, reduceMotion ? 0 : 420);
        }

        if (window.history && window.history.replaceState) {
            try {
                var url = new URL(window.location.href);
                if (forgotMode) url.searchParams.set('forgot', '1');
                else url.searchParams.delete('forgot');
                window.history.replaceState({}, '', url);
            } catch (err) {}
        }
    }

    deck.querySelectorAll('[data-auth-show]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            show(link.getAttribute('data-auth-show'));
        });
    });

    window.addEventListener('resize', setHeight);
    show(deck.classList.contains('is-forgot') ? 'forgot' : 'signin', { skipFocus: true });
})();
</script>
<?php
$page_content = ob_get_clean();

require __DIR__ . '/../layout.php';
