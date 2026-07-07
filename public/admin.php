<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../course_catalog.php';

portal_require_admin();

$currentUser = portal_current_user();
$isOwner     = portal_is_owner();
$pdo         = portal_db();

$flash = [];

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!portal_verify_csrf()) {
        $_SESSION['admin_flash'] = ['error', 'Your session expired. Please try that again.'];
        portal_redirect('admin.php');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_user') {
        $username  = trim((string) ($_POST['username'] ?? ''));
        $email     = trim((string) ($_POST['email'] ?? ''));
        $name      = trim((string) ($_POST['name'] ?? ''));
        $year      = trim((string) ($_POST['year'] ?? 'Year 11'));
        $programme = trim((string) ($_POST['programme'] ?? 'General'));
        $password  = (string) ($_POST['password'] ?? '');
        $newRole   = $isOwner ? (string) ($_POST['role'] ?? 'student') : 'student';

        if (!in_array($newRole, ['admin', 'supervisor', 'teacher', 'student'], true)) {
            $newRole = 'student';
        }

        // Build initials from name
        $parts    = preg_split('/\s+/', $name) ?: [];
        $initials = strtoupper(substr($parts[0] ?? 'S', 0, 1) . substr($parts[1] ?? 'T', 0, 1));

        if ($username === '' || $email === '' || $name === '' || $password === '') {
            $_SESSION['admin_flash'] = ['error', 'All fields are required.'];
        } elseif (strlen($password) < 6) {
            $_SESSION['admin_flash'] = ['error', 'Password must be at least 6 characters.'];
        } else {
            try {
                $pdo->prepare("
                    INSERT INTO users (username, email, password_hash, name, year, programme, initials, role)
                    VALUES (?,?,?,?,?,?,?,?)
                ")->execute([
                    $username, $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $name, $year, $programme, $initials, $newRole,
                ]);
                $_SESSION['admin_flash'] = ['success', "Account for {$name} created successfully."];
            } catch (\PDOException $e) {
                $msg = str_contains($e->getMessage(), 'UNIQUE') ? 'Username or email already in use.' : 'Could not create account.';
                $_SESSION['admin_flash'] = ['error', $msg];
            }
        }
        portal_redirect('admin.php');
    }

    if ($action === 'delete_user') {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $target   = portal_find_user_by_id($targetId);

        if (!$target) {
            $_SESSION['admin_flash'] = ['error', 'User not found.'];
        } elseif ($targetId === (int) $currentUser['id']) {
            $_SESSION['admin_flash'] = ['error', 'You cannot delete your own account.'];
        } elseif ($target['role'] === 'owner') {
            $_SESSION['admin_flash'] = ['error', 'Owner accounts cannot be deleted.'];
        } elseif ($target['role'] === 'admin' && !$isOwner) {
            $_SESSION['admin_flash'] = ['error', 'Only the owner can delete admin accounts.'];
        } else {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$targetId]);
            $_SESSION['admin_flash'] = ['success', "Account for {$target['name']} deleted."];
        }
        portal_redirect('admin.php');
    }

    if ($action === 'change_role' && $isOwner) {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $newRole  = (string) ($_POST['role'] ?? '');
        $target   = portal_find_user_by_id($targetId);

        if (!$target) {
            $_SESSION['admin_flash'] = ['error', 'User not found.'];
        } elseif ($targetId === (int) $currentUser['id']) {
            $_SESSION['admin_flash'] = ['error', 'You cannot change your own role.'];
        } elseif ($target['role'] === 'owner') {
            $_SESSION['admin_flash'] = ['error', 'Owner role cannot be reassigned.'];
        } elseif (!in_array($newRole, ['admin', 'supervisor', 'teacher', 'student'], true)) {
            $_SESSION['admin_flash'] = ['error', 'Invalid role.'];
        } else {
            $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, $targetId]);
            $_SESSION['admin_flash'] = ['success', "{$target['name']}'s role updated to {$newRole}."];
        }
        portal_redirect('admin.php');
    }

    if ($action === 'save_enrollments') {
        $targetId  = (int) ($_POST['user_id'] ?? 0);
        $courseIds = array_map('intval', (array) ($_POST['course_ids'] ?? []));
        $target    = portal_find_user_by_id($targetId);

        if (!$target) {
            $_SESSION['admin_flash'] = ['error', 'User not found.'];
        } else {
            // Replace enrollments: delete all, re-insert selected
            $pdo->prepare("DELETE FROM enrollments WHERE user_id = ?")->execute([$targetId]);
            $stmtE = $pdo->prepare("INSERT OR IGNORE INTO enrollments (user_id, course_id) VALUES (?,?)");
            foreach ($courseIds as $cid) {
                if ($cid > 0) {
                    $stmtE->execute([$targetId, $cid]);
                }
            }
            $_SESSION['admin_flash'] = ['success', "Enrollments for {$target['name']} saved."];
        }
        portal_redirect('admin.php' . ($targetId ? '?enroll=' . $targetId : ''));
    }

    if ($action === 'save_integrity_settings') {
        $policy = (string) ($_POST['external_ai_policy'] ?? 'disabled');
        if (!in_array($policy, ['disabled', 'site_wide', 'per_module'], true)) {
            $policy = 'disabled';
        }

        portal_site_setting_set('external_ai_policy', $policy);

        $apiKey = trim((string) ($_POST['zerogpt_api_key'] ?? ''));
        if ($apiKey !== '') {
            portal_site_setting_set('zerogpt_api_key', $apiKey);
        }

        $clearKey = isset($_POST['clear_zerogpt_api_key']) && $_POST['clear_zerogpt_api_key'] === '1';
        if ($clearKey) {
            portal_site_setting_set('zerogpt_api_key', '');
        }

        $safeBrowsingKey = trim((string) ($_POST['google_safe_browsing_api_key'] ?? ''));
        if ($safeBrowsingKey !== '') {
            portal_site_setting_set('google_safe_browsing_api_key', $safeBrowsingKey);
        }
        $clearSafeBrowsingKey = isset($_POST['clear_google_safe_browsing_api_key']) && $_POST['clear_google_safe_browsing_api_key'] === '1';
        if ($clearSafeBrowsingKey) {
            portal_site_setting_set('google_safe_browsing_api_key', '');
        }

        $pdo->exec('UPDATE courses SET external_ai_detection = 0');
        if ($policy === 'per_module') {
            $courseIds = array_map('intval', (array) ($_POST['external_ai_courses'] ?? []));
            $upd = $pdo->prepare('UPDATE courses SET external_ai_detection = 1 WHERE id = ?');
            foreach ($courseIds as $cid) {
                if ($cid > 0) {
                    $upd->execute([$cid]);
                }
            }
        }

        $_SESSION['admin_flash'] = ['success', 'Integrity and link safety settings saved.'];
        portal_redirect('admin.php#integrity-settings');
    }
}

// ── Read flash ─────────────────────────────────────────────────────────────────
if (isset($_SESSION['admin_flash'])) {
    $flash = $_SESSION['admin_flash'];
    unset($_SESSION['admin_flash']);
}

// ── Page data ─────────────────────────────────────────────────────────────────
$users      = portal_all_users();
$allCourses = portal_course_catalog();

$enrollTargetId = (int) ($_GET['enroll'] ?? 0);
$enrollTarget   = $enrollTargetId > 0 ? portal_find_user_by_id($enrollTargetId) : null;
$enrolledIds    = $enrollTarget ? portal_enrolled_course_ids($enrollTargetId) : [];

$stats = [
    'total_users'       => count($users),
    'owners'            => count(array_filter($users, fn($u) => $u['role'] === 'owner')),
    'admins'            => count(array_filter($users, fn($u) => $u['role'] === 'admin')),
    'supervisors'       => count(array_filter($users, fn($u) => $u['role'] === 'supervisor')),
    'teachers'          => count(array_filter($users, fn($u) => $u['role'] === 'teacher')),
    'students'          => count(array_filter($users, fn($u) => $u['role'] === 'student')),
    'total_courses'     => count($allCourses),
    'total_enrollments' => (int) $pdo->query("SELECT COUNT(*) FROM enrollments")->fetchColumn(),
];

$integrityPolicy   = portal_external_ai_policy();
$integrityKeySet   = portal_site_setting_has('zerogpt_api_key') || trim((string) getenv('ZEROGPT_API_KEY')) !== '';
$safeBrowsingKeySet  = portal_site_setting_has('google_safe_browsing_api_key') || trim((string) getenv('GOOGLE_SAFE_BROWSING_API_KEY')) !== '';
$integrityCourses  = $pdo->query(
    "SELECT id, title, code, external_ai_detection FROM courses ORDER BY title ASC"
)->fetchAll();

$page_title   = 'Admin | ' . portal_school_name();
$active_page  = 'admin';
$page_eyebrow = 'Management';
$page_heading = 'Admin panel';
$page_description = 'Manage student accounts, assign roles, and control course enrollments.';
$dbSecurityWarning = portal_db_security_warning();

ob_start();
?>
<section class="admin-layout">

    <!-- ── Left column: users + enrollment ── -->
    <div class="stack">

        <?php if ($dbSecurityWarning): ?>
        <div class="admin-flash error">
            <?= portal_escape($dbSecurityWarning) ?>
        </div>
        <?php endif; ?>

        <?php if ($flash): ?>
        <div class="admin-flash <?= $flash[0] === 'success' ? 'success' : 'error' ?>">
            <?= portal_escape($flash[1]) ?>
        </div>
        <?php endif; ?>

        <!-- Create account -->
        <article class="card-shell">
            <div class="section-head">
                <div>
                    <p class="eyebrow">New account</p>
                    <h3 class="card-title">Add a user</h3>
                </div>
            </div>

            <form class="admin-create-form" method="post" action="admin.php">
                <?= portal_csrf_field() ?>
                <input type="hidden" name="action" value="create_user">

                <div class="admin-form-grid">
                    <label class="admin-field">
                        <span>Full name</span>
                        <input type="text" name="name" required placeholder="e.g. Jane Smith">
                    </label>
                    <label class="admin-field">
                        <span>Username</span>
                        <input type="text" name="username" required placeholder="e.g. jsmith">
                    </label>
                    <label class="admin-field">
                        <span>Email</span>
                        <input type="email" name="email" required placeholder="student@rieo.edu">
                    </label>
                    <label class="admin-field">
                        <span>Year group</span>
                        <select name="year">
                            <option>Year 10</option>
                            <option selected>Year 11</option>
                            <option>Year 12</option>
                            <option>Year 13</option>
                        </select>
                    </label>
                    <label class="admin-field">
                        <span>Programme</span>
                        <input type="text" name="programme" placeholder="e.g. Sciences pathway" value="General">
                    </label>
                    <label class="admin-field">
                        <span>Password</span>
                        <input type="password" name="password" required minlength="6" placeholder="Min. 6 characters">
                    </label>
                    <?php if ($isOwner): ?>
                    <label class="admin-field">
                        <span>Role</span>
                        <select name="role">
                            <option value="student" selected>Student</option>
                            <option value="teacher">Teacher</option>
                            <option value="supervisor">Supervisor</option>
                            <option value="admin">Admin</option>
                        </select>
                    </label>
                    <?php endif; ?>
                </div>

                <div class="button-row">
                    <button type="submit" class="button">Create account</button>
                </div>
            </form>
        </article>

        <!-- User list -->
        <article class="card-shell">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Accounts</p>
                    <h3 class="card-title">All users</h3>
                </div>
                <span class="chip"><?= $stats['total_users'] ?></span>
            </div>

            <div class="admin-user-list">
                <?php foreach ($users as $u): ?>
                <?php $isSelf = (int) $u['id'] === (int) $currentUser['id']; ?>
                <div class="admin-user-row">
                    <div class="admin-avatar"><?= portal_escape($u['initials']) ?></div>

                    <div class="admin-user-info">
                        <strong><?= portal_escape($u['name']) ?></strong>
                        <span><?= portal_escape($u['email']) ?></span>
                        <span><?= portal_escape($u['year']) ?> · <?= portal_escape($u['programme']) ?></span>
                    </div>

                    <span class="admin-role-badge role-<?= portal_escape($u['role']) ?>"><?= portal_escape(ucfirst($u['role'])) ?></span>

                    <div class="admin-user-actions">
                        <a class="inline-action" href="admin.php?enroll=<?= (int) $u['id'] ?>#enrollment-panel">Enrollments</a>

                        <?php if ($isOwner && !$isSelf && $u['role'] !== 'owner'): ?>
                        <form method="post" action="admin.php" class="admin-inline-form">
                            <?= portal_csrf_field() ?>
                            <input type="hidden" name="action" value="change_role">
                            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                            <select name="role" class="admin-role-select" onchange="this.form.submit()">
                                <option value="student"<?= $u['role'] === 'student' ? ' selected' : '' ?>>Student</option>
                                <option value="teacher"<?= $u['role'] === 'teacher' ? ' selected' : '' ?>>Teacher</option>
                                <option value="supervisor"<?= $u['role'] === 'supervisor' ? ' selected' : '' ?>>Supervisor</option>
                                <option value="admin"<?= $u['role'] === 'admin' ? ' selected' : '' ?>>Admin</option>
                            </select>
                        </form>
                        <?php endif; ?>

                        <?php if (!$isSelf && $u['role'] !== 'owner' && ($isOwner || $u['role'] === 'student')): ?>
                        <form method="post" action="admin.php" class="admin-inline-form"
                              onsubmit="return confirm('Delete account for <?= portal_escape($u['name']) ?>?')">
                            <?= portal_csrf_field() ?>
                            <input type="hidden" name="action" value="delete_user">
                            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                            <button type="submit" class="admin-delete-btn">Delete</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </article>

        <!-- Enrollment panel -->
        <?php if ($enrollTarget): ?>
        <article class="card-shell" id="enrollment-panel">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Enrollment</p>
                    <h3 class="card-title">Courses for <?= portal_escape($enrollTarget['name']) ?></h3>
                    <p><?= count($enrolledIds) ?> of <?= count($allCourses) ?> courses currently enrolled</p>
                </div>
                <a class="inline-action" href="admin.php">Close</a>
            </div>

            <form method="post" action="admin.php">
                <?= portal_csrf_field() ?>
                <input type="hidden" name="action" value="save_enrollments">
                <input type="hidden" name="user_id" value="<?= (int) $enrollTarget['id'] ?>">

                <div class="admin-enroll-grid">
                    <?php foreach ($allCourses as $course): ?>
                    <?php $checked = in_array((int) $course['id'], $enrolledIds, true); ?>
                    <label class="admin-enroll-item<?= $checked ? ' enrolled' : '' ?>">
                        <input type="checkbox" name="course_ids[]" value="<?= (int) $course['id'] ?>"<?= $checked ? ' checked' : '' ?>>
                        <span class="admin-course-dot" style="background:<?= portal_escape($course['accent']) ?>"></span>
                        <div class="admin-enroll-text">
                            <strong><?= portal_escape($course['title']) ?></strong>
                            <span><?= portal_escape($course['code']) ?></span>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>

                <div class="button-row">
                    <button type="submit" class="button">Save enrollments</button>
                    <a href="admin.php" class="button-secondary">Cancel</a>
                </div>
            </form>
        </article>
        <?php endif; ?>

    </div>

    <!-- ── Right column: stats + course overview ── -->
    <div class="stack">

        <article class="card-shell">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Overview</p>
                    <h3 class="card-title">Quick stats</h3>
                </div>
            </div>
            <div class="admin-stats-grid">
                <div class="admin-stat">
                    <strong><?= $stats['total_users'] ?></strong>
                    <span>Total accounts</span>
                </div>
                <div class="admin-stat">
                    <strong><?= $stats['students'] ?></strong>
                    <span>Students</span>
                </div>
                <div class="admin-stat">
                    <strong><?= $stats['teachers'] ?></strong>
                    <span>Teachers</span>
                </div>
                <div class="admin-stat">
                    <strong><?= $stats['supervisors'] ?></strong>
                    <span>Supervisors</span>
                </div>
                <div class="admin-stat">
                    <strong><?= $stats['admins'] ?></strong>
                    <span>Admins</span>
                </div>
                <div class="admin-stat">
                    <strong><?= $stats['total_enrollments'] ?></strong>
                    <span>Enrollments</span>
                </div>
            </div>
        </article>

        <article class="card-shell">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Courses</p>
                    <h3 class="card-title">Active this year</h3>
                </div>
                <span class="chip"><?= $stats['total_courses'] ?></span>
            </div>
            <div class="admin-course-overview">
                <?php foreach ($allCourses as $course): ?>
                <div class="admin-course-row">
                    <span class="admin-course-dot" style="background:<?= portal_escape($course['accent']) ?>"></span>
                    <div>
                        <strong><?= portal_escape($course['title']) ?></strong>
                        <span><?= portal_escape($course['code']) ?> · <?= portal_escape($course['room']) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="card-shell" id="integrity-settings">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Integrity</p>
                    <h3 class="card-title">Integrity and link safety</h3>
                    <p>Configure ZeroGPT for optional external AI checks and Google Safe Browsing for safer external course links.</p>
                </div>
                <span class="chip<?= ($integrityKeySet || $safeBrowsingKeySet) ? '' : ' chip--muted' ?>">
                    <?= $integrityKeySet ? 'ZeroGPT set' : 'ZeroGPT missing' ?> · <?= $safeBrowsingKeySet ? 'Safe Browsing set' : 'Safe Browsing missing' ?>
                </span>
            </div>

            <form method="post" action="admin.php#integrity-settings" class="admin-integrity-form">
                <?= portal_csrf_field() ?>
                <input type="hidden" name="action" value="save_integrity_settings">

                <label class="admin-field">
                    <span>ZeroGPT API key</span>
                    <input type="password" name="zerogpt_api_key" autocomplete="new-password"
                           placeholder="<?= $integrityKeySet ? 'Leave blank to keep current key' : 'Paste your ZeroGPT API key' ?>">
                    <?php if ($integrityKeySet): ?>
                    <label class="admin-checkbox-inline">
                        <input type="checkbox" name="clear_zerogpt_api_key" value="1">
                        Remove saved API key
                    </label>
                    <?php endif; ?>
                    <small class="admin-field-hint">Stored securely in the site database. You can still use <code>.env</code> as a fallback if no key is saved here.</small>
                </label>

                <label class="admin-field">
                    <span>Google Safe Browsing API key <small>(for external course links)</small></span>
                    <input type="password" name="google_safe_browsing_api_key" autocomplete="new-password"
                           placeholder="<?= $safeBrowsingKeySet ? 'Leave blank to keep current key' : 'Paste your Google Safe Browsing API key' ?>">
                    <?php if ($safeBrowsingKeySet): ?>
                    <label class="admin-checkbox-inline">
                        <input type="checkbox" name="clear_google_safe_browsing_api_key" value="1">
                        Remove saved Google Safe Browsing key
                    </label>
                    <?php endif; ?>
                    <small class="admin-field-hint">Used server-side before users open external link items. If unset, users still see an external-link warning but no Google Safe Browsing verdict.</small>
                </label>

                <fieldset class="admin-policy-fieldset">
                    <legend>When should external AI detection run?</legend>
                    <label class="admin-radio">
                        <input type="radio" name="external_ai_policy" value="disabled"<?= $integrityPolicy === 'disabled' ? ' checked' : '' ?>>
                        <span><strong>Disabled</strong> — never call ZeroGPT (internal checks still run).</span>
                    </label>
                    <label class="admin-radio">
                        <input type="radio" name="external_ai_policy" value="site_wide"<?= $integrityPolicy === 'site_wide' ? ' checked' : '' ?>>
                        <span><strong>Site-wide</strong> — run for every submission when an API key is configured.</span>
                    </label>
                    <label class="admin-radio">
                        <input type="radio" name="external_ai_policy" value="per_module"<?= $integrityPolicy === 'per_module' ? ' checked' : '' ?>>
                        <span><strong>Selected modules only</strong> — enable specific courses below; teachers opt in per assignment.</span>
                    </label>
                </fieldset>

                <div class="admin-ai-modules" id="admin-ai-modules"<?= $integrityPolicy === 'per_module' ? '' : ' hidden' ?>>
                    <p class="admin-field-hint">Choose which modules can use external AI detection. Teachers must also tick “External AI detection” on each submission slot.</p>
                    <div class="admin-enroll-grid">
                        <?php foreach ($integrityCourses as $ic): ?>
                        <?php $checked = (int) ($ic['external_ai_detection'] ?? 0) === 1; ?>
                        <label class="admin-enroll-item<?= $checked ? ' enrolled' : '' ?>">
                            <input type="checkbox" name="external_ai_courses[]" value="<?= (int) $ic['id'] ?>"<?= $checked ? ' checked' : '' ?>>
                            <div class="admin-enroll-text">
                                <strong><?= portal_escape((string) $ic['title']) ?></strong>
                                <span><?= portal_escape((string) $ic['code']) ?></span>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="button-row">
                    <button type="submit" class="button">Save settings</button>
                </div>
            </form>
        </article>

        <article class="card-shell">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Permissions</p>
                    <h3 class="card-title">Role guide</h3>
                </div>
            </div>
            <div class="admin-role-guide">
                <div class="admin-role-row">
                    <span class="admin-role-badge role-owner">Owner</span>
                    <p>Full access — manage all accounts, change roles, delete any user.</p>
                </div>
                <div class="admin-role-row">
                    <span class="admin-role-badge role-admin">Admin</span>
                    <p>Create accounts, manage enrollments, assign teachers to courses.</p>
                </div>
                <div class="admin-role-row">
                    <span class="admin-role-badge role-teacher">Teacher</span>
                    <p>Manage folders, materials, and announcements for assigned courses only.</p>
                </div>
                <div class="admin-role-row">
                    <span class="admin-role-badge role-supervisor">Supervisor</span>
                    <p>Same course-management tools as a teacher, but only on courses an admin/owner assigns to them. No admin panel access.</p>
                </div>
                <div class="admin-role-row">
                    <span class="admin-role-badge role-student">Student</span>
                    <p>Access enrolled courses only via the student portal.</p>
                </div>
            </div>
        </article>

    </div>
</section>
<script>
(function () {
    const modules = document.getElementById('admin-ai-modules');
    if (!modules) return;
    function syncModules() {
        const sel = document.querySelector('input[name="external_ai_policy"]:checked');
        modules.hidden = !sel || sel.value !== 'per_module';
    }
    document.querySelectorAll('input[name="external_ai_policy"]').forEach(radio => {
        radio.addEventListener('change', syncModules);
    });
    syncModules();
})();
</script>
<?php
$page_content = ob_get_clean();

require __DIR__ . '/../layout.php';
