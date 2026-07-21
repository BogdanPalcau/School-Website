<?php
declare(strict_types=1);

/**
 * Asking a question on a lesson video used to submit a normal <form method="POST">.
 * That POST redirects back to lesson-viewer.php, and a fresh page load always resets
 * a native <video> element to 0:00 — so every student question silently rewound the
 * lesson. The fix submits the question over fetch() and splices the new Q&A card into
 * the DOM instead of reloading the page. These checks lock that behaviour in.
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

$src = file_get_contents(__DIR__ . '/../public/lesson-viewer.php') ?: '';

// The AJAX branch must exist, be reached only for real fetch() requests, and never
// fall through to portal_redirect() (which is what caused the reset).
$ajaxBranchPos = strpos($src, "=== 'ask_question'");
$fetchGuardPos = strpos($src, 'portal_is_fetch_request()');
expect_true(
    $ajaxBranchPos !== false && $fetchGuardPos !== false && $fetchGuardPos - $ajaxBranchPos < 60,
    'lesson-viewer.php has a dedicated fetch-only branch for ask_question'
);

$fallbackRedirectPos = strpos($src, "if (\$action === 'ask_question') {");
expect_true($fallbackRedirectPos !== false, 'the legacy full-page-POST fallback for ask_question still exists (progressive enhancement)');

expect_true(
    $ajaxBranchPos !== false && $fallbackRedirectPos !== false && $ajaxBranchPos < $fallbackRedirectPos,
    'the fetch-only branch is checked before the code path that redirects (and thus reloads the video)'
);

expect_true(
    str_contains($src, "'html' => \$newQuestionRow"),
    'a successful AJAX ask_question responds with JSON (html of the new card) instead of redirecting'
);

// The composer's submit handler must intercept the native form submission.
$jsSubmitPos = strpos($src, "composer.addEventListener('submit'");
expect_true($jsSubmitPos !== false, 'composer submit listener is present');
$jsSnippet = $jsSubmitPos !== false ? substr($src, $jsSubmitPos, 2200) : '';
expect_true(str_contains($jsSnippet, 'e.preventDefault()'), 'composer submit handler prevents the native (page-reloading) form submission');
expect_true(str_contains($jsSnippet, "fetch(window.location"), 'composer submit handler sends the question via fetch instead');
expect_true(str_contains($jsSnippet, 'addQuestionCard'), 'composer submit handler inserts the new question client-side on success');

// Newly inserted cards must still support "jump to this moment" without a rebind.
expect_true(
    str_contains($src, "e.target.closest('.qa-video-stamp[data-seek]')"),
    'video-stamp seek buttons use delegated click handling so dynamically inserted cards work too'
);

// The shared renderer must be used both for the initial page and the AJAX response,
// so a newly posted question renders identically either way.
expect_true(
    substr_count($src, 'portal_render_lesson_qa_item(') >= 3, // 1 definition + main loop + AJAX response
    'portal_render_lesson_qa_item() is shared between the initial render and the AJAX response'
);

// YouTube/Vimeo timestamps without a Data API key: postMessage bridge + theater CSS.
expect_true(
    str_contains($src, 'enablejsapi=1') && str_contains($src, 'initEmbedBridge'),
    'YouTube embeds enable the postMessage bridge (no Data API key) for timestamps'
);
expect_true(
    str_contains($src, 'function ytPost') && str_contains($src, "ytPost('getCurrentTime')"),
    'lesson-viewer polls the YouTube embed for currentTime over postMessage'
);
expect_true(
    str_contains($src, 'function toggleTheater') && str_contains($src, 'theaterBtn.focus'),
    'theater toggle refocuses the button so T works after leaving the iframe'
);

$css = file_get_contents(__DIR__ . '/../style.css') ?: '';
$theaterBlock = '';
if (preg_match('/\.lesson-viewer\.is-theater \.lesson-player\s*\{([^}]+)\}/s', $css, $m)) {
    $theaterBlock = $m[1];
}
expect_true(
    str_contains($theaterBlock, 'aspect-ratio: 16 / 9')
        && !str_contains($theaterBlock, 'aspect-ratio: auto'),
    'theater mode keeps a 16:9 aspect ratio so YouTube embeds do not collapse'
);

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} check(s) failed.\n");
    exit(1);
}

echo "\nAll lesson Q&A no-reload checks passed.\n";
