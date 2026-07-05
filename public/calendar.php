<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

portal_require_login();

$page_title = 'Calendar | ' . portal_school_name();
$active_page = 'calendar';
$page_eyebrow = 'Weekly planning';
$page_heading = 'Calendar';
$page_description = 'A simple week view for lessons, deadlines, and the key things you need to remember.';

$days = [
    ['day' => 'Mon', 'date' => '29 Mar', 'title' => 'Computer Science lab', 'meta' => '09:00 | Lab 4', 'featured' => true, 'link' => 'timetable.php#week-schedule'],
    ['day' => 'Tue', 'date' => '30 Mar', 'title' => 'Physics practical', 'meta' => '10:30 | Science 2', 'link' => 'timetable.php#week-schedule'],
    ['day' => 'Wed', 'date' => '31 Mar', 'title' => 'Literature draft due', 'meta' => 'Before 16:00', 'link' => 'courses.php#deadlines'],
    ['day' => 'Thu', 'date' => '01 Apr', 'title' => 'Maths intervention', 'meta' => '15:20 | Room 12', 'link' => 'timetable.php#week-schedule'],
    ['day' => 'Fri', 'date' => '02 Apr', 'title' => 'Assembly and clubs fair', 'meta' => '13:30 | Main hall', 'link' => 'events.php#featured-events'],
    ['day' => 'Sat', 'date' => '03 Apr', 'title' => 'Independent revision', 'meta' => 'Catch-up block', 'link' => 'courses.php'],
    ['day' => 'Sun', 'date' => '04 Apr', 'title' => 'Prep for Monday', 'meta' => 'Planner reset', 'link' => 'settings.php'],
];

$deadlines = [
    ['title' => 'Computer Science prototype', 'date' => 'Mon 10:00', 'detail' => 'Upload interface notes and annotated screenshots.'],
    ['title' => 'Literature comparison essay', 'date' => 'Wed 16:00', 'detail' => 'Submit the first polished draft through the portal.'],
    ['title' => 'Physics reflection log', 'date' => 'Thu 18:00', 'detail' => 'Record findings from the refraction practical.'],
];

ob_start();
?>
<section class="calendar-layout">
    <article class="card-shell" id="week-view">
        <div class="section-head">
            <div>
                <p class="eyebrow">Week view</p>
                <h3 class="card-title">This week</h3>
                <p>Use this to see what is coming up and jump straight to the right page when you need more detail.</p>
            </div>
            <a class="inline-action" href="timetable.php#week-schedule">Linked timetable</a>
        </div>

        <div class="calendar-grid">
            <?php foreach ($days as $day): ?>
                <article class="calendar-card<?= !empty($day['featured']) ? ' featured' : '' ?>">
                    <span class="calendar-date"><?= portal_escape($day['day']) ?></span>
                    <div>
                        <h3><?= portal_escape($day['date']) ?></h3>
                        <p class="calendar-meta"><?= portal_escape($day['title']) ?></p>
                    </div>
                    <span class="calendar-event"><?= portal_escape($day['meta']) ?></span>
                    <a href="<?= portal_escape($day['link']) ?>">View details</a>
                </article>
            <?php endforeach; ?>
        </div>
    </article>

    <div class="stack">
        <article class="card-shell">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Deadlines</p>
                    <h3 class="card-title">What needs attention</h3>
                </div>
                <span class="chip">3 items</span>
            </div>

            <div class="deadline-list">
                <?php foreach ($deadlines as $deadline): ?>
                    <article class="deadline-item">
                        <div>
                            <h3><?= portal_escape($deadline['title']) ?></h3>
                            <p class="section-copy"><?= portal_escape($deadline['detail']) ?></p>
                        </div>
                        <span class="score-chip">
                            <strong><?= portal_escape($deadline['date']) ?></strong>
                        </span>
                    </article>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="card-shell">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Sync</p>
                    <h3 class="card-title">Calendar to timetable</h3>
                </div>
            </div>
            <article class="schedule-note">
                <div>
                    <h3>Next live lesson</h3>
                    <p>Computer Science starts Monday at 09:00. Join using the session link in the timetable.</p>
                </div>
                <a class="inline-action" href="timetable.php#next-class">Go now</a>
            </article>
        </article>
    </div>
</section>
<?php
$page_content = ob_get_clean();

require __DIR__ . '/../layout.php';
