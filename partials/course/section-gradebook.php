                <?php
                    $gbIsStaff = portal_can_manage_course($courseId);
                    $gbMarked = array_values(array_filter(
                        $submissionGradebook,
                        static function (array $g) use ($gbIsStaff): bool {
                            if (($g['score'] ?? null) === null || trim((string) ($g['marked_at'] ?? '')) === '') {
                                return false;
                            }
                            if ($gbIsStaff) {
                                return true;
                            }
                            if (($g['grade_source'] ?? '') === 'activity') {
                                return true;
                            }
                            return portal_submission_grades_released($g);
                        }
                    ));
                    $gbPending = array_values(array_filter(
                        $submissionGradebook,
                        static function (array $g) use ($gbIsStaff): bool {
                            if (($g['score'] ?? null) === null || trim((string) ($g['marked_at'] ?? '')) === '') {
                                return true;
                            }
                            if ($gbIsStaff || ($g['grade_source'] ?? '') === 'activity') {
                                return false;
                            }
                            return !portal_submission_grades_released($g);
                        }
                    ));
                    $gbAvg = null;
                    if (!empty($gbMarked)) {
                        $gbAvg = portal_weighted_grade_average($gbMarked);
                    }

                    $gbGrouped = [];
                    foreach ($submissionGradebook as $grade) {
                        $slot = (string) $grade['slot_title'];
                        $gbGrouped[$slot][] = $grade;
                    }
                ?>
                <section class="gb-shell">
                    <header class="gb-header">
                        <div>
                            <p class="eyebrow"><?= $gbIsStaff ? 'Course gradebook' : 'Your grades' ?></p>
                            <h3 class="card-title"><?= $gbIsStaff ? 'Marks and feedback' : 'Grades for this module' ?></h3>
                            <p class="gb-header-copy"><?= $gbIsStaff
                                ? 'Mark work privately, then release grades when the class should see them.'
                                : 'Individual marks and feedback for assignments in this module.' ?></p>
                        </div>
                        <div class="gb-stat-row">
                            <div class="gb-stat">
                                <span>Marked</span>
                                <strong><?= count($gbMarked) ?></strong>
                            </div>
                            <div class="gb-stat">
                                <span>Awaiting</span>
                                <strong><?= count($gbPending) ?></strong>
                            </div>
                            <div class="gb-stat gb-stat--accent">
                                <span>Weighted avg</span>
                                <strong><?= $gbAvg !== null ? $gbAvg . '%' : '—' ?></strong>
                            </div>
                        </div>
                    </header>

                    <?php if (empty($submissionGradebook)): ?>
                        <div class="gb-empty">
                            <p class="eyebrow">No marks yet</p>
                            <h3>Nothing in the gradebook</h3>
                            <p><?= $gbIsStaff
                                ? 'When students submit work and you mark it, results appear here.'
                                : 'After you submit work and it is marked, your scores will show here.' ?></p>
                        </div>
                    <?php else: ?>
                        <div class="gb-slot-stack">
                            <?php if ($gbIsStaff): ?>
                                <?php foreach ($gbGrouped as $slotTitle => $rows): ?>
                                    <?php
                                        $slotWeightLabel = portal_format_submission_weight($rows[0]['submission_weight'] ?? 100);
                                        $slotItemId = (int) ($rows[0]['item_id'] ?? 0);
                                        $heldCount = count(array_filter(
                                            $rows,
                                            static fn(array $g): bool => portal_submission_is_marked($g) && !portal_submission_grades_released($g)
                                        ));
                                    ?>
                                    <div class="gb-slot-group" data-gb-slot data-item-id="<?= $slotItemId ?>">
                                        <div class="gb-slot-group-head">
                                            <div class="gb-slot-title">
                                                <h4><?= portal_escape($slotTitle) ?></h4>
                                                <span>Weight <?= portal_escape($slotWeightLabel) ?></span>
                                            </div>
                                            <div class="gb-slot-meta">
                                                <span class="chip"><?= count($rows) ?> submission<?= count($rows) === 1 ? '' : 's' ?></span>
                                                <?php if ($heldCount > 0 && $slotItemId > 0): ?>
                                                    <form method="POST" class="gb-release-form" data-gb-release-form>
                                                        <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                                        <input type="hidden" name="action" value="release_submission_grades">
                                                        <input type="hidden" name="item_id" value="<?= $slotItemId ?>">
                                                        <button type="submit" class="button button--sm">Release all grades</button>
                                                    </form>
                                                <?php elseif ($slotItemId > 0): ?>
                                                    <form method="POST" class="gb-release-form is-hidden" data-gb-release-form>
                                                        <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                                        <input type="hidden" name="action" value="release_submission_grades">
                                                        <input type="hidden" name="item_id" value="<?= $slotItemId ?>">
                                                        <button type="submit" class="button button--sm">Release all grades</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="gb-slot-grid">
                                            <?php foreach ($rows as $grade): ?>
                                                <?php
                                                    $isMarked = portal_submission_is_marked($grade);
                                                    $isReleased = portal_submission_grades_released($grade);
                                                    $submittedTs = portal_db_timestamp((string) $grade['submitted_at']);
                                                    $reviewId = 'rvw-' . (int) $grade['id'];
                                                ?>
                                                <div class="sub-slot-card"
                                                     data-review-open="<?= portal_escape($reviewId) ?>"
                                                     data-submission-id="<?= (int) $grade['id'] ?>"
                                                     role="button"
                                                     tabindex="0"
                                                     aria-label="Open review for <?= portal_escape((string) ($grade['student_name'] ?? 'student')) ?>">
                                                    <?= portal_render_submission_deadline((string) ($grade['submission_deadline'] ?? '')) ?>
                                                    <div class="sub-slot-card-row">
                                                        <span class="sub-slot-file">
                                                            <span class="course-staff-avatar sub-avatar"><?= portal_escape((string) ($grade['student_initials'] ?? '?')) ?></span>
                                                            <span>
                                                                <strong><?= portal_escape((string) ($grade['student_name'] ?? 'Student')) ?></strong>
                                                                <small><?= portal_escape($submittedTs ? date('j M Y H:i', $submittedTs) : '') ?><?= trim((string) ($grade['filename'] ?? '')) !== '' ? ' · ' . portal_escape((string) $grade['filename']) : '' ?></small>
                                                            </span>
                                                        </span>
                                                        <?php if ($isMarked && $isReleased): ?>
                                                            <span class="sub-slot-status sub-slot-status--graded" data-grade-status><?= (int) $grade['score'] ?>%</span>
                                                        <?php elseif ($isMarked): ?>
                                                            <span class="sub-slot-status sub-slot-status--held" data-grade-status><?= (int) $grade['score'] ?>% held</span>
                                                        <?php else: ?>
                                                            <span class="sub-slot-status sub-slot-status--pending" data-grade-status>Not graded</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="sub-slot-card-row">
                                                        <button type="button" class="button button--sm" data-review-open="<?= portal_escape($reviewId) ?>">Open review</button>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="gb-slot-grid">
                                    <?php foreach ($submissionGradebook as $grade): ?>
                                        <?php
                                            $isActivityGrade = ($grade['grade_source'] ?? '') === 'activity';
                                            $isMarked = $isActivityGrade
                                                ? ($grade['score'] !== null && trim((string) ($grade['marked_at'] ?? '')) !== '')
                                                : (portal_submission_is_marked($grade) && portal_submission_grades_released($grade));
                                            $isHeld = !$isActivityGrade && portal_submission_is_marked($grade) && !portal_submission_grades_released($grade);
                                            $submittedTs = portal_db_timestamp((string) $grade['submitted_at']);
                                            $reviewId = 'rvw-' . (int) $grade['id'];
                                            $weightLabel = portal_format_submission_weight($grade['submission_weight'] ?? 100);
                                            $activityResultHref = 'activity-results.php?id=' . (int) ($grade['activity_id'] ?? 0)
                                                . '&attempt=' . (int) ($grade['attempt_id'] ?? $grade['id']);
                                        ?>
                                        <<?= $isActivityGrade ? 'a' : 'div' ?> class="sub-slot-card"
                                             <?php if ($isActivityGrade): ?>href="<?= portal_escape($activityResultHref) ?>"<?php endif; ?>
                                             <?php if (!$isActivityGrade): ?>
                                             data-review-open="<?= portal_escape($reviewId) ?>"
                                             role="button"
                                             tabindex="0"
                                             <?php endif; ?>
                                             aria-label="Open result for <?= portal_escape((string) $grade['slot_title']) ?>">
                                            <?= portal_render_submission_deadline((string) ($grade['submission_deadline'] ?? '')) ?>
                                            <div class="sub-slot-card-row">
                                                <span class="sub-slot-file">
                                                    <?= portal_icon($isActivityGrade ? 'award' : 'file', 'icon-xs') ?>
                                                    <span>
                                                        <strong><?= portal_escape((string) $grade['slot_title']) ?></strong>
                                                        <small><?= portal_escape($submittedTs ? 'Submitted ' . date('j M Y H:i', $submittedTs) . ' | ' : '') ?>Weight <?= portal_escape($weightLabel) ?></small>
                                                    </span>
                                                </span>
                                                <?php if ($isMarked): ?>
                                                    <span class="sub-slot-status sub-slot-status--graded"><?= (int) $grade['score'] ?>%</span>
                                                <?php elseif ($isHeld): ?>
                                                    <span class="sub-slot-status sub-slot-status--pending">Awaiting release</span>
                                                <?php else: ?>
                                                    <span class="sub-slot-status sub-slot-status--pending">Not graded</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="sub-slot-card-row">
                                                <?php if ($isActivityGrade): ?>
                                                    <span class="button button--sm">View result</span>
                                                <?php else: ?>
                                                    <button type="button" class="button button--sm" data-review-open="<?= portal_escape($reviewId) ?>">Open review</button>
                                                <?php endif; ?>
                                            </div>
                                        </<?= $isActivityGrade ? 'a' : 'div' ?>>
                                    <?php endforeach; ?>
                                </div>
                                <p class="gb-footnote">See marks from every module on <a href="grades.php">My grades</a>.</p>
                            <?php endif; ?>
                        </div>

                        <?php
                            // Same review overlays as Content section — open in place from gradebook cards.
                            ob_start();
                            $isTeacherReview = $gbIsStaff;
                            foreach ($submissionGradebook as $reviewSub):
                                if (($reviewSub['grade_source'] ?? '') === 'activity') {
                                    continue;
                                }
                                $reviewAnns = $submissionAnnotations[(int) $reviewSub['id']] ?? [];
                                $reviewWho  = $isTeacherReview ? (string) ($reviewSub['student_name'] ?? '') : 'Your submission';
                                $itemForReview = [
                                    'id' => (int) ($reviewSub['item_id'] ?? 0),
                                    'title' => (string) ($reviewSub['slot_title'] ?? 'Submission'),
                                    'submission_deadline' => (string) ($reviewSub['submission_deadline'] ?? ''),
                                ];
                                $submittedLabel = portal_db_timestamp((string) ($reviewSub['submitted_at'] ?? ''));
                        ?>
                            <div id="rvw-<?= (int) $reviewSub['id'] ?>" class="rvw-overlay" hidden role="dialog" aria-modal="true">
                                <div class="rvw-dialog">
                                    <header class="rvw-dialog-header">
                                        <div class="rvw-dialog-heading">
                                            <p class="eyebrow">Assignment review</p>
                                            <h3><?= portal_escape((string) $itemForReview['title']) ?></h3>
                                            <p class="rvw-dialog-sub"><?= portal_escape($reviewWho) ?> &middot; <?= portal_escape((string) ($reviewSub['filename'] ?? '')) ?> &middot; <?= portal_escape($submittedLabel ? date('j M Y H:i', $submittedLabel) : '') ?></p>
                                        </div>
                                        <div class="rvw-dialog-actions">
                                            <?php if ($isTeacherReview): ?>
                                                <form method="POST" class="sub-rerun-form">
                                                    <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="rerun_integrity">
                                                    <input type="hidden" name="submission_id" value="<?= (int) $reviewSub['id'] ?>">
                                                    <button type="submit" class="button button--sm button--ghost">Re-run checks</button>
                                                </form>
                                            <?php endif; ?>
                                            <button type="button" class="rvw-close" aria-label="Close">&times;</button>
                                        </div>
                                    </header>
                                    <?= portal_render_submission_review($reviewSub, $isTeacherReview, $itemForReview, $reviewAnns, $csrfToken) ?>
                                </div>
                            </div>
                        <?php
                            endforeach;
                            $submissionModals .= ob_get_clean();
                        ?>
                    <?php endif; ?>
                </section>
