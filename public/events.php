<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

portal_require_login();

$page_title = 'Events | ' . portal_school_name();
$active_page = 'events';
$page_eyebrow = 'School events';
$page_heading = 'Events';
$page_description = 'Live sessions, online events, and school activities coming up over the next few days.';

$events = [
    ['month' => 'Apr', 'day' => '02', 'title' => 'Clubs and societies fair', 'location' => 'Live session · 13:30', 'copy' => 'Meet clubs, sign up for trial sessions, and collect your activity timetable.', 'featured' => true],
    ['month' => 'Apr', 'day' => '05', 'title' => 'STEM project showcase', 'location' => 'Live session · 15:45', 'copy' => 'Student teams will show their work to teachers, families, and anyone thinking of joining next term.'],
    ['month' => 'Apr', 'day' => '09', 'title' => 'Revision masterclass', 'location' => 'Live session · 16:00', 'copy' => 'A focused session on exam planning, note condensing, and timed practice.'],
];

$extras = [
    ['title' => 'Device check', 'detail' => 'Test your audio and camera before live sessions so you are ready to join on time.'],
    ['title' => 'Sign-up window', 'detail' => 'Event sign-ups close 24 hours before each session.'],
    ['title' => 'Parent evening note', 'detail' => 'Parent evening invites will appear in next week\'s bulletin.'],
];

$featured = null;
$upcoming = [];
foreach ($events as $ev) {
    if (!empty($ev['featured']) && $featured === null) {
        $featured = $ev;
    } else {
        $upcoming[] = $ev;
    }
}

ob_start();
?>
<section class="events-layout" id="featured-events">
    <div class="stack">

        <?php if ($featured): ?>
        <article class="ev-hero">
            <div class="ev-hero-header">
                <p class="eyebrow">Featured event</p>
                <a class="inline-action light" href="communication.php#latest-issue">Open bulletin</a>
            </div>
            <div class="ev-hero-body">
                <div class="ev-hero-date">
                    <strong><?= portal_escape($featured['day']) ?></strong>
                    <span><?= portal_escape($featured['month']) ?></span>
                </div>
                <div class="ev-hero-text">
                    <h3 class="ev-hero-title"><?= portal_escape($featured['title']) ?></h3>
                    <p><?= portal_escape($featured['copy']) ?></p>
                </div>
            </div>
            <p class="ev-hero-location"><?= portal_escape($featured['location']) ?></p>
        </article>
        <?php endif; ?>

        <?php if ($upcoming): ?>
        <article class="card-shell">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Coming up</p>
                    <h3 class="card-title">More this week</h3>
                </div>
                <span class="chip"><?= count($upcoming) ?> event<?= count($upcoming) !== 1 ? 's' : '' ?></span>
            </div>
            <div class="ev-list">
                <?php foreach ($upcoming as $event): ?>
                <article class="ev-item">
                    <div class="ev-date">
                        <strong><?= portal_escape($event['day']) ?></strong>
                        <span><?= portal_escape($event['month']) ?></span>
                    </div>
                    <div class="ev-body">
                        <h3><?= portal_escape($event['title']) ?></h3>
                        <p class="event-location"><?= portal_escape($event['location']) ?></p>
                        <p><?= portal_escape($event['copy']) ?></p>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        </article>
        <?php endif; ?>

    </div>

    <div class="stack">
        <article class="card-shell">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Notes</p>
                    <h3 class="card-title">Before you attend</h3>
                </div>
            </div>
            <div class="ev-notes">
                <?php foreach ($extras as $i => $extra): ?>
                <div class="ev-note">
                    <span class="ev-note-num"><?= $i + 1 ?></span>
                    <div>
                        <strong><?= portal_escape($extra['title']) ?></strong>
                        <p><?= portal_escape($extra['detail']) ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="card-shell">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Next step</p>
                    <h3 class="card-title">Plan your week</h3>
                </div>
            </div>
            <article class="schedule-note">
                <div>
                    <h3>Use calendar + timetable together</h3>
                    <p>Check the calendar for dates and the timetable if you want to plan the rest of your day around an event.</p>
                </div>
                <a class="inline-action" href="calendar.php#week-view">Open calendar</a>
            </article>
        </article>
    </div>
</section>
<?php
$page_content = ob_get_clean();

require __DIR__ . '/../layout.php';
