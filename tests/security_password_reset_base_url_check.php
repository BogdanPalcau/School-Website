<?php
declare(strict_types=1);

/**
 * Password-reset links must never be built from HTTP_HOST.
 */

require_once __DIR__ . '/../bootstrap.php';

$failures = 0;

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

$bootstrapSrc = file_get_contents(__DIR__ . '/../bootstrap.php') ?: '';

expect_true(
    function_exists('portal_configured_base_url'),
    'portal_configured_base_url helper exists'
);

$prev = getenv('PORTAL_BASE_URL');
putenv('PORTAL_BASE_URL=');
expect_true(
    portal_configured_base_url() === '',
    'empty PORTAL_BASE_URL yields empty configured base'
);

putenv('PORTAL_BASE_URL=https://portal.example.edu/public');
expect_true(
    portal_configured_base_url() === 'https://portal.example.edu/public',
    'configured PORTAL_BASE_URL is returned without trailing slash issues'
);
putenv('PORTAL_BASE_URL=https://portal.example.edu/public/');
expect_true(
    portal_configured_base_url() === 'https://portal.example.edu/public',
    'trailing slash is stripped from PORTAL_BASE_URL'
);

if ($prev === false) {
    putenv('PORTAL_BASE_URL');
} else {
    putenv('PORTAL_BASE_URL=' . $prev);
}

expect_true(
    str_contains($bootstrapSrc, 'portal_configured_base_url()')
        && str_contains($bootstrapSrc, 'PORTAL_BASE_URL not configured'),
    'password reset refuses to send when PORTAL_BASE_URL is unset'
);
expect_true(
    !preg_match(
        '/function portal_password_reset_request.*?portal_base_url\(\).*?reset-password\.php/s',
        $bootstrapSrc
    ),
    'password reset does not call portal_base_url for the emailed link'
);

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} check(s) failed.\n");
    exit(1);
}

echo "\nAll password-reset base URL checks passed.\n";
