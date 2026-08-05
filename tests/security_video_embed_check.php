<?php
declare(strict_types=1);

/**
 * Teachers may embed lesson videos from YouTube/Vimeo instead of uploading a file.
 * portal_parse_external_video_url() is the only thing allowed to decide what ever
 * reaches an <iframe src> on lesson-viewer.php, so it must:
 *   - accept only the real embed URL shapes from the allowlisted platforms
 *   - reject lookalike/attacker-controlled hosts, even if they smuggle a valid-looking
 *     video id in the path or query string
 *   - always hand back a URL built from the platform's own embed domain, never the
 *     raw string the teacher pasted
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

function expect_null($value, string $label): void
{
    expect_true($value === null, $label);
}

// ── Accepted: real YouTube URL shapes ───────────────────────────────────────────
$yt1 = portal_parse_external_video_url('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
expect_true($yt1 !== null && $yt1['provider'] === 'youtube' && $yt1['video_id'] === 'dQw4w9WgXcQ', 'accepts youtube.com/watch?v=');
expect_true($yt1 !== null && $yt1['embed_url'] === 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'youtube embed_url uses the nocookie domain + validated id');

$yt2 = portal_parse_external_video_url('https://youtu.be/dQw4w9WgXcQ?t=30');
expect_true($yt2 !== null && $yt2['video_id'] === 'dQw4w9WgXcQ', 'accepts youtu.be short link');

$yt3 = portal_parse_external_video_url('youtube.com/embed/dQw4w9WgXcQ');
expect_true($yt3 !== null && $yt3['video_id'] === 'dQw4w9WgXcQ', 'accepts bare host + /embed/ path, no scheme');

$yt4 = portal_parse_external_video_url('https://m.youtube.com/shorts/dQw4w9WgXcQ');
expect_true($yt4 !== null && $yt4['video_id'] === 'dQw4w9WgXcQ', 'accepts youtube shorts URL');

// ── Accepted: real Vimeo URL shapes ─────────────────────────────────────────────
$vim1 = portal_parse_external_video_url('https://vimeo.com/76979871');
expect_true($vim1 !== null && $vim1['provider'] === 'vimeo' && $vim1['video_id'] === '76979871', 'accepts vimeo.com/{id}');
expect_true($vim1 !== null && $vim1['embed_url'] === 'https://player.vimeo.com/video/76979871', 'vimeo embed_url uses the official player domain + validated id');

$vim2 = portal_parse_external_video_url('https://player.vimeo.com/video/76979871');
expect_true($vim2 !== null && $vim2['video_id'] === '76979871', 'accepts an already-embedded player.vimeo.com URL');

$vim3 = portal_parse_external_video_url('https://vimeo.com/76979871/8272103f6e');
expect_true(
    $vim3 !== null && $vim3['watch_url'] === 'https://vimeo.com/76979871/8272103f6e',
    'preserves the privacy hash in a canonical unlisted Vimeo watch URL'
);
expect_true(
    $vim3 !== null && $vim3['embed_url'] === 'https://player.vimeo.com/video/76979871?h=8272103f6e',
    'passes the privacy hash to the unlisted Vimeo embed'
);

$vim4 = portal_parse_external_video_url('https://player.vimeo.com/video/76979871?h=8272103f6e&autoplay=1');
expect_true(
    $vim4 !== null
        && $vim4['watch_url'] === 'https://vimeo.com/76979871/8272103f6e'
        && $vim4['embed_url'] === 'https://player.vimeo.com/video/76979871?h=8272103f6e',
    'preserves an unlisted Vimeo embed h parameter while dropping unrelated options'
);

// ── Rejected: lookalike / attacker-controlled hosts ─────────────────────────────
expect_null(
    portal_parse_external_video_url('https://youtube.com.evil-mirror.example/watch?v=dQw4w9WgXcQ'),
    'rejects a lookalike host that merely contains "youtube.com"'
);
expect_null(
    portal_parse_external_video_url('https://evil.example/?redirect=https://youtube.com/watch?v=dQw4w9WgXcQ'),
    'rejects an unrelated host smuggling a real video URL in a query param'
);
expect_null(
    portal_parse_external_video_url('https://youtu.be.attacker.io/dQw4w9WgXcQ'),
    'rejects a subdomain-suffix trick on youtu.be'
);
expect_null(
    portal_parse_external_video_url('javascript:alert(1)'),
    'rejects a javascript: pseudo-url'
);
expect_null(
    portal_parse_external_video_url('https://vimeo.com/not-a-number'),
    'rejects a non-numeric vimeo path'
);
expect_null(
    portal_parse_external_video_url('https://vimeo.com/76979871/8272103f6e?h=different'),
    'rejects conflicting Vimeo privacy hashes'
);
expect_null(
    portal_parse_external_video_url('https://player.vimeo.com/video/76979871?h[]=8272103f6e'),
    'rejects a non-scalar Vimeo privacy hash'
);
expect_null(
    portal_parse_external_video_url(''),
    'rejects an empty string'
);
expect_null(
    portal_parse_external_video_url('https://drive.google.com/file/d/xyz/view'),
    'rejects a platform outside the allowlist'
);

// ── Wiring: lesson-viewer.php must re-validate on every load, never trust the DB row blindly ──
$lessonSrc = file_get_contents(__DIR__ . '/../public/lesson-viewer.php') ?: '';
expect_true(
    str_contains($lessonSrc, 'portal_parse_external_video_url'),
    'lesson-viewer.php re-validates the stored video url through the allowlist parser'
);
expect_true(
    str_contains($lessonSrc, "\$videoMeta['embed_url']"),
    'lesson-viewer.php builds the iframe src from the parsed embed_url, not the raw stored url'
);

// course.php must run new video links through the same allowlist before ever saving them
$courseSrc = file_get_contents(__DIR__ . '/../public/course.php') ?: '';
expect_true(
    substr_count($courseSrc, 'portal_parse_external_video_url') >= 2,
    'course.php validates external video links on both create and edit'
);

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} check(s) failed.\n");
    exit(1);
}

echo "\nAll video-embed allowlist checks passed.\n";
