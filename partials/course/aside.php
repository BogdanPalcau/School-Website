        <div class="course-aside-wrap">
            <?php if (!empty($courseTeachers) || !empty($course['updates'])): ?>
            <div class="course-mobile-peek">
                <?php if (!empty($courseTeachers)): ?>
                <div class="course-staff-peek">
                    <div class="course-staff-peek-avatars" aria-hidden="true">
                        <?php foreach (array_slice($courseTeachers, 0, 4) as $teacher): ?>
                            <?php
                                $_peekRole = (string) ($teacher['assignment_role'] ?? 'teacher');
                                $_peekSuper = $_peekRole === 'supervisor';
                            ?>
                            <span class="course-staff-peek-avatar <?= $_peekSuper ? 'supervisor-avatar' : 'teacher-avatar' ?>"><?= portal_escape($teacher['initials']) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="course-staff-peek-copy">
                        <strong>Taught by</strong>
                        <span><?= portal_escape(implode(', ', array_column(array_slice($courseTeachers, 0, 3), 'name'))) ?><?= count($courseTeachers) > 3 ? '…' : '' ?></span>
                    </div>
                </div>
                <?php endif; ?>
                <p class="course-update-peek">See More course info for the full staff list and latest updates.</p>
            </div>
            <?php endif; ?>
            <input type="checkbox" id="course-about-toggle" class="course-about-toggle">
            <label for="course-about-toggle" class="course-about-toggle-label">
                <span class="course-about-toggle-copy">
                    <strong>More course info</strong>
                    <small>Full staff list, updates &amp; structure</small>
                </span>
                <?= portal_icon('chevron-down', 'course-about-chevron') ?>
            </label>
        <aside class="stack course-aside">
            <article class="card-shell">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Course staff</p>
                        <h3 class="card-title">Who is teaching</h3>
                    </div>
                </div>

                <div class="course-staff-list">
                    <?php if (empty($courseTeachers)): ?>
                        <p class="folder-empty-note" style="padding:4px 0;">No course staff assigned yet.</p>
                    <?php else: ?>
                        <?php foreach ($courseTeachers as $teacher): ?>
                            <?php
                                $_assignRole = (string) ($teacher['assignment_role'] ?? 'teacher');
                                $_staffIsSupervisor = $_assignRole === 'supervisor';
                                $_assignLabel = portal_course_assignment_role_label($_assignRole);
                            ?>
                            <div class="course-staff-item">
                                <div class="course-staff-avatar <?= $_staffIsSupervisor ? 'supervisor-avatar' : 'teacher-avatar' ?>"><?= portal_escape($teacher['initials']) ?></div>
                                <div class="course-staff-info">
                                    <h4><?= portal_escape($teacher['name']) ?></h4>
                                    <span class="admin-badge <?= $_staffIsSupervisor ? 'admin-badge--supervisor' : 'admin-badge--teacher' ?>"><?= portal_escape($_assignLabel) ?></span>
                                </div>
                                <?php if (portal_is_admin()): ?>
                                    <div class="course-staff-admin-actions">
                                        <form method="POST" class="staff-role-form">
                                            <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                            <input type="hidden" name="action" value="change_assignment_role">
                                            <input type="hidden" name="user_id" value="<?= (int) $teacher['id'] ?>">
                                            <select name="assignment_role" class="admin-role-select" onchange="this.form.submit()" title="Change assignment">
                                                <option value="teacher"<?= $_assignRole === 'teacher' ? ' selected' : '' ?>>Course Teacher</option>
                                                <option value="supervisor"<?= $_assignRole === 'supervisor' ? ' selected' : '' ?>>Course Supervisor</option>
                                            </select>
                                        </form>
                                        <form method="POST" class="staff-remove-form">
                                            <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                            <input type="hidden" name="action" value="remove_teacher">
                                            <input type="hidden" name="user_id" value="<?= (int) $teacher['id'] ?>">
                                            <button type="submit" class="btn-icon-danger" title="Remove from course">
                                                <?= portal_icon('trash', 'icon-sm') ?>
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if (portal_is_admin()): ?>
                    <?php if (!empty($availableTeachers)): ?>
                        <details class="folder-admin-panel" style="margin-top:14px;">
                            <summary class="folder-admin-trigger folder-admin-trigger--sm">
                                <?= portal_icon('plus', 'icon-sm') ?>
                                <span>Assign course staff</span>
                            </summary>
                            <form method="POST" class="folder-admin-form folder-admin-form--inner">
                                <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                <input type="hidden" name="action" value="assign_teacher">
                                <label class="folder-form-label">
                                    <span>Select staff account</span>
                                    <select name="user_id">
                                        <?php foreach ($availableTeachers as $t): ?>
                                            <option value="<?= (int) $t['id'] ?>">
                                                <?= portal_escape($t['name']) ?>
                                                (<?= portal_escape(ucfirst((string) ($t['role'] ?? 'teacher'))) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="folder-form-label">
                                    <span>Assignment on this module</span>
                                    <select name="assignment_role">
                                        <option value="teacher">Course Teacher</option>
                                        <option value="supervisor">Course Supervisor</option>
                                    </select>
                                </label>
                                <button type="submit" class="button button--sm">Assign</button>
                            </form>
                        </details>
                    <?php elseif (empty($courseTeachers)): ?>
                        <p class="folder-empty-note" style="margin-top:10px;">No staff accounts are available to assign. Create an owner, admin, or teacher in Admin → Manage Users.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </article>

            <article class="card-shell course-aside-focus">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Section focus</p>
                        <h3 class="card-title"><?= portal_escape($currentSection['label']) ?></h3>
                    </div>
                </div>
                <p class="section-copy"><?= portal_escape($currentSection['description']) ?></p>
                <div class="button-row">
                    <a class="button" href="<?= portal_escape($tabLookup['content']) ?>">Main content</a>
                    <a class="button-secondary" href="<?= portal_escape($tabLookup['calendar']) ?>">Course calendar</a>
                </div>
            </article>

            <article class="card-shell" id="course-updates">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Latest updates</p>
                        <h3 class="card-title">What changed</h3>
                    </div>
                </div>

                <div class="course-update-list">
                    <?php foreach ($course['updates'] as $update): ?>
                        <article class="course-update-item">
                            <p><?= portal_escape($update) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="card-shell course-aside-structure">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Course structure</p>
                        <h3 class="card-title">Built to expand</h3>
                    </div>
                </div>
                <article class="schedule-note">
                    <p>The course space is set up to grow over time. New folders and teaching materials can be added later without changing the student navigation.</p>
                </article>
            </article>
        </aside>
        </div>
    </div>
</section>
