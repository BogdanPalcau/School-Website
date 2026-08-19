                <?php if (portal_can_manage_course($courseId)): ?>
                <details class="folder-admin-panel">
                    <summary class="folder-admin-trigger">
                        <?= portal_icon('plus', 'icon-sm') ?> <span>Add schedule slot</span>
                    </summary>
                    <form method="POST" class="folder-admin-form">
                        <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                        <input type="hidden" name="action" value="create_schedule_slot">
                        <div class="folder-form-row">
                            <label class="folder-form-label">
                                <span>Day</span>
                                <select name="day_of_week">
                                    <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $d): ?>
                                        <option><?= $d ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="folder-form-label">
                                <span>Start time</span>
                                <input type="time" name="start_time">
                            </label>
                            <label class="folder-form-label">
                                <span>End time</span>
                                <input type="time" name="end_time">
                            </label>
                            <label class="folder-form-label">
                                <span>Location / join link <small>(optional)</small></span>
                                <input type="text" name="room" maxlength="500" placeholder="Room 12 or https://zoom.us/j/...">
                            </label>
                        </div>
                        <label class="folder-form-label">
                            <span>Notes <small>(optional)</small></span>
                            <input type="text" name="notes" maxlength="300" placeholder="Any extra info">
                        </label>
                        <button type="submit" class="button">Add</button>
                    </form>
                </details>
                <?php endif; ?>

                <article class="card-shell">
                    <div class="section-head">
                        <div>
                            <p class="eyebrow">Weekly schedule</p>
                            <h3 class="card-title">When this class meets</h3>
                        </div>
                        <span class="chip"><?= count($courseSchedule) ?> slot<?= count($courseSchedule) !== 1 ? 's' : '' ?></span>
                    </div>
                    <?php if (empty($courseSchedule)): ?>
                        <p style="margin:0;color:var(--muted);font-size:.9rem;">No schedule set yet.</p>
                    <?php else: ?>
                    <div class="schedule-grid">
                        <?php foreach ($courseSchedule as $slot): ?>
                        <div class="schedule-slot-wrap">
                            <div class="schedule-slot-card">
                                <div class="slot-day-badge"><?= portal_escape(substr($slot['day_of_week'], 0, 3)) ?></div>
                                <div class="slot-detail">
                                    <strong><?= portal_escape($slot['start_time']) ?><?= $slot['end_time'] ? ' – ' . portal_escape($slot['end_time']) : '' ?></strong>
                                    <?php
                                        $joinUrl = trim((string) ($slot['room'] ?? ''));
                                        $isValidUrl = portal_schedule_slot_is_online($joinUrl);
                                    ?>
                                    <?php if ($isValidUrl): ?>
                                        <span class="slot-online-label">Online</span>
                                    <?php elseif ($joinUrl !== ''): ?>
                                        <span class="slot-online-label"><?= portal_escape($joinUrl) ?></span>
                                    <?php else: ?>
                                        <span class="slot-online-label">Location TBA</span>
                                    <?php endif; ?>
                                    <?php if ($slot['notes'] !== ''): ?>
                                        <em><?= portal_escape($slot['notes']) ?></em>
                                    <?php endif; ?>
                                    <?php if ($isValidUrl): ?>
                                        <a class="slot-join-link" href="<?= portal_escape($joinUrl) ?>" target="_blank" rel="noopener noreferrer">
                                            Join session →
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <?php if (portal_can_manage_course($courseId)): ?>
                                <div class="slot-actions">
                                    <button type="button"
                                            class="settings-toggle btn-icon"
                                            data-settings-target="edit-slot-<?= (int)$slot['id'] ?>"
                                            title="Edit slot"
                                            aria-label="Edit schedule slot">
                                        <?= portal_icon('edit', 'icon-sm') ?>
                                    </button>
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete_schedule_slot">
                                        <input type="hidden" name="slot_id" value="<?= (int)$slot['id'] ?>">
                                        <button type="submit" class="btn-icon-danger"><?= portal_icon('trash','icon-sm') ?></button>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php if (portal_can_manage_course($courseId)): ?>
                            <div class="settings-panel slot-edit-panel" id="edit-slot-<?= (int)$slot['id'] ?>" hidden>
                                <form method="POST" class="folder-admin-form folder-admin-form--inner">
                                    <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                    <input type="hidden" name="action" value="update_schedule_slot">
                                    <input type="hidden" name="slot_id" value="<?= (int)$slot['id'] ?>">
                                    <div class="folder-form-row">
                                        <label class="folder-form-label">
                                            <span>Day</span>
                                            <select name="day_of_week">
                                                <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $d): ?>
                                                    <option<?= $slot['day_of_week'] === $d ? ' selected' : '' ?>><?= $d ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="folder-form-label">
                                            <span>Start time</span>
                                            <input type="time" name="start_time" value="<?= portal_escape($slot['start_time']) ?>">
                                        </label>
                                        <label class="folder-form-label">
                                            <span>End time</span>
                                            <input type="time" name="end_time" value="<?= portal_escape($slot['end_time']) ?>">
                                        </label>
                                        <label class="folder-form-label">
                                            <span>Location / join link <small>(optional)</small></span>
                                            <input type="text" name="room" maxlength="500" value="<?= portal_escape($slot['room']) ?>" placeholder="Room 12 or https://zoom.us/j/...">
                                        </label>
                                    </div>
                                    <label class="folder-form-label">
                                        <span>Notes <small>(optional)</small></span>
                                        <input type="text" name="notes" maxlength="300" value="<?= portal_escape($slot['notes']) ?>">
                                    </label>
                                    <div class="button-row">
                                        <button type="submit" class="button button--sm">Save</button>
                                        <button type="button"
                                                class="button-secondary button--sm settings-toggle"
                                                data-settings-target="edit-slot-<?= (int)$slot['id'] ?>">Cancel</button>
                                    </div>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </article>

                <article class="card-shell">
                    <div class="section-head">
                        <div>
                            <p class="eyebrow">One-off activities</p>
                            <h3 class="card-title">Upcoming events</h3>
                        </div>
                        <span class="chip"><?= count($courseUpcomingEvents) ?></span>
                    </div>
                    <?php if (empty($courseUpcomingEvents)): ?>
                        <p style="margin:0;color:var(--muted);font-size:.9rem;">No upcoming course events yet.</p>
                    <?php else: ?>
                    <div class="ev-list">
                        <?php foreach ($courseUpcomingEvents as $courseEvent): ?>
                        <?php
                            $evCancelled = (string) ($courseEvent['status'] ?? '') === 'cancelled';
                            $evChips = portal_event_chip_parts($courseEvent);
                        ?>
                        <article class="ev-item<?= $evCancelled ? ' ev-item--cancelled' : '' ?>">
                            <div class="ev-date">
                                <strong><?= portal_escape($evChips['day']) ?></strong>
                                <span><?= portal_escape($evChips['month']) ?></span>
                            </div>
                            <div class="ev-body">
                                <h3><?= portal_escape((string) $courseEvent['title']) ?></h3>
                                <p class="event-location">
                                    <?= portal_escape(portal_event_format_time_range($courseEvent)) ?>
                                    · <?= portal_escape(portal_event_place_label($courseEvent)) ?>
                                    <?php if ($evCancelled): ?> · Cancelled<?php endif; ?>
                                </p>
                                <a class="inline-action" href="events.php?event=<?= (int) $courseEvent['id'] ?>">View details</a>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </article>
