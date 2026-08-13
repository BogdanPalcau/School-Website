<?php
declare(strict_types=1);

/**
 * Smoke checks for email-bound student invites (no SMTP required).
 */

require_once dirname(__DIR__) . '/bootstrap.php';

$failed = 0;
$passed = 0;

function inv_expect(bool $cond, string $label): void
{
    global $failed, $passed;
    if ($cond) {
        echo "PASS  {$label}\n";
        $passed++;
    } else {
        echo "FAIL  {$label}\n";
        $failed++;
    }
}

$db = portal_db();

$ownerId = (int) ($db->query("SELECT id FROM users WHERE role IN ('owner','admin') ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
$courseId = (int) ($db->query('SELECT id FROM courses ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);

inv_expect(function_exists('portal_invite_create'), 'portal_invite_create exists');
inv_expect($ownerId > 0, 'admin/owner user available');
inv_expect($courseId > 0, 'course available');

if ($ownerId <= 0 || $courseId <= 0) {
    echo "\n{$passed} passed, {$failed} failed (skipped flow checks)\n";
    exit($failed > 0 ? 1 : 0);
}

$suffix = bin2hex(random_bytes(4));
$email = 'invite.student.' . $suffix . '@example.test';
$username = 'inv_' . $suffix;

$created = portal_invite_create(
    $email,
    $courseId,
    $ownerId,
    'Invite Student',
    'Year 10',
    7,
    '127.0.0.1'
);
inv_expect(!empty($created['ok']), 'create invite succeeds');
$token = (string) ($created['token'] ?? '');
inv_expect($token !== '', 'plaintext token returned once');
inv_expect(portal_invite_find_valid($token) !== null, 'token validates');

$wrong = portal_invite_accept($token, [
    'email' => 'other@example.test',
    'username' => $username,
    'password' => 'SecurePass1',
    'name' => 'Wrong',
    'year' => 'Year 10',
], '127.0.0.1');
inv_expect(empty($wrong['ok']), 'wrong email rejected');
inv_expect(portal_invite_find_valid($token) !== null, 'token still valid after mismatch');

$ok = portal_invite_accept($token, [
    'email' => $email,
    'username' => $username,
    'password' => 'SecurePass1',
    'name' => 'Hacker Name',
    'year' => 'Year 13',
], '127.0.0.1');
inv_expect(!empty($ok['ok']), 'accept with matching email succeeds');
$userId = (int) ($ok['user_id'] ?? 0);
inv_expect($userId > 0, 'user id returned');

$user = portal_find_user_by_id($userId);
inv_expect($user !== null && (string) $user['role'] === 'student', 'created role is student');
inv_expect($user !== null && (string) $user['name'] === 'Invite Student', 'locked name enforced');
inv_expect($user !== null && (string) $user['year'] === 'Year 10', 'locked year enforced');

$enrolled = (int) $db->prepare(
    'SELECT COUNT(*) FROM enrollments WHERE user_id = ? AND course_id = ?'
)->execute([$userId, $courseId]) ?: 0;
$enrollStmt = $db->prepare('SELECT COUNT(*) FROM enrollments WHERE user_id = ? AND course_id = ?');
$enrollStmt->execute([$userId, $courseId]);
inv_expect((int) $enrollStmt->fetchColumn() === 1, 'student enrolled into locked course');

$again = portal_invite_accept($token, [
    'email' => $email,
    'username' => $username . '2',
    'password' => 'SecurePass1',
    'name' => 'Invite Student',
    'year' => 'Year 10',
], '127.0.0.1');
inv_expect(empty($again['ok']), 'second accept fails');

// Revoke path
$created2 = portal_invite_create(
    'invite.revoke.' . $suffix . '@example.test',
    $courseId,
    $ownerId,
    '',
    '',
    3,
    '127.0.0.1'
);
inv_expect(!empty($created2['ok']), 'second invite created');
$inviteId2 = (int) ($created2['invite_id'] ?? 0);
$token2 = (string) ($created2['token'] ?? '');
inv_expect(portal_invite_revoke($inviteId2, $ownerId), 'revoke succeeds');
inv_expect(portal_invite_find_valid($token2) === null, 'revoked token invalid');

// Multi-course invite
$courseIds = $db->query('SELECT id FROM courses ORDER BY id ASC LIMIT 2')->fetchAll(PDO::FETCH_COLUMN) ?: [];
$courseIds = array_map('intval', $courseIds);
if (count($courseIds) >= 2) {
    $multiEmail = 'invite.multi.' . $suffix . '@example.test';
    $multiUser = 'invm_' . $suffix;
    $multi = portal_invite_create($multiEmail, $courseIds, $ownerId, '', '', 7, '127.0.0.1');
    inv_expect(!empty($multi['ok']), 'multi-course invite created');
    $multiToken = (string) ($multi['token'] ?? '');
    $multiInvite = portal_invite_find_valid($multiToken);
    inv_expect($multiInvite !== null, 'multi-course token validates');
    inv_expect(
        count((array) ($multiInvite['course_titles'] ?? [])) === 2,
        'multi-course invite lists both courses'
    );
    $multiOk = portal_invite_accept($multiToken, [
        'email' => $multiEmail,
        'username' => $multiUser,
        'password' => 'SecurePass1',
        'name' => 'Multi Student',
        'year' => 'Year 11',
    ], '127.0.0.1');
    inv_expect(!empty($multiOk['ok']), 'multi-course accept succeeds');
    $multiUserId = (int) ($multiOk['user_id'] ?? 0);
    $enrollCountStmt = $db->prepare(
        'SELECT COUNT(*) FROM enrollments WHERE user_id = ? AND course_id IN (?,?)'
    );
    $enrollCountStmt->execute([$multiUserId, $courseIds[0], $courseIds[1]]);
    inv_expect((int) $enrollCountStmt->fetchColumn() === 2, 'student enrolled into both courses');
    $db->prepare('DELETE FROM enrollments WHERE user_id = ?')->execute([$multiUserId]);
    $db->prepare('DELETE FROM users WHERE id = ?')->execute([$multiUserId]);
} else {
    inv_expect(true, 'multi-course checks skipped (need 2+ courses)');
}

// Cleanup
$db->prepare('DELETE FROM enrollments WHERE user_id = ?')->execute([$userId]);
$db->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
$db->prepare('DELETE FROM student_invite_courses WHERE invite_id IN (SELECT id FROM student_invites WHERE email LIKE ? OR email = ?)')
    ->execute(['invite.%' . $suffix . '@example.test', $email]);
$db->prepare("DELETE FROM student_invites WHERE email LIKE ?")->execute(['invite.%' . $suffix . '@example.test']);
$db->prepare("DELETE FROM student_invites WHERE email = ?")->execute([$email]);
$db->prepare("DELETE FROM student_invite_attempts WHERE ip = '127.0.0.1'")->execute();

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
