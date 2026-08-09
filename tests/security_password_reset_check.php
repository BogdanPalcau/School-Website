<?php
declare(strict_types=1);

/**
 * Password-reset links must use an explicitly configured public origin. Request
 * headers are attacker-controlled and must never influence emailed reset URLs.
 */

require_once __DIR__ . '/../bootstrap.php';

$failures = 0;

function expect_same(string $expected, string $actual, string $label): void
{
    global $failures;
    if ($expected === $actual) {
        echo "PASS  {$label}\n";
        return;
    }

    $failures++;
    echo "FAIL  {$label}\n";
    echo "      expected: {$expected}\n";
    echo "      actual:   {$actual}\n";
}

$originalBaseUrl = getenv('PORTAL_BASE_URL');
$originalHost = $_SERVER['HTTP_HOST'] ?? null;
$originalHttps = $_SERVER['HTTPS'] ?? null;
$originalPort = $_SERVER['SERVER_PORT'] ?? null;
$originalScript = $_SERVER['SCRIPT_NAME'] ?? null;

try {
    putenv('PORTAL_BASE_URL');
    $_SERVER['HTTP_HOST'] = 'attacker.example';
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = '443';
    $_SERVER['SCRIPT_NAME'] = '/public/forgot-password.php';

    expect_same(
        '',
        portal_base_url(),
        'missing configuration never falls back to a forged Host header'
    );

    putenv('PORTAL_BASE_URL=https://school.example/portal/');
    expect_same(
        'https://school.example/portal',
        portal_base_url(),
        'configured public URL is normalized and used'
    );

    putenv('PORTAL_BASE_URL=javascript://school.example');
    expect_same(
        '',
        portal_base_url(),
        'non-http URL schemes are rejected'
    );

    putenv('PORTAL_BASE_URL=https://school.example/portal?redirect=https://attacker.example');
    expect_same(
        '',
        portal_base_url(),
        'configured URLs with query strings are rejected'
    );
} finally {
    if ($originalBaseUrl === false) {
        putenv('PORTAL_BASE_URL');
    } else {
        putenv('PORTAL_BASE_URL=' . $originalBaseUrl);
    }

    foreach (
        [
            'HTTP_HOST' => $originalHost,
            'HTTPS' => $originalHttps,
            'SERVER_PORT' => $originalPort,
            'SCRIPT_NAME' => $originalScript,
        ] as $key => $value
    ) {
        if ($value === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $value;
        }
    }
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} check(s) failed.\n");
    exit(1);
}

echo "\nAll password-reset URL security checks passed.\n";
