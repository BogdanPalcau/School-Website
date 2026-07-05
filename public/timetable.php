<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

portal_require_login();

$page_title = 'Timetable | ' . portal_school_name();
$active_page = 'timetable';
$page_eyebrow = 'Lesson planning';
$page_heading = 'Timetable';
$page_description = 'Your week at a glance, with rooms, teachers, and your next lesson easy to spot.';

$days = ['Time', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$rows = [
    [
        'time' => '08:45',
        'slots' => [
            ['title' => 'Tutor', 'detail' => 'Hall | Register'],
            ['title' => 'Tutor', 'detail' => 'Hall | Register'],
            ['title' => 'Tutor', 'detail' => 'Hall | Register'],
            ['title' => 'Tutor', 'detail' => 'Hall | Register'],
            ['title' => 'Assembly', 'detail' => 'Main hall'],
        ],
    ],
    [
        'time' => '09:00',
        'slots' => [
            ['title' => 'Computer Science', 'detail' => 'Lab 4 | Mr Hart', 'highlight' => true],
            ['title' => 'Mathematics', 'detail' => 'Room 12 | Mrs Lewis'],
            ['title' => 'English Literature', 'detail' => 'Library wing | Ms Clarke'],
            ['title' => 'Physics', 'detail' => 'Science 2 | Dr Ndlovu'],
            ['title' => 'Computer Science', 'detail' => 'Lab 4 | Mr Hart'],
        ],
    ],
    [
        'time' => '10:30',
        'slots' => [
            ['title' => 'Physics', 'detail' => 'Science 2 | Dr Ndlovu'],
            ['title' => 'Physics', 'detail' => 'Science 2 | Dr Ndlovu'],
            ['title' => 'Mathematics', 'detail' => 'Room 12 | Mrs Lewis'],
            ['title' => 'English Literature', 'detail' => 'Library wing | Ms Clarke'],
            ['title' => 'Study period', 'detail' => 'Learning hub'],
        ],
    ],
    [
        'time' => '11:15',
        'slots' => [
            ['title' => 'Mathematics', 'detail' => 'Room 12 | Mrs Lewis'],
            ['title' => 'Computer Science', 'detail' => 'Lab 4 | Mr Hart'],
            ['title' => 'Physics', 'detail' => 'Science 2 | Dr Ndlovu'],
            ['title' => 'Mathematics', 'detail' => 'Room 12 | Mrs Lewis'],
            ['title' => 'Literature seminar', 'detail' => 'Library wing | Ms Clarke'],
        ],
    ],
    [
        'time' => '14:10',
        'slots' => [
            ['title' => 'Study hall', 'detail' => 'Open revision'],
            ['title' => 'Literature workshop', 'detail' => 'Essay studio'],
            ['title' => 'Clubs', 'detail' => 'Robotics and debate'],
            ['title' => 'Computer Science', 'detail' => 'Lab 4 | Sprint review'],
            ['title' => 'Games', 'detail' => 'Sports hall'],
        ],
    ],
];

$notes = [
    ['title' => 'Next class', 'detail' => 'Computer Science at 09:00. Join using the session link and have your interface wireframes ready to share.', 'id' => 'next-class'],
    ['title' => 'Before you join', 'detail' => 'Check your session links in advance and test your audio and camera before each class starts.'],
    ['title' => 'After-school block', 'detail' => 'Robotics club meets online Wednesday from 14:10 to 15:20.'],
];

ob_start();
?>
<section class="calendar-layout" id="week-schedule">
    <article class="card-shell">
        <div class="section-head">
            <div>
                <p class="eyebrow">Weekly schedule</p>
                <h3 class="card-title">Full lesson grid</h3>
                <p>The next lesson is marked clearly so you can see where you need to be without scanning the whole table.</p>
            </div>
            <a class="inline-action" href="calendar.php#week-view">Back to calendar</a>
        </div>

        <div class="schedule-table-wrapper">
            <table class="schedule-table">
                <thead>
                    <tr>
                        <?php foreach ($days as $day): ?>
                            <th><?= portal_escape($day) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td>
                                <article class="schedule-note">
                                    <h3><?= portal_escape($row['time']) ?></h3>
                                    <p>Start</p>
                                </article>
                            </td>
                            <?php foreach ($row['slots'] as $slot): ?>
                                <td>
                                    <article class="schedule-note schedule-slot<?= !empty($slot['highlight']) ? ' highlight' : '' ?>">
                                        <strong><?= portal_escape($slot['title']) ?></strong>
                                        <span><?= portal_escape($slot['detail']) ?></span>
                                    </article>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>

    <div class="stack">
        <?php foreach ($notes as $note): ?>
            <article class="card-shell"<?= !empty($note['id']) ? ' id="' . portal_escape($note['id']) . '"' : '' ?>>
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Schedule note</p>
                        <h3 class="card-title"><?= portal_escape($note['title']) ?></h3>
                    </div>
                </div>
                <article class="schedule-note">
                    <p><?= portal_escape($note['detail']) ?></p>
                </article>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php
$page_content = ob_get_clean();

require __DIR__ . '/../layout.php';
