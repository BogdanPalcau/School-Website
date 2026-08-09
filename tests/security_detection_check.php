<?php
declare(strict_types=1);

/**
 * Security activity detection + bulk review helpers.
 * Uses synthetic security_events rows tagged with a unique marker in details.
 */

require_once __DIR__ . '/../bootstrap.php';

$failures = 0;
$marker = '203.0.113.'; // TEST-NET-3
$tag = 'sec_detect_check_' . bin2hex(random_bytes(4));

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

function insert_event(
    string $type,
    string $ip,
    string $username,
    string $createdAt,
    string $details,
    int $reviewed = 0
): int {
    $stmt = portal_db()->prepare("
        INSERT INTO security_events
            (event_type, severity, user_id, username, ip_address, user_agent, route, method, details, reviewed, created_at)
        VALUES (?, 'medium', NULL, ?, ?, 'sec-detect-check', '/login.php', 'POST', ?, ?, ?)
    ");
    $stmt->execute([$type, $username, $ip, $details, $reviewed, $createdAt]);
    return (int) portal_db()->lastInsertId();
}

$db = portal_db();
$cleanup = static function () use ($db, $tag): void {
    $db->prepare("DELETE FROM security_events WHERE details LIKE ?")->execute([$tag . '%']);
};
$cleanup();

$now = gmdate('Y-m-d H:i:s');
$minsAgo = static function (int $mins) use ($now): string {
    return gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') - ($mins * 60));
};

// ── IP summary ───────────────────────────────────────────────────────────────
$ipA = $marker . '10';
$ipB = $marker . '20';
insert_event('failed_login', $ipA, 'alice', $minsAgo(5), $tag . ' sum1');
insert_event('failed_login', $ipA, 'bob', $minsAgo(4), $tag . ' sum2');
insert_event('csrf_failed', $ipA, '', $minsAgo(3), $tag . ' sum3');
insert_event('failed_login', $ipB, 'carol', $minsAgo(2), $tag . ' sum4');

$summary = portal_security_ip_summary('24h', 50);
$rowA = null;
foreach ($summary as $row) {
    if (($row['ip_address'] ?? '') === $ipA) {
        $rowA = $row;
        break;
    }
}
expect_true($rowA !== null, 'IP summary includes synthetic IP A');
expect_true($rowA !== null && (int) $rowA['event_count'] >= 3, 'IP summary event_count for IP A >= 3');
expect_true($rowA !== null && (int) $rowA['distinct_event_types'] >= 2, 'IP summary distinct_event_types for IP A >= 2');
expect_true($rowA !== null && (int) $rowA['distinct_usernames'] >= 2, 'IP summary distinct_usernames for IP A >= 2');

$filtered = portal_security_events_filtered('24h', 'all', 'all', 'all', $ipA, 100);
$filteredIps = array_unique(array_map(static fn(array $e): string => (string) ($e['ip_address'] ?? ''), $filtered));
expect_true($filtered !== [] && $filteredIps === [$ipA], 'sec_ip exact filter returns only that IP');

// ── Credential stuffing thresholds ───────────────────────────────────────────
$cleanup();
$stuffIp = $marker . '30';
foreach (['u1', 'u1', 'u2', 'u2', 'u1'] as $i => $user) {
    insert_event('failed_login', $stuffIp, $user, $minsAgo(10 - $i), $tag . ' below_stuff_' . $i);
}
$incidents = portal_security_detect_incidents('24h');
$stuffHits = array_values(array_filter(
    $incidents,
    static fn(array $i): bool => ($i['label'] ?? '') === 'Possible credential stuffing' && ($i['ip'] ?? '') === $stuffIp
));
expect_true($stuffHits === [], 'credential stuffing does not fire below username threshold');

$cleanup();
foreach (['a', 'b', 'c', 'd', 'e'] as $i => $user) {
    insert_event('failed_login', $stuffIp, $user, $minsAgo(10 - $i), $tag . ' stuff_' . $i);
}
$incidents = portal_security_detect_incidents('24h');
$stuffHits = array_values(array_filter(
    $incidents,
    static fn(array $i): bool => ($i['label'] ?? '') === 'Possible credential stuffing' && ($i['ip'] ?? '') === $stuffIp
));
expect_true(count($stuffHits) === 1, 'credential stuffing fires at 5 failures / 3+ usernames / 60 min');
expect_true(
    $stuffHits !== [] && count($stuffHits[0]['event_ids'] ?? []) >= 5,
    'credential stuffing incident includes matching event ids'
);

// ── Account targeting ────────────────────────────────────────────────────────
$cleanup();
$targetUser = 'target_user_' . substr($tag, -6);
insert_event('failed_login', $marker . '41', $targetUser, $minsAgo(5), $tag . ' tgt1');
insert_event('failed_login', $marker . '42', $targetUser, $minsAgo(4), $tag . ' tgt2');
$incidents = portal_security_detect_incidents('24h');
$targetHits = array_values(array_filter(
    $incidents,
    static fn(array $i): bool => ($i['label'] ?? '') === 'Possible account targeting'
        && ($i['username'] ?? '') === $targetUser
));
expect_true($targetHits === [], 'account targeting does not fire with only 2 IPs');

insert_event('failed_login', $marker . '43', $targetUser, $minsAgo(3), $tag . ' tgt3');
$incidents = portal_security_detect_incidents('24h');
$targetHits = array_values(array_filter(
    $incidents,
    static fn(array $i): bool => ($i['label'] ?? '') === 'Possible account targeting'
        && ($i['username'] ?? '') === $targetUser
));
expect_true(count($targetHits) === 1, 'account targeting fires with 3+ distinct IPs in 60 min');

// ── Bulk mark reviewed ───────────────────────────────────────────────────────
$cleanup();
$idKeepOpen = insert_event('failed_login', $marker . '50', 'keep', $minsAgo(1), $tag . ' keep_open', 0);
$idMark1 = insert_event('failed_login', $marker . '51', 'mark1', $minsAgo(1), $tag . ' mark1', 0);
$idMark2 = insert_event('failed_login', $marker . '51', 'mark2', $minsAgo(1), $tag . ' mark2', 0);
$idOutside = insert_event('failed_login', $marker . '52', 'out', $minsAgo(1), $tag . ' outside', 0);

$reviewerId = (int) ($db->query("SELECT id FROM users WHERE role IN ('owner','admin') ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
expect_true($reviewerId > 0, 'found an admin/owner reviewer id for bulk mark');

$marked = portal_mark_security_events_reviewed_bulk([$idMark1, $idMark2], $reviewerId);
expect_true($marked === 2, 'bulk mark updates exactly the two IDs passed');

$chk = $db->prepare('SELECT reviewed FROM security_events WHERE id = ?');
$chk->execute([$idMark1]);
expect_true((int) $chk->fetchColumn() === 1, 'marked id 1 is reviewed');
$chk->execute([$idMark2]);
expect_true((int) $chk->fetchColumn() === 1, 'marked id 2 is reviewed');
$chk->execute([$idKeepOpen]);
expect_true((int) $chk->fetchColumn() === 0, 'unrelated keep_open stays unreviewed');
$chk->execute([$idOutside]);
expect_true((int) $chk->fetchColumn() === 0, 'unrelated outside id stays unreviewed');

$matched = portal_security_events_filtered('24h', 'unreviewed', 'all', 'all', $marker . '50', 500);
$matchedIds = array_map(static fn(array $r): int => (int) $r['id'], $matched);
expect_true(in_array($idKeepOpen, $matchedIds, true), 'filter re-run finds keep_open for IP .50');
expect_true(!in_array($idOutside, $matchedIds, true), 'filter re-run does not include outside IP');
$markedFilter = portal_mark_security_events_reviewed_bulk($matchedIds, $reviewerId);
expect_true($markedFilter >= 1, 'bulk mark via filter-resolved IDs updates matching rows');
$chk->execute([$idKeepOpen]);
expect_true((int) $chk->fetchColumn() === 1, 'filter-resolved keep_open is now reviewed');
$chk->execute([$idOutside]);
expect_true((int) $chk->fetchColumn() === 0, 'outside row still unreviewed after filter bulk');

$many = [];
for ($i = 0; $i < 3; $i++) {
    $many[] = insert_event('failed_login', $marker . '60', 'cap' . $i, $minsAgo(1), $tag . ' cap' . $i, 0);
}
$padded = array_merge($many, range(900000, 900520));
$capped = portal_mark_security_events_reviewed_bulk($padded, $reviewerId);
expect_true($capped <= 500, 'bulk mark never claims more than 500 updates');
expect_true($capped === 3, 'bulk mark with padded fake IDs still only updates the 3 real rows');

// ── CSRF / admin gating (architecture) ───────────────────────────────────────
$adminSrc = file_get_contents(__DIR__ . '/../public/admin.php') ?: '';
expect_true(
    str_contains($adminSrc, "if (!portal_verify_csrf())")
        && str_contains($adminSrc, 'bulk_security_action'),
    'admin.php verifies CSRF before POST actions including bulk_security_action'
);
expect_true(
    str_contains($adminSrc, 'portal_require_admin()')
        && strpos($adminSrc, 'portal_require_admin()') < strpos($adminSrc, 'bulk_security_action'),
    'admin.php requires admin before bulk_security_action handler'
);
expect_true(
    str_contains($adminSrc, 'select_all_matching')
        && str_contains($adminSrc, 'portal_security_events_filtered'),
    'select_all_matching re-resolves IDs server-side via portal_security_events_filtered'
);
expect_true(
    (bool) preg_match("/select_all_matching.*?===\\s*'1'/s", $adminSrc),
    'select_all_matching is checked as exact string "1" (not empty("0") quirk)'
);

// ── Account-action role hierarchy ────────────────────────────────────────────
$statusSuffix = substr($tag, -8);
$statusActorUsername = 'sec_status_actor_' . $statusSuffix;
$statusTargetUsername = 'sec_status_target_' . $statusSuffix;
$statusUserIds = [];
try {
    $insertUser = $db->prepare(
        'INSERT INTO users (username, email, password_hash, name, year, programme, initials, role)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insertUser->execute([
        $statusActorUsername,
        $statusActorUsername . '@example.test',
        password_hash('SecurityPass123!', PASSWORD_DEFAULT),
        'Status Actor',
        'Year 11',
        'Security Test',
        'SA',
        'admin',
    ]);
    $statusActorId = (int) $db->lastInsertId();
    $statusUserIds[] = $statusActorId;

    $insertUser->execute([
        $statusTargetUsername,
        $statusTargetUsername . '@example.test',
        password_hash('SecurityPass123!', PASSWORD_DEFAULT),
        'Status Target',
        'Year 11',
        'Security Test',
        'ST',
        'admin',
    ]);
    $statusTargetId = (int) $db->lastInsertId();
    $statusUserIds[] = $statusTargetId;

    $statusResult = portal_set_user_account_status($statusTargetId, 'banned', $statusActorId, 'role hierarchy check');
    expect_true(empty($statusResult['ok']), 'non-owner admin cannot ban another admin');

    $statusCheck = $db->prepare('SELECT account_status FROM users WHERE id = ?');
    $statusCheck->execute([$statusTargetId]);
    expect_true($statusCheck->fetchColumn() === 'active', 'denied peer-admin ban leaves account active');
} finally {
    $deleteStatusUser = $db->prepare('DELETE FROM users WHERE id = ?');
    foreach (array_reverse($statusUserIds) as $statusUserId) {
        $deleteStatusUser->execute([$statusUserId]);
    }
}

$cleanup();

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} check(s) failed.\n");
    exit(1);
}

echo "\nAll security detection / bulk-review checks passed.\n";
