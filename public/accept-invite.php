<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if (portal_is_logged_in()) {
    portal_redirect('dashboard.php');
}

$layout_variant = 'auth';
$page_title = 'Accept invite | ' . portal_school_name();
$auth_eyebrow = 'Create account';
$auth_heading = portal_school_name();
$auth_description = 'Finish setting up your student account with the invite you were sent.';

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';
$invite = portal_invite_find_valid($token);
$tokenValid = $invite !== null;
$yearGroupOptions = portal_year_group_options();

$nameValue = '';
$yearValue = 'Year 11';
$usernameValue = '';
if ($invite !== null) {
    $nameValue = trim((string) ($invite['locked_name'] ?? ''));
    $lockedYear = trim((string) ($invite['locked_year'] ?? ''));
    if ($lockedYear !== '') {
        $yearValue = $lockedYear;
    }
}

$nameLocked = $invite !== null && trim((string) ($invite['locked_name'] ?? '')) !== '';
$yearLocked = $invite !== null && trim((string) ($invite['locked_year'] ?? '')) !== '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!portal_verify_csrf()) {
        $error = 'Your session expired. Please try again.';
        $invite = portal_invite_find_valid($token);
        $tokenValid = $invite !== null;
    } else {
        $token = trim((string) ($_POST['token'] ?? ''));
        $usernameValue = trim((string) ($_POST['username'] ?? ''));
        $nameValue = trim((string) ($_POST['name'] ?? ''));
        $yearValue = trim((string) ($_POST['year'] ?? 'Year 11'));
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if ($password !== $confirm) {
            $error = 'Passwords do not match.';
            $invite = portal_invite_find_valid($token);
            $tokenValid = $invite !== null;
            if ($invite !== null) {
                $nameLocked = trim((string) ($invite['locked_name'] ?? '')) !== '';
                $yearLocked = trim((string) ($invite['locked_year'] ?? '')) !== '';
                if ($nameLocked) {
                    $nameValue = trim((string) $invite['locked_name']);
                }
                if ($yearLocked) {
                    $yearValue = trim((string) $invite['locked_year']);
                }
            }
        } else {
            $result = portal_invite_accept($token, [
                'email' => (string) ($_POST['email'] ?? ''),
                'username' => $usernameValue,
                'password' => $password,
                'name' => $nameValue,
                'year' => $yearValue,
            ], portal_client_ip());

            if (!empty($result['ok']) && empty($result['error'])) {
                portal_redirect('dashboard.php');
            }
            if (!empty($result['ok']) && !empty($result['error'])) {
                $_SESSION['login_flash'] = ['success', (string) $result['error']];
                portal_redirect('login.php');
            }

            $error = (string) ($result['error'] ?? 'Could not create your account.');
            $invite = portal_invite_find_valid($token);
            $tokenValid = $invite !== null;
            if ($invite !== null) {
                $nameLocked = trim((string) ($invite['locked_name'] ?? '')) !== '';
                $yearLocked = trim((string) ($invite['locked_year'] ?? '')) !== '';
                if ($nameLocked) {
                    $nameValue = trim((string) $invite['locked_name']);
                }
                if ($yearLocked) {
                    $yearValue = trim((string) $invite['locked_year']);
                }
            }
        }
    }
}

$confirmValue = '';

ob_start();
?>
<div class="login-stack">
    <div class="login-intro">
        <p class="eyebrow">Student invite</p>
        <h2>Create your account</h2>
        <?php if ($tokenValid && $invite !== null): ?>
            <?php
                $inviteCourseTitles = array_values(array_filter(array_map(
                    'strval',
                    (array) ($invite['course_titles'] ?? [])
                )));
                if ($inviteCourseTitles === [] && trim((string) ($invite['course_title'] ?? '')) !== '') {
                    $inviteCourseTitles = [(string) $invite['course_title']];
                }
            ?>
            <p class="login-copy">
                <?php if (count($inviteCourseTitles) <= 1): ?>
                    You are joining
                    <strong><?= portal_escape((string) ($inviteCourseTitles[0] ?? 'your course')) ?></strong>.
                <?php else: ?>
                    You are joining <strong><?= count($inviteCourseTitles) ?> courses</strong>:
                    <?= portal_escape(implode(', ', $inviteCourseTitles)) ?>.
                <?php endif; ?>
                This invite is for <strong><?= portal_escape((string) $invite['email']) ?></strong> only.
            </p>
        <?php else: ?>
            <p class="login-copy">Open the invite link from your email or admin to continue.</p>
        <?php endif; ?>
    </div>

    <?php if (!$tokenValid): ?>
        <div class="auth-message error">
            <?= portal_icon('lock', 'auth-message-icon') ?>
            <span><?= portal_escape($error !== '' ? $error : 'This invite link is invalid, used, or has expired. Ask your admin for a new invite.') ?></span>
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

        <form class="login-form" method="post" action="accept-invite.php" autocomplete="off" novalidate>
            <?= portal_csrf_field() ?>
            <input type="hidden" name="token" value="<?= portal_escape($token) ?>">

            <label class="login-field">
                <span>Email</span>
                <span class="login-input">
                    <?= portal_icon('user', 'field-icon') ?>
                    <input type="email" name="email" required readonly
                           value="<?= portal_escape((string) $invite['email']) ?>"
                           autocomplete="email">
                </span>
            </label>

            <label class="login-field">
                <span>Full name</span>
                <span class="login-input">
                    <?= portal_icon('user', 'field-icon') ?>
                    <input type="text" name="name" required maxlength="200"
                           value="<?= portal_escape($nameValue) ?>"
                           <?= $nameLocked ? 'readonly' : '' ?>
                           autocomplete="name">
                </span>
            </label>

            <label class="login-field">
                <span>Username</span>
                <span class="login-input">
                    <?= portal_icon('user', 'field-icon') ?>
                    <input type="text" name="username" required minlength="3" maxlength="40"
                           pattern="[A-Za-z0-9._\-]+"
                           value="<?= portal_escape($usernameValue) ?>"
                           autocomplete="username"
                           placeholder="e.g. jsmith">
                </span>
            </label>

            <label class="login-field">
                <span>Year group</span>
                <?php if ($yearLocked): ?>
                    <input type="hidden" name="year" value="<?= portal_escape($yearValue) ?>">
                    <span class="login-input">
                        <?= portal_icon('award', 'field-icon') ?>
                        <input type="text" readonly value="<?= portal_escape($yearValue) ?>">
                    </span>
                <?php else: ?>
                    <span class="login-input">
                        <?= portal_icon('award', 'field-icon') ?>
                        <select name="year" required>
                            <?php foreach ($yearGroupOptions as $yr): ?>
                            <option value="<?= portal_escape($yr) ?>"<?= $yr === $yearValue ? ' selected' : '' ?>><?= portal_escape($yr) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </span>
                <?php endif; ?>
            </label>

            <label class="login-field">
                <span>Password</span>
                <span class="login-input">
                    <?= portal_icon('lock', 'field-icon') ?>
                    <input type="password" name="password" required minlength="8" autocomplete="new-password">
                </span>
            </label>

            <label class="login-field">
                <span>Confirm password</span>
                <span class="login-input">
                    <?= portal_icon('lock', 'field-icon') ?>
                    <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password">
                </span>
            </label>
            <p class="login-copy" style="margin-top:-0.5rem;">At least 8 characters, including a letter and a number.</p>

            <button class="login-button" type="submit">
                <span>Create account</span>
                <?= portal_icon('arrow-right', 'button-icon') ?>
            </button>
        </form>

        <p class="login-meta">
            <a href="login.php">Already have an account? Sign in</a>
        </p>
    <?php endif; ?>
</div>
<?php
$page_content = ob_get_clean();

require __DIR__ . '/../layout.php';
