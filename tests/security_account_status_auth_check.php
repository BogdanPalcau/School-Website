<?php
declare(strict_types=1);

/**
 * Account-status authorization checks for Security Activity actions.
 * Ensures non-owner admins cannot mute/restrict/ban peer admins.
 */

require_once __DIR__ . '/../bootstrap.php';

$failures = 0;
$marker = 'acct_auth_check_' . bin2hex(random_bytes(3));

function expect_true(bool $cond, string $label): void
{
    global $failures;
    if ($cond) {
        echo "PASS  {$label}\n";
        return;
    }
    $failures++;
    echo "FAIL  {$label}\n";
}

$pdo = portal_db();
$hash = password_hash('TempPass!234', PASSWORD_DEFAULT);

$pdo->prepare("
    INSERT INTO users (username, email, password_hash, name, year, programme, initials, role, account_status)
    VALUES (?, ?, ?, ?, '', '', 'OA', 'owner', 'active')
")->execute([$marker . '_owner', $marker . '_owner@example.test', $hash, 'Owner ' . $marker]);
$ownerId = (int) $pdo->lastInsertId();

$pdo->prepare("
    INSERT INTO users (username, email, password_hash, name, year, programme, initials, role, account_status)
    VALUES (?, ?, ?, ?, '', '', 'A1', 'admin', 'active')
")->execute([$marker . '_admin1', $marker . '_admin1@example.test', $hash, 'Admin1 ' . $marker]);
$admin1Id = (int) $pdo->lastInsertId();

$pdo->prepare("
    INSERT INTO users (username, email, password_hash, name, year, programme, initials, role, account_status)
    VALUES (?, ?, ?, ?, '', '', 'A2', 'admin', 'active')
")->execute([$marker . '_admin2', $marker . '_admin2@example.test', $hash, 'Admin2 ' . $marker]);
$admin2Id = (int) $pdo->lastInsertId();

$pdo->prepare("
    INSERT INTO users (username, email, password_hash, name, year, programme, initials, role, account_status)
    VALUES (?, ?, ?, ?, '', '', 'ST', 'student', 'active')
")->execute([$marker . '_student', $marker . '_student@example.test', $hash, 'Student ' . $marker]);
$studentId = (int) $pdo->lastInsertId();

$denied = portal_set_user_account_status($admin2Id, 'banned', $admin1Id, 'peer admin probe');
expect_true(empty($denied['ok']), 'non-owner admin cannot ban peer admin');
expect_true(
    str_contains((string) ($denied['error'] ?? ''), 'Only the owner'),
    'peer-admin ban returns owner-only error'
);

$statusAfter = (string) $pdo->query("SELECT account_status FROM users WHERE id = {$admin2Id}")->fetchColumn();
expect_true($statusAfter === 'active', 'peer admin account_status unchanged after denied ban');

$allowedStudent = portal_set_user_account_status($studentId, 'muted', $admin1Id, 'student mute');
expect_true(!empty($allowedStudent['ok']), 'admin can mute a student');

$allowedOwner = portal_set_user_account_status($admin2Id, 'restricted', $ownerId, 'owner restrict');
expect_true(!empty($allowedOwner['ok']), 'owner can restrict an admin');

$reactivate = portal_set_user_account_status($admin2Id, 'active', $admin1Id, 'peer reactivate');
expect_true(empty($reactivate['ok']), 'non-owner admin cannot reactivate peer admin');

$pdo->prepare('DELETE FROM users WHERE id IN (?, ?, ?, ?)')->execute([
    $ownerId, $admin1Id, $admin2Id, $studentId,
]);

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} check(s) failed.\n");
    exit(1);
}

echo "\nAll account-status authorization checks passed.\n";
