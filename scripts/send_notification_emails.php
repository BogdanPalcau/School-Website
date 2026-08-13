<?php
declare(strict_types=1);

/**
 * CLI: send deadline reminder emails for submissions due within N hours.
 *
 * Usage:
 *   php scripts/send_notification_emails.php
 *   php scripts/send_notification_emails.php --hours=24
 *   php scripts/send_notification_emails.php --dry-run
 *
 * Schedule daily (or hourly) via Task Scheduler / cron when SMTP + PORTAL_BASE_URL are set.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/bootstrap.php';

$withinHours = 24;
$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if (preg_match('/^--hours=(\d+)$/', $arg, $m)) {
        $withinHours = max(1, min(72, (int) $m[1]));
    }
}

echo "Notification mail ready: " . (portal_transactional_mail_ready() ? 'yes' : 'no') . PHP_EOL;
echo "Window: next {$withinHours} hour(s)" . PHP_EOL;

if ($dryRun) {
    echo "Dry run — no emails sent." . PHP_EOL;
    exit(0);
}

if (!portal_transactional_mail_ready()) {
    echo "Skipped: configure SMTP_* and PORTAL_BASE_URL first." . PHP_EOL;
    exit(0);
}

$stats = portal_send_deadline_reminder_emails($withinHours);
echo 'Scanned: ' . $stats['scanned']
    . ' | Sent: ' . $stats['sent']
    . ' | Skipped: ' . $stats['skipped']
    . ' | Errors: ' . $stats['errors']
    . PHP_EOL;

exit($stats['errors'] > 0 ? 2 : 0);
