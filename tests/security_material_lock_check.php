<?php
declare(strict_types=1);

/**
 * Locks on course materials must block non-managers from viewing/downloading.
 * Teachers hide locked items in the UI; view.php / download.php must enforce it.
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

expect_true(
    portal_folder_item_content_locked(['locked' => 1, 'folder_locked' => 0]) === true,
    'item locked alone blocks access'
);
expect_true(
    portal_folder_item_content_locked(['locked' => 0, 'folder_locked' => 1]) === true,
    'parent folder locked alone blocks access'
);
expect_true(
    portal_folder_item_content_locked(['locked' => '1', 'folder_locked' => '0']) === true,
    'stringy locked flags from SQLite still block'
);
expect_true(
    portal_folder_item_content_locked(['locked' => 0, 'folder_locked' => 0]) === false,
    'unlocked item+folder allows access'
);
expect_true(
    portal_folder_item_content_locked(['locked' => null]) === false,
    'missing folder_locked with unlocked item allows access'
);

$viewSrc = file_get_contents(__DIR__ . '/../public/view.php') ?: '';
$downloadSrc = file_get_contents(__DIR__ . '/../public/download.php') ?: '';
$lessonSrc = file_get_contents(__DIR__ . '/../public/lesson-viewer.php') ?: '';
$courseSrc = file_get_contents(__DIR__ . '/../public/course.php') ?: '';

expect_true(
    str_contains($viewSrc, 'portal_folder_item_content_locked')
        && str_contains($viewSrc, 'folder_locked'),
    'view.php enforces material locks'
);
expect_true(
    str_contains($downloadSrc, 'portal_folder_item_content_locked')
        && str_contains($downloadSrc, 'folder_locked'),
    'download.php enforces material locks'
);
expect_true(
    str_contains($lessonSrc, 'portal_folder_item_content_locked')
        && str_contains($lessonSrc, 'folder_locked'),
    'lesson-viewer.php enforces material locks'
);
expect_true(
    str_contains($courseSrc, "\$action === 'submit_work'")
        && str_contains($courseSrc, 'portal_folder_item_content_locked($slot)')
        && str_contains($courseSrc, 'cf.locked AS folder_locked'),
    'course.php blocks submissions to locked slots and folders'
);
expect_true(
    str_contains($viewSrc, 'DOMPurify') && str_contains($viewSrc, 'sanitizeDocHtml'),
    'view.php sanitizes Mammoth HTML before innerHTML'
);
expect_true(
    str_contains($viewSrc, '.lock') && str_contains($viewSrc, 'LOCK_EX'),
    'presentation conversion uses a per-cache flock'
);

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} check(s) failed.\n");
    exit(1);
}

echo "\nAll material-lock / viewer security checks passed.\n";
