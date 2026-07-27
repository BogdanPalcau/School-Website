<?php
declare(strict_types=1);

/**
 * Regression: annotation JSON embedded in <script> must not break out via </script>.
 */

$payload = [
    'quote'   => '</script><script>alert(1)</script>',
    'comment' => 'normal comment',
    'author'  => 'Teacher',
];

$unsafe = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$safe = json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

$failures = 0;
if (str_contains((string) $unsafe, '</script>')) {
    echo "PASS  baseline shows raw </script> under JSON_UNESCAPED_SLASHES\n";
} else {
    echo "FAIL  baseline expectation changed\n";
    $failures++;
}

if (str_contains((string) $safe, '</script>') || str_contains((string) $safe, '<script>')) {
    echo "FAIL  safe encoding still contains raw script tags\n";
    $failures++;
} else {
    echo "PASS  HEX encoding neutralizes script breakout sequences\n";
}

$decoded = json_decode((string) $safe, true);
if (is_array($decoded) && ($decoded['quote'] ?? '') === $payload['quote']) {
    echo "PASS  HEX-encoded JSON still round-trips via json_decode\n";
} else {
    echo "FAIL  HEX-encoded JSON does not round-trip\n";
    $failures++;
}

$integrity = file_get_contents(__DIR__ . '/../integrity.php') ?: '';
if (
    str_contains($integrity, 'rvw-annotations-data')
    && str_contains($integrity, 'JSON_HEX_TAG')
    && !preg_match('/rvw-annotations-data[\s\S]{0,800}JSON_UNESCAPED_SLASHES/', $integrity)
) {
    echo "PASS  integrity.php annotation embed uses HEX flags without UNESCAPED_SLASHES\n";
} else {
    echo "FAIL  integrity.php annotation embed flags not hardened\n";
    $failures++;
}

$course = file_get_contents(__DIR__ . '/../public/course.php') ?: '';
if (
    str_contains($course, 'dompurify@3.1.6')
    && str_contains($course, 'function sanitizeDocHtml')
    && str_contains($course, 'sanitizeDocHtml(result.value')
) {
    echo "PASS  course.php review DOCX path sanitizes Mammoth HTML\n";
} else {
    echo "FAIL  course.php review DOCX path still unsanitized\n";
    $failures++;
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} check(s) failed.\n");
    exit(1);
}

echo "\nAll review XSS hardening checks passed.\n";
