<?php
declare(strict_types=1);

/**
 * Ordered CSS sources. Concatenate in this order so cascade matches the
 * former monolithic style.css.
 *
 * @return list<string> Paths relative to the project root
 */
function portal_stylesheet_files(): array
{
    return [
        'styles/base.css',
        'styles/pages/events.css',
        'styles/shared-polish.css',
        'styles/pages/admin.css',
        'styles/shared-motion.css',
        'styles/pages/course.css',
        'styles/pages/settings.css',
        'styles/pages/review.css',
        'styles/pages/communication.css',
        'styles/pages/lesson-viewer.css',
        'styles/pages/dashboard.css',
        'styles/pages/grades.css',
        'styles/layout-mobile.css',
        'styles/pages/courses.css',
        'styles/pages/course-mobile.css',
        'styles/pages/timetable.css',
        'styles/pages/activity.css',
        'styles/pages/login.css',
    ];
}

function portal_concatenated_stylesheet(): string
{
    $root = dirname(__DIR__);
    $css = '';
    foreach (portal_stylesheet_files() as $rel) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!is_file($path)) {
            continue;
        }
        $css .= file_get_contents($path);
        if ($css !== '' && !str_ends_with($css, "\n")) {
            $css .= "\n";
        }
    }
    return $css;
}
