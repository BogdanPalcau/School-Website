<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

portal_require_login();

$page_title = 'Communication | ' . portal_school_name();
$active_page = 'communication';
$page_eyebrow = 'Student bulletin';
$page_heading = 'Communication';
$page_description = 'School news, reminders, and small updates written more like a bulletin than a notice board.';

$features = [
    ['tag' => 'Principal note', 'title' => 'Assessment week starts strong with shorter morning briefings', 'copy' => 'Morning notices move into the bulletin this week so students get calmer starts before first period.', 'featured' => true],
    ['tag' => 'Clubs', 'title' => 'Robotics opens sign-ups for the regional build challenge', 'copy' => 'Teams of three can submit by Friday. Beginners are welcome this term.'],
    ['tag' => 'Library', 'title' => 'Extended revision hours now run until 18:00 on Wednesdays', 'copy' => 'Quiet seating, printer access, and peer mentors are available during the extension.'],
    ['tag' => 'Wellbeing', 'title' => 'Study-support drop-in now includes short stress reset sessions', 'copy' => 'Student services added ten-minute reset slots between revision blocks.'],
];

$briefs = [
    ['title' => 'Tech reminder', 'detail' => 'Update your browser and check your microphone settings before any live session this week.'],
    ['title' => 'Reminder for year groups', 'detail' => 'Have your materials ready before Thursday\'s session as the class moves into group review.'],
    ['title' => 'Submission tip', 'detail' => 'Rename coursework files with subject, surname, and draft number before upload.'],
];

ob_start();
?>
<section class="newsletter-layout" id="latest-issue">
    <div class="stack">

        <article class="newsletter-cover">
            <div class="nl-cover-top">
                <p class="eyebrow">Issue 14</p>
                <span class="issue-tag">March edition</span>
            </div>
            <h3>The RIEO Student Bulletin</h3>
            <p>Everything students might want to know this week, gathered into one easy read.</p>
            <p class="issue-meta">Published Saturday, 28 March 2026 · Editor: Student services team</p>
            <a class="inline-action light" href="events.php#featured-events">See featured events</a>
        </article>

        <div class="nl-stories">
            <?php foreach ($features as $feature): ?>
            <article class="nl-story<?= !empty($feature['featured']) ? ' featured' : '' ?>">
                <span class="issue-tag"><?= portal_escape($feature['tag']) ?></span>
                <h3><?= portal_escape($feature['title']) ?></h3>
                <p><?= portal_escape($feature['copy']) ?></p>
            </article>
            <?php endforeach; ?>
        </div>

    </div>

    <div class="stack">
        <article class="card-shell">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Briefs</p>
                    <h3 class="card-title">Fast reads</h3>
                </div>
                <span class="chip"><?= count($briefs) ?> updates</span>
            </div>
            <div class="nl-briefs">
                <?php foreach ($briefs as $i => $brief): ?>
                <div class="nl-brief">
                    <span class="nl-brief-num"><?= $i + 1 ?></span>
                    <div>
                        <strong><?= portal_escape($brief['title']) ?></strong>
                        <p><?= portal_escape($brief['detail']) ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="card-shell">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Follow-up</p>
                    <h3 class="card-title">Where to go next</h3>
                </div>
            </div>
            <article class="schedule-note">
                <div>
                    <h3>Need something practical?</h3>
                    <p>Jump to the timetable for room changes or open events for club registrations and assemblies.</p>
                </div>
                <a class="inline-action" href="timetable.php#week-schedule">Open timetable</a>
            </article>
        </article>
    </div>
</section>
<?php
$page_content = ob_get_clean();

require __DIR__ . '/../layout.php';
