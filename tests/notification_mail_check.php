<?php
declare(strict_types=1);

/**
 * Smoke checks for transactional notification mail helpers (no SMTP required).
 */

require_once dirname(__DIR__) . '/bootstrap.php';

$failed = 0;
$passed = 0;

function nm_expect(bool $cond, string $label): void
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

nm_expect(function_exists('portal_transactional_mail_ready'), 'portal_transactional_mail_ready exists');
nm_expect(function_exists('portal_mail_claim_send'), 'portal_mail_claim_send exists');
nm_expect(function_exists('portal_notify_grade_returned'), 'portal_notify_grade_returned exists');
nm_expect(function_exists('portal_send_deadline_reminder_emails'), 'portal_send_deadline_reminder_emails exists');

$prefs = portal_user_preferences(0);
nm_expect(isset($prefs['notify_deadlines']) && (int) $prefs['notify_deadlines'] === 1, 'default prefs include notify_deadlines=1');

$db = portal_db();
$cols = array_column($db->query('PRAGMA table_info(user_preferences)')->fetchAll(), 'name');
nm_expect(in_array('notify_deadlines', $cols, true), 'user_preferences.notify_deadlines column exists');

$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='portal_mail_sent'")->fetchColumn();
nm_expect($tables === 'portal_mail_sent', 'portal_mail_sent table exists');

// Dedupe claim works without sending mail.
$uid = 0;
try {
    $uid = (int) $db->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn();
} catch (\Throwable $e) {
    $uid = 0;
}
if ($uid > 0) {
    $ref = 'test:' . bin2hex(random_bytes(4));
    nm_expect(portal_mail_claim_send($uid, 'deadline_24h', $ref) === true, 'first claim succeeds');
    nm_expect(portal_mail_claim_send($uid, 'deadline_24h', $ref) === false, 'duplicate claim is ignored');
    $db->prepare('DELETE FROM portal_mail_sent WHERE user_id = ? AND kind = ? AND ref_key = ?')
        ->execute([$uid, 'deadline_24h', $ref]);
} else {
    echo "SKIP  claim checks (no users in DB)\n";
}

// Without SMTP / BASE_URL, reminder runner is a no-op (does not throw).
$stats = portal_send_deadline_reminder_emails(24);
nm_expect(isset($stats['sent'], $stats['scanned'], $stats['skipped'], $stats['errors']), 'deadline reminder returns stats shape');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
