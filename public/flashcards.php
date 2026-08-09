<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../course_catalog.php';

portal_require_login();

$me = portal_current_user();
$uid = (int) ($me['id'] ?? 0);
$decks = portal_flashcard_decks_for_user($uid);

$page_title = 'Flashcards | ' . portal_school_name();
$active_page = 'flashcards';
$page_eyebrow = 'Revision';
$page_heading = 'Flashcards';
$page_description = 'All flashcard decks from your courses, in one place.';

ob_start();
?>
<section class="flashcards-hub">
    <header class="flashcards-hub-head">
        <div>
            <p class="eyebrow">Study</p>
            <h2>Your flashcard decks</h2>
            <p class="section-copy">Open any deck to flip through cards. You can also start a deck from inside its course.</p>
        </div>
        <span class="chip"><?= count($decks) ?> deck<?= count($decks) === 1 ? '' : 's' ?></span>
    </header>

    <?php if ($decks === []): ?>
        <div class="flashcards-empty">
            <strong>No flashcard decks yet</strong>
            <p>When a teacher publishes flashcards in one of your courses, they will show up here.</p>
            <a class="button button-secondary" href="courses.php">Browse courses</a>
        </div>
    <?php else: ?>
        <div class="flashcards-grid">
            <?php foreach ($decks as $deck): ?>
                <article class="flashcards-deck-card">
                    <div class="flashcards-deck-top">
                        <span class="activity-mode-pill activity-mode-pill--flashcard">Flashcards</span>
                        <span class="flashcards-deck-course"><?= portal_escape((string) $deck['course_title']) ?></span>
                    </div>
                    <h3><?= portal_escape((string) $deck['title']) ?></h3>
                    <?php if (trim((string) $deck['short_description']) !== ''): ?>
                        <p><?= portal_escape((string) $deck['short_description']) ?></p>
                    <?php endif; ?>
                    <div class="flashcards-deck-meta">
                        <span><strong><?= (int) $deck['card_count'] ?></strong> card<?= (int) $deck['card_count'] === 1 ? '' : 's' ?></span>
                    </div>
                    <div class="flashcards-deck-actions">
                        <a class="button" href="activity.php?id=<?= (int) $deck['id'] ?>">Study</a>
                        <?php if (!empty($deck['can_manage'])): ?>
                            <a class="button button-secondary" href="activity-builder.php?id=<?= (int) $deck['id'] ?>">Edit</a>
                            <a class="flashcards-course-link" href="course.php?id=<?= (int) $deck['course_id'] ?>">Open course</a>
                        <?php else: ?>
                            <a class="flashcards-course-link" href="course.php?id=<?= (int) $deck['course_id'] ?>">Open course</a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php
$page_content = ob_get_clean();
require __DIR__ . '/../layout.php';
