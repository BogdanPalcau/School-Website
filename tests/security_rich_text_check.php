<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$payloads = [
    '<span onclick=alert(1)>click me</span>',
    '<span onclick="alert(1)">click me</span>',
    '<span onmouseover=alert(1)>hover</span>',
    '<img src=x onerror=alert(1)>',
    '<svg onload=alert(1)>',
    '<script>alert(1)</script>hello',
    '<style>body{display:none}</style>Visible text',
    '<a href="javascript:alert(1)">click me</a>',
    '<p style="background:url(javascript:alert(1))">test</p>',
    '<iframe srcdoc="<script>alert(1)</script>"></iframe>hello',
    '<p><strong>Safe</strong> formatting</p>',
    '<span class="ql-align-center">centred</span>',
    '<a href="https://example.com">safe link</a>',
    '<a href="data:text/html,<script>alert(1)</script>">bad</a>',
    '<a href="mailto:test@example.com">email me</a>',
];

$expected = [
    '<span onclick=alert(1)>click me</span>' => '<span>click me</span>',
    '<script>alert(1)</script>hello' => 'hello',
    '<style>body{display:none}</style>Visible text' => 'Visible text',
    '<iframe srcdoc="<script>alert(1)</script>"></iframe>hello' => 'hello',
    '<a href="javascript:alert(1)">click me</a>' => 'click me',
    '<a href="data:text/html,<script>alert(1)</script>">bad</a>' => 'bad',
    '<a href="mailto:test@example.com">email me</a>' => '<a href="mailto:test@example.com">email me</a>',
];

/** @var array<string, callable(string): bool> $expectedChecks */
$expectedChecks = [
    '<a href="https://example.com">safe link</a>' => static function (string $clean): bool {
        return str_contains($clean, 'href="https://example.com"')
            && str_contains($clean, 'target="_blank"')
            && str_contains($clean, 'rel="noopener noreferrer"');
    },
];

$failures = 0;
foreach ($payloads as $payload) {
    $clean = portal_sanitize_rich_text($payload);
    $lower = strtolower($clean);

    $bad = false;
    $reasons = [];

    if (array_key_exists($payload, $expected) && $clean !== $expected[$payload]) {
        $bad = true;
        $reasons[] = 'expected [' . $expected[$payload] . ']';
    }
    if (array_key_exists($payload, $expectedChecks) && !$expectedChecks[$payload]($clean)) {
        $bad = true;
        $reasons[] = 'link hardening check failed';
    }

    if (preg_match('/\son[a-z]+\s*=/i', $clean)) {
        $bad = true;
        $reasons[] = 'event handler attribute present';
    }
    if (str_contains($lower, '<script') || str_contains($lower, '<iframe')
        || str_contains($lower, '<style') || str_contains($lower, '<img')
        || str_contains($lower, '<svg') || str_contains($lower, '<object')
        || str_contains($lower, '<embed')) {
        $bad = true;
        $reasons[] = 'dangerous tag present';
    }
    if (str_contains($lower, 'javascript:') || str_contains($lower, 'data:')) {
        $bad = true;
        $reasons[] = 'dangerous URL scheme present';
    }
    if (str_contains($lower, 'style=')) {
        $bad = true;
        $reasons[] = 'style attribute present';
    }

    $status = $bad ? 'FAIL' : 'PASS';
    if ($bad) {
        $failures++;
    }

    echo $status . ' | in:  ' . $payload . "\n";
    echo '       out: ' . $clean . "\n";
    if ($reasons !== []) {
        echo '       why: ' . implode(', ', $reasons) . "\n";
    }
    echo "\n";
}

echo $failures === 0 ? "All rich-text XSS checks passed.\n" : ($failures . " check(s) failed.\n");
exit($failures === 0 ? 0 : 1);
