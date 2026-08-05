<?php
declare(strict_types=1);

/** @var array<string, mixed> $fv */
/** @var bool $isAdmin */
/** @var list<array{id:int|string,title:string,code?:string,accent?:string}> $manageableCourses */

$fvScope = (string) ($fv['scope'] ?? ($isAdmin ? 'school' : 'course'));
if ($fvScope !== 'school' && $fvScope !== 'course') {
    $fvScope = 'course';
}
$fvCourseId = (int) ($fv['course_id'] ?? 0);
$fvStarts = (string) ($fv['starts_at'] ?? '');
$fvEnds = (string) ($fv['ends_at'] ?? '');
if ($fvStarts !== '' && !str_contains($fvStarts, 'T')) {
    $fvStarts = events_datetime_local_value($fvStarts);
}
if ($fvEnds !== '' && !str_contains($fvEnds, 'T')) {
    $fvEnds = events_datetime_local_value($fvEnds);
}
?>
<label class="folder-form-label">
    <span>Title</span>
    <input type="text" name="title" required maxlength="200" value="<?= portal_escape((string) ($fv['title'] ?? '')) ?>" placeholder="Event title">
</label>
<label class="folder-form-label">
    <span>Summary</span>
    <input type="text" name="summary" required maxlength="500" value="<?= portal_escape((string) ($fv['summary'] ?? '')) ?>" placeholder="Short description for the list">
</label>
<label class="folder-form-label">
    <span>Description <small>(optional)</small></span>
    <textarea name="description" rows="5" maxlength="20000" placeholder="Full details"><?= portal_escape((string) ($fv['description'] ?? '')) ?></textarea>
</label>
<div class="folder-form-row">
    <label class="folder-form-label">
        <span>Starts</span>
        <input type="datetime-local" name="starts_at" required value="<?= portal_escape($fvStarts) ?>">
    </label>
    <label class="folder-form-label">
        <span>Ends <small>(optional)</small></span>
        <input type="datetime-local" name="ends_at" value="<?= portal_escape($fvEnds) ?>">
    </label>
</div>
<label class="folder-form-label">
    <span>Location <small>(optional)</small></span>
    <input type="text" name="location" maxlength="200" value="<?= portal_escape((string) ($fv['location'] ?? '')) ?>" placeholder="Hall, room, or meeting place">
</label>
<label class="folder-form-label">
    <span>Online URL <small>(optional)</small></span>
    <input type="url" name="online_url" maxlength="500" value="<?= portal_escape((string) ($fv['online_url'] ?? '')) ?>" placeholder="https://…">
</label>
<?php if ($isAdmin): ?>
<label class="folder-form-label">
    <span>Scope</span>
    <select name="scope" id="event-scope-select">
        <option value="school"<?= $fvScope === 'school' ? ' selected' : '' ?>>Whole school</option>
        <option value="course"<?= $fvScope === 'course' ? ' selected' : '' ?>>Course</option>
    </select>
</label>
<?php else: ?>
<input type="hidden" name="scope" value="course">
<?php endif; ?>
<?php if ($manageableCourses !== []): ?>
<label class="folder-form-label" id="event-course-field">
    <span>Course</span>
    <select name="course_id">
        <?php foreach ($manageableCourses as $course): ?>
            <option value="<?= (int) $course['id'] ?>"<?= $fvCourseId === (int) $course['id'] ? ' selected' : '' ?>>
                <?= portal_escape((string) $course['title']) ?>
                <?php if (!empty($course['code'])): ?>
                    (<?= portal_escape((string) $course['code']) ?>)
                <?php endif; ?>
            </option>
        <?php endforeach; ?>
    </select>
</label>
<?php elseif (!$isAdmin): ?>
<p class="folder-form-hint">You are not assigned to any courses yet, so you cannot create course events.</p>
<?php endif; ?>
<div class="settings-toggles ev-form-toggles">
    <?php if ($isAdmin): ?>
    <label class="settings-toggle">
        <span>
            <strong>Mark as important</strong>
            <small>Preferred for the featured slot when upcoming</small>
        </span>
        <input type="checkbox" name="important" value="1"<?= !empty($fv['important']) ? ' checked' : '' ?>>
    </label>
    <?php endif; ?>
    <label class="settings-toggle">
        <span>
            <strong>Send notification</strong>
            <small>Alert people who can see this event</small>
        </span>
        <input type="checkbox" name="notify" value="1"<?= !isset($fv['notify']) || !empty($fv['notify']) ? ' checked' : '' ?>>
    </label>
</div>
